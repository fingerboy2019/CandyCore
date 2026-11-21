# Arr 类

## 简介
Arr 类是一个功能丰富的数组操作工具类，提供了数组访问判断、多维数组处理、数组过滤、数组排序等一系列常用操作。该类采用静态方法设计，方便在项目中直接调用，无需实例化。

## 命名空间
```php
namespace Candy\Extend;
```

## 类定义
```php
/**
 * 数组操作工具类
 * 
 * 提供数组访问判断、多维数组处理、数组过滤、数组排序等一系列常用操作
 * 采用静态方法设计，方便直接调用
 */
final class Arr
```

## 主要方法

### accessible()
```php
/**
 * 判断数组是否可访问
 * 
 * 检查给定值是否是一个数组或是一个ArrayAccess对象，以确定它是否可以通过数组语法访问
 * 
 * @param mixed $value 需要检查的值
 * @return bool 如果值是可访问的数组，则返回true，否则返回false
 * @example
 * \```php
 * // 检查普通数组
 * $result1 = Arr::accessible([1, 2, 3]);
 * // 结果: true
 * 
 * // 检查非数组
 * $result2 = Arr::accessible('string');
 * // 结果: false
 * 
 * // 检查ArrayAccess对象
 * $obj = new ArrayObject(['a' => 1]);
 * $result3 = Arr::accessible($obj);
 * // 结果: true
 * \```
 */
public static function accessible(mixed $value): bool
```

### add()
```php
/**
 * 如果键不存在则向数组添加元素
 * 
 * 检查数组中是否已存在指定键，如果不存在则将键值对添加到数组中
 * 
 * @param array $array 源数组
 * @param string|int $key 要添加的键
 * @param mixed $value 要添加的值
 * @return array 返回添加后的数组
 * @example
 * \```php
 * // 添加不存在的键
 * $array = ['name' => 'John'];
 * $result1 = Arr::add($array, 'age', 30);
 * // 结果: ['name' => 'John', 'age' => 30]
 * 
 * // 尝试添加已存在的键
 * $result2 = Arr::add($array, 'name', 'Jane');
 * // 结果: ['name' => 'John'] (保持原值不变)
 * \```
 */
public static function add(array $array, string|int $key, mixed $value): array
```

### collapse()
```php
/**
 * 将多维数组折叠为一维数组
 * 
 * 将一个包含多个数组的数组折叠成一个一维数组，保持所有元素的顺序
 * 
 * @param array $array 要折叠的数组
 * @return array 返回折叠后的一维数组
 * @example
 * \```php
 * // 折叠简单的二维数组
 * $array = [[1, 2], [3, 4], [5]];
 * $result = Arr::collapse($array);
 * // 结果: [1, 2, 3, 4, 5]
 * 
 * // 折叠混合类型数组
 * $array = [['a' => 1], [2, 3], ['b' => 4]];
 * $result = Arr::collapse($array);
 * // 结果: ['a' => 1, 2, 3, 'b' => 4]
 * \```
 */
public static function collapse(array $array): array
```

### crossJoin()
```php
/**
 * 计算多个数组的笛卡尔积
 * 
 * 生成多个数组的笛卡尔积，返回一个包含所有可能组合的多维数组
 * 
 * @param array ...$arrays 要计算笛卡尔积的数组列表
 * @return array 返回笛卡尔积结果
 * @example
 * \```php
 * // 计算两个数组的笛卡尔积
 * $array1 = ['a', 'b'];
 * $array2 = [1, 2];
 * $result = Arr::crossJoin($array1, $array2);
 * // 结果: [['a', 1], ['a', 2], ['b', 1], ['b', 2]]
 * 
 * // 计算三个数组的笛卡尔积
 * $array3 = ['x', 'y'];
 * $result = Arr::crossJoin($array1, $array2, $array3);
 * // 结果: 所有可能的三个数组元素组合
 * \```
 */
public static function crossJoin(array ...$arrays): array
```

