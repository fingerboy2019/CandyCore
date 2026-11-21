# Pdo 类

## 概述

* Candy框架 数据库PDO驱动类
 * 
 * 该类是Candy框架的PDO数据库驱动实现，继承自DBServer基类，提供了完整的数据库操作功能，包括：
 * - 自动连接管理与连接池支持
 * - 安全的SQL执行与参数绑定
 * - 事务支持（开始、提交、回滚）
 * - 自动重试机制处理连接断开情况
 * - 表结构缓存优化
 * - 针对CLI模式的特殊处理和优化
 * 
 * 核心功能特点：
 * - 支持参数化查询，防止SQL注入攻击
 * - 连接池管理，提高数据库操作性能
 * - 自动检测连接有效性，支持连接超时处理
 * - 内置重试机制，提高系统稳定性
 * - 智能区分CLI和Web模式，优化连接策略
 * - 表结构缓存，减少数据库元数据查询
 * - 完善的异常处理和调试信息记录
 * 
 * 使用场景：
 * - Web应用数据库操作
 * - CLI命令行脚本数据库交互
 * - 需要事务支持的业务处理
 * - 大数据量查询优化
 * - 跨数据库平台兼容性需求
 * 
 * @package Candy\Extend\DB\Driver
 *

## 主要方法


### retryQuery()


### /**
	 * 执行SQL语句的方法，支持自动重试机制
	 * 
	 * 该方法是执行数据库操作的核心方法，具有以下功能：
	 * - 记录SQL执行开始时间，用于性能统计
	 * - 对数据参数进行转义处理
	 * - 解析方法类型，支持前缀命名空间格式
	 * - 使用retryQuery方法包装查询，实现自动重试
	 * - 根据SQL类型执行不同的处理逻辑
	 * - 记录SQL执行信息，包括执行时间、次数等
	 * 
	 * @param string $sql 要执行的SQL语句
	 * @param string $method SQL语句的类型（select,find,total,insert,update,delete,other）
	 * @param array $data 要绑定到SQL语句的值数组
	 * @return array|int|string|bool 根据SQL类型返回不同的结果：
	 * - select: 返回所有结果集数组
	 * - find: 返回单条结果数组
	 * - total: 返回记录总数（整数）
	 * - insert: 返回自增ID或执行结果
	 * - update/delete: 返回受影响的行数
	 * - other: 返回执行结果（布尔值）
	 */
	public function query(string $sql, string $method = 'other', array $data = []):()


### /**
	 * 自动获取和缓存表结构信息
	 * 
	 * 该方法负责获取数据库表的结构信息，并实现了表结构缓存机制：
	 * - 首先检查表结构缓存是否存在
	 * - 如果缓存存在，直接从缓存加载表结构
	 * - 如果缓存不存在，查询数据库获取表结构信息
	 * - 记录表的字段信息、主键信息和自增设置
	 * - 在非调试模式下，将表结构信息保存到缓存
	 * 
	 * @param string $tabName 表名（不包含表前缀）
	 * @return void
	 */ 
	public function setTable(string $tabName):()


### /**
	 * 开始数据库事务
	 * 
	 * 该方法启动一个新的数据库事务，关闭自动提交模式。
	 * 在事务中执行的所有SQL语句将作为一个原子操作提交或回滚。
	 * 
	 * @return ?bool 成功返回true，失败返回false或null
	 */
	public function beginTransaction():()


### /**
	 * 提交数据库事务
	 * 
	 * 该方法提交当前事务，使所有在事务中执行的修改永久化到数据库。
	 * 提交后会自动恢复自动提交模式。
	 * 
	 * @return ?bool 成功返回true，失败返回false或null
	 */
	public function commit():()


### /**
	 * 回滚数据库事务
	 * 
	 * 该方法取消当前事务中执行的所有修改，将数据库恢复到事务开始前的状态。
	 * 回滚后会自动恢复自动提交模式。
	 * 
	 * @return ?bool 成功返回true，失败返回false或null
	 */
	public function rollBack():()


### /**
	 * 获取数据库使用大小
	 * 
	 * 该方法计算数据库中所有表的总大小（数据大小+索引大小）
	 * 可以选择按表前缀过滤要计算的表，并使用sizeCount函数将字节数转换为易读的单位
	 * 
	 * @return string 返回格式化后的数据库大小（包含适当的单位，如KB、MB、GB等）
	 */
	public function dbSize():()


### /**
	 * 获取数据库服务器版本
	 * 
	 * 该方法返回当前连接的数据库服务器版本信息
	 * 版本信息格式取决于数据库服务器类型（如MySQL会返回版本号字符串）
	 * 
	 * @return string 返回数据库服务器的版本号字符串
	 */
	public function dbVersion():()

