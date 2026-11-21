# Sqlite 类

## 类简介

Sqlite类是Candy框架的SQLite数据库驱动实现，继承自DBServer基类，提供了完整的SQLite数据库操作功能。
主要负责数据库连接管理、SQL执行、事务处理等核心数据库操作，并具有连接池管理、参数化查询、自动重试等特性。

## 命名空间

```php
namespace Candy\Extend\DB\Driver;
```

## 类定义

```php
/**
 * Sqlite类 - SQLite数据库驱动类
 * 
 * 该类是Candy框架的SQLite数据库驱动实现，继承自DBServer基类，提供了完整的数据库操作功能，
 * 包括自动连接管理、参数绑定、事务支持、自动重试机制等。
 * 该类为最终类，不允许继承。
 */
final class Sqlite extends DBServer {
    // 类成员
}
```

## 主要方法

### query()

```php
/**
 * 执行SQL查询并返回结果
 * 
 * 此方法是数据库操作的核心，实现SQL执行、参数绑定、自动重试、性能统计等功能。
 * 根据SQL类型返回不同的结果集格式。
 * 
 * @param string $sql 要执行的SQL语句
 * @param string $method 查询类型（select,find,total,insert,update,delete,other）
 * @param array $data 为prepare方法中的?参数绑定值
 * @return array|int|string|bool 根据查询类型返回不同格式的结果
 * @example
 * $db->query("SELECT * FROM users WHERE id = ?", "find", [1]);
 * $db->query("INSERT INTO users(name,age) VALUES(?,?)", "insert", ["张三", 25]);
 */
public function query(string $sql, string $method = 'other', array $data = []): mixed
```

### setTable()

```php
/**
 * 设置并初始化表信息
 * 
 * 此方法负责设置表信息并进行表结构缓存，获取表的字段信息和主键信息。
 * 
 * @param string $tabName 表名
 * @return void
 * @example
 * $db->setTable("users"); // 设置操作表为users
 */
public function setTable(string $tabName): void
```

### beginTransaction()

```php
/**
 * 开始数据库事务
 * 
 * 启动一个新的数据库事务，所有后续操作将在事务内执行
 * 直到调用commit()提交或rollBack()回滚
 * 
 * @return bool|null 开始事务是否成功
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
 * 将当前事务中所有执行的操作永久保存到数据库
 * 并结束当前事务
 * 
 * @return bool|null 提交事务是否成功
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
 * 撤销当前事务中所有执行的操作
 * 并结束当前事务
 * 
 * @return bool|null 回滚事务是否成功
 * @example
 * $db->rollBack();
 */
public function rollBack(): ?bool
```

### dbSize()

```php
/**
 * 获取数据库大小
 * 
 * 查询并返回SQLite数据库文件的总大小，以格式化后的字符串形式返回
 * 
 * @return string 格式化的数据库大小（包含适当单位）
 * @example
 * $size = $db->dbSize(); // 例如返回 "10.5 KB"
 */
public function dbSize(): string
```

### dbVersion()

```php
/**
 * 获取数据库版本信息
 * 
 * 查询并返回SQLite数据库引擎的版本信息
 * 
 * @return string 数据库版本字符串
 * @example
 * $version = $db->dbVersion(); // 例如返回 "3.35.5"
 */
public function dbVersion(): string
```

## 内部方法

以下是Sqlite类中的内部方法，仅供类内部使用：

### connect()

```php
/**
 * 获取数据库连接
 * 
 * 实现数据库连接管理，包括连接池检查、连接有效性验证、连接过期检查等功能
 * 
 * @return \PDO PDO连接对象
 */
protected function connect(): \PDO
```

### isConnectionExpired()

```php
/**
 * 检查数据库连接是否过期
 * 
 * 根据连接的最后使用时间，判断连接是否已经超过了设定的超时时间
 * 
 * @param string $dbname 数据库名称，作为连接标识符
 * @return bool 如果连接过期返回true，否则返回false
 */
private function isConnectionExpired(string $dbname): bool
```

### isConnectionValid()

```php
/**
 * 检查数据库连接是否有效
 * 
 * 通过执行简单的SQL语句（SELECT 1）来验证数据库连接是否正常工作
 * 
 * @param \PDO $connection PDO连接对象
 * @return bool 如果连接有效返回true，否则返回false
 */
private function isConnectionValid(\PDO $connection): bool
```

### getDB()

```php
/**
 * 创建并返回数据库连接对象
 * 
 * 此方法负责创建新的PDO连接，设置连接参数，处理数据库文件的创建和目录管理
 * 
 * @return \PDO|null PDO连接对象，失败时返回null
 */
protected function getDB(): ?\PDO
```

### retryQuery()

```php
/**
 * 执行查询重试
 * 
 * 当数据库查询失败时，此方法实现自动重试机制
 * 最多重试MAX_RECONNECT_ATTEMPTS次，适用于临时网络问题等情况
 * 
 * @param string $sql SQL语句
 * @param string $method 查询类型
 * @param array $data 参数数据
 * @param object $instance 当前实例
 * @param int $attempts 当前尝试次数
 * @return array|int|string|bool 根据查询类型返回不同格式的结果
 */
private static function retryQuery(string $sql, string $method, array $data, object $instance, int $attempts = 0): mixed
```

## 使用说明

### 基本用法

```php
// 初始化数据库连接
$db = new \Candy\Extend\DB\Driver\Sqlite([
    'dbname' => '/path/to/database.db',
    'TABPREFIX' => 'pre_'
]);

// 设置操作表
$db->setTable('users');

// 执行查询
$user = $db->query("SELECT * FROM pre_users WHERE id = ?", "find", [1]);

// 执行插入
$id = $db->query("INSERT INTO pre_users(name,age) VALUES(?,?)", "insert", ["张三", 25]);

// 执行更新
$affected = $db->query("UPDATE pre_users SET age = ? WHERE id = ?", "update", [26, 1]);

// 执行删除
$affected = $db->query("DELETE FROM pre_users WHERE id = ?", "delete", [1]);
```

### 事务操作

```php
try {
    $db->beginTransaction();
    
    // 执行多个操作
    $db->query("UPDATE accounts SET balance = balance - 100 WHERE user_id = ?", "update", [1]);
    $db->query("UPDATE accounts SET balance = balance + 100 WHERE user_id = ?", "update", [2]);
    $db->query("INSERT INTO transactions(from_id,to_id,amount) VALUES(?,?,?)", "insert", [1, 2, 100]);
    
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
$users = $db->query("SELECT * FROM pre_users WHERE name LIKE ?", "select", [$keyword]);
```

### 获取数据库信息

```php
// 获取数据库大小
$size = $db->dbSize();
echo "数据库大小: " . $size;

// 获取数据库版本
$version = $db->dbVersion();
echo "数据库版本: " . $version;
```

## 注意事项

1. 该类为最终类（final），不允许继承
2. 数据库文件路径可以在配置中指定，如果未指定则默认为CANDYROOT/Date/queue.db
3. 数据库文件会自动创建，包括其父目录
4. 在CLI模式下，连接会进行超时检查，避免使用已过期的连接
5. 表结构信息会自动缓存，提高查询效率
6. 所有数据库操作都支持参数化查询，可以有效防止SQL注入攻击
7. 内置的自动重试机制可以提高系统在数据库文件临时不可访问情况下的稳定性
8. 事务操作完成后会自动恢复自动提交模式

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-05 10:00:00