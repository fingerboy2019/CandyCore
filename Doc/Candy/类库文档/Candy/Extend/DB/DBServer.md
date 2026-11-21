# DBServer 类

## 类简介

DBServer类是Candy框架中的数据库操作抽象基类，提供了统一的数据库操作接口和核心功能。
该类定义了数据库操作的基本方法和属性，为具体的数据库驱动提供了实现规范。
主要功能包括：连贯操作查询、数据验证和过滤、软删除、完整的增删改查操作、关联查询、事务处理、数据缓存等。

## 命名空间

```php
namespace Candy\Extend\DB;
use \Candy\Core\{Debug,Validate};
```

## 类定义

```php
/**
 * 数据库操作基类
 * 
 * 这是一个抽象基类，为Candy框架提供统一的数据库操作接口和核心功能。
 * 主要特性：支持连贯操作查询、内置数据验证和过滤、支持软删除功能等。
 */
declare(strict_types=1);
abstract Class DBServer {
    // 类成员变量
}
```

## 主要方法

### setLink()

```php
/**
 * 设置数据库连接配置
 * 
 * 通过指定的配置文件或配置数组设置对应的数据库连接参数。
 * 支持以下三种配置方式：
 * 1. 不传参数：自动调用子类的setDB方法获取配置
 * 2. 字符串：指定配置的键名，从全局配置中获取
 * 3. 数组：直接提供数据库配置参数
 * 
 * @param string|array|null $config 数据库配置，可以是配置键名、配置数组或null
 * @return void
 * @throws \Exception 当数据库配置不完整时会显示错误信息
 * @example
 * \```php
 * // 方式1：使用默认配置
 * $db->setLink();
 * 
 * // 方式2：使用配置键名
 * $db->setLink('user'); // 使用名为'user'的数据库配置
 * 
 * // 方式3：使用配置数组
 * $db->setLink(['HOST' => 'localhost', 'USER' => 'root', 'PASS' => '123456', 'DBNAME' => 'test']);
 * \```
 */
public function setLink(string|array|null $config): void
```

### sqlArgs()

```php
/**
 * 获取或设置SQL构建参数
 * 
 * 用于获取或修改SQL构建过程中使用的各种参数，如where条件、字段、排序等。
 * 支持在原子操作模式下保存和读取参数状态。
 * 
 * @param string $name 参数名称
 * @param mixed $value 参数值，不传则获取参数值
 * @return mixed 获取模式下返回参数值，设置模式下返回null
 * @example
 * \```php
 * // 获取参数值
 * $where = $db->sqlArgs('where');
 * 
 * // 设置参数值
 * $db->sqlArgs('field', ['id', 'name', 'age']);
 * \```
 */
public function sqlArgs(string $name,mixed $value = ''): mixed
```

### total()

```php
/**
 * 获取符合条件的记录总数
 * 
 * 根据指定的条件查询数据库中满足条件的记录数量。
 * 支持数据缓存，可提高查询性能。
 * 
 * @param mixed ...$args 查询条件，支持多种格式
 * @return int 符合条件的记录总数
 * @example
 * \```php
 * // 获取所有记录数
 * $count = $db->total();
 * 
 * // 根据主键获取
 * $count = $db->total(100);
 * 
 * // 根据条件获取
 * $count = $db->total(['status' => 1]);
 * 
 * // 使用连贯操作
 * $count = $db->where(['status' => 1])->total();
 * \```
 */
public function total(...$args): int
```

### select()

```php
/**
 * 查询多条记录
 * 
 * 根据指定条件查询数据库中的记录，返回二维数组结果。
 * 支持字段筛选、条件查询、排序、分组、分页等操作。
 * 支持左关联查询和数据缓存。
 * 
 * @param mixed ...$args 查询条件，支持多种格式
 * @return array 查询结果数组，二维数组格式
 * @example
 * \```php
 * // 查询所有记录
 * $list = $db->select();
 * 
 * // 查询指定条件的记录
 * $list = $db->select(['status' => 1]);
 * 
 * // 使用连贯操作
 * $list = $db->field('id,name,age')
 *           ->where(['status' => 1])
 *           ->order('id desc')
 *           ->limit(10)
 *           ->select();
 * \```
 */
public function select(...$args)
```

### find()

