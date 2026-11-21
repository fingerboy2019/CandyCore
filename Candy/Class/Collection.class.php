<?php
/**
 * Candy框架 数据集管理类
 * 
 * Collection类提供了一套丰富的数组操作API，支持链式调用，用于高效地处理和转换数据集合。
 * 该类实现了ArrayAccess、Countable、IteratorAggregate和JsonSerializable接口，
 * 使其能够像原生PHP数组一样使用，同时提供了更多便捷的方法。
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-03 11:31:47
 * @final 该类不允许被继承
 */
 
declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Collection implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * 数据集内部存储的数据数组
     * 
     * 该数组保存了Collection实例中所有的元素数据，是整个Collection操作的核心存储结构。
     * 
     * @var array 存储的数据集内容
     */
    protected $items = [];

    /**
     * 构造函数，初始化数据集
     * 
     * 创建一个新的Collection实例，并将传入的数据转换为数组格式存储。
     * 支持传入数组、Collection实例或其他可转换为数组的类型。
     * 
     * @param mixed $items 初始数据，可以是数组、Collection实例或其他可转换为数组的类型
     * @example
     * ```php
     * // 使用数组初始化
     * $collection = new Collection([1, 2, 3]);
     * 
     * // 使用另一个Collection初始化
     * $anotherCollection = new Collection($collection);
     * ```
     */
    public function __construct(mixed $items = [])
    {
		// 将输入数据转换为标准数组格式并存储
		$this->items = $this->convertToArray($items);
    }

    /**
     * 静态工厂方法，创建Collection实例
     * 
     * 提供一个便捷的静态方法来创建Collection实例，功能等同于构造函数。
     * 支持链式调用的起始方法。
     * 
     * @param mixed $items 初始数据，可以是数组、Collection实例或其他可转换为数组的类型
     * @return static 新创建的Collection实例
     * @example
     * ```php
     * // 使用静态方法创建Collection
     * $collection = Collection::make([1, 2, 3]);
     * 
     * // 支持链式调用
     * $result = Collection::make([1, 2, 3])
     *     ->map(function($item) { return $item * 2; })
     *     ->toArray();
     * ```
     */
    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    /**
     * 判断数据集是否为空
     * 
     * 检查当前Collection实例中是否包含任何元素。
     * 
     * @return bool 如果数据集为空则返回true，否则返回false
     * @example
     * ```php
     * $emptyCollection = new Collection();
     * $emptyCollection->isEmpty(); // true
     * 
     * $filledCollection = new Collection([1, 2, 3]);
     * $filledCollection->isEmpty(); // false
     * ```
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * 将数据集转换为原生数组
     * 
     * 将Collection实例转换为PHP原生数组，并递归地将嵌套的Collection对象也转换为数组。
     * 这是一个深转换操作，确保返回的完全是原生PHP数组。
     * 
     * @return array 转换后的原生数组
     * @example
     * ```php
     * $collection = new Collection(['a' => 1, 'b' => 2]);
     * $array = $collection->toArray(); // ['a' => 1, 'b' => 2]
     * ```
     */
    public function toArray(): array
    {
        // 使用array_map递归地将Collection对象转换为数组
        return array_map(function ($value) {
            return $value instanceof Collection ? $value->toArray() : $value;
        }, $this->items);
    }

    /**
     * 获取数据集内部的原始数组
     * 
     * 返回Collection实例内部存储的原始数组，不进行任何转换或处理。
     * 注意：与toArray()不同，此方法不会递归转换嵌套对象。
     * 
     * @return array Collection内部存储的原始数组
     * @example
     * ```php
     * $collection = new Collection(['a' => 1, 'b' => 2]);
     * $rawArray = $collection->all(); // ['a' => 1, 'b' => 2]
     * ```
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * 合并当前数据集与另一个数据集合
     * 
     * 将传入的数据与当前Collection实例中的数据合并，并返回一个新的Collection实例。
     * 内部使用PHP的array_merge函数实现，后面的数组会覆盖前面相同键名的值（对于数字索引会重新索引）。
     * 
     * @param mixed $items 要合并的数据，可以是数组或Collection实例
     * @return static 合并后的新Collection实例
     * @example
     * ```php
     * $collection1 = new Collection(['a' => 1, 'b' => 2]);
     * $collection2 = $collection1->merge(['c' => 3, 'b' => 4]);
     * // 结果: ['a' => 1, 'b' => 4, 'c' => 3]
     * ```
     */
    public function merge(mixed $items): static
    {
        // 合并当前数组与转换后的输入数据，返回新的Collection实例
        return new static(array_merge($this->items, $this->convertToArray($items)));
    }

    /**
     * 将数据数组转换为以指定字段为索引的关联数组
     * 
     * 将一维或二维数组转换为以指定字段值为键名的关联数组，便于快速查找。
     * 如果不指定索引键，将尝试使用'id'字段（数组项）或getPk()方法返回的主键（对象项）。
     * 
     * @param mixed $items 可选，要处理的数据，默认为当前Collection的数据
     * @param string|null &$indexKey 可选，作为索引的字段名，会被修改为实际使用的键名
     * @return array 以指定字段为索引的关联数组
     * @example
     * ```php
     * // 使用默认'id'字段
     * $collection = new Collection([
     *     ['id' => 1, 'name' => 'John'],
     *     ['id' => 2, 'name' => 'Jane']
     * ]);
     * $dict = $collection->dictionary();
     * // 结果: [
     * //   1 => ['id' => 1, 'name' => 'John'],
     * //   2 => ['id' => 2, 'name' => 'Jane']
     * // ]
     * 
     * // 指定索引键
     * $indexKey = 'name';
     * $dict = $collection->dictionary(null, $indexKey);
     * // 结果: [
     * //   'John' => ['id' => 1, 'name' => 'John'],
     * //   'Jane' => ['id' => 2, 'name' => 'Jane']
     * // ]
     * ```
     */
    public function dictionary(mixed $items = null,string|null &$indexKey = null): array
    {
        // 处理输入数据类型
        if ($items instanceof self) {
            $items = $items->all();
        }

        // 如果没有提供数据，使用当前Collection的数据
        $items = is_null($items) ? $this->items : $items;

        // 如果数据不为空且没有指定索引键，尝试使用默认键
        if ($items && empty($indexKey)) {
            // 对于数组项使用'id'字段，对于对象项使用getPk()方法获取主键
            $indexKey = is_array($items[0]) ? 'id' : $items[0]->getPk();
        }

        // 如果索引键存在且为字符串，使用array_column创建关联数组
        if (isset($indexKey) && is_string($indexKey)) {
            return array_column($items, null, $indexKey);
        }

        // 无法创建字典时返回原始数据
        return $items;
    }

    /**
     * 计算两个集合的差集
     * 
     * 返回当前集合中存在但在给定集合中不存在的元素，组成新的Collection实例。
     * 支持简单数组和关联数组（对象集合）的比较。对于关联数组，可以指定比较的键名。
     * 
     * @param mixed $items 要比较的集合或数组
     * @param string|null $indexKey 可选，用于比较的键名，仅对关联数组有效
     * @return static 包含差集元素的新Collection实例
     * @example
     * ```php
     * // 简单数组的差集
     * $collection = new Collection([1, 2, 3, 4]);
     * $diff = $collection->diff([3, 4, 5]);
     * // 结果: [1, 2]
     * 
     * // 关联数组的差集（通过指定键名）
     * $collection = new Collection([
     *     ['id' => 1, 'name' => 'John'],
     *     ['id' => 2, 'name' => 'Jane']
     * ]);
     * $diff = $collection->diff([
     *     ['id' => 2, 'name' => 'Jane'],
     *     ['id' => 3, 'name' => 'Bob']
     * ], 'id');
     * // 结果: [['id' => 1, 'name' => 'John']]
     * ```
     */
    public function diff(mixed $items,?string $indexKey = null): static
    {
        if ($this->isEmpty() || is_scalar($this->items[0])) {
            return new static(array_diff($this->items, $this->convertToArray($items)));
        }

        $diff       = [];
        $dictionary = $this->dictionary($items, $indexKey);

        if (is_string($indexKey)) {
            foreach ($this->items as $item) {
                if (!isset($dictionary[$item[$indexKey]])) {
                    $diff[] = $item;
                }
            }
        }

        return new static($diff);
    }

    /**
     * 计算两个集合的交集
     * 
     * 返回同时存在于当前集合和给定集合中的元素，组成新的Collection实例。
     * 支持简单数组和关联数组（对象集合）的比较。对于关联数组，可以指定比较的键名。
     * 
     * @param mixed $items 要比较的集合或数组
     * @param string|null $indexKey 可选，用于比较的键名，仅对关联数组有效
     * @return static 包含交集元素的新Collection实例
     * @example
     * ```php
     * // 简单数组的交集
     * $collection = new Collection([1, 2, 3, 4]);
     * $intersect = $collection->intersect([3, 4, 5]);
     * // 结果: [3, 4]
     * 
     * // 关联数组的交集（通过指定键名）
     * $collection = new Collection([
     *     ['id' => 1, 'name' => 'John'],
     *     ['id' => 2, 'name' => 'Jane']
     * ]);
     * $intersect = $collection->intersect([
     *     ['id' => 2, 'name' => 'Jane'],
     *     ['id' => 3, 'name' => 'Bob']
     * ], 'id');
     * // 结果: [['id' => 2, 'name' => 'Jane']]
     * ```
     */
    public function intersect(mixed $items, ?string $indexKey = null): static
    {
        if ($this->isEmpty() || is_scalar($this->items[0])) {
            return new static(array_intersect($this->items, $this->convertToArray($items)));
        }

        $intersect  = [];
        $dictionary = $this->dictionary($items, $indexKey);

        if (is_string($indexKey)) {
            foreach ($this->items as $item) {
                if (isset($dictionary[$item[$indexKey]])) {
                    $intersect[] = $item;
                }
            }
        }

        return new static($intersect);
    }

    /**
     * 交换数组中的键和值
     *
     * 将当前集合中的键名和对应的值互换位置，返回包含交换结果的新Collection实例。
     * 注意：所有的值必须是可哈希的（即可以作为键名），通常是标量类型。
     * 
     * @return static 键值交换后的新Collection实例
     * @example
     * ```php
     * $collection = new Collection(['a' => 1, 'b' => 2]);
     * $flipped = $collection->flip();
     * // 结果: [1 => 'a', 2 => 'b']
     * ```
     */
    public function flip(): static
    {
        // 使用PHP内置的array_flip函数交换键值，并创建新的Collection实例
        return new static(array_flip($this->items));
    }

    /**
     * 返回数组中所有的键名
     *
     * 提取当前集合中的所有键名，返回包含这些键名的新Collection实例。
     * 
     * @return static 包含所有键名的新Collection实例
     * @example
     * ```php
     * $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
     * $keys = $collection->keys();
     * // 结果: ['a', 'b', 'c']
     * ```
     */
    public function keys(): static
    {
        // 使用PHP内置的array_keys函数获取所有键名，并创建新的Collection实例
        return new static(array_keys($this->items));
    }

    /**
     * 返回数组中所有的值组成的新Collection实例
     *
     * 提取当前集合中的所有值，返回包含这些值的新Collection实例。
     * 对于数字索引的数组，会重新索引为连续的数字键。
     * 
     * @return static 包含所有值的新Collection实例
     * @example
     * ```php
     * $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
     * $values = $collection->values();
     * // 结果: [1, 2, 3]
     * ```
     */
    public function values(): static
    {
        // 使用PHP内置的array_values函数获取所有值并重新索引，并创建新的Collection实例
        return new static(array_values($this->items));
    }

    /**
     * 删除数组的最后一个元素（出栈）
     *
     * @return mixed
     */
    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    /**
     * 通过使用用户自定义函数，以字符串返回数组
     *
     * @param $callback 调用方法
     * @param $initial
     * @return mixed
     */
    public function reduce(callable $callback,mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * 以相反的顺序返回数组。
     *
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    /**
     * 删除数组中首个元素，并返回被删除元素的值
     *
     * @return mixed
     */
    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    /**
     * 在数组结尾插入一个元素
     * @param  $value 元素
     * @param $key   KEY
     * @return void
     */
    public function push(mixed $value,?string $key = null): void
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    /**
     * 把一个数组分割为新的数组块.
     *
     * @param int  $size 块大小
     * @param bool $preserveKeys
     * @return static
     */
    public function chunk(int $size, bool $preserveKeys = false): static
    {
        $chunks = [];

        foreach (array_chunk($this->items, $size, $preserveKeys) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * 在数组开头插入一个元素
     * @param  $value 元素
     * @param $key   KEY
     * @return void
     */
    public function unshift(mixed $value,?string $key = null): void
    {
        if (is_null($key)) {
            array_unshift($this->items, $value);
        } else {
            $this->items = [$key => $value] + $this->items;
        }
    }

    /**
     * 对集合中的每个元素执行回调函数
     *
     * 遍历集合中的每个元素，并对其应用指定的回调函数。支持提前中断遍历和修改元素值。
     * 注意：此方法会修改原始集合，并返回同一个实例（支持链式调用）。
     * 
     * @param callable $callback 应用于每个元素的回调函数，接收两个参数：元素值和键名
     * @return $this 返回自身，支持链式调用
     * @example
     * ```php
     * // 遍历并打印每个元素
     * $collection->each(function($item, $key) {
     *     echo "Key: $key, Value: $item<br>";
     * });
     * 
     * // 修改元素值
     * $collection->each(function($item, $key) {
     *     return $item * 2; // 非对象元素的值将被更新
     * });
     * 
     * // 提前中断遍历
     * $collection->each(function($item, $key) {
     *     echo "$item<br>";
     *     return $item < 10; // 当item >= 10时停止遍历
     * });
     * ```
     */
    public function each(callable $callback): Collection
    {
        foreach ($this->items as $key => $item) {
            $result = $callback($item, $key);

            if (false === $result) {
                break;
            } elseif (!is_object($item)) {
                $this->items[$key] = $result;
            }
        }

        return $this;
    }

    /**
     * 使用回调函数转换集合中的所有元素
     *
     * 对集合中的每个元素应用指定的回调函数，并返回包含转换结果的新Collection实例。
     * 注意：此方法不会修改原始集合，而是返回新集合。
     * 
     * @param callable $callback 应用于每个元素的转换函数，接收元素值作为参数
     * @return static 包含转换后元素的新Collection实例
     * @example
     * ```php
     * $collection = new Collection([1, 2, 3, 4]);
     * $doubled = $collection->map(function($item) {
     *     return $item * 2; // 将每个元素乘以2
     * });
     * // 结果: [2, 4, 6, 8]
     * ```
     */
    public function map(callable $callback): static
    {
        // 使用PHP内置的array_map函数转换元素，并创建新的Collection实例
        return new static(array_map($callback, $this->items));
    }

    /**
     * 使用回调函数过滤集合中的元素
     *
     * 根据回调函数的返回值过滤集合中的元素，仅保留回调返回true的元素。
     * 如果不提供回调函数，则默认过滤掉所有等值于false的元素。
     * 
     * @param callable|null $callback 可选，过滤函数，接收元素值作为参数
     * @return static 包含过滤后元素的新Collection实例
     * @example
     * ```php
     * // 使用回调函数过滤
     * $collection = new Collection([1, 2, 3, 4, 5]);
     * $evens = $collection->filter(function($item) {
     *     return $item % 2 === 0; // 只保留偶数
     * });
     * // 结果: [2, 4]
     * 
     * // 默认过滤（移除false、null、0、空字符串等）
     * $collection = new Collection([0, 1, false, 2, '', 3]);
     * $filtered = $collection->filter();
     * // 结果: [1, 2, 3]
     * ```
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback));
        }

        return new static(array_filter($this->items));
    }

    /**
     * 根据字段条件过滤数组中的元素
     * @param $field    字段名
     * @param  $operator 操作符
     * @param  $value    数据
     * @return static
     */
    public function where(string $field, $operator, $value = null): static
    {
        if (is_null($value)) {
            $value    = $operator;
            $operator = '=';
        }

        return $this->filter(function ($data) use ($field, $operator, $value) {
            if (strpos($field, '.')) {
                [$field, $relation] = explode('.', $field);

                $result = $data[$field][$relation] ?? null;
            } else {
                $result = $data[$field] ?? null;
            }

            return match (strtolower($operator)) {
                '===' => $result === $value,
                '!==' => $result !== $value,
                '!=', '<>' => $result != $value,
                '>' => $result > $value,
                '>=' => $result >= $value,
                '<' => $result < $value,
                '<=' => $result <= $value,
                'like' => is_string($result) && false !== strpos($result, $value),
                'not like' => is_string($result) && false === strpos($result, $value),
                'in' => is_scalar($result) && in_array($result, $value, true),
                'not in' => is_scalar($result) && !in_array($result, $value, true),
                'between' => (function() use ($result, $value) {
                    [$min, $max] = is_string($value) ? explode(',', $value) : $value;
                    return is_scalar($result) && $result >= $min && $result <= $max;
                })(),
                'not between' => (function() use ($result, $value) {
                    [$min, $max] = is_string($value) ? explode(',', $value) : $value;
                    return is_scalar($result) && $result > $max || $result < $min;
                })(),
                '==', '=' => $result == $value,
                default => $result == $value,
            };
        });
    }

    /**
     * LIKE过滤
     * @param $field 字段名
     * @param $value 数据
     * @return static
     */
    public function whereLike(string $field, string $value): static
    {
        return $this->where($field, 'like', $value);
    }

    /**
     * NOT LIKE过滤
     * @param string $field 字段名
     * @param string $value 数据
     * @return static
     */
    public function whereNotLike(string $field, string $value): static
    {
        return $this->where($field, 'not like', $value);
    }

    /**
     * IN过滤
     * @param string $field 字段名
     * @param array  $value 数据
     * @return static
     */
    public function whereIn(string $field, array $value): static
    {
        return $this->where($field, 'in', $value);
    }

    /**
     * NOT IN过滤
     * @param string $field 字段名
     * @param array  $value 数据
     * @return static
     */
    public function whereNotIn(string $field, array $value): static
    {
        return $this->where($field, 'not in', $value);
    }

    /**
     * BETWEEN 过滤
     * @param string $field 字段名
     * @param  $value 数据
     * @return static
     */
    public function whereBetween(string $field, $value): static
    {
        return $this->where($field, 'between', $value);
    }

    /**
     * NOT BETWEEN 过滤
     * @param string $field 字段名
     * @param  $value 数据
     * @return static
     */
    public function whereNotBetween(string $field, $value): static
    {
        return $this->where($field, 'not between', $value);
    }

    /**
     * 返回数据中指定的一列
     * @param string $columnKey 键名
     * @param string $indexKey  作为索引值的列
     * @return array
     */
    public function column(string $columnKey, ?string $indexKey = null): array
    {
        return array_column($this->items, $columnKey, $indexKey);
    }

    /**
     * 对数组排序
     *
     * @param $callback 回调
     * @return static
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        $callback = $callback ?: function ($a, $b) {
            return $a == $b ? 0 : (($a < $b) ? -1 : 1);
        };

        uasort($items, $callback);

        return new static($items);
    }

    /**
     * 指定字段排序
     * @param string $field 排序字段
     * @param string $order 排序
     * @return $this
     */
    public function order(string $field, ?string $order = null): Collection
    {
        return $this->sort(function ($a, $b) use ($field, $order) {
            $fieldA = $a[$field] ?? null;
            $fieldB = $b[$field] ?? null;

            return 'desc' == strtolower($order) ? strcmp($fieldB, $fieldA) : strcmp($fieldA, $fieldB);
        });
    }

    /**
     * 将数组打乱
     *
     * @return static
     */
    public function shuffle(): static
    {
        $items = $this->items;

        shuffle($items);

        return new static($items);
    }

    /**
     * 获取集合中的第一个元素
     *
     * 如果提供了回调函数，则返回第一个通过回调测试的元素；否则返回集合的第一个元素。
     * 当集合为空或没有元素通过回调测试时，返回默认值。
     * 
     * @param callable|null $callback 可选，用于筛选元素的回调函数
     * @param mixed $default 可选，当没有符合条件的元素时返回的默认值
     * @return mixed 找到的元素或默认值
     */
    public function first(?callable $callback = null, $default = null): mixed
    {
        return Arr::first($this->items, $callback, $default);
    }

    /**
     * 获取集合中的最后一个元素
     *
     * 如果提供了回调函数，则返回最后一个通过回调测试的元素；否则返回集合的最后一个元素。
     * 当集合为空或没有元素通过回调测试时，返回默认值。
     * 
     * @param callable|null $callback 可选，用于筛选元素的回调函数
     * @param mixed $default 可选，当没有符合条件的元素时返回的默认值
     * @return mixed 找到的元素或默认值
     */
    public function last(?callable $callback = null, $default = null): mixed
    {
        return Arr::last($this->items, $callback, $default);
    }

    /**
     * 截取数组中的一部分元素
     *
     * 从指定位置开始，截取指定长度的元素，返回包含这些元素的新Collection实例。
     * 
     * @param int $offset 起始位置，从0开始计数，支持负数表示从末尾开始计数
     * @param int|null $length 可选，截取的长度，为null时截取到数组末尾
     * @param bool $preserveKeys 是否保留原始键名，默认为false
     * @return static 包含截取元素的新Collection实例
     */
    public function slice(int $offset, ?int $length = null, bool $preserveKeys = false): static
    {
        return new static(array_slice($this->items, $offset, $length, $preserveKeys));
    }

    /**
     * 检查一个偏移位置是否存在
     *
     * 实现ArrayAccess接口，允许使用isset()和empty()函数检查集合中的元素是否存在
     * 
     * @param mixed $offset 偏移位置/键名
     * @return bool 如果偏移位置存在则返回true，否则返回false
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    /**
     * 获取一个偏移位置的值
     *
     * 实现ArrayAccess接口，允许使用$collection[$offset]语法获取集合中的元素
     * 
     * @param mixed $offset 偏移位置/键名
     * @return mixed 元素值
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * 设置一个偏移位置的值
     *
     * 实现ArrayAccess接口，允许使用$collection[$offset] = $value语法设置集合中的元素
     * 当未指定偏移位置时，将元素添加到数组末尾
     * 
     * @param mixed $offset 偏移位置/键名，为null时添加到数组末尾
     * @param mixed $value 要设置的值
     */
    public function offsetSet(mixed $offset,mixed $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * 移除一个偏移位置的值
     *
     * 实现ArrayAccess接口，允许使用unset($collection[$offset])语法移除集合中的元素
     * 
     * @param mixed $offset 要移除的偏移位置/键名
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * 获取数组元素总数
     *
     * 实现Countable接口，允许对Collection实例使用count()函数获取元素数量
     * 
     * @return int 集合中的元素数量
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * 获取迭代器
     *
     * 实现IteratorAggregate接口，允许对Collection实例使用foreach循环遍历元素
     * 返回的ArrayIterator支持所有PHP迭代器功能
     * 
     * @return \ArrayIterator 可用于遍历集合元素的迭代器
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * 获取JSON序列化结果
     *
     * 实现JsonSerializable接口，允许直接将Collection实例传递给json_encode()函数
     * 内部调用toArray()方法确保正确序列化所有数据
     * 
     * @return array 可JSON序列化的数据结构
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 转换当前数据集为JSON字符串
     * 
     * 将Collection实例转换为JSON格式的字符串，支持自定义JSON编码选项。
     * 
     * @param int $options JSON编码选项，默认为JSON_UNESCAPED_UNICODE以保留中文字符
     * @return string JSON格式的字符串
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     *   to String
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * 取指定字段的值（Laravel pluck 等价）
     *
     * 与 column() 的区别：column() 直接返回数组，pluck() 返回 Collection 可继续链式。
     *
     * @param string      $value 取值字段
     * @param string|null $key   作为结果键的字段
     * @return static
     * @example collect($rows)->pluck('real_name');        // 值列表
     *          collect($rows)->pluck('real_name', 'id');   // id => real_name
     */
    public function pluck(string $value, ?string $key = null): static
    {
        $out = [];
        foreach ($this->items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $val = $item[$value] ?? null;
            if ($key !== null && array_key_exists($key, $item)) {
                $out[$item[$key]] = $val;
            } else {
                $out[] = $val;
            }
        }

        return new static($out);
    }

    /**
     * 去重
     *
     * @param string|null $key 按某字段去重；null 时按元素本身
     * @return static
     */
    public function unique(?string $key = null): static
    {
        $seen = [];
        $out  = [];
        foreach ($this->items as $k => $item) {
            $val = $key === null ? $item : (is_array($item) ? ($item[$key] ?? null) : null);
            $sig = is_scalar($val) || $val === null ? (string) $val : md5(serialize($val));
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $out[$k]    = $item;
        }

        return new static($out);
    }

    /**
     * 按字段/回调分组
     *
     * @param string|callable $groupBy 字段名或 fn($item, $key)
     * @return static 顶层键为分组值，值为该组元素数组
     * @example collect($rows)->groupBy('department')->all();
     */
    public function groupBy(string|callable $groupBy): static
    {
        $out = [];
        foreach ($this->items as $k => $item) {
            $val = is_callable($groupBy)
                ? $groupBy($item, $k)
                : (is_array($item) ? ($item[$groupBy] ?? null) : null);
            $key = is_scalar($val) || $val === null ? (string) $val : md5(serialize($val));
            $out[$key][] = $item;
        }

        return new static($out);
    }

    /**
     * 用某字段作为键重建数据集
     *
     * @param string|callable $keyBy 字段名或 fn($item, $key)
     * @return static
     */
    public function keyBy(string|callable $keyBy): static
    {
        $out = [];
        foreach ($this->items as $k => $item) {
            $val = is_callable($keyBy)
                ? $keyBy($item, $k)
                : (is_array($item) ? ($item[$keyBy] ?? null) : null);
            if ($val === null) {
                $out[$k] = $item;
                continue;
            }
            $out[is_scalar($val) ? $val : md5(serialize($val))] = $item;
        }

        return new static($out);
    }

    /**
     * 取字段/回调结果并重建键值（Laravel mapWithKeys）
     *
     * @param callable $callback fn($item, $key): array
     * @return static
     */
    public function mapWithKeys(callable $callback): static
    {
        $out = [];
        foreach ($this->items as $k => $item) {
            $pair = $callback($item, $k);
            if (!is_array($pair)) {
                continue;
            }
            foreach ($pair as $nk => $nv) {
                $out[$nk] = $nv;
            }
        }

        return new static($out);
    }

    /**
     * 求和
     *
     * @param string|callable|null $callback 字段名 / fn($item, $key) / null（对元素本身求和）
     * @return int|float
     */
    public function sum(string|callable|null $callback = null): int|float
    {
        $total = 0;
        foreach ($this->items as $k => $item) {
            $val = $this->resolveValue($item, $k, $callback);
            if (is_numeric($val)) {
                $total += $val + 0;
            }
        }

        return $total;
    }

    /**
     * 求平均
     *
     * @param string|callable|null $callback 同 sum
     * @return int|float|null 空数据集返回 null
     */
    public function avg(string|callable|null $callback = null): int|float|null
    {
        $nums = [];
        foreach ($this->items as $k => $item) {
            $val = $this->resolveValue($item, $k, $callback);
            if (is_numeric($val)) {
                $nums[] = $val + 0;
            }
        }

        return $nums === [] ? null : array_sum($nums) / count($nums);
    }

    /**
     * 求平均（avg 的别名，对齐 Laravel average）
     *
     * @param string|callable|null $callback 同 sum
     * @return int|float|null
     */
    public function average(string|callable|null $callback = null): int|float|null
    {
        return $this->avg($callback);
    }

    /**
     * 求最小值
     *
     * @param string|callable|null $callback 同 sum
     * @return int|float|null
     */
    public function min(string|callable|null $callback = null): int|float|null
    {
        $nums = $this->numericValues($callback);

        return $nums === [] ? null : min($nums);
    }

    /**
     * 求最大值
     *
     * @param string|callable|null $callback 同 sum
     * @return int|float|null
     */
    public function max(string|callable|null $callback = null): int|float|null
    {
        $nums = $this->numericValues($callback);

        return $nums === [] ? null : max($nums);
    }

    /**
     * 是否包含
     *
     * 单参数：判断值是否在集合中；三参数：按 where 的条件语义判断。
     *
     * @param string|callable|mixed $key      字段名 / 回调 / 直接比较的值
     * @param mixed                 $operator 操作符（两参时省略，视为等值比较）
     * @param mixed                 $value    比较值
     * @return bool
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (is_callable($key)) {
            foreach ($this->items as $k => $item) {
                if ($key($item, $k)) {
                    return true;
                }
            }

            return false;
        }

        $argc = func_num_args();
        if ($argc === 1) {
            foreach ($this->items as $item) {
                if ($item === $key) {
                    return true;
                }
            }

            return false;
        }

        if ($argc === 2) {
            $value    = $operator;
            $operator = '=';
        }

        return (new static($this->items))->where((string) $key, $operator, $value)->count() > 0;
    }

    /**
     * 取第一个满足条件的元素
     *
     * @param string $field    字段名
     * @param mixed  $operator 操作符（两参时省略，视为等值比较）
     * @param mixed  $value    比较值
     * @return mixed 未命中返回 null
     */
    public function firstWhere(string $field, mixed $operator = null, mixed $value = null): mixed
    {
        $argc = func_num_args();
        if ($argc === 1) {
            return $this->first();
        }
        if ($argc === 2) {
            $value    = $operator;
            $operator = '=';
        }

        return (new static($this->items))->where($field, $operator, $value)->first();
    }

    /**
     * 按字段/回调排序（保留原键）
     *
     * @param string|callable $callback   字段名或 fn($item, $key)
     * @param bool            $descending 是否倒序
     * @return static
     */
    public function sortBy(string|callable $callback, bool $descending = false): static
    {
        $decorated = [];
        foreach ($this->items as $k => $item) {
            $decorated[$k] = [
                is_callable($callback) ? $callback($item, $k) : (is_array($item) ? ($item[$callback] ?? null) : null),
                $item,
            ];
        }

        uasort($decorated, function (array $a, array $b) use ($descending): int {
            $cmp = $a[0] <=> $b[0];

            return $descending ? -$cmp : $cmp;
        });

        $out = [];
        foreach ($decorated as $k => $pair) {
            $out[$k] = $pair[1];
        }

        return new static($out);
    }

    /**
     * 按字段/回调倒序排序
     *
     * @param string|callable $callback 字段名或 fn($item, $key)
     * @return static
     */
    public function sortByDesc(string|callable $callback): static
    {
        return $this->sortBy($callback, true);
    }

    /**
     * 取前 N 条（负数为从尾部取）
     *
     * @param int $limit 条数
     * @return static
     */
    public function take(int $limit): static
    {
        return new static(array_slice($this->items, $limit >= 0 ? 0 : $limit, $limit >= 0 ? $limit : null, true));
    }

    /**
     * 跳过前 N 条
     *
     * @param int $count 跳过条数
     * @return static
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, max(0, $count), null, true));
    }

    /**
     * 按页码取一页（页码从 1 开始）
     *
     * @param int $page    页码
     * @param int $perPage 每页条数
     * @return static
     */
    public function forPage(int $page, int $perPage): static
    {
        $page     = max(1, $page);
        $perPage  = max(1, $perPage);

        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }

    /**
     * map 后拍平一层（Laravel flatMap）
     *
     * @param callable $callback fn($item, $key): array
     * @return static
     */
    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->collapse();
    }

    /**
     * 拍平一层
     *
     * @return static
     */
    public function collapse(): static
    {
        $out = [];
        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }
            if (is_array($item)) {
                foreach ($item as $v) {
                    $out[] = $v;
                }
                continue;
            }
            $out[] = $item;
        }

        return new static($out);
    }

    /**
     * 条件为真时对自身执行回调
     *
     * @param mixed         $value   条件
     * @param callable      $callback fn($self, $value)
     * @param callable|null $default  条件为假时的回调
     * @return static
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): static
    {
        if ($value) {
            return $callback($this, $value) ?? $this;
        }
        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /**
     * 条件为假时对自身执行回调
     *
     * @param mixed         $value    条件
     * @param callable      $callback fn($self, $value)
     * @param callable|null $default  条件为真时的回调
     * @return static
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null): static
    {
        return $this->when(!$value, $callback, $default);
    }

    /**
     * 对自身执行副作用回调后原样返回
     *
     * @param callable $callback fn($self)
     * @return static
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * 把自身交给回调，返回回调结果
     *
     * @param callable $callback fn($self)
     * @return mixed
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * 拼接为字符串
     *
     * @param string      $glue  分隔符
     * @param string|null $value 取该字段拼接；null 时拼接元素本身
     * @return string
     */
    public function implode(string $glue = '', ?string $value = null): string
    {
        $pieces = $value === null ? $this->items : $this->pluck($value)->all();

        return implode($glue, array_map(static fn($v): string => (string) $v, $pieces));
    }

    /**
     * 查找值首次出现的键
     *
     * @param mixed $value  要查找的值
     * @param bool  $strict 是否严格比较
     * @return int|string|false
     */
    public function search(mixed $value, bool $strict = false): int|string|false
    {
        return array_search($value, $this->items, $strict);
    }

    /**
     * 随机取一条或多条
     *
     * @param int|null $number 条数；null 时返回单条
     * @return mixed 单条为元素本身，多条为 static
     */
    public function random(?int $number = null): mixed
    {
        if ($this->items === []) {
            throw new \RuntimeException('random 不能用于空数据集');
        }
        if ($number === null) {
            return $this->items[array_rand($this->items)];
        }
        if ($number < 1) {
            throw new \InvalidArgumentException('random 的 number 必须 >= 1');
        }

        $picked = (array) array_rand($this->items, min($number, count($this->items)));
        $out    = [];
        foreach ($picked as $k) {
            $out[] = $this->items[$k];
        }

        return new static($out);
    }

    /**
     * 每隔 N 条取一条
     *
     * @param int $step   步长
     * @param int $offset 起始偏移
     * @return static
     */
    public function nth(int $step, int $offset = 0): static
    {
        if ($step < 1) {
            throw new \InvalidArgumentException('nth 的 step 必须 >= 1');
        }

        $out = [];
        $i   = 0;
        foreach ($this->items as $k => $item) {
            if ($i % $step === $offset) {
                $out[$k] = $item;
            }
            $i++;
        }

        return new static($out);
    }

    /**
     * 按条件拆成两部分
     *
     * @param string|callable $key 字段名（取真值）或 fn($item, $key): bool
     * @return array{0: static, 1: static} [满足, 不满足]
     */
    public function partition(string|callable $key): array
    {
        $yes = [];
        $no  = [];
        foreach ($this->items as $k => $item) {
            $ok = is_callable($key)
                ? (bool) $key($item, $k)
                : (bool) (is_array($item) ? ($item[$key] ?? false) : false);
            if ($ok) {
                $yes[$k] = $item;
                continue;
            }
            $no[$k] = $item;
        }

        return [new static($yes), new static($no)];
    }

    /**
     * 逐个位置合并多个数组
     *
     * @param array|Collection ...$arrays 其它列
     * @return static 每项为 [自身项, 其它列项...]
     */
    public function zip(mixed ...$arrays): static
    {
        $columns = [$this->items];
        foreach ($arrays as $arr) {
            $columns[] = $arr instanceof self ? $arr->all() : (array) $arr;
        }

        return new static(array_map(null, ...$columns));
    }

    /**
     * 把数据集填到指定长度
     *
     * @param int   $size  目标长度（正数右侧补、负数左侧补）
     * @param mixed $value 填充值
     * @return static
     */
    public function pad(int $size, mixed $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    /**
     * 取指定键的值
     *
     * @param int|string $key     键
     * @param mixed      $default 缺省值
     * @return mixed
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * 设置指定键的值（返回新实例，不改自身）
     *
     * @param int|string $key   键
     * @param mixed      $value 值
     * @return static
     */
    public function put(int|string $key, mixed $value): static
    {
        $items       = $this->items;
        $items[$key] = $value;

        return new static($items);
    }

    /**
     * 移除指定键（返回新实例，不改自身）
     *
     * @param int|string $key 键
     * @return static
     */
    public function forget(int|string $key): static
    {
        $items = $this->items;
        unset($items[$key]);

        return new static($items);
    }

    /**
     * 是否含某键
     *
     * @param int|string $key 键
     * @return bool
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * 按字段/回调取单个元素的值（内部辅助）
     *
     * @param mixed                 $item     元素
     * @param int|string            $key      元素键
     * @param string|callable|null $callback 字段名 / 回调 / null
     * @return mixed
     */
    protected function resolveValue(mixed $item, int|string $key, string|callable|null $callback): mixed
    {
        if ($callback === null) {
            return $item;
        }
        if (is_callable($callback)) {
            return $callback($item, $key);
        }

        return is_array($item) ? ($item[$callback] ?? null) : null;
    }

    /**
     * 取所有数值型值（内部辅助，供 min/max 复用）
     *
     * @param string|callable|null $callback 同 sum
     * @return array 数值列表
     */
    protected function numericValues(string|callable|null $callback = null): array
    {
        $nums = [];
        foreach ($this->items as $k => $item) {
            $val = $this->resolveValue($item, $k, $callback);
            if (is_numeric($val)) {
                $nums[] = $val + 0;
            }
        }

        return $nums;
    }

    /**
     * 将输入数据转换为标准数组格式
     * 
     * 处理不同类型的输入数据，确保返回标准的PHP数组。
     * 对于Collection实例，返回其内部数据数组；对于其他类型，使用类型转换。
     * 
     * @param mixed $items 要转换的数据
     * @return array 转换后的标准数组
     */
    protected function convertToArray(mixed $items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }
		
        return (array) $items;
    }
}

