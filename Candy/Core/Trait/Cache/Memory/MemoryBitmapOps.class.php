<?php
/**
 * Memory 驱动 — 位图 KV 操作
 *
 * @package Candy\Core\Trait\Cache\Memory
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Memory;

use Throwable;

trait MemoryBitmapOps
{
    private const BITS_PER_BYTE = 8;

    public function __rawBit(string $key, int|array $id, mixed $options = null, int $expire = 0): array|int|bool
    {
        if (is_string($options)) {
            $opType = strtolower($options);
            if ($opType === 'count') {
                return $this->__doBitCount($key, $id);
            }
            if ($opType === 'pos') {
                return $this->__doBitPos($key, $id, 1);
            }
        }

        if (is_array($options)) {
            if (isset($options['operation'])) {
                $operation = $options['operation'] ?? 'OR';
                $destKey = $options['destKey'] ?? null;
                $sourceKeys = $options['sourceKeys'] ?? [];
                return $this->__doBitOp($key, $id, $operation, $destKey, $sourceKeys, $expire);
            }
            if (isset($options['value'])) {
                $value = (int)($options['value'] ?? 1);
                $start = $options['start'] ?? 0;
                $end = $options['end'] ?? -1;
                return $this->__doBitPos($key, $id, $value, $start, $end);
            }
        }

        $status = is_bool($options) ? $options : null;
        return $this->__doBitRW($key, $id, $status, $expire);
    }

    /**
     * @throws Throwable
     */
    private function __doBitRW(string $key, int|array $id, ?bool $status = null, int $expire = 0): bool|int|array
    {
        $idList = is_array($id) ? $id : [$id];
        $isQuery = is_null($status);
        $singleId = is_int($id);

        if ($isQuery) {
            if (!$this->acquireReadLock($key)) {
                return $singleId ? 0 : array_fill(0, count($idList), 0);
            }
            try {
                $bitString = $this->__rawGet($key) ?? '';
                $result = [];
                foreach ($idList as $offset) {
                    $byte = (int)($offset / self::BITS_PER_BYTE);
                    $bit = $offset % self::BITS_PER_BYTE;
                    $result[] = $byte >= strlen($bitString) ? 0 : ((ord($bitString[$byte]) >> $bit) & 1);
                }
                return $singleId ? $result[0] : $result;
            } finally {
                $this->releaseReadLock($key);
            }
        }

        if (!$this->acquireWriteLock($key)) {
            return false;
        }
        try {
            $bitString = $this->__rawGet($key) ?? '';
            $maxOffset = max($idList);
            $len = (int)ceil(($maxOffset + 1) / self::BITS_PER_BYTE);
            $len > strlen($bitString) && $bitString .= str_repeat("\0", $len - strlen($bitString));

            foreach ($idList as $offset) {
                $byte = (int)($offset / self::BITS_PER_BYTE);
                $bit = $offset % self::BITS_PER_BYTE;
                $val = ord($bitString[$byte]);
                $bitString[$byte] = chr($status ? ($val | (1 << $bit)) : ($val & ~(1 << $bit)));
            }

            $now = time();
            $expireAt = $expire > 0 ? $now + $expire : 0;
            $this->memoryStoreValue($key, $bitString, $expireAt, $now);
            $this->lruElimination();
            $this->cleanupExpiredKeys();
            $this->setDirty($key);
            $this->autoDump();
            return true;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    private function __doBitCount(string $key, int|array $id): int
    {
        if (!$this->acquireReadLock($key)) {
            return 0;
        }
        try {
            if (is_array($id) && count($id) >= 2) {
                $start = (int)$id[0];
                $end = (int)$id[1];
            } else {
                $start = is_int($id) ? $id : 0;
                $end = -1;
            }

            $bitString = $this->__rawGet($key) ?? '';
            if ($bitString === '') {
                return 0;
            }

            $count = 0;
            $endOffset = ($end >= 0) ? min($end, strlen($bitString) * 8 - 1) : strlen($bitString) * 8 - 1;

            for ($offset = $start; $offset <= $endOffset; $offset++) {
                $byte = (int)($offset / self::BITS_PER_BYTE);
                $bit = $offset % self::BITS_PER_BYTE;
                if ($byte < strlen($bitString)) {
                    $count += (ord($bitString[$byte]) >> $bit) & 1;
                }
            }

            return $count;
        } finally {
            $this->releaseReadLock($key);
        }
    }

    private function __doBitPos(string $key, int|array $id, int $value = 1, int $start = 0, int $end = -1): int
    {
        if (!$this->acquireReadLock($key)) {
            return -1;
        }
        try {
            $bitString = $this->__rawGet($key) ?? '';
            if ($bitString === '') {
                return -1;
            }

            $totalBits = strlen($bitString) * 8;
            $endOffset = ($end >= 0) ? min($end, $totalBits - 1) : $totalBits - 1;

            for ($offset = $start; $offset <= $endOffset; $offset++) {
                $byte = (int)($offset / self::BITS_PER_BYTE);
                $bit = $offset % self::BITS_PER_BYTE;
                if ($byte < strlen($bitString)) {
                    $bitValue = (ord($bitString[$byte]) >> $bit) & 1;
                    if ($bitValue === $value) {
                        return $offset;
                    }
                }
            }

            return -1;
        } finally {
            $this->releaseReadLock($key);
        }
    }

    private function __doBitOp(
        string $key,
        int|array $id,
        string $operation = 'OR',
        ?string $destKey = null,
        array $sourceKeys = [],
        int $expire = 0
    ): int {
        if (empty($sourceKeys)) {
            return 0;
        }

        $bitStrings = [];
        $maxLen = 0;
        foreach ($sourceKeys as $srcKey) {
            $bs = $this->__rawGet($srcKey) ?? '';
            $bitStrings[$srcKey] = $bs;
            $maxLen = max($maxLen, strlen($bs));
        }

        if ($maxLen === 0) {
            return 0;
        }

        $result = str_repeat("\0", $maxLen);
        $operation = strtoupper($operation);

        for ($byte = 0; $byte < $maxLen; $byte++) {
            $values = [];
            foreach ($sourceKeys as $srcKey) {
                $values[] = $byte < strlen($bitStrings[$srcKey]) ? ord($bitStrings[$srcKey][$byte]) : 0;
            }

            $resultByte = match ($operation) {
                'AND' => array_reduce($values, fn($carry, $v) => $carry & $v, 0xFF),
                'OR' => array_reduce($values, fn($carry, $v) => $carry | $v, 0),
                'XOR' => array_reduce($values, fn($carry, $v) => $carry ^ $v, 0),
                'NOT' => isset($values[0]) ? (~$values[0]) & 0xFF : 0,
                default => 0
            };
            $result[$byte] = chr($resultByte);
        }

        $targetKey = $destKey ?? ('bitop_result_' . $key . '_' . time());
        $this->__rawSet($targetKey, $result, $expire);

        return $maxLen * 8;
    }
}
