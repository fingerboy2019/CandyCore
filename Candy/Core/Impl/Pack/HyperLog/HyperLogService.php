<?php
namespace Candy\Core\Impl\Pack\HyperLog;

/**
 * HyperLog 服务类
 * 处理 HyperLog 相关的逻辑
 */
class HyperLogService
{
    /**
     * 内部哈希函数
     * @param string $str 输入字符串
     * @return int 哈希值
     */
    public static function hllHash(string $str): int
    {
        // 检查是否支持 murmur3a 哈希函数
        if (in_array('murmur3a', hash_algos())) {
            return hexdec(substr(hash('murmur3a', $str), 0, 16));
        } else {
            // 使用 CRC32 优化版哈希算法作为替代
            return self::optimizedCrc32Hash($str);
        }
    }
    
    /**
     * CRC32 优化版哈希算法
     * @param string $str 输入字符串
     * @return int 哈希值
     */
    public static function optimizedCrc32Hash(string $str): int
    {
        $fullCrc = crc32($str);
        $len = strlen($str);
        $prefix = substr($str, 0, min(10, $len));
        $suffix = substr($str, max(0, $len - 10), 10);
        $partialCrc = crc32($prefix . $suffix);
        
        // 组合两个 CRC32 值
        if (PHP_INT_SIZE === 8) {
            // 64 位环境：直接使用位运算
            return ($fullCrc << 32) | $partialCrc;
        } else {
            // 32 位环境：使用字符串拼接后转换为整数
            $hashStr = str_pad(dechex($fullCrc), 8, '0', STR_PAD_LEFT) . str_pad(dechex($partialCrc), 8, '0', STR_PAD_LEFT);
            return hexdec($hashStr);
        }
    }
    
    /**
     * 计算二进制中前导零的个数
     * @param int $hash 哈希值
     * @return int 前导零的个数
     */
    private static function countLeadingZeros(int $hash): int
    {
        if ($hash === 0) return 32;
        $count = 0;
        while (($hash & 0x80000000) === 0) {
            $count++;
            $hash <<= 1;
        }
        return $count;
    }
    
    /**
     * 向 HyperLogLog 添加元素
     * @param array $hll HyperLogLog 结构
     * @param string $element 元素
     * @return array 更新后的 HyperLogLog 结构
     */
    public static function hLogAdd(array $hll, string $element): array
    {
        $hash = self::hllHash($element);
        $index = $hash & 0x1F; // 取低 5 位作为桶索引
        $remainder = $hash >> 5;
        $zeros = self::countLeadingZeros($remainder) + 1;
        
        if ($zeros > $hll[$index]) {
            $hll[$index] = $zeros;
        }
        
        return $hll;
    }
    
    /**
     * 合并多个 HyperLogLog
     * @param array ...$hlls HyperLogLog 结构
     * @return array 合并后的 HyperLogLog 结构
     */
    public static function hLogMerge(array ...$hlls): array
    {
        $merged = array_fill(0, 32, 0);
        
        foreach ($hlls as $hll) {
            for ($i = 0; $i < 32; $i++) {
                if (isset($hll[$i]) && $hll[$i] > $merged[$i]) {
                    $merged[$i] = $hll[$i];
                }
            }
        }
        
        return $merged;
    }
    
    /**
     * 估计 HyperLogLog 中的基数
     * @param array $hll HyperLogLog 结构
     * @return int 估计的基数
     */
    public static function hLogCount(array $hll): int
    {
        $m = 32; // 桶数
        $sum = 0;
        
        for ($i = 0; $i < $m; $i++) {
            $sum += 1.0 / (1 << $hll[$i]);
        }
        
        $estimate = 0.7213 / (1 + 1.079 / $m) * $m * $m / $sum;
        
        // 小基数修正
        if ($estimate <= 5 * $m) {
            $zeros = 0;
            for ($i = 0; $i < $m; $i++) {
                if ($hll[$i] === 0) {
                    $zeros++;
                }
            }
            if ($zeros > 0) {
                $estimate = $m * log($m / $zeros);
            }
        }
        
        return (int)round($estimate);
    }
}