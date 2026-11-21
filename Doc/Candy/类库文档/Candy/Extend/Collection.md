# Collection

## 类简介
数据集管理类，提供丰富的数组操作API和链式调用支持，实现了PHP常用接口以便更好地集成到PHP生态系统中。

## 命名空间
```php
namespace Candy\Extend;
```

## 类定义
```php
/**
 * 数据集管理类
 *
 * 提供丰富的数组操作API和链式调用支持，实现了PHP常用接口以便更好地集成到PHP生态系统中。
 * 支持数组访问、计数、迭代和JSON序列化等功能。
 */
final class Collection implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    // 类实现...
}
```

## 主要方法

### __construct()
```php
/**
 * 构造函数
 *
 * @param mixed $items 初始数据集，可以是数组或其他可转换为数组的类型
 */
public function __construct(mixed $items = [])
```

### make()
```php
/**
 * 创建Collection实例的静态工厂方法
 *
 * @param mixed $items 初始数据集
 * @return static 新的Collection实例
 */
public static function make(mixed $items = []): static
```

### isEmpty()
```php
/**
 * 检查集合是否为空
 *
 * @return bool 如果集合为空则返回true，否则返回false
 */
public function isEmpty(): bool
```

### toArray()
```php
/**
 * 将集合转换为数组
 *
 * @return array 集合中的所有元素组成的数组
 */
public function toArray(): array
```

### all()
```php
/**
 * 获取集合中的所有元素
 *
 * @return array 集合中的所有元素组成的数组
 */
public function all(): array
```

### merge()
```php
/**
 * 合并另一个集合或数组到当前集合
 *
 * @param mixed $items 要合并的集合或数组
 * @return static 包含合并结果的新Collection实例
 */
public function merge(mixed $items): static
```

### dictionary()
```php
/**
 * 将集合转换为字典格式（键值对）
 *
 * @param string $keyField 作为键的字段名
 * @param string $valueField 作为值的字段名
 * @return static 转换后的字典格式集合
 */
public function dictionary(string $keyField, string $valueField): static
```

### diff()
```php
/**
 * 计算当前集合与另一个集合的差集
 *
 * @param mixed $items 要比较的集合或数组
 * @return static 包含差集元素的新Collection实例
 */
public function diff(mixed $items): static
```

### intersect()
```php
/**
 * 计算当前集合与另一个集合的交集
 *
 * @param mixed $items 要比较的集合或数组
 * @return static 包含交集元素的新Collection实例
 */
public function intersect(mixed $items): static
```

### flip()
```php
/**
 * 交换集合中的键和值
 *
 * @return static 键值交换后的新Collection实例
 */
public function flip(): static
```

### keys()
```php
/**
 * 获取集合中的所有键名
 *
 * @return static 包含所有键名的新Collection实例
 */
public function keys(): static
```

### values()
```php
/**
 * 获取集合中的所有值
 *
 * @return static 包含所有值的新Collection实例
 */
public function values(): static
```

### pop()
```php
/**
 * 删除并返回集合末尾的元素
 *
 * @return mixed 被删除的末尾元素
 */
public function pop(): mixed
```

### reduce()
```php
/**
 * 使用回调函数迭代地减少集合到单个值
 *
 * @param callable $callback 迭代函数，接收累计值和当前元素作为参数
 * @param mixed $initial 初始值
 * @return mixed 最终的累计结果
 */
public function reduce(callable $callback, $initial = null): mixed
```

### reverse()
```php
/**
 * 反转集合中的元素顺序
 *
 * @return static 元素顺序反转后的新Collection实例
 */
public function reverse(): static
```

### shift()
```php
/**
 * 删除数组中首个元素，并返回被删除元素的值
 *
 * @return mixed
 */
public function shift(): mixed
```

### push()
```php
/**
 * 在数组结尾插入一个元素
 * @param  $value 元素
 * @param $key   KEY
 * @return void
 */
public function push(mixed $value,?string $key = null): void
```

### chunk()
```php
/**
 * 把一个数组分割为新的数组块.
 *
 * @param int  $size 块大小
 * @param bool $preserveKeys
 * @return static
 */
public function chunk(int $size, bool $preserveKeys = false): static
```

