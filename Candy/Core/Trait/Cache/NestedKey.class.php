<?php
/**
 * 多维键（user.profile.name）读写删包装
 *
 * @package Candy\Core\Cache\Trait
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache;

trait NestedKey
{
    /**
     * 多维键写入：委托主键递归写入
     *
     * @return bool|null 多维键已处理时返回结果，单键时返回 null 继续后续逻辑
     */
    protected function dispatchNestedRawSet(string $key, mixed $value, int $ttl = 0): ?bool
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths === null) {
            return null;
        }

        $arr = $this->__rawGet($mainKey) ?? [];
        if (!is_array($arr)) {
            $arr = [];
        }
        $this->setNestedValue($arr, $paths, $value);

        return $this->__rawSet($mainKey, $arr, $ttl);
    }

    /**
     * 多维键读取
     *
     * @return array{value: mixed}|null 多维键已处理时返回 ['value' => ...]，单键时返回 null
     */
    protected function dispatchNestedRawGet(string $key, mixed $default = null): ?array
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths === null) {
            return null;
        }

        $data = $this->__rawGet($mainKey);
        if ($data === null || !is_array($data)) {
            return ['value' => $default];
        }

        $val = $this->getNestedValue($data, $paths);

        return ['value' => $val ?? $default];
    }

    /**
     * 多维键删除：从主键数组中移除嵌套项后写回
     *
     * @return bool|null 多维键已处理时返回结果，单键时返回 null
     */
    protected function dispatchNestedRawDelete(string $key): ?bool
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths === null) {
            return null;
        }

        $arr = $this->__rawGet($mainKey);
        if (!is_array($arr)) {
            return true;
        }

        $result = $this->unsetNestedInArray($arr, $paths);
        if (!$result['changed']) {
            return true;
        }

        return $this->__rawSet($mainKey, $result['data']);
    }

    /**
     * 内联写入：将嵌套值合并进父数据（避免递归加锁，供 File / Shmop）
     *
     * @param callable(string): mixed $loadParent 加载主键现有值
     * @return array{0: string, 1: mixed} [主键, 合并后的写入值]
     */
    protected function mergeNestedIntoValue(string $key, mixed $value, callable $loadParent): array
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths === null) {
            return [$key, $value];
        }

        $data = $loadParent($mainKey);
        is_array($data) OR $data = [];
        $this->setNestedValue($data, $paths, $value);

        return [$mainKey, $data];
    }

    /**
     * 从已加载数据中取出嵌套路径的值
     */
    protected function extractNestedValue(mixed $data, ?array $paths, mixed $default = null): mixed
    {
        if ($paths === null) {
            return $data;
        }
        if (!is_array($data)) {
            return $default;
        }

        $val = $this->getNestedValue($data, $paths);

        return $val ?? $default;
    }

    /**
     * 从数组中删除嵌套路径对应的键
     *
     * @return array{data: array, changed: bool}
     */
    protected function unsetNestedInArray(array $data, array $paths): array
    {
        $paths = [...$paths];
        $last = array_pop($paths);
        $curr = &$data;

        foreach ($paths as $p) {
            if (!isset($curr[$p]) || !is_array($curr[$p])) {
                return ['data' => $data, 'changed' => false];
            }
            $curr = &$curr[$p];
        }

        if (!isset($curr[$last])) {
            return ['data' => $data, 'changed' => false];
        }

        unset($curr[$last]);

        return ['data' => $data, 'changed' => true];
    }
}
