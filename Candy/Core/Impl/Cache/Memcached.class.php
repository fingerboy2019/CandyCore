<?php

declare(strict_types=1);

namespace Candy\Core\Impl\Cache;

use Candy\Core\Cache;
use Candy\Core\Impl\Pack\Binary;
use Throwable;

defined('CANDY') OR die('Access Denied');

final class Memcached extends Cache
{
    /**
     * Driver Capabilities
     * @return array
     */
    protected function driverCapabilities(): array
    {
        return [
            'distributed_state' => ['support' => true, 'mode' => 'native', 'distributed' => true, 'durable' => false, 'atomic' => true],
            'atomic_mutation' => ['support' => true, 'mode' => 'native', 'distributed' => true, 'durable' => false, 'atomic' => true],
        ];
    }
    use \Candy\Core\Trait\Cache\LocalHotCache;
    use \Candy\Core\Trait\Cache\Simulated\MemcachedCasStorage;
    use \Candy\Core\Trait\Cache\Simulated\MemcachedSimulatedBridge;
    use \Candy\Core\Trait\Cache\Simulated\MemcachedKeyOps;
    use \Candy\Core\Trait\Cache\Simulated\SimulatedDriver;
    use \Candy\Core\Trait\Cache\Lock\MemcachedLockBridge;
    use \Candy\Core\Trait\Cache\Resilience\NetworkKvCircuitGuard;
    use \Candy\Core\Trait\Cache\Resilience\SimulatedCasCircuitGuard;
    
    private const BITS_PER_BYTE = 8;
    private const SHARD_COUNT = 16;
    
    private readonly array $config;

    private readonly int $connectionCleanupInterval;

    private readonly int $lockOutTime;

    private readonly int $lockTtl;

    private readonly bool $enableLocalCache;
    
    private int $lastCleanupTime = 0;
    private array $connectionMetadata = [];
    private array $connectionPool = [];

    /**
     * 构造函数（严格对齐父类规范）
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        parent::__construct($config);

        if (!class_exists(\Memcached::class)) {
            $msg = 'Memcached扩展未安装或未启用，请先配置PHP环境';
            function_exists('showExceptionMsg') ? showExceptionMsg('shutdown', $msg) : die($msg);
        }

        $this->config = $config + [
            'servers' => [['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1]],
            'timeout' => 1,
            'enableCompression' => true,
            'enableTcpNodelay' => true,
            'enableConsistentHash' => true,
            'enableLocalCache' => true,
            'lockOutTime' => 3000,
            'lockTtl' => 30,
            'retryInterval' => 200,
            'connectionCleanupInterval' => 300,
            'circuitBreaker' => [
                'enabled' => true,
                'failureThreshold' => 5,
                'recoveryTime' => 10,
            ],
        ];

        $this->lockOutTime = $this->config['lockOutTime'];
        $this->lockTtl = $this->config['lockTtl'];
        $this->retryInterval = $this->config['retryInterval'];
        $this->connectionCleanupInterval = $this->config['connectionCleanupInterval'];
        $this->enableLocalCache = $this->config['enableLocalCache'];

        $this->configureCircuitBreaker($this->config);

        register_shutdown_function([$this, '__releaseAllLocksOnShutdown']);
    }
    
    /**
     * 获取分片锁名称（与全驱动对齐，代码风格统一）
     * @param string $key 原始键
     * @return string 分片锁名称
     */
    private function getShardLockName(string $key): string
    {
        $hash = crc32($key);
        $shardIndex = $hash % self::SHARD_COUNT;
        return 'memcached_shard_' . $shardIndex;
    }

    /**
     * TTL格式化：符合Memcached官方规范
     * @param int $ttl 过期时间（秒），0表示不过期
     * @return int 格式化后的过期时间（秒）
     */
    private function formatTtl(int $ttl): int
    {
        if ($ttl === 0) {
            return 0;
        }
        return $ttl <= 2592000 ? $ttl : time() + $ttl;
    }
    