### divide()
```php
/**
 * 将数组分为键和值两个数组
 * 
 * 将一个关联数组拆分为两个数组，一个包含所有键，另一个包含所有对应的值
 * 
 * @param array $array 要拆分的数组
 * @return array 返回包含两个数组的数组，第一个是键数组，第二个是值数组
 * @example
 * \```php
 * // 拆分关联数组
 * $array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];
 * [$keys, $values] = Arr::divide($array);
 * // $keys = ['name', 'age', 'city']
 * // $values = ['John', 30, 'New York']
 * \```
 */
public static function divide(array $array): array
```

### dot()
```php
/**
 * 将多维数组转换为点表示法一维数组
 * 
 * 将多维数组扁平化为一维数组，使用点符号表示嵌套层级关系
 * 
 * @param array $array 要扁平化的数组
 * @param string $prepend 前缀，用于在键前添加
 * @return array 返回扁平化后的一维数组
 * @example
 * \```php
 * // 扁平化简单多维数组
 * $array = ['user' => ['name' => 'John', 'age' => 30]];
 * $result = Arr::dot($array);
 * // 结果: ['user.name' => 'John', 'user.age' => 30]
 * 
 * // 使用前缀
 * $result = Arr::dot($array, 'data.');
 * // 结果: ['data.user.name' => 'John', 'data.user.age' => 30]
 * \```
 */
public static function dot(array $array, string $prepend = ''): array
```

### except()
```php
/**
 * 从数组中移除指定的键
 * 
 * 从数组中排除指定的键及其对应的值，返回剩余的数组元素
 * 
 * @param array $array 源数组
 * @param array|string $keys 要移除的键名，单个键名或键名数组
 * @return array 返回移除指定键后的数组
 * @example
 * \```php
 * // 移除单个键
 * $array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];
 * $result1 = Arr::except($array, 'age');
 * // 结果: ['name' => 'John', 'city' => 'New York']
 * 
 * // 移除多个键
 * $result2 = Arr::except($array, ['age', 'city']);
 * // 结果: ['name' => 'John']
 * \```
 */
public static function except(array $array, array|string $keys): array
```

### exists()
```php
/**
 * 检查数组中是否存在指定键
 * 
 * 使用isset检查数组中是否存在指定的键，并且该键的值不为null
 * 
 * @param array $array 要检查的数组
 * @param string|int $key 要检查的键名
 * @return bool 如果键存在且值不为null，则返回true，否则返回false
 * @example
 * \```php
 * // 检查存在的键
 * $array = ['name' => 'John', 'age' => null];
 * $result1 = Arr::exists($array, 'name');
 * // 结果: true
 * 
 * // 检查不存在的键
 * $result2 = Arr::exists($array, 'city');
 * // 结果: false
 * 
 * // 检查值为null的键
 * $result3 = Arr::exists($array, 'age');
 * // 结果: false
 * \```
 */
public static function exists(array $array, string|int $key): bool
```

### first()
```php
/**
 * 获取数组中的第一个元素
 * 
 * 返回数组中的第一个元素，或根据可选的回调函数返回第一个满足条件的元素
 * 
 * @param array $array 源数组
 * @param callable|null $callback 可选的过滤回调函数
 * @param mixed $default 如果没有找到元素则返回的默认值
 * @return mixed 返回找到的元素或默认值
 * @example
 * \```php
 * // 获取第一个元素
 * $array = [10, 20, 30];
 * $result1 = Arr::first($array);
 * // 结果: 10
 * 
 * // 使用回调函数
 * $result2 = Arr::first($array, function($value) {
 *     return $value > 15;
 * });
 * // 结果: 20
 * 
 * // 使用默认值
 * $emptyArray = [];
 * $result3 = Arr::first($emptyArray, null, 'default');
 * // 结果: 'default'
 * \```
 */
public static function first(array $array, callable $callback = null, mixed $default = null): mixed
```

