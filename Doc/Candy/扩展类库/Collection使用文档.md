# Collection 数据集管理类使用文档

## 简介与基本概念

Collection类是一个功能强大的数据集管理工具，提供了丰富的数组操作API和流畅的链式调用支持。它实现了PHP的常用接口（如ArrayAccess、Countable、IteratorAggregate等），使其能够无缝集成到PHP生态系统中，提供比原生数组更便捷的操作方式。

### 命名空间
```php
namespace Candy\Extend;
```

### 核心特性
- **链式调用**：支持连续调用多个方法，提高代码可读性
- **接口兼容**：实现PHP标准接口，可像操作原生数组一样使用
- **丰富API**：提供超过50个实用方法，覆盖各种数据处理需求
- **不可变操作**：大多数方法返回新实例，避免意外修改原始数据

## 快速入门

### 基本用法
```php

use Candy\Extend\Collection;

// 创建集合实例
$collection = Collection::make([1, 2, 3, 4, 5]);

// 链式操作：过滤偶数并乘以2
$result = $collection->filter(function($item) {
    return $item % 2 === 0;
})->map(function($item) {
    return $item * 2;
});

// 输出结果：[4, 8]
print_r($result->all());
```

## 创建Collection实例

### 使用构造函数
```php

use Candy\Extend\Collection;

// 创建空集合
$collection = new Collection();

// 创建包含初始数据的集合
$collection = new Collection([1, 2, 3]);
$collection = new Collection("字符串也会被转换为数组");
```

### 使用静态工厂方法
```php
<?php
use Candy\Extend\Collection;

// 创建空集合
$collection = Collection::make();

// 创建包含初始数据的集合
$collection = Collection::make([1, 2, 3]);
$collection = Collection::make("测试字符串");
```

## 常用操作方法

### 集合遍历与转换

#### each() - 遍历集合元素
```php
<?php
$collection = Collection::make([1, 2, 3]);

// 遍历并打印每个元素
$collection->each(function($item, $key) {
    echo "键: {$key}, 值: {$item}<br>";
});

// 遍历并修改元素值（注意：会修改原始集合）
$collection->each(function(&$item) {
    $item *= 2;
});

// 提前中断遍历
$collection->each(function($item) {
    echo $item;
    return $item < 3; // 当item >= 3时停止遍历
});
```

#### toArray() / all() - 转换为数组
```php
<?php
$collection = Collection::make(['name' => '张三', 'age' => 25]);

// 转换为数组
$array = $collection->toArray();
// 或使用all()方法（两者功能相同）
$array = $collection->all();

// 输出：Array([name] => 张三 [age] => 25)
print_r($array);
```

#### toJson() - 转换为JSON字符串
```php
<?php
$collection = Collection::make(['name' => '张三', 'age' => 25]);

// 转换为JSON
$json = $collection->toJson();

// 输出：{"name":"张三","age":25}
echo $json;

// 自定义JSON编码选项
$json = $collection->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
```

### 集合过滤与筛选

#### filter() - 过滤集合元素
```php
<?php
$collection = Collection::make([0, 1, false, 2, '', 3]);

// 默认过滤：移除所有等值于false的元素
$filtered = $collection->filter();
// 结果：[1, 2, 3]

// 使用自定义回调函数过滤
$evenNumbers = $collection->filter(function($item) {
    return is_numeric($item) && $item % 2 === 0;
});
// 结果：[0, 2]
```

#### where() - 根据字段条件过滤
```php
<?php
$users = Collection::make([
    ['id' => 1, 'name' => '张三', 'age' => 20],
    ['id' => 2, 'name' => '李四', 'age' => 25],
    ['id' => 3, 'name' => '王五', 'age' => 30]
]);

// 筛选年龄大于20的用户
$adults = $users->where('age', '>', 20);

// 筛选年龄等于25的用户
$user = $users->where('age', '=', 25);

// 简写形式（当操作符为'='时）
$user = $users->where('age', 25);
```

#### whereIn() / whereNotIn() - 范围过滤
```php
<?php
$users = Collection::make([
    ['id' => 1, 'name' => '张三', 'status' => 'active'],
    ['id' => 2, 'name' => '李四', 'status' => 'inactive'],
    ['id' => 3, 'name' => '王五', 'status' => 'active']
]);

// 筛选状态在指定范围内的用户
$activeUsers = $users->whereIn('status', ['active']);

// 筛选状态不在指定范围内的用户
$inactiveUsers = $users->whereNotIn('status', ['active']);
```

#### first() / last() - 获取首/尾元素
```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5]);

// 获取第一个元素
$first = $collection->first(); // 1

// 获取最后一个元素
$last = $collection->last(); // 5

// 获取第一个满足条件的元素
$firstEven = $collection->first(function($item) {
    return $item % 2 === 0;
}); // 2

// 设置默认值
$firstNegative = $collection->first(function($item) {
    return $item < 0;
}, -1); // -1（没有找到满足条件的元素，返回默认值）
```

### 集合排序与重组

#### sort() - 排序集合
```php
<?php
$collection = Collection::make([3, 1, 4, 1, 5, 9, 2, 6]);

// 默认升序排序
$sorted = $collection->sort();
// 结果：[1, 1, 2, 3, 4, 5, 6, 9]

// 使用自定义排序回调
$reverseSorted = $collection->sort(function($a, $b) {
    return $b - $a;
});
// 结果：[9, 6, 5, 4, 3, 2, 1, 1]
```

#### order() - 指定字段排序
```php
<?php
$users = Collection::make([
    ['name' => '张三', 'age' => 25],
    ['name' => '李四', 'age' => 20],
    ['name' => '王五', 'age' => 30]
]);

// 按年龄升序排序
$sortedByAge = $users->order('age');

// 按年龄降序排序
$sortedByAgeDesc = $users->order('age', 'desc');
```

