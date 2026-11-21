<?php
/**
 * 限流操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait RateLimit {
    /**
     * 滑动窗口限流器 (Sliding Window)
     * 特点：精度高，能有效防止"边界突刺"
     * 场景：防刷、登录次数限制
     *
     * @param string $key 限流标识 (如 'login:192.168.1.1')
     * @param int $maxRequests 最大请求数
     * @param int $windowSeconds 窗口大小 (秒)
     * @return bool true=允许通过, false=被限流
     */
    public function rateLimitSlide(string $key, int $maxRequests, int $windowSeconds): bool
    {
        // 逻辑键走公共 API（自动租户隔离）；锁用物理键避免与业务键冲突
        $logicalKey = 'rate:slide:' . $key;
        $lockKey = $this->prefix($logicalKey);
        $now = microtime(true) * 1000; // 当前毫秒时间戳
        $windowStart = $now - ($windowSeconds * 1000);

        try {
            // 1. 加锁保证原子性
            if (!$this->__acquireLock($lockKey)) return false;

            // 2. 移除窗口外的旧记录
            // 注意：这里需要你的 ZSet 支持按分数范围删除，如果没有，可以在驱动里加个 __rawZRemRangeByScore
            // 这里我们用一个通用的笨办法：先查出来再删 (性能稍差但通用)
            $allMembers = $this->zRange($logicalKey, 0, -1, true);
            foreach ($allMembers as $member => $score) {
                if ($score < $windowStart) {
                    $this->zRem($logicalKey, $member);
                }
            }

            // 3. 统计当前窗口内的数量
            $current = $this->zCard($logicalKey);

            if ($current >= $maxRequests) {
                $this->__releaseLock($lockKey);
                return false; // 超过限制
            }

            // 4. 记录本次请求 (member用唯一ID，score用时间戳)
            $this->zAdd($logicalKey, $now, uniqid('', true));
            
            // 5. 设置整个key的过期时间 (稍微比窗口长一点，防止垃圾残留)
            $this->expire($logicalKey, $windowSeconds + 1);

            $this->__releaseLock($lockKey);
            return true;
        } catch (\Throwable) {
            $this->__releaseLock($lockKey);
            return false;
        }
    }

    /**
     * 令牌桶限流器 (Token Bucket)
     * 特点：流量平滑，允许一定程度的"突发流量"
     * 场景：API 调用配额、消息队列削峰
     *
     * @param string $key 限流标识
     * @param int $capacity 桶容量 (最大突发数)
     * @param int $fillRate 每秒填充速率 (生成令牌数)
     * @param int $tokensNeeded 每次请求消耗的令牌数 (默认1)
     * @return bool true=允许通过, false=被限流
     */
    public function rateLimitToken(string $key, int $capacity, int $fillRate, int $tokensNeeded = 1): bool
    {
        $logicalKey = 'rate:token:' . $key;
        $lockKey = $this->prefix($logicalKey);
        
        try {
            if (!$this->__acquireLock($lockKey)) return false;

            // 1. 获取当前状态（逻辑键，prefix 内按租户隔离）
            $state = $this->get($logicalKey);
            $now = time();

            if ($state === null) {
                // 初始化：桶满
                $state = [
                    'tokens' => $capacity,
                    'last_time' => $now
                ];
            } else {
                // 2. 计算时间差，填充令牌
                $timePassed = $now - $state['last_time'];
                $newTokens = $timePassed * $fillRate;
                
                $state['tokens'] = min($capacity, $state['tokens'] + $newTokens);
                $state['last_time'] = $now;
            }

            // 3. 检查是否有足够的令牌
            if ($state['tokens'] < $tokensNeeded) {
                $this->__releaseLock($lockKey);
                return false;
            }

            // 4. 消耗令牌
            $state['tokens'] -= $tokensNeeded;
            
            // 5. 保存状态 (永不过期或设置很长的TTL)
            $this->set($logicalKey, $state, 86400);

            $this->__releaseLock($lockKey);
            return true;
        } catch (\Throwable) {
            $this->__releaseLock($lockKey);
            return false;
        }
    }
}