### last()
```php
/**
 * 获取数组中的最后一个元素
 * 
 * 返回数组中的最后一个元素，或根据可选的回调函数返回最后一个满足条件的元素
 * 
 * @param array $array 源数组
 * @param callable|null $callback 可选的过滤回调函数
 * @param mixed $default 如果没有找到元素则返回的默认值
 * @return mixed 返回找到的元素或默认值
 * @example
 * \```php
 * // 获取最后一个元素
 * $array = [10, 20, 30];
 * $result1 = Arr::last($array);
 * // 结果: 30
 * 
 * // 使用回调函数
 * $result2 = Arr::last($array, function($value) {
 *     return $value < 25;
 * });
 * // 结果: 20
 * 
 * // 使用默认值
 * $emptyArray = [];
 * $result3 = Arr::last($emptyArray, null, 'default');
 * // 结果: 'default'
 * \```
 */
public static function last(array $array, callable $callback = null, mixed $default = null): mixed
```

### flatten()
```php
/**
 * 扁平化多维数组
 * 
 * 将多维数组转换为一维数组，可以通过参数控制扁平化的深度
 * 
 * @param array $array 要扁平化的数组
 * @param int $depth 扁平化的深度，默认是无限深度(PHP_INT_MAX)
 * @return array 返回扁平化后的一维数组
 * @example
 * \```php
 * // 完全扁平化
 * $array = [1, [2, 3, [4, 5]]];
 * $result1 = Arr::flatten($array);
 * // 结果: [1, 2, 3, 4, 5]
 * 
 * // 限制扁平化深度
 * $result2 = Arr::flatten($array, 1);
 * // 结果: [1, 2, 3, [4, 5]]
 * \```
 */
public static function flatten(array $array, int $depth = PHP_INT_MAX): array
```

### forget()
```php
/**
 * 从数组中移除指定的键值对，支持点表示法
 * 
 * 使用点符号语法从多维数组中移除指定的键值对
 * 
 * @param array $array 要修改的数组
 * @param array|string $keys 要移除的键，支持点表示法
 * @return array 返回修改后的数组
 * @example
 * \```php
 * // 使用点表示法移除嵌套键
 * $array = ['user' => ['name' => 'John', 'age' => 30]];
 * $result = Arr::forget($array, 'user.age');
 * // 结果: ['user' => ['name' => 'John']]
 * 
 * // 移除多个键
 * $result = Arr::forget($array, ['user.name', 'user.age']);
 * // 结果: ['user' => []]
 * \```
 */
public static function forget(array &$array, array|string $keys): array
```

### get()
```php
/**
 * 从数组中获取指定键的值，支持点表示法
 * 
 * 使用点符号语法从多维数组中获取指定键的值，如果键不存在则返回默认值
 * 
 * @param array $array 源数组
 * @param array|string $key 要获取的键，支持点表示法
 * @param mixed $default 如果键不存在则返回的默认值
 * @return mixed 返回获取的值或默认值
 * @example
 * \```php
 * // 使用点表示法获取嵌套值
 * $array = ['user' => ['name' => 'John', 'age' => 30]];
 * $result1 = Arr::get($array, 'user.name');
 * // 结果: 'John'
 * 
 * // 使用默认值
 * $result2 = Arr::get($array, 'user.city', 'Unknown');
 * // 结果: 'Unknown'
 * \```
 */
public static function get(array $array, array|string $key, mixed $default = null): mixed
```

### has()
```php
/**
 * 检查数组中是否存在指定键，支持点表示法
 * 
 * 使用点符号语法检查多维数组中是否存在指定的键
 * 
 * @param array $array 要检查的数组
 * @param array|string $keys 要检查的键，支持点表示法
 * @return bool 如果所有指定的键都存在，则返回true，否则返回false
 * @example
 * \```php
 * // 使用点表示法检查嵌套键
 * $array = ['user' => ['name' => 'John', 'age' => 30]];
 * $result1 = Arr::has($array, 'user.name');
 * // 结果: true
 * 
 * // 检查多个键
 * $result2 = Arr::has($array, ['user.name', 'user.age']);
 * // 结果: true
 * 
 * // 检查不存在的键
 * $result3 = Arr::has($array, 'user.city');
 * // 结果: false
 * \```
 */
public static function has(array $array, array|string $keys): bool
```

