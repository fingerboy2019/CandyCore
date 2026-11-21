<?php
namespace Candy\Core\Impl\Pack\CuckooFilter;

/**
 * 布谷鸟过滤器服务类
 * 处理布谷鸟过滤器相关的逻辑
 * 相比布隆过滤器，布谷鸟过滤器支持删除操作，且具有更好的空间效率
 */
class CuckooFilterService
{
    /**
     * 内部哈希函数
     * @param string $str 输入字符串
     * @param int $seed 哈希种子
     * @return int 哈希值
     */
    private static function cFilterHash(string $str, int $seed): int
    {
        // 检查是否支持 murmur3a 哈希函数
        if (in_array('murmur3a', hash_algos())) {
            $hash = hash("murmur3a", $str . $seed);
            return abs(hexdec(substr($hash, 0, 16)));
        } else {
            // 使用 CRC32 优化版哈希算法作为替代
            return abs(self::optimizedCrc32Hash($str . $seed));
        }
    }
    
    /**
     * CRC32 优化版哈希算法
     * @param string $str 输入字符串
     * @return int 哈希值
     */
    private static function optimizedCrc32Hash(string $str): int
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
     * 计算布谷鸟过滤器的最优大小
     * @param int $n 预期元素数量
     * @param float $loadFactor 负载因子
     * @return int 布谷鸟过滤器桶数量
     */
    public static function calculateSize(int $n, float $loadFactor = 0.9): int
    {
        // 布谷鸟过滤器的桶数量
        return (int)ceil($n / $loadFactor);
    }
    
    /**
     * 初始化布谷鸟过滤器
     * @param int $size 布谷鸟过滤器桶数量
     * @param int $bucketSize 每个桶的条目数
     * @return array 布谷鸟过滤器结构
     */
    public static function cFilterInit(int $size, int $bucketSize = 4): array
    {
        return [
            'buckets' => array_fill(0, $size, []),
            'size' => $size,
            'bucket_size' => $bucketSize,
            'count' => 0
        ];
    }
    
    /**
     * 计算两个哈希值
     * @param string $element 元素
     * @param int $size 过滤器大小
     * @return array 两个哈希值
     */
    private static function getTwoHashes(string $element, int $size): array
    {
        $h1 = self::cFilterHash($element, 0) % $size;
        $h2 = (self::cFilterHash($element, 1) % $size) ^ $h1;
        return [$h1, $h2];
    }
    
    /**
     * 向布谷鸟过滤器添加元素
     * @param array $filter 布谷鸟过滤器结构
     * @param string $element 元素
     * @param int $maxTries 最大尝试次数
     * @return array|null 更新后的布谷过滤器结构，失败返回null
     */
    public static function cFilterAdd(array $filter, string $element, int $maxTries = 500): ?array
    {
        $buckets = $filter['buckets'];
        $size = $filter['size'];
        $bucketSize = $filter['bucket_size'];
        $count = $filter['count'];
        
        // 计算两个哈希值
        [$h1, $h2] = self::getTwoHashes($element, $size);
        
        // 检查元素是否已存在
        if (self::cFilterExists($filter, $element)) {
            return $filter;
        }
        
        // 尝试将元素添加到第一个桶
        if (count($buckets[$h1]) < $bucketSize) {
            $buckets[$h1][] = $element;
            $filter['buckets'] = $buckets;
            $filter['count'] = $count + 1;
            return $filter;
        }
        
        // 尝试将元素添加到第二个桶
        if (count($buckets[$h2]) < $bucketSize) {
            $buckets[$h2][] = $element;
            $filter['buckets'] = $buckets;
            $filter['count'] = $count + 1;
            return $filter;
        }
        
        // 两个桶都满了，开始驱逐
        $currentHash = $h1;
        $currentElement = $element;
        
        for ($i = 0; $i < $maxTries; $i++) {
            // 随机选择一个位置驱逐
            $pos = rand(0, $bucketSize - 1);
            $evictedElement = $buckets[$currentHash][$pos];
            $buckets[$currentHash][$pos] = $currentElement;
            
            // 计算被驱逐元素的另一个哈希位置
            [$h1_evict, $h2_evict] = self::getTwoHashes($evictedElement, $size);
            $otherHash = $currentHash === $h1_evict ? $h2_evict : $h1_evict;
            
            // 尝试将被驱逐元素添加到另一个桶
            if (count($buckets[$otherHash]) < $bucketSize) {
                $buckets[$otherHash][] = $evictedElement;
                $filter['buckets'] = $buckets;
                $filter['count'] = $count + 1;
                return $filter;
            }
            
            // 另一个桶也满了，继续驱逐
            $currentHash = $otherHash;
            $currentElement = $evictedElement;
        }
        
        // 达到最大尝试次数，添加失败
        return null;
    }
    
    /**
     * 从布谷鸟过滤器删除元素
     * @param array $filter 布谷鸟过滤器结构
     * @param string $element 元素
     * @return array 更新后的布谷过滤器结构
     */
    public static function cFilterDelete(array $filter, string $element): array
    {
        $buckets = $filter['buckets'];
        $size = $filter['size'];
        $count = $filter['count'];
        
        // 计算两个哈希值
        [$h1, $h2] = self::getTwoHashes($element, $size);
        
        // 检查并删除第一个桶中的元素
        foreach ($buckets[$h1] as $key => $bucketElement) {
            if ($bucketElement === $element) {
                unset($buckets[$h1][$key]);
                $buckets[$h1] = array_values($buckets[$h1]); // 重新索引
                $filter['buckets'] = $buckets;
                $filter['count'] = max(0, $count - 1);
                return $filter;
            }
        }
        
        // 检查并删除第二个桶中的元素
        foreach ($buckets[$h2] as $key => $bucketElement) {
            if ($bucketElement === $element) {
                unset($buckets[$h2][$key]);
                $buckets[$h2] = array_values($buckets[$h2]); // 重新索引
                $filter['buckets'] = $buckets;
                $filter['count'] = max(0, $count - 1);
                return $filter;
            }
        }
        
        // 元素不存在，返回原过滤器
        return $filter;
    }
    
    /**
     * 检查元素是否在布谷鸟过滤器中
     * @param array $filter 布谷鸟过滤器结构
     * @param string $element 元素
     * @return bool 是否存在
     */
    public static function cFilterExists(array $filter, string $element): bool
    {
        $buckets = $filter['buckets'];
        $size = $filter['size'];
        
        // 计算两个哈希值
        [$h1, $h2] = self::getTwoHashes($element, $size);
        
        // 检查第一个桶
        foreach ($buckets[$h1] as $bucketElement) {
            if ($bucketElement === $element) {
                return true;
            }
        }
        
        // 检查第二个桶
        foreach ($buckets[$h2] as $bucketElement) {
            if ($bucketElement === $element) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 清空布谷鸟过滤器
     * @param array $filter 布谷鸟过滤器结构
     * @return array 清空后的布谷鸟过滤器结构
     */
    public static function cFilterClear(array $filter): array
    {
        $filter['buckets'] = array_fill(0, $filter['size'], []);
        $filter['count'] = 0;
        return $filter;
    }
    
    /**
     * 获取布谷鸟过滤器中的元素数量
     * @param array $filter 布谷鸟过滤器结构
     * @return int 元素数量
     */
    public static function cFilterCount(array $filter): int
    {
        return $filter['count'];
    }
}