```php
/**
 * 查询单条记录
 * 
 * 根据主键或条件查询单条记录，返回一维数组。
 * 支持左关联查询和数据缓存。
 * 
 * @param string $pri 主键值，不传则使用where条件
 * @return array|null 查询结果数组，一维数组格式，未找到则返回null
 * @example
 * \```php
 * // 根据主键查询
 * $data = $db->find(100);
 * 
 * // 使用where条件查询
 * $data = $db->where(['name' => '张三'])->find();
 * 
 * // 结合字段筛选
 * $data = $db->field('id,name,age')
 *           ->where(['status' => 1])
 *           ->find();
 * \```
 */
public function find(string $pri = '')
```

### insert()

```php
/**
 * 插入单条记录
 * 
 * 向数据库中插入一条新记录，支持数据验证、数据过滤和自动填充。
 * 自动处理时间戳字段（创建时间、更新时间）。
 * 支持修改器处理数据。
 * 
 * @param array $array 要插入的数据数组，默认为$_POST
 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
 * @param int $validate 是否进行数据验证（1:验证，0:不验证）
 * @return mixed 成功返回插入ID，失败返回false
 * @example
 * \```php
 * // 插入一条记录
 * $result = $db->insert(['name' => '张三', 'age' => 25, 'status' => 1]);
 * 
 * // 使用默认过滤但不验证
 * $result = $db->insert(['name' => '张三', 'age' => 25], 1, 0);
 * \```
 */
public function insert(array $array = [],int $filter = 1,int $validate = 0)
```

### update()

```php
/**
 * 更新记录
 * 
 * 更新数据表中符合条件的记录，支持数据验证、数据过滤和自动填充。
 * 自动处理更新时间字段。
 * 支持修改器处理数据。
 * 支持只读字段保护，防止被修改。
 * 
 * @param array $array 要更新的数据数组，默认为$_POST
 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
 * @param int $validate 是否进行数据验证（1:验证，0:不验证）
 * @return mixed 成功返回受影响的行数，失败返回false
 * @example
 * \```php
 * // 更新指定条件的记录
 * $result = $db->where(['id' => 100])->update(['name' => '李四', 'age' => 26]);
 * 
 * // 通过主键更新（数据中包含主键）
 * $result = $db->update(['id' => 100, 'name' => '李四', 'age' => 26]);
 * \```
 */
public function update(array $array = [],int $filter = 1,int $validate = 0)
```

### delete()

```php
/**
 * 删除记录
 * 
 * 根据指定条件删除记录，默认执行软删除（更新删除时间字段）。
 * 支持强制硬删除（完全从数据库删除）。
 * 
 * @param mixed ...$args 删除条件，支持多种格式；最后一个参数为true时执行硬删除
 * @return int|bool 成功返回受影响的行数，失败返回false
 * @example
 * \```php
 * // 软删除（默认）
 * $result = $db->where(['id' => 100])->delete();
 * 
 * // 硬删除
 * $result = $db->where(['id' => 100])->delete(true);
 * 
 * // 根据主键删除
 * $result = $db->delete(100);
 * 
 * // 强制硬删除指定主键
 * $result = $db->delete(100, true);
 * \```
 */
public function delete(...$args): int|bool
```

### setInc()

```php
/**
 * 字段值自增
 * 
 * 将指定字段的值增加指定的数量。
 * 支持条件筛选，精确控制哪些记录需要自增。
 * 
 * @param string $field 要自增的字段名
 * @param int $num 自增的数量，默认为1
 * @return mixed 成功返回受影响的行数，失败返回false
 * @example
 * \```php
 * // 将所有记录的views字段加1
 * $result = $db->setInc('views');
 * 
 * // 将指定条件记录的score字段加5
 * $result = $db->where(['id' => 100])->setInc('score', 5);
 * \```
 */
public function setInc(?string $field = null,int $num = 1)
```

### setDec()

```php
/**
 * 字段值自减
 * 
 * 将指定字段的值减少指定的数量。
 * 支持条件筛选，精确控制哪些记录需要自减。
 * 
 * @param string $field 要自减的字段名
 * @param int $num 自减的数量，默认为1
 * @return mixed 成功返回受影响的行数，失败返回false
 * @example
 * \```php
 * // 将所有记录的stock字段减1
 * $result = $db->setDec('stock');
 * 
 * // 将指定条件记录的points字段减10
 * $result = $db->where(['id' => 100])->setDec('points', 10);
 * \```
 */
public function setDec(?string $field = null,int $num = 1)
```

### r_select()

