# DBService 类

## 类简介

DBService类是Candy框架中的数据库备份与还原服务类，提供了完整的数据库管理功能。
该类负责数据库表结构和数据的备份与还原，支持分卷备份、数据压缩，以及表优化、修复、清空和删除等数据库维护操作。
主要功能包括：数据库表结构和数据的备份、分卷备份、数据压缩、数据库表还原、表优化、修复、清空和删除操作、表结构查看等。

## 命名空间

```php
namespace Candy\Extend\DB;
use \Candy\Core\Debug;
```

## 类定义

```php
/**
 * 数据库备份与还原服务类
 * 
 * 提供完整的数据库管理功能，包括：
 * - 数据库表结构和数据的备份
 * - 支持分卷备份（可配置卷大小）
 * - 支持数据压缩（可选GZIP压缩）
 * - 数据库表还原功能
 * - 表优化、修复、清空和删除操作
 * - 表结构查看功能
 * 
 * 核心特点：
 * - 支持分批次处理大数据量，避免内存溢出
 * - 支持多表批量操作
 * - 提供详细的备份元信息
 * - 安全的SQL注入防护
 * - 资源自动管理（自动关闭文件流）
 * 
 */

declare(strict_types=1);
final class DBService {
    // 类成员变量
}
```

## 主要方法

### 构造方法 __construct()

```php
/**
 * 数据库备份构造方法
 *
 * 初始化数据库备份服务，设置文件信息和配置参数。
 * 支持配置备份路径、分卷大小、压缩设置等。
 *
 * @param array $file 备份或还原的文件信息，包含name和part字段
 * @param array $config 备份配置信息，包含path、part、compress、level等参数
 * @param string $type 执行类型，export - 备份数据， import - 还原数据
 * @example
 * \```php
 * // 示例配置
 * $config = [
 *     'path'     => './backup/', // 备份文件目录
 *     'part'     => 3145728,     // 3MB，分卷大小
 *     'compress' => 0,           // 是否压缩
 *     'level'    => 4,           // 压缩级别
 * ];
 * 
 * // 创建备份服务实例
 * $service = new DBService(['name' => 'backup_2024', 'part' => 1], $config);
 * \```
 */
public function __construct(array $file = [], array $config = [], string $type = 'export')
```

### backup()

```php
/**
 * 备份数据库表结构和数据
 * 
 * 支持备份单个表或所有表，自动处理大数据量，分批次进行备份。
 * 备份内容包括表结构定义和数据记录，支持分卷存储。
 * 
 * @param string $table 要备份的表名，留空则备份所有表
 * @param int $start 数据备份的起始行号，用于分批处理大数据表
 * @return boolean true - 备份成功，false - 备份失败
 * @example
 * \```php
 * // 备份所有表
 * $service = new DBService(['name' => 'backup_2024'], ['path' => './backup/', 'part' => 5242880]);
 * $result = $service->backup();
 * 
 * // 备份指定表
 * $service->backup('users');
 * 
 * // 分批备份大数据表（内部递归调用）
 * $service->backup('large_table', 10000);
 * \```
 */
public function backup(string $table = '', int $start = 0): bool
```

### import()

```php
/**
 * 从备份文件还原数据库
 * 
 * 支持从单个或多个备份文件中还原数据，可指定起始位置继续还原。
 * 自动处理压缩的备份文件，按SQL语句分号分隔执行。
 * 
 * @param string $file 指定的备份文件路径，留空则根据file数组查找所有匹配的备份文件
 * @param int $start 还原的起始位置（字节数），用于中断后继续还原
 * @return boolean true - 还原成功，false - 还原失败
 * @example
 * \```php
 * // 还原所有备份文件
 * $service = new DBService(['name' => 'backup_2024'], ['path' => './backup/']);
 * $result = $service->import();
 * 
 * // 还原指定备份文件
 * $service->import('./backup/backup_2024-1.sql');
 * 
 * // 从指定位置继续还原
 * $service->import('./backup/backup_2024-1.sql', 102400);
 * \```
 */
public function import(string $file = '', int $start = 0): bool
```

