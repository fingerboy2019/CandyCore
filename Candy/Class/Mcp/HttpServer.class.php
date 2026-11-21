<?php
/***
 * MCP Streamable HTTP 服务（Workerman Worker）
 *
 * 业务说明：独立端口提供 POST /mcp JSON-RPC；与 STDIO 共用 Protocol/Registry；Token 鉴权
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 10:54:51 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Process.inc 示例：
 * 'mcp' => [
 *   'handler' => \Candy\Extend\Mcp\HttpServer::class,
 *   'init' => '127.0.0.1:8108',
 *   'count' => 1,
 *   'reloadable' => false,
 * ]
 */
class HttpServer extends Worker
{
	/** @var Protocol|null MCP 协议处理器（勿命名 $protocol，与 Worker 属性冲突） */
	private ?Protocol $mcpProtocol = null;

	/**
	 * 旧客户端 Session 软表（默认不强制；仅 emit_session_on_initialize 时写入）
	 *
	 * @var array<string, array{created:int}>
	 */
	private array $sessions = [];

	private string $listenHost = '127.0.0.1';

	private int $listenPort = 8108;

	/**
	 * @param string $address host:port 或 http://host:port
	 */
	public function __construct(string $address = '127.0.0.1:8108')
	{
		if (str_contains($address, '://')) {
			[, $address] = explode('://', $address, 2);
		}
		$address = trim($address);
		if ($address === '') {
			$address = '127.0.0.1:8108';
		}

		if (str_contains($address, ':')) {
			[$host, $port] = explode(':', $address, 2);
			$this->listenHost = $host !== '' ? $host : '127.0.0.1';
			$this->listenPort = (int) $port;
		} else {
			$this->listenHost = $address;
		}

		parent::__construct('http://' . $this->listenHost . ':' . $this->listenPort);

		$this->name = 'CandyMcp';
		$this->count = 1;
		$this->reloadable = false;
		$this->onWorkerStart = [$this, 'onWorkerStart'];
		$this->onMessage = [$this, 'onMessage'];
	}

	/**
	 * Worker 启动：校验开关与 Token，装配 Protocol
	 *
	 * @return void
	 */
	public function onWorkerStart(): void
	{
		if (!Bootstrap::isEnabled()) {
			Bootstrap::log('http worker: Mcp.inc enable=false — all requests will 503');
		}

		$http = $this->httpConfig();
		if (empty($http['enable'])) {
			Bootstrap::log('http worker: http.enable=false — all requests will 503');
		}

		$token = trim((string) ($http['token'] ?? ''));
		if ($token === '' && $this->isPublicBind()) {
			Bootstrap::log('FATAL: http.token empty while listening on public interface — refusing requests');
		}

		$serverName = trim((string) ($http['server'] ?? 'default')) ?: 'default';
		try {
			$registry = Bootstrap::buildRegistry($serverName);
			$this->mcpProtocol = new Protocol($registry, Bootstrap::config(), $serverName);
			Bootstrap::log(sprintf(
				'http ready listen=%s:%d path=%s tools=%d',
				$this->listenHost,
				$this->listenPort,
				(string) ($http['path'] ?? '/mcp'),
				count($registry->all())
			));
		} catch (\Throwable $e) {
			$this->mcpProtocol = null;
			Bootstrap::log('http boot failed: ' . $e->getMessage());
		}
	}