    /**
     * 设置缓存值（支持任意层数多维键）
     * @param string $key 缓存键（支持多维：user.profile.name）
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间（秒），0表示不过期
     * @return bool 设置是否成功
     */
    public function __rawSet(string $key, mixed $value, int $ttl = 0): bool
    {
        if (($nested = $this->dispatchNestedRawSet($key, $value, $ttl)) !== null) {
            return $nested;
        }

        return $this->fuseGuardBool(function () use ($key, $value, $ttl): bool {
            $mem = $this->getClient();
            $serialized = Binary::pack(['data' => $value]);
            $realTtl = $this->formatTtl($ttl);

            $result = $mem->set($key, $serialized, $realTtl);
            $success = $result && $mem->getResultCode() === \Memcached::RES_SUCCESS;

            if ($success) {
                $this->setHotCache($key, $value, $ttl > 0 ? time() + $ttl : 0);
            }

            return $success;
        });
    }

    /**
     * 获取缓存值（支持任意层数多维键）
     * @param string $key 缓存键（支持多维：user.profile.name）
     * @param mixed $default 默认值
     * @return mixed 读取的数据，不存在返回默认值
     */
    public function __rawGet(string $key, mixed $default = null): mixed
    {
        if (($nested = $this->dispatchNestedRawGet($key, $default)) !== null) {
            return $nested['value'];
        }

        $hot = $this->lookupHotCache($key);
        if ($hot !== null) {
            return $hot['miss'] ? $default : $hot['value'];
        }

        return $this->fuseGuardValue(function () use ($key, $default) {
            $mem = $this->getClient();
            $serialized = $mem->get($key);
            $code = $mem->getResultCode();

            if ($code === \Memcached::RES_NOTFOUND) {
                $this->setHotMiss($key);

                return $default;
            }

            $unpacked = Binary::tryUnpack($serialized);
            if ($unpacked === null) {
                $mem->delete($key);
                $this->setHotMiss($key);
                return $default;
            }
            $value = is_array($unpacked) && array_key_exists('data', $unpacked)
                ? $unpacked['data']
                : $default;

            $ttl = $this->__ttl($key);
            $this->setHotCache($key, $value, $ttl > 0 ? time() + $ttl : 0);

            return $value;
        }, $default);
    }

    /**
     * 删除缓存值（支持任意层数多维键）
     * @param string $key 缓存键（支持多维：user.profile.name）
     * @return bool 删除是否成功
     */
    public function __rawDelete(string $key): bool
    {
        if (($nested = $this->dispatchNestedRawDelete($key)) !== null) {
            return $nested;
        }

        return $this->fuseGuardBool(function () use ($key): bool {
            $mem = $this->getClient();
            $result = $mem->delete($key);
            $success = $result && $mem->getResultCode() === \Memcached::RES_SUCCESS;

            if ($success) {
                $this->deleteHotCache($key);
            }

            return $success;
        });
    }

