# HashMap 使用文档

## 概述

HashMap类是Candy框架中的键值对存储与管理工具，提供了一个基于PHP数组实现的哈希表数据结构，用于高效存储和管理键值对数据。支持键值对的添加、获取、删除、查询等操作，提供了类似Java HashMap的API接口。

**注意：** 此类属于核心补充类库，是Candy框架核心功能的扩展。

## 快速入门

### 基本使用流程

```php
use Extend\HashMap;

// 创建HashMap实例
$map = new HashMap();

// 添加键值对
$map->put('name', '张三');
$map->put('age', 25);
$map->put('isStudent', false);

// 获取值
$name = $map->get('name'); // 返回: '张三'

// 删除键值对
$removedValue = $map->remove('age'); // 返回: 25

// 检查是否包含键
$hasName = $map->containsKey('name'); // 返回: true
```

## 核心功能详解

### 1. 创建与初始化

```php
/**
 * 创建HashMap实例
 *
 * @return HashMap 新创建的HashMap实例
 */
$map = new HashMap();
```

### 2. 键值对操作

#### 添加或更新键值对
```php
/**
 * 向HashMap中添加或更新一个键值对
 *
 * @param mixed $key 要存储的键，可以是任意可哈希的PHP类型
 * @param mixed $value 要存储的值，可以是任意PHP类型
 * @return mixed|null 如果键已存在则返回被替换的旧值，否则返回null
 */
$oldValue = $map->put('name', '张三'); // 添加新键值对，返回: null
$oldValue = $map->put('name', '李四'); // 更新现有键值对，返回: '张三'
```

#### 获取值
```php
/**
 * 根据指定的键获取对应的值
 *
 * @param mixed $key 要查找的键
 * @return mixed|null 如果键存在则返回对应的值，否则返回null
 */
$value = $map->get('name'); // 返回: '李四'
$nonExistent = $map->get('unknown'); // 返回: null
```

#### 删除键值对
```php
/**
 * 从HashMap中删除指定键的键值对
 *
 * @param mixed $key 要删除的键
 * @return mixed|null 如果键存在则返回被删除的值，否则返回null
 */
$removedValue = $map->remove('name'); // 返回: '李四'
$nonExistent = $map->remove('unknown'); // 返回: null
```

### 3. 批量操作

#### 合并HashMap
```php
/**
 * 将另一个HashMap中的所有键值对添加到当前HashMap中
 *
 * @param HashMap $map 另一个HashMap对象
 * @return void
 */
$map1 = new HashMap();
$map1->put('id', 1);
$map1->put('name', '张三');

$map2 = new HashMap();
$map2->put('name', '李四'); // 与map1中的'name'键冲突
$map2->put('age', 30);

$map1->putAll($map2);
// 合并后map1的内容:
// 'id' => 1
// 'name' => '李四' (原值被替换)
// 'age' => 30
```

#### 清空HashMap
```php
/**
 * 清空HashMap中的所有键值对
 *
 * @return bool 始终返回true
 */
$map->removeAll(); // 返回: true，HashMap变为空
```

### 4. 查询与检查

#### 检查键是否存在
```php
/**
 * 检查HashMap中是否包含指定的键
 *
 * @param mixed $key 要检查的键
 * @return bool 如果存在则返回true，否则返回false
 */
$hasKey = $map->containsKey('name'); // 返回: true
$noKey = $map->containsKey('unknown'); // 返回: false
```

#### 检查值是否存在
```php
/**
 * 检查HashMap中是否包含指定的值
 *
 * @param mixed $value 要检查的值
 * @return bool 如果存在则返回true，否则返回false
 */
$hasValue = $map->containsValue('张三'); // 返回: true
$noValue = $map->containsValue('未知值'); // 返回: false
```

#### 获取所有键
```php
/**
 * 获取HashMap中所有键的列表
 *
 * @return array 包含所有键的数组
 */
$keys = $map->keys(); // 返回: ['name', 'age', 'isStudent']
```

#### 获取所有值
```php
/**
 * 获取HashMap中所有值的列表
 *
 * @return array 包含所有值的数组
 */
$values = $map->values(); // 返回: ['张三', 25, false]
```

