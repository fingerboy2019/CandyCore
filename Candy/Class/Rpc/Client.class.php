<?php
/***
 * Rpc 客户端
 *
 * 业务说明：魔术方法远程调用；协议 json/bin/auto；请求 id 使用 Snowflake
 * PayloadCrypto 启用时自动加密 params、解密响应 payload
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

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Rpc 客户端代理
 *
 * 用法：$client = Client::instance('Freight', ['tcp://127.0.0.1:2020']); $client->calc(...);
 */
class Client
{
	private const TIME_OUT = 5;

	/** @var array<string,self> */
	private static array $instances = [];

	/** @var list<string> */
	private static array $addressArray = [];

	private static string $apiName = 'default';

	private static string $apiVersion = '1.0.0';

	private static string $codec = 'bin';

	/** 是否在客户端自动执行 result.ops */
	private static bool $autoApply = false;

	private string $serviceName;

	/** @var resource|null */
	private $connection = null;

	/** 请求 id（雪花字符串，避免毫秒戳碰撞与 JS 大整数精度问题） */
	private string $id = '0';

	/** 最近一次调用方法名（响应 AAD 与请求 method 对齐） */
	private string $lastMethod = '';

	/**
	 * 配置地址与选项
	 *
	 * @param array<int,string>    $addressArray tcp://host:port 列表
	 * @param array<string,mixed>  $options protocol/name/version
	 * @return array<int,string>
	 */
	public static function config(array $addressArray = [], array $options = []): array
	{
		if ($addressArray !== []) {
			self::$addressArray = array_values($addressArray);
		}
		if (isset($options['name'])) {
			self::$apiName = (string)$options['name'];
		}
		if (isset($options['version'])) {
			self::$apiVersion = (string)$options['version'];
		}
		$protocol = isset($options['protocol']) ? (string)$options['protocol'] : null;
		self::$codec = Config::resolveCodec($protocol);
		if (array_key_exists('auto_apply', $options)) {
			self::$autoApply = (bool)$options['auto_apply'];
		} else {
			self::$autoApply = Config::autoApply();
		}
		return self::$addressArray;
	}

	/**
	 * 获取服务代理实例
	 *
	 * @param string              $serviceName 服务名
	 * @param array<int,string>   $addressArray 可选，顺带 config
	 * @param array<string,mixed> $options      可选
	 * @return self
	 */
	public static function instance(string $serviceName, array $addressArray = [], array $options = []): self
	{
		if ($addressArray !== [] || $options !== []) {
			self::config($addressArray, $options);
		}
		if (!isset(self::$instances[$serviceName])) {
			self::$instances[$serviceName] = new self($serviceName);
		}
		return self::$instances[$serviceName];
	}

	/**
	 * @param string $serviceName 服务名
	 */
	protected function __construct(string $serviceName)
	{
		$this->serviceName = $serviceName;
	}

	/**
	 * 同步远程调用
	 *
	 * @param string       $method    方法名
	 * @param array<mixed> $arguments 参数
	 * @return mixed
	 */
	public function __call(string $method, array $arguments): mixed
	{
		$this->sendRequest($method, $arguments);
		return $this->recvResponse();
	}

	/**
	 * 发送请求
	 *
	 * @param string       $method 方法名
	 * @param array<mixed> $params 参数
	 * @return void
	 */
	protected function sendRequest(string $method, array $params): void
	{
		$this->openConnection();
		$this->lastMethod = $method;
		$payload = [
			'jsonrpc' => '2.0',
			'name'    => self::$apiName,
			'version' => self::$apiVersion,
			'id'      => $this->id,
			'class'   => $this->serviceName,
			'method'  => $method,
			'params'  => $params,
		];

		// 强密：params 改为密文字符串，外层带 crypto=1
		if (PayloadCrypto::enabled()) {
			try {
				$payload['crypto'] = 1;
				$payload['params'] = PayloadCrypto::sealRpcParams(
					$params,
					$this->serviceName,
					$method,
					$this->id
				);
			} catch (PayloadCryptoException $e) {
				$this->closeConnection();
				throw new \RuntimeException($e->getMessage(), $e->rpcCode(), $e);
			}
		}

		$packet = RpcProtocol::encodeWith($payload, self::$codec);
		$len = strlen($packet);
		$sent = 0;
		while ($sent < $len) {
			$n = fwrite($this->connection, substr($packet, $sent));
			if ($n === false || $n === 0) {
				$this->closeConnection();
				throw new \RuntimeException('RPC send failed');
			}
			$sent += $n;
		}
	}

