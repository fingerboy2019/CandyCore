<?php
namespace Beanbun\Lib;

use InvalidArgumentException;
use SplFixedArray;

/**
 * 布隆过滤器类
 * 
 * 用于高效地判断一个元素是否在一个集合中，具有低空间复杂度，但存在一定的误判率
 */
class BloomFilter
{
    /**
     * 位字段，用于存储数据
     * @var string
     */
    protected $bitField = '';
    
    /**
     * 位字段大小
     * @var int
     */
    protected $m;
    
    /**
     * 哈希函数数量
     * @var int
     */
    protected $k;

    /**
     * 构造函数
     * 
     * @param int $m 位字段大小。实际使用的内存将是 $m/8 字节
     * @param int $k 哈希函数数量
     * @throws InvalidArgumentException 当 $m 或 $k 不是数字时抛出异常
     */
    public function __construct($m, $k)
    {
        if (!is_numeric($m) || !is_numeric($k)) {
            throw new InvalidArgumentException('$m and $k must be integers');
        }
        $this->bitField = $this->initializeBitFieldOfLength($m);
        $this->m = (int) $m;
        $this->k = (int) $k;
    }

    /**
     * 计算给定 m 和典型存储项目数时的最佳 k 值
     *
     * @param int $m 位字段大小
     * @param int $n 典型的插入项目数
     * @return int 最佳的 k 值
     */
    public static function getK($m, $n)
    {
        return ceil(($m / $n) * log(2));
    }

    /**
     * 根据位字段大小和预期存储项目数返回实例
     * 自动计算 k 值
     *
     * @param int $m 位字段大小
     * @param int $n 预期存储的项目数
     * @return BloomFilter 布隆过滤器实例
     */
    public static function constructForTypicalSize($m, $n)
    {
        return new self($m, self::getK($m, $n));
    }

    /**
     * 从 __toString 方法生成的 ASCII 安全字符串表示中反序列化实例
     *
     * @param string $string 字符串表示
     * @return BloomFilter 反序列化的实例
     * @throws InvalidArgumentException 当字符串表示无效时抛出异常
     */
    public static function unserializeFromStringRepresentation($string)
    {
        if (!preg_match('~k:(?P<k>\d+)/m:(?P<m>\d+)\((?P<bitfield>[0-9a-zA-Z+/=]+)\)~', $string, $matches)) {
            throw new InvalidArgumentException('Invalid string representation');
        }
        $bf = new self((int) $matches['m'], (int) $matches['k']);
        $bf->bitField = base64_decode($matches['bitfield']);
        return $bf;
    }
    /**
     * 初始化指定长度的位字段
     * 
     * @param int $length 位字段长度
     * @return string 初始化后的位字段
     */
    protected function initializeBitFieldOfLength($length)
    {
        return str_repeat("\x00", ceil($length / 8));
    }

    /**
     * 设置指定位置的位
     * 
     * @param int $pos 位置
     * @return void
     */
    protected function setBitAtPosition($pos)
    {
        [$char, $byte] = $this->position2CharAndByte($pos);
        $this->bitField[$char] = $this->bitField[$char] | $byte;
    }

    /**
     * 获取指定位置的位值
     * 
     * @param int $pos 位置
     * @return bool 位值
     */
    protected function getBitAtPosition($pos)
    {
        [$char, $byte] = $this->position2CharAndByte($pos);
        return ($this->bitField[$char] & $byte) === $byte;
    }

    /**
     * 返回一个元组，包含位字段字符串中的字符偏移量（索引0）和特定位置的位掩码（索引1）
     * 例如：位置9 -> (1, "10000000")（第二个字节，"第一个"位）
     *
     * @param int $pos 位字段中的第 $pos 位
     * @return array 包含字符偏移量和位掩码的数组
     * @throws InvalidArgumentException 当位置超出位字段长度时抛出异常
     */
    protected function position2CharAndByte($pos)
    {
        if ($pos > $this->m) {
            throw new InvalidArgumentException("\$pos of $pos beyond bitfield length of $this->m");
        }
        static $positionMap = [
            8 => "\x01",
            7 => "\x02",
            6 => "\x04",
            5 => "\x08",
            4 => "\x10",
            3 => "\x20",
            2 => "\x40",
            1 => "\x80",
        ];

        $char = (int) ceil($pos / 8) - 1;
        $byte = $positionMap[$pos % 8 ?: 8];
        return [$char, $byte];
    }

    /**
     * 计算值在位字段中哈希到的位置
     *
     * @param string $value 要插入到位字段中的值
     * @return SplFixedArray 包含位字段中数值位置的数组
     */
    protected function positions($value)
    {
        mt_srand(crc32($value));
        $positions = new SplFixedArray($this->k);
        for ($i = 0; $i < $this->k; $i++) {
            $positions[$i] = mt_rand(1, $this->m);
        }
        return $positions;
    }

    /**
     * 将值添加到集合中
     *
     * @param string $value 要添加的值
     * @return void
     */
    public function add($value)
    {
        foreach ($this->positions($value) as $position) {
            $this->setBitAtPosition($position);
        }
    }

    /**
     * 检查值是否可能已被添加到集合中
     * 可能存在误判，但不存在漏判
     *
     * @param string $value 要检查的值
     * @return boolean 是否可能在集合中
     */
    public function maybeInSet($value)
    {
        foreach ($this->positions($value) as $position) {
            if (!$this->getBitAtPosition($position)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 返回当前位字段的 ASCII 表示
     *
     * @return string 位字段的 ASCII 表示
     */
    public function showBitField()
    {
        return join(array_map(function ($chr) {return str_pad(base_convert(bin2hex($chr), 16, 2), 8, '0', STR_PAD_LEFT);}, str_split($this->bitField)));
    }

    /**
     * 返回布隆过滤器对象的 ASCII 安全表示
     * 此表示可以使用 unserializeFromStringRepresentation() 方法反序列化
     *
     * @return string 布隆过滤器的 ASCII 安全表示
     */
    public function __toString()
    {
        return "k:$this->k/m:$this->m(" . base64_encode($this->bitField) . ')';
    }

}