### unshift()
```php
/**
 * 在数组开头插入一个元素
 * @param  $value 元素
 * @param $key   KEY
 * @return void
 */
public function unshift(mixed $value,?string $key = null): void
```

### each()
```php
/**
 * 对集合中的每个元素执行回调函数
 *
 * 遍历集合中的每个元素，并对其应用指定的回调函数。支持提前中断遍历和修改元素值。
 * 注意：此方法会修改原始集合，并返回同一个实例（支持链式调用）。
 * 
 * @param callable $callback 应用于每个元素的回调函数，接收两个参数：元素值和键名
 * @return $this 返回自身，支持链式调用
 * @example
 * \```php
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
 * \```
 */
public function each(callable $callback): Collection
```

### map()
```php
/**
 * 使用回调函数转换集合中的所有元素
 *
 * 对集合中的每个元素应用指定的回调函数，并返回包含转换结果的新Collection实例。
 * 注意：此方法不会修改原始集合，而是返回新集合。
 * 
 * @param callable $callback 应用于每个元素的转换函数，接收元素值作为参数
 * @return static 包含转换后元素的新Collection实例
 * @example
 * \```php
 * $collection = new Collection([1, 2, 3, 4]);
 * $doubled = $collection->map(function($item) {
 *     return $item * 2; // 将每个元素乘以2
 * });
 * // 结果: [2, 4, 6, 8]
 * \```
 */
public function map(callable $callback): static
```

### filter()
```php
/**
 * 使用回调函数过滤集合中的元素
 *
 * 根据回调函数的返回值过滤集合中的元素，仅保留回调返回true的元素。
 * 如果不提供回调函数，则默认过滤掉所有等值于false的元素。
 * 
 * @param callable|null $callback 可选，过滤函数，接收元素值作为参数
 * @return static 包含过滤后元素的新Collection实例
 * @example
 * \```php
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
 * \```
 */
public function filter(callable $callback = null): static
```

### where()
```php
/**
 * 根据字段条件过滤数组中的元素
 * @param $field    字段名
 * @param  $operator 操作符
 * @param  $value    数据
 * @return static
 */
public function where(string $field, $operator, $value = null): static
```

### whereLike()
```php
/**
 * LIKE过滤
 * @param $field 字段名
 * @param $value 数据
 * @return static
 */
public function whereLike(string $field, string $value): static
```

### whereNotLike()
```php
/**
 * NOT LIKE过滤
 * @param string $field 字段名
 * @param string $value 数据
 * @return static
 */
public function whereNotLike(string $field, string $value): static
```

### whereIn()
```php
/**
 * IN过滤
 * @param string $field 字段名
 * @param array  $value 数据
 * @return static
 */
public function whereIn(string $field, array $value): static
```

### whereNotIn()
```php
/**
 * NOT IN过滤
 * @param string $field 字段名
 * @param array  $value 数据
 * @return static
 */
public function whereNotIn(string $field, array $value): static
```

### whereBetween()
```php
/**
 * BETWEEN 过滤
 * @param string $field 字段名
 * @param  $value 数据
 * @return static
 */
public function whereBetween(string $field, $value): static
```

### whereNotBetween()
```php
/**
 * NOT BETWEEN 过滤
 * @param string $field 字段名
 * @param  $value 数据
 * @return static
 */
public function whereNotBetween(string $field, $value): static
```

### column()
```php
/**
 * 返回数据中指定的一列
 * @param string $columnKey 键名
 * @param string $indexKey  作为索引值的列
 * @return array
 */
public function column(string $columnKey, ?string $indexKey = null): array
```

### sort()
```php
/**
 * 对数组排序
 *
 * @param $callback 回调
 * @return static
 */
public function sort(?callable $callback = null): static
```

### order()
```php
/**
 * 指定字段排序
 * @param string $field 排序字段
 * @param string $order 排序
 * @return $this
 */
public function order(string $field, string $order = null): Collection
```

### shuffle()
```php
/**
 * 将数组打乱
 *
 * @return static
 */
public function shuffle(): static
```

