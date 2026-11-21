<?php
/**
 * 缓存管理 Trait
 *
 * ORM 查询结果缓存：键含库隔离别名，写操作以版本号失效（兼容 Redis/Memcached）
 *
 * @package Candy\Extend\DB\Trait
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-10-08 13:53:52 $
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Trait;
use \Candy\Core\Debug;

trait Cache {
	/**
	 * 进程内回退缓存：alias => dbname => tabName => md5 => data
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	static $results = [];

	/**
	 * 内存缓存大小限制（默认1000条记录，按表）
	 */
	private static $cacheLimit = 1000;

	/**
	 * 远程 ORM 查询结果缓存 TTL（秒）
	 *
	 * 写操作仍通过表版本号主动失效；TTL 负责回收已无法再命中的历史版本键，
	 * 防止 Redis/Memcached 长期积累高基数查询指纹。
	 */
	private const REMOTE_QUERY_CACHE_TTL = 60;

	/**
	 * 是否启用 ORM 查询结果缓存
	 *
	 * - cache(true)  强制开启
	 * - cache(false) 强制关闭
	 * - 未设置时：自动开启。查询缓存走「同驱动 + 独立 prefix（query:）」的派生实例，
	 *   File 下自动落成独立 .data 文件，不与业务 KV 抢槽位；无需任何配置。
	 */
	private function queryCacheStore(): ?\Candy\Core\Cache
	{
		static $resolved = false;
		if ($resolved !== false) {
			return $resolved;
		}

		try {
			$cacheInc = loadConfig('Config') ?: [];
			$stores = $cacheInc['CACHE']['stores'] ?? [];

			// ① 使用者显式配了 CACHE.stores.query（高级覆盖）→ 直接用它
			if (isset($stores['query'])) {
				$resolved = \Candy\Core\Cache::store('query');
				return $resolved;
			}

			// ② 零配置自动派生：把 query 缓存放进「同驱动 + 独立 prefix」的实例
			//    File 的 prefix 参与 .data 文件名 → 自动落成独立文件，不与业务 KV 抢槽位；
			//    Redis/Memcached 只是 key 命名空间，本来也不存在槽位挤占。
			//    使用者无需任何配置，开启 File 驱动即生效。
			$base = (string) (\Candy\Core\Cache::storeConfig()['prefix'] ?? '');
			$resolved = \Candy\Core\Cache::store(null, ['prefix' => $base . 'query:']);
			return $resolved;
		} catch (\Throwable) {
			// 解析失败按「不可用」处理，回退进程内数组
		}

		$resolved = null;
		return null;
	}

	private function shouldQueryCache(): bool
	{
		$flag = $this->sql['querycache'] ?? '';

		if ($flag === false) {
			return false;
		}
		if ($flag === true) {
			return true;
		}

		return $this->queryCacheStore() !== null;
	}

	/**
	 * 库隔离作用域（别名）
	 *
	 * 只用别名、不用 write/read 池键，保证主从共享同一结果缓存与失效版本
	 *
	 * @return string
	 */
	private function queryCacheAlias(): string
	{
		if (method_exists($this, 'getDbAlias')) {
			$alias = (string)$this->getDbAlias();
			if ($alias !== '') {
				return $alias;
			}
		}
		return 'default';
	}

	/**
	 * 表级缓存命名空间前缀（不含版本与查询指纹）
	 *
	 * 格式：dbqc:{alias}:{dbname}_{tabName}:c{connectionHash}
	 * 同一连接配置共享缓存，不同连接配置隔离缓存。
	 *
	 * @return string
	 */
	private function queryCacheTablePrefix(): string
	{
		$alias = $this->queryCacheAlias();
		$dbname = $this->queryCacheDatabaseName();
		$tab = (string)($this->tabName ?? '');
		$connection = $this->queryCacheConnectionFingerprint();
		return 'dbqc:' . $alias . ':' . $dbname . '_' . $tab . ':c' . $connection;
	}

	/**
	 * 获取查询缓存使用的稳定库名。
	 *
	 * 读写分库查询执行期间会切换活动节点；这里固定使用 write 节点的库名，
	 * 避免同一套连接配置在读写切换前后生成不同的表缓存命名空间。
	 *
	 * @return string 逻辑数据库名
	 */
	private function queryCacheDatabaseName(): string
	{
		$config = $this->queryCacheConnectionConfig();
		$write = $config['write'] ?? $config['master'] ?? $config['WRITE'] ?? $config['MASTER'] ?? null;
		if (is_array($write) && isset($write['DBNAME'])) {
			return (string)$write['DBNAME'];
		}
		if (is_array($write) && isset($write['dbname'])) {
			return (string)$write['dbname'];
		}
		if (isset($config['DBNAME'])) {
			return (string)$config['DBNAME'];
		}
		if (isset($config['dbname'])) {
			return (string)$config['dbname'];
		}
		return (string)($this->dbname ?? '');
	}

	/**
	 * 获取完整连接配置，兼容默认扁平连接未回填 currentConfig 的旧路径。
	 *
	 * @return array 连接配置或读写分库配置包
	 */
	private function queryCacheConnectionConfig(): array
	{
		$config = method_exists($this, 'getCurrentConfig')
			? $this->getCurrentConfig()
			: [];
		if (is_array($config) && $config !== []) {
			return $config;
		}
		return isset($this->activeConnectionConfig) && is_array($this->activeConnectionConfig)
			? $this->activeConnectionConfig
			: [];
	}

	/**
	 * 生成连接配置身份指纹。
	 *
	 * 对当前连接配置做稳定规范化后计算 hash，不把配置明文写入缓存键。
	 * 配置变更到不同数据源时，即使别名相同也不会复用旧查询结果。
	 *
	 * @return string 连接配置身份的 MD5 指纹
	 */
	private function queryCacheConnectionFingerprint(): string
	{
		$config = $this->queryCacheConnectionConfig();
		$identity = [
			'driver' => method_exists($this, 'getResolvedDriver')
				? (string)$this->getResolvedDriver()
				: '',
			'config' => $this->normalizeQueryCacheConnectionConfig($config),
		];
		return md5(serialize($identity));
	}

	/**
	 * 规范化连接配置。
	 *
	 * 递归排序配置键，保证同一配置因数组键顺序不同仍生成相同身份指纹。
	 *
	 * @param mixed $config 连接配置片段
	 * @return mixed 规范化后的可序列化配置
	 */
	private function normalizeQueryCacheConnectionConfig(mixed $config): mixed
	{
		if (!is_array($config)) {
			return is_scalar($config) || $config === null ? $config : (string)$config;
		}

		$normalized = [];
		foreach ($config as $key => $value) {
			$name = strtoupper((string)$key);
			$normalized[$name] = $this->normalizeQueryCacheConnectionConfig($value);
		}
		ksort($normalized);
		return $normalized;
	}

	/**
	 * 查询指纹（条件 + 当前 sql 片段）
	 *
	 * @param array $args 附加参数
	 * @return string
	 */
	private function queryCacheFingerprint(array $args): string
	{
		$state = $this->sql;
		$state['_connection'] = $this->queryCacheConnectionFingerprint();
		return md5(json(array_merge($args, $state)));
	}

	/**
	 * 将 ORM 查询缓存逻辑键标记为平台共享键
	 *
	 * dbqc 键本身已含 alias / dbname / 连接指纹，足以隔离库与租户连接；
	 * 若再挂请求级 TENANT_ID 前缀，会在 Portal（有租户）与账号中心（无租户）之间
	 * 分裂成两套版本号：改密只 bump 平台侧，Portal 仍读到旧 session_version，
	 * 表现为 SSO「尚未获得当前业务平台访问权限」。
	 *
	 * @param string $logicalKey dbqc 逻辑键（不含 Cache 驱动前缀）
	 * @return string 以 @ 开头的共享逻辑键
	 */
	private function queryCacheSharedKey(string $logicalKey): string
	{
		return '@' . ltrim($logicalKey, '@');
	}

	/**
	 * 读取表缓存版本（默认 1）
	 *
	 * @param object $memCache Cache 实例
	 * @return int
	 */
	private function queryCacheVersion(object $memCache): int
	{
		$verKey = $this->queryCacheSharedKey($this->queryCacheTablePrefix() . ':ver');
		$ver = $memCache->get($verKey);
		if ($ver === false || $ver === null || $ver === '') {
			return 1;
		}
		return max(1, (int)$ver);
	}

	/**
	 * 递增表缓存版本，使旧 md5 键全部失效（无需前缀扫描，Memcached 可用）
	 *
	 * @param object $memCache Cache 实例
	 * @return void
	 */
	private function bumpQueryCacheVersion(object $memCache): void
	{
		$verKey = $this->queryCacheSharedKey($this->queryCacheTablePrefix() . ':ver');
		$cur = $this->queryCacheVersion($memCache);
		$memCache->set($verKey, $cur + 1, 0);
	}

	/**
	 * 组装远程查询缓存完整键
	 *
	 * 格式：@dbqc:{alias}:{dbname}_{tab}:v{ver}:{md5}（@ 表示平台共享命名空间）
	 *
	 * @param array $args 附加参数
	 * @param object $memCache Cache 实例
	 * @return string
	 */
	private function buildRemoteQueryCacheKey(array $args, object $memCache): string
	{
		$ver = $this->queryCacheVersion($memCache);
		return $this->queryCacheSharedKey(
			$this->queryCacheTablePrefix() . ':v' . $ver . ':' . $this->queryCacheFingerprint($args)
		);
	}

	/**
	 * 当前查询是否允许进入结果缓存
	 *
	 * @return bool
	 */
	private function canUseQueryCacheSlot(): bool
	{
		if (!isset($this->sqlArgs('limit')[0]) || $this->sqlArgs('limit')[0] === '') {
			return false;
		}
		if (stristr((string)$this->sqlArgs('limit')[0], 'rand()') !== false) {
			return false;
		}
		return true;
	}

	/**
	 * 将查询结果编码为可区分缓存未命中的值。
	 *
	 * 远程 Cache 的 null 表示“键不存在”，因此 find() 的空结果需要内部哨兵承载。
	 *
	 * @param mixed $data 查询结果
	 * @return mixed 可写入缓存的结果
	 */
	private function encodeQueryCacheData(mixed $data): mixed
	{
		if ($data !== null) {
			return $data;
		}
		return ['__candy_query_cache_marker' => 'null'];
	}

	/**
	 * 还原查询缓存中的空结果哨兵。
	 *
	 * @param mixed $data 缓存结果
	 * @return mixed 业务查询结果或 null
	 */
	private function decodeQueryCacheData(mixed $data): mixed
	{
		if (
			is_array($data)
			&& count($data) === 1
			&& ($data['__candy_query_cache_marker'] ?? null) === 'null'
		) {
			return null;
		}
		return $data;
	}

	/**
	 * 设置数据缓存
	 *
	 * 将查询结果写入 queryCacheStore()；无可用 store 时回退进程内数组。
	 * 随机查询 / 无 limit 不缓存。
	 *
	 * @param array $args 查询参数数组，用于生成缓存键
	 * @param mixed $data 要缓存的数据
	 * @return void
	 */
	private function setDataCache(array $args, mixed $data): void
	{
		if (!$this->shouldQueryCache() || !$this->canUseQueryCacheSlot()) {
			return;
		}
		$cacheData = $this->encodeQueryCacheData($data);

		if ($memCache = $this->queryCacheStore()) {
			$key = $this->buildRemoteQueryCacheKey($args, $memCache);
			$memCache->set($key, $cacheData, self::REMOTE_QUERY_CACHE_TTL);
			return;
		}

		$alias = $this->queryCacheAlias();
		$dbname = $this->queryCacheDatabaseName();
		$tab = (string)($this->tabName ?? '');
		$md5Key = $this->queryCacheFingerprint($args);
		self::$results[$alias][$dbname][$tab][$md5Key] = $cacheData;
		$this->checkCacheLimit();
	}

	/**
	 * 获取数据缓存
	 *
	 * @param array $args 查询参数数组，用于生成缓存键
	 * @return mixed 缓存的数据，如果没有缓存则返回 false
	 */
	private function getDataCache(array $args): mixed
	{
		if (!$this->shouldQueryCache() || !$this->canUseQueryCacheSlot()) {
			return false;
		}

		if ($memCache = $this->queryCacheStore()) {
			$key = $this->buildRemoteQueryCacheKey($args, $memCache);
			$data = $memCache->get($key);
			return $data === null ? false : $this->decodeQueryCacheData($data);
		}

		$alias = $this->queryCacheAlias();
		$dbname = $this->queryCacheDatabaseName();
		$tab = (string)($this->tabName ?? '');
		$md5Key = $this->queryCacheFingerprint($args);
		$data = self::$results[$alias][$dbname][$tab][$md5Key] ?? false;
		return $data === false ? false : $this->decodeQueryCacheData($data);
	}

	/**
	 * 删除数据缓存
	 *
	 * 写库后调用：远程驱动递增表版本号；进程内缓存按别名+库+表整组清除。
	 *
	 * @return void
	 */
	private function delDataCache(): void
	{
		if ($memCache = $this->queryCacheStore()) {
			$this->bumpQueryCacheVersion($memCache);
		}

		$alias = $this->queryCacheAlias();
		$dbname = $this->queryCacheDatabaseName();
		$tab = (string)($this->tabName ?? '');
		unset(self::$results[$alias][$dbname][$tab]);

		Debug::addMsg("清除表<b>{$tab}</b>查询缓存（alias={$alias}, db={$dbname}）!");
		$this->resetSql();
	}

	/**
	 * 主动失效当前表查询缓存
	 *
	 * 供执行受控原生写 SQL 的 Model 调用；普通 ORM 写入仍由框架自动失效。
	 *
	 * @return static
	 */
	public function invalidateQueryCache(): static
	{
		$this->delDataCache();
		return $this;
	}

	/**
	 * 检查并限制内存缓存大小
	 *
	 * @return void
	 */
	private function checkCacheLimit(): void
	{
		$alias = $this->queryCacheAlias();
		$dbname = $this->queryCacheDatabaseName();
		$tab = (string)($this->tabName ?? '');
		if (!isset(self::$results[$alias][$dbname][$tab])) {
			return;
		}

		$cacheCount = count(self::$results[$alias][$dbname][$tab]);
		if ($cacheCount <= self::$cacheLimit) {
			return;
		}

		$keys = array_keys(self::$results[$alias][$dbname][$tab]);
		$removeCount = $cacheCount - self::$cacheLimit;
		for ($i = 0; $i < $removeCount; $i++) {
			unset(self::$results[$alias][$dbname][$tab][$keys[$i]]);
		}
	}

	/**
	 * 设置提示信息
	 *
	 * @param mixed $mess 最后一次错误
	 */
	public function setMsg(string $mess): void
	{
		$this->msg = $mess;
		$this->resetSql();
	}
}
