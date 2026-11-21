<?php
/**
 * 地理位置服务 Trait
 * 
 * @package Candy\Core\Cache\Trait 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

use Candy\Core\Impl\Pack\Geo\GeoService;

trait Geo {
    // Geo 哈希位数（默认50，可配置调整精度）
    protected const GEO_HASH_BITS = 50;

    /**
     * 初始化 Geo 事务方法
     * 使用 registerMethods 批量注册以减少函数调用
     */
    protected function initGeoTransactionMethods(): void
    {
        // 使用 registerMethods 批量注册 Geo 操作方法
        // 格式: ["短方法名", "原始方法名", "结果处理器/null"]
        $this->registerMethods([
            ["geoAdd", "__rawSet", null],
            ["geoDist", "__rawGet", [$this, "handleGeoDistResult"]],
            ["geoHash", "__rawGet", [$this, "handleGeoHashResult"]],
            ["geoPos", "__rawGet", [$this, "handleGeoPosResult"]],
            ["geoRadius", "__rawGet", fn($r) => is_array($r) ? $r : []],
            ["geoRadiusByMember", "__rawGet", fn($r) => is_array($r) ? $r : []],
        ]);
    }

    /**
     * 添加/更新地理坐标
     * @param string $key 集合键
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @param string $member 成员名（用户ID/门店ID）
     * @return bool
     */
    public function geoAdd(string $key, float $longitude, float $latitude, string $member): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $geohash = GeoService::geoEncode($latitude, $longitude);
        // 存储额外信息，包括经纬度
        $memberData = [
            'geohash' => $geohash,
            'lon' => $longitude,
            'lat' => $latitude
        ];
        
        // 加锁后操作
        $keyWithPrefix = $this->prefix($key);
        $memberKeyWithPrefix = $this->prefix($key . ':member:' . $member);
        
        // 按字典序获取锁，避免死锁
        $lock1 = min($keyWithPrefix, $memberKeyWithPrefix);
        $lock2 = max($keyWithPrefix, $memberKeyWithPrefix);
        
        try {
            $this->lock($lock1);
            $this->lock($lock2);
            
            // 获取当前成员集合
            $members = $this->__rawGet($keyWithPrefix) ?: [];
            if (!is_array($members)) {
                $members = [];
            }
            
            // 添加/更新成员
            $members[$member] = $memberData;
            
            // 存储集合和成员信息
            $this->__rawSet($keyWithPrefix, $members);
            $this->__rawSet($memberKeyWithPrefix, $memberData);
            
            return true;
        } finally {
            $this->unlock($lock1);
            $this->unlock($lock2);
        }
    }

    /**
     * 计算两个成员之间的距离
     * @param string $key 集合键
     * @param string $member1 成员1
     * @param string $member2 成员2
     * @param string $unit 单位（m/km/mi/ft）
     * @return float|null
     */
    public function geoDist(string $key, string $member1, string $member2, string $unit = 'm'): ?float
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        
        $member1Data = $this->__rawGet($this->prefix($key . ':member:' . $member1));
        $member2Data = $this->__rawGet($this->prefix($key . ':member:' . $member2));
        
        if (!is_array($member1Data) || !is_array($member2Data)) {
            return null;
        }
        
        // 使用 geoHaversine 计算距离（返回米）
        $distance = GeoService::geoHaversine(
            $member1Data['lon'], $member1Data['lat'],
            $member2Data['lon'], $member2Data['lat']
        );
        
        // 单位转换
        return $this->convertDistance($distance, $unit);
    }
    
    /**
     * 距离单位转换
     * @param float $meters 距离（米）
     * @param string $unit 目标单位（m/km/mi/ft）
     * @return float
     */
    private function convertDistance(float $meters, string $unit): float
    {
        return match (strtolower($unit)) {
            'km' => $meters / 1000,           // 公里
            'mi' => $meters * 0.000621371,   // 英里
            'ft' => $meters * 3.28084,       // 英尺
            default => $meters,               // 米（默认）
        };
    }

    /**
     * 获取成员的 geohash 值
     * @param string $key 集合键
     * @param string ...$members 成员名列表
     * @return array
     */
    public function geoHash(string $key, string ...$members): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        
        $result = [];
        foreach ($members as $member) {
            $memberData = $this->__rawGet($this->prefix($key . ':member:' . $member));
            $result[$member] = is_array($memberData) ? $memberData['geohash'] ?? null : null;
        }
        return $result;
    }

    /**
     * 获取成员的坐标
     * @param string $key 集合键
     * @param string ...$members 成员名列表
     * @return array
     */
    public function geoPos(string $key, string ...$members): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        
        $result = [];
        foreach ($members as $member) {
            $memberData = $this->__rawGet($this->prefix($key . ':member:' . $member));
            if (is_array($memberData)) {
                $result[$member] = [$memberData['lon'] ?? 0, $memberData['lat'] ?? 0];
            } else {
                $result[$member] = null;
            }
        }
        return $result;
    }

    /**
     * 根据坐标范围查询成员
     * @param string $key 集合键
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @param float $radius 半径
     * @param string $unit 单位（m/km/mi/ft）
     * @param array $options 选项
     * @return array
     */
    public function geoRadius(string $key, float $longitude, float $latitude, float $radius, string $unit = 'm', array $options = []): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        
        $members = $this->__rawGet($this->prefix($key));
        if (!is_array($members)) {
            return [];
        }
        
        // 将半径转换为米
        $radiusInMeters = $this->convertToMeters($radius, $unit);
        
        // 构建成员数据（成员 => 经纬度）
        $memberData = [];
        foreach ($members as $member => $_) {
            $memberInfo = $this->__rawGet($this->prefix($key . ':member:' . $member));
            if (is_array($memberInfo) && isset($memberInfo['lon'], $memberInfo['lat'])) {
                $memberData[$member] = $memberInfo;
            }
        }
        
        // 使用 GeoService::geoRadius 搜索
        $results = GeoService::geoRadius($members, $longitude, $latitude, $radiusInMeters, $memberData);
        
        // 根据选项处理结果
        return $this->processRadiusResults($results, $unit, $options);
    }
    
    /**
     * 将距离转换为米
     * @param float $distance 距离
     * @param string $unit 单位（m/km/mi/ft）
     * @return float
     */
    private function convertToMeters(float $distance, string $unit): float
    {
        return match (strtolower($unit)) {
            'km' => $distance * 1000,         // 公里转米
            'mi' => $distance * 1609.34,     // 英里转米
            'ft' => $distance * 0.3048,       // 英尺转米
            default => $distance,              // 米（默认）
        };
    }
    
    /**
     * 处理半径搜索结果
     * @param array $results 原始结果 [成员 => 距离(米)]
     * @param string $unit 目标单位
     * @param array $options 选项
     * @return array
     */
    private function processRadiusResults(array $results, string $unit, array $options): array
    {
        // 如果需要转换单位
        if ($unit !== 'm') {
            foreach ($results as $member => $distance) {
                $results[$member] = $this->convertDistance($distance, $unit);
            }
        }
        
        // 根据选项限制返回数量
        if (isset($options['count'])) {
            $results = array_slice($results, 0, (int)$options['count'], true);
        }
        
        return $results;
    }

    /**
     * 根据成员查询附近其他成员
     * @param string $key 集合键
     * @param string $member 成员名
     * @param float $radius 半径
     * @param string $unit 单位（m/km/mi/ft）
     * @param array $options 选项
     * @return array
     */
    public function geoRadiusByMember(string $key, string $member, float $radius, string $unit = 'm', array $options = []): array
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        
        $memberData = $this->__rawGet($this->prefix($key . ':member:' . $member));
        if (!is_array($memberData)) {
            return [];
        }
        
        return $this->geoRadius($key, $memberData['lon'], $memberData['lat'], $radius, $unit, $options);
    }

    /**
     * 删除成员
     * @param string $key 集合键
     * @param string ...$members 成员名列表
     * @return bool
     */
    public function geoRem(string $key, string ...$members): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        
        $keyWithPrefix = $this->prefix($key);
        
        try {
            $this->lock($keyWithPrefix);
            
            $membersData = $this->__rawGet($keyWithPrefix) ?: [];
            if (!is_array($membersData)) {
                return false;
            }
            
            foreach ($members as $member) {
                unset($membersData[$member]);
                $this->__rawDelete($this->prefix($key . ':member:' . $member));
            }
            
            $this->__rawSet($keyWithPrefix, $membersData);
            return true;
        } finally {
            $this->unlock($keyWithPrefix);
        }
    }
}
