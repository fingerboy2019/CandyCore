<?php
/**
 * 集合操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait Set {
    /**
     * 添加元素到集合
     * @param string $key 原始key（自动加前缀）
     * @param mixed ...$members 元素（支持多个）
     * @return int|bool 添加的元素数量
     * @note 驱动层可以重写此方法，使用原生命令提高性能和原子性
     */
    public function sAdd(string $key, mixed ...$members): int|bool
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
            
            $set = $this->__rawGet($key) ?? [];
            is_array($set) || $set = [];

            $count = 0;
            foreach ($members as $m) {
                if (!in_array($m, $set, true)) {
                    $set[] = $m;
                    $count++;
                }
            }

            $this->__rawSet($key, $set);
            return $count;
        } catch (\Throwable) {
            return 0;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * 批量添加元素到集合
     * @param string $key 原始key（自动加前缀）
     * @param array $members 元素数组
     * @param int $ttl 过期时间，0=永久
     * @return int|bool 添加的元素数量
     */
    public function sAddBulk(string $key, array $members, int $ttl = 0): int|bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            // 加锁后一次性读取、修改、写入
            if (!$this->__acquireLock($key)) return false;
            
            $set = $this->__rawGet($key) ?? [];
            is_array($set) || $set = [];

            $count = 0;
            foreach ($members as $m) {
                if (!in_array($m, $set, true)) {
                    $set[] = $m;
                    $count++;
                }
            }

            $this->__rawSet($key, $set, $ttl);
            $this->__releaseLock($key);
            return $count;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return 0;
        }
    }

    /**
     * S Rem
     * @param mixed $key
     * @param mixed $members
     * @return int|bool
     */
    public function sRem(string $key, mixed ...$members): int|bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return 0;
            $set = $this->__rawGet($key) ?? [];
            if (!is_array($set)) return 0;

            $count = 0;
            foreach ($members as $m) {
                $idx = array_search($m, $set, true);
                if ($idx !== false) {
                    array_splice($set, $idx, 1);
                    $count++;
                }
            }

            $this->__rawSet($key, $set);
            return $count;
        } catch (\Throwable) {
            return 0;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * S Members
     * @param mixed $key
     * @return array
     */
    public function sMembers(string $key): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $set = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($set) ? $set : [];
    }

    /**
     * 随机移除集合中的元素
     * @param string $key 原始key（自动加前缀）
     * @param int $count 移除数量
     * @return array 移除的元素数组
     */
    public function sPop(string $key, int $count = 1): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return [];
            $set = $this->__rawGet($key) ?? [];
            if (!is_array($set) || empty($set)) {
                $this->__releaseLock($key);
                return [];
            }
            
            // 随机选择元素
            $keys = array_rand($set, min($count, count($set)));
            $keys = is_array($keys) ? $keys : [$keys];
            
            $popped = [];
            foreach ($keys as $idx) {
                $popped[] = $set[$idx];
                unset($set[$idx]);
            }
            
            $this->__rawSet($key, array_values($set));
            $this->__releaseLock($key);
            return $popped;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return [];
        }
    }

    /**
     * 随机获取集合中的元素（不移除）
     * @param string $key 原始key（自动加前缀）
     * @param int $count 获取数量
     * @return array 随机元素数组
     */
    public function sRandMember(string $key, int $count = 1): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $set = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($set) || empty($set)) return [];
        
        $keys = array_rand($set, min($count, count($set)));
        $keys = is_array($keys) ? $keys : [$keys];
        
        $result = [];
        foreach ($keys as $idx) {
            $result[] = $set[$idx];
        }
        return $result;
    }

    /**
     * 获取多个集合的差集
     * @param string ...$keys 原始key数组（自动加前缀）
     * @return array 差集结果数组
     */
    public function sDiff(string ...$keys): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        if (count($keys) < 2) return [];
        
        $sets = [];
        foreach ($keys as $k) {
            $set = $this->__rawGet($this->prefix($k)) ?? [];
            if (!is_array($set)) return [];
            $sets[] = $set;
        }
        
        $result = array_shift($sets);
        foreach ($sets as $s) {
            $result = array_diff($result, $s);
        }
        return array_values($result);
    }

    /**
     * S Union
     * @param mixed $keys
     * @return array
     */
    public function sUnion(string ...$keys): array
    {
        $result = [];
        foreach ($keys as $k) {
            $set = $this->__rawGet($this->prefix($k)) ?? [];
            is_array($set) && $result = array_merge($result, $set);
        }
        return array_values(array_unique($result, SORT_REGULAR));
    }

    /**
     * S Inter
     * @param mixed $keys
     * @return array
     */
    public function sInter(string ...$keys): array
    {
        if (count($keys) < 2) return $this->sMembers($keys[0] ?? '');
        
        $sets = [];
        foreach ($keys as $k) {
            $set = $this->__rawGet($this->prefix($k)) ?? [];
            if (!is_array($set) || empty($set)) return [];
            $sets[] = $set;
        }

        $result = array_shift($sets);
        foreach ($sets as $s) $result = array_intersect($result, $s);
        return array_values($result);
    }

    /**
     * 判断元素是否在集合中
     * @param string $key 原始key（自动加前缀）
     * @param mixed $member 成员
     * @return bool
     */
    public function sIsMember(string $key, mixed $member): bool
    {
        return $this->__rawSIsMember($this->prefix($key), $member);
    }

    /**
     * 判断元素是否在集合中（默认实现）
     * @param string $key 带前缀的完整key
     * @param mixed $member 成员
     * @return bool
     */
    protected function __rawSIsMember(string $key, mixed $member): bool
    {
        $set = $this->__rawGet($key) ?? [];
        if (!is_array($set)) return false;
        return in_array($member, $set, true);
    }

    /**
     * 设置带标签的缓存
     * @param string $key 原始key（自动加前缀）
     * @param mixed $value 缓存值
     * @param array $tags 标签数组
     * @param int $ttl 过期时间，0=永久
     * @return bool
     */
    public function setWithTags(string $key, mixed $value, array $tags, int $ttl = 0): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $result = $this->set($key, $value, $ttl);
        if ($result) {
            foreach ($tags as $tag) {
                $this->sAdd('tag_' . $tag, $key);
            }
        }
        return $result;
    }

    /**
     * 按标签清除缓存
     * @param string $tag 标签名
     * @return bool
     */
    public function clearByTag(string $tag): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $keys = $this->sMembers('tag_' . $tag);
        $this->mDelete($keys);
        return $this->delete('tag_' . $tag);
    }
}
