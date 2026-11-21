<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * G() 全局变量存储主键（@ 前缀 = 平台共享命名空间，不按 TENANT_ID 隔离）
 *
 * 物理键仍为 {cachePrefix}frameVariableForG，与升级前兼容
 */
const G_CACHE_MAIN_KEY = '@frameVariableForG';

/**
 * 标记平台共享缓存逻辑键（不注入租户段）
 *
 * 等价于 Cache::sharedKey()；用于跨租户/平台公共数据（如配置、全站开关）
 *
 * @param string $key 业务键名
 * @return string 以 @ 开头的逻辑键
 */
function cacheSharedKey(string $key): string
{
	return \Candy\Core\Cache::sharedKey($key);
}

/**
 * 快速文件缓存操作
 * 
 * 提供简单高效的文件缓存读写功能，支持字符串和数组类型数据的快速存取，
 * 包含设置缓存、获取缓存和删除缓存的完整功能，并支持设置缓存过期时间。
 * 
 * @param string $name 缓存名称，用于标识缓存数据的唯一键
 * @param mixed $value 缓存值，为空字符串时表示获取缓存，为null时表示删除缓存，其他值表示设置缓存
 * @param int $expiresTime 缓存过期时间（秒），0表示永不过期，默认值为0
 * @return mixed 设置缓存时返回null，获取缓存时返回缓存值或null，删除缓存时返回布尔值
 * 
 * @example
 * ```php
 * // 设置缓存（永不过期）
 * F('config', ['db' => 'mysql', 'host' => 'localhost']);
 * 
 * // 设置带过期时间的缓存（3600秒后过期）
 * F('user_info', ['id' => 1, 'name' => 'admin'], 3600);
 * 
 * // 获取缓存
 * $config = F('config');
 * 
 * // 删除缓存
 * F('user_info', null);
 * ```
 */
function F(string $name,mixed $value = '',int $expiresTime = 0):mixed
{
	//缓存对象
	$cache = \Candy\Core\Cache::store('file');
	$key = $name;
	//存值和删除
    if ('' !== $value){
        if (is_null($value)){
			//删除值
            return $cache->delete($key);
        } else {
			//缓存值
			return $cache->set($key, $value, $expiresTime);
        }
    }
	
	//取值
    return $cache->get($key, false);
}

/**
 * 加载并初始化缓存系统
 *
 * 该函数用于加载系统缓存，根据运行环境进行相应的缓存初始化，
 * 并返回缓存实例。支持页面静态化缓存配置。
 *
 * 使用示例：
 * - 初始化默认缓存: cacheLoading()
 * - 开启页面静态化缓存: cacheLoading(1)
 *
 * @param bool $cstart 是否开启页面静态化缓存，true表示开启，false表示不开启，默认为false
 * @return \Candy\Core\Cache|false 返回缓存实例，失败时返回false
 */
function cacheLoading(bool $cstart = false): mixed
{
	//运行模式
	if(isWebServer()){
		\Candy\Core\Debug::addMsg('<font color=\'red\'>CLI模式运行中，缓存直接存于内存!</font>');
	}else{
		\Candy\Core\Debug::addMsg('<font color=\'red\'>建议使用CLI模式运行!</font>');
	}

	//OPcache 检查
	if (!extension_loaded('Zend OPcache') || !ini_get('opcache.enable')) {
		\Candy\Core\Debug::addMsg('<font color=\'red\'>建议开启 OPcache 以提高性能!</font>');
	}

	//页面缓存
	if(!\Candy\Core\Cache::isFileDriver()){
		//缓存可用
		if($cstart === true){
			\Candy\Core\Debug::addMsg('<font color=\'green\'>开启页面缓存，实现页面静态化!</font>');
		} else {
			\Candy\Core\Debug::addMsg('<font color=\'red\'>没有开启页面缓存!</font>（但可以使用）');
			return \Candy\Core\Cache::store();
		}
	}
	return false;
}

/**
 * 从共享缓存解析 G() 载荷（兼容旧版聚合数组与新版 rev+data 结构）
 *
 * @return array{0: array, 1: int} [data, rev]
 */
function parseSharedGlobalPayload(mixed $payload): array
{
	if (!is_array($payload)) {
		return [[], 0];
	}

	if (array_key_exists('data', $payload) && array_key_exists('rev', $payload) && is_array($payload['data'])) {
		return [$payload['data'], (int)$payload['rev']];
	}

	return [$payload, 0];
}