### isAssoc()
```php
/**
 * 判断是否为关联数组
 * 
 * 检查一个数组是否为关联数组（即键不是连续的整数索引）
 * 
 * @param array $array 要检查的数组
 * @return bool 如果是关联数组则返回true，否则返回false
 * @example
 * \```php
 * // 检查关联数组
 * $assocArray = ['name' => 'John', 'age' => 30];
 * $result1 = Arr::isAssoc($assocArray);
 * // 结果: true
 * 
 * // 检查索引数组
 * $indexArray = [1, 2, 3];
 * $result2 = Arr::isAssoc($indexArray);
 * // 结果: false
 * 
 * // 检查混合键数组
 * $mixedArray = [0 => 'first', 'second' => 2];
 * $result3 = Arr::isAssoc($mixedArray);
 * // 结果: true
 * \```
 */
public static function isAssoc(array $array): bool
```

### only()
```php
/**
 * 只保留数组中指定的键
 * 
 * 从数组中提取指定的键及其对应的值，返回一个只包含这些键值对的新数组
 * 
 * @param array $array 源数组
 * @param array|string $keys 要保留的键名，单个键名或键名数组
 * @return array 返回只包含指定键的新数组
 * @example
 * \```php
 * // 保留单个键
 * $array = ['name' => 'John', 'age' => 30, 'city' => 'New York'];
 * $result1 = Arr::only($array, 'name');
 * // 结果: ['name' => 'John']
 * 
 * // 保留多个键
 * $result2 = Arr::only($array, ['name', 'age']);
 * // 结果: ['name' => 'John', 'age' => 30]
 * \```
 */
public static function only(array $array, array|string $keys): array
```

### pluck()
```php
/**
 * 从二维数组中提取指定键的值
 * 
 * 从二维数组中提取指定字段的值，返回一个新数组，可以通过第二个参数指定新数组的键
 * 
 * @param array $array 源二维数组
 * @param array|string $value 要提取的字段名，支持点表示法
 * @param array|string|null $key 可选，用作新数组键的字段名
 * @return array 返回提取后的新数组
 * @example
 * \```php
 * // 提取单个字段
 * $array = [
 *     ['id' => 1, 'name' => 'John'],
 *     ['id' => 2, 'name' => 'Jane']
 * ];
 * $result1 = Arr::pluck($array, 'name');
 * // 结果: ['John', 'Jane']
 * 
 * // 使用另一个字段作为键
 * $result2 = Arr::pluck($array, 'name', 'id');
 * // 结果: [1 => 'John', 2 => 'Jane']
 * 
 * // 使用点表示法
 * $array = [
 *     ['user' => ['id' => 1, 'name' => 'John']],
 *     ['user' => ['id' => 2, 'name' => 'Jane']]
 * ];
 * $result3 = Arr::pluck($array, 'user.name', 'user.id');
 * // 结果: [1 => 'John', 2 => 'Jane']
 * \```
 */
public static function pluck(array $array, array|string $value, array|string $key = null): array
```

### prepend()
```php
/**
 * 向数组开头添加元素
 * 
 * 在数组的开头添加一个元素，如果提供了键名，则使用指定的键名
 * 
 * @param array $array 源数组
 * @param mixed $value 要添加的值
 * @param string|int|null $key 可选，添加元素的键名
 * @return array 返回添加后的数组
 * @example
 * \```php
 * // 添加值到数组开头
 * $array = [1, 2, 3];
 * $result1 = Arr::prepend($array, 0);
 * // 结果: [0, 1, 2, 3]
 * 
 * // 使用指定键名
 * $array = ['b' => 2, 'c' => 3];
 * $result2 = Arr::prepend($array, 1, 'a');
 * // 结果: ['a' => 1, 'b' => 2, 'c' => 3]
 * \```
 */
public static function prepend(array $array, mixed $value, string|int $key = null): array
```

