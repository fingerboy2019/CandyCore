<?php
/**
 * 列表操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait ListOps {
    /**
     * 原子左入队
     *
     * @param string $key key
     * @param array $args 入队值
     * @param bool $cover true=先清空再推入（替换）；false=追加（默认，可创建）
     * @return int 入队后的队列长度
     */
    public function lPush(string $key, array $args, bool $cover = false): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0; // 事务中返回 0，保持类型一致
        }
        return $this->__rawPush($this->prefix($key), $args, $cover, 0);
    }
    
    /**
     * 原子右入队
     *
     * @param string $key key
     * @param array $args 入队值
     * @param bool $cover true=先清空再推入（替换）；false=追加（默认，可创建）
     * @return int 入队后的队列长度
     */
    public function rPush(string $key, array $args, bool $cover = false): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0; // 事务中返回 0，保持类型一致
        }
        return $this->__rawPush($this->prefix($key), $args, $cover, 1);
    }
    
    /**
     * 原子左出队
     * @param string $key key
     * @return mixed 出队值，事务中返回 null
     */
    public function lPop(string $key): mixed
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null; // 事务中返回 null，保持类型一致
        }
        return $this->__rawPop($this->prefix($key), 0);
    }
    
    /**
     * 原子右出队
     * @param string $key key
     * @return mixed 出队值，事务中返回 null
     */
    public function rPop(string $key): mixed
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null; // 事务中返回 null，保持类型一致
        }
        return $this->__rawPop($this->prefix($key), 1);
    }

    /**
     * 获取列表长度
     * @param string $key 原始key（自动加前缀）
     * @return int 列表长度
     */
    public function lLen(string $key): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        $list = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($list) ? count($list) : 0;
    }

    /**
     * 通过索引获取列表中的元素
     * @param string $key 原始key（自动加前缀）
     * @param int $index 索引（负数表示从末尾开始）
     * @return mixed 元素值，不存在返回null
     */
    public function lIndex(string $key, int $index): mixed
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $list = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($list)) return null;
        
        // 支持负数索引
        if ($index < 0) {
            $index = count($list) + $index;
        }
        
        return $list[$index] ?? null;
    }

    /**
     * 通过索引设置列表元素的值
     * @param string $key 原始key（自动加前缀）
     * @param int $index 索引
     * @param mixed $value 值
     * @return bool
     */
    public function lSet(string $key, int $index, mixed $value): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return false;
            
            $list = $this->__rawGet($key) ?? [];
            if (!is_array($list)) {
                $this->__releaseLock($key);
                return false;
            }
            
            // 支持负数索引
            if ($index < 0) {
                $index = count($list) + $index;
            }
            
            if (!isset($list[$index])) {
                $this->__releaseLock($key);
                return false;
            }
            
            $list[$index] = $value;
            $result = $this->__rawSet($key, $list);
            $this->__releaseLock($key);
            return $result;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }

    /**
     * 获取列表指定范围的元素
     * @param string $key 原始key（自动加前缀）
     * @param int $start 起始索引
     * @param int $stop 结束索引
     * @return array
     */
    public function lRange(string $key, int $start, int $stop): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $list = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($list) || empty($list)) return [];
        
        // 支持负数索引
        if ($start < 0) {
            $start = count($list) + $start;
        }
        if ($stop < 0) {
            $stop = count($list) + $stop;
        }
        
        $length = $stop - $start + 1;
        return array_slice($list, $start, max(0, $length));
    }

    /**
     * 修剪列表到指定范围
     * @param string $key 原始key（自动加前缀）
     * @param int $start 起始索引
     * @param int $stop 结束索引
     * @return bool
     */
    public function lTrim(string $key, int $start, int $stop): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return false;
            
            $list = $this->__rawGet($key) ?? [];
            if (!is_array($list)) {
                $this->__releaseLock($key);
                return false;
            }
            
            // 支持负数索引
            if ($start < 0) {
                $start = count($list) + $start;
            }
            if ($stop < 0) {
                $stop = count($list) + $stop;
            }
            
            $length = $stop - $start + 1;
            $trimmed = array_slice($list, $start, max(0, $length));
            
            $result = $this->__rawSet($key, $trimmed);
            $this->__releaseLock($key);
            return $result;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }
}
