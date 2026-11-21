# Arr 类使用文档

## 简介

Arr 类是一个功能丰富的数组操作工具类，提供了数组访问判断、多维数组处理、数组过滤、数组排序等一系列常用操作。该类采用静态方法设计，方便在项目中直接调用，无需实例化。

## 命名空间

```php
use Candy\Extend\Arr;
```

## 基本使用

### 判断数组是否可访问

```php
// 检查普通数组
$isAccessible = Arr::accessible([1, 2, 3]); // true

// 检查非数组
$isAccessible = Arr::accessible('string'); // false

// 检查ArrayAccess对象
$obj = new ArrayObject(['a' => 1]);
$isAccessible = Arr::accessible($obj); // true
```

### 向数组添加元素

```php
$array = ['name' => 'John'];

// 添加不存在的键
$array = Arr::add($array, 'age', 30);
// 结果: ['name' => 'John', 'age' => 30]

// 尝试添加已存在的键（保持原值不变）
$array = Arr::add($array, 'name', 'Jane');
// 结果: ['name' => 'John', 'age' => 30]
```

### 折叠多维数组

```php
$array = [[1, 2], [3, 4], [5]];
$collapsed = Arr::collapse($array);
// 结果: [1, 2, 3, 4, 5]

// 折叠混合类型数组
$array = [['a' => 1], [2, 3], ['b' => 4]];
$collapsed = Arr::collapse($array);
// 结果: ['a' => 1, 2, 3, 'b' => 4]
```

### 计算笛卡尔积

```php
$array1 = ['a', 'b'];
$array2 = [1, 2];

// 计算两个数组的笛卡尔积
$cartesian = Arr::crossJoin($array1, $array2);
// 结果: [['a', 1], ['a', 2], ['b', 1], ['b', 2]]

// 计算三个数组的笛卡尔积
$array3 = ['x', 'y'];
$cartesian = Arr::crossJoin($array1, $array2, $array3);
// 结果: 包含所有可能组合的三维数组
```

## 多维数组处理

### 点表示法转换

```php
$array = ['user' => ['name' => 'John', 'age' => 30]];

// 将多维数组转换为点表示法一维数组
$dot = Arr::dot($array);
// 结果: ['user.name' => 'John', 'user.age' => 30]

// 使用前缀
$dot = Arr::dot($array, 'data.');
// 结果: ['data.user.name' => 'John', 'data.user.age' => 30]
```

### 获取嵌套数组值

```php
$array = ['user' => ['name' => 'John', 'age' => 30]];

// 使用点表示法获取嵌套值
$name = Arr::get($array, 'user.name');
// 结果: 'John'

// 使用默认值
$city = Arr::get($array, 'user.city', 'Unknown');
// 结果: 'Unknown'
```

### 设置嵌套数组值

```php
$array = ['user' => ['name' => 'John']];

// 使用点表示法设置嵌套值
$array = Arr::set($array, 'user.age', 30);
// 结果: ['user' => ['name' => 'John', 'age' => 30]]

// 创建不存在的嵌套数组
$array = Arr::set($array, 'user.address.city', 'New York');
// 结果: ['user' => ['name' => 'John', 'age' => 30, 'address' => ['city' => 'New York']]]
```

### 移除嵌套数组值

```php
$array = ['user' => ['name' => 'John', 'age' => 30]];

// 使用点表示法移除嵌套键
$array = Arr::forget($array, 'user.age');
// 结果: ['user' => ['name' => 'John']]

// 移除多个键
$array = Arr::forget($array, ['user.name', 'user.age']);
// 结果: ['user' => []]
```

## 数组过滤与排序

### 过滤数组

```php
$array = [1, 2, 3, 4, 5, 6];

// 过滤偶数
$even = Arr::where($array, function($value, $key) {
    return $value % 2 == 0;
});
// 结果: [2, 4, 6]

// 过滤关联数组
$array = ['a' => 1, 'b' => 2, 'c' => 3];
$filtered = Arr::where($array, function($value, $key) {
    return $key !== 'b';
});
// 结果: ['a' => 1, 'c' => 3]
```

### 数组排序