### pull()
```php
/**
 * 从数组中获取并移除指定键的值
 * 
 * 获取数组中指定键的值，然后从数组中移除该键值对
 * 
 * @param array $array 要修改的数组
 * @param string|int $key 要获取和移除的键
 * @param mixed $default 如果键不存在则返回的默认值
 * @return mixed 返回获取的值或默认值
 * @example
 * \```php
 * // 获取并移除键值对
 * $array = ['name' => 'John', 'age' => 30];
 * $value = Arr::pull($array, 'age');
 * // $value = 30
 * // $array = ['name' => 'John']
 * 
 * // 使用默认值
 * $value = Arr::pull($array, 'city', 'Unknown');
 * // $value = 'Unknown'
 * // $array 保持不变
 * \```
 */
public static function pull(array &$array, string|int $key, mixed $default = null): mixed
```

### random()
```php
/**
 * 从数组中随机获取一个或多个元素
 * 
 * 从数组中随机选择一个或指定数量的元素，可选参数可以控制是否保持键名
 * 
 * @param array $array 源数组
 * @param int|null $number 可选，要获取的元素数量
 * @param bool $preserveKeys 是否保持原始键名，默认为false
 * @return mixed 返回随机选择的元素或元素数组
 * @example
 * \```php
 * // 获取单个随机元素
 * $array = [1, 2, 3, 4, 5];
 * $result1 = Arr::random($array);
 * // 结果: 随机一个数字
 * 
 * // 获取多个随机元素
 * $result2 = Arr::random($array, 2);
 * // 结果: 随机两个数字的数组
 * 
 * // 保持键名
 * $array = ['a' => 1, 'b' => 2, 'c' => 3];
 * $result3 = Arr::random($array, 2, true);
 * // 结果: 保持键名的随机元素数组
 * \```
 */
public static function random(array $array, int $number = null, bool $preserveKeys = false): mixed
```

### set()
```php
/**
 * 设置数组中指定键的值，支持点表示法
 * 
 * 使用点符号语法在多维数组中设置指定键的值
 * 
 * @param array $array 要修改的数组
 * @param array|string $key 要设置的键，支持点表示法
 * @param mixed $value 要设置的值
 * @return array 返回修改后的数组
 * @example
 * \```php
 * // 使用点表示法设置嵌套值
 * $array = ['user' => ['name' => 'John']];
 * $result = Arr::set($array, 'user.age', 30);
 * // 结果: ['user' => ['name' => 'John', 'age' => 30]]
 * 
 * // 创建不存在的嵌套数组
 * $result = Arr::set($array, 'user.address.city', 'New York');
 * // 结果: ['user' => ['name' => 'John', 'address' => ['city' => 'New York']]]
 * \```
 */
public static function set(array &$array, array|string $key, mixed $value): array
```

### shuffle()
```php
/**
 * 打乱数组顺序
 * 
 * 随机打乱数组中元素的顺序，可选参数可以控制是否保持键名
 * 
 * @param array $array 源数组
 * @param bool $preserveKeys 是否保持原始键名，默认为false
 * @return array 返回打乱顺序后的数组
 * @example
 * \```php
 * // 打乱数组顺序
 * $array = [1, 2, 3, 4, 5];
 * $result1 = Arr::shuffle($array);
 * // 结果: 顺序随机的数组
 * 
 * // 保持键名打乱
 * $array = ['a' => 1, 'b' => 2, 'c' => 3];
 * $result2 = Arr::shuffle($array, true);
 * // 结果: 键名保持不变但值随机排列的数组
 * \```
 */
public static function shuffle(array $array, bool $preserveKeys = false): array
```

