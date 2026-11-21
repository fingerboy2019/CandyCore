<?php
/**
 * Memory 驱动 — 键编解码与系统键常量
 *
 * @package Candy\Core\Trait\Cache\Memory
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Memory;

trait MemoryKeyCodec
{
    /**
     * LRU 默认最大键数量
     */
    protected const DEFAULT_MAX_KEY_COUNT = 10000;

    /**
     * 分片锁数量
     */
    protected const SHARD_COUNT = 16;

    /**
     * 内部键指纹长度（字节）
     */
    protected const FINGERPRINT_LENGTH = 6;

    /**
     * 写锁饥饿保护阈值
     */
    protected const MAX_STARVATION_THRESHOLD = 100;

    /**
     * 分布式锁键前缀
     */
    protected const LOCK_PREFIX = 'mem_lock_';

    /**
     * 最后落盘时间系统键
     */
    protected const LAST_DUMP_KEY = '__memory_last_dump_time';

    /**
     * 向量索引系统键
     */
    protected const VECTOR_INDEX_KEY = '__memory_vector_index';

    /**
     * 系统内置键白名单（禁止删除与 LRU 淘汰）
     */
    protected const SYSTEM_INTERNAL_KEYS = [
        self::LAST_DUMP_KEY,
        self::VECTOR_INDEX_KEY,
    ];

    /**
     * 获取带缓存前缀的系统键
     *
     * @param string $key 基础键名
     * @return string 完整系统键
     */
    protected function getSystemKey(string $key): string
    {
        return $this->cachePrefix . $key;
    }

    /**
     * 编译内部防冲突键
     *
     * 优先复用 keyMap；冲突时追加递增后缀
     *
     * @param string $rawKey 原始键名
     * @return string 内部键
     */
    protected function compileInternalKey(string $rawKey): string
    {
        if (isset($this->keyMap[$rawKey])) {
            return $this->keyMap[$rawKey];
        }

        $internalKey = $this->buildInternalKeyFingerprint($rawKey);

        if (isset($this->data[$internalKey]) && ($this->data[$internalKey]['raw_key'] ?? '') !== $rawKey) {
            $suffix = 1;
            do {
                $candidate = $internalKey . '_' . $suffix++;
            } while (
                isset($this->data[$candidate])
                && ($this->data[$candidate]['raw_key'] ?? '') !== $rawKey
            );
            $internalKey = $candidate;
        }

        $this->keyMap[$rawKey] = $internalKey;
        return $internalKey;
    }

    /**
     * 由 rawKey 生成默认内部键指纹
     *
     * @param string $rawKey 原始键名
     * @return string CRC32_指纹 格式内部键
     */
    protected function buildInternalKeyFingerprint(string $rawKey): string
    {
        $crc32 = dechex(crc32($rawKey));
        $fingerprint = bin2hex(substr(md5($rawKey, true), 0, self::FINGERPRINT_LENGTH));

        return $crc32 . '_' . $fingerprint;
    }

    /**
     * 校验 internalKey 是否仍归属该 rawKey
     *
     * @param string $rawKey 原始键名
     * @param string $internalKey 内部键
     * @return bool 是否归属
     */
    protected function ownsInternalKey(string $rawKey, string $internalKey): bool
    {
        return isset($this->data[$internalKey])
            && ($this->data[$internalKey]['raw_key'] ?? '') === $rawKey;
    }

    /**
     * 根据键名计算分片锁名称
     *
     * @param string $key 键名
     * @return string 分片锁名
     */
    protected function getShardLockName(string $key): string
    {
        $shardIndex = crc32($key) % self::SHARD_COUNT;
        return 'memory_shard_' . $shardIndex;
    }

    /**
     * 从键名提取数据库前缀（LRU 隔离用）
     *
     * 匹配规则：**_**_key
     *
     * @param string $rawKey 原始键名
     * @return string 前缀或 _default_
     */
    protected function extractDbPrefix(string $rawKey): string
    {
        if (preg_match('/^([^_]+_[^_]+_)/', $rawKey, $matches)) {
            return $matches[1];
        }
        return '_default_';
    }

    /**
     * 判断是否为系统内置键
     *
     * @param string $rawKey 原始键名
     * @return bool 是否系统键
     */
    protected function isSystemKey(string $rawKey): bool
    {
        foreach (self::SYSTEM_INTERNAL_KEYS as $systemKey) {
            if ($rawKey === $this->cachePrefix . $systemKey) {
                return true;
            }
        }
        return false;
    }
}
