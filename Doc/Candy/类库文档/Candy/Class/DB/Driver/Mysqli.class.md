# Mysqli 类

## 概述

*
 * Candy框架 数据库Mysqli驱动类
 * 
 * 该类是Candy框架的MySQL数据库驱动实现，提供了完整的数据库操作功能，包括：
 * - 自动连接管理与连接池支持
 * - 安全的SQL执行与参数绑定
 * - 事务支持（开始、提交、回滚）
 * - 自动重试机制处理连接断开情况
 * - 表结构缓存优化
 * - CLI模式下的特殊处理
 * 
 * 核心功能特点：
 * - 支持参数化查询，防止SQL注入
 * - 连接池管理，提高性能
 * - 自动检测连接有效性，超时自动重连
 * - 支持表结构缓存，减少数据库查询
 * - 提供详细的调试信息和执行统计
 * 
 * 使用场景：
 * - Web应用数据库操作
 * - CLI命令行脚本数据库交互
 * - 需要事务支持的业务处理
 * - 大数据量查询优化
 * 
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-05 14:00:00
 * @final 该类为最终类，不允许继承

## 主要方法


### /***
 * Candy框架 数据库Mysqli驱动类
 * 
 * 该类是Candy框架的MySQL数据库驱动实现，提供了完整的数据库操作功能，包括：
 * - 自动连接管理与连接池支持
 * - 安全的SQL执行与参数绑定
 * - 事务支持（开始、提交、回滚）
 * - 自动重试机制处理连接断开情况
 * - 表结构缓存优化
 * - CLI模式下的特殊处理
 * 
 * 核心功能特点：
 * - 支持参数化查询，防止SQL注入
 * - 连接池管理，提高性能
 * - 自动检测连接有效性，超时自动重连
 * - 支持表结构缓存，减少数据库查询
 * - 提供详细的调试信息和执行统计
 * 
 * 使用场景：
 * - Web应用数据库操作
 * - CLI命令行脚本数据库交互
 * - 需要事务支持的业务处理
 * - 大数据量查询优化
 * 
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-05 14:00:00
 * @final 该类为最终类，不允许继承
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Driver;
use \Candy\Extend\DB\DBServer;
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Mysqli extends DBServer {
	static $count = 0;
	static $execute = 0;
	static $totalcate = 0;
	static $links = [];
	static $results = [];
	// 连接池管理属性
	static $lastUsed = []; // 记录每个连接最后使用时间
	const CONNECTION_TIMEOUT = 300; // 连接超时时间（秒）
	const MAX_RECONNECT_ATTEMPTS = 3; // 最大重连尝试次数
	
	/**
	 * 获取或创建数据库连接
	 *
	 * 该方法实现了数据库连接池管理，优先返回已存在的连接（如果有效），
	 * 否则创建新的连接。在CLI模式下会额外检查连接的有效性和过期状态。
	 *
	 * @return mysqli MySQLi数据库连接对象
	 * @throws Exception 当Mysqli扩展未加载时抛出异常
	 */
	protected function connect():()


### /**
	 * 检查数据库连接是否已过期
	 *
	 * 根据连接的最后使用时间和配置的超时时间，判断连接是否需要重新建立。
	 * 如果连接不存在于lastUsed数组中，也被视为过期。
	 *
	 * @param string $dbname 数据库名称
	 * @return bool 如果连接已过期返回true，否则返回false
	 */
	private function isConnectionExpired(string $dbname):()


### /**
	 * 检查数据库连接是否有效
	 *
	 * 通过执行ping()方法测试连接是否仍然活跃。捕获可能的异常并返回false。
	 *
	 * @param mysqli $mysqli MySQLi连接对象
	 * @return bool 如果连接有效返回true，否则返回false
	 */
	private function isConnectionValid(object $mysqli):()


### /**
	 * 创建新的数据库连接对象
	 *
	 * 该方法负责创建并初始化MySQLi连接对象，设置字符集等连接参数。
	 * 在CLI模式下会使用非持久连接并设置特定的超时参数。
	 *
	 * @return mysqli 新创建的MySQLi数据库连接对象
	 * @throws Exception 当连接失败时抛出异常
	 */
	protected function getDB():()


### /**
	 * 执行SQL语句的方法，支持参数化查询和自动重试机制
	 *
	 * 该方法是执行所有数据库操作的核心，支持多种查询类型并根据类型返回相应结果。
	 * 内置自动重试机制，当遇到连接断开等问题时会自动尝试重新连接执行。
	 *
	 * @param string $sql SQL语句，可以包含?占位符
	 * @param string $method SQL语句的类型
	 *                      - select: 查询多条记录，返回二维数组
	 *                      - find: 查询单条记录，返回一维数组或false
	 *                      - total: 返回总记录数，返回整数
	 *                      - insert: 插入数据，返回自增ID或布尔值
	 *                      - update: 更新数据，返回受影响行数
	 *                      - delete: 删除数据，返回受影响行数
	 *                      - other: 其他操作，返回布尔值
	 * @param array $data 为prepare方法中的?参数绑定的值数组
	 * @return array|int|string|bool 根据不同的SQL语句类型返回不同格式的结果
	 *         - select: 返回二维关联数组
	 *         - find: 返回一维关联数组或false
	 *         - total: 返回整数
	 *         - insert: 返回自增ID或布尔值
	 *         - update/delete: 返回受影响行数
	 *         - other: 返回布尔值表示成功或失败
	 * @throws Exception 当数据库连接失败或SQL执行出错时
	 *
	 * @example
	 * // 查询多条记录
	 * $users = $db->query("SELECT * FROM users WHERE status = ?", "select", [1]);
	 * 
	 * // 查询单条记录
	 * $user = $db->query("SELECT * FROM users WHERE id = ?", "find", [10]);
	 * 
	 * // 插入数据
	 * $id = $db->query("INSERT INTO users(name, email) VALUES(?, ?)", "insert", ["张三", "zhangsan@example.com"]);
	 */
	public function query(string $sql,string $method = 'other',array $data = []):()


### /**
	 * 自动重试查询方法，处理连接断开情况
	 *
	 * 该方法是查询执行的核心包装器，能够检测连接断开等错误，并在配置的最大尝试次数内
	 * 自动重新建立连接并执行查询。同时记录查询执行时间和相关调试信息。
	 *
	 * @param string $sql SQL语句
	 * @param string $method 查询方法类型
	 * @param array $data 参数数组
	 * @param object $instance 当前数据库驱动实例
	 * @param int $attempts 当前尝试次数，默认为0
	 * @return mixed 查询结果，格式取决于查询类型
	 */
	private static function retryQuery(string $sql, string $method, array $data, object $instance, int $attempts = 0):()


### /**
	 * 获取查询结果集中的所有记录
	 *
	 * 该方法将预处理语句的结果集转换为关联数组格式，每一行数据作为一个关联数组，
	 * 所有行组成一个二维数组返回。
	 *
	 * @param mysqli_stmt $stmt MySQLi预处理语句对象
	 * @return array 二维关联数组，每行记录为一个关联数组
	 */
	private function getAll(\mysqli_stmt $stmt):()


### /**
	 * 获取查询结果集中的第一条记录
	 *
	 * 该方法将预处理语句的结果集的第一条记录转换为关联数组格式返回。
	 * 适用于只需要单条记录的查询场景。
	 *
	 * @param mysqli_stmt $stmt MySQLi预处理语句对象
	 * @return array 一维关联数组，表示查询结果的第一条记录
	 */
	private function getOne(\mysqli_stmt $stmt):()


### /**
	 * 自动获取表结构并缓存，优化后续操作性能
	 *
	 * 该方法负责获取指定表的结构信息，包括字段列表、主键信息和自动增长设置，并将结果缓存到文件或缓存系统中，
	 * 避免重复查询数据库，提高系统性能。如果是调试模式，则不使用缓存。
	 *
	 * @param string $tabName 表名（不包含前缀）
	 * @return void
	 * @throws Exception 当表不存在时会抛出异常
	 *
	 * @example
	 * // 设置要操作的表
	 * $db->setTable('users');
	 * 
	 * // 之后可以执行针对该表的操作
	 * $users = $db->query("SELECT * FROM {$db->tabName} WHERE status = 1", "select");
	 */
	public function setTable(string $tabName):()


### /**
	 * 开始数据库事务
	 *
	 * 该方法用于启动一个新的数据库事务，在事务中执行的所有操作可以通过commit提交或rollBack回滚。
	 * 事务开始后，数据库连接的autocommit模式会被设置为false，需要手动调用commit或rollBack结束事务。
	 *
	 * @return void
	 * @see commit() 提交事务
	 * @see rollBack() 回滚事务
	 *
	 * @example
	 * // 开始事务
	 * $db->beginTransaction();
	 * try {
	 *     // 执行多个操作
	 *     $db->query("UPDATE accounts SET balance = balance - 100 WHERE id = 1", "update");
	 *     $db->query("UPDATE accounts SET balance = balance + 100 WHERE id = 2", "update");
	 *     // 提交事务
	 *     $db->commit();
	 * } catch (Exception $e) {
	 *     // 发生错误时回滚
	 *     $db->rollBack();
	 *     throw $e;
	 * }
	 */
	public function beginTransaction():()

