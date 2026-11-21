<?php
/**
 * CuckooFilter 服务 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

use Candy\Core\Impl\Pack\CuckooFilter\CuckooFilterService;

trait Cuckoo {
    // 布隆过滤器默认参数
    protected const BLOOM_DEFAULT_CAPACITY = 100000;
    protected const BLOOM_DEFAULT_ERROR_RATE = 0.001;
    /**
     * Init Cuckoo Transaction Methods
     */
    protected function initCuckooTransactionMethods(): void
    {
        // 使用 registerMethods 批量注册 Cuckoo 过滤器操作方法
        // 格式: ["短方法名", "原始方法名", "结果处理器/null"]
        $this->registerMethods([
            ["cFilterInit", "__rawSet", null],
            ["cFilterAdd", "__rawSet", null],
            ["cFilterDelete", "__rawSet", null],
            ["cFilterClear", "__rawSet", null],
            ["cFilterExists", "__rawGet", fn($r) => (bool)$r],
        ]);
    }

    /**
     * 初始化布隆过滤器（自动计算最优参数）
     * @param string $key 过滤器键
     * @param int $capacity 预计容量（默认类常量 BLOOM_DEFAULT_CAPACITY）
     * @param float $errorRate 误判率（默认类常量 BLOOM_DEFAULT_ERROR_RATE）
     * @return bool
     */
    public function cFilterInit(string $key, int $capacity = self::BLOOM_DEFAULT_CAPACITY, float $loadFactor = 0.9): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        try {
            // 计算布谷鸟过滤器桶数量
            $size = CuckooFilterService::calculateSize($capacity, $loadFactor);
            
            // 初始化布谷鸟过滤器
            $config = CuckooFilterService::cFilterInit($size);
            return $this->__rawSet($this->prefix($key), $config);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 添加元素到布谷鸟过滤器
     * @param string $key 过滤器键
     * @param string $element 元素（用户ID/商品ID/IP）
     * @return bool
     */
    public function cFilterAdd(string $key, string $element): bool
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
            
            $config = $this->__rawGet($key);
            if (!$config) {
                return false;
            }

            // 使用布谷鸟过滤器添加元素
            $updatedConfig = CuckooFilterService::cFilterAdd($config, $element);
            if (!$updatedConfig) {
                return false;
            }

            $result = $this->__rawSet($key, $updatedConfig);
            return $result;
        } catch (\Throwable) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }

    /**
     * 判断元素是否存在
     * @param string $key 过滤器键
     * @param string $element 元素
     * @return bool 存在返回true，不存在返回false
     */
    public function cFilterExists(string $key, string $element): bool
    {
        $config = $this->__rawGet($this->prefix($key));
        if (!$config) return false;

        // 使用布谷鸟过滤器检查元素
        return CuckooFilterService::cFilterExists($config, $element);
    }

    /**
     * 清空布谷鸟过滤器
     * @param string $key 过滤器键
     * @return bool
     */
    public function cFilterClear(string $key): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        $config = $this->__rawGet($key);
        if ($config) {
            // 使用布谷鸟过滤器清空
            $config = CuckooFilterService::cFilterClear($config);
            return $this->__rawSet($key, $config);
        }
        return false;
    }

    /**
     * 从布谷鸟过滤器删除元素
     * @param string $key 过滤器键
     * @param string $element 元素
     * @return bool
     */
    public function cFilterDelete(string $key, string $element): bool
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
            
            $config = $this->__rawGet($key);
            if (!$config) {
                return false;
            }

            // 使用布谷鸟过滤器删除元素
            $updatedConfig = CuckooFilterService::cFilterDelete($config, $element);
            $result = $this->__rawSet($key, $updatedConfig);
            return $result;
        } catch (\Throwable) {
            return false;
        } finally {
            $this->__releaseLock($key);
        }
    }
}
