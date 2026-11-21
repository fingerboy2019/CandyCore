<?php
/**
 * Candy框架数组操作工具类
 * 
 * 提供全面的数组操作方法集合，支持数组访问判断、多维数组处理、数组查询、排序、过滤等功能。
 * 该类为final类，禁止被继承。所有方法均为静态方法，可直接通过Arr::方法名()调用。
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-03 20:08:03
 * @final 该类禁止被继承
 */
 
declare(strict_types=1);
namespace Candy\Extend;
use ArrayAccess;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Arr
{

    /**
     * 确定给定值是否可以作为数组访问
     * 
     * 检查一个值是否是数组或者实现了ArrayAccess接口的对象，这些类型可以使用数组语法（如$value[$key]）访问。
     * 这是框架中许多数组操作方法的前置检查，确保操作的目标是可访问的。
     * 
     * @param mixed $value 需要检查的值
     * @return bool 如果值可以作为数组访问则返回true，否则返回false
     * @example
     * ```php
     * Arr::accessible([]); // true
     * Arr::accessible(new ArrayObject); // true
     * Arr::accessible('string'); // false
     * Arr::accessible(null); // false
     * ```
     */
    public static function accessible($value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    /**
     * 给不存在的键位添加值
     * 
     * 检查数组中是否存在指定键名，如果不存在则添加对应的键值对。
     * 该方法不会覆盖已存在的键，只会在键不存在时添加新值。
     * 内部使用Arr::get()方法检查键是否存在，并使用Arr::set()方法设置值。
     * 
     * @param array $array 要操作的数组
     * @param string $key 要检查并可能添加的键名
     * @param mixed $value 当键不存在时要添加的值
     * @return array 返回修改后的数组
     * @example
     * ```php
     * $array = ['name' => 'John'];
     * $result = Arr::add($array, 'age', 25); // ['name' => 'John', 'age' => 25]
     * $result = Arr::add($array, 'name', 'Jane'); // ['name' => 'John'] - 键已存在，不修改
     * ```
     */
    public static function add(array $array,string $key,mixed $value): array
    {
        if (is_null(self::get($array, $key))) {
            self::set($array, $key, $value);
        }

        return $array;
    }

    /**
     * 将多个数组合并为单一数组
     * 
     * 接收一个数组的数组，并将所有子数组合并成一个单一的扁平数组。
     * 该方法能够处理Collection对象，会自动调用其all()方法获取数组数据。
     * 非数组类型的子项会被忽略。
     * 
     * @param array $array 包含多个数组的数组
     * @return array 合并后的单一数组
     * @example
     * ```php
     * $array = [
     *     [1, 2, 3],
     *     [4, 5, 6],
     *     [7, 8, 9]
     * ];
     * $result = Arr::collapse($array); // [1, 2, 3, 4, 5, 6, 7, 8, 9]
     * 
     * // 包含非数组元素的情况
     * $array = [[1, 2], 'not an array', [3, 4]];
     * $result = Arr::collapse($array); // [1, 2, 3, 4] - 非数组元素被忽略
     * ```
     */
    public static function collapse(array $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if ($values instanceof Collection) {
                $values = $values->all();
            } elseif (!is_array($values)) {
                continue;
            }

            $results = array_merge($results, $values);
        }

        return $results;
    }

    /**
     * 创建多个数组的笛卡尔积
     * 
     * 生成多个数组的笛卡尔积，返回所有可能的组合。每个组合是一个数组，包含每个输入数组中的一个元素。
     * 输入数组的顺序在结果中保持不变。
     * 
     * @param array ...$arrays 任意数量的数组参数
     * @return array 笛卡尔积结果数组，每个元素是一个包含各输入数组一个元素的组合
     * @example
     * ```php
     * $result = Arr::crossJoin([1, 2], ['a', 'b']);
     * // 结果：[[1, 'a'], [1, 'b'], [2, 'a'], [2, 'b']]
     * 
     * $result = Arr::crossJoin([1, 2], ['a', 'b'], ['x', 'y']);
     * // 结果：[[1, 'a', 'x'], [1, 'a', 'y'], [1, 'b', 'x'], [1, 'b', 'y'], 
     * //        [2, 'a', 'x'], [2, 'a', 'y'], [2, 'b', 'x'], [2, 'b', 'y']]
     * ```
     */
    public static function crossJoin(array ...$arrays): array
    {
        $results = [[]];

        foreach ($arrays as $index => $array) {
            $append = [];

            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[$index] = $item;

                    $append[] = $product;
                }
            }

            $results = $append;
        }

        return $results;
    }

    /**
     * 将数组分割为索引数组和关联数组
     * 
     * 将一个数组分割为两个数组：一个包含所有的数字索引元素，另一个包含所有的字符串索引元素。
     * 数字索引从0开始且连续的元素会被归类到索引数组中，其余的会被归类到关联数组中。
     * 
     * @param array $array 要分割的数组
     * @return array 包含两个元素的数组，第一个元素是索引数组，第二个元素是关联数组
     * @example
     * ```php
     * $array = [0 => 'a', 1 => 'b', 'c' => 'd', 3 => 'e'];
     * [$indexed, $assoc] = Arr::divide($array);
     * // $indexed = [0 => 'a', 1 => 'b']
     * // $assoc = ['c' => 'd', 3 => 'e'] - 注意：非连续的数字索引(如3)被视为关联数组
     * ```
     */
    public static function divide(array $array): array
    {
        return [array_keys($array), array_values($array)];
    }

    /**
     * 使用点表示法将多维数组转为一维数组
     * 
     * 将一个嵌套的多维数组递归地转换为使用点表示法键的一维数组。
     * 这对于需要使用点表示法访问嵌套数组元素的场景非常有用。
     * 
     * @param array $array 要转换的多维数组
     * @param string $prepend 可选，要添加到所有键之前的字符串前缀
     * @return array 转换后的一维数组，键使用点表示法
     * @example
     * ```php
     * $array = [
     *     'user' => [
     *         'name' => 'John',
     *         'contacts' => [
     *             'email' => 'john@example.com',
     *             'phone' => '123-456-7890'
     *         ]
     *     ]
     * ];
     * $result = Arr::dot($array);
     * // 结果：
     * // [
     * //     'user.name' => 'John',
     * //     'user.contacts.email' => 'john@example.com',
     * //     'user.contacts.phone' => '123-456-7890'
     * // ]
     * 
     * // 添加前缀
     * $result = Arr::dot($array, 'settings.');
     * // 结果：
     * // [
     * //     'settings.user.name' => 'John',
     * //     'settings.user.contacts.email' => 'john@example.com',
     * //     'settings.user.contacts.phone' => '123-456-7890'
     * // ]
     * ```
     */
    public static function dot(array $array,string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $results = array_merge($results, self::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    /**
     * 从数组中排除指定的键值对
     * 
     * 从数组中移除指定的键及其对应的值，返回剩余的键值对。
     * 支持单个键名或多个键名数组作为排除参数。
     * 可以使用点表示法排除嵌套数组中的键值对。
     * 
     * @param array $array 原始数组
     * @param array|string $keys 要排除的键名，可以是单个键名或键名数组
     * @return array 排除指定键后的新数组
     * @example
     * ```php
     * $array = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
     * $result = Arr::except($array, 'a');
     * // 结果: ['b' => 2, 'c' => 3, 'd' => 4]
     * 
     * $result = Arr::except($array, ['a', 'c']);
     * // 结果: ['b' => 2, 'd' => 4]
     * 
     * // 使用点表示法排除嵌套数组中的键
     * $array = [
     *     'user' => [
     *         'name' => 'John',
     *         'email' => 'john@example.com',
     *         'age' => 30
     *     ],
     *     'settings' => ['theme' => 'dark']
     * ];
     * $result = Arr::except($array, 'user.email');
     * // 结果将移除user.email键，保留其他所有键值对
     * ```
     */
    public static function except(array $array,array|string $keys): array
    {
        self::forget($array, $keys);

        return $array;
    }

    /**
     * 检查数组中是否存在指定的键
     * 
     * 检查数组中是否存在指定的键，支持点表示法检查嵌套数组中的键。
     * 与PHP内置的array_key_exists()不同，该函数会递归地检查嵌套数组。
     * 
     * @param array $array 要检查的数组
     * @param mixed $key 要查找的键名，可以使用点表示法检查嵌套键
     * @return bool 如果键存在则返回true，否则返回false
     * @example
     * ```php
     * $array = ['a' => 1, 'b' => 2];
     * $result = Arr::exists($array, 'a'); // true
     * $result = Arr::exists($array, 'c'); // false
     * 
     * // 使用点表示法检查嵌套数组中的键
     * $array = [
     *     'user' => [
     *         'name' => 'John',
     *         'contacts' => [
     *             'email' => 'john@example.com'
     *         ]
     *     ]
     * ];
     * $result = Arr::exists($array, 'user.contacts.email'); // true
     * $result = Arr::exists($array, 'user.phone'); // false
     * ```
     */
    public static function exists(ArrayAccess|array $array,string|int $key): bool
    {
        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }

    /**
     * 获取数组中的第一个元素或第一个满足条件的元素
     * 
     * 获取数组中的第一个元素，如果提供了回调函数，则返回第一个通过回调函数检查的元素。
     * 如果没有找到匹配的元素，可以返回默认值。
     * 
     * @param array $array 要检查的数组
     * @param callable|null $callback 可选，用于过滤元素的回调函数
     * @param mixed $default 可选，当没有找到匹配元素时返回的默认值
     * @return mixed 数组的第一个元素或第一个通过回调函数检查的元素，或者默认值
     * @example
     * ```php
     * // 获取数组的第一个元素
     * $array = [1, 2, 3, 4];
     * $result = Arr::first($array); // 1
     * 
     * // 获取第一个满足条件的元素
     * $array = [1, 2, 3, 4, 5];
     * $result = Arr::first($array, function ($value, $key) {
     *     return $value > 2;
     * }); // 3
     * 
     * // 当没有满足条件的元素时返回默认值
     * $array = [1, 2];
     * $result = Arr::first($array, function ($value) {
     *     return $value > 3;
     * }, 'default'); // 'default'
     * ```
     */
    public static function first(array $array,?callable $callback = null,mixed $default = null): mixed
    {
        if (is_null($callback)) {
            if (empty($array)) {
                return $default;
            }

            foreach ($array as $item) {
                return $item;
            }
        }

        foreach ($array as $key => $value) {
            if (call_user_func($callback, $value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * 获取数组中的最后一个元素或最后一个满足条件的元素
     * 
     * 获取数组中的最后一个元素，如果提供了回调函数，则返回最后一个通过回调函数检查的元素。
     * 如果没有找到匹配的元素，可以返回默认值。
     * 
     * @param array $array 要检查的数组
     * @param callable|null $callback 可选，用于过滤元素的回调函数
     * @param mixed $default 可选，当没有找到匹配元素时返回的默认值
     * @return mixed 数组的最后一个元素或最后一个通过回调函数检查的元素，或者默认值
     * @example
     * ```php
     * // 获取数组的最后一个元素
     * $array = [1, 2, 3, 4];
     * $result = Arr::last($array); // 4
     * 
     * // 获取最后一个满足条件的元素
     * $array = [1, 2, 3, 4, 5];
     * $result = Arr::last($array, function ($value, $key) {
     *     return $value < 4;
     * }); // 3
     * 
     * // 当没有满足条件的元素时返回默认值
     * $array = [1, 2];
     * $result = Arr::last($array, function ($value) {
     *     return $value > 3;
     * }, 'default'); // 'default'
     * ```
     */
    public static function last(array $array,?callable $callback = null,mixed $default = null): mixed
    {
        if (is_null($callback)) {
            return empty($array) ? $default : end($array);
        }

        return self::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * 将多维数组扁平化成一维数组
     * 
     * 将多维数组递归扁平化为一维数组，可以通过参数控制扁平化的深度。
     * 如果未指定深度，则默认仅扁平化一层嵌套。
     * 
     * @param array $array 要扁平化的多维数组
     * @param int $depth 可选，扁平化的最大深度，默认为1（仅扁平一层），-1表示无限深度
     * @return array 扁平化后的一维数组
     * @example
     * ```php
     * // 扁平化一层嵌套
     * $array = [1, [2, 3], [4, [5, 6]]];
     * $result = Arr::flatten($array); // [1, 2, 3, 4, [5, 6]]
     * 
     * // 扁平化指定深度
     * $array = [1, [2, 3], [4, [5, 6]]];
     * $result = Arr::flatten($array, 2); // [1, 2, 3, 4, 5, 6]
     * 
     * // 完全扁平化（无限深度）
     * $array = [1, [2, [3, [4]]]];
     * $result = Arr::flatten($array, -1); // [1, 2, 3, 4]
     * ```
     */
    public static function flatten(array $array,int $depth = INF): array
    {
        $result = [];

        foreach ($array as $item) {
            $item = $item instanceof Collection ? $item->all() : $item;

            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, self::flatten($item, $depth - 1));
            }
        }

        return $result;
    }

    /**
     * 从数组中移除指定的键值对
     * 
     * 根据给定的键或键数组，从数组中移除对应的值。支持使用点表示法（如 'foo.bar'）来移除嵌套数组中的元素。
     * 注意：此函数会直接修改原始数组，通过引用传递。
     * 
     * @param array $array 要修改的数组（通过引用传递）
     * @param array|string $keys 要移除的键名或键名数组，支持点表示法
     * @return void 无返回值，直接修改原数组
     * @example
     * ```php
     * // 移除单个键
     * $array = ['name' => 'John', 'age' => 30];
     * Arr::forget($array, 'age');
     * // $array 现在为 ['name' => 'John']
     * 
     * // 移除多个键
     * $array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];
     * Arr::forget($array, ['age', 'city']);
     * // $array 现在为 ['name' => 'John']
     * 
     * // 使用点表示法移除嵌套键
     * $array = ['user' => ['name' => 'John', 'age' => 30, 'contact' => ['email' => 'john@example.com']]];
     * Arr::forget($array, 'user.contact.email');
     * // $array 现在为 ['user' => ['name' => 'John', 'age' => 30, 'contact' => []]]
     * ```
     */
    public static function forget(array &$array,array|string $keys): void
    {
        $original = &$array;

        $keys = (array) $keys;

        if (count($keys) === 0) {
            return;
        }

        foreach ($keys as $key) {
            // if the exact key exists in the top-level, remove it
            if (self::exists($array, $key)) {
                unset($array[$key]);

                continue;
            }

            $parts = explode('.', $key);

            // clean up before each pass
            $array = &$original;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (isset($array[$part]) && is_array($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }

            unset($array[array_shift($parts)]);
        }
    }

    /**
     * 获取数组中的指定键值，支持点表示法
     * 
     * 根据给定的键名从数组中获取对应的值。支持使用点表示法（如 'foo.bar'）来访问嵌套数组中的元素。
     * 如果指定的键不存在，可以返回提供的默认值。
     * 
     * @param array $array 要从中获取值的数组
     * @param array|string $key 要获取的键名或键名数组，支持点表示法
     * @param mixed $default 可选，当键不存在时返回的默认值
     * @return mixed 找到的值或默认值
     * @example
     * ```php
     * // 获取单个键的值
     * $array = ['name' => 'John', 'age' => 30];
     * $result = Arr::get($array, 'name'); // 'John'
     * 
     * // 使用点表示法获取嵌套键的值
     * $array = ['user' => ['name' => 'John', 'profile' => ['age' => 30, 'city' => 'New York']]];
     * $result = Arr::get($array, 'user.profile.city'); // 'New York'
     * 
     * // 当键不存在时返回默认值
     * $array = ['name' => 'John'];
     * $result = Arr::get($array, 'email', 'john@example.com'); // 'john@example.com'
     * 
     * // 获取多个键的值
     * $array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];
     * $result = Arr::get($array, ['name', 'age']); // ['John', 30]
     * ```
     */
    public static function get(ArrayAccess|array $array,string $key,mixed $default = null): mixed
    {
        if (!self::accessible($array)) {
            return $default;
        }

        if (is_null($key)) {
            return $array;
        }

        if (self::exists($array, $key)) {
            return $array[$key];
        }

        if (strpos($key, '.') === false) {
            return $array[$key] ?? $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (self::accessible($array) && self::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * 检查数组中是否存在指定的键，支持点表示法
     * 
     * 检查给定的键是否存在于数组中。支持使用点表示法（如 'foo.bar'）来检查嵌套数组中的键是否存在。
     * 如果提供了键数组，则会检查所有键是否都存在。
     * 
     * @param array $array 要检查的数组
     * @param array|string $keys 要检查的键名或键名数组，支持点表示法
     * @return bool 如果键存在返回true，否则返回false；如果是键数组，则所有键存在时才返回true
     * @example
     * ```php
     * // 检查单个键是否存在
     * $array = ['name' => 'John', 'age' => 30];
     * $result = Arr::has($array, 'name'); // true
     * 
     * // 使用点表示法检查嵌套键是否存在
     * $array = ['user' => ['name' => 'John', 'profile' => ['age' => 30]]];
     * $result = Arr::has($array, 'user.profile.age'); // true
     * $result = Arr::has($array, 'user.profile.email'); // false
     * 
     * // 检查多个键是否都存在
     * $array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];
     * $result = Arr::has($array, ['name', 'age']); // true
     * $result = Arr::has($array, ['name', 'email']); // false
     * ```
     */
    public static function has(ArrayAccess|array $array,string|array $keys): bool
    {
        $keys = (array) $keys;

        if (!$array || $keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            $subKeyArray = $array;

            if (self::exists($array, $key)) {
                continue;
            }

            foreach (explode('.', $key) as $segment) {
                if (self::accessible($subKeyArray) && self::exists($subKeyArray, $segment)) {
                    $subKeyArray = $subKeyArray[$segment];
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 判断数组是否为关联数组
     * 
     * 检查给定的数组是否为关联数组。如果数组的键不都是连续的整数索引，则认为它是一个关联数组。
     * 空数组被视为索引数组（返回false），而包含字符串键、非连续数字键或从0开始但不连续的数字键的数组被视为关联数组。
     * 
     * @param array $array 要检查的数组
     * @return bool 如果数组是关联数组返回true，否则返回false
     * @example
     * ```php
     * // 索引数组（连续的数字键）
     * $array1 = [1, 2, 3, 4];
     * $result1 = Arr::isAssoc($array1); // false
     * 
     * // 关联数组（字符串键）
     * $array2 = ['name' => 'John', 'age' => 30];
     * $result2 = Arr::isAssoc($array2); // true
     * 
     * // 关联数组（非连续数字键）
     * $array3 = [1 => 'a', 2 => 'b', 4 => 'd'];
     * $result3 = Arr::isAssoc($array3); // true
     * 
     * // 空数组
     * $array4 = [];
     * $result4 = Arr::isAssoc($array4); // false
     * ```
     */
    public static function isAssoc(array $array): bool
    {
        $keys = array_keys($array);

        return array_keys($keys) !== $keys;
    }

    /**
     * 从数组中获取指定的键值对
     * 
     * 从给定的数组中提取指定键对应的键值对，返回一个只包含这些键值对的新数组。
     * 如果指定的键在原数组中不存在，则在返回的数组中不会包含该键值对。
     * $keys参数可以是单个键名的字符串，也可以是多个键名组成的数组。
     * 
     * @param array $array 源数组
     * @param array|string $keys 要提取的键名，支持单个字符串或数组形式的多个键名
     * @return array 包含指定键值对的新数组
     * @example
     * ```php
     * // 从数组中提取单个键
     * $array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];
     * $result1 = Arr::only($array, 'name');
     * // 结果: ['name' => 'John']
     * 
     * // 从数组中提取多个键
     * $result2 = Arr::only($array, ['name', 'age']);
     * // 结果: ['name' => 'John', 'age' => 30]
     * 
     * // 尝试提取不存在的键
     * $result3 = Arr::only($array, ['name', 'country']);
     * // 结果: ['name' => 'John']  (country键不存在，不会包含在结果中)
     * ```
     */
    public static function only(array $array,array|string $keys): array
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * 从数组中提取指定键的值
     * 
     * 从数组的每个元素中提取指定键的值，返回一个新的数组。
     * 如果提供了$key参数，则返回的数组将使用该键对应的值作为新数组的键名。
     * 支持使用点表示法访问嵌套数组中的值。
     * 
     * @param array $array 要处理的数组，通常是多维数组
     * @param array|string $value 要提取的值对应的键名，支持点表示法访问嵌套值
     * @param null|string $key 可选，用于作为返回数组键名的键名
     * @return array 提取后的值组成的数组
     * @example
     * ```php
     * // 从对象数组中提取单个属性
     * $array = [
     *     ['id' => 1, 'name' => 'John'],
     *     ['id' => 2, 'name' => 'Jane'],
     *     ['id' => 3, 'name' => 'Bob']
     * ];
     * $result1 = Arr::pluck($array, 'name');
     * // 结果: ['John', 'Jane', 'Bob']
     * 
     * // 使用id作为键名提取name
     * $result2 = Arr::pluck($array, 'name', 'id');
     * // 结果: [1 => 'John', 2 => 'Jane', 3 => 'Bob']
     * 
     * // 使用点表示法提取嵌套值
     * $nestedArray = [
     *     ['user' => ['id' => 1, 'profile' => ['name' => 'John']]],
     *     ['user' => ['id' => 2, 'profile' => ['name' => 'Jane']]]
     * ];
     * $result3 = Arr::pluck($nestedArray, 'user.profile.name');
     * // 结果: ['John', 'Jane']
     * 
     * // 使用点表示法提取嵌套值并指定键名
     * $result4 = Arr::pluck($nestedArray, 'user.profile.name', 'user.id');
     * // 结果: [1 => 'John', 2 => 'Jane']
     * ```
     */
    public static function pluck(array $array,string|array $value,string|array|null $key = null): array
    {
        $results = [];

        [$value, $key] = self::explodePluckParameters($value, $key);

        foreach ($array as $item) {
            $itemValue = self::pluck($item, $value);

            // If the key is "null", we will just append the value to the array and keep
            // looping. Otherwise we will key the array using the value of the key we
            // received from the developer. Then we'll return the final array form.
            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = self::pluck($item, $key);

                if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                    $itemKey = (string) $itemKey;
                }

                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * 解析pluck函数的参数
     * 
     * 此方法用于解析pluck函数中的$value和$key参数，处理字符串形式的参数并将其转换为数组。
     * 该函数是内部辅助方法，主要用于支持pluck方法中通过字符串形式指定多个键的功能。
     * 
     * @param mixed $value 要解析的value参数，可以是字符串或数组
     * @param mixed $key 要解析的key参数，可以是字符串或数组
     * @return array 包含解析后的值和键的数组，格式为['value' => ..., 'key' => ...]
     * @example
     * ```php
     * // 解析字符串形式的value和key参数
     * $result1 = Arr::explodePluckParameters('name', 'id');
     * // 结果: ['value' => ['name'], 'key' => ['id']]
     * 
     * // 解析字符串形式的value和空key参数
     * $result2 = Arr::explodePluckParameters('name,email', null);
     * // 结果: ['value' => ['name', 'email'], 'key' => null]
     * 
     * // 解析数组形式的value和key参数
     * $result3 = Arr::explodePluckParameters(['name', 'email'], ['id']);
     * // 结果: ['value' => ['name', 'email'], 'key' => ['id']]
     * ```
     */
    protected static function explodePluckParameters(string|array $value,string|array|null $key): array
    {
        $value = is_string($value) ? explode('.', $value) : $value;

        $key = is_null($key) || is_array($key) ? $key : explode('.', $key);

        return [$value, $key];
    }

    /**
     * 向数组开头添加元素
     * 
     * 将指定的值添加到数组的开头，并返回修改后的数组。
     * 支持添加单个元素或指定键名的键值对。如果提供了键名，则会以指定的键名添加到数组开头。
     * 与PHP内置的array_unshift不同，此方法不会重置数字索引，而是保持键的关联性。
     * 
     * @param array $array 要操作的数组
     * @param mixed $value 要添加的值
     * @param null|string|int $key 可选，要使用的键名
     * @return array 修改后的数组
     * @example
     * ```php
     * // 添加单个元素到数组开头
     * $array1 = [1, 2, 3];
     * $result1 = Arr::prepend($array1, 0);
     * // 结果: [0, 1, 2, 3]
     * 
     * // 添加带键名的元素到数组开头
     * $array2 = ['b' => 'second', 'c' => 'third'];
     * $result2 = Arr::prepend($array2, 'first', 'a');
     * // 结果: ['a' => 'first', 'b' => 'second', 'c' => 'third']
     * 
     * // 添加数组到数组开头
     * $array3 = [3, 4];
     * $result3 = Arr::prepend($array3, [1, 2]);
     * // 结果: [[1, 2], 3, 4]
     * ```
     */
    public static function prepend(array $array,mixed $value,mixed $key = null): array
    {
        if (is_null($key)) {
            array_unshift($array, $value);
        } else {
            $array = [$key => $value] + $array;
        }

        return $array;
    }

    /**
     * 从数组中移除并返回指定键的值
     * 
     * 从数组中获取指定键的值，然后从数组中移除该键值对，并返回获取到的值。
     * 如果指定的键不存在，则返回默认值。支持使用点表示法操作嵌套数组。
     * 
     * @param array $array 要操作的数组（将被修改）
     * @param array|string $key 要获取并移除的键名，支持点表示法访问嵌套键
     * @param null|mixed $default 当键不存在时返回的默认值
     * @return mixed 获取到的值或默认值
     * @example
     * ```php
     * // 从数组中获取并移除单个键的值
     * $array = ['name' => 'John', 'age' => 30];
     * $name = Arr::pull($array, 'name');
     * // $name 的值为: 'John'
     * // $array 现在变为: ['age' => 30]
     * 
     * // 尝试获取不存在的键，返回默认值
     * $city = Arr::pull($array, 'city', 'Unknown');
     * // $city 的值为: 'Unknown'
     * // $array 保持不变: ['age' => 30]
     * 
     * // 使用点表示法操作嵌套数组
     * $nestedArray = ['user' => ['profile' => ['name' => 'John']]];
     * $name = Arr::pull($nestedArray, 'user.profile.name');
     * // $name 的值为: 'John'
     * // $nestedArray 变为: ['user' => ['profile' => []]]
     * ```
     */
    public static function pull(array &$array,string $key,mixed $default = null): mixed
    {
        $value = self::get($array, $key, $default);

        self::forget($array, $key);

        return $value;
    }

    /**
     * 随机获取数组中的一个或多个元素
     * 
     * 从给定的数组中随机获取一个或指定数量的元素。
     * 如果指定了$number参数，则返回一个包含随机元素的新数组；否则返回单个随机元素。
     * 如果$number大于数组长度，则抛出异常。
     * 
     * @param array $array 要从中随机获取元素的数组
     * @param int|null $number 可选，要获取的随机元素数量
     * @return mixed 如果未指定$number返回单个随机元素，否则返回包含随机元素的数组
     * @throws \Exception 当请求的元素数量大于数组可用元素数量时抛出
     * @example
     * ```php
     * // 随机获取一个元素
     * $array = [1, 2, 3, 4, 5];
     * $result1 = Arr::random($array);
     * // 结果: 随机返回数组中的一个元素，如 3
     * 
     * // 随机获取多个元素
     * $result2 = Arr::random($array, 2);
     * // 结果: 随机返回数组中的两个元素，如 [2, 5]
     * ```
     */
    public static function random(array $array,int|null $number = null): mixed
    {
        $requested = is_null($number) ? 1 : $number;

        $count = count($array);

        if ($requested > $count) {
            throw new \Exception(
                "You requested {$requested} items, but there are only {$count} items available."
            );
        }

        if (is_null($number)) {
            return $array[array_rand($array)];
        }

        if ((int) $number === 0) {
            return [];
        }

        $keys = array_rand($array, $number);

        $results = [];

        foreach ((array) $keys as $key) {
            $results[] = $array[$key];
        }

        return $results;
    }

    /**
     * 设置数组指定键的值，支持嵌套数组的点表示法
     * 
     * 在数组中设置指定键的值，支持使用点表示法来设置嵌套数组中的键值。
     * 如果通过点表示法指定的父级键不存在，将自动创建对应的数组结构。
     * 可以传入数组形式的键来批量设置多个键值对。
     * 
     * @param array $array 要修改的数组
     * @param string|array $key 要设置的键名，支持点表示法或键值对数组
     * @param mixed $value 要设置的值，如果$key是数组，则忽略此参数
     * @return array 返回修改后的数组
     * @example
     * ```php
     * // 设置单个键值
     * $array = ['user' => ['name' => 'John']];
     * $result1 = Arr::set($array, 'user.email', 'john@example.com');
     * // 结果: ['user' => ['name' => 'John', 'email' => 'john@example.com']]
     * 
     * // 设置不存在的嵌套键，会自动创建数组结构
     * $result2 = Arr::set($array, 'user.profile.age', 30);
     * // 结果: ['user' => ['name' => 'John', 'profile' => ['age' => 30]]]
     * 
     * // 批量设置多个键值对
     * $result3 = Arr::set($array, [
     *     'user.name' => 'Jane',
     *     'user.email' => 'jane@example.com'
     * ]);
     * // 结果: ['user' => ['name' => 'Jane', 'email' => 'jane@example.com']]
     * ```
     */
    public static function set(array &$array,string $key,mixed $value): array
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            // If the key doesn't exist at this depth, we will just create an empty array
            // to hold the next value, allowing us to create the arrays to hold final
            // values at the correct depth. Then we'll keep digging into the array.
            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * 打乱数组顺序并返回新数组
     * 
     * 随机打乱数组元素的顺序，返回一个新的打乱后的数组，同时保持键名和键值的对应关系。
     * 与PHP内置的shuffle()函数不同，此方法不会重置数字索引，而是返回一个全新的数组。
     * 可以选择性地提供随机种子以生成可重现的随机序列。
     * 
     * @param array $array 要打乱顺序的数组
     * @param int|null $seed 可选，随机种子，使用相同的种子将产生相同的随机排列
     * @return array 返回打乱顺序后的新数组，保持键值对应关系
     * @example
     * ```php
     * // 打乱数字索引数组
     * $array = [1, 2, 3, 4, 5];
     * $result1 = Arr::shuffle($array);
     * // 结果: 可能是 [3, 1, 5, 2, 4] 或其他随机排列
     * 
     * // 使用随机种子确保可重现的结果
     * $array = [1, 2, 3, 4, 5];
     * $result2 = Arr::shuffle($array, 12345);
     * $result3 = Arr::shuffle($array, 12345);
     * // $result2 和 $result3 将产生完全相同的随机序列
     * 
     * // 打乱关联数组
     * $array = ['a' => 1, 'b' => 2, 'c' => 3];
     * $result4 = Arr::shuffle($array);
     * // 结果: 可能是 ['c' => 3, 'a' => 1, 'b' => 2] 或其他随机排列
     * ```
     */
    public static function shuffle(array $array,int|null $seed = null): array
    {
        if (is_null($seed)) {
            shuffle($array);
        } else {
            srand($seed);

            usort($array, function () {
                return rand(-1, 1);
            });
        }

        return $array;
    }

    /**
     * 对数组进行排序，可以使用回调函数或点表示法
     * 
     * 根据指定的回调函数或点表示法对数组进行排序。如果不指定回调，则使用PHP的默认排序规则。
     * 点表示法可以用于对多维数组中的特定键进行排序。此方法内部使用Collection类的sort方法实现。
     * 排序操作不会修改原始数组，而是返回一个新的排序后的数组。
     * 
     * @param array $array 要排序的数组
     * @param callable|string|null $callback 可选，排序回调函数或点表示法的键名
     * @return array 返回排序后的新数组
     * @example
     * ```php
     * // 默认排序（升序）
     * $array = [3, 1, 4, 2, 5];
     * $result1 = Arr::sort($array);
     * // 结果: [1, 2, 3, 4, 5]
     * 
     * // 使用回调函数自定义排序
     * $array = [3, 1, 4, 2, 5];
     * $result2 = Arr::sort($array, function($a, $b) {
     *     return $b - $a; // 降序排序
     * });
     * // 结果: [5, 4, 3, 2, 1]
     * 
     * // 使用点表示法对多维数组排序
     * $array = [
     *     ['name' => 'John', 'age' => 30],
     *     ['name' => 'Jane', 'age' => 25],
     *     ['name' => 'Bob', 'age' => 35]
     * ];
     * $result3 = Arr::sort($array, 'age');
     * // 结果: 根据age升序排列的数组
     * ```
     */
    public static function sort(array $array,callable|string|null $callback = null): array
    {
        return Collection::make($array)->sort($callback)->all();
    }

    /**
     * 递归地对数组按键和值进行排序
     * 
     * 递归地对多维数组进行排序处理。首先递归处理每个子数组，然后根据数组类型进行排序：
     * - 对于关联数组（键名不是连续整数），按键名进行升序排序（使用ksort）
     * - 对于索引数组（键名是连续整数），按值进行升序排序（使用sort）
     * 此函数返回一个新的排序后的数组，不会修改原始数组。
     * 
     * @param array $array 要递归排序的数组
     * @return array 返回递归排序后的新数组
     * @example
     * ```php
     * // 对简单关联数组排序
     * $array = ['c' => 3, 'a' => 1, 'b' => 2];
     * $result1 = Arr::sortRecursive($array);
     * // 结果: ['a' => 1, 'b' => 2, 'c' => 3] (按键名排序)
     * 
     * // 对索引数组排序
     * $array = [3, 1, 4, 2, 5];
     * $result2 = Arr::sortRecursive($array);
     * // 结果: [1, 2, 3, 4, 5] (按值排序)
     * 
     * // 对多维数组递归排序
     * $array = [
     *     'b' => [3, 1, 2],
     *     'a' => ['d' => 4, 'c' => 3]
     * ];
     * $result3 = Arr::sortRecursive($array);
     * // 结果: [
     * //   'a' => ['c' => 3, 'd' => 4],
     * //   'b' => [1, 2, 3]
     * // ]
     * ```
     */
    public static function sortRecursive(array $array): array
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = self::sortRecursive($value);
            }
        }

        if (self::isAssoc($array)) {
            ksort($array);
        } else {
            sort($array);
        }

        return $array;
    }

    /**
     * 将数组转换为URL查询字符串
     * 
     * 将关联数组转换为标准的URL查询字符串格式，可直接用于构建GET请求URL或表单数据。
     * 使用PHP的http_build_query函数实现，采用RFC3986标准进行URL编码，确保特殊字符正确转义。
     * 默认使用'&'作为查询参数分隔符，不添加前缀。
     * 
     * @param array $array 要转换的关联数组，键值对将被转换为查询参数
     * @return string 返回格式化的查询字符串，如"key1=value1&key2=value2"
     * @example
     * ```php
     * // 基本使用 - 简单键值对
     * $array = ['name' => 'John', 'age' => 30];
     * $result1 = Arr::query($array);
     * // 结果: "name=John&age=30"
     * 
     * // 处理包含特殊字符的值
     * $array = ['search' => 'hello world', 'filter' => 'a=b&c=d'];
     * $result2 = Arr::query($array);
     * // 结果: "search=hello%20world&filter=a%3Db%26c%3Dd" (已正确编码特殊字符)
     * 
     * // 处理嵌套数组
     * $array = [
     *     'user' => ['name' => 'John', 'email' => 'john@example.com'],
     *     'page' => 1
     * ];
     * $result3 = Arr::query($array);
     * // 结果: "user%5Bname%5D=John&user%5Bemail%5D=john%40example.com&page=1"
     * // 解码后: "user[name]=John&user[email]=john@example.com&page=1"
     * ```
     */
    public static function query(array $array): string
    {
        return http_build_query($array, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 使用给定的回调函数过滤数组元素
     * 
     * 通过回调函数对数组中的每个元素进行判断，只保留回调函数返回true的元素。
     * 回调函数接收两个参数：当前元素的值和键。函数内部使用PHP的array_filter实现，
     * 并通过ARRAY_FILTER_USE_BOTH标志传递键和值给回调函数。
     * 此函数返回一个新的过滤后的数组，不会修改原始数组，同时保持数组键的关联性。
     * 
     * @param array $array 要过滤的数组
     * @param callable $callback 过滤回调函数，接收值和键两个参数，返回布尔值
     * @return array 返回过滤后的数组，只包含回调函数返回true的元素
     * @example
     * ```php
     * // 过滤出偶数
     * $array = [1, 2, 3, 4, 5, 6];
     * $result1 = Arr::where($array, function($value) {
     *     return $value % 2 === 0; // 只保留偶数
     * });
     * // 结果: [1 => 2, 3 => 4, 5 => 6] (保留原始索引)
     * 
     * // 同时使用值和键进行过滤
     * $array = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
     * $result2 = Arr::where($array, function($value, $key) {
     *     return $key !== 'b' && $value > 1; // 排除键为'b'的元素，且值大于1
     * });
     * // 结果: ['c' => 3, 'd' => 4]
     * 
     * // 过滤关联数组元素
     * $array = [
     *     ['name' => 'John', 'age' => 30],
     *     ['name' => 'Jane', 'age' => 25],
     *     ['name' => 'Bob', 'age' => 35]
     * ];
     * $result3 = Arr::where($array, function($user) {
     *     return $user['age'] > 28; // 只保留年龄大于28的用户
     * });
     * // 结果: [0 => ['name' => 'John', 'age' => 30], 2 => ['name' => 'Bob', 'age' => 35]]
     * ```
     */
    public static function where(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * 将非数组值包装成数组，如果值为null则返回空数组
     * 
     * 提供一个统一的值包装机制，确保无论传入什么类型的值，都能获得一个数组结果：
     * - 如果值为null，返回空数组[]
     * - 如果值已经是数组，则直接返回原值
     * - 如果值不是数组且不为null，则将值包装在一个新数组中返回
     * 此函数常用于需要确保参数始终是数组的场景，提高代码的健壮性。
     * 
     * @param array|null $value 要包装的值，可以是任何类型，包括null和数组
     * @return array 返回标准化后的数组
     * @example
     * ```php
     * // 包装非数组值
     * $result1 = Arr::wrap('hello');
     * // 结果: ['hello']
     * 
     * // 处理已经是数组的值
     * $result2 = Arr::wrap(['a', 'b', 'c']);
     * // 结果: ['a', 'b', 'c'] (不变)
     * 
     * // 处理null值
     * $result3 = Arr::wrap(null);
     * // 结果: [] (空数组)
     * 
     * // 包装数字值
     * $result4 = Arr::wrap(42);
     * // 结果: [42]
     * 
     * // 包装对象
     * $obj = new stdClass();
     * $obj->name = 'Test';
     * $result5 = Arr::wrap($obj);
     * // 结果: [$obj] (包含对象的单元素数组)
     * ```
     */
    public static function wrap(array $value): array
    {
        if (is_null($value)) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }
	
	/**
	 * 获取数组中指定索引位置的键值对
	 * 
	 * 从数组中查找并返回指定索引位置的元素的键和值。索引从0开始计数，
	 * 函数会遍历数组直到找到对应位置的元素，然后以键值对数组的形式返回。
	 * 如果索引超出数组范围，则返回空数组[]。
	 * 
	 * @param array $arr 要搜索的数组数据
	 * @param int $index 要获取元素的位置索引（从0开始）
	 * @return array 返回包含键和值的数组，如果未找到则返回空数组
	 * @example
	 * ```php
	 * // 获取索引为1的元素
	 * $array = ['a' => 10, 'b' => 20, 'c' => 30];
	 * $result1 = Arr::arrayGetIndex($array, 1);
	 * // 结果: ['b', 20] (索引1对应的键值对)
	 * 
	 * // 获取索引为0的元素
	 * $result2 = Arr::arrayGetIndex($array, 0);
	 * // 结果: ['a', 10] (第一个元素)
	 * 
	 * // 获取超出范围的索引
	 * $result3 = Arr::arrayGetIndex($array, 5);
	 * // 结果: [] (空数组，表示未找到)
	 * 
	 * // 处理索引数组
	 * $indexArray = [100, 200, 300];
	 * $result4 = Arr::arrayGetIndex($indexArray, 2);
	 * // 结果: [2, 300] (索引为2的键值对)
	 * ```
	 */
	public static function arrayGetIndex(array $arr,int $index): array
	{
	   foreach($arr as $k=>$v){
		   $index--;
		   if($index<0) return [$k, $v];
	   }
	   return [];
	}
	
	/**
	 * 递归地对数组中的每个元素应用指定的回调函数
	 * 
	 * 对多维数组中的每个元素递归地应用给定的回调函数进行处理。该函数会遍历整个数组结构，
	 * 对于每个子数组继续递归调用自身，对于非数组元素则应用回调函数进行处理。
	 * 整个过程保持数组的原始键名和结构不变，只替换叶子节点的值。
	 * 
	 * @param string|callable $filter 回调函数名或匿名函数，用于处理非数组元素
	 * @param array $data 要递归处理的数组
	 * @return array 返回经过递归处理后的新数组
	 * @example
	 * ```php
	 * // 使用字符串回调函数 - 应用strtoupper转换字符串
	 * $array = ['a' => 'hello', 'b' => ['c' => 'world']];
	 * $result1 = Arr::arrayMapRecursive('strtoupper', $array);
	 * // 结果: ['a' => 'HELLO', 'b' => ['c' => 'WORLD']]
	 * 
	 * // 使用匿名函数 - 将数值乘以2
	 * $array = [1, 2, [3, 4, [5]]];
	 * $result2 = Arr::arrayMapRecursive(function($value) {
	 *     return is_numeric($value) ? $value * 2 : $value;
	 * }, $array);
	 * // 结果: [2, 4, [6, 8, [10]]]
	 * 
	 * // 处理混合类型数组
	 * $array = [
	 *     'name' => 'John',
	 *     'age' => 30,
	 *     'address' => [
	 *         'city' => 'New York',
	 *         'zip' => '10001'
	 *     ]
	 * ];
	 * $result3 = Arr::arrayMapRecursive(function($value) {
	 *     return is_string($value) ? strtoupper($value) : $value;
	 * }, $array);
	 * // 结果: [
	 * //   'name' => 'JOHN',
	 * //   'age' => 30,
	 * //   'address' => [
	 * //     'city' => 'NEW YORK',
	 * //     'zip' => '10001'
	 * //   ]
	 * // ]
	 * ```
	 */
	public static function arrayMapRecursive(string $filter,array $data): array
	{
		$result = [];
		foreach($data as $key => $val){
			$result[$key] = is_array($val) ? self::arrayMapRecursive($filter, $val) : call_user_func($filter, $val);
		}
		return $result;
	}
	
	/**
	 * 冒泡排序算法实现
	 * 
	 * 使用标准冒泡排序算法对一维数组进行升序排序。该算法通过重复遍历要排序的数组，
	 * 比较相邻两个元素，如果它们的顺序错误就交换它们，直到没有交换发生为止。
	 * 算法时间复杂度为O(n²)，对于大型数组性能较低，但实现简单易懂。
	 * 
	 * @param array $array 需要排序的一维数组，元素必须是可比较的基本类型（如数字或字符串）
	 * @return array|false 返回排序后的新数组，升序排列；如果输入数组为空，则返回false
	 * @example
	 * ```php
	 * // 对数字数组进行排序
	 * $numbers = [3, 1, 4, 1, 5, 9, 2, 6];
	 * $sorted = Arr::arrayBubbleSort($numbers);
	 * // 结果: [1, 1, 2, 3, 4, 5, 6, 9]
	 * 
	 * // 对字符串数组进行排序
	 * $strings = ['banana', 'apple', 'cherry', 'date'];
	 * $sorted = Arr::arrayBubbleSort($strings);
	 * // 结果: ['apple', 'banana', 'cherry', 'date']
	 * 
	 * // 空数组情况
	 * $empty = [];
	 * $result = Arr::arrayBubbleSort($empty);
	 * // 结果: false
	 * ```
	 */
	public static function arrayBubbleSort(array $array): mixed
	{
		$count = count($array);
		if ($count<=0){
			return false;
		}
		for ($i=0; $i <$count ; $i++){ 
			for ($j=0; $j <$count-$i-1 ; $j++){ 
				if ($array[$j]>$array[$j+1]){
					$tmp = $array[$j+1];
					$array[$j+1]=$array[$j];
					$array[$j]=$tmp;
				}
			}
		}
		return $array;
	}
	
	/**
	 * 二维数组按照指定键值排序
	 * 
	 * 根据指定的字段值对二维数组进行排序，并处理可能出现的值相同的情况。
	 * 该方法通过创建一个中间哈希表来确保即使排序字段值重复也能正确排序。
	 * 
	 * @param array $records 需要排序的二维数组，每个元素应为关联数组或索引数组
	 * @param string $field 用于排序的字段名，该字段必须存在于二维数组的每个子数组中
	 * @param bool $reverse 排序方向，默认为false（升序），设置为true可实现降序排序
	 * @return array 返回排序后的二维数组，保持原有键的关联关系
	 * @example
	 * ```php
	 * // 按照年龄升序排列用户数组
	 * $users = [
	 *     ['name' => 'John', 'age' => 30],
	 *     ['name' => 'Jane', 'age' => 25],
	 *     ['name' => 'Bob', 'age' => 35]
	 * ];
	 * $sorted = Arr::arraySort($users, 'age');
	 * // 结果: 按照age升序排列的用户数组
	 * 
	 * // 处理重复值的情况
	 * $products = [
	 *     ['id' => 1, 'price' => 100],
	 *     ['id' => 2, 'price' => 50],
	 *     ['id' => 3, 'price' => 100],
	 *     ['id' => 4, 'price' => 75]
	 * ];
	 * $sorted = Arr::arraySort($products, 'price');
	 * // 结果: 价格相同的商品会被保留在排序中
	 * 
	 * // 使用降序排序
	 * $sorted = Arr::arraySort($users, 'age', true);
	 * // 结果: 按照age降序排列的用户数组
	 * ```
	 */
	public static function arraySort(array $records,string $field,bool $reverse=false): array
	{
		$hash = [];
		foreach($records as $addon=>$record){
			$key = $record[$field];
			while(isset($hash[$key])){
				$key++;
			}
			$hash[$key] = $addon;
		}
		
		$recordLists = [];
		foreach($hash as $record){
			$recordLists[$record]= $records[$record];
		}
		return $recordLists;
	}
	
	/**
	 * 尝试对数组元素应用回调并返回第一个非假值结果
	 * 
	 * 遍历数组中的每个元素，依次对其调用指定的回调函数，一旦遇到回调函数返回非假值的情况，
	 * 立即返回该值并终止遍历。如果所有元素的回调执行都返回假值，则最终返回false。
	 * 这种设计模式在查找第一个满足特定条件的元素或处理结果时非常有用。
	 * 
	 * @param array $array 要遍历的数组，可以是关联数组或索引数组
	 * @param callable $callback 应用于每个数组元素的回调函数，接收数组元素作为第一个参数
	 * @param mixed ...$args 可选，传递给回调函数的额外参数
	 * @return mixed 返回回调函数的第一个非假值结果，如果所有结果都是假值则返回false
	 * @example
	 * ```php
	 * // 查找第一个大于2的元素
	 * $numbers = [1, 2, 3, 4, 5];
	 * $result1 = Arr::arrayTry($numbers, function($num) {
	 *     return $num > 2 ? $num : false;
	 * });
	 * // 结果: 3 (第一个大于2的元素)
	 * 
	 * // 使用额外参数
	 * $users = [
	 *     ['name' => 'John', 'age' => 25],
	 *     ['name' => 'Jane', 'age' => 30],
	 *     ['name' => 'Bob', 'age' => 35]
	 * ];
	 * $result2 = Arr::arrayTry($users, function($user, $minAge) {
	 *     return $user['age'] >= $minAge ? $user['name'] : false;
	 * }, 30);
	 * // 结果: 'Jane' (第一个年龄大于等于30的用户名字)
	 * 
	 * // 所有元素都不满足条件
	 * $result3 = Arr::arrayTry($numbers, function($num) {
	 *     return $num > 10 ? $num : false;
	 * });
	 * // 结果: false (没有大于10的元素)
	 * ```
	 */
	public static function arrayTry(array $array, $callback, ...$args): mixed
	{
		if (!$array || !$callback){
			return false;
		}
		if (!$args){
			$args = [];
		} 
		foreach($array as $v){
			$params = $args;
			array_unshift($params, $v);
			$x = call_user_func_array($callback, $params);
			if ($x){
				return $x;
			} 
		} 
		return false;
	}
	
	/**
	 * 计算多个数组的并集
	 * 
	 * 将两个或多个数组合并，返回包含所有不重复元素的新数组。对于重复的值，
	 * 后面数组中的值不会覆盖前面数组中的值。该方法通过递归方式处理两个以上的数组。
	 * 如果只传入一个或零个数组，则返回false。
	 * 
	 * @param array ...$argsCount 两个或多个要求并集的数组
	 * @return array|false 返回包含所有不重复元素的新数组，如果参数不满足要求则返回false
	 * @example
	 * ```php
	 * // 计算两个数组的并集
	 * $array1 = [1, 2, 3, 4];
	 * $array2 = [3, 4, 5, 6];
	 * $result1 = Arr::arrayUnion($array1, $array2);
	 * // 结果: [1, 2, 3, 4, 5, 6]
	 * 
	 * // 计算三个数组的并集
	 * $array3 = [6, 7, 8];
	 * $result2 = Arr::arrayUnion($array1, $array2, $array3);
	 * // 结果: [1, 2, 3, 4, 5, 6, 7, 8]
	 * 
	 * // 处理包含字符串的数组
	 * $fruits1 = ['apple', 'banana', 'orange'];
	 * $fruits2 = ['orange', 'grape', 'pear'];
	 * $result3 = Arr::arrayUnion($fruits1, $fruits2);
	 * // 结果: ['apple', 'banana', 'orange', 'grape', 'pear']
	 * 
	 * // 参数不足的情况
	 * $result4 = Arr::arrayUnion($array1);
	 * // 结果: false (只传入了一个数组)
	 * ```
	 */
	public static function arrayUnion(array ...$argsCount): mixed
	{
		$count = count($argsCount);
		if ($count < 2){
			return false;
		} else if (2 === $count){
			[$arr1, $arr2] = func_get_args();
			// 使用foreach替代已废弃的each函数
			foreach ($arr2 as $k => $v){
				if (!in_array($v, $arr1)) $arr1[] = $v;
			} 
			return $arr1;
		} else { // 三个以上的数组合并
			$i = 0;
			$arr2 = $argsCount[0];
			do{
				$i++;
				$arr2 = self::arrayUnion($arr2, $argsCount[$i]);
			}while($i < $count);
			return $arr2;
		} 
	}

    /**
	 * 计算多个数组的交集
	 * 
	 * 返回多个数组中共同存在的元素，即所有数组都包含的元素。该方法通过递归方式处理两个以上的数组。
	 * 如果只传入一个或零个数组，则返回false。
	 * 
	 * @param array ...$argsCount 两个或多个要求交集的数组
	 * @return array|false 返回包含所有共同元素的新数组，如果参数不满足要求则返回false
	 * @example
	 * ```php
	 * // 计算两个数组的交集
	 * $array1 = [1, 2, 3, 4];
	 * $array2 = [3, 4, 5, 6];
	 * $result1 = Arr::arrayIntersect($array1, $array2);
	 * // 结果: [3, 4]
	 * 
	 * // 计算三个数组的交集
	 * $array3 = [6, 7, 8];
	 * $result2 = Arr::arrayIntersect($array1, $array2, $array3);
	 * // 结果: [] (没有共同元素)
	 * 
	 * // 处理包含字符串的数组
	 * $fruits1 = ['apple', 'banana', 'orange'];
	 * $fruits2 = ['orange', 'grape', 'pear'];
	 * $result3 = Arr::arrayIntersect($fruits1, $fruits2);
	 * // 结果: ['orange']
	 * 
	 * // 参数不足的情况
	 * $result4 = Arr::arrayIntersect($array1);
	 * // 结果: false (只传入了一个数组)
	 * ```
	 */
	public static function arrayIntersect(array ...$argsCount): mixed
	{
		$count = count($argsCount);
		if ($count < 2){
			return false;
		} else if (2 === $count){
			[$arr1, $arr2] = func_get_args();
			foreach ($arr2 as $k => $v){
				if (in_array($v, $arr1)) $arr1[] = $v;
			} 
			return $arr1;
		} else { // 三个以上的数组合并
			$i = 0;
			$arr2 = $argsCount[0];
			do{
				$i++;
				$arr2 = self::arrayIntersect($arr2, $argsCount[$i]);
			}while($i < $count);
			return $arr2;
		} 
	}

	/**
	 * 递归深度合并数组
	 *
	 * 后者同名标量覆盖前者；双方均为数组时递归合并；数字键按追加语义由后者覆盖同键
	 * 已有 first/last，本方法补齐 deepMerge
	 *
	 * @param array ...$arrays 待合并数组（从左到右）
	 * @return array
	 * @access public
	 * @static
	 */
	public static function deepMerge(array ...$arrays): array
	{
		$result = [];
		foreach ($arrays as $array) {
			foreach ($array as $key => $value) {
				if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
					$result[$key] = self::deepMerge($result[$key], $value);
				} else {
					$result[$key] = $value;
				}
			}
		}
		return $result;
	}
}

