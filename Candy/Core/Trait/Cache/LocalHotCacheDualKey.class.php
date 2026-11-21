<?php
/**
 * 进程内热点缓存 + LRU 淘汰（双键模式）
 * hotCache 以 ckey（md5）索引，lruRecords 以 memKey 索引，供 File / Shmop 复用
 *
 * @package Candy\Core\Cache\Trait
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache;

trait LocalHotCacheDualKey
{
    /**
     * 最大热点缓存条目数
     */
    private const MAX_HOT_CACHE_COUNT = 200;

    /**
     * @return array{hotCache: array<string, array{value: mixed, expire: int}>, lruRecords: array<string, array{ckey: string, time: int}>}
     */
    abstract protected function &dualHotCacheBucket(?string $dbAlias): array;

    /**
     * 从热缓存读取
     */
    private function getHotCache(string $ckey, string $memKey, ?string $dbAlias = null): mixed
    {
        $bucket = &$this->dualHotCacheBucket($dbAlias);

        if (!isset($bucket['hotCache'][$ckey])) {
            return null;
        }

        $item = $bucket['hotCache'][$ckey];
        if ($item['expire'] > 0 && $item['expire'] < time()) {
            unset($bucket['hotCache'][$ckey], $bucket['lruRecords'][$memKey]);
            return null;
        }

        $this->touchLru($memKey, $ckey, $dbAlias);
        return $item['value'];
    }

    /**
     * 写入热缓存
     */
    private function setHotCache(string $ckey, string $memKey, mixed $value, int $expireTimestamp, ?string $dbAlias = null): void
    {
        $bucket = &$this->dualHotCacheBucket($dbAlias);

        if (count($bucket['hotCache']) >= self::MAX_HOT_CACHE_COUNT) {
            $this->trimLru($dbAlias);
        }

        $bucket['hotCache'][$ckey] = [
            'value' => $value,
            'expire' => $expireTimestamp,
        ];
        $this->touchLru($memKey, $ckey, $dbAlias);
    }

    /**
     * 删除热缓存条目
     */
    private function deleteHotCache(string $ckey, string $memKey, ?string $dbAlias = null): void
    {
        $bucket = &$this->dualHotCacheBucket($dbAlias);
        unset($bucket['hotCache'][$ckey], $bucket['lruRecords'][$memKey]);
    }

    /**
     * 仅更新热缓存过期时间（不读取 value）
     */
    private function updateHotCacheExpire(string $ckey, int $expireTimestamp, ?string $dbAlias = null): void
    {
        $bucket = &$this->dualHotCacheBucket($dbAlias);
        if (!isset($bucket['hotCache'][$ckey])) {
            return;
        }

        $bucket['hotCache'][$ckey]['expire'] = $expireTimestamp;
    }

    /**
     * 重命名热缓存索引（不复制 value）
     */
    private function renameHotCache(
        string $oldCkey,
        string $oldMemKey,
        string $newCkey,
        string $newMemKey,
        ?string $dbAlias = null
    ): void {
        $bucket = &$this->dualHotCacheBucket($dbAlias);

        if (isset($bucket['hotCache'][$oldCkey])) {
            $bucket['hotCache'][$newCkey] = $bucket['hotCache'][$oldCkey];
            unset($bucket['hotCache'][$oldCkey]);
        }

        if (isset($bucket['lruRecords'][$oldMemKey])) {
            $bucket['lruRecords'][$newMemKey] = [
                'ckey' => $newCkey,
                'time' => time(),
            ];
            unset($bucket['lruRecords'][$oldMemKey]);
        }
    }

    /**
     * 更新 LRU 记录
     */
    private function touchLru(string $memKey, string $ckey, ?string $dbAlias = null): void
    {
        $bucket = &$this->dualHotCacheBucket($dbAlias);
        $bucket['lruRecords'][$memKey] = ['ckey' => $ckey, 'time' => time()];

        if (count($bucket['lruRecords']) > self::MAX_HOT_CACHE_COUNT * 2) {
            $this->trimLru($dbAlias);
        }
    }

    /**
     * 裁剪 LRU，淘汰最久未使用的条目
     */
    private function trimLru(?string $dbAlias = null): void
    {
        $bucket = &$this->dualHotCacheBucket($dbAlias);

        if (count($bucket['lruRecords']) <= self::MAX_HOT_CACHE_COUNT) {
            return;
        }

        uasort(
            $bucket['lruRecords'],
            static fn(array $a, array $b): int => $a['time'] <=> $b['time']
        );

        $removeCount = count($bucket['lruRecords']) - self::MAX_HOT_CACHE_COUNT;
        $keysToRemove = array_slice(array_keys($bucket['lruRecords']), 0, $removeCount);

        foreach ($keysToRemove as $memKey) {
            if (!isset($bucket['lruRecords'][$memKey])) {
                continue;
            }

            $oldCkey = $bucket['lruRecords'][$memKey]['ckey'];
            if ($oldCkey !== '') {
                unset($bucket['hotCache'][$oldCkey]);
            }
            unset($bucket['lruRecords'][$memKey]);
        }
    }
}