	/**
	 * 处理 HTTP 请求
	 *
	 * @param TcpConnection $connection 连接
	 * @param Request       $request    请求
	 * @return void
	 */
	public function onMessage(TcpConnection $connection, Request $request): void
	{
		$http = $this->httpConfig();
		$path = (string) ($http['path'] ?? '/mcp');
		$path = '/' . trim($path, '/');
		if ($path === '/') {
			$path = '/mcp';
		}

		$reqPath = parse_url($request->uri(), PHP_URL_PATH);
		$reqPath = is_string($reqPath) ? rtrim($reqPath, '/') : '';
		if ($reqPath === '') {
			$reqPath = '/';
		}

		$method = strtoupper($request->method());
		// 元数据公开可读，不走 /mcp 路径与 Bearer（OAuth PRM / CIMD 探测）
		if ($method === 'GET'
			&& ($reqPath === '/.well-known/oauth-protected-resource'
				|| str_ends_with($reqPath, '/.well-known/oauth-protected-resource'))
		) {
			$connection->send($this->jsonResponse(200, $this->oauthProtectedResourceMeta()));
			return;
		}

		$normPath = rtrim($path, '/');
		if ($reqPath !== $normPath && $reqPath !== $normPath . '/') {
			$connection->send(new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not Found'));
			return;
		}

		if (!Bootstrap::isEnabled() || empty($http['enable'])) {
			$connection->send($this->jsonResponse(503, ['error' => 'mcp http disabled']));
			return;
		}

		$token = trim((string) ($http['token'] ?? ''));
		if ($token === '' && $this->isPublicBind()) {
			$connection->send($this->jsonResponse(503, ['error' => 'mcp http.token required for public bind']));
			return;
		}
		if ($token !== '' && !$this->authorize($request, $token)) {
			$connection->send($this->jsonResponse(401, ['error' => 'unauthorized']));
			return;
		}

		if ($this->mcpProtocol === null) {
			$connection->send($this->jsonResponse(503, ['error' => 'mcp protocol not ready']));
			return;
		}

		if ($method === 'OPTIONS') {
			$connection->send(new Response(204, $this->corsHeaders([
				'Allow' => 'POST, GET, DELETE, OPTIONS',
			]), ''));
			return;
		}

		if ($method === 'GET') {
			// Streamable HTTP：本实现不提供长 SSE，明确 405，避免客户端挂死
			$connection->send(new Response(405, $this->corsHeaders([
				'Content-Type' => 'text/plain; charset=utf-8',
				'Allow'        => 'POST, DELETE, OPTIONS',
			]), 'Method Not Allowed: use POST for JSON-RPC'));
			return;
		}

		if ($method === 'DELETE') {
			$sid = $this->sessionIdFromRequest($request);
			if ($sid !== '' && isset($this->sessions[$sid])) {
				unset($this->sessions[$sid]);
			}
			$connection->send(new Response(200, $this->corsHeaders([
				'Content-Type' => 'application/json',
			]), '{"ok":true}'));
			return;
		}

		if ($method !== 'POST') {
			$connection->send(new Response(405, $this->corsHeaders(['Allow' => 'POST, DELETE, OPTIONS']), 'Method Not Allowed'));
			return;
		}

		$body = (string) $request->rawBody();
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			$connection->send($this->jsonResponse(400, [
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => ['code' => -32700, 'message' => 'Parse error'],
			]));
			return;
		}

		$rpcMethod = isset($decoded['method']) ? (string) $decoded['method'] : '';
		$hasId = array_key_exists('id', $decoded);
		$toolName = '';
		if ($rpcMethod === 'tools/call') {
			$args = $decoded['params'] ?? [];
			if (is_array($args)) {
				$toolName = (string) ($args['name'] ?? '');
			}
		}

		// 2026-07-28：头与 body 镜像校验；默认不强制必带（兼容旧客户端）
		$headerErr = $this->validateMethodHeaders($request, $rpcMethod, $toolName);
		if ($headerErr !== null) {
			$connection->send($this->jsonResponse(400, [
				'jsonrpc' => '2.0',
				'id'      => $decoded['id'] ?? null,
				'error'   => ['code' => -32600, 'message' => $headerErr],
			]));
			return;
		}

		// 旧规范粘性 Session：仅 require_session=true 时强制
		if ($this->requireSession() && $rpcMethod !== '' && $rpcMethod !== 'initialize' && $rpcMethod !== 'server/discover') {
			$sid = $this->sessionIdFromRequest($request);
			if ($sid === '' || !isset($this->sessions[$sid])) {
				$connection->send($this->jsonResponse(400, [
					'jsonrpc' => '2.0',
					'id'      => $decoded['id'] ?? null,
					'error'   => ['code' => -32000, 'message' => 'Mcp-Session-Id required (http.require_session=true)'],
				]));
				return;
			}
		}

		// 通知：无 JSON-RPC 响应体 → 202
		if ($rpcMethod !== '' && !$hasId) {
			$this->mcpProtocol->handle($decoded);
			$connection->send(new Response(202, $this->responseHeaders($request, $rpcMethod, $toolName, [
				'Content-Type' => 'application/json',
			]), ''));
			return;
		}

		$response = $this->mcpProtocol->handle($decoded);
		if ($response === null) {
			$connection->send(new Response(202, $this->responseHeaders($request, $rpcMethod, $toolName, [
				'Content-Type' => 'application/json',
			]), ''));
			return;
		}

		$headers = $this->responseHeaders($request, $rpcMethod, $toolName, [
			'Content-Type' => 'application/json',
		]);

		// 软兼容：initialize 时可回显 Session（2026 客户端可不使用）
		if ($rpcMethod === 'initialize' && $this->emitSessionOnInitialize()) {
			$sid = bin2hex(random_bytes(16));
			$this->sessions[$sid] = ['created' => time()];
			$headers['Mcp-Session-Id'] = $sid;
		} else {
			$sid = $this->sessionIdFromRequest($request);
			if ($sid !== '' && isset($this->sessions[$sid])) {
				$headers['Mcp-Session-Id'] = $sid;
			}
		}

		$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$connection->send(new Response(200, $headers, $json !== false ? $json : '{}'));
	}

