# Pgsql 类

## 概述

* PostgreSQL数据库驱动类
 * 
 * 提供基于PDO的PostgreSQL数据库连接、查询、事务管理等功能
 * 实现连接池管理、查询重试、超时检测等高级特性
 * 
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-05 10:48:32
 * @final 类为最终类，不允许继承

## 主要方法


### /**
 * PostgreSQL数据库驱动类
 * 
 * 提供基于PDO的PostgreSQL数据库连接、查询、事务管理等功能
 * 实现连接池管理、查询重试、超时检测等高级特性
 * 
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-05 10:48:32
 * @final 类为最终类，不允许继承
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Driver;
use \Candy\Extend\DB\DBServer;
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Pgsql extends DBServer {
	/**
	 * 数据库连接计数器
	 * 
	 * 记录创建的数据库连接总数
	 * 
	 * @var int 连接计数
	 */
	static $count = 0;
	
	/**
	 * SQL查询执行计数器
	 * 
	 * 记录执行的SQL查询总数
	 * 
	 * @var int 查询计数
	 */
	static $execute = 0;
	
	/**
	 * SQL查询总耗时（毫秒）
	 * 
	 * 记录所有SQL查询的累计执行时间
	 * 
	 * @var float 总耗时
	 */
	static $totalcate = 0;
	
	/**
	 * 数据库连接池
	 * 
	 * 存储活跃的数据库连接，使用数据库名称作为键
	 * 
	 * @var array 连接池数组
	 */
	static $links = [];
	
	/**
	 * 查询结果缓存
	 * 
	 * 存储查询结果，提高性能
	 * 
	 * @var array 结果缓存数组
	 */
	static $results = [];
	
	/**
	 * 连接最后使用时间记录
	 * 
	 * 记录每个连接的最后访问时间，用于判断连接是否过期
	 * 
	 * @var array 时间戳数组
	 */
	static $lastUsed = [];
	
	/**
	 * 连接超时时间（秒）
	 * 
	 * 定义数据库连接的最大空闲时间，超过此时间的连接将被视为过期
	 * 
	 * @var int 超时时间（秒）
	 */
	const CONNECTION_TIMEOUT = 300;
	
	/**
	 * 最大重连尝试次数
	 * 
	 * 定义数据库连接断开时的最大重试次数
	 * 
	 * @var int 最大重连次数
	 */
	const MAX_RECONNECT_ATTEMPTS = 3;
	
	/**
	 * 获取数据库连接
	 * 
	 * 实现数据库连接管理，包括以下功能：
	 * 1. 检查PDO扩展是否加载
	 * 2. 检查连接池中的连接是否存在且有效
	 * 3. CLI模式下进行连接过期检查和有效性验证
	 * 4. 更新连接最后使用时间
	 * 5. 必要时创建新的数据库连接
	 *
	 * @return object PDO连接对象
	 */
	protected function connect():()


### /**
	 * 检查数据库连接是否过期
	 * 
	 * 根据连接的最后使用时间，判断连接是否已经超过了设定的超时时间
	 * 此方法主要用于连接池管理，避免使用过期连接
	 *
	 * @param string $dbname 数据库名称，作为连接标识符
	 * @return bool 如果连接过期返回true，否则返回false
	 */
	private function isConnectionExpired(string $dbname):()


### /**
	 * 检查数据库连接是否有效
	 * 
	 * 通过执行简单的SQL语句（SELECT 1）来验证数据库连接是否正常工作
	 * 此方法用于连接池管理，确保在使用连接前其有效性
	 *
	 * @param \PDO $connection PDO连接对象
	 * @return bool 如果连接有效返回true，否则返回false
	 */
	private function isConnectionValid(object $connection):()


### /**
	 * 创建并返回数据库连接对象
	 * 
	 * 此方法负责创建新的PDO连接，实现以下功能：
	 * 1. 增加连接计数器
	 * 2. 添加调试信息
	 * 3. 构建PostgreSQL的DSN连接字符串
	 * 4. 根据运行环境（CLI/Web）设置优化的连接参数
	 * 5. 创建PDO实例并返回
	 *
	 * @return \PDO|null PDO连接对象，失败时返回null
	 */
	protected function getDB():()


### /**
	 * 执行SQL查询并返回结果
	 * 
	 * 此方法是数据库操作的核心，实现以下功能：
	 * 1. 获取数据库连接
	 * 2. 处理参数化查询
	 * 3. 统计SQL执行次数和执行时间
	 * 4. 根据查询类型返回不同格式的结果
	 * 5. 处理查询失败时的重试机制
	 *
	 * @param string $sql 要执行的SQL语句
	 * @param string $method 查询类型（select,find,total,insert,update,other）
	 * @param array $data 为prepare方法中的?参数绑定值
	 * @return array|int|string|bool 根据查询类型返回不同格式的结果：
	 *               - SELECT查询返回关联数组
	 *               - INSERT查询返回最后插入的ID或布尔值
	 *               - UPDATE/DELETE查询返回影响的行数
	 *               - 其他语句返回布尔值
	 */
	public function query(string $sql,string $method = 'other',array $data = []):()


### /**
	 * 执行查询重试
	 * 
	 * 当数据库查询失败时，此方法实现自动重试机制
	 * 最多重试MAX_RECONNECT_ATTEMPTS次，适用于临时网络问题等情况
	 *
	 * @param string $sql SQL语句
	 * @param string $method 查询类型（select,find,total,insert,update,other）
	 * @param array $data 参数数据，用于参数化查询
	 * @param object $instance 当前实例
	 * @param int $attempts 当前尝试次数，默认为0
	 * @return array|int|string|bool 根据查询类型返回不同格式的结果
	 */
	private static function retryQuery(string $sql, string $method, array $data, object $instance, int $attempts = 0):()


### /**
	 * 设置并初始化表信息
	 * 
	 * 此方法负责设置表信息并进行表结构缓存，实现以下功能：
	 * 1. 缓存表结构信息以提高性能
	 * 2. 查询表的字段信息和主键
	 * 3. 设置表名称和字段信息
	 * 4. 处理数据库连接和错误情况
	 *
	 * @param string $tabName 表名
	 * @return void
	 */
	public function setTable(string $tabName):()


### /**
	 * 开始数据库事务
	 * 
	 * 启动一个新的数据库事务，所有后续操作将在事务内执行
	 * 直到调用commit()提交或rollBack()回滚
	 *
	 * @return bool 开始事务是否成功
	 */
	public function beginTransaction():()


### /**
	 * 提交数据库事务
	 * 
	 * 将当前事务中所有执行的操作永久保存到数据库
	 * 并结束当前事务
	 *
	 * @return bool 提交事务是否成功
	 */
	public function commit():()


### /**
	 * 回滚数据库事务
	 * 
	 * 撤销当前事务中所有执行的操作
	 * 并结束当前事务
	 *
	 * @return bool 回滚事务是否成功
	 */
	public function rollBack():()