#### reverse() - 反转集合
```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5]);

$reversed = $collection->reverse();
// 结果：[5, 4, 3, 2, 1]
```

#### shuffle() - 打乱集合
```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5]);

$shuffled = $collection->shuffle();
// 结果：随机顺序的数组
```

#### chunk() - 分割集合为块
```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5, 6, 7]);

// 分割为每个块包含3个元素
$chunks = $collection->chunk(3);

// 结果：[[1, 2, 3], [4, 5, 6], [7]]
```

### 集合计算与统计

#### count() - 获取集合元素数量
```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5]);

$count = count($collection); // 5
// 或使用方法
$count = $collection->count(); // 5
```

#### reduce() - 计算集合元素总和/累计值
```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5]);

// 计算总和
$sum = $collection->reduce(function($carry, $item) {
    return $carry + $item;
}, 0); // 15

// 拼接字符串
$strings = Collection::make(['Hello', ' ', 'World']);
$greeting = $strings->reduce(function($carry, $item) {
    return $carry . $item;
}); // 'Hello World'
```

#### isEmpty() - 检查集合是否为空
```php
<?php
$emptyCollection = Collection::make();
$nonEmptyCollection = Collection::make([1, 2, 3]);

$emptyCollection->isEmpty(); // true
$nonEmptyCollection->isEmpty(); // false
```

## 链式调用

Collection类支持强大的链式调用，可以将多个操作组合在一起，使代码更加简洁易读。

```php
<?php
$users = Collection::make([
    ['id' => 1, 'name' => '张三', 'age' => 20, 'gender' => 'male'],
    ['id' => 2, 'name' => '李四', 'age' => 25, 'gender' => 'female'],
    ['id' => 3, 'name' => '王五', 'age' => 30, 'gender' => 'male'],
    ['id' => 4, 'name' => '赵六', 'age' => 35, 'gender' => 'female']
]);

// 链式调用示例
$result = $users
    ->where('gender', 'male') // 筛选男性用户
    ->where('age', '>', 25)    // 筛选年龄大于25的用户
    ->map(function($user) {    // 转换为只包含姓名的数组
        return $user['name'];
    })
    ->sort()                   // 排序
    ->toArray();              // 转换为数组

// 结果：['王五']
print_r($result);
```

## 接口实现与特性

Collection类实现了多个PHP标准接口，提供了丰富的操作方式：

### 数组访问 (ArrayAccess)
```php
<?php
$collection = Collection::make(['name' => '张三', 'age' => 25]);

// 检查元素是否存在
isset($collection['name']); // true

// 获取元素
$name = $collection['name']; // '张三'

// 设置元素
$collection['email'] = 'zhangsan@example.com';

// 删除元素
unset($collection['age']);
```

### 计数 (Countable)
```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5]);

count($collection); // 5
```

### 迭代 (IteratorAggregate)
```php
<?php
$collection = Collection::make(['name' => '张三', 'age' => 25]);

// 使用foreach遍历
foreach ($collection as $key => $value) {
    echo "{$key}: {$value}<br>";
}
```

### JSON序列化 (JsonSerializable)
```php
<?php
$collection = Collection::make(['name' => '张三', 'age' => 25]);

// 直接转换为JSON
$json = json_encode($collection);
// 结果：{"name":"张三","age":25}
```

## 最佳实践与注意事项

### 1. 区分修改原始集合与返回新集合的方法

- **返回新集合的方法**（推荐使用）：filter()、map()、sort()、reverse()、chunk()等
- **修改原始集合的方法**：push()、unshift()、pop()、shift()等

```php
<?php
$collection = Collection::make([1, 2, 3]);

// 不会修改原始集合，返回新集合
$filtered = $collection->filter(function($item) {
    return $item > 1;
});

// 会直接修改原始集合
$collection->push(4);
```

### 2. 合理使用链式调用

链式调用可以使代码更简洁，但过长的链式调用会降低可读性：

```php
<?php
// 推荐：适当的链式调用
$result = $collection
    ->where('status', 'active')
    ->map(function($item) {
        return $item['name'];
    })
    ->toArray();

// 不推荐：过长的链式调用
$result = $collection
    ->where('status', 'active')
    ->where('age', '>', 20)
    ->map(function($item) {
        return ['name' => $item['name'], 'email' => $item['email']];
    })
    ->sortBy('name')
    ->chunk(10)
    ->first()
    ->toJson();
```

### 3. 注意内存使用

在处理大量数据时，请注意内存使用情况：

```php
<?php
// 不推荐：处理大量数据时创建多个中间集合
$result = $largeCollection
    ->filter(...)
    ->map(...)
    ->sort(...);

// 推荐：考虑使用原生PHP函数或其他更高效的方式
$result = array_values(
    array_map(...,
        array_filter(...,
            $largeArray
        )
    )
);
```

### 4. 利用类型提示

在回调函数中使用类型提示可以提高代码可读性和安全性：

```php
<?php
$collection = Collection::make([1, 2, 3, 4, 5]);

// 使用类型提示
$sum = $collection->reduce(function(int $carry, int $item): int {
    return $carry + $item;
}, 0);
```

## 总结

Collection类是一个功能强大的数据集管理工具，它提供了丰富的API和优雅的链式调用方式，使数组操作更加便捷高效。通过实现PHP标准接口，它能够无缝集成到PHP生态系统中，提供与原生数组相似的操作体验，但功能更加强大。

无论是简单的数组过滤、映射，还是复杂的数据处理流程，Collection类都能提供简洁、高效的解决方案，是PHP开发中处理数据集的理想选择。