### 5. 大小与状态检查

#### 获取键值对数量
```php
/**
 * 获取HashMap中键值对的数量
 *
 * @return int 键值对数量
 */
$size = $map->size(); // 返回: 3
```

#### 检查是否为空
```php
/**
 * 检查HashMap是否为空
 *
 * @return bool 如果为空则返回true，否则返回false
 */
$isEmpty = $map->isEmpty(); // 返回: false
```

## 注意事项

1. **数据类型支持**：键可以是任意可哈希的PHP类型，值可以是任意PHP类型
2. **性能考虑**：
   - 键值对的添加、获取、删除操作的平均时间复杂度为O(1)
   - `containsValue()`方法使用线性查找，对于大型数据集可能会影响性能
3. **键的唯一性**：每个键在HashMap中只能出现一次，重复添加会覆盖现有值
4. **null值处理**：
   - 支持存储null值
   - `get()`方法在键不存在时返回null，无法区分键不存在和值为null的情况
5. **线程安全**：HashMap不是线程安全的，在并发环境中使用需要额外的同步机制
6. **数组语法**：该类没有实现PHP的ArrayAccess接口，不能直接使用数组语法访问

## 常见问题

1. **如何判断键是否存在？**
   - 使用`containsKey()`方法，而不是依赖`get()`方法返回null来判断
   ```php
   if ($map->containsKey('key')) {
       // 键存在
   }
   ```

2. **如何处理null值？**
   - HashMap支持存储null值
   - 要区分键不存在和值为null的情况，需要先使用`containsKey()`检查键是否存在
   ```php
   if ($map->containsKey('key')) {
       $value = $map->get('key');
       if ($value === null) {
           // 键存在，但值为null
       } else {
           // 键存在，且值不为null
       }
   } else {
       // 键不存在
   }
   ```

3. **如何遍历HashMap？**
   - 可以结合`keys()`和`get()`方法实现遍历
   ```php
   foreach ($map->keys() as $key) {
       $value = $map->get($key);
       echo "$key => $value\n";
   }
   ```

## 代码示例

### 完整的数据管理示例

```php
use Extend\HashMap;

// 创建用户信息HashMap
$userMap = new HashMap();

// 添加用户信息
$userMap->put('id', 1001);
$userMap->put('username', 'zhangsan');
$userMap->put('email', 'zhangsan@example.com');
$userMap->put('age', 28);
$userMap->put('isActive', true);
$userMap->put('roles', ['user', 'editor']);

// 查询用户信息
if ($userMap->containsKey('username')) {
    echo '用户名: ' . $userMap->get('username') . PHP_EOL;
}

if ($userMap->containsValue(true)) {
    echo '用户状态: 活跃' . PHP_EOL;
}

// 更新用户信息
$oldAge = $userMap->put('age', 29);
echo '年龄更新: 从' . $oldAge . '岁改为29岁' . PHP_EOL;

// 删除不需要的信息
$userMap->remove('isActive');

// 显示所有用户信息
$size = $userMap->size();
echo "共有$size个用户信息字段:\n";

foreach ($userMap->keys() as $key) {
    $value = $userMap->get($key);
    if (is_array($value)) {
        $value = implode(', ', $value);
    }
    echo "- $key: $value\n";
}

// 清空用户信息
$userMap->removeAll();
echo '用户信息已清空，是否为空: ' . ($userMap->isEmpty() ? '是' : '否') . PHP_EOL;
```

### 与普通数组的比较示例

```php
use Extend\HashMap;

// 使用普通数组
$array = [];
$array['name'] = '张三';
$array['age'] = 25;

// 使用HashMap
$map = new HashMap();
$map->put('name', '张三');
$map->put('age', 25);

// 获取值
$arrayName = $array['name'] ?? null;
$mapName = $map->get('name');

// 检查键是否存在
$arrayHasName = isset($array['name']);
$mapHasName = $map->containsKey('name');

// 获取大小
$arraySize = count($array);
$mapSize = $map->size();

// 清空
$array = [];
$map->removeAll();
```
