<?php
/**
 * HyperLog 服务 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

use Candy\Core\Impl\Pack\HyperLog\HyperLogService;

trait HyperLog {
    const HLL_REGISTERS = 128;

    /**
     * 初始化 HyperLog 事务方法
     * 使用 registerMethods 批量注册以减少函数调用
     */
    protected function initHyperLogTransactionMethods(): void
    {
        // 使用 registerMethods 批量注册 HyperLog 操作方法
        // 格式: ["短方法名", "原始方法名", "结果处理器/null"]
        $this->registerMethods([
            ["hLogAdd", "__rawSet", null],
            ["hLogMerge", "__rawSet", null],
            ["hLogCount", "__rawGet", fn($r) => is_numeric($r) ? (int)$r : 0],
        ]);
    }
    
    /**
     * 向 HyperLog 添加元素
     * @param string $key 统计键
     * @param mixed ...$elements 元素（用户ID、IP、设备ID等）
     * @return bool
     */
    public function hLogAdd(string $key, mixed ...$elements): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            // 加锁后操作
            if (!$this->__acquireLock($key)) return false;
            
            $hll = $this->__rawGet($key) ?? array_fill(0, self::HLL_REGISTERS, 0);
            foreach ($elements as $item) {
                $hash = $this->hllHash((string)$item);
                $reg = ($hash >> 53) & (self::HLL_REGISTERS - 1);
                $rank = min(64 - strspn(decbin($hash & ((1 << 53) - 1)), '0'), 63);
                if ($rank > $hll[$reg]) $hll[$reg] = $rank;
            }
            $result = $this->__rawSet($key, $hll);
            return $result;
        } catch (\Throwable) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * 统计 HyperLog 基数（去重数量）
     * @param string $key
     * @return int
     */
    public function hLogCount(string $key): int
    {
        $hll = $this->__rawGet($this->prefix($key)) ?? array_fill(0, self::HLL_REGISTERS, 0);
        $sum = array_reduce($hll, fn($s, $v) => $s + 1 / (1 << $v), 0);
        $estimate = (0.7213 / (1 + 1.079 / self::HLL_REGISTERS)) * (self::HLL_REGISTERS * self::HLL_REGISTERS) / $sum;
        return (int)round($estimate);
    }

    /**
     * 合并多个 HyperLog
     * @param string $dest 目标键
     * @param string ...$sources 源键
     * @return bool
     */
    public function hLogMerge(string $dest, string ...$sources): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        try {
            $result = array_fill(0, self::HLL_REGISTERS, 0);
            foreach ($sources as $sourceKey) {
                $hll = $this->__rawGet($this->prefix($sourceKey)) ?? [];
                if (count($hll) !== self::HLL_REGISTERS) continue;
                foreach ($hll as $i => $v) $result[$i] = max($result[$i], $v);
            }
            return $this->__rawSet($this->prefix($dest), $result);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 内部哈希函数
     * @param string $str 输入字符串
     * @return int 哈希值
     */
    private function hllHash(string $str): int
    {
        return HyperLogService::hllHash($str);
    }
    
    /**
     * CRC32 优化版哈希算法
     * @param string $str 输入字符串
     * @return int 哈希值
     */
    private function optimizedCrc32Hash(string $str): int
    {
        return HyperLogService::optimizedCrc32Hash($str);
    }
}
