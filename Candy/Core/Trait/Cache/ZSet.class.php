<?php
/**
 * 有序集合操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait ZSet {
    /**
     * zPopDue 未传 $limit 时的默认弹出条数。
     *
     * 不用「不限」做默认：File/Memory 等模拟驱动是整包读写，
     * 一次弹太多会放大写量（实测一次取 500 条约 87ms/tick）。
     */
    public const ZPOPDUE_DEFAULT_LIMIT = 100;

    /**
     * zPopDue 单次弹出条数的硬上限（调用方传更大值也会被截断到它）。
     *
     * 积压场景请分批循环调用，而不是一次取完。
     */
    public const ZPOPDUE_MAX_LIMIT = 500;

    /**
     * 归一化 zPopDue 的条数：<=0 视为「没传」用默认值，超过上限则截断
     *
     * @param int $limit 调用方传入的条数
     * @return int 生效条数（ZPOPDUE_DEFAULT_LIMIT .. ZPOPDUE_MAX_LIMIT）
     */
    protected static function normalizePopLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::ZPOPDUE_DEFAULT_LIMIT;
        }

        return min($limit, self::ZPOPDUE_MAX_LIMIT);
    }

    /**
     * 添加有序集合成员
     * @param string $key 原始key（自动加前缀）
     * @param float $score 分数
     * @param mixed $member 成员
     * @return bool
     * @note 驱动层可以重写此方法，使用原生命令提高性能和原子性
     */
    public function zAdd(string $key, float $score, mixed $member): bool
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
            
            // 底层获取：ZSet 存储结构 [成员 => 分数]
            $zset = $this->__rawGet($key) ?? [];
            is_array($zset) || $zset = [];

            // 新增/更新分数（自动去重）
            $zset[(string)$member] = $score;

            // 底层存回（自动锁、持久化、LRU）
            $result = $this->__rawSet($key, $zset);
            return $result;
        } catch (\Throwable) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * Z Range
     * @param mixed $key
     * @param mixed $start
     * @param mixed $end
     * @param mixed $withScore
     * @return array
     */
    public function zRange(string $key, int $start = 0, int $end = -1, bool $withScore = false): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $zset = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($zset) || empty($zset)) return [];

        // 按分数正序排序
        asort($zset, SORT_NUMERIC);
        $list = $end === -1 ? array_slice($zset, $start) : array_slice($zset, $start, $end - $start + 1);
        return $withScore ? $list : array_keys($list);
    }

    /**
     * Z Rank
     * @param mixed $key
     * @param mixed $member
     * @return ?int
     */
    public function zRank(string $key, mixed $member): ?int
    {
        $zset = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($zset) || !isset($zset[(string)$member])) return null;

        asort($zset, SORT_NUMERIC);
        $rank = array_search((string)$member, array_keys($zset));
        return $rank === false ? null : (int)$rank;
    }

    /**
     * Z Rev Rank
     * @param mixed $key
     * @param mixed $member
     * @return ?int
     */
    public function zRevRank(string $key, mixed $member): ?int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $rank = $this->zRank($key, $member);
        if ($rank === null) return null;
        $count = $this->zCard($key);
        return $count > 0 ? $count - 1 - $rank : null;
    }

    /**
     * Z Score
     * @param mixed $key
     * @param mixed $member
     * @return ?float
     */
    public function zScore(string $key, mixed $member): ?float
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $zset = $this->__rawGet($this->prefix($key)) ?? [];
        return $zset[(string)$member] ?? null;
    }

    /**
     * Z Card
     * @param mixed $key
     * @return int
     */
    public function zCard(string $key): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        $zset = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($zset) ? count($zset) : 0;
    }

    /**
     * 统计有序集合指定分数范围的成员数量
     * @param string $key 原始key（自动加前缀）
     * @param float $min 最小分数
     * @param float $max 最大分数
     * @return int 成员数量
     */
    public function zCount(string $key, float $min, float $max): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        $zset = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($zset) || empty($zset)) return 0;
        
        $count = 0;
        foreach ($zset as $score) {
            if ($score >= $min && $score <= $max) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 有序集合成员分数增加
     * @param string $key 原始key（自动加前缀）
     * @param float $increment 增量
     * @param mixed $member 成员
     * @return float|false 增加后的分数，失败返回false
     */
    public function zIncrBy(string $key, float $increment, mixed $member): float|false
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0.0;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return false;
            
            $zset = $this->__rawGet($key) ?? [];
            is_array($zset) || $zset = [];
            
            $currentScore = $zset[(string)$member] ?? 0.0;
            $newScore = $currentScore + $increment;
            $zset[(string)$member] = $newScore;
            
            $result = $this->__rawSet($key, $zset);
            $this->__releaseLock($key);
            return $result ? $newScore : false;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }

    /**
     * 按分数范围返回有序集合成员
     * @param string $key 原始key（自动加前缀）
     * @param float $min 最小分数
     * @param float $max 最大分数
     * @param int $offset 偏移量
     * @param int $count 返回数量
     * @param bool $withScore 是否返回分数
     * @return array
     */
    public function zRangeByScore(string $key, float $min, float $max, int $offset = 0, int $count = -1, bool $withScore = false): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $zset = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($zset) || empty($zset)) return [];
        
        // 筛选分数范围内的成员
        $filtered = [];
        foreach ($zset as $member => $score) {
            if ($score >= $min && $score <= $max) {
                $filtered[$member] = $score;
            }
        }
        
        // 按分数正序排序
        asort($filtered, SORT_NUMERIC);
        
        // 应用偏移和数量限制
        if ($offset > 0 || $count > 0) {
            $filtered = array_slice($filtered, $offset, $count > 0 ? $count : null, true);
        }
        
        return $withScore ? $filtered : array_keys($filtered);
    }

    /**
     * 弹出分数最低的成员
     * @param string $key 原始key（自动加前缀）
     * @param int $count 弹出数量
     * @return array|null [成员 => 分数] 数组，失败返回null
     */
    public function zPopMin(string $key, int $count = 1): ?array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return null;
            
            $zset = $this->__rawGet($key) ?? [];
            if (!is_array($zset) || empty($zset)) {
                $this->__releaseLock($key);
                return null;
            }
            
            // 按分数正序排序
            asort($zset, SORT_NUMERIC);
            
            // 弹出最低分数的成员
            $popped = array_slice($zset, 0, $count, true);
            $remaining = array_slice($zset, $count, null, true);
            
            $this->__rawSet($key, $remaining);
            $this->__releaseLock($key);
            return $popped;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return null;
        }
    }

    /**
     * 弹出分数最高的成员
     * @param string $key 原始key（自动加前缀）
     * @param int $count 弹出数量
     * @return array|null [成员 => 分数] 数组，失败返回null
     */
    public function zPopMax(string $key, int $count = 1): ?array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return null;
            
            $zset = $this->__rawGet($key) ?? [];
            if (!is_array($zset) || empty($zset)) {
                $this->__releaseLock($key);
                return null;
            }
            
            // 按分数倒序排序
            arsort($zset, SORT_NUMERIC);
            
            // 弹出最高分数的成员
            $popped = array_slice($zset, 0, $count, true);
            $remaining = array_slice($zset, $count, null, true);
            
            $this->__rawSet($key, $remaining);
            $this->__releaseLock($key);
            return $popped;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return null;
        }
    }

    /**
     * 删除 ZSet 中的成员
     * @param string $key 原始key（自动加前缀）
     * @param mixed ...$members 成员
     * @return int 删除的成员数量
     */
    public function zRem(string $key, mixed ...$members): int
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        return $this->__rawZRem($this->prefix($key), ...$members);
    }

    /**
     * 按分数倒序返回 ZSet 中的成员
     * @param string $key 原始key（自动加前缀）
     * @param int $start 起始索引
     * @param int $stop 结束索引
     * @param bool $withScores 是否返回分数
     * @return array
     */
    public function zRevRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        return $this->__rawZRevRange($this->prefix($key), $start, $stop, $withScores);
    }

    /**
     * 删除 ZSet 中的成员（默认实现）
     * @param string $key 带前缀的完整key
     * @param mixed ...$members 成员
     * @return int 删除的成员数量
     */
    protected function __rawZRem(string $key, mixed ...$members): int
    {
        try {
            // 加锁后操作
            if (!$this->__acquireLock($key)) return 0;
            
            $zset = $this->__rawGet($key) ?? [];
            if (!is_array($zset)) {
                $this->__releaseLock($key);
                return 0;
            }

            $count = 0;
            foreach ($members as $member) {
                $memberStr = (string)$member;
                if (isset($zset[$memberStr])) {
                    unset($zset[$memberStr]);
                    $count++;
                }
            }

            if ($count > 0) {
                $this->__rawSet($key, $zset);
            }
            
            $this->__releaseLock($key);
            return $count;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return 0;
        }
    }

    /**
     * 按分数倒序返回 ZSet 中的成员（默认实现）
     * @param string $key 带前缀的完整key
     * @param int $start 起始索引
     * @param int $stop 结束索引
     * @param bool $withScores 是否返回分数
     * @return array
     */
    protected function __rawZRevRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        $zset = $this->__rawGet($key) ?? [];
        if (!is_array($zset) || empty($zset)) return [];

        // 按分数倒序排序
        arsort($zset, SORT_NUMERIC);
        
        // 计算切片
        $keys = array_keys($zset);
        $total = count($keys);
        
        // 处理负数索引
        if ($start < 0) $start = $total + $start;
        if ($stop < 0) $stop = $total + $stop;
        
        // 边界检查
        if ($start < 0) $start = 0;
        if ($stop >= $total) $stop = $total - 1;
        if ($start > $stop) return [];
        
        $length = $stop - $start + 1;
        $sliced = array_slice($zset, $start, $length, true);

        return $withScores ? $sliced : array_keys($sliced);
    }

    /**
     * 原子弹出所有 score <= $maxScore 的成员（取走即移除）
     *
     * 模拟驱动（File/Memory/Shmop/Sqlite）下**必须用它**，不能写成
     * `zRangeByScore()` + `zRem()` —— 那是两次独立加锁，中间的空窗会让
     * 两个进程取到同一批成员（重复消费）。本方法把「读到期 + 移除 + 写回」
     * 放在同一个 __acquireLock 区间内。
     *
     * Redis 驱动有自己的原生覆盖（ZRANGEBYSCORE + ZREM，靠 ZREM 返回 1/0 裁决）。
     *
     * @param string $key      zset 键
     * @param float  $maxScore 分数上限（含）
     * @param int    $limit    本次最多取走多少条（<= 0 表示不限）
     * @return array<int,string> 被弹出的成员，按分数升序
     */
    public function zPopDue(
        string $key,
        ?float $maxScore = null,
        int $limit = self::ZPOPDUE_DEFAULT_LIMIT
    ): array {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }

        // 参数兜底：不传时间戳取「现在」，条数归一化（<=0 用默认，超大被截断）
        $maxScore = $maxScore ?? (float) time();
        $limit    = self::normalizePopLimit($limit);

        $key = $this->prefix($key);
        if (!$this->__acquireLock($key)) {
            return [];
        }

        try {
            $zset = $this->__rawGet($key);
            if (!is_array($zset) || $zset === []) {
                return [];
            }

            $due = [];
            foreach ($zset as $member => $score) {
                if ((float) $score <= $maxScore) {
                    $due[(string) $member] = (float) $score;
                }
            }
            if ($due === []) {
                return [];
            }

            asort($due, SORT_NUMERIC);
            if ($limit > 0) {
                $due = array_slice($due, 0, $limit, true);
            }

            $rest = $zset;
            foreach (array_keys($due) as $member) {
                unset($rest[$member]);
            }
            $this->__rawSet($key, $rest);

            return array_map('strval', array_keys($due));
        } finally {
            $this->__releaseLock($key);
        }
    }
}