	/**
	 * 校验 Mcp-Method / Mcp-Name 与 JSON-RPC body 一致
	 *
	 * require_method_headers=true 时：除 OPTIONS 外 POST 必须带头
	 * 否则：仅当客户端已带头时校验镜像，避免旧客户端报错
	 *
	 * @param Request $request   请求
	 * @param string  $rpcMethod JSON-RPC method
	 * @param string  $toolName  tools/call 时的 Tool 名
	 * @return string|null 错误信息；null 表示通过
	 */
	private function validateMethodHeaders(Request $request, string $rpcMethod, string $toolName): ?string
	{
		$headerMethod = trim((string) $request->header('mcp-method', ''));
		$headerName = trim((string) $request->header('mcp-name', ''));
		$require = $this->requireMethodHeaders();

		if ($require) {
			if ($headerMethod === '') {
				return 'Mcp-Method header is required (http.require_method_headers or protocol_mode=strict_2026)';
			}
			if ($rpcMethod === 'tools/call' && $headerName === '') {
				return 'Mcp-Name header is required for tools/call';
			}
		}

		if ($headerMethod !== '' && $rpcMethod !== '' && strcasecmp($headerMethod, $rpcMethod) !== 0) {
			return 'Mcp-Method header mismatch: header=' . $headerMethod . ' body=' . $rpcMethod;
		}
		if ($headerName !== '' && $rpcMethod === 'tools/call' && $toolName !== ''
			&& strcasecmp($headerName, $toolName) !== 0
		) {
			return 'Mcp-Name header mismatch: header=' . $headerName . ' body=' . $toolName;
		}

		return null;
	}

	/**
	 * 响应头：CORS + 协议版本 + 可选方法镜像回显
	 *
	 * @param Request               $request   请求
	 * @param string                $rpcMethod JSON-RPC method
	 * @param string                $toolName  Tool 名
	 * @param array<string, string> $extra     额外头
	 * @return array<string, string>
	 */
	private function responseHeaders(Request $request, string $rpcMethod, string $toolName, array $extra = []): array
	{
		$headers = $this->corsHeaders($extra);
		$ver = trim((string) $request->header('mcp-protocol-version', ''));
		if ($ver === '' && $this->mcpProtocol !== null) {
			$ver = $this->mcpProtocol->defaultProtocolVersion();
		}
		if ($ver !== '') {
			$headers['MCP-Protocol-Version'] = $ver;
		}
		if ($rpcMethod !== '') {
			$headers['Mcp-Method'] = $rpcMethod;
		}
		if ($toolName !== '') {
			$headers['Mcp-Name'] = $toolName;
		}

		return $headers;
	}

	/**
	 * 是否强制要求 Mcp-Session-Id（默认 false；strict_2026 恒为 false）
	 *
	 * @return bool
	 */
	private function requireSession(): bool
	{
		if (Bootstrap::isStrict2026()) {
			return false;
		}

		return !empty($this->httpConfig()['require_session']);
	}

	/**
	 * initialize 成功时是否仍下发 Mcp-Session-Id（strict_2026 恒为 false）
	 *
	 * @return bool
	 */
	private function emitSessionOnInitialize(): bool
	{
		if (Bootstrap::isStrict2026()) {
			return false;
		}
		$http = $this->httpConfig();
		if (array_key_exists('emit_session_on_initialize', $http)) {
			return !empty($http['emit_session_on_initialize']);
		}

		return true;
	}