### first()
```php
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
```

### last()
```php
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
```

### slice()
```php
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
```

## 接口实现方法

### offsetExists()
```php
/**
 * 检查一个偏移位置是否存在
 *
 * 实现ArrayAccess接口，允许使用isset()和empty()函数检查集合中的元素是否存在
 * 
 * @param mixed $offset 偏移位置/键名
 * @return bool 如果偏移位置存在则返回true，否则返回false
 */
public function offsetExists(mixed $offset): bool
```

### offsetGet()
```php
/**
 * 获取一个偏移位置的值
 *
 * 实现ArrayAccess接口，允许使用$collection[$offset]语法获取集合中的元素
 * 
 * @param mixed $offset 偏移位置/键名
 * @return mixed 元素值
 */
public function offsetGet(mixed $offset): mixed
```

### offsetSet()
```php
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
```

### offsetUnset()
```php
/**
 * 移除一个偏移位置的值
 *
 * 实现ArrayAccess接口，允许使用unset($collection[$offset])语法移除集合中的元素
 * 
 * @param mixed $offset 要移除的偏移位置/键名
 */
public function offsetUnset(mixed $offset): void
```

### count()
```php
/**
 * 获取数组元素总数
 *
 * 实现Countable接口，允许对Collection实例使用count()函数获取元素数量
 * 
 * @return int 集合中的元素数量
 */
public function count(): int
```

### getIterator()
```php
/**
 * 获取迭代器
 *
 * 实现IteratorAggregate接口，允许对Collection实例使用foreach循环遍历元素
 * 返回的ArrayIterator支持所有PHP迭代器功能
 * 
 * @return \ArrayIterator 可用于遍历集合元素的迭代器
 */
public function getIterator(): \ArrayIterator
```

### jsonSerialize()
```php
/**
 * 获取JSON序列化结果
 *
 * 实现JsonSerializable接口，允许直接将Collection实例传递给json_encode()函数
 * 内部调用toArray()方法确保正确序列化所有数据
 * 
 * @return array 可JSON序列化的数据结构
 */
public function jsonSerialize(): array
```

### toJson()
```php
/**
 * 转换当前数据集为JSON字符串
 * 
 * 将Collection实例转换为JSON格式的字符串，支持自定义JSON编码选项。
 * 
 * @param int $options JSON编码选项，默认为JSON_UNESCAPED_UNICODE以保留中文字符
 * @return string JSON格式的字符串
 */
public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
```

### __toString()
```php
/**
 * 魔术方法，将对象转换为字符串时自动调用
 *
 * @return string JSON格式的字符串表示
 */
public function __toString(): string
```

## 内部方法

### convertToArray()
```php
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
```

## 使用说明

### 创建集合实例
```php
// 使用构造函数创建
$collection = new Collection([1, 2, 3, 4, 5]);

// 使用静态工厂方法创建
$collection = Collection::make([1, 2, 3, 4, 5]);
```

### 链式调用
Collection类支持链式调用，可以连续应用多个操作：
```php
$collection = Collection::make([1, 2, 3, 4, 5])
    ->filter(function($item) { return $item % 2 === 0; })  // 过滤偶数
    ->map(function($item) { return $item * 2; })          // 每个元素乘以2
    ->sort();                                            // 排序

// 结果: [4, 8]
```

### 数组操作
```php
// 添加元素
$collection->push(6);
$collection->unshift(0);

// 获取元素
$first = $collection->first();
$last = $collection->last();
$item = $collection[2];  // 使用数组语法

// 过滤元素
$filtered = $collection->where('id', '>', 5);

// 转换为数组
$array = $collection->toArray();

// 转换为JSON
$json = $collection->toJson();
```

## 注意事项

1. Collection类是final的，不能被继承。
2. 大多数操作方法会返回新的Collection实例，不修改原始集合；但某些方法如push()、unshift()会直接修改原始集合。
3. 当使用数组语法访问集合元素时，请注意元素是否存在，否则可能抛出异常。
4. 在进行大量数据操作时，请注意内存使用情况。

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-02-03 11:31:47
