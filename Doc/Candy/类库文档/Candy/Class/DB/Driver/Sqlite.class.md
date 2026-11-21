# Sqlite 类

## 概述

* Candy框架 SQLite数据库驱动类
 * 
 * 实现SQLite数据库的连接、查询、事务管理等核心功能
 * 支持连接池管理、自动重连机制和CLI模式优化
 * 
 * @package    Candy\Extend\DB\Driver
 * @author     刘森 (fingerboy@qq.com)
 * @date      2019-08-05 10:00:00
 * @final	 类为最终类，不允许继承

## 主要方法


### /**
 * Candy框架 SQLite数据库驱动类
 * 
 * 实现SQLite数据库的连接、查询、事务管理等核心功能
 * 支持连接池管理、自动重连机制和CLI模式优化
 * 
 * @package    Candy\Extend\DB\Driver
 * @author     刘森 (fingerboy@qq.com)
 * @date      2019-08-05 10:00:00
 * @final	 类为最终类，不允许继承
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Driver;
use \Candy\Extend\DB\DBServer;
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Sqlite extends DBServer {
	static $count = 0;
	static $execute = 0;
	static $totalcate = 0;
	static $links = [];
	static $results = null;
	// 连接池管理相关属性
	static $lastUsed = []; // 记录连接最后使用时间
	const CONNECTION_TIMEOUT = 300; // 连接超时时间（秒）
	const MAX_RECONNECT_ATTEMPTS = 3; // 最大重连次数
	
	/**
	 * 获取或创建SQLite数据库PDO连接对象
	 * 
	 * 实现了连接池管理机制，避免重复创建连接
	 * 在CLI模式下增加连接有效性检查和自动重连机制
	 * 自动更新连接最后使用时间以支持过期管理
	 * 
	 * @return \PDO 数据库连接对象
	 */
	protected function connect():()


### /**
	 * 检查数据库连接是否已过期
	 * 
	 * 根据连接最后使用时间与当前时间的差值判断
	 * 使用CONNECTION_TIMEOUT常量作为超时阈值
	 * 
	 * @param string $dbname 数据库名称，用于索引连接池
	 * @return bool 如果连接已过期返回true，否则返回false
	 */
	private function isConnectionExpired(string $dbname):()


### /**
	 * 检查数据库连接是否有效
	 * 
	 * 通过执行简单的SELECT语句测试连接状态
	 * 捕获PDOException来判断连接是否正常
	 * 
	 * @param \PDO $connection 要检查的PDO连接对象
	 * @return bool 如果连接有效返回true，否则返回false
	 * @throws \PDOException 当连接测试失败时抛出异常，但会被方法内部捕获
	 */
	private function isConnectionValid(\PDO $connection):()


### /**
	 * 创建并返回新的SQLite数据库PDO连接对象
	 * 
	 * 负责数据库文件的创建和目录管理
	 * 根据运行模式设置合适的PDO连接参数
	 * CLI模式下调整超时参数以适应长时间运行的场景
	 * 
	 * @return \PDO|null 成功时返回PDO连接对象，失败时返回null
	 */
	protected function getDB():()


### /**
	 * 执行SQL语句的方法
	 * 
	 * 作为SQL执行的统一入口，支持参数化查询
	 * 根据SQL类型返回不同格式的结果
	 * 内部使用retryQuery方法实现连接断开自动重试
	 * 
	 * @param string $sql 用户查询的SQL语句
	 * @param string $method SQL语句的类型（select,find,total,insert,update,delete,other）
	 * @param array $data 为prepare方法中的?参数绑定值
	 * @return array|int|string|bool 根据不同的SQL语句类型返回不同格式的结果：
	 *         - select: 返回结果集数组
	 *         - find: 返回单条记录数组
	 *         - total: 返回记录总数（整数）
	 *         - insert: 返回自增ID或布尔值
	 *         - update/delete: 返回影响行数
	 *         - other: 返回执行结果布尔值
	 */
	public function query(string $sql,string $method = 'other',array $data = []):()


### /**
	 * 执行带自动重试机制的SQL查询
	 * 
	 * 处理连接断开等临时故障，自动进行重试
	 * 支持多种查询类型并返回对应格式的结果
	 * 记录查询执行时间和执行次数用于性能监控
	 * 
	 * @param string $sql SQL查询语句
	 * @param string $method 查询类型，支持：select、find、total、insert、update、delete、other
	 * @param array $data 绑定到预处理语句的参数数组
	 * @param object $instance 当前SQLite实例对象
	 * @param int $attempts 当前重试次数，默认为0
	 * @return array|int|string|bool 根据查询类型返回不同格式的结果
	 */
	private static function retryQuery(string $sql, string $method, array $data, object $instance, int $attempts = 0):()


### /**
	 * 获取并缓存数据库表结构信息
	 * 
	 * 实现表结构缓存机制，提高性能
	 * 自动识别表的主键和自增字段
	 * 支持文件缓存和Redis缓存（取决于配置）
	 * 
	 * @param string $tabName 表名（不包含前缀）
	 * @return void
	 */ 
	public function setTable(string $tabName):()


### /**
	 * 开始数据库事务
	 * 
	 * 关闭自动提交模式并启动新事务
	 * 用于需要原子性操作的场景
	 * 
	 * @return bool|null 成功返回true，失败返回null
	 */
	public function beginTransaction():()


### /**
	 * 提交当前事务
	 * 
	 * 将事务中所有修改永久保存到数据库
	 * 完成后恢复自动提交模式
	 * 
	 * @return bool|null 成功返回true，失败返回null
	 */
	public function commit():()


### /**
	 * 回滚当前事务
	 * 
	 * 撤销事务中所有未提交的修改
	 * 完成后恢复自动提交模式
	 * 
	 * @return bool|null 成功返回true，失败返回null
	 */
	public function rollBack():()