	/**
	 * 接收并解析响应；信封含 queries/ops 时，auto_apply 走 LocalApply（先查后写）
	 *
	 * 未自动执行时返回规范化信封 ['data','queries','ops']
	 *
	 * @return mixed
	 */
	protected function recvResponse(): mixed
	{
		$header = $this->readExact(4);
		if ($header === false) {
			$this->closeConnection();
			throw new \RuntimeException('RPC recv header failed');
		}
		$total = unpack('N', $header)[1];
		if ($total <= 0 || $total > RpcProtocol::MAX_PACKET_SIZE) {
			$this->closeConnection();
			throw new \RuntimeException('RPC invalid packet length');
		}
		$bodyLen = $total - 4;
		$body = $bodyLen > 0 ? $this->readExact($bodyLen) : '';
		$this->closeConnection();
		if ($body === false) {
			throw new \RuntimeException('RPC recv body failed');
		}

		$decoded = RpcProtocol::decode($header . $body);
		$data = $decoded['data'];

		if ($data === 'pong') {
			return 'pong';
		}
		if (!is_array($data)) {
			throw new \RuntimeException('RPC invalid response');
		}
		if (isset($data['id']) && (string)$data['id'] !== (string)$this->id) {
			throw new \RuntimeException('RPC response id mismatch');
		}

		$cryptoFlag = (int)($data['crypto'] ?? 0);
		if (PayloadCrypto::enabled()) {
			if ($cryptoFlag !== 1) {
				// 协议层明文 error（如对端未开强密）仍可读
				if (isset($data['error']) && $data['error'] !== null) {
					$err = $data['error'];
					$msg = is_array($err) ? (string)($err['message'] ?? 'RPC error') : (string)$err;
					$code = is_array($err) ? (int)($err['code'] ?? -32000) : -32000;
					throw new \RuntimeException($msg, $code);
				}
				throw new \RuntimeException(
					'RPC response missing crypto payload',
					PayloadCryptoException::RPC_REQUIRED
				);
			}
			try {
				$inner = PayloadCrypto::openRpcPayload(
					(string)($data['payload'] ?? ''),
					$this->serviceName,
					$this->lastMethod,
					$data['id'] ?? $this->id
				);
			} catch (PayloadCryptoException $e) {
				throw new \RuntimeException($e->getMessage(), $e->rpcCode(), $e);
			}
			if (isset($inner['error']) && $inner['error'] !== null) {
				$err = $inner['error'];
				$msg = is_array($err) ? (string)($err['message'] ?? 'RPC error') : (string)$err;
				$code = is_array($err) ? (int)($err['code'] ?? -32000) : -32000;
				throw new \RuntimeException($msg, $code);
			}
			$result = $inner['result'] ?? null;
		} else {
			if (isset($data['error']) && $data['error'] !== null) {
				$err = $data['error'];
				$msg = is_array($err) ? (string)($err['message'] ?? 'RPC error') : (string)$err;
				$code = is_array($err) ? (int)($err['code'] ?? -32000) : -32000;
				throw new \RuntimeException($msg, $code);
			}
			$result = $data['result'] ?? null;
		}
		if (!OpEnvelope::isEnvelope($result)) {
			return $result;
		}

		if (self::$autoApply) {
			return LocalApply::runAndData($result);
		}

		return OpEnvelope::normalize($result);
	}

	/**
	 * 建立 TCP 连接
	 *
	 * @return void
	 */
	protected function openConnection(): void
	{
		if (self::$addressArray === []) {
			throw new \RuntimeException('RPC address list is empty; call rpc() config first');
		}
		$address = self::$addressArray[array_rand(self::$addressArray)];
		$errno = 0;
		$errstr = '';
		$conn = @stream_socket_client($address, $errno, $errstr, self::TIME_OUT);
		if (!$conn) {
			throw new \RuntimeException("RPC connect failed: {$address} ({$errno}) {$errstr}");
		}
		stream_set_blocking($conn, true);
		stream_set_timeout($conn, self::TIME_OUT);
		$this->connection = $conn;
		$this->id = \Candy\Extend\Snowflake::id();
	}

	/**
	 * 精确读取字节
	 *
	 * @param int $len 长度
	 * @return string|false
	 */
	protected function readExact(int $len): string|false
	{
		$data = '';
		while (strlen($data) < $len) {
			$chunk = fread($this->connection, $len - strlen($data));
			if ($chunk === false || $chunk === '') {
				return false;
			}
			$data .= $chunk;
		}
		return $data;
	}

	/**
	 * 关闭连接
	 *
	 * @return void
	 */
	protected function closeConnection(): void
	{
		if (is_resource($this->connection)) {
			fclose($this->connection);
		}
		$this->connection = null;
	}
}

