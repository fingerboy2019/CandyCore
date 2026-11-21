<?php
/**
 * Redis 驱动 — Bitmap 位图
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisBitmapOps
{
    public function __rawBit(string $key, int|array $id, mixed $options = null, int $expire = 0): array|int|bool
    {
        // 检测操作类型
        if (is_string($options)) {
            $opType = strtolower($options);
            if ($opType === 'count') {
                return $this->__doBitCount($key, $id);
            }
            if ($opType === 'pos') {
                return $this->__doBitPos($key, $id, $options);
            }
        }
        
        if (is_array($options)) {
            if (isset($options['operation'])) {
                $operation = $options['operation'] ?? 'OR';
                $destKey = $options['destKey'] ?? null;
                return $this->__doBitOp($key, $id, $operation, $destKey, $expire);
            }
            if (isset($options['value'])) {
                return $this->__doBitPos($key, $id, $options['value']);
            }
        }

        // 默认 rw 模式（向后兼容）
        $status = is_bool($options) ? $options : null;
        return $this->__rawBitRW($key, $id, $status, $expire);
    }
    private function __rawBitRW(string $key, int|array $id, ?bool $status = null, int $expire = 0): bool|int|array
    {
        if (empty($key)) {
            return is_int($id) ? 0 : (is_array($id) ? array_fill(0, count($id), 0) : false);
        }

        $idList = is_array($id) ? $id : [$id];
        foreach ($idList as $offset) {
            if (!is_int($offset) || $offset < 0) {
                showExceptionMsg('shutdown', '[RedisCache__rawBit] Bit偏移量必须为非负整数，当前值：%s', var_export($offset, true));
            }
        }

        $isQuery = is_null($status);
        $singleId = is_int($id);

        if (!$this->isFuseAllow()) {
            return is_int($id) ? 0 : (is_array($id) ? array_fill(0, count($id), 0) : false);
        }

        try {
            $redis = $this->getClient();

            // 查询操作
            if ($isQuery) {
                $result = [];
                $pipe = $redis->pipeline();

                foreach ($idList as $offset) {
                    $pipe->getbit($key, $offset);
                }

                $replies = $pipe->exec();
                foreach ($replies as $index => $bitValue) {
                    $result[] = (int)$bitValue;
                    $this->setHotCache("bit_{$key}_{$idList[$index]}", (int)$bitValue, 0);
                }

                $this->recordSuccess();
                return $singleId ? $result[0] : $result;
            }

            // 写入操作
            $pipe = $redis->pipeline();

            foreach ($idList as $offset) {
                $pipe->setbit($key, $offset, (bool)$status);
                $this->deleteHotCache("bit_{$key}_{$offset}");
            }

            $pipe->exec();

            if ($expire > 0) {
                $redis->expire($key, $expire);
            }

            $this->recordSuccess();
            return true;
        } catch (Throwable $e) {
            $this->recordFailure();
            showExceptionMsg('shutdown', sprintf('[RedisCache__rawBit] 异常：%s | Key=%s | ID=%s | Status=%s',
                $e->getMessage(), $key, var_export($id, true), var_export($status, true)
            ));
            return false;
        }
    }
    private function __doBitCount(string $key, int|array $id, mixed $options = null): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();

            if (is_array($id) && count($id) >= 2) {
                $start = (int)$id[0];
                $end = (int)$id[1];
            } else {
                $start = is_int($id) ? $id : 0;
                $end = -1;
            }

            $result = $redis->bitCount($key, $start, $end);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    private function __doBitOp(string $key, int|array $id, string $operation = 'OR', ?string $destKey = null, array $sourceKeys = [], int $expire = 0): int
    {
        if (empty($sourceKeys)) return 0;
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $targetKey = $destKey ?? ('bitop_result_' . $key . '_' . time());

            $result = $redis->bitOp($operation, $targetKey, ...$sourceKeys);

            $this->deleteHotCache($targetKey);
            if ($expire > 0) {
                $redis->expire($targetKey, $expire);
            }
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    private function __doBitPos(string $key, int|array $id, int $value = 0): int
    {
        if (!$this->isFuseAllow()) return -1;

        try {
            $redis = $this->getClient();

            if (is_array($id) && count($id) >= 2) {
                $start = (int)$id[0];
                $end = (int)$id[1];
            } else {
                $start = is_int($id) ? $id : 0;
                $end = -1;
            }

            $result = $redis->bitPos($key, $value, $start, $end);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return -1;
        }
    }
}