	/**
	 * 是否强制 Mcp-Method 头
	 *
	 * 显式配置优先；未配置时 strict_2026 默认为 true
	 *
	 * @return bool
	 */
	private function requireMethodHeaders(): bool
	{
		$http = $this->httpConfig();
		if (array_key_exists('require_method_headers', $http)) {
			return !empty($http['require_method_headers']);
		}

		return Bootstrap::isStrict2026();
	}

	/**
	 * OAuth Protected Resource Metadata（最小桩）
	 *
	 * Candy MCP HTTP 默认共享 Bearer（http.token），不是完整 AS+CIMD。
	 * 本端点供探测与文档对齐；authorization_servers 为空表示未挂 OAuth AS。
	 *
	 * @return array<string, mixed>
	 */
	private function oauthProtectedResourceMeta(): array
	{
		$http = $this->httpConfig();
		$path = '/' . trim((string) ($http['path'] ?? '/mcp'), '/');
		if ($path === '/') {
			$path = '/mcp';
		}
		$resource = 'http://' . $this->listenHost . ':' . $this->listenPort . $path;
		$oauth = $http['oauth'] ?? [];
		if (!is_array($oauth)) {
			$oauth = [];
		}
		$servers = $oauth['authorization_servers'] ?? [];
		if (!is_array($servers)) {
			$servers = [];
		}

		return [
			'resource'                 => (string) ($oauth['resource'] ?? $resource),
			'authorization_servers'    => array_values($servers),
			'bearer_methods_supported' => ['header'],
			'scopes_supported'         => is_array($oauth['scopes_supported'] ?? null)
				? array_values($oauth['scopes_supported'])
				: [],
			'candy'                    => [
				'auth_mode' => 'shared_bearer',
				'note'      => 'Uses Mcp.inc http.token Bearer; CIMD/DCR not required for this deployment. See raw/doc/Mcp20260728Migration.md',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function httpConfig(): array
	{
		$cfg = Bootstrap::config();
		$http = $cfg['http'] ?? [];

		return is_array($http) ? $http : [];
	}

	/**
	 * 是否绑定到非本机地址
	 *
	 * @return bool
	 */
	private function isPublicBind(): bool
	{
		$host = strtolower($this->listenHost);

		return !in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
	}

	/**
	 * Bearer / X-MCP-Token 鉴权
	 *
	 * @param Request $request 请求
	 * @param string  $token   配置 Token
	 * @return bool
	 */
	private function authorize(Request $request, string $token): bool
	{
		$auth = trim((string) $request->header('authorization', ''));
		if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
			return hash_equals($token, trim($m[1]));
		}
		$headerToken = trim((string) $request->header('x-mcp-token', ''));
		if ($headerToken !== '') {
			return hash_equals($token, $headerToken);
		}
		$queryToken = trim((string) $request->get('token', ''));
		if ($queryToken !== '') {
			return hash_equals($token, $queryToken);
		}

		return false;
	}

	/**
	 * @param Request $request 请求
	 * @return string
	 */
	private function sessionIdFromRequest(Request $request): string
	{
		return trim((string) $request->header('mcp-session-id', ''));
	}

	/**
	 * @param array<string, string> $extra 额外头
	 * @return array<string, string>
	 */
	private function corsHeaders(array $extra = []): array
	{
		return array_merge([
			'Access-Control-Allow-Origin'  => '*',
			'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-MCP-Token, Mcp-Session-Id, Accept, MCP-Protocol-Version, Mcp-Method, Mcp-Name',
			'Access-Control-Allow-Methods' => 'POST, GET, DELETE, OPTIONS',
			'Access-Control-Expose-Headers' => 'Mcp-Session-Id, MCP-Protocol-Version, Mcp-Method, Mcp-Name',
		], $extra);
	}

	/**
	 * @param int   $status HTTP 状态
	 * @param mixed $payload JSON 体
	 * @return Response
	 */
	private function jsonResponse(int $status, mixed $payload): Response
	{
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return new Response($status, $this->corsHeaders([
			'Content-Type' => 'application/json; charset=utf-8',
		]), $json !== false ? $json : '{}');
	}
}

