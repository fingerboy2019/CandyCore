# Pdo 类

## 类简介

Pdo类是Candy框架的PDO数据库驱动实现，继承自DBServer基类，提供了完整的数据库操作功能。
主要负责数据库连接管理、SQL执行、事务处理等核心数据库操作，并具有连接池管理、参数化查询、自动重试等特性。

## 命名空间

```php
namespace Candy\Extend\DB\Driver;
```

## 类定义

```php
/**
 * Pdo类 - 数据库PDO驱动类
 * 
 * 该类是Candy框架的PDO数据库驱动实现，继承自DBServer基类，提供了完整的数据库操作功能，
 * 包括自动连接管理、参数绑定、事务支持、自动重试机制等。
 * 该类为最终类，不允许继承。
 */
final class Pdo extends DBServer {
    // 类成员
}
```

## 主要方法

### query()

```php
/**
 * 执行SQL语句的方法，支持自动重试机制
 * 
 * 该方法是执行数据库操作的核心方法，具有SQL执行、参数绑定、自动重试、性能统计等功能。
 * 根据SQL类型返回不同的结果集格式。
 * 
 * @param string $sql 要执行的SQL语句
 * @param string $method SQL语句的类型（select,find,total,insert,update,delete,other）
 * @param array $data 要绑定到SQL语句的值数组
 * @return array|int|string|bool 根据SQL类型返回不同的结果
 * @example
 * $db->query("SELECT * FROM user WHERE id=?", "find", [1]);
 * $db->query("INSERT INTO user(name,age) VALUES(?,?)", "insert", ["张三", 25]);
 */
public function query(string $sql, string $method = 'other', array $data = []): array|int|string|bool
```

### setTable()

```php
/**
 * 自动获取和缓存表结构信息
 * 
 * 该方法负责获取数据库表的结构信息，并实现了表结构缓存机制，提高查询效率。
 * 
 * @param string $tabName 表名（不包含表前缀）
 * @return void
 * @example
 * $db->setTable("user"); // 设置操作表为user
 */
public function setTable(string $tabName): void
```

### beginTransaction()

```php
/**
 * 开始数据库事务
 * 
 * 该方法启动一个新的数据库事务，关闭自动提交模式。
 * 在事务中执行的所有SQL语句将作为一个原子操作提交或回滚。
 * 
 * @return ?bool 成功返回true，失败返回false或null
 * @example
 * $db->beginTransaction();
 */
public function beginTransaction(): ?bool
```

### commit()

```php
/**
 * 提交数据库事务
 * 
 * 该方法提交当前事务，使所有在事务中执行的修改永久化到数据库。
 * 提交后会自动恢复自动提交模式。
 * 
 * @return ?bool 成功返回true，失败返回false或null
 * @example
 * $db->commit();
 */
public function commit(): ?bool
```

### rollBack()

```php
/**
 * 回滚数据库事务
 * 
 * 该方法取消当前事务中执行的所有修改，将数据库恢复到事务开始前的状态。
 * 回滚后会自动恢复自动提交模式。
 * 
 * @return ?bool 成功返回true，失败返回false或null
 * @example
 * $db->rollBack();
 */
public function rollBack(): ?bool
```

### retryQuery()

```php
/**
 * 静态重试方法，用于处理数据库连接断开时的自动重试
 * 
 * 该方法实现了数据库操作的自动重试机制，当遇到连接错误时自动尝试重新连接。
 * 
 * @param callable $callback 要执行的回调函数，通常包含数据库操作代码
 * @param int $attempts 当前已尝试的重试次数，默认从0开始
 * @return mixed 返回回调函数的执行结果
 * @example
 * Pdo::retryQuery(function() use ($db) {
 *     return $db->query("SELECT * FROM user");
 * });
 */
public static function retryQuery(callable $callback, int $attempts = 0): mixed
```

### dbSize()

```php
/**
 * 获取数据库使用大小
 * 
 * 该方法计算数据库中所有表的总大小（数据大小+索引大小）
 * 
 * @return string 返回格式化后的数据库大小（包含适当的单位，如KB、MB、GB等）
 * @example
 * $size = $db->dbSize(); // 例如返回 "10.5 MB"
 */
public function dbSize(): string
```