```php
/**
 * 关联查询
 * 
 * 执行多表关联查询，支持一对一、一对多关系。
 * 可指定关联表、关联字段和关联条件。
 * 支持左关联（LEFT JOIN）等多种关联方式。
 * 支持数据缓存功能。
 * 
 * @param array ...$args 关联表配置数组，每个数组包含：[表名, 字段, 外键, 子查询配置]
 * @return array|bool 查询结果数组或false
 * @example
 * \```php
 * // 简单一对一关联
 * $result = $db->r_select(['user_info', 'address,phone', 'user_id']);
 * 
 * // 指定别名和关联方式
 * $result = $db->r_select([['user_info', 'info'], 'address,phone', 'user_id']);
 * 
 * // 一对多关联（带子查询配置）
 * $result = $db->r_select([
 *     'orders',
 *     '*',
 *     'user_id',
 *     ['orderList', 'id DESC', 10] // 子查询配置：别名、排序、数量
 * ]);
 * \```
 */
public function r_select(...$args): array|bool
```

### r_delete()

```php
/**
 * 关联删除
 * 
 * 删除当前表数据的同时，也删除关联表中的相关数据。
 * 支持软删除和硬删除两种模式。
 * 
 * @param array ...$args 关联表配置数组，每个数组包含：[表名, 外键, 额外条件]
 * @param bool $args[1] 可选，是否执行硬删除
 * @return int|bool 成功返回删除的总记录数，失败返回false
 * @example
 * \```php
 * // 软删除（默认），同时删除关联表数据
 * $result = $db->where(['user_id' => 100])->r_delete([
 *     ['orders', 'user_id'],
 *     ['user_logs', 'user_id']
 * ]);
 * 
 * // 硬删除，同时强制删除关联表数据
 * $result = $db->where(['user_id' => 100])->r_delete([
 *     ['orders', 'user_id'],
 *     ['user_logs', 'user_id']
 * ], true);
 * \```
 */
public function r_delete(...$args): int|bool
```

### getMsg()

```php
/**
 * 获取错误消息
 * 
 * 返回最近一次操作的错误提示信息。
 * 通常用于调试或错误处理。
 * 
 * @return string 错误消息字符串
 * @example
 * \```php
 * // 执行操作后检查错误
 * if(!$db->insert($data)) {
 *     echo '错误信息: ' . $db->getMsg();
 * }
 * \```
 */
public function getMsg(): string
```

## 内部方法

以下是DBServer类中的内部方法（私有方法），仅供类内部使用：

### compileWhere()

```php
/**
 * 用来组合SQL语句中的where条件 
 */ 
private function compileWhere(array $args,string $join = '',string $where = ' WHERE ',string $pri = ''): array
```

### compileJoin()

```php
/**
 * 处理join情况 
 */ 
private function compileJoin(): array
```

### check()

```php
/**
 * 数据验证和过滤
 * 
 * 对输入数据进行验证、过滤和格式化。
 * 处理修改器、类型转换和时间字段自动填充。
 * 
 * @param array $array 待处理的数据数组
 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
 * @return array 处理后的数据数组
 */
private function check(array $array,int $filter): array
```

### checkAttr()

```php
/**
 * 处理获取器
 * 
 * 对查询结果应用字段获取器处理。
 * 
 * @param mixed $data 查询结果数据
 * @param string $fields 查询的字段列表
 * @return mixed 处理后的查询结果
 */
private function checkAttr($data,string $fields = '')
```

### typeChange()

```php
/**
 * 类型转换
 * 
 * 对字段值进行类型转换，支持整型、浮点型、布尔型、数组、JSON等。
 * 
 * @param mixed $key 类型键值
 * @param mixed $value 待转换的值
 * @param string $type 转换类型（'en'：编码，'de'：解码）
 * @return mixed 转换后的值
 */
private function typeChange($key, $value, $type = 'en')
```

### getDataCache() / setDataCache() / delDataCache()

```php
/**
 * 数据缓存操作方法
 * 
 * 用于管理数据库查询结果的缓存，提高查询性能。
 */
private function getDataCache(array $args)
private function setDataCache(array $args,mixed $data): void
private function delDataCache(): void
```

## 抽象方法

DBServer类定义了以下抽象方法，子类必须实现：

### query()

```php
/**
 * 执行SQL查询
 * 
 * @param string $sql SQL查询语句
 * @param string $method 查询方法类型
 * @param array $data 查询参数
 * @return mixed 查询结果
 */
abstract function query(string $sql, string $method, array $data = []): mixed;
```

### setTable()

```php
/**
 * 设置数据表
 * 
 * @param string $tabName 数据表名称
 * @return $this
 */
abstract function setTable(string $tabName): void;
```

