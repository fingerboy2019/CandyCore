<?php
/**
 * 哈希表操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait Hash {
    /**
     * 设置哈希字段值
     * @param string $key 原始key（自动加前缀）
     * @param string|array $field 哈希字段名或字段值数组
     * @param mixed $value 哈希字段值（field为数组时可省略）
     * @param int $ttl 过期时间，0=永久
     * @return bool
     * @note 驱动层可以重写此方法，使用原生命令提高性能和原子性
     */
    public function hSet(string $key, string|array $field, mixed $value = null, int $ttl = 0): bool
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
            
            // 1. 调用底层获取原始数组
            $hash = $this->__rawGet($key) ?? [];
            is_array($hash) || $hash = [];

            // 2. 操作数组
            if (is_array($field)) {
                foreach ($field as $f => $v) $hash[(string)$f] = $v;
            } else {
                $hash[(string)$field] = $value;
            }

            // 3. 调用底层存回（自动带锁、持久化、LRU）
            $result = $this->__rawSet($key, $hash, $ttl);
            return $result;
        } catch (\Throwable $e) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * 原生哈希读取（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @param string $field 哈希字段
     * @param mixed $default 默认值
     * @return mixed 哈希字段值
     */
    public function hGet(string $key, string $field, mixed $default = null): mixed
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return $default;
        }
        $hash = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($hash) ? ($hash[$field] ?? $default) : $default;
    }

    /**
     * 原生哈希删除（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @param string ...$fields 哈希字段（支持多个）
     * @return bool
     */
    public function hDel(string $key, string ...$fields): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return false;
            $hash = $this->__rawGet($key) ?? [];
            if (!is_array($hash)) return true;

            foreach ($fields as $f) unset($hash[$f]);
            return $this->__rawSet($key, $hash);
        } catch (\Throwable) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * 批量设置哈希字段值
     * @param string $key 原始key（自动加前缀）
     * @param array $fieldValues 字段值数组，格式：['field1' => 'value1', 'field2' => 'value2']
     * @param int $ttl 过期时间，0=永久
     * @return bool
     */
    public function hMSet(string $key, array $fieldValues, int $ttl = 0): bool
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
            
            $hash = $this->__rawGet($key) ?? [];
            if (!is_array($hash)) $hash = [];

            foreach ($fieldValues as $field => $value) {
                $hash[$field] = $value;
            }
            
            $result = $this->__rawSet($key, $hash, $ttl);
            $this->__releaseLock($key);
            return $result;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }

    /**
     * 原生哈希获取所有字段（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @return array 哈希字段数组
     */
    public function hKeys(string $key): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $hash = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($hash) ? array_keys($hash) : [];
    }

    /**
     * 获取哈希所有字段值（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @return array 哈希字段值数组
     */
    public function hVals(string $key): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $hash = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($hash) ? array_values($hash) : [];
    }

    /**
     * 获取哈希所有字段和值（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @return array 哈希字段键值对数组
     */
    public function hGetAll(string $key): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $hash = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($hash) ? $hash : [];
    }

    /**
     * 批量获取哈希字段值
     * @param string $key 原始key（自动加前缀）
     * @param array $fields 字段名数组
     * @return array 字段值对数组
     */
    public function hMGet(string $key, array $fields): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $hash = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($hash)) return [];
        
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $hash[$field] ?? null;
        }
        return $result;
    }

    /**
     * 原生哈希获取字段数量（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @return int 哈希字段数量
     */
    public function hLen(string $key): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        $hash = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($hash) ? count($hash) : 0;
    }

    /**
     * 判断哈希字段是否存在
     * @param string $key 原始key（自动加前缀）
     * @param string $field 字段
     * @return bool
     */
    public function hExists(string $key, string $field): bool
    {
        return $this->__rawHExists($this->prefix($key), $field);
    }

    /**
     * 判断哈希字段是否存在（默认实现）
     * @param string $key 带前缀的完整key
     * @param string $field 字段
     * @return bool
     */
    protected function __rawHExists(string $key, string $field): bool
    {
        $hash = $this->__rawGet($key) ?? [];
        if (!is_array($hash)) return false;
        return array_key_exists($field, $hash);
    }
}
