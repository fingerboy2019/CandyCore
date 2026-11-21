<?php
/***
 * Rpc 服务端 Worker
 *
 * 业务说明：Workerman 监听；调 Registry/Dispatcher；结果写入 result；适配 G('rpc') 供 UrlParser
 * PayloadCrypto 启用时解密 params、加密响应 payload
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 17:48:19 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

use Candy\Extend\PayloadCrypto\PayloadCrypto;
use Candy\Extend\PayloadCrypto\PayloadCryptoException;
use Candy\Extend\Protocols\Rpc as RpcProtocol;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Rpc 服务进程
 *
 * Process.inc.php 示例：
 * 'rpc' => [
 *   'handler' => \Candy\Extend\Rpc\Server::class,
 *   'init' => '0.0.0.0:2020',
 *   'count' => 1,
 * ]
 */
class Server extends Worker
{
	/**
	 * @param string $address 监听 host:port
	 */
	public function __construct(string $address = '')
	{
		if ($address === '') {
			$address = Config::listen();
		}
		if (str_contains($address, '://')) {
			[, $address] = explode('://', $address, 2);
		}

		class_alias(RpcProtocol::class, 'Protocols\Rpc');
		parent::__construct('Rpc://' . $address);

		$this->name = 'CandyRpc';
		$this->count = 1;
		$this->reloadable = false;
		$this->onMessage = [$this, 'onMessage'];
		$this->onWorkerStart = [$this, 'onWorkerStart'];
	}

	/**
	 * Worker 启动时预热注册表
	 *
	 * @return void
	 */
	public function onWorkerStart(): void
	{
		Registry::reset();
		Registry::map();
	}

	/**
	 * 处理一帧 Rpc 请求
	 *
	 * @param TcpConnection $connection 连接
	 * @param mixed         $data       Protocols\Rpc::decode 结果
	 * @return void
	 */
	public function onMessage(TcpConnection $connection, mixed $data): void
	{
		$codec = 'bin';
		$payload = $data;

		if (is_array($data) && isset($data['codec'], $data['data'])) {
			$codec = (string)$data['codec'];
			$payload = $data['data'];
		}

		if ($codec === 'sys' || $payload === 'ping') {
			RpcProtocol::$encodeCodec = 'bin';
			$connection->send('pong');
			return;
		}

		RpcProtocol::$encodeCodec = $codec === 'json' ? 'json' : 'bin';

		if (!is_array($payload)) {
			$connection->send($this->errorResponse(0, -32600, 'Invalid request'));
			return;
		}

		if (isset($payload['error']) && !isset($payload['method'])) {
			$connection->send([
				'jsonrpc' => '2.0',
				'id'      => $payload['id'] ?? 0,
				'result'  => null,
				'error'   => $payload['error'],
			]);
			return;
		}

		$id = $payload['id'] ?? 0;
		$class = (string)($payload['class'] ?? '');
		$method = (string)($payload['method'] ?? '');
		$params = $payload['params'] ?? [];
		$appId = isset($payload['app_id']) ? (string)$payload['app_id'] : null;
		if ($appId === '') {
			$appId = null;
		}

		if ($class === '' || $method === '') {
			$connection->send($this->errorResponse($id, -32600, 'Invalid request: class/method required'));
			return;
		}

		// 强密：要求 crypto=1 并解密 params
		if (PayloadCrypto::enabled()) {
			if ((int)($payload['crypto'] ?? 0) !== 1) {
				$connection->send($this->errorResponse(
					$id,
					PayloadCryptoException::RPC_REQUIRED,
					'payload crypto required'
				));
				return;
			}
			if (!is_string($params)) {
				$connection->send($this->errorResponse(
					$id,
					PayloadCryptoException::RPC_INVALID,
					'encrypted params must be string'
				));
				return;
			}
			try {
				$params = PayloadCrypto::openRpcParams($params, $class, $method, is_scalar($id) ? $id : '0', $appId);
			} catch (PayloadCryptoException $e) {
				$connection->send($this->errorResponse($id, $e->rpcCode(), $e->getMessage()));
				return;
			}
		} elseif ((int)($payload['crypto'] ?? 0) === 1) {
			$connection->send($this->errorResponse(
				$id,
				PayloadCryptoException::RPC_MISCONFIGURED,
				'payload crypto not enabled on server'
			));
			return;
		}

		if (!is_array($params)) {
			$params = [$params];
		}

		// 框架解析自动适配：供 UrlParser / 业务读取 RPC 上下文
		$this->adaptFrameworkContext($class, $method, $params, $payload);

		try {
			$result = Dispatcher::call($class, $method, $params);
			$connection->send($this->successResponse($id, $class, $method, $result, $appId));
		} catch (\Throwable $e) {
			$code = (int)$e->getCode();
			if ($code === 0) {
				$code = -32000;
			}
			$connection->send($this->failureResponse($id, $class, $method, $code, $e->getMessage(), $appId));
		}
	}

