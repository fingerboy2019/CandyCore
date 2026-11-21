<?php
/***
 * MCP JSON-RPC 协议处理（薄实现）
 *
 * 业务说明：initialize/discover/tools/*；MRTR input_required；Tasks 扩展；strict_2026 关闭握手
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 10:54:51 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Protocol
{
	private Registry $registry;

	/** @var array<string, mixed> */
	private array $config;

	private string $serverName;

	/**
	 * @param Registry             $registry   Tool 注册表
	 * @param array<string, mixed> $config     Mcp.inc 全量配置
	 * @param string               $serverName 当前 server 名
	 */
	public function __construct(Registry $registry, array $config, string $serverName)
	{
		$this->registry = $registry;
		$this->config = $config;
		$this->serverName = $serverName;
	}

	/**
	 * 处理一条已解码的 JSON-RPC 消息
	 *
	 * 通知无 id：返回 null（不写响应）
	 * 请求有 id：返回响应数组
	 *
	 * @param array<string, mixed> $message 解码后的消息
	 * @return array<string, mixed>|null
	 */
	public function handle(array $message): ?array
	{
		$method = isset($message['method']) ? (string) $message['method'] : '';
		$id = array_key_exists('id', $message) ? $message['id'] : null;
		$params = $message['params'] ?? [];
		if (!is_array($params)) {
			$params = [];
		}

		// 通知（无 id）
		if ($id === null && $method !== '') {
			if ($method === 'notifications/initialized' || $method === 'initialized') {
				// strict_2026 下亦静默忽略，避免旧客户端噪音
				return null;
			}
			Bootstrap::log('ignored notification: ' . $method);

			return null;
		}

		if ($method === '') {
			return $this->error($id, -32600, 'Invalid Request: missing method');
		}

		// 纯 2026：拒绝已弃用的 initialize
		if ($method === 'initialize' && $this->isStrict2026()) {
			return $this->error(
				$id,
				-32601,
				'initialize removed in protocol_mode=strict_2026; use server/discover'
			);
		}

		try {
			$result = match ($method) {
				'initialize' => $this->initialize($params),
				'server/discover' => $this->discover($params),
				'ping' => (object) [],
				'tools/list' => $this->toolsList(),
				'tools/call' => $this->callTool($params),
				'tasks/get' => $this->taskGet($params),
				'tasks/update' => $this->taskUpdate($params),
				default => null,
			};
		} catch (\Throwable $e) {
			Bootstrap::log('handler error: ' . $e->getMessage());

			return $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
		}

		if ($result === null) {
			return $this->error($id, -32601, 'Method not found: ' . $method);
		}

		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * 是否纯 2026 模式（本 Protocol 实例配置）
	 *
	 * @return bool
	 */
	public function isStrict2026(): bool
	{
		$mode = strtolower(trim((string) ($this->config['protocol_mode'] ?? 'compat')));

		return $mode === 'strict_2026';
	}

	/**
	 * 配置文件中的 protocol_version（未套用 strict 覆盖）
	 *
	 * @return string
	 */
	private function configuredProtocolVersion(): string
	{
		return (string) ($this->config['protocol_version'] ?? '2024-11-05');
	}

	/**
	 * 读取 supported_protocol_versions 原始列表（可为空）
	 *
	 * @return list<string>
	 */
	private function readSupportedList(): array
	{
		$raw = $this->config['supported_protocol_versions'] ?? null;
		if (!is_array($raw) || $raw === []) {
			return [];
		}
		$out = [];
		foreach ($raw as $v) {
			$s = trim((string) $v);
			if ($s !== '' && !in_array($s, $out, true)) {
				$out[] = $s;
			}
		}

		return $out;
	}

	/**
	 * 默认对外协议版本（initialize / discover 未协商时）
	 *
	 * strict_2026 且支持列表含 2026-07-28 时优先宣告该版本
	 *
	 * @return string
	 */
	public function defaultProtocolVersion(): string
	{
		if ($this->isStrict2026()) {
			$list = $this->readSupportedList();
			if ($list === []) {
				$list = [$this->configuredProtocolVersion()];
			}
			if (in_array('2026-07-28', $list, true)) {
				return '2026-07-28';
			}
		}

		return $this->configuredProtocolVersion();
	}

	/**
	 * 本端声明支持的协议版本列表
	 *
	 * @return list<string>
	 */
	public function supportedProtocolVersions(): array
	{
		$out = $this->readSupportedList();

		return $out !== [] ? $out : [$this->configuredProtocolVersion()];
	}

	/**
	 * initialize 握手（2024-11-05 客户端；2026 规范已弃用但仍兼容）
	 *
	 * @param array<string, mixed> $params 客户端参数
	 * @return array<string, mixed>
	 */
	private function initialize(array $params): array
	{
		$negotiated = $this->negotiateVersion($params);

		return [
			'protocolVersion' => $negotiated,
			'capabilities'    => $this->capabilities(),
			'serverInfo'      => $this->serverInfo(),
		];
	}

	/**
	 * server/discover（2026-07-28：可选发现，无需握手）
	 *
	 * @param array<string, mixed> $params 客户端参数（可含 protocolVersion）
	 * @return array<string, mixed>
	 */
	private function discover(array $params): array
	{
		$negotiated = $this->negotiateVersion($params);

		return [
			'protocolVersion'            => $negotiated,
			'supportedProtocolVersions'  => $this->supportedProtocolVersions(),
			'capabilities'               => $this->capabilities(),
			'serverInfo'                 => $this->serverInfo(),
		];
	}

	/**
	 * tools/list（可附带列表缓存提示，供新客户端）
	 *
	 * @return array<string, mixed>
	 */
	private function toolsList(): array
	{
		$result = [
			'tools' => $this->registry->listDefinitions(),
		];
		$cache = $this->config['list_cache'] ?? null;
		if (!is_array($cache)) {
			return $result;
		}
		if (isset($cache['ttl_ms']) && is_numeric($cache['ttl_ms'])) {
			$result['ttlMs'] = (int) $cache['ttl_ms'];
		}
		$scope = isset($cache['cache_scope']) ? trim((string) $cache['cache_scope']) : '';
		if ($scope !== '') {
			$result['cacheScope'] = $scope;
		}

		return $result;
	}

	/**
	 * 协商协议版本：客户端请求且在支持列表内则采纳，否则用默认
	 *
	 * @param array<string, mixed> $params 请求 params
	 * @return string
	 */
	private function negotiateVersion(array $params): string
	{
		$requested = trim((string) ($params['protocolVersion'] ?? ''));
		$supported = $this->supportedProtocolVersions();
		if ($requested !== '' && in_array($requested, $supported, true)) {
			return $requested;
		}

		return $this->defaultProtocolVersion();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function capabilities(): array
	{
		$caps = [
			'tools' => [
				'listChanged' => false,
			],
		];
		// 宣告 Tasks 扩展（客户端可发现；未启用时 RPC 仍返回明确错误）
		if ($this->tasksEnabled()) {
			$caps['extensions'] = [
				'io.modelcontextprotocol/tasks' => new \stdClass(),
			];
		}

		return $caps;
	}

	/**
	 * Tasks 扩展是否开启（默认 true）
	 *
	 * @return bool
	 */
	private function tasksEnabled(): bool
	{
		$tasks = $this->config['tasks'] ?? null;
		if (!is_array($tasks)) {
			return true;
		}
		if (array_key_exists('enable', $tasks)) {
			return !empty($tasks['enable']);
		}

		return true;
	}

	/**
	 * @return array{name: string, version: string}
	 */
	private function serverInfo(): array
	{
		$info = $this->config['server_info'] ?? [];
		$name = is_array($info) ? (string) ($info['name'] ?? 'candy-mcp') : 'candy-mcp';
		$version = is_array($info) ? (string) ($info['version'] ?? '0.1.0') : '0.1.0';

		return [
			'name'    => $name . '/' . $this->serverName,
			'version' => $version,
		];
	}

	/**
	 * tools/call（含 MRTR input_required 与可选 Task 句柄）
	 *
	 * Tool 约定：
	 * - 普通成功：['ok'=>true,'data'=>mixed]
	 * - MRTR：['ok'=>true,'mrtr'=>['inputRequests'=>..., 'requestState'=>?string]]
	 * - 异步任务：['ok'=>true,'data'=>?,'task'=>['status'=>'working', ...]]
	 * 重试时客户端在 params 顶层或 arguments 内传 inputResponses / requestState
	 *
	 * @param array<string, mixed> $params name + arguments (+ inputResponses/requestState)
	 * @return array<string, mixed>
	 */
	private function callTool(array $params): array
	{
		$name = (string) ($params['name'] ?? '');
		$args = $params['arguments'] ?? [];
		if (!is_array($args)) {
			$args = [];
		}
		// MRTR 重试字段注入 Tool 可见参数
		foreach (['inputResponses', 'requestState'] as $k) {
			if (array_key_exists($k, $params) && !array_key_exists($k, $args)) {
				$args[$k] = $params[$k];
			}
		}

		if ($name === '') {
			return $this->toolError('tool name is required');
		}

		$tool = $this->registry->get($name);
		if ($tool === null) {
			return $this->toolError('tool not found or not allowed: ' . $name);
		}

		$started = microtime(true);
		$ok = false;
		$detail = '';
		$ret = [];
		try {
			$ret = $tool->handle($args);
			$ok = !empty($ret['ok']);
			if (!$ok) {
				$detail = (string) ($ret['error'] ?? 'tool failed');
			}
		} catch (\Throwable $e) {
			$ms = (int) round((microtime(true) - $started) * 1000);
			Audit::record($this->serverName, $name, $args, false, $ms, $e->getMessage());

			return $this->toolError($e->getMessage());
		}
		$ms = (int) round((microtime(true) - $started) * 1000);

		if (!$ok) {
			Audit::record($this->serverName, $name, $args, false, $ms, $detail);

			return $this->toolError($detail !== '' ? $detail : 'tool failed');
		}

		// MRTR：返回 input_required，客户端补全后重试原请求
		if (isset($ret['mrtr']) && is_array($ret['mrtr'])) {
			Audit::record($this->serverName, $name, $args, true, $ms, 'input_required');

			return $this->inputRequiredResult($ret['mrtr']);
		}

		$payload = $ret['data'] ?? null;
		$taskMeta = null;
		if (isset($ret['task']) && is_array($ret['task']) && $this->tasksEnabled()) {
			$seed = $ret['task'];
			if (!array_key_exists('data', $seed)) {
				$seed['data'] = $payload;
			}
			$taskMeta = TaskStore::create($this->serverName, $name, $seed);
			$payload = is_array($payload) ? $payload : [];
			if (is_array($payload)) {
				$payload['taskId'] = $taskMeta['taskId'];
				$payload['taskStatus'] = $taskMeta['status'];
			}
		}

		$text = is_string($payload)
			? $payload
			: (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

		$result = [
			'resultType' => 'complete',
			'content'    => [
				[
					'type' => 'text',
					'text' => $text !== '' && $text !== 'null' ? $text : '{}',
				],
			],
			'isError' => false,
		];

		$structured = $this->structuredFromPayload($payload);
		if ($structured !== null) {
			$result['structuredContent'] = $structured;
		}
		if ($taskMeta !== null) {
			$result['_meta'] = [
				'io.modelcontextprotocol/task' => [
					'taskId' => $taskMeta['taskId'],
					'status' => $taskMeta['status'],
				],
			];
		}

		Audit::record($this->serverName, $name, $args, true, $ms, $taskMeta ? ('task=' . $taskMeta['taskId']) : '');

		return $result;
	}

	/**
	 * 构造 InputRequiredResult（SEP-2322）
	 *
	 * @param array<string, mixed> $mrtr Tool 返回的 mrtr 段
	 * @return array<string, mixed>
	 */
	private function inputRequiredResult(array $mrtr): array
	{
		$inputRequests = $mrtr['inputRequests'] ?? null;
		$requestState = isset($mrtr['requestState']) ? (string) $mrtr['requestState'] : '';
		if (!is_array($inputRequests)) {
			$inputRequests = null;
		}
		if (($inputRequests === null || $inputRequests === []) && $requestState === '') {
			$requestState = bin2hex(random_bytes(16));
		}

		$out = [
			'resultType' => 'input_required',
			'isError'    => false,
			'content'    => [
				[
					'type' => 'text',
					'text' => 'input_required',
				],
			],
		];
		if (is_array($inputRequests) && $inputRequests !== []) {
			$out['inputRequests'] = $inputRequests;
		}
		if ($requestState !== '') {
			$out['requestState'] = $requestState;
		}

		return $out;
	}

	/**
	 * tasks/get — 轮询任务状态
	 *
	 * @param array<string, mixed> $params taskId
	 * @return array<string, mixed>
	 */
	private function taskGet(array $params): array
	{
		if (!$this->tasksEnabled()) {
			throw new \RuntimeException('tasks extension disabled');
		}
		$taskId = trim((string) ($params['taskId'] ?? $params['id'] ?? ''));
		if ($taskId === '') {
			throw new \RuntimeException('taskId is required');
		}
		$rec = TaskStore::get($taskId);
		if ($rec === null) {
			throw new \RuntimeException('task not found or expired: ' . $taskId);
		}

		return [
			'resultType' => 'complete',
			'task'       => $rec,
		];
	}

	/**
	 * tasks/update — 客户端向任务写入补充输入或状态
	 *
	 * @param array<string, mixed> $params taskId + status/input/data/message/progress
	 * @return array<string, mixed>
	 */
	private function taskUpdate(array $params): array
	{
		if (!$this->tasksEnabled()) {
			throw new \RuntimeException('tasks extension disabled');
		}
		$taskId = trim((string) ($params['taskId'] ?? $params['id'] ?? ''));
		if ($taskId === '') {
			throw new \RuntimeException('taskId is required');
		}
		$patch = [];
		foreach (['status', 'message', 'progress', 'data', 'input'] as $k) {
			if (array_key_exists($k, $params)) {
				$patch[$k] = $params[$k];
			}
		}
		$rec = TaskStore::update($taskId, $patch);
		if ($rec === null) {
			throw new \RuntimeException('task not found or expired: ' . $taskId);
		}

		return [
			'resultType' => 'complete',
			'task'       => $rec,
		];
	}

	/**
	 * 将 Tool data 转为 MCP structuredContent（仅数组）
	 *
	 * 关联数组原样上报；list（0..n-1）包一层 items，避免裸数组歧义
	 * 字符串/标量/null 不设 structuredContent
	 *
	 * @param mixed $payload Tool 返回的 data
	 * @return array<string, mixed>|null
	 */
	private function structuredFromPayload(mixed $payload): ?array
	{
		if (!is_array($payload)) {
			return null;
		}
		if ($payload === []) {
			return [];
		}
		// 判断是否为 list（连续整数键）
		if (array_is_list($payload)) {
			return ['items' => $payload];
		}

		return $payload;
	}

	/**
	 * Tool 调用失败时的 result（MCP 约定走 result+isError，非 JSON-RPC error）
	 *
	 * @param string $message 错误信息
	 * @return array<string, mixed>
	 */
	private function toolError(string $message): array
	{
		return [
			'resultType' => 'complete',
			'content'    => [
				[
					'type' => 'text',
					'text' => $message,
				],
			],
			'isError' => true,
		];
	}

	/**
	 * JSON-RPC 错误响应
	 *
	 * @param mixed  $id      请求 id
	 * @param int    $code    错误码
	 * @param string $message 错误信息
	 * @return array<string, mixed>
	 */
	private function error(mixed $id, int $code, string $message): array
	{
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
}
