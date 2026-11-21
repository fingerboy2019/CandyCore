<?php
/**
 * 缓存公共辅助 Trait
 *
 * 统一配置前缀、租户作用域键段与嵌套键解析；业务键随 TENANT_ID 自动隔离
 *
 * @package Candy\Core\Trait\Cache
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-22
 */

declare(strict_types=1);
namespace Candy\Core\Trait\Cache;

trait Helper {
    
    /**
     * 配置级缓存前缀（全驱动统一；不含租户段）
     * @var string
     */
    protected string $cachePrefix = '';

    /**
     * 共享键逻辑标记：键以该字符开头则不注入租户段（物理键会去掉该标记）
     *
     * 用于 G()、跨租户配置等必须落在默认命名空间的框架键
     */
    protected const SHARED_KEY_MARK = '@';

    /**
     * 租户作用域键段（有 TENANT_ID 时为 t/{id}/，无则空串回退默认空间）
     *
     * 与 Bootstrap::tenantPathSegment() 同源，保证 Cache / 上传 / 向量路径段一致
     *
     * @return string
     */
    protected function tenantScopeSegment(): string
    {
        return \Candy\Core\Bootstrap::tenantPathSegment();
    }

    /**
     * 当前请求下的作用域前缀（配置前缀 + 租户段）
     *
     * clear() 等按前缀扫描时使用本方法，仅清理当前租户（无租户时等同配置前缀）
     *
     * @return string
     */
    protected function scopePrefix(): string
    {
        $tenantSegment = $this->tenantScopeSegment();
        if ($tenantSegment !== '' && property_exists($this, 'clusterHashTag') && $this->clusterHashTag) {
            $tenantId = trim(substr($tenantSegment, 2), '/');
            return $this->cachePrefix . 't/{' . $tenantId . '}:';
        }
        return $this->cachePrefix . $tenantSegment;
    }

    /**
     * 是否为平台共享逻辑键（以 @ 开头）
     *
     * @param string $key 业务传入的逻辑键
     * @return bool
     */
    protected function isSharedCacheKey(string $key): bool
    {
        return str_starts_with($key, self::SHARED_KEY_MARK);
    }

    /**
     * 逻辑键 → 驱动物理键（配置前缀 + 租户段或共享空间）
     *
     * - 无 TENANT_ID：`{cachePrefix}{key}`（默认/平台）
     * - 有 TENANT_ID：`{cachePrefix}t/{tenantId}/{key}`
     * - 逻辑键以 `@` 开头：强制默认空间 `{cachePrefix}{key去掉@}`，跨租户共享
     *
     * @param string $key 原始逻辑键名
     * @return string 带前缀的完整键名
     */
    protected function prefix(string $key): string
    {
        if ($this->isSharedCacheKey($key)) {
            return $this->cachePrefix . substr($key, strlen(self::SHARED_KEY_MARK));
        }
        return $this->scopePrefix() . $key;
    }

    /**
     * 物理键 → 逻辑键（供限流等先拼物理键再回调公共 API 的场景）
     *
     * @param string $prefixedKey 已含配置前缀（及可能的租户段）的键
     * @return string 逻辑键；共享键会还原为 @ 前缀形式
     */
    protected function unprefix(string $prefixedKey): string
    {
        $scope = $this->scopePrefix();
        if ($scope !== '' && str_starts_with($prefixedKey, $scope)) {
            return substr($prefixedKey, strlen($scope));
        }
        if ($this->cachePrefix !== '' && str_starts_with($prefixedKey, $this->cachePrefix)) {
            return self::SHARED_KEY_MARK . substr($prefixedKey, strlen($this->cachePrefix));
        }
        return $prefixedKey;
    }

    /**
     * 解析多维键
     * @param string $key 键名
     * @return array [主键, 路径数组]
     */
    protected function parseNestedKey(string $key): array {
        if (!str_contains($key, '.')) {
            return [$key, null];
        }
        $parts = explode('.', $key, 2);
        return [$parts[0], explode('.', $parts[1])];
    }

    /**
     * 获取嵌套值
     * @param mixed $data 数据
     * @param array $paths 路径数组
     * @return mixed
     */
    protected function getNestedValue(mixed $data, array $paths): mixed {
        foreach ($paths as $p) {
            if (is_array($data) && array_key_exists($p, $data)) {
                $data = $data[$p];
            } else {
                return null;
            }
        }
        return $data;
    }

    /**
     * 设置嵌套值
     * @param array &$data 数据
     * @param array $paths 路径数组
     * @param mixed $val 值
     */
    protected function setNestedValue(array &$data, array $paths, mixed $val): void {
        $last = array_pop($paths);
        $curr = &$data;
        foreach ($paths as $p) {
            if (!isset($curr[$p]) || !is_array($curr[$p])) {
                $curr[$p] = [];
            }
            $curr = &$curr[$p];
        }
        $curr[$last] = $val;
    }

    /**
     * 验证向量是否为合法数值数组
     * @param mixed $vector
     * @return bool
     */
    protected function isValidVector(mixed $vector): bool
    {
        if (!is_array($vector)) return false;
        foreach ($vector as $val) {
            if (!is_numeric($val)) return false;
        }
        return true;
    }

    /**
     * 验证两个向量长度是否一致
     * @param array $v1
     * @param array $v2
     * @return bool
     */
    protected function isVectorLengthMatch(array $v1, array $v2): bool
    {
        return count($v1) === count($v2);
    }
    
    /**
     * 获取锁
     * @param string $lockName 锁名称
     * @param int|null $timeout 超时时间，null使用默认值
     * @return bool
     */
    abstract public function __acquireLock(string $lockName, ?int $timeout = null): bool;

    /**
     * 释放锁
     * @param string $lockName 锁名称
     * @return bool
     */
    abstract public function __releaseLock(string $lockName): bool;
}
