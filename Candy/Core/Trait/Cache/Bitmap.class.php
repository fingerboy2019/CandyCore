<?php
/**
 * 位图操作 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */
declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait Bitmap {
    /**
     * 位图操作（统一接口，支持所有位图操作）
     * @param string $key 原始key（自动加前缀）
     *                    - rw/count模式：位图key
     *                    - op模式：逗号分隔的多个源key（NOT操作时只需一个key）
     * @param int|array $id 位图索引或区间数组
     *                      - rw模式：单个偏移量或多个偏移量数组 [offset1, offset2, ...]
     *                      - count/op/pos模式：区间数组 [start, end]，end=-1表示到最后
     * @param mixed $options 操作选项：
     *                       - rw模式（默认）：null=查询，true=置1，false=置0（向后兼容）
     *                       - 字符串：'rw'=读写，'count'=统计，'op'=位图运算，'pos'=查找位
     *                       - 数组（op模式）：['operation'=>'AND|OR|XOR|NOT', 'destKey'=>'目标key']
     *                       - 数组（pos模式）：['value'=>0或1] 查找第一个置位/复位的位
     * @param int $expire 过期时间（秒），仅写操作/op模式有效
     * @return array|int|bool 
     *         - rw查询：返回0/1或数组
     *         - rw写入：返回bool
     *         - count：返回置位数量
     *         - op：返回结果位图长度
     *         - pos：返回第一个匹配位的位置，未找到返回-1
     */
    public function bit(string $key, int|array $id, mixed $options = null, int $expire = 0): array|int|bool
    {
        //事务支持：写操作进入事务队列
        if ($this->inTransaction) {
            $isWriteOp = (is_bool($options) && $options !== null) || 
                         (is_string($options) && in_array(strtolower($options), ['write', 'op'])) ||
                         (is_array($options) && isset($options['operation']));
            if ($isWriteOp) {
                $this->queueTransaction(__METHOD__, func_get_args());
                return true;
            }
        }
        
        return $this->__rawBit($this->prefix($key), $id, $options, $expire);
    }

    /**
     * 位图置位统计
     * @param string $key 原始key（自动加前缀）
     * @param int|array $id 位图索引或区间数组
     * @return int|bool 置位数量
     */
    public function bitCount(string $key, int|array $id): int|bool
    {
        return $this->bit($key, $id, 'count');
    }

    /**
     * 位图运算
     * @param string $key 原始key（自动加前缀）
     * @param int|array $id 位图索引或区间数组
     * @param array $options 操作选项：
     *                       - operation：AND|OR|XOR|NOT
     *                       - destKey：目标key（NOT操作时无需）
     * @return int|bool 结果位图长度
     */
    public function bitOp(string $key, int|array $id, array $options): int|bool
     {
        return $this->bit($key, $id, $options);
    }

    /**
     * 位图查找位
     * @param string $key 原始key（自动加前缀）
     * @param int|array $id 位图索引或区间数组
     * @param array $options 操作选项：
     *                       - value：0或1
     * @return int|bool 第一个匹配位的位置，未找到返回-1
     */
    public function bitPos(string $key, int|array $id, array $options): int|bool
     {
        return $this->bit($key, $id, $options);
    }
    
    /**
     * 位图操作（抽象方法，驱动层实现）
     * @param string $key 位图key
     * @param int|array $id 偏移量或区间数组
     * @param mixed $options 操作选项
     * @param int $expire 过期时间
     * @return array|int|bool
     */
    abstract public function __rawBit(string $key, int|array $id, mixed $options = null, int $expire = 0): array|int|bool;
}