    /**
     * 判断缓存是否存在（支持任意层数多维键）
     * @param string $key 缓存键（支持多维：user.profile.name）
     * @return bool 缓存是否存在
     */
    public function __rawHas(string $key): bool
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $hot = $this->lookupHotCache($mainKey);
            if ($hot !== null && $hot['miss']) {
                return false;
            }
            $data = $this->__rawGet($mainKey);
            if ($data === null) return false;
            return $this->getNestedValue($data, $paths) !== null;
        }

        $hot = $this->lookupHotCache($key);
        if ($hot !== null) {
            return !$hot['miss'];
        }

        try {

            return $this->fuseGuardBool(function () use ($key): bool {
                $mem = $this->getClient();
                $mem->get($key, \Memcached::GET_EXTENDED);

                return $mem->getResultCode() === \Memcached::RES_SUCCESS;
            });
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 获取TTL（支持任意层数多维键，多维键复用顶层TTL）
     * @param string $key 缓存键（支持多维：user.profile.name）
     * @return int 剩余生存时间（秒），-1表示不存在，0表示永不过期
     */
    public function __ttl(string $key): int
    {
        // 多维键TTL查询（与全驱动完全一致）
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            return $this->__ttl($mainKey);
        }

        try {
            $mem = $this->getClient();
            $data = $mem->get($key, \Memcached::GET_EXTENDED);
            $code = $mem->getResultCode();

            if ($code === \Memcached::RES_NOTFOUND) {
                return -1;
            }

            $expire = $data['expiration'] ?? 0;
            if ($expire === 0) return 0;

            $left = $expire - time();
            return $left > 0 ? $left : -1;
        } catch (Throwable) {
            return -1;
        }
    }
    
    /**
     * 批量设置缓存（与全驱动对齐）
     * @param array $items 键值对数组
     * @param int $ttl 过期时间（秒），0表示不过期
     * @return bool 是否全部成功
     */
    public function __rawMSet(array $items, int $ttl = 0): bool
    {
        if (empty($items)) return true;

        try {
            $allSuccess = true;
            foreach ($items as $key => $value) {
                if (!$this->__rawSet($key, $value, $ttl)) {
                    $allSuccess = false;
                }
            }
            return $allSuccess;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 批量获取缓存（与全驱动对齐）
     * @param array $keys 键数组
     * @param mixed $default 默认值
     * @return array 键值对结果数组
     */
    public function __rawMGet(array $keys, mixed $default = null): array
    {
        $result = array_fill_keys($keys, $default);
        if (empty($keys)) return $result;

        try {
            foreach ($keys as $key) {
                $result[$key] = $this->__rawGet($key, $default);
            }
            return $result;
        } catch (Throwable) {
            return $result;
        }
    }

    /**
     * 批量删除缓存（与全驱动对齐）
     * @param array $keys 键数组
     * @return bool 是否全部成功
     */
    public function __rawMDelete(array $keys): bool
    {
        if (empty($keys)) return true;

        try {
            $allSuccess = true;
            foreach ($keys as $key) {
                if (!$this->__rawDelete($key)) {
                    $allSuccess = false;
                }
            }
            return $allSuccess;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 批量判断缓存是否存在（与全驱动对齐）
     * @param array $keys 键数组
     * @return array 键值对结果数组
     */
    public function __rawMHas(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->__rawHas($key);
        }
        return $result;
    }

    /**
     * 操作位图（Bit）的底层核心方法（统一签名版）
     * 支持 rw 模式 / count 模式 / op 模式 / pos 模式
     *
     * @param string $key 位图的唯一标识Key
     * @param int|array $id 单个Bit偏移量（非负整数）或多个偏移量组成的数组
     * @param mixed $options 操作选项：
     *   - null/bool → rw 模式（读/写位）
     *   - string 'count' → 统计模式，统计范围内置为1的位数
     *   - string 'pos' → 查找模式，查找位图中第一个值为0的位（默认查找0）
     *   - array['operation' => 'AND'|'OR'|'XOR'|'NOT'] → 位运算模式
     *   - array['value' => 0|1] → pos模式，查找指定的位值（0或1）
     *   - array['type' => 'pos'|'count', 'value' => 0|1] → 通过type指定操作类型
     *   - 在 rw 模式下，bool 类型时等效于旧版 $status 参数
     * @param int $expire 过期时间（秒）
     * @return array|int|bool rw模式：查询返回位值，写入返回bool；count模式返回整数；pos模式返回位置或-1；op模式返回结果长度
     */
    public function __rawBit(string $key, int|array $id, mixed $options = null, int $expire = 0): array|int|bool
    {
        // 检测操作类型
        if (is_string($options)) {
            $opType = strtolower($options);
            if ($opType === 'count') {
                return $this->__doBitCount($key, $id);
            }
            if ($opType === 'pos') {
                // pos 模式需要从 options 中获取 bit 值
                // 如果 options 是字符串 'pos'，需要从其他地方获取 bit 值
                // 这里需要重新设计签名以支持 pos 操作
                return $this->__doBitPos($key, $id, 0); // 默认查找0
            }
        }

        if (is_array($options)) {
            if (isset($options['operation'])) {
                $operation = $options['operation'] ?? 'OR';
                $destKey = $options['destKey'] ?? null;
                return $this->__doBitOp($key, $id, $operation, $destKey, $expire);
            }
            if (isset($options['value'])) {
                // pos 模式：options['value'] 指定查找的位值
                return $this->__doBitPos($key, $id, (int)$options['value']);
            }
            if (isset($options['type'])) {
                // 支持 options['type'] = 'pos' 的模式
                $type = strtolower($options['type']);
                if ($type === 'pos') {
                    return $this->__doBitPos($key, $id, (int)($options['value'] ?? 0));
                }
                if ($type === 'count') {
                    return $this->__doBitCount($key, $id);
                }
            }
        }

        // 默认 rw 模式（向后兼容）
        $status = is_bool($options) ? $options : null;
        return $this->__rawBitRW($key, $id, $status, $expire);
    }

    /**
     * RW模式：读/写位操作
     *
     * @param string $key 位图的唯一标识Key
     * @param int|array $id 偏移量
     * @param bool|null $status null=查询，true=置1，false=置0
     * @param int $expire 过期时间
     * @return bool|int|array
     */
    private function __rawBitRW(string $key, int|array $id, ?bool $status = null, int $expire = 0): bool|int|array
    {
        if (empty($key)) {
            return is_int($id) ? 0 : (is_array($id) ? array_fill(0, count($id), 0) : false);
        }

        $idList = is_array($id) ? $id : [$id];
        foreach ($idList as $offset) {
            if (!is_int($offset) || $offset < 0) {
                function_exists('showExceptionMsg') ? showExceptionMsg('shutdown', '[Memcached__rawBit] Bit偏移量必须为非负整数，当前值：%s', var_export($offset, true)) : die('[Memcached__rawBit] Bit偏移量必须为非负整数');
            }
        }

        $isQuery = is_null($status);
        $singleId = is_int($id);

        // 多维键处理（位图不支持多维键，直接返回默认值）
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            return is_int($id) ? 0 : (is_array($id) ? array_fill(0, count($id), 0) : false);
        }

        try {
            $mem = $this->getClient();

            // 查询操作
            if ($isQuery) {
                $result = [];
                foreach ($idList as $offset) {
                    $bitValue = $mem->getBit($key, $offset);
                    $result[] = $bitValue === false ? 0 : $bitValue;
                }
                $finalResult = $singleId ? $result[0] : $result;

                if ($this->enableLocalCache && $singleId) {
                    $this->setHotCache("bit_{$key}_{$id}", $finalResult, 0);
                }

                return $finalResult;
            }

            // 写入操作
            $allSuccess = true;
            foreach ($idList as $offset) {
                $result = $mem->setBit($key, $offset, $status ? 1 : 0);
                if ($result === false) {
                    $allSuccess = false;
                }
            }

            if ($this->enableLocalCache) {
                foreach ($idList as $offset) {
                    $this->deleteHotCache("bit_{$key}_{$offset}");
                }
            }

            // 如果设置了过期时间
            if ($expire > 0) {
                $mem->set($key, $mem->get($key), $this->formatTtl($expire));
            }

            return $singleId ? $allSuccess : ($allSuccess && count($idList) > 0);
        } catch (Throwable) {
            return is_int($id) ? 0 : false;
        }
    }

    /**
     * Count模式：统计范围内置为1的位数（Memcached 模拟实现）
     *
     * @param string $key 位图的唯一标识Key
     * @param int|array $id [start, end] 范围，或单个 start 值
     * @param mixed $options 选项（可包含 start/end）
     * @return int 值为1的位数
     */
    private function __doBitCount(string $key, int|array $id, mixed $options = null): int
    {
        try {
            $mem = $this->getClient();

            if (is_array($id) && count($id) >= 2) {
                $start = (int)$id[0];
                $end = (int)$id[1];
            } else {
                $start = is_int($id) ? $id : 0;
                $end = -1;
            }

            // Memcached 无原生 BITCOUNT，通过逐位统计模拟
            $count = 0;
            $endOffset = ($end >= 0) ? $end : 8192; // 默认最大 8KB
            $byteStart = (int)($start / 8);
            $byteEnd = (int)($endOffset / 8);

            for ($byte = $byteStart; $byte <= $byteEnd; $byte++) {
                for ($bit = 0; $bit < 8; $bit++) {
                    $offset = $byte * 8 + $bit;
                    if ($offset < $start) continue;
                    if ($end >= 0 && $offset > $end) break 2;

                    $bitValue = $mem->getBit($key, $offset);
                    if ($bitValue === 1) {
                        $count++;
                    }
                }
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Op模式：对多个位图执行位运算（Memcached 模拟实现）
     *
     * @param string $key 位图Key（用作运算类型标识）
     * @param int|array $id 忽略或用于指定目标
     * @param string $operation AND/OR/XOR/NOT
     * @param string|null $destKey 目标键
     * @param array $sourceKeys 源键列表
     * @param int $expire 过期时间
     * @return int 结果位图长度
     */
    private function __doBitOp(string $key, int|array $id, string $operation = 'OR', ?string $destKey = null, array $sourceKeys = [], int $expire = 0): int
    {
        if (empty($sourceKeys)) return 0;

        try {
            $mem = $this->getClient();
            $targetKey = $destKey ?? ('bitop_result_' . $key . '_' . time());

            // 获取最大位图长度
            $maxBits = 8192; // 默认 8KB
            $sourceData = [];

            foreach ($sourceKeys as $srcKey) {
                $sourceData[$srcKey] = [];
                for ($i = 0; $i < $maxBits; $i++) {
                    $sourceData[$srcKey][$i] = $mem->getBit($srcKey, $i) ?: 0;
                }
            }

            // 逐位执行运算
            $resultBits = [];
            for ($offset = 0; $offset < $maxBits; $offset++) {
                $values = [];
                foreach ($sourceKeys as $srcKey) {
                    $values[] = $sourceData[$srcKey][$offset] ?? 0;
                }

                $result = match (strtoupper($operation)) {
                    'AND' => array_reduce($values, fn($carry, $v) => $carry & $v, 1),
                    'OR' => array_reduce($values, fn($carry, $v) => $carry | $v, 0),
                    'XOR' => array_reduce($values, fn($carry, $v) => $carry ^ $v, 0),
                    'NOT' => isset($values[0]) ? (~$values[0]) & 1 : 0,
                    default => 0
                };
                $resultBits[$offset] = $result;
            }

            // 存储结果（序列化后存入）
            $serialized = Binary::pack(['data' => $resultBits]);
            $ttl = $expire > 0 ? $this->formatTtl($expire) : 0;
            $mem->set($targetKey, $serialized, $ttl);

            return $maxBits;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Pos模式：查找位图中第一个值为0或1的位
     *
     * @param string $key 位图的唯一标识Key
     * @param int|array $id [start, end] 范围
     * @param int $value 查找的值：0或1
     * @return int 第一个匹配位的偏移量，未找到返回-1
     */
    private function __doBitPos(string $key, int|array $id, int $value = 0): int
    {
        try {
            $mem = $this->getClient();

            if (is_array($id) && count($id) >= 2) {
                $start = (int)$id[0];
                $end = (int)$id[1];
            } else {
                $start = is_int($id) ? $id : 0;
                $end = -1;
            }

            // Memcached 无原生 BITPOS，需逐位查找
            $maxOffset = $end > 0 ? $end : 8192;
            for ($offset = $start; $offset <= $maxOffset; $offset++) {
                $bit = $mem->getBit($key, $offset);
                if ($bit === $value) {
                    return $offset;
                }
            }

            return -1;
        } catch (Throwable) {
            return -1;
        }
    }

    /**
     * 清空当前前缀的全部缓存
     * @return bool 是否成功清空
     */
    public function __clear(): bool
    {
        try {
            $mem = $this->getClient();
            $allKeys = $mem->getAllKeys();

            if (empty($allKeys)) {
                return true;
            }

            // 仅清当前租户作用域；无 TENANT_ID 时等同配置前缀（默认/平台空间）
            $scopePrefix = $this->scopePrefix();
            $targetKeys = array_filter($allKeys, fn($key) => str_starts_with((string)$key, $scopePrefix));
            if (!empty($targetKeys)) {
                $mem->deleteMulti($targetKeys);
            }

            if ($this->enableLocalCache) {
                $this->clearHotCache();
            }

            return $mem->getResultCode() === \Memcached::RES_SUCCESS;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 脚本结束自动释放所有锁
     */
    
    protected function getMemcachedClient(): \Memcached
    {
        return $this->getClient();
    }

    /**
     * 获取Memcached客户端
     * @return \Memcached Memcached客户端实例
     */
    private function getClient(): \Memcached
    {
        $this->cleanupConnectionPool();
        $connectionId = md5(serialize($this->config['servers']));

        if (isset($this->connectionPool[$connectionId])) {
            $client = $this->connectionPool[$connectionId];
            $this->connectionMetadata[$connectionId]['lastUsed'] = time();
            return $client;
        }

        $persistentId = 'candy_memcached_' . $connectionId;
        $avoidPersist = class_exists(\Candy\Extend\DB\WorkerRuntime::class)
            && \Candy\Extend\DB\WorkerRuntime::shouldAvoidPersistentIo();
        // Workerman 长驻勿用持久 Memcached 实例 ID，防止 fork 后共享坏连接
        $mem = $avoidPersist ? new \Memcached() : new \Memcached($persistentId);

        if (empty($mem->getServerList())) {
            $servers = array_map(fn($s) => [
                $s['host'] ?? '127.0.0.1',
                $s['port'] ?? 11211,
                $s['weight'] ?? 1
            ], $this->config['servers']);

            $mem->addServers($servers);

            $mem->setOptions([
                \Memcached::OPT_TCP_NODELAY => $this->config['enableTcpNodelay'],
                \Memcached::OPT_DISTRIBUTION => $this->config['enableConsistentHash']
                    ? \Memcached::DISTRIBUTION_CONSISTENT
                    : \Memcached::DISTRIBUTION_MODULA,
                \Memcached::OPT_LIBKETAMA_COMPATIBLE => $this->config['enableConsistentHash'],
                \Memcached::OPT_COMPRESSION => $this->config['enableCompression'],
                \Memcached::OPT_CONNECT_TIMEOUT => $this->config['timeout'] * 1000,
                \Memcached::OPT_RECV_TIMEOUT => $this->config['timeout'] * 1000,
                \Memcached::OPT_SEND_TIMEOUT => $this->config['timeout'] * 1000,
                \Memcached::OPT_RETRY_TIMEOUT => 1,
                \Memcached::OPT_AUTO_EJECT_HOSTS => true,
                \Memcached::OPT_SERVER_FAILURE_LIMIT => 3,
                \Memcached::OPT_BINARY_PROTOCOL => true,
            ]);
        }

        $this->connectionPool[$connectionId] = $mem;
        $this->connectionMetadata[$connectionId] = [
            'createdAt' => time(),
            'lastUsed'  => time(),
        ];

        return $mem;
    }

    /**
     * 清理闲置连接
     */
    private function cleanupConnectionPool(): void
    {
        $now = time();
        if ($now - $this->lastCleanupTime < $this->connectionCleanupInterval) {
            return;
        }

        $this->lastCleanupTime = $now;
        foreach ($this->connectionMetadata as $connId => $meta) {
            if ($now - $meta['lastUsed'] > 1800) {
                $client = $this->connectionPool[$connId] ?? null;
                if ($client) {
                    $client->quit();
                }
                unset($this->connectionPool[$connId], $this->connectionMetadata[$connId]);
            }
        }
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->connectionPool = [];
        $this->connectionMetadata = [];
    }
}