### sort()
```php
/**
 * 对数组进行排序
 * 
 * 根据提供的回调函数对数组进行排序
 * 
 * @param array $array 要排序的数组
 * @param callable|null $callback 可选的排序回调函数
 * @return array 返回排序后的数组
 * @example
 * \```php
 * // 基本排序
 * $array = [3, 1, 4, 1, 5, 9];
 * $result1 = Arr::sort($array);
 * // 结果: [1, 1, 3, 4, 5, 9]
 * 
 * // 使用自定义回调
 * $array = [
 *     ['name' => 'John', 'age' => 30],
 *     ['name' => 'Jane', 'age' => 25]
 * ];
 * $result2 = Arr::sort($array, function($a, $b) {
 *     return $a['age'] <=> $b['age'];
 * });
 * // 结果: 按年龄排序的数组
 * \```
 */
public static function sort(array $array, callable $callback = null): array
```

### sortRecursive()
```php
/**
 * 递归排序多维数组
 * 
 * 递归地对多维数组进行排序，对每个子数组也进行排序
 * 
 * @param array $array 要排序的多维数组
 * @return array 返回排序后的多维数组
 * @example
 * \```php
 * // 递归排序多维数组
 * $array = [
 *     'b' => [3, 1, 2],
 *     'a' => [5, 4]
 * ];
 * $result = Arr::sortRecursive($array);
 * // 结果: 主数组和所有子数组都按升序排序
 * \```
 */
public static function sortRecursive(array $array): array
```

### query()
```php
/**
 * 将数组转换为查询字符串
 * 
 * 将数组转换为URL查询字符串格式
 * 
 * @param array $array 要转换的数组
 * @return string 返回URL编码的查询字符串
 * @example
 * \```php
 * // 转换简单数组
 * $array = ['name' => 'John', 'age' => 30];
 * $result1 = Arr::query($array);
 * // 结果: 'name=John&age=30'
 * 
 * // 转换多维数组
 * $array = ['user' => ['name' => 'John', 'age' => 30]];
 * $result2 = Arr::query($array);
 * // 结果: 'user%5Bname%5D=John&user%5Bage%5D=30'
 * \```
 */
public static function query(array $array): string
```

### where()
```php
/**
 * 根据回调函数过滤数组
 * 
 * 使用回调函数过滤数组，只保留回调函数返回true的元素
 * 
 * @param array $array 源数组
 * @param callable $callback 过滤回调函数
 * @return array 返回过滤后的数组
 * @example
 * \```php
 * // 过滤偶数
 * $array = [1, 2, 3, 4, 5, 6];
 * $result = Arr::where($array, function($value, $key) {
 *     return $value % 2 == 0;
 * });
 * // 结果: [2, 4, 6]
 * 
 * // 过滤关联数组
 * $array = ['a' => 1, 'b' => 2, 'c' => 3];
 * $result = Arr::where($array, function($value, $key) {
 *     return $key !== 'b';
 * });
 * // 结果: ['a' => 1, 'c' => 3]
 * \```
 */
public static function where(array $array, callable $callback): array
```

### wrap()
```php
/**
 * 将非数组值包装成数组
 * 
 * 如果值不是数组，则将其包装在数组中返回；如果是数组则直接返回；如果是null则返回空数组
 * 
 * @param array $value 要包装的值
 * @return array 返回包装后的数组
 * @example
 * \```php
 * // 包装字符串
 * $result1 = Arr::wrap('hello');
 * // 结果: ['hello']
 * 
 * // 包装数值
 * $result2 = Arr::wrap(42);
 * // 结果: [42]
 * 
 * // 包装null
 * $result3 = Arr::wrap(null);
 * // 结果: []
 * 
 * // 数组直接返回
 * $result4 = Arr::wrap([1, 2, 3]);
 * // 结果: [1, 2, 3]
 * \```
 */
public static function wrap(array $value): array
```

## 扩展方法