### optimize()

```php
/**
 * 优化数据库表
 * 
 * 执行OPTIMIZE TABLE命令优化指定的表或所有表，
 * 用于回收已删除记录占用的空间，提高查询性能。
 * 
 * @param array $tables 要优化的表名数组，留空则优化所有表
 * @return void
 * @example
 * \```php
 * // 优化所有表
 * $service->optimize();
 * 
 * // 优化指定的表
 * $service->optimize(['users', 'orders', 'products']);
 * \```
 */
public function optimize(array $tables = []): void
```

### repair()

```php
/**
 * 修复损坏的数据库表
 * 
 * 执行REPAIR TABLE命令修复指定的表或所有表，
 * 用于修复因崩溃、断电等原因导致的数据表损坏。
 * 
 * @param array $tables 要修复的表名数组，留空则修复所有表
 * @return void
 * @example
 * \```php
 * // 修复所有表
 * $service->repair();
 * 
 * // 修复指定的表
 * $service->repair(['users', 'orders']);
 * \```
 */
public function repair(array $tables = []): void
```

### structure()

```php
/**
 * 获取数据库表的创建语句
 * 
 * 查询指定表的完整CREATE TABLE语句，包含表结构、索引、约束等所有定义信息。
 * 用于查看表结构或在其他数据库中重建相同结构的表。
 * 
 * @param string $table 要查询的表名
 * @return string 表的CREATE TABLE语句，如果表名为空则返回空字符串
 * @example
 * \```php
 * // 获取表结构
 * $createSql = $service->structure('users');
 * echo $createSql; // 输出: CREATE TABLE `users` (id INT AUTO_INCREMENT PRIMARY KEY, ...)
 * \```
 */
public function structure(string $table = ''): string
```

### truncate()

```php
/**
 * 清空表数据
 * 
 * 执行TRUNCATE TABLE命令删除表中的所有数据，但保留表结构。
 * 比DELETE语句更高效，会重置自增计数器。
 * 
 * @param string $table 要清空的表名
 * @return void
 * @example
 * \```php
 * // 清空指定表
 * $service->truncate('temporary_data');
 * \```
 */
public function truncate(string $table = ''): void
```

### delete()

```php
/**
 * 删除整个数据库表
 * 
 * 执行DROP TABLE命令删除指定的表，包括表结构和所有数据。
 * 此操作不可逆，请谨慎使用。
 * 
 * @param string $table 要删除的表名
 * @return void
 * @example
 * \```php
 * // 删除指定表
 * $service->delete('obsolete_table');
 * \```
 */
public function delete(string $table = ''): void
```

### 析构方法 __destruct()

```php
/**
 * 析构方法，用于关闭文件资源
 * 
 * 在对象销毁时自动关闭文件指针，确保资源正确释放，
 * 避免文件句柄泄露。根据是否开启压缩选择相应的关闭方法。
 * 
 * @return void
 */
public function __destruct()
```

## 内部方法

以下是DBService类中的内部方法（私有方法），仅供类内部使用：

### open()

```php
/**
 * 打开一个卷，用于写入数据
 *
 * 在备份过程中根据配置的分卷大小，自动创建或打开备份文件。
 * 支持普通文件和GZIP压缩文件。
 *
 * @param int $size 写入数据的大小
 * @return void
 */
private function open(int $size = 0): void
```

### create()

```php
/**
 * 写入初始数据
 *
 * 在每个备份文件的开头写入元数据信息，包括主机信息、数据库名、
 * 备份时间等信息，以及禁用外键检查的SQL语句。
 *
 * @return boolean true - 写入成功，false - 写入失败
 */
private function create(): bool
```

### write()

```php
/**
 * 写入SQL语句
 *
 * 将SQL语句写入备份文件，自动处理分卷和压缩。
 * 根据压缩设置选择不同的写入方法。
 *
 * @param string $sql 要写入的SQL语句
 * @return boolean true - 写入成功，false - 写入失败
 */
private function write(string $sql): bool
```

