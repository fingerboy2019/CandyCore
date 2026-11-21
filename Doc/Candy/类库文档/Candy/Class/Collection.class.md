# Collection 类

## 概述

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

## 主要方法


### make()


### /**
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
    public function isEmpty():()


### /**
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
    public function toArray():()


### /**
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
    public function all():()


### /**
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
    public function merge(mixed $items):()


### /**
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
    public function dictionary(mixed $items = null,string|null &$indexKey = null):()


### /**
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
    public function diff(mixed $items,?string $indexKey = null):()


### /**
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
    public function intersect(mixed $items, ?string $indexKey = null):()


### /**
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
    public function flip():()


### /**
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
    public function keys():()