/**
 * 将 G() 进程内缓存同步到共享存储
 */
function persistSharedGlobalCache(\Candy\Core\Cache $cache, array $data, int $rev): bool
{
	return $cache->set(G_CACHE_MAIN_KEY, ['rev' => $rev, 'data' => $data]);
}

/**
 * 读取单个 G 变量（兼容旧版 frameVariableForG:key 分键存储）
 */
function readSharedGlobalValue(\Candy\Core\Cache $cache, array &$local, int &$localRev, string $name, mixed $default): mixed
{
	// ① 字面键优先：兼容历史上以「a.b」为完整键名写入的旧数据
	if (array_key_exists($name, $local)) {
		return $local[$name];
	}

	// ② 点号路径逐层下钻 —— 与 C() 对齐。
	// 【2026-11-12 修复】该能力在后续迭代中丢失：原实现只做 array_key_exists($name, $local)，
	// 导致 G('storage.alioss_bucket') 这类二维路径读不到（G() 注释仍写着「二维配置路径」）。
	if (strpos($name, '.') !== false) {
		$keys  = explode('.', $name);
		$value = $local;
		$hit   = true;
		while (count($keys) > 0) {
			$k = array_shift($keys);
			if (is_array($value) && array_key_exists($k, $value)) {
				$value = $value[$k];
			} else {
				$hit = false;
				break;
			}
		}
		if ($hit) {
			return $value;
		}
	}

	$legacyKey = G_CACHE_MAIN_KEY . ':' . $name;
	$legacyValue = $cache->get($legacyKey, '__G_LEGACY_MISS__');
	if ($legacyValue !== '__G_LEGACY_MISS__') {
		$local[$name] = $legacyValue;
		$cache->delete($legacyKey);
		$localRev = (int)microtime(true);
		persistSharedGlobalCache($cache, $local, $localRev);
		return $legacyValue;
	}

	return $default;
}

/**
 * 重置 G() 进程内缓存（下次访问时从共享缓存重新加载）
 */
function resetSharedGlobalCache(): void
{
	G(null, null);
}

/**
 * G() 跨进程同步间隔（秒）；Memory 驱动下仅首载同步
 */
function sharedGlobalCacheSyncInterval(): int
{
	static $interval = null;

	if ($interval !== null) {
		return $interval;
	}

	try {
		$store = \Candy\Core\Cache::store();
	} catch (\Throwable) {
		$interval = 2;
		return $interval;
	}

	$interval = $store instanceof \Candy\Core\Impl\Cache\Memory ? PHP_INT_MAX : 2;
	return $interval;
}

/**
 * 确保 G() 进程内缓存已从共享层加载（带 revision 定时同步，避免高频 Redis 往返）
 */
function syncSharedGlobalCache(\Candy\Core\Cache $cache, ?array &$local, int &$localRev, int &$lastSyncAt): void
{
	$syncInterval = sharedGlobalCacheSyncInterval();
	$now = time();

	if ($local !== null && ($now - $lastSyncAt) < $syncInterval) {
		return;
	}

	$payload = $cache->get(G_CACHE_MAIN_KEY, null);
	[$remoteData, $remoteRev] = parseSharedGlobalPayload($payload);

	if ($local === null || $remoteRev !== $localRev) {
		$local = $remoteData;
		$localRev = $remoteRev;
	}

	$lastSyncAt = $now;
}


/**
 * 获取一个分布式缓存锁
 *
 * 基于 `Cache::cas()` 的 Compare-And-Set 语义，跨进程/跨机器安全。
 *
 * ```php
 * $lock = cache_lock('daily-report', 30);
 * if ($lock->get()) {
 *     try {
 *         // 临界区
 *     } finally {
 *         $lock->release();
 *     }
 * }
 *
 * // 回调式，自动释放
 * cache_lock('daily-report', 30)->get(fn() => doSomething());
 *
 * // 阻塞等待最多 5 秒
 * cache_lock('daily-report', 30)->block(5, fn() => doSomething());
 * ```
 *
 * @param string      $name    锁名
 * @param int         $seconds 持有时长（秒），0 = 永不过期（不推荐）
 * @param string|null $owner   持有者令牌，默认自动生成
 * @return \Candy\Extend\Cache\Lock
 */
function cache_lock(string $name, int $seconds = 0, ?string $owner = null): \Candy\Extend\Cache\Lock
{
    return new \Candy\Extend\Cache\Lock(\Candy\Core\Cache::store(), $name, $seconds, $owner);
}
