<?php
/**
 * 栈操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */
declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait Stack {
    

    /**
     * 栈入栈（压入元素）
     * @param string $key 栈键
     * @param mixed $element 入栈元素
     * @return bool
     */
    public function stackPush(string $key, mixed $element): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return false;
            // 获取栈数组
            $stack = $this->__rawGet($key) ?? [];
            is_array($stack) || $stack = [];

            // 入栈（末尾为栈顶）
            array_push($stack, $element);

            // 存回底层
            return $this->__rawSet($key, $stack);
        } catch (\Throwable) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * 栈出栈（弹出栈顶元素，删除并返回）
     * @param string $key
     * @return int|bool|null 空返回 null，事务中返回 true
     */
    public function stackPop(string $key): int|bool|null
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return null;
            $stack = $this->__rawGet($key) ?? [];
            if (!is_array($stack) || empty($stack)) {
                return null;
            }

            // 弹出栈顶
            $element = array_pop($stack);

            // 存回剩余元素
            $this->__rawSet($key, $stack);
            return $element;
        } catch (\Throwable) {
            return null;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * 查看栈顶元素（不删除）
     * @param string $key
     * @return mixed
     */
    public function stackPeek(string $key): mixed
    {
        $stack = $this->__rawGet($this->prefix($key)) ?? [];
        if (!is_array($stack) || empty($stack)) {
            return null;
        }
        // 返回最后一个元素（栈顶）
        return end($stack);
    }

    /**
     * 判断栈是否为空
     * @param string $key
     * @return bool
     */
    public function stackIsEmpty(string $key): bool
    {
        $stack = $this->__rawGet($this->prefix($key)) ?? [];
        return empty($stack);
    }

    /**
     * 获取栈元素数量
     * @param string $key
     * @return int
     */
    public function stackSize(string $key): int
    {
        $stack = $this->__rawGet($this->prefix($key)) ?? [];
        return is_array($stack) ? count($stack) : 0;
    }

    /**
     * 清空栈
     * @param string $key
     * @return bool
     */
    public function stackClear(string $key): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return false;
            return $this->__rawSet($key, []);
        } catch (\Throwable) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }
    
}
