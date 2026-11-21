<?php
/***
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
 * - 连接空闲超时自动重连，真实查询失败自动重试
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
 * @date 2026-10-24 09:37:53
 * @final 该类为最终类，不允许继承
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Driver;
use \Candy\Extend\DB\DBServer;
use \Candy\Extend\DB\Trait\ConnectionManager;
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Mysqli extends DBServer {

	use ConnectionManager;

	static $count = 0;
	static $execute = 0;
	static $totalcate = 0;
	static $links = [];
	static $results = [];
	// 连接池管理属性
	static $lastUsed = []; // 记录每个连接最后使用时间
	const CONNECTION_TIMEOUT = 300; // 连接空闲超时（秒），与 Pdo 一致
	const MAX_RECONNECT_ATTEMPTS = 3; // 最大重连尝试次数

	/** 是否 CLI SAPI（长驻进程启用空闲超时检测） */
	const IS_CLI = PHP_SAPI === 'cli';
	
	/**
	 * 获取或创建数据库连接
	 *
	 * 该方法实现了数据库连接池管理，优先复用已存在的连接；
	 * 在 CLI 长驻进程中仅对空闲超时连接重建，真实查询失败由重试流程处理。
	 *
	 * @return mysqli MySQLi数据库连接对象
	 * @throws Exception 当Mysqli扩展未加载时抛出异常
	 */
	protected function connect(): object
	{
		if (!extension_loaded('mysqli')) {
			Debug::showMessage('请开启Mysqli扩展。', 'Mysql');
		}

		$dbKey = $this->resolvePoolKey();

		if (!empty(self::$links[$dbKey])) {
			if (self::IS_CLI && $this->isConnectionExpired($dbKey)) {
				Debug::addMsg('连接已超时，重建数据库连接', 2);
				$this->dropPoolConnection($dbKey);
				return $this->registerPoolConnection($dbKey, $this->getDB());
			}
			// 不在每条业务 SQL 前额外执行 ping；真实查询失败时由 retryQuery 重连。
			self::$lastUsed[$dbKey] = time();
			return self::$links[$dbKey];
		}

		$this->enforceConnectionLimit($dbKey);
		return $this->registerPoolConnection($dbKey, $this->getDB());
	}

	/**
	 * 检查连接是否空闲超时
	 *
	 * 与 Pdo 一致：仅当存在 lastUsed 记录且超过 CONNECTION_TIMEOUT 时视为过期。
	 *
	 * @param string $dbKey 池键
	 * @return bool
	 * @access private
	 */
	private function isConnectionExpired(string $dbKey): bool
	{
		return isset(self::$lastUsed[$dbKey])
			&& (time() - self::$lastUsed[$dbKey]) > self::CONNECTION_TIMEOUT;
	}
	
	/**
	 * 创建新的数据库连接对象
	 *
	 * 该方法负责创建并初始化MySQLi连接对象，设置字符集等连接参数。
	 * 在CLI模式下会使用非持久连接并设置特定的超时参数。
	 *
	 * @return mysqli 新创建的MySQLi数据库连接对象
	 * @throws Exception 当连接失败时抛出异常
	 */
	protected function getDB(): \mysqli
	{
		self::$count++;
		Debug::addMsg('<b>第 '.self::$count.' 次连接数据库</b>', 2);

		try {
			$mysqli = \mysqli_init();
			if ($mysqli === false) {
				Debug::showMessage('mysqli_init 失败', 'Mysql');
			}
			$mysqli->options(\MYSQLI_OPT_CONNECT_TIMEOUT, 10);
			$mysqli->options(\MYSQLI_OPT_READ_TIMEOUT, 30);

			$host = isWebServer() ? $this->host : 'p:' . $this->host;
			$port = (int) ($this->port ?? 3306);
			$connected = $mysqli->real_connect($host, $this->user, $this->pass, $this->dbname, $port);
			if (!$connected || $mysqli->connect_errno > 0) {
				Debug::showMessage($mysqli->connect_error ?: '数据库连接失败', 'Mysql');
			}

			if (!is_null(G('CHARSET'))) {
				$mysqli->set_charset((string) $this->charset);
			}

			return $mysqli;
		} catch (\Exception $e) {
			Debug::showMessage('数据库连接失败: ' . $e->getMessage(), 'Mysql');
		}
	}
	
	/**
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
	public function query(string $sql,string $method = 'other',array $data = []): mixed
	{
		$marr = explode('::', $method);
		$op = strtolower(array_pop($marr));
		$this->switchRoleForQuery($op);
		return self::retryStatement($sql, $method, $data, $this);
	}
	
	/**
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
	private static function retryStatement(string $sql, string $method, array $data, object $instance, int $attempts = 0): mixed
	{
		$startTime = microtime(true); 
		$value = $instance->escapeStringArray($data);
		$metricBindings = $value;
		$marr = explode('::', $method);
		$method = strtolower(array_pop($marr));
		
		//查询
		$mysqli = $instance->connect();
		$stmt = $mysqli->prepare($sql);  //准备好一个语句
		
		//扩展数据库表不存在
		if($instance->dbname != 'default' && $mysqli->errno == 1146){
			//获取默认库
			$config = loadConfig('Config')['DB']['default'] ?? null;
			if($config){
				//表创建语句
				$res = D(str_replace($instance->tablePrefix,'',$instance->tabName), $config)->query('SHOW CREATE TABLE '. $instance->tabName .';', 'find');
				//创建新表
				$newTable = $instance->query($res['Create Table']);
				if($newTable){
					$stmt = $mysqli->prepare($sql);  //准备好一个语句
				}else{
					Debug::showMessage('新库创建表失败，请检查权限', 'Mysql');
				}
			}else{
				Debug::showMessage('默认数据库不存在', 'Mysql');
			}
		}
		
		// 判断是否为连接错误，需要重试
		$connectionErrors = [2006, 2013, 2014, 2045, 2054];
		if (in_array($mysqli->errno, $connectionErrors) && $attempts < self::MAX_RECONNECT_ATTEMPTS) {
			Debug::addMsg('数据库连接断开，尝试重新连接 (第'.($attempts+1).'次)', 2);
			
			// 清除现有连接
			$dbKey = $instance->resolvePoolKey();
			if (isset(self::$links[$dbKey])) {
				$instance->dropPoolConnection($dbKey);
			}
			
			// 短暂延迟后重试
			sleep(1);
			return self::retryStatement($sql, $method, $data, $instance, $attempts + 1);
		}
		
		//绑定参数
		if(count($value) > 0){
			$types = self::mysqliBindTypes($value);
			array_unshift($value, $types);
			$stmt->bind_param(...$value);
		}
		if($stmt){
			$result = $stmt->execute();   //执行一个准备好的语句
		}
		
		//如果SQL有误，则输出并直接返回退出
		if(!$result){
			Debug::addMsg("<font color='red'>SQL ERROR: [{$mysqli->errno}] {$stmt->error}</font>");
			return false;
		}
		
		$returnv = match($method){
			'select' => ($stmt->store_result() !== false) ? $instance->getAll($stmt) : null,
			'find' => $stmt->store_result() !== false && $stmt->num_rows > 0 ? $instance->getOne($stmt) : null,
			'total' => $stmt->store_result() !== false ? (int)$instance->getOne($stmt)['count'] : 0,
			'insert' => $instance->auto == 'yes' ? (int)$mysqli->insert_id : ($result ? 1 : 0),
			'delete', 'update' => $stmt->affected_rows,
			default => $result,
		};
		self::$execute++;
		$stopTime = microtime(true);
		$ys = round(($stopTime - $startTime)*1000, 4);
		\Candy\Extend\DB\DbMetrics::recordQuery(
			$sql,
			$ys,
			$instance->getDbAlias(),
			$instance->getDbActiveRole(),
			$metricBindings
		);
		
		Debug::addMsg('[<b>'.$instance->dbname.'</b>] 表<b>'.$instance->tabName.'</b>结构：'.implode(',', $instance->fieldList),2); //debug
		Debug::addMsg('[用时<font color="red">'.$ys.'</font>ms] ('. self::$execute .'次) - '.$sql,2); //debug
		self::$totalcate += $ys;
		self::$echoSql = $sql;
		$dialect = $instance->dialectClass();
		return $dialect::normalizeResultKeys($returnv, $method);
	}
	
	/**
	 * 获取查询结果集中的所有记录
	 *
	 * 该方法将预处理语句的结果集转换为关联数组格式，每一行数据作为一个关联数组，
	 * 所有行组成一个二维数组返回。
	 *
	 * @param mysqli_stmt $stmt MySQLi预处理语句对象
	 * @return array 二维关联数组，每行记录为一个关联数组
	 */
	private function getAll(\mysqli_stmt $stmt): array
	{
		$result = [];
		$field = $stmt->result_metadata()->fetch_fields();
		$out = [];
		//获取所有结果集中的字段名
		$fields = [];
		foreach ($field as $val){
			$fields[] = &$out[$val->name];
		}
		//用所有字段名绑定到bind_result方上
		$stmt->bind_result(...$fields);
	       	while ($stmt->fetch()){
			$t = [];  //一条记录关联数组
			foreach ($out as $key => $val){
				$t[$key] = $val;
			}
			$result[] = $t;
		}
		return $result;  //二维数组
	}
	
	/**
	 * 获取查询结果集中的第一条记录
	 *
	 * 该方法将预处理语句的结果集的第一条记录转换为关联数组格式返回。
	 * 适用于只需要单条记录的查询场景。
	 *
	 * @param mysqli_stmt $stmt MySQLi预处理语句对象
	 * @return array 一维关联数组，表示查询结果的第一条记录
	 */
	private function getOne(\mysqli_stmt $stmt): array
	{
		$result = [];
		$field = $stmt->result_metadata()->fetch_fields();
		$out = [];
		//获取所有结果集中的字段名
		$fields = [];
		foreach ($field as $val){
			$fields[] = &$out[$val->name];
		}
		//用所有字段名绑定到bind_result方上
		$stmt->bind_result(...$fields);
	        $stmt->fetch();
		
		foreach ($out as $key => $val){
			$result[$key] = $val;
		}
		return $result;  //一维关联数组
    }
	
	/**
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
	public function setTable(string $tabName): void
	{
		$this->tabName = $this->physicalTableName($tabName); //加前缀的表名
		$cachefile = C('RUNTIME') . 'Data/'.$tabName.'.php';
		$json = !\Candy\Core\Cache::isFileDriver() ? (file_exists($cachefile) ? ltrim(file_get_contents($cachefile),'<?php ') : false) : \getCache()->get('data.' . md5($cachefile), false);
		
		if($json != false){
			$this->auto = substr($json,-3);
			$json = substr($json, 0, -3);
			$this->fieldList = (array)json_decode($json, true);
		}else{
			$mysqli = $this->connect();
			if($mysqli){
				$result = $mysqli->query("desc {$this->tabName}");
				if(!$result){
					//表不存在
					Debug::showMessage('SQLSTATE[42S02]: Base table or view not found: 1146 Table \''. $this->dbname .'.'. $this->tabName.'\' doesn\'t exist', 'Mysql');
				}
			}
				
			$fields = [];
			$auto = 'no';
			while($row = $result->fetch_assoc()){
				if($row['Key'] == 'PRI'){
					$fields['pri'] = strtolower($row['Field']);
				}else{
					$fields[] = strtolower($row['Field']);
				}
				if($row['Extra'] == 'auto_increment')
					$auto = 'yes';
			}
			//如果表中没有主键，则将第一列当作主键
			if(!array_key_exists('pri', $fields)){
				$fields['pri'] = array_shift($fields);		
			}
			if(isDebug() === false){
				!\Candy\Core\Cache::isFileDriver() ? file_put_contents($cachefile, '<?php '.json_encode($fields).$auto) : \Candy\Core\Cache::store()->set('data.' . md5($cachefile), json_encode($fields).$auto);
			}
			$this->fieldList = $fields;
			$this->auto = $auto;
		}
	}
	
	/**
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
	public function beginTransaction(): bool
	{
		try {
			$this->onTransactionBegin();
			$mysqli = $this->connect();
			return $mysqli->autocommit(false);
		} catch (\Exception $e) {
			return false;
		}
	}
	
	/**
	 * 提交当前事务
	 *
	 * 该方法用于提交当前活跃的事务，将所有在事务中执行的更改永久保存到数据库中。
	 * 提交后，数据库连接的autocommit模式会被重新设置为true。
	 *
	 * @return void
	 * @see beginTransaction() 开始事务
	 * @see rollBack() 回滚事务
	 */
	public function commit(): ?bool
	{
		try {
			$mysqli = $this->connect();
			$success = $mysqli->commit();
		    $mysqli->autocommit(true);
			$this->onTransactionEnd();
		    return $success;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * 回滚当前事务
	 *
	 * 该方法用于取消当前活跃的事务，撤销所有在事务中执行的更改。
	 * 回滚后，数据库连接的autocommit模式会被重新设置为true。
	 *
	 * @return void
	 * @see beginTransaction() 开始事务
	 * @see commit() 提交事务
	 */
	public function rollBack(): ?bool
	{
		try {
			$mysqli = $this->connect();
			$success = $mysqli->rollback();
		    $mysqli->autocommit(true);
			$this->onTransactionEnd();
		    return $success;
		} catch (\Exception $e) {
			return false;
		}
	}
	
	/**
	 * 获取数据库使用大小，以人类可读的格式返回
	 *
	 * 该方法计算数据库中所有表（或指定前缀的表）的数据大小和索引大小之和，
	 * 并使用sizeCount函数将字节数转换为更易读的格式（如KB、MB、GB等）。
	 *
	 * @return string 数据库大小，格式如"10.5 MB"、"2.3 GB"等
	 *
	 * @example
	 * // 获取所有表的大小
	 * $size = $db->dbSize();
	 * echo "数据库大小: {$size}"; // 输出如 "数据库大小: 10.5 MB"
	 */
	public function dbSize(): string
	{
		$schema = (string) ($this->dbname ?: G('DBNAME'));
		$sql = 'SHOW TABLE STATUS FROM `' . str_replace('`', '``', $schema) . '`';
		if (!empty($this->tablePrefix)) {
			$sql .= ' LIKE \'' . addslashes($this->tablePrefix) . '%\'';
		}
		$mysqli = $this->connect();
		$result = $mysqli->query($sql);
		$size = 0;
		while($row = $result->fetch_assoc())
			$size += $row['Data_length'] + $row['Index_length'];
		
		return sizeCount($size);
	}

	/**
	 * 推断 mysqli bind_param 类型串
	 *
	 * @param array $values 绑定值
	 * @return string
	 * @access private
	 * @static
	 */
	private static function mysqliBindTypes(array $values): string
	{
		$types = '';
		foreach ($values as $v) {
			if (is_int($v)) {
				$types .= 'i';
			} elseif (is_float($v)) {
				$types .= 'd';
			} elseif (is_string($v) && $v !== '' && ctype_digit($v)) {
				$types .= 'i';
			} else {
				$types .= 's';
			}
		}
		return $types;
	}

	/**
	 * 获取MySQL数据库服务器的版本信息
	 *
	 * 该方法返回MySQL服务器的版本字符串，可以用于版本兼容性检查。
	 *
	 * @return string MySQL数据库服务器的版本信息
	 *
	 * @example
	 * // 获取数据库版本
	 * $version = $db->dbVersion();
	 * echo "MySQL版本: {$version}"; // 输出如 "MySQL版本: 5.7.33"
	 *
	 * // 版本比较示例
	 * if (version_compare($version, '5.7.0', '>=')) {
	 *     // 使用MySQL 5.7及以上版本的特性
	 * } else {
	 *     // 使用兼容旧版本的实现
	 * }
	 */
	public function dbVersion(): string
	{
		$mysqli = $this->connect();
		return $mysqli->server_info;
	}
}