### arrayGetIndex()
```php
/**
 * 获取数组中指定索引位置的键值对
 * 
 * 从数组中查找并返回指定索引位置的元素的键和值。索引从0开始计数。
 * 
 * @param array $arr 要搜索的数组数据
 * @param int $index 要获取元素的位置索引（从0开始）
 * @return array 返回包含键和值的数组，如果未找到则返回空数组
 */
public static function arrayGetIndex(array $arr, int $index): array
```

### arrayMapRecursive()
```php
/**
 * 递归地对数组中的每个元素应用指定的回调函数
 * 
 * 对多维数组中的每个元素递归地应用给定的回调函数进行处理，保持数组的原始键名和结构不变。
 * 
 * @param string|callable $filter 回调函数名或匿名函数，用于处理非数组元素
 * @param array $data 要递归处理的数组
 * @return array 返回经过递归处理后的新数组
 */
public static function arrayMapRecursive(string $filter, array $data): array
```

### arrayBubbleSort()
```php
/**
 * 冒泡排序算法实现
 * 
 * 使用标准冒泡排序算法对一维数组进行升序排序。
 * 
 * @param array $array 需要排序的一维数组
 * @return array|false 返回排序后的新数组，升序排列；如果输入数组为空，则返回false
 */
public static function arrayBubbleSort(array $array): mixed
```

### arraySort()
```php
/**
 * 二维数组按照指定键值排序
 * 
 * 根据指定的字段值对二维数组进行排序，并处理可能出现的值相同的情况。
 * 
 * @param array $records 需要排序的二维数组
 * @param string $field 用于排序的字段名
 * @param bool $reverse 排序方向，默认为false（升序）
 * @return array 返回排序后的二维数组
 */
public static function arraySort(array $records, string $field, bool $reverse = false): array
```

### arrayTry()
```php
/**
 * 尝试对数组元素应用回调并返回第一个非假值结果
 * 
 * 遍历数组中的每个元素，依次对其调用指定的回调函数，一旦遇到回调函数返回非假值的情况，立即返回该值。
 * 
 * @param array $array 要遍历的数组
 * @param callable $callback 应用于每个数组元素的回调函数
 * @param mixed ...$args 可选，传递给回调函数的额外参数
 * @return mixed 返回回调函数的第一个非假值结果，如果所有结果都是假值则返回false
 */
public static function arrayTry(array $array, $callback, ...$args): mixed
```

### arrayUnion()
```php
/**
 * 计算多个数组的并集
 * 
 * 将两个或多个数组合并，返回包含所有不重复元素的新数组。
 * 
 * @param array ...$argsCount 两个或多个要求并集的数组
 * @return array|false 返回包含所有不重复元素的新数组，如果参数不满足要求则返回false
 */
public static function arrayUnion(array ...$argsCount): mixed
```

### arrayIntersect()
```php
/**
 * 计算多个数组的交集
 * 
 * 返回多个数组中共同存在的元素，即所有数组都包含的元素。
 * 
 * @param array ...$argsCount 两个或多个要求交集的数组
 * @return array|false 返回包含所有共同元素的新数组，如果参数不满足要求则返回false
 */
public static function arrayIntersect(array ...$argsCount): mixed
```

## 使用说明

Arr类提供了一系列静态方法，可以直接通过类名调用，无需实例化：

```php
// 导入命名空间
use Candy\Extend\Arr;

// 使用示例
$array = ['user' => ['name' => 'John', 'age' => 30]];
$name = Arr::get($array, 'user.name');
$array = Arr::set($array, 'user.city', 'New York');
$filtered = Arr::where([1, 2, 3, 4], function($value) { return $value % 2 == 0; });
```

## 注意事项

1. 所有方法都接受数组作为参数，不支持其他数据类型
2. 部分方法会修改原始数组，需要注意引用传递的问题
3. 多维数组操作时，点表示法是一种便捷的访问嵌套元素的方式
4. 数组操作方法对大型数组可能会有性能影响，使用时需注意

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-02-03 20:08:03