	/**
	 * 写入 G('rpc') 并规范化 $_GET/$_POST，对齐原 jsonrpc 解析分支
	 *
	 * @param string              $class   服务名
	 * @param string              $method  方法名
	 * @param array<mixed>        $params  调用参数
	 * @param array<string,mixed> $payload 原始请求
	 * @return void
	 */
	private function adaptFrameworkContext(string $class, string $method, array $params, array $payload): void
	{
		if (!function_exists('G')) {
			return;
		}

		$app = 'Home';
		$service = $class;
		if (str_contains($class, '/')) {
			[$app, $service] = explode('/', $class, 2);
		}

		$get = [
			'g' => $app,
			'm' => $service,
			'a' => $method,
		];
		$post = [];

		if (isset($params['_post']) && is_array($params['_post'])) {
			$post = $params['_post'];
			unset($params['_post']);
		}

		// 关联参数并入 GET，便于旧代码读 $_GET
		if ($params !== [] && !array_is_list($params)) {
			$get = array_merge($get, $params);
		} elseif (array_is_list($params)) {
			$get['params'] = $params;
		}

		$ctx = array_merge($get, [
			'post'    => $post,
			'payload' => $payload,
		]);
		G('rpc', $ctx);
		// 兼容旧键名
		G('jsonrpc', $ctx);

		if (\Candy\Core\Request::inCoroutine()) {
			R()->replaceGet($get);
			if ($post !== []) {
				R()->replacePost($post);
			}
		} else {
			$_GET = $get;
			if ($post !== []) {
				$_POST = $post;
			}
		}

		if (function_exists('C')) {
			C('APPNAME', ucfirst($app));
			C('CONTROL', ucfirst($service));
			C('ACTION', $method);
		}

		if (function_exists('debug')) {
			debug(0);
		}
	}

	/**
	 * 成功响应（强密时封 payload）
	 *
	 * @param mixed       $id     请求 id
	 * @param string      $class  服务
	 * @param string      $method 方法
	 * @param mixed       $result 业务结果
	 * @param string|null $appId  应用
	 * @return array<string,mixed>
	 */
	private function successResponse(mixed $id, string $class, string $method, mixed $result, ?string $appId): array
	{
		if (!PayloadCrypto::enabled()) {
			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
				'error'   => null,
			];
		}
		return $this->cryptoEnvelope($id, $class, $method, [
			'result' => $result,
			'error'  => null,
		], $appId);
	}

	/**
	 * 业务失败响应（强密时错误进密文；协议层仍用 errorResponse 明文）
	 *
	 * @param mixed       $id      请求 id
	 * @param string      $class   服务
	 * @param string      $method  方法
	 * @param int         $code    错误码
	 * @param string      $message 信息
	 * @param string|null $appId   应用
	 * @return array<string,mixed>
	 */
	private function failureResponse(
		mixed $id,
		string $class,
		string $method,
		int $code,
		string $message,
		?string $appId
	): array {
		if (!PayloadCrypto::enabled()) {
			return $this->errorResponse($id, $code, $message);
		}
		return $this->cryptoEnvelope($id, $class, $method, [
			'result' => null,
			'error'  => [
				'code'    => $code,
				'message' => $message,
			],
		], $appId);
	}

	/**
	 * 密文响应外壳
	 *
	 * @param mixed                       $id     请求 id
	 * @param string                      $class  服务
	 * @param string                      $method 方法
	 * @param array{result:mixed,error:mixed} $inner 内层
	 * @param string|null                 $appId  应用
	 * @return array<string,mixed>
	 */
	private function cryptoEnvelope(
		mixed $id,
		string $class,
		string $method,
		array $inner,
		?string $appId
	): array {
		try {
			$payload = PayloadCrypto::sealRpcPayload(
				$inner,
				$class,
				$method,
				is_scalar($id) ? $id : '0',
				$appId
			);
		} catch (PayloadCryptoException $e) {
			return $this->errorResponse($id, $e->rpcCode(), $e->getMessage());
		}
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'crypto'  => 1,
			'payload' => $payload,
		];
	}

	/**
	 * 构造错误响应（明文，用于协议/强密诊断）
	 *
	 * @param mixed  $id      请求 id
	 * @param int    $code    错误码
	 * @param string $message 信息
	 * @return array<string,mixed>
	 */
	private function errorResponse(mixed $id, int $code, string $message): array
	{
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => null,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
}
