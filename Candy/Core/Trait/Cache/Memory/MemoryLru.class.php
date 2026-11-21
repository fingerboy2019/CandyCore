<?php
/**
 * Memory 驱动 — LRU 淘汰、过期清理与脏标记
 *
 * @package Candy\Core\Trait\Cache\Memory
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Memory;

trait MemoryLru
{
    /**
     * 更新 LRU 访问时间（按前缀隔离）
     *
     * @param string $internalKey 内部键
     * @return void
     */
    protected function touchLru(string $internalKey): void
    {
        $rawKey = $this->data[$internalKey]['raw_key'];
        $prefix = $this->extractDbPrefix($rawKey);

        if (!isset($this->lruRecords[$prefix])) {
            $this->lruRecords[$prefix] = [];
            $this->lruHeads[$prefix] = null;
            $this->lruTails[$prefix] = null;
            $this->lruNodes[$prefix] = [];
        }

        $now = microtime(true);
        $this->lruRecords[$prefix][$internalKey] = $now;

        if (isset($this->lruNodes[$prefix][$internalKey])) {
            $node = $this->lruNodes[$prefix][$internalKey];
            $this->removeFromList($node, $prefix);
        }

        $newNode = ['key' => $internalKey, 'prev' => null, 'next' => $this->lruHeads[$prefix]];
        if ($this->lruHeads[$prefix] !== null) {
            $this->lruHeads[$prefix]['prev'] = $newNode;
        }
        $this->lruHeads[$prefix] = $newNode;
        if ($this->lruTails[$prefix] === null) {
            $this->lruTails[$prefix] = $newNode;
        }
        $this->lruNodes[$prefix][$internalKey] = $newNode;
    }

    /**
     * 从指定前缀 LRU 链表中移除节点
     *
     * @param array $node 链表节点
     * @param string $prefix 前缀
     * @return void
     */
    protected function removeFromList(array &$node, string $prefix): void
    {
        $key = $node['key'];
        if ($node['prev'] !== null) {
            $node['prev']['next'] = $node['next'];
        } else {
            $this->lruHeads[$prefix] = $node['next'];
        }
        if ($node['next'] !== null) {
            $node['next']['prev'] = $node['prev'];
        } else {
            $this->lruTails[$prefix] = $node['prev'];
        }
        unset($this->lruNodes[$prefix][$key]);
    }

    /**
     * 扫描并清理过期键（按前缀隔离）
     *
     * @return void
     */
    protected function cleanupExpiredKeys(): void
    {
        $now = time();
        if (microtime(true) - $this->lastCleanupTime < $this->cleanupInterval) {
            return;
        }
        $this->lastCleanupTime = $now;

        $hasExpired = false;
        foreach ($this->lruRecords as $prefix => $records) {
            $expiredKeys = [];
            $scanned = 0;
            $scanLimit = 1000;

            foreach ($records as $internalKey => $time) {
                if (++$scanned > $scanLimit) {
                    break;
                }
                if (!isset($this->data[$internalKey])) {
                    continue;
                }

                $item = $this->data[$internalKey];
                if ($this->isSystemKey($item['raw_key']) || str_starts_with($item['raw_key'], self::LOCK_PREFIX)) {
                    continue;
                }
                if ($item['expire'] > 0 && $item['expire'] < $now) {
                    $expiredKeys[] = $internalKey;
                }
            }

            foreach ($expiredKeys as $internalKey) {
                $rawKey = $this->data[$internalKey]['raw_key'];
                $itemPrefix = $this->extractDbPrefix($rawKey);
                if (isset($this->dirtyKeys[$itemPrefix][$rawKey])) {
                    unset($this->dirtyKeys[$itemPrefix][$rawKey]);
                }

                if (isset($this->lruNodes[$itemPrefix][$internalKey])) {
                    $this->removeFromList($this->lruNodes[$itemPrefix][$internalKey], $itemPrefix);
                }
                unset($this->data[$internalKey], $this->lruRecords[$itemPrefix][$internalKey], $this->keyMap[$rawKey]);
                $hasExpired = true;
            }
        }

        $hasExpired && $this->setDirty();
        $this->cleanupDirtyKeys();
        $this->checkMemoryLimit();
    }

    /**
     * LRU 淘汰（按前缀独立）
     *
     * @return void
     */
    protected function lruElimination(): void
    {
        foreach ($this->lruRecords as $prefix => $records) {
            $total = count($records);
            if ($total <= $this->maxKeyCount) {
                continue;
            }

            $keepCount = (int)($this->maxKeyCount * 0.9);
            $eliminateCount = $total - $keepCount;
            if ($eliminateCount <= 0) {
                continue;
            }

            $eliminated = 0;
            $current = $this->lruTails[$prefix];

            while ($eliminated < $eliminateCount && $current !== null) {
                $internalKey = $current['key'];
                $next = $current['prev'];

                if (!isset($this->data[$internalKey])) {
                    $this->removeFromList($current, $prefix);
                    unset($this->lruRecords[$prefix][$internalKey]);
                    $current = $next;
                    continue;
                }

                $rawKey = $this->data[$internalKey]['raw_key'];
                if ($this->isSystemKey($rawKey) || str_starts_with($rawKey, self::LOCK_PREFIX)) {
                    $current = $next;
                    continue;
                }

                $this->removeFromList($current, $prefix);
                unset($this->data[$internalKey], $this->lruRecords[$prefix][$internalKey], $this->keyMap[$rawKey]);
                $eliminated++;
                $current = $next;
            }
            $this->setDirty();
        }
        $this->checkMemoryLimit();
    }

    /**
     * 内存溢出防护：超过 php.ini 限制 85% 时触发 LRU
     *
     * @return void
     */
    protected function checkMemoryLimit(): void
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return;
        }

        $limitBytes = $this->convertToBytes($memoryLimit);
        $currentMemory = memory_get_usage(true);

        if ($currentMemory > $limitBytes * 0.85) {
            $this->lruElimination();
        }
    }

    /**
     * 将 php.ini 内存限制字符串转为字节数
     *
     * @param string $val 如 256M、1G
     * @return int 字节数
     */
    protected function convertToBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int)$val;
        return match ($last) {
            'g' => $val * 1024 * 1024 * 1024,
            'm' => $val * 1024 * 1024,
            'k' => $val * 1024,
            default => $val,
        };
    }

    /**
     * 标记脏数据（按前缀隔离）
     *
     * @param string|null $key 被修改的原始键
     * @return void
     */
    protected function setDirty(?string $key = null): void
    {
        $this->dirty = true;
        if ($key !== null) {
            $prefix = $this->extractDbPrefix($key);
            if (!isset($this->dirtyKeys[$prefix])) {
                $this->dirtyKeys[$prefix] = [];
            }
            $this->dirtyKeys[$prefix][$key] = true;
        }
    }

    /**
     * 清理空的脏键前缀桶
     *
     * @return void
     */
    protected function cleanupDirtyKeys(): void
    {
        foreach ($this->dirtyKeys as $prefix => $keys) {
            if (empty($keys)) {
                unset($this->dirtyKeys[$prefix]);
            }
        }
    }
}