### getTableList()

```php
/**
 * 获取数据库所有表名
 *
 * 从INFORMATION_SCHEMA获取当前数据库中的所有表名列表，
 * 用于批量操作数据库表。
 *
 * @return void
 */
private function getTableList(): void
```

### safeReplace()

```php
/**
 * 安全替换
 *
 * 移除字符串中的特殊字符，防止SQL注入攻击。
 * 清理可能导致SQL注入的字符，如反引号、反斜杠、引号等。
 *
 * @param string $string 要处理的字符串
 * @return string 处理后的安全字符串
 */
private function safeReplace(string $string): string
```

## 使用说明

### 基本用法

DBService类提供了完整的数据库管理功能，下面是一些常用操作的示例：

```php
// 1. 备份数据库
// 初始化配置
$config = [
    'path'     => './backup/', // 备份文件存储目录
    'part'     => 5242880,     // 5MB，分卷大小
    'compress' => 1,           // 启用压缩
    'level'    => 6            // 压缩级别
];

// 创建备份实例
$service = new DBService(['name' => 'backup_' . date('Ymd')], $config);

// 备份所有表
$result = $service->backup();
if ($result) {
    echo '数据库备份成功！';
} else {
    echo '数据库备份失败！';
}

// 2. 还原数据库
// 创建还原实例
$service = new DBService(['name' => 'backup_20240101'], ['path' => './backup/']);

// 还原数据库
$result = $service->import();
if ($result) {
    echo '数据库还原成功！';
} else {
    echo '数据库还原失败！';
}

// 3. 优化数据库表
$service->optimize(['users', 'orders']);
echo '表优化完成！';

// 4. 修复损坏的表
$service->repair(['products']);
echo '表修复完成！';

// 5. 查看表结构
$createSql = $service->structure('users');
echo $createSql;
```

### 大数据量备份

对于大数据量的数据库，DBService类提供了分批次处理的能力，避免内存溢出：

```php
// 备份大数据表，内部会自动分批处理
$service = new DBService(['name' => 'large_backup'], ['path' => './backup/', 'part' => 10485760]);

// 备份特定的大数据表
$service->backup('very_large_table');

// 注意：对于超大表，可能需要前端配合使用AJAX进行异步分批调用，
// 避免PHP超时限制
```

### 分卷备份和还原

当数据库较大时，可以使用分卷备份功能将备份文件分成多个较小的文件：

```php
// 配置较小的分卷大小（例如2MB）
$config = [
    'path' => './backup/',
    'part' => 2097152,  // 2MB
    'compress' => 1
];

// 创建备份实例
$service = new DBService(['name' => 'volumed_backup'], $config);

// 备份时会自动创建多个分卷文件
// 例如：volumed_backup-1.sql.gz, volumed_backup-2.sql.gz...
$service->backup();

// 还原时不需要指定分卷号，会自动查找所有匹配的备份文件
$service->import();
```

## 注意事项

1. **文件权限**：确保备份目录具有可写权限，否则会导致备份失败。

2. **安全考虑**：
   - 备份文件可能包含敏感数据，请确保备份目录的访问权限设置正确
   - 建议定期将备份文件下载到本地存储，以防服务器故障
   - 还原操作会覆盖现有数据，请谨慎操作

3. **性能优化**：
   - 对于大数据量的备份和还原，建议在低峰期进行
   - 可以调整分卷大小和压缩设置以平衡备份速度和文件大小
   - 压缩会增加CPU使用率，但能显著减小备份文件体积

4. **超时处理**：
   - 处理大数据量时，可能会遇到PHP超时限制
   - 可以在php.ini中调整max_execution_time参数
   - 或者使用前端AJAX进行异步分批调用

5. **资源管理**：
   - 类会通过析构函数自动关闭文件资源，但建议在操作完成后手动设置实例为null
   - 大批量操作时请注意服务器内存使用情况

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-05 12:00:00