<?php
/**
 * 流式操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */
declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait Stream {
    /**
     * 追加消息到流（自动添加缓存前缀）
     * @param string $key 原始key（自动加前缀）
     * @param array $fieldValues 字段值对数组
     * @param string|null $id 消息ID，*表示自动生成
     * @param int $maxLen 最大长度，0表示不限制
     * @return string|bool|false 消息ID，失败返回false，事务中返回 true
     */
    public function xAdd(string $key, array $fieldValues, ?string $id = '*', int $maxLen = 0): string|bool
	{
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawXAdd($this->prefix($key), $fieldValues, $id, $maxLen);
    }

    /**
     * 读取消息（支持多key，自动添加缓存前缀）
     * @param string|array $keys 原始key或key数组（自动加前缀）
     * @param int $count 单次读取的最大消息数，0表示不限制
     * @param int $blockMs 阻塞超时时间（毫秒），0表示非阻塞
     * @return array|false 消息数组，失败返回false
     */
    public function xRead(string|array $keys, int $count = 0, int $blockMs = 0): array|false
	{
        // 事务支持
        if ($this->inTransaction) {
            // 阻塞读在事务中不支持
            if ($blockMs > 0) {
                throw new \InvalidArgumentException('Block read is not supported in transaction');
            }
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        if (is_string($keys)) {
            $keys = [$this->prefix($keys)];
        } elseif (is_array($keys)) {
            $keys = array_map(fn($k) => $this->prefix($k), $keys);
        }
        return $this->__rawXRead($keys, $count, $blockMs);
    }

    /**
     * 创建/配置消费者组（自动添加缓存前缀）
     * @param string $key 原始key（自动加前缀）
     * @param string $group 消费者组名称
     * @param string $startId 起始消息ID，0表示从头开始
     * @param bool $mkStream 是否自动创建流
     * @return bool 操作是否成功
     */
    public function xGroup(string $key, string $group, string $startId = '0', bool $mkStream = false): bool
	{
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawXGroup($this->prefix($key), $group, $startId, $mkStream);
    }

    /**
     * 消费者组读取消息（自动添加缓存前缀）
     * @param string $group 消费者组名称
     * @param string $consumer 消费者名称
     * @param string $key 原始key（自动加前缀）
     * @param int $count 单次读取的最大消息数，0表示不限制
     * @param int $blockMs 阻塞超时时间（毫秒），0表示非阻塞
     * @return array|false 消息数组，失败返回false
     */
    public function xReadGroup(string $group, string $consumer, string $key, int $count = 0, int $blockMs = 0):  array|false
	{
        // 事务支持
        if ($this->inTransaction) {
            // 阻塞读在事务中不支持
            if ($blockMs > 0) {
                throw new \InvalidArgumentException('Block read is not supported in transaction');
            }
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawXReadGroup($group, $consumer, $this->prefix($key), $count, $blockMs);
    }

    /**
     * 确认消息（不添加缓存前缀，需使用完整key）
     * @param string $key 带前缀的完整key
     * @param string $group 消费者组名称
     * @param array $ids 消息ID数组
     * @return int|false 确认的消息数量，失败返回false
     */
    public function xAck(string $key, string $group, array $ids):  int|false
	{
        return $this->__rawXAck($this->prefix($key), $group, $ids);
    }

    /** 查询 Pending；不支持可靠消费者组的驱动返回 false。 */
    public function xPending(string $key, string $group, ?string $start = null, ?string $end = null, int $count = 0, ?string $consumer = null): array|false
    {
        unset($key, $group, $start, $end, $count, $consumer);
        return false;
    }

    /** 接管超时 Pending；不支持可靠消费者组的驱动返回 false。 */
    public function xClaim(string $key, string $group, string $consumer, int $minIdleMs, array $ids, array $options = []): array|false
    {
        unset($key, $group, $consumer, $minIdleMs, $ids, $options);
        return false;
    }

    /** 自动接管 Pending；不支持可靠消费者组的驱动返回 false。 */
    public function xAutoClaim(string $key, string $group, string $consumer, int $minIdleMs, string $startId = '0-0', int $count = 0, array $options = []): array|false
    {
        unset($key, $group, $consumer, $minIdleMs, $startId, $count, $options);
        return false;
    }

    /**
     * 范围查询流中的消息
     * @param string $key 原始key（自动加前缀）
     * @param string $start 起始ID，-表示最小值
     * @param string $end 结束ID，+表示最大值
     * @param int $count 最大返回消息数，0表示不限制
     * @return array|false 消息数组，失败返回false
     */
    public function xRange(string $key, string $start = '-', string $end = '+', int $count = 0):  array|false
	{
        return $this->__rawXRange($this->prefix($key), $start, $end, $count);
    }

    /**
     * 反向范围查询流中的消息
     * @param string $key 原始key（自动加前缀）
     * @param string $start 起始ID，+表示最大值
     * @param string $end 结束ID，-表示最小值
     * @param int $count 最大返回消息数，0表示不限制
     * @return array|false 消息数组，失败返回false
     */
    public function xRevRange(string $key, string $start = '+', string $end = '-', int $count = 0): array|false
    {
        return $this->__rawXRevRange($this->prefix($key), $start, $end, $count);
    }

    /**
     * 获取流中的消息数量
     * @param string $key 原始key（自动加前缀）
     * @return int 流中的消息数量
     */
    public function xLen(string $key): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        // 通过查询整个流来统计消息数量
        $result = $this->__rawXRange($this->prefix($key), '-', '+');
        return is_array($result) ? count($result) : 0;
    }

    /**
     * 删除流中的消息
     * @param string $key 原始key（自动加前缀）
     * @param array $ids 消息ID数组
     * @return int 删除的消息数量
     */
    public function xDel(string $key, array $ids): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        // Redis驱动会使用原生XDEL命令，其他驱动通过读取-修改-写入实现
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return 0;
            
            // 获取所有消息
            $allMessages = $this->__rawXRange($key, '-', '+');
            if (!is_array($allMessages)) {
                $this->__releaseLock($key);
                return 0;
            }
            
            // 过滤掉要删除的消息
            $idsToRemove = array_flip($ids);
            $filteredMessages = array_filter($allMessages, function($msg) use ($idsToRemove) {
                return !isset($idsToRemove[$msg['id']]);
            });
            
            // 重新写入（注意：这需要驱动支持批量删除和插入）
            // 简化实现：删除整个流并重建
            $this->__rawDelete($key);
            foreach ($filteredMessages as $msg) {
                $this->__rawXAdd($key, $msg['fields'], $msg['id']);
            }
            
            $this->__releaseLock($key);
            return count($ids) - count(array_filter($ids, function($id) use ($allMessages) {
                return !array_any($allMessages, fn($m) => $m['id'] === $id);
            }));
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return 0;
        }
    }

    /**
     * 修剪流到最大长度
     * @param string $key 原始key（自动加前缀）
     * @param int $maxLen 最大消息数
     * @return int 删除的消息数量
     */
    public function xTrim(string $key, int $maxLen): int
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        $key = $this->prefix($key);
        try {
            if (!$this->__acquireLock($key)) return 0;
            
            $allMessages = $this->__rawXRange($key, '-', '+');
            if (!is_array($allMessages) || count($allMessages) <= $maxLen) {
                $this->__releaseLock($key);
                return 0;
            }
            
            $toDelete = count($allMessages) - $maxLen;
            // 保留最后 maxLen 条消息
            $toKeep = array_slice($allMessages, -$maxLen);
            
            $this->__rawDelete($key);
            foreach ($toKeep as $msg) {
                $this->__rawXAdd($key, $msg['fields'], $msg['id']);
            }
            
            $this->__releaseLock($key);
            return $toDelete;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return 0;
        }
    }

    /**
     * 追加消息（对应 Redis XADD 命令）
     * @param string $key 带前缀的完整key
     * @param array $fieldValues 字段值对数组
     * @param string|null $id 消息ID，*表示自动生成
     * @param int $maxLen 最大长度，0表示不限制
     * @return string|false 消息ID，失败返回false
     */
    abstract public function __rawXAdd(string $key, array $fieldValues, ?string $id = '*', int $maxLen = 0): string|false;

    /**
     * 读取消息（对应 Redis XREAD 命令）
     * @param string|array $keys key或key数组
     * @param int $count 单次读取的最大消息数，0表示不限制
     * @param int $blockMs 阻塞超时时间（毫秒），0表示非阻塞
     * @return array|false 消息数组，失败返回false
     */
    abstract public function __rawXRead(string|array $keys, int $count = 0, int $blockMs = 0): array|false;

    /**
     * 消费者组操作（对应 Redis XGROUP 命令）
     * @param string $key 带前缀的完整key
     * @param string $group 消费者组名称
     * @param string $startId 起始消息ID，0表示从头开始
     * @param bool $mkStream 是否自动创建流
     * @return bool 操作是否成功
     */
    abstract public function __rawXGroup(string $key, string $group, string $startId = '0', bool $mkStream = false): bool;

    /**
     * 消费者组读取（对应 Redis XREADGROUP 命令）
     * @param string $group 消费者组名称
     * @param string $consumer 消费者名称
     * @param string $key 带前缀的完整key
     * @param int $count 单次读取的最大消息数，0表示不限制
     * @param int $blockMs 阻塞超时时间（毫秒），0表示非阻塞
     * @return array|false 消息数组，失败返回false
     */
    abstract public function __rawXReadGroup(string $group, string $consumer, string $key, int $count = 0, int $blockMs = 0): array|false;

    /**
     * 确认消息（对应 Redis XACK 命令）
     * @param string $key 带前缀的完整key
     * @param string $group 消费者组名称
     * @param array $ids 消息ID数组
     * @return int|false 确认的消息数量，失败返回false
     */
    abstract public function __rawXAck(string $key, string $group, array $ids): int|false;
    
    /**
     * 范围查询流中的消息
     * @param string $key 带前缀的完整key
     * @param string $start 起始ID，-表示最小值
     * @param string $end 结束ID，+表示最大值
     * @param int $count 最大返回消息数，0表示不限制
     * @return array|false 消息数组，失败返回false
     */
    abstract public function __rawXRange(string $key, string $start = '-', string $end = '+', int $count = 0): array|false;
    
    /**
     * 反向范围查询流中的消息
     * @param string $key 带前缀的完整key
     * @param string $start 起始ID，-表示最小值
     * @param string $end 结束ID，+表示最大值
     * @param int $count 最大返回消息数，0表示不限制
     * @return array|false 消息数组，失败返回false
     */
    abstract public function __rawXRevRange(string $key, string $start = '-', string $end = '+', int $count = 0): array|false;
    
}