### dbVersion()

```php
/**
 * 获取数据库服务器版本
 * 
 * 该方法返回当前连接的数据库服务器版本信息
 * 
 * @return string 返回数据库服务器的版本号字符串
 * @example
 * $version = $db->dbVersion(); // 例如返回 "5.7.33"
 */
public function dbVersion(): string
```

## 内部方法

以下是Pdo类中的内部方法，仅供类内部使用：

### connect()

```php
/**
 * 获取数据库连接PDO对象
 * 
 * 该方法负责管理数据库连接，实现了连接池机制和连接有效性检查。
 * 
 * @return object 返回PDO连接对象实例
 */
protected function connect(): object
```

### isConnectionExpired()

```php
/**
 * 检查连接是否超时（主要用于CLI模式）
 * 
 * 该方法检查数据库连接的最后使用时间，判断连接是否已超过设定的超时时间。
 * 
 * @param string $dbKey 数据库标识符，用于在连接池中查找对应的连接
 * @return bool 如果连接存在且已超时返回true，否则返回false
 */
private function isConnectionExpired(string $dbKey): bool
```

### isConnectionValid()

```php
/**
 * 检查数据库连接是否有效
 * 
 * 该方法通过执行一个简单的SQL查询测试PDO连接是否仍然有效。
 * 
 * @param \PDO $pdo PDO连接对象实例
 * @return bool 连接有效返回true，无效返回false
 */
private function isConnectionValid(object $pdo): bool
```

### getDB()

```php
/**
 * 创建新的数据库连接对象
 * 
 * 该方法负责创建新的PDO连接实例，并根据运行模式设置不同的连接参数。
 * 
 * @return object|null 返回新创建的PDO连接对象实例，连接失败时返回null
 */
protected function getDB()
```

## 使用说明

### 基本用法

```php
// 初始化数据库连接
$db = new \Candy\Extend\DB\Driver\Pdo([
    'host'    => 'localhost',
    'user'    => 'root',
    'pass'    => 'password',
    'dbname'  => 'testdb',
    'charset' => 'utf8mb4',
    'TABPREFIX' => 'pre_'
]);

// 设置操作表
$db->setTable('user');

// 执行查询
$user = $db->query("SELECT * FROM pre_user WHERE id=?", "find", [1]);

// 执行插入
$id = $db->query("INSERT INTO pre_user(name,age) VALUES(?,?)", "insert", ["张三", 25]);

// 执行更新
$affected = $db->query("UPDATE pre_user SET age=? WHERE id=?", "update", [26, 1]);

// 执行删除
$affected = $db->query("DELETE FROM pre_user WHERE id=?", "delete", [1]);
```

### 事务操作

```php
try {
    $db->beginTransaction();
    
    // 执行多个操作
    $db->query("UPDATE account SET balance=balance-100 WHERE user_id=?", "update", [1]);
    $db->query("UPDATE account SET balance=balance+100 WHERE user_id=?", "update", [2]);
    $db->query("INSERT INTO transaction(from_id,to_id,amount) VALUES(?,?,?)", "insert", [1, 2, 100]);
    
    // 提交事务
    $db->commit();
} catch (Exception $e) {
    // 出现错误，回滚事务
    $db->rollBack();
    echo "事务执行失败: " . $e->getMessage();
}
```

### 参数化查询

```php
// 使用参数化查询防止SQL注入
$keyword = "%张%";
$users = $db->query("SELECT * FROM pre_user WHERE name LIKE ?", "select", [$keyword]);
```

### 自动重试

```php
// 使用自动重试机制处理连接断开的情况
$result = Pdo::retryQuery(function() use ($db) {
    return $db->query("SELECT * FROM pre_user LIMIT 10");
});
```

## 注意事项

1. 该类为最终类（final），不允许继承
2. 在CLI模式下，连接会进行超时检查，避免使用已过期的连接
3. 表结构信息会自动缓存，提高查询效率
4. 所有数据库操作都支持参数化查询，可以有效防止SQL注入攻击
5. 内置的自动重试机制可以提高系统在网络不稳定情况下的稳定性
6. 事务操作完成后会自动恢复自动提交模式

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-05 14:00:00