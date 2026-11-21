<?php
/***
 * 数据库运行指标
 *
 * 业务说明：慢查询、连接创建/重连、读写路由命中，以及可选的请求级 SQL 指纹与耗时采样
 *
 * 存储策略：
 * - Workerman 长驻：进程内累计；FPM/CGI：runApplicationDestructor() 合并写入 Runtime/Cache/db_metrics.json
 * - 请求级采样按配置概率写入 Runtime/Logs/db-query-samples.json，并镜像到 Data/observability/db-query-samples.json；仅保存路由、聚合指标、哈希指纹与有界耗时
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-14 23:12:00 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class DbMetrics
{
	private const STORE_BASENAME = 'db_metrics.json';
	private const REQUEST_SAMPLE_BASENAME = 'db-query-samples.json';
	private const REQUEST_SAMPLE_ARCHIVE_LABEL = 'Data/observability/db-query-samples.json';
	private const SAMPLE_WINDOW_BASENAME = 'db-query-sample-window.json';
	private const REQUEST_SAMPLE_LIMIT = 500;
	private const REQUEST_FINGERPRINT_LIMIT = 256;
	private const REQUEST_FINGERPRINT_OUTPUT_LIMIT = 20;
	private const REQUEST_QUERY_DURATION_LIMIT = 512;
	private const SAMPLE_WINDOW_MAX_SECONDS = 900;

	/** @var array<string,mixed> 本进程/本请求增量 */
	private static array $stats = [];

	/** @var bool 本请求是否已刷盘（避免析构重复合并） */
	private static bool $flushedThisRequest = false;

	/** @var bool|null 缓存 usePersist() 结果 */
	private static ?bool $persistMode = null;

	/** @var float|null 缓存请求级 SQL 采样率 */
	private static ?float $requestSampleRate = null;

	/** @var float|null 单测覆盖的请求级 SQL 采样率 */
	private static ?float $forcedRequestSampleRate = null;

	/**
	 * @return array<string,mixed>
	 */
	private static function emptyStats(): array
	{
		return [
			'queries' => 0,
			'slow_queries' => 0,
			'total_ms' => 0.0,
			'connections_created' => 0,
			'reconnects' => 0,
			'role_hits' => ['write' => 0, 'read' => 0],
			'pool_keys' => [],
			'async_enqueued' => 0,
			'async_processed' => 0,
			'async_errors' => 0,
		];
	}

	/**
	 * 确保本地统计已初始化
	 *
	 * @return void
	 */
	private static function bootLocal(): void
	{
		if (self::$stats === []) {
			self::$stats = self::emptyStats();
		}
	}

	/**
	 * 是否启用跨请求持久化（FPM 默认开，Workerman 默认关）
	 *
	 * @return bool
	 */
	public static function usePersist(): bool
	{
		if (self::$persistMode !== null) {
			return self::$persistMode;
		}
		$cfg = function_exists('loadConfig') ? (loadConfig('Db') ?: []) : [];
		$flag = $cfg['metrics_persist'] ?? G('DB_METRICS_PERSIST') ?? C('DB_METRICS_PERSIST') ?? 'auto';
		if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') {
			self::$persistMode = true;
		} elseif ($flag === false || $flag === 0 || $flag === '0' || $flag === 'false') {
			self::$persistMode = false;
		} else {
			self::$persistMode = self::resolvePersistAuto();
		}
		return self::$persistMode;
	}

	/**
	 * auto 模式：FPM/CGI 等短请求落盘；CLI/Workerman 长驻仅进程内累计
	 *
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function resolvePersistAuto(): bool
	{
		// php candy / Workerman Worker：进程内 static 累计，不写指标文件
		if (defined('CLIWORKING')) {
			return false;
		}

		$sapi = strtolower(PHP_SAPI);
		if (
			str_contains($sapi, 'fpm')
			|| str_contains($sapi, 'cgi')
			|| in_array($sapi, ['apache2handler', 'litespeed', 'uws-http'], true)
		) {
			return true;
		}

		// 其余非 CLI Web SAPI
		return !str_starts_with($sapi, 'cli');
	}

	/**
	 * 测试用：强制持久化开关并清空缓存判定
	 *
	 * @param bool|null $on null=恢复自动判定
	 * @return void
	 */
	public static function forcePersist(?bool $on): void
	{
		self::$persistMode = $on;
	}

	/**
	 * 单测覆盖请求级 SQL 采样率。
	 *
	 * @param float|null $rate 采样率，null 表示恢复配置值
	 * @return void
	 */
	public static function forceQuerySampleRate(?float $rate): void
	{
		self::$forcedRequestSampleRate = $rate === null ? null : max(0.0, min(1.0, $rate));
		self::$requestSampleRate = null;
	}

	/**
	 * 开启一个带 TTL 的请求级 SQL 采样窗口。
	 *
	 * 采样窗口只写入 Runtime 控制文件，不触碰业务库；调用方必须明确提供路由，
	 * 防止把全站流量误当作客户驾驶舱证据。窗口由 HTTP 请求读取，到期后自动失效。
	 *
	 * @param float $rate 采样率，范围为 0 到 1
	 * @param int $seconds 窗口秒数，最大 900 秒
	 * @param array<int,string> $routes 允许采样的规范化路由
	 * @param bool $replace 是否替换现有活动窗口
	 * @return array{code:int,message:string,data:array<string,mixed>}
	 */
	public static function openSampleWindow(
		float $rate,
		int $seconds,
		array $routes,
		bool $replace = false
	): array
	{
		if ($rate <= 0.0 || $rate > 1.0) {
			return [
				'code' => 1,
				'message' => 'db_sample_window_invalid_rate',
				'data' => [],
			];
		}
		if ($seconds < 1 || $seconds > self::SAMPLE_WINDOW_MAX_SECONDS) {
			return [
				'code' => 1,
				'message' => 'db_sample_window_invalid_duration',
				'data' => [],
			];
		}

		$routes = self::normalizeSampleRoutes($routes);
		if ($routes === []) {
			return [
				'code' => 1,
				'message' => 'db_sample_window_routes_required',
				'data' => [],
			];
		}

		$current = self::sampleWindowStatus();
		if (!empty($current['data']['active']) && !$replace) {
			return [
				'code' => 1,
				'message' => 'db_sample_window_already_active',
				'data' => (array) ($current['data'] ?? []),
			];
		}

		$createdEpoch = time();
		$window = [
			'version' => 1,
			'window_id' => bin2hex(random_bytes(8)),
			'created_at' => date(DATE_ATOM, $createdEpoch),
			'created_epoch' => $createdEpoch,
			'expires_at' => date(DATE_ATOM, $createdEpoch + $seconds),
			'expires_epoch' => $createdEpoch + $seconds,
			'rate' => round($rate, 6),
			'routes' => $routes,
		];

		if (!self::writeSampleWindow($window)) {
			return [
				'code' => 1,
				'message' => 'db_sample_window_write_failed',
				'data' => [],
			];
		}

		if (function_exists('T')) {
			T('DB_QUERY_SAMPLE_WINDOW', $window);
		}

		return [
			'code' => 0,
			'message' => 'db_sample_window_opened',
			'data' => self::sampleWindowData($window, true),
		];
	}

	/**
	 * 读取当前 SQL 采样窗口状态。
	 *
	 * @return array{code:int,message:string,data:array<string,mixed>}
	 */
	public static function sampleWindowStatus(): array
	{
		$window = self::readSampleWindow();
		if ($window === null) {
			return [
				'code' => 0,
				'message' => 'db_sample_window_inactive',
				'data' => [
					'active' => false,
					'reason' => 'file_missing_or_invalid',
					'sample_file' => self::REQUEST_SAMPLE_ARCHIVE_LABEL,
					'window_file' => 'Runtime/Cache/db-query-sample-window.json',
				],
			];
		}

		$active = (int) $window['expires_epoch'] > time();
		$data = self::sampleWindowData($window, $active);
		if (!$active) {
			$data['reason'] = 'expired';
		}

		return [
			'code' => 0,
			'message' => $active ? 'db_sample_window_active' : 'db_sample_window_expired',
			'data' => $data,
		];
	}

	/**
	 * 关闭当前 SQL 采样窗口。
	 *
	 * @return array{code:int,message:string,data:array<string,mixed>}
	 */
	public static function closeSampleWindow(): array
	{
		$path = self::sampleWindowPath();
		if (is_file($path) && !@unlink($path)) {
			return [
				'code' => 1,
				'message' => 'db_sample_window_close_failed',
				'data' => [],
			];
		}
		if (function_exists('T')) {
			T('DB_QUERY_SAMPLE_WINDOW', false);
		}

		return [
			'code' => 0,
			'message' => 'db_sample_window_closed',
			'data' => [
				'active' => false,
				'window_file' => 'Runtime/Cache/db-query-sample-window.json',
			],
		];
	}

	/**
	 * 获取请求级 SQL 采样率。
	 *
	 * @return float
	 */
	private static function querySampleRate(): float
	{
		if (self::$forcedRequestSampleRate !== null) {
			return self::$forcedRequestSampleRate;
		}

		$window = self::requestSampleWindow();
		if (is_array($window)) {
			return (float) $window['rate'];
		}
		if (self::$requestSampleRate !== null) {
			return self::$requestSampleRate;
		}

		$config = function_exists('loadConfig') ? (loadConfig('Db') ?: []) : [];
		$override = C('DB_QUERY_SAMPLE_RATE');
		$rate = is_numeric($override)
			? (float) $override
			: (float) ($config['metrics_query_sample_rate'] ?? 0.0);
		self::$requestSampleRate = max(0.0, min(1.0, $rate));
		return self::$requestSampleRate;
	}

	/**
	 * 获取当前请求可用的采样窗口，并按请求缓存文件读取结果。
	 *
	 * @return array<string,mixed>|null
	 */
	private static function requestSampleWindow(): ?array
	{
		if (!function_exists('T')) {
			return null;
		}

		$cached = T('DB_QUERY_SAMPLE_WINDOW');
		if (is_array($cached)) {
			if ((int) ($cached['expires_epoch'] ?? 0) > time()) {
				return $cached;
			}
			T('DB_QUERY_SAMPLE_WINDOW', false);
			return null;
		}
		if ($cached === false) {
			return null;
		}

		$window = self::readSampleWindow();
		if ($window === null || (int) $window['expires_epoch'] <= time()) {
			T('DB_QUERY_SAMPLE_WINDOW', false);
			return null;
		}
		T('DB_QUERY_SAMPLE_WINDOW', $window);
		return $window;
	}

	/**
	 * 规范化采样窗口中的路由白名单。
	 *
	 * @param array<int,string> $routes 原始路由
	 * @return array<int,string>
	 */
	private static function normalizeSampleRoutes(array $routes): array
	{
		$normalized = [];
		foreach ($routes as $route) {
			$route = trim((string) $route);
			if ($route === '') {
				continue;
			}
			$path = parse_url($route, PHP_URL_PATH);
			$path = is_string($path) && $path !== '' ? $path : $route;
			if (!str_starts_with($path, '/')) {
				$path = '/' . $path;
			}
			$path = '/' . trim($path, '/');
			if ($path === '//') {
				$path = '/';
			}
			if (strlen($path) > 180) {
				continue;
			}
			$normalized[] = $path;
		}

		$normalized = array_values(array_unique($normalized));
		sort($normalized);
		return $normalized;
	}

	/**
	 * 生成采样窗口控制文件路径。
	 *
	 * @return string
	 */
	private static function sampleWindowPath(): string
	{
		$runtime = rtrim((string) C('RUNTIME'), '/\\');
		if ($runtime === '') {
			return '';
		}
		return $runtime . DIRECTORY_SEPARATOR . 'Cache'
			. DIRECTORY_SEPARATOR . self::SAMPLE_WINDOW_BASENAME;
	}

	/**
	 * 读取并校验采样窗口控制文件。
	 *
	 * @return array<string,mixed>|null
	 */
	private static function readSampleWindow(): ?array
	{
		$path = self::sampleWindowPath();
		if ($path === '' || !is_file($path) || !is_readable($path)) {
			return null;
		}

		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return null;
		}

		$locked = false;
		try {
			$locked = @flock($handle, LOCK_SH);
			if (!$locked) {
				return null;
			}
			$raw = stream_get_contents($handle);
			$data = json_decode((string) $raw, true);
			return is_array($data) ? self::normalizeSampleWindow($data) : null;
		} catch (\Throwable) {
			return null;
		} finally {
			if ($locked) {
				flock($handle, LOCK_UN);
			}
			fclose($handle);
		}
	}

	/**
	 * 规范化采样窗口控制数据。
	 *
	 * @param array<string,mixed> $window 原始控制数据
	 * @return array<string,mixed>|null
	 */
	private static function normalizeSampleWindow(array $window): ?array
	{
		$rate = is_numeric($window['rate'] ?? null) ? (float) $window['rate'] : 0.0;
		$createdEpoch = (int) ($window['created_epoch'] ?? 0);
		$expiresEpoch = (int) ($window['expires_epoch'] ?? 0);
		$routes = is_array($window['routes'] ?? null)
			? self::normalizeSampleRoutes($window['routes'])
			: [];
		$windowId = trim((string) ($window['window_id'] ?? ''));
		if (
			$rate <= 0.0
			|| $rate > 1.0
			|| $createdEpoch <= 0
			|| $expiresEpoch <= $createdEpoch
			|| $expiresEpoch - $createdEpoch > self::SAMPLE_WINDOW_MAX_SECONDS
			|| $windowId === ''
			|| $routes === []
		) {
			return null;
		}

		return [
			'version' => (int) ($window['version'] ?? 1),
			'window_id' => $windowId,
			'created_at' => (string) ($window['created_at'] ?? date(DATE_ATOM, $createdEpoch)),
			'created_epoch' => $createdEpoch,
			'expires_at' => (string) ($window['expires_at'] ?? date(DATE_ATOM, $expiresEpoch)),
			'expires_epoch' => $expiresEpoch,
			'rate' => round($rate, 6),
			'routes' => $routes,
		];
	}

	/**
	 * 生成对外返回的采样窗口状态，不暴露绝对文件路径。
	 *
	 * @param array<string,mixed> $window 采样窗口
	 * @param bool $active 是否仍在有效期内
	 * @return array<string,mixed>
	 */
	private static function sampleWindowData(array $window, bool $active): array
	{
		return [
			'active' => $active,
			'window_id' => (string) ($window['window_id'] ?? ''),
			'rate' => (float) ($window['rate'] ?? 0),
			'routes' => (array) ($window['routes'] ?? []),
			'created_at' => (string) ($window['created_at'] ?? ''),
			'expires_at' => (string) ($window['expires_at'] ?? ''),
			'remaining_seconds' => max(0, (int) ($window['expires_epoch'] ?? 0) - time()),
			'sample_file' => self::REQUEST_SAMPLE_ARCHIVE_LABEL,
			'window_file' => 'Runtime/Cache/db-query-sample-window.json',
		];
	}

	/**
	 * 以文件锁写入采样窗口控制文件。
	 *
	 * @param array<string,mixed> $window 采样窗口
	 * @return bool
	 */
	private static function writeSampleWindow(array $window): bool
	{
		$runtime = rtrim((string) C('RUNTIME'), '/\\');
		if ($runtime === '') {
			return false;
		}
		$directory = $runtime . DIRECTORY_SEPARATOR . 'Cache';
		if (!is_dir($directory) && !@\Candy\Extend\Dir::create($directory)) {
			return false;
		}

		$path = $directory . DIRECTORY_SEPARATOR . self::SAMPLE_WINDOW_BASENAME;
		$handle = @fopen($path, 'c+b');
		if ($handle === false) {
			return false;
		}

		$locked = false;
		try {
			$locked = @flock($handle, LOCK_EX);
			if (!$locked) {
				return false;
			}
			$payload = json_encode($window, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if (!is_string($payload)) {
				return false;
			}
			@ftruncate($handle, 0);
			rewind($handle);
			$written = @fwrite($handle, $payload);
			@fflush($handle);
			return $written === strlen($payload);
		} catch (\Throwable) {
			return false;
		} finally {
			if ($locked) {
				flock($handle, LOCK_UN);
			}
			fclose($handle);
		}
	}

	/**
	 * 持久化文件路径
	 *
	 * @return string
	 */
	private static function storePath(): string
	{
		$dir = rtrim((string) C('RUNTIME'), '/\\') . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR;
		if (!is_dir($dir)) {
			@\Candy\Extend\Dir::create($dir);
		}
		return $dir . self::STORE_BASENAME;
	}

	/**
	 * 读取已累计指标（带共享锁）
	 *
	 * @return array<string,mixed>
	 */
	private static function loadStore(): array
	{
		$path = self::storePath();
		if (!is_file($path)) {
			return self::emptyStats();
		}
		$fp = @fopen($path, 'rb');
		if ($fp === false) {
			return self::emptyStats();
		}
		flock($fp, LOCK_SH);
		$raw = stream_get_contents($fp);
		flock($fp, LOCK_UN);
		fclose($fp);
		$data = json_decode((string) $raw, true);
		if (!is_array($data)) {
			return self::emptyStats();
		}
		return self::normalizeStore($data);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function normalizeStore(array $data): array
	{
		$base = self::emptyStats();
		$base['queries'] = (int) ($data['queries'] ?? 0);
		$base['slow_queries'] = (int) ($data['slow_queries'] ?? 0);
		$base['total_ms'] = (float) ($data['total_ms'] ?? 0);
		$base['connections_created'] = (int) ($data['connections_created'] ?? 0);
		$base['reconnects'] = (int) ($data['reconnects'] ?? 0);
		$base['async_enqueued'] = (int) ($data['async_enqueued'] ?? 0);
		$base['async_processed'] = (int) ($data['async_processed'] ?? 0);
		$base['async_errors'] = (int) ($data['async_errors'] ?? 0);
		$hits = $data['role_hits'] ?? [];
		$base['role_hits'] = [
			'write' => (int) ($hits['write'] ?? 0),
			'read' => (int) ($hits['read'] ?? 0),
		];
		$pools = $data['pool_keys'] ?? [];
		$base['pool_keys'] = is_array($pools) ? $pools : [];
		return $base;
	}

	/**
	 * 合并两份统计
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	private static function mergeStats(array $a, array $b): array
	{
		$out = self::normalizeStore($a);
		$b = self::normalizeStore($b);
		$out['queries'] += $b['queries'];
		$out['slow_queries'] += $b['slow_queries'];
		$out['total_ms'] += $b['total_ms'];
		$out['connections_created'] += $b['connections_created'];
		$out['reconnects'] += $b['reconnects'];
		$out['async_enqueued'] += $b['async_enqueued'];
		$out['async_processed'] += $b['async_processed'];
		$out['async_errors'] += $b['async_errors'];
		$out['role_hits']['write'] += $b['role_hits']['write'];
		$out['role_hits']['read'] += $b['role_hits']['read'];
		foreach ($b['pool_keys'] as $k => $n) {
			$out['pool_keys'][$k] = (int) ($out['pool_keys'][$k] ?? 0) + (int) $n;
		}
		return $out;
	}

	/**
	 * 将本请求增量刷入持久化文件
	 *
	 * 由 runApplicationDestructor() 在请求结束时统一调用
	 *
	 * @return void
	 */
	public static function flush(): void
	{
		self::flushRequestSample();
		if (!self::usePersist() || self::$flushedThisRequest) {
			return;
		}
		self::bootLocal();
		$path = self::storePath();
		$fp = @fopen($path, 'c+b');
		if ($fp === false) {
			return;
		}
		flock($fp, LOCK_EX);
		$raw = stream_get_contents($fp);
		$stored = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
		$merged = self::mergeStats(is_array($stored) ? $stored : [], self::$stats);
		$merged['updated_at'] = date('Y-m-d H:i:s');
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, (string) json_encode($merged, JSON_UNESCAPED_UNICODE));
		fflush($fp);
		flock($fp, LOCK_UN);
		fclose($fp);
		// 已并入磁盘，清空本请求增量，避免重复累计
		self::$stats = self::emptyStats();
		self::$flushedThisRequest = true;
	}

	/**
	 * 记录一次查询
	 *
	 * @param string $sql SQL
	 * @param float $ms 耗时毫秒
	 * @param string $alias 隔离别名
	 * @param string $role write|read
	 * @param array $bindings 已执行的绑定参数，仅用于计算不可逆摘要
	 * @return void
	 */
	public static function recordQuery(
		string $sql,
		float $ms,
		string $alias,
		string $role,
		array $bindings = []
	): void
	{
		self::bootLocal();
		self::$stats['queries']++;
		self::$stats['total_ms'] += $ms;
		if (isset(self::$stats['role_hits'][$role])) {
			self::$stats['role_hits'][$role]++;
		}
		self::recordRequestQuery($sql, $ms, $role, $bindings);
		$threshold = (float) (G('DB_SLOW_MS') ?? C('DB_SLOW_MS') ?? 500);
		if ($threshold > 0 && $ms >= $threshold) {
			self::$stats['slow_queries']++;
			self::writeSlowLog($sql, $ms, $alias, $role);
		}
	}

	/**
	 * 记录新连接
	 *
	 * @param string $poolKey 池键
	 * @return void
	 */
	public static function recordConnectionCreated(string $poolKey): void
	{
		self::bootLocal();
		self::$stats['connections_created']++;
		self::$stats['pool_keys'][$poolKey] = (self::$stats['pool_keys'][$poolKey] ?? 0) + 1;
		self::incrementRequestSample('connections_created');
	}

	/**
	 * 记录重连
	 *
	 * @return void
	 */
	public static function recordReconnect(): void
	{
		self::bootLocal();
		self::$stats['reconnects']++;
		self::incrementRequestSample('reconnects');
	}

	/**
	 * 记录异步入队
	 *
	 * @return void
	 */
	public static function recordAsyncEnqueue(): void
	{
		self::bootLocal();
		self::$stats['async_enqueued']++;
	}

	/**
	 * 记录异步消费成功
	 *
	 * @param int $count 条数
	 * @return void
	 */
	public static function recordAsyncProcessed(int $count = 1): void
	{
		self::bootLocal();
		self::$stats['async_processed'] += max(0, $count);
	}

	/**
	 * 记录异步消费失败
	 *
	 * @param int $count 条数
	 * @return void
	 */
	public static function recordAsyncError(int $count = 1): void
	{
		self::bootLocal();
		self::$stats['async_errors'] += max(0, $count);
	}

	/**
	 * 获取指标快照
	 *
	 * 持久化模式下返回「磁盘累计 + 本请求未刷盘增量」。
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array
	{
		self::bootLocal();
		$merged = self::$stats;
		$scope = 'worker';
		if (self::usePersist()) {
			$merged = self::mergeStats(self::loadStore(), self::$stats);
			$scope = 'aggregate';
		}
		$queries = (int) $merged['queries'];
		return [
			'scope' => $scope,
			'queries' => $queries,
			'slow_queries' => (int) $merged['slow_queries'],
			'avg_ms' => $queries > 0 ? round($merged['total_ms'] / $queries, 2) : 0,
			'total_ms' => round((float) $merged['total_ms'], 2),
			'connections_created' => (int) $merged['connections_created'],
			'reconnects' => (int) $merged['reconnects'],
			'role_hits' => $merged['role_hits'],
			'active_pool_keys' => count($merged['pool_keys']),
			'pool_keys' => $merged['pool_keys'],
			'async_enqueued' => (int) $merged['async_enqueued'],
			'async_processed' => (int) $merged['async_processed'],
			'async_errors' => (int) $merged['async_errors'],
		];
	}

	/**
	 * 重置指标（本地 + 持久化文件）
	 *
	 * @return void
	 */
	public static function reset(): void
	{
		self::$stats = self::emptyStats();
		self::$flushedThisRequest = false;
		self::$requestSampleRate = null;
		self::$forcedRequestSampleRate = null;
		if (function_exists('T')) {
			T('DB_QUERY_SAMPLE_STATE', false);
			T('DB_QUERY_SAMPLE_WINDOW', false);
		}
		$path = self::storePath();
		if (is_file($path)) {
			@unlink($path);
		}
	}

	/**
	 * 写慢查询日志
	 *
	 * @param string $sql SQL
	 * @param float $ms 毫秒
	 * @param string $alias 别名
	 * @param string $role 角色
	 * @return void
	 * @access private
	 * @static
	 */
	private static function writeSlowLog(string $sql, float $ms, string $alias, string $role): void
	{
		$logDir = C('RUNTIME') . 'Logs/';
		if (!is_dir($logDir)) {
			@\Candy\Extend\Dir::create($logDir);
		}
		$line = date('Y-m-d H:i:s') . "\t{$ms}ms\t{$alias}\t{$role}\t" . str_replace(["\r", "\n"], ' ', $sql) . PHP_EOL;
		@file_put_contents($logDir . 'sql-slow.log', $line, FILE_APPEND | LOCK_EX);
	}

	/**
	 * 取得当前请求的采样状态；仅在采样开启且处于 HTTP 请求时建立状态。
	 *
	 * @return array<string,mixed>|null
	 */
	private static function requestSampleState(): ?array
	{
		$rate = self::querySampleRate();
		if ($rate <= 0.0 || !function_exists('T') || !function_exists('I')) {
			return null;
		}
		$window = self::requestSampleWindow();

		$requestUuid = trim((string) I('server.REDIRECT_REQUEST_UUID', '', ''));
		if (str_starts_with(strtolower(PHP_SAPI), 'cli') && $requestUuid === '') {
			return null;
		}

		$state = T('DB_QUERY_SAMPLE_STATE');
		if (is_array($state)) {
			return $state;
		}
		if ($state === false) {
			return null;
		}

		$selected = $rate >= 1.0 || mt_rand() <= (int) ($rate * mt_getrandmax());
		if (!$selected) {
			T('DB_QUERY_SAMPLE_STATE', false);
			return null;
		}
		$route = self::requestRoute();
		if (is_array($window) && !in_array($route, (array) $window['routes'], true)) {
			T('DB_QUERY_SAMPLE_STATE', false);
			return null;
		}

		$state = [
			'method' => self::requestMethod(),
			'route' => $route,
			'sampling_window_id' => (string) ($window['window_id'] ?? ''),
			'query_count' => 0,
			'read_queries' => 0,
			'write_queries' => 0,
			'other_queries' => 0,
			'total_db_ms' => 0.0,
			'connections_created' => 0,
			'reconnects' => 0,
			'fingerprints' => [],
			'fingerprints_omitted_count' => 0,
			'query_shapes' => [],
			'query_shapes_omitted_count' => 0,
			'query_durations' => [],
			'query_durations_omitted_count' => 0,
		];
		T('DB_QUERY_SAMPLE_STATE', $state);
		return $state;
	}

	/**
	 * 读取并规范化 HTTP 请求方法。
	 *
	 * @return string
	 */
	private static function requestMethod(): string
	{
		$method = strtoupper(trim((string) I('server.REQUEST_METHOD', '', '')));
		$allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
		return in_array($method, $allowed, true) ? $method : 'OTHER';
	}

	/**
	 * 获取脱敏后的路由，不保留查询参数或动态路径段。
	 *
	 * @return string
	 */
	private static function requestRoute(): string
	{
		$uri = (string) I('server.REQUEST_URI', '', '');
		$path = parse_url($uri, PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			$path = (string) I('server.PATH_INFO', '/', '');
		}

		$segments = array_values(array_filter(
			explode('/', trim($path, '/')),
			static fn(string $segment): bool => $segment !== ''
		));
		if (isset($segments[0]) && strtolower($segments[0]) === 'index.php') {
			array_shift($segments);
		}
		$htmlActionIndex = null;
		foreach ($segments as $index => $segment) {
			if (str_ends_with(strtolower($segment), '.html')) {
				$htmlActionIndex = $index;
			}
		}
		if ($htmlActionIndex !== null) {
			$start = max(0, $htmlActionIndex - 2);
			$segments = array_slice($segments, $start, $htmlActionIndex - $start + 1);
		} else {
			$segments = array_slice($segments, -3);
		}
		foreach ($segments as &$segment) {
			if (
				preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}(?:\.html)?$/D', $segment) !== 1
				|| preg_match('/^[a-f0-9-]{16,}$/iD', $segment) === 1
			) {
				$segment = '{param}';
			}
		}
		unset($segment);

		$route = '/' . implode('/', $segments);
		return $route === '/' ? '/' : substr($route, 0, 180);
	}

	/**
	 * 将一次查询聚合到当前请求，不保存 SQL 文本、参数、别名或租户标识。
	 *
	 * @param string $sql SQL 文本，仅用于计算不可逆指纹
	 * @param float $ms 查询耗时毫秒
	 * @param string $role read|write
	 * @param array $bindings 已执行的绑定参数，仅用于计算不可逆摘要
	 * @return void
	 */
	private static function recordRequestQuery(
		string $sql,
		float $ms,
		string $role,
		array $bindings = []
	): void
	{
		$state = self::requestSampleState();
		if ($state === null) {
			return;
		}

		$state['query_count']++;
		if ($role === 'read') {
			$state['read_queries']++;
		} elseif ($role === 'write') {
			$state['write_queries']++;
		} else {
			$state['other_queries']++;
		}
		$elapsed = max(0.0, $ms);
		$state['total_db_ms'] = round((float) $state['total_db_ms'] + $elapsed, 4);
		if (count($state['query_durations']) < self::REQUEST_QUERY_DURATION_LIMIT) {
			$state['query_durations'][] = round($elapsed, 4);
		} else {
			$state['query_durations_omitted_count']++;
		}

		$executionFingerprint = self::queryExecutionFingerprint($sql, $bindings);
		self::addFingerprint(
			$state,
			'fingerprints',
			'fingerprints_omitted_count',
			$executionFingerprint,
			$elapsed
		);
		$shapeFingerprint = self::queryFingerprint($sql);
		self::addFingerprint(
			$state,
			'query_shapes',
			'query_shapes_omitted_count',
			$shapeFingerprint,
			$elapsed
		);
		T('DB_QUERY_SAMPLE_STATE', $state);
	}

	/**
	 * 将一次查询加入有限大小的指纹桶。
	 *
	 * @param array<string,mixed> $state 当前请求采样状态
	 * @param string $bucket 指纹桶名称
	 * @param string $omittedKey 超出容量时的计数键
	 * @param string $fingerprint 不可逆指纹
	 * @param float $elapsed 查询耗时毫秒
	 * @return void
	 */
	private static function addFingerprint(
		array &$state,
		string $bucket,
		string $omittedKey,
		string $fingerprint,
		float $elapsed
	): void
	{
		if (isset($state[$bucket][$fingerprint])) {
			$state[$bucket][$fingerprint]['count']++;
			$state[$bucket][$fingerprint]['total_ms'] = round(
				(float) $state[$bucket][$fingerprint]['total_ms'] + $elapsed,
				4
			);
			return;
		}
		if (count($state[$bucket]) < self::REQUEST_FINGERPRINT_LIMIT) {
			$state[$bucket][$fingerprint] = [
				'count' => 1,
				'total_ms' => round($elapsed, 4),
			];
			return;
		}
		$state[$omittedKey]++;
	}

	/**
	 * 累加当前请求的连接事件。
	 *
	 * @param string $metric 指标键
	 * @return void
	 */
	private static function incrementRequestSample(string $metric): void
	{
		$state = self::requestSampleState();
		if ($state === null || !isset($state[$metric])) {
			return;
		}
		$state[$metric]++;
		T('DB_QUERY_SAMPLE_STATE', $state);
	}

	/**
	 * 生成不落原文的 SQL 结构指纹，合并字符串与数值字面量差异。
	 *
	 * @param string $sql SQL 文本
	 * @return string
	 */
	private static function queryFingerprint(string $sql): string
	{
		$normalized = preg_replace("/'(?:''|\\\\.|[^'\\\\])*'/s", '?', substr($sql, 0, 65536));
		$normalized = is_string($normalized) ? $normalized : $sql;
		$normalized = preg_replace(
			'~(?<![A-Za-z_])(?:0x[0-9a-f]+|[+-]?\d+(?:\.\d+)?(?:e[+-]?\d+)?)(?![A-Za-z_])~i',
			'?',
			$normalized
		);
		$normalized = is_string($normalized) ? $normalized : $sql;
		$normalized = preg_replace('~\s+~', ' ', strtolower(trim($normalized)));
		$normalized = is_string($normalized) ? $normalized : strtolower(trim($sql));
		return hash('sha256', $normalized);
	}

	/**
	 * 生成包含执行参数摘要的精确查询指纹，不落原始参数。
	 *
	 * @param string $sql SQL 文本
	 * @param array $bindings 已执行的绑定参数
	 * @return string
	 */
	private static function queryExecutionFingerprint(string $sql, array $bindings): string
	{
		$bindingDigest = '';
		if ($bindings !== []) {
			$bindingRows = [];
			foreach ($bindings as $key => $value) {
				$encoded = json_encode(
					$value,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
				);
				$bindingRows[] = [
					'key' => (string) $key,
					'type' => get_debug_type($value),
					'digest' => hash('sha256', is_string($encoded) ? $encoded : get_debug_type($value)),
				];
			}
			$bindingDigest = (string) json_encode(
				$bindingRows,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		}
		return hash('sha256', substr($sql, 0, 65536) . "\0" . $bindingDigest);
	}

	/**
	 * 请求收尾时保存一条有界、脱敏的 SQL 聚合样本。
	 *
	 * @return void
	 */
	private static function flushRequestSample(): void
	{
		if (!function_exists('T')) {
			return;
		}
		$state = T('DB_QUERY_SAMPLE_STATE');
		if (!is_array($state)) {
			return;
		}
		T('DB_QUERY_SAMPLE_STATE', false);
		if ((int) $state['query_count'] === 0 && (int) $state['connections_created'] === 0) {
			return;
		}

		$fingerprints = self::sortFingerprints((array) $state['fingerprints']);
		$queryShapes = self::sortFingerprints((array) ($state['query_shapes'] ?? []));
		$topFingerprints = self::fingerprintRows($fingerprints);
		$topQueryShapes = self::fingerprintRows($queryShapes);
		$duplicateFingerprintCount = self::duplicateFingerprintCount($fingerprints);
		$duplicateQueryCount = self::duplicateQueryCount($fingerprints);
		$duplicateQueryShapeCount = self::duplicateFingerprintCount($queryShapes);
		$duplicateQueryShapeRepeatCount = self::duplicateQueryCount($queryShapes);

		self::storeRequestSample([
			'sampled_at' => date(DATE_ATOM),
			'method' => (string) $state['method'],
			'route' => (string) $state['route'],
			'sampling_window_id' => (string) ($state['sampling_window_id'] ?? ''),
			'query_count' => (int) $state['query_count'],
			'read_queries' => (int) $state['read_queries'],
			'write_queries' => (int) $state['write_queries'],
			'other_queries' => (int) $state['other_queries'],
			'total_db_ms' => round((float) $state['total_db_ms'], 4),
			'connections_created' => (int) $state['connections_created'],
			'reconnects' => (int) $state['reconnects'],
			'duplicate_fingerprint_count' => $duplicateFingerprintCount,
			'duplicate_query_count' => $duplicateQueryCount,
			'duplicate_query_count_semantics' => 'exact_sql_and_bindings',
			'duplicate_query_shape_count' => $duplicateQueryShapeCount,
			'duplicate_query_shape_repeat_count' => $duplicateQueryShapeRepeatCount,
			'duplicate_query_shape_semantics' => 'normalized_sql_without_bindings',
			'fingerprints_omitted_count' => (int) $state['fingerprints_omitted_count'],
			'fingerprints' => $topFingerprints,
			'query_shapes_omitted_count' => (int) ($state['query_shapes_omitted_count'] ?? 0),
			'query_shapes' => $topQueryShapes,
			'query_durations' => array_values($state['query_durations']),
			'query_duration_omitted_count' => (int) $state['query_durations_omitted_count'],
		]);
	}

	/**
	 * 按重复次数降序排列指纹桶。
	 *
	 * @param array<string,array<string,int|float>> $fingerprints 指纹桶
	 * @return array<string,array<string,int|float>> 排序后的指纹桶
	 */
	private static function sortFingerprints(array $fingerprints): array
	{
		uasort($fingerprints, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);
		return $fingerprints;
	}

	/**
	 * 生成有界的指纹输出行。
	 *
	 * @param array<string,array<string,int|float>> $fingerprints 指纹桶
	 * @return array<int,array<string,int|float|string>>
	 */
	private static function fingerprintRows(array $fingerprints): array
	{
		$rows = [];
		foreach (
			array_slice($fingerprints, 0, self::REQUEST_FINGERPRINT_OUTPUT_LIMIT, true)
			as $fingerprint => $metrics
		) {
			$rows[] = [
				'fingerprint' => $fingerprint,
				'count' => (int) $metrics['count'],
				'total_ms' => round((float) $metrics['total_ms'], 4),
			];
		}
		return $rows;
	}

	/**
	 * 统计发生重复的指纹键数量。
	 *
	 * @param array<string,array<string,int|float>> $fingerprints 指纹桶
	 * @return int
	 */
	private static function duplicateFingerprintCount(array $fingerprints): int
	{
		$count = 0;
		foreach ($fingerprints as $metrics) {
			if ((int) $metrics['count'] > 1) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * 统计重复指纹桶中超出首次执行的查询数。
	 *
	 * @param array<string,array<string,int|float>> $fingerprints 指纹桶
	 * @return int
	 */
	private static function duplicateQueryCount(array $fingerprints): int
	{
		$count = 0;
		foreach ($fingerprints as $metrics) {
			$count += max(0, (int) $metrics['count'] - 1);
		}
		return $count;
	}

	/**
	 * 以文件锁原子更新最新请求样本，并将保留数量限制在固定上限内。
	 *
	 * @param array<string,mixed> $sample 已脱敏的请求统计
	 * @return void
	 */
	private static function storeRequestSample(array $sample): void
	{
		foreach (self::requestSamplePaths() as $path) {
			self::appendRequestSample($path, $sample);
		}
	}

	/**
	 * 返回请求采样的运行时文件与耐久归档路径。
	 *
	 * 归档路径可由测试或部署配置覆盖；默认位于 Data/observability，避免清理 Runtime 时丢失审计证据。
	 *
	 * @return array<int,string> 去重后的采样文件路径
	 */
	private static function requestSamplePaths(): array
	{
		$paths = [];
		$runtime = rtrim((string) C('RUNTIME'), '/\\');
		if ($runtime !== '') {
			$paths[] = $runtime . DIRECTORY_SEPARATOR . 'Logs'
				. DIRECTORY_SEPARATOR . self::REQUEST_SAMPLE_BASENAME;
		}

		$configuredArchive = C('DB_QUERY_SAMPLE_ARCHIVE_PATH');
		$archivePath = is_string($configuredArchive) ? trim($configuredArchive) : '';
		if ($archivePath === '') {
			$root = rtrim((string) C('CANDYROOT'), '/\\');
			if ($root !== '') {
				$archivePath = $root . DIRECTORY_SEPARATOR . 'Data'
					. DIRECTORY_SEPARATOR . 'observability'
					. DIRECTORY_SEPARATOR . self::REQUEST_SAMPLE_BASENAME;
			}
		}
		if ($archivePath !== '') {
			$paths[] = $archivePath;
		}

		return array_values(array_unique($paths));
	}

	/**
	 * 以文件锁原子追加一条请求采样。
	 *
	 * @param string $path 采样文件路径
	 * @param array<string,mixed> $sample 已脱敏的请求统计
	 * @return void
	 */
	private static function appendRequestSample(string $path, array $sample): void
	{
		$path = trim($path);
		if ($path === '') {
			return;
		}
		$directory = dirname($path);
		if (!is_dir($directory) && !@\Candy\Extend\Dir::create($directory)) {
			return;
		}
		$handle = @fopen($path, 'c+b');
		if ($handle === false) {
			return;
		}

		$locked = false;
		try {
			$locked = @flock($handle, LOCK_EX);
			if (!$locked) {
				return;
			}
			rewind($handle);
			$raw = stream_get_contents($handle);
			$stored = json_decode((string) $raw, true);
			$samples = is_array($stored) && is_array($stored['samples'] ?? null)
				? array_values($stored['samples'])
				: [];
			$samples[] = $sample;
			if (count($samples) > self::REQUEST_SAMPLE_LIMIT) {
				$samples = array_slice($samples, -self::REQUEST_SAMPLE_LIMIT);
			}
			$payload = json_encode([
				'version' => 1,
				'updated_at' => date(DATE_ATOM),
				'samples' => $samples,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if (!is_string($payload)) {
				return;
			}
			@ftruncate($handle, 0);
			rewind($handle);
			@fwrite($handle, $payload);
			@fflush($handle);
		} catch (\Throwable) {
			// 采样存储失败不得影响业务请求。
		} finally {
			if ($locked) {
				flock($handle, LOCK_UN);
			}
			fclose($handle);
		}
	}
}