### 事务处理相关方法

```php
/**
 * 开始事务
 * 
 * @return bool 是否成功开始事务
 */
abstract function beginTransaction(): ?bool;

/**
 * 提交事务
 * 
 * @return bool 是否成功提交事务
 */
abstract function commit(): ?bool;

/**
 * 回滚事务
 * 
 * @return bool 是否成功回滚事务
 */
abstract function rollBack(): ?bool;
```

### 数据库信息相关方法

```php
/**
 * 获取数据库大小
 * 
 * @return string|int 数据库大小，通常以字节数或格式化字符串返回
 */
abstract function dbSize(): string|int;

/**
 * 获取数据库版本
 * 
 * @return string 数据库版本信息
 */
abstract function dbVersion(): string;
```

## 使用说明

### 基本用法

DBServer是一个抽象类，不能直接实例化，需要使用具体的数据库驱动类，或者通过D()函数来获取数据库实例：

```php
// 使用D函数获取数据库实例
$db = D('User'); // 对应User表的数据库操作实例

// 查询操作
$userList = $db->field('id,name,age')
               ->where(['status' => 1])
               ->order('id desc')
               ->limit(10)
               ->select();

// 插入操作
$userId = $db->insert(['name' => '张三', 'age' => 25, 'status' => 1]);

// 更新操作
$affectedRows = $db->where(['id' => 100])->update(['name' => '李四', 'age' => 26]);

// 删除操作
$affectedRows = $db->where(['id' => 100])->delete();
```

### 连贯操作

DBServer类支持丰富的连贯操作方法，可以链式调用多个方法：

```php
// 复杂查询示例
$result = $db->field('id,name,status')
             ->where(['status' => 1])
             ->where(['age' => ['>', 18]])
             ->order('id desc')
             ->limit(0, 10)
             ->group('department')
             ->having(['count(*)' => ['>', 5]])
             ->select();
```

### 关联查询

使用r_select方法进行关联查询：

```php
// 一对一关联查询
$userInfo = $db->r_select([
    ['user_detail', 'detail'],  // 关联表名和别名
    'address,phone,email',      // 要查询的字段
    'user_id'                   // 外键
]);

// 一对多关联查询
$userOrders = $db->r_select([
    'orders',                   // 关联表名
    '*',                        // 查询所有字段
    'user_id',                  // 外键
    ['orderList', 'id DESC', 5] // 子查询配置：别名、排序、数量限制
]);
```

### 软删除和硬删除

```php
// 软删除（默认）
$db->where(['id' => 100])->delete();

// 硬删除（完全删除）
$db->where(['id' => 100])->delete(true);

// 查询包含已软删除的记录
$allRecords = $db->withtrashed()->select();

// 仅查询已软删除的记录
$deletedRecords = $db->onlytrashed()->select();
```

### 事务处理

```php
// 开始事务
$db->beginTransaction();
try {
    // 执行多个操作
    $db->insert($data1);
    $db->update(['id' => 100], $data2);
    
    // 提交事务
    $db->commit();
} catch (\Exception $e) {
    // 回滚事务
    $db->rollBack();
    echo '事务执行失败：' . $e->getMessage();
}
```

## 注意事项

1. DBServer是一个抽象类，不能直接实例化，必须使用具体的数据库驱动实现类。

2. 数据验证和过滤：
   - 插入和更新操作默认会对数据进行过滤（去除HTML标签和特殊字符）
   - 可以通过设置$filter参数来控制是否过滤数据

3. 软删除功能：
   - 默认使用'deleteTime'字段进行软删除标记
   - 可以通过修改$deleteTime属性来自定义软删除字段名
   - 使用withtrashed()可以查询包含已软删除的记录
   - 使用onlytrashed()可以仅查询已软删除的记录

4. 缓存机制：
   - 查询结果会自动缓存，提高查询性能
   - 写操作（插入、更新、删除）会自动清除相关缓存
   - 随机查询不会被缓存

5. 自动时间戳：
   - 自动处理'AddTime'和'UpTime'字段
   - 可以通过修改$createTime和$updateTime属性自定义字段名

6. 修改器和获取器：
   - 支持通过setFieldNameAttr()方法设置修改器
   - 支持通过getFieldNameAttr()方法设置获取器

7. 安全考虑：
   - 默认使用参数化查询，防止SQL注入
   - 支持CSRF令牌验证，增强安全性

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-05 14:31:47