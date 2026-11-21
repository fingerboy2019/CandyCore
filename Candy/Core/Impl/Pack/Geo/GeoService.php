<?php
namespace Candy\Core\Impl\Pack\Geo;

/**
 * 地理服务类
 * 处理地理坐标相关的逻辑
 */
class GeoService
{
    /**
     * 地球半径（米）
     */
    const EARTH_RADIUS = 6371000;
    
    /**
     * 对经纬度进行编码，生成 Geohash
     * @param float $lat 纬度
     * @param float $lon 经度
     * @param int $precision 精度
     * @return float Geohash
     */
    public static function geoEncode(float $lat, float $lon, int $precision = 12): float
    {
        $latMin = -90.0;
        $latMax = 90.0;
        $lonMin = -180.0;
        $lonMax = 180.0;
        $hash = 0;
        $isLon = true;
        
        for ($i = 0; $i < $precision * 5; $i++) {
            $hash <<= 1;
            if ($isLon) {
                $mid = ($lonMin + $lonMax) / 2;
                if ($lon > $mid) {
                    $hash |= 1;
                    $lonMin = $mid;
                } else {
                    $lonMax = $mid;
                }
            } else {
                $mid = ($latMin + $latMax) / 2;
                if ($lat > $mid) {
                    $hash |= 1;
                    $latMin = $mid;
                } else {
                    $latMax = $mid;
                }
            }
            $isLon = !$isLon;
        }
        
        return (float)$hash;
    }
    
    /**
     * 计算两个经纬度之间的距离（米）
     * @param float $lon1 经度1
     * @param float $lat1 纬度1
     * @param float $lon2 经度2
     * @param float $lat2 纬度2
     * @return float 距离（米）
     */
    public static function geoHaversine(float $lon1, float $lat1, float $lon2, float $lat2): float
    {
        $lat1Rad = $lat1 * M_PI / 180;
        $lon1Rad = $lon1 * M_PI / 180;
        $lat2Rad = $lat2 * M_PI / 180;
        $lon2Rad = $lon2 * M_PI / 180;
        
        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLon = $lon2Rad - $lon1Rad;
        
        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLon / 2) * sin($deltaLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return self::EARTH_RADIUS * $c;
    }
    
    /**
     * 根据半径计算经纬度范围
     * @param float $lon 中心点经度
     * @param float $lat 中心点纬度
     * @param float $radius 半径（米）
     * @return array [lonMin, lonMax, latMin, latMax]
     */
    public static function calculateBounds(float $lon, float $lat, float $radius): array
    {
        $latRad = $lat * M_PI / 180;
        $latDist = $radius / 111000; // 纬度每度约111公里
        $lonDist = $radius / (111000 * cos($latRad)); // 经度范围随纬度变化
        
        return [
            'lonMin' => $lon - $lonDist,
            'lonMax' => $lon + $lonDist,
            'latMin' => $lat - $latDist,
            'latMax' => $lat + $latDist
        ];
    }
    
    /**
     * 搜索指定半径内的附近成员
     * @param array $zset 有序集合（Geohash => 成员）
     * @param float $lon 中心点经度
     * @param float $lat 中心点纬度
     * @param float $radius 半径（米）
     * @param array $memberData 成员数据（成员 => 经纬度）
     * @return array [成员 => 距离]
     */
    public static function geoRadius(array $zset, float $lon, float $lat, float $radius, array $memberData): array
    {
        // 计算经纬度范围
        $bounds = self::calculateBounds($lon, $lat, $radius);
        
        // 计算经纬度范围的 Geohash 边界
        $minHash = self::geoEncode($bounds['latMin'], $bounds['lonMin']);
        $maxHash = self::geoEncode($bounds['latMax'], $bounds['lonMax']);
        
        // 筛选 Geohash 在范围内的成员（大幅减少遍历数量）
        $candidates = [];
        foreach ($zset as $member => $score) {
            if ($score >= $minHash && $score <= $maxHash) {
                $candidates[$member] = $score;
            }
        }
        
        // 精确计算距离并过滤
        $results = [];
        foreach ($candidates as $member => $score) {
            if (isset($memberData[$member])) {
                $memberPos = $memberData[$member];
                $distance = self::geoHaversine($lon, $lat, $memberPos['lon'], $memberPos['lat']);
                if ($distance <= $radius) {
                    $results[$member] = $distance;
                }
            }
        }
        
        // 按距离排序
        asort($results);
        
        return $results;
    }
    
    /**
     * 解码 Geohash 为经纬度
     * @param float $hash Geohash
     * @return array [经度, 纬度]
     */
    public static function geoDecode(float $hash): array
    {
        $binary = decbin((int)$hash);
        
        $latMin = -90.0;
        $latMax = 90.0;
        $lonMin = -180.0;
        $lonMax = 180.0;
        $isLon = true;
        
        for ($i = 0; $i < strlen($binary); $i++) {
            $bit = $binary[$i];
            if ($isLon) {
                $mid = ($lonMin + $lonMax) / 2;
                if ($bit == '1') {
                    $lonMin = $mid;
                } else {
                    $lonMax = $mid;
                }
            } else {
                $mid = ($latMin + $latMax) / 2;
                if ($bit == '1') {
                    $latMin = $mid;
                } else {
                    $latMax = $mid;
                }
            }
            $isLon = !$isLon;
        }
        
        // 返回中心点
        $lon = ($lonMin + $lonMax) / 2;
        $lat = ($latMin + $latMax) / 2;
        
        return [$lon, $lat];
    }
}