```php
$array = [3, 1, 4, 1, 5, 9];

// 基本排序
$sorted = Arr::sort($array);
// 结果: [1, 1, 3, 4, 5, 9]

// 使用自定义回调
$array = [
    ['name' => 'John', 'age' => 30],
    ['name' => 'Jane', 'age' => 25]
];
$sorted = Arr::sort($array, function($a, $b) {
    return $a['age'] <=> $b['age'];
});
// 结果: 按年龄排序的数组

// 递归排序多维数组
$array = [
    'b' => [3, 1, 2],
    'a' => [5, 4]
];
$sorted = Arr::sortRecursive($array);
// 结果: 主数组和所有子数组都按升序排序
```

## 数组转换

### 数组拆分

```php
$array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];

// 将数组分为键和值两个数组
[$keys, $values] = Arr::divide($array);
// $keys = ['name', 'age', 'city']
// $values = ['John', 30, 'New York']
```

### 提取数组值

```php
$array = [
    ['id' => 1, 'name' => 'John'],
    ['id' => 2, 'name' => 'Jane']
];

// 提取单个字段
$names = Arr::pluck($array, 'name');
// 结果: ['John', 'Jane']

// 使用另一个字段作为键
$names = Arr::pluck($array, 'name', 'id');
// 结果: [1 => 'John', 2 => 'Jane']
```

### 转换为查询字符串

```php
$array = ['name' => 'John', 'age' => 30];

// 转换简单数组
$queryString = Arr::query($array);
// 结果: 'name=John&age=30'

// 转换多维数组
$array = ['user' => ['name' => 'John', 'age' => 30]];
$queryString = Arr::query($array);
// 结果: 'user%5Bname%5D=John&user%5Bage%5D=30'
```

## 常用功能

### 获取数组元素

```php
$array = [10, 20, 30];

// 获取第一个元素
$first = Arr::first($array); // 10

// 获取第一个满足条件的元素
$first = Arr::first($array, function($value) {
    return $value > 15;
}); // 20

// 获取最后一个元素
$last = Arr::last($array); // 30

// 获取最后一个满足条件的元素
$last = Arr::last($array, function($value) {
    return $value < 25;
}); // 20
```

### 随机元素

```php
$array = [1, 2, 3, 4, 5];

// 获取单个随机元素
$random = Arr::random($array);

// 获取多个随机元素
$randoms = Arr::random($array, 2);

// 保持键名
$array = ['a' => 1, 'b' => 2, 'c' => 3];
$randoms = Arr::random($array, 2, true);
```

### 数组操作

```php
$array = [1, 2, 3];

// 向数组开头添加元素
$array = Arr::prepend($array, 0);
// 结果: [0, 1, 2, 3]

// 使用指定键名
$array = ['b' => 2, 'c' => 3];
$array = Arr::prepend($array, 1, 'a');
// 结果: ['a' => 1, 'b' => 2, 'c' => 3]

// 获取并移除键值对
$array = ['name' => 'John', 'age' => 30];
$age = Arr::pull($array, 'age');
// $age = 30
// $array = ['name' => 'John']
```

## 最佳实践

1. **使用点表示法处理多维数组**：
   - 对于多层嵌套的数组，使用点表示法（如 `Arr::get($array, 'user.name')`）比多层 `isset()` 检查更简洁。

2. **使用默认值**：
   - 在获取数组值时，始终提供默认值，避免出现 `undefined index` 错误。

3. **适当使用回调函数**：
   - 利用 `Arr::where()`、`Arr::first()`、`Arr::last()` 等方法的回调函数参数，可以实现复杂的过滤逻辑。

4. **保持数组操作的一致性**：
   - 统一使用 Arr 类的方法进行数组操作，避免混合使用原生 PHP 函数和 Arr 类方法，提高代码的可读性和可维护性。

## 注意事项

1. **性能考虑**：
   - 对于大型数组，某些操作（如 `Arr::dot()`、`Arr::flatten()`）可能会消耗较多内存和时间。
   - 在处理大型数组时，应尽量减少不必要的数组转换和操作。

2. **回调函数的效率**：
   - 当使用带有回调函数的方法时，回调函数的实现会影响整体性能。
   - 尽量使回调函数简单高效，避免在回调函数中执行复杂操作。

3. **类型检查**：
   - Arr 类的方法通常不会对输入参数进行严格的类型检查。
   - 在调用方法前，确保提供的参数类型正确，避免出现意外错误。