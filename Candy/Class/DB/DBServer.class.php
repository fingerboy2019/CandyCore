<?php
/***
 * Candy框架数据库操作基类
 *
 * 抽象基类：链式查询、校验、软删、事务、缓存、读写分库与驱动解析。
 * 具体驱动继承本类并实现 DriverInterface 契约方法。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-10-19 11:16:38 $
 *
 * @package Candy\Extend\DB
 * @abstract
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

abstract class DBServer implements DriverInterface
{
	use Trait\Config;
	use Trait\DbLinkRole;
	use Trait\Common;
	use Trait\Data;
	use Trait\Cache;
	use Trait\Result;
	use Trait\WhereParser;
	use Trait\WhereClause;
	use Trait\SqlCompile;
	use Trait\QueryRead;
	use Trait\QueryWrite;
	use Trait\RelationQuery;
	use Trait\AttributeTransform;
	use Trait\ModelEvents;

	protected $sql = [];
	protected $msg = '';
	protected $tabName = '';
	protected $fieldList = [];
	protected $createTime = 'AddTime';
	protected $updateTime = 'UpTime';
	protected $deleteTime = 'delete_time';
	/** @var string 业务 Model 主键字段 */
	protected $pk = 'id';
	/** @var string Model 附带的数据文件路径 */
	public string $path = '';
	/**
	 * 自动写入时间戳：false 关闭；true 用 Unix 时间戳；'datetime' 用 Y-m-d H:i:s
	 *
	 * @var bool|string
	 */
	protected $autoWriteTimestamp = false;
	protected $type = [];
	protected $readonly = [];
	/**
	 * 批量赋值白名单（正向）
	 *
	 * 非空时只有列出的字段可被写入，优先级高于 $readonly。
	 * 全空时不做字段级限制（沿用原有行为）。
	 * 自动时间戳字段（createTime/updateTime）永远放行，不受白名单影响。
	 *
	 * @var array<int, string>
	 */
	protected $fillable = [];
	protected $auto;
	/** @var object|null 所属业务 Model（用于触发模型事件） */
	protected ?object $modelObserver = null;
	protected static $echoSql = '';
	protected $charset;
	protected $host;
	protected $user;
	protected $pass;
	protected ?int $port = null;
	protected $dbname;
	protected $tablePrefix;

	/**
	 * 解析 ORM 当前表名对应的物理表名。
	 *
	 * 当前连接的 tablePrefix 是唯一的物理表前缀来源。已经带当前连接前缀
	 * 的表名保持原样，逻辑表名统一追加当前连接前缀。
	 *
	 * @param string $tabName 逻辑表名或已带基座前缀的物理表名
	 * @return string 物理表名
	 */
	protected function physicalTableName(string $tabName): string
	{
		$tabName = trim($tabName);
		$prefix = (string)$this->tablePrefix;
		if ($prefix !== '' && str_starts_with($tabName, $prefix)) {
			return $tabName;
		}

		return $prefix . $tabName;
	}

	public $defaults = [
		'db' => '',
		'field' => '',
		'field_expressions' => [],
		'where' => [],
		'where_expressions' => [],
		'where_columns' => [],
		'where_exists' => [],
		'alias' => '',
		'order' => '',
		'limit' => '',
		'group' => '',
		'having' => '',
		'join' => '',
		'share' => false,
		'fetch' => '',
		'token' => '',
		'scope' => '',
		'withtrashed' => false,
		'onlytrashed' => false,
		'tojson' => false,
		'lock' => '',
		'transaction' => false,
		'data' => [],
		'querycache' => '',
	];

	/**
	 * 设置数据库连接配置
	 *
	 * 支持：空参（默认库）、隔离别名字符串、内联配置数组；可选第三语义角色 write|read。
	 * 读写分库配置走 DbLinkRole；扁平配置走 applyFlatConnectionNode。
	 *
	 * @param string|array|null $config 别名、配置数组或 null
	 * @param string|null $role auto|write|read
	 * @return void
	 */
	public function setLink(mixed $config, ?string $role = null): void
	{
		$inlineRole = null;

		if (empty($config) && method_exists($this, 'setDB')) {
			$config = $this->setDB();
		}

		if (empty($config)) {
			$this->linkDefaultConfig();
		} elseif (is_array($config)) {
			$this->linkArrayConfig($config);
		} else {
			[$aliasName, $inlineRole] = self::parseAliasSpec((string) $config);
			if ($aliasName === '') {
				$aliasName = (string) $config;
			}
			$this->linkAliasConfig($aliasName);
		}

		$this->dbRoleMode = 'auto';
		if ($role !== null && $role !== '' && strtolower($role) !== 'auto') {
			$this->dbRoleMode = self::normalizeRoleName($role);
		} elseif ($inlineRole !== null && $inlineRole !== '' && strtolower($inlineRole) !== 'auto') {
			$this->dbRoleMode = self::normalizeRoleName($inlineRole);
		}

		$this->applyInitialRoleAfterSetLink();
		$this->sql = $this->defaults;
	}

	/**
	 * 绑定默认库（优先 DBLIST.default 读写分库，否则 C() 扁平）
	 *
	 * @return void
	 * @access private
	 */
	private function linkDefaultConfig(): void
	{
		$bundle = $this->peekDefaultBundle();
		if (is_array($bundle) && self::isRoleSplitConfig($bundle)) {
			$this->initRoleSplitBundle($bundle, 'default');
			return;
		}
		$this->initFlatFromGlobal();
	}

	/**
	 * 绑定内联配置数组（支持 write/read 分库）
	 *
	 * @param array $config 配置
	 * @return void
	 * @access private
	 */
	private function linkArrayConfig(array $config): void
	{
		$poolKey = 'db_' . crc32((string) json_encode($config, JSON_UNESCAPED_UNICODE));
		if (self::isRoleSplitConfig($config)) {
			$this->initRoleSplitBundle($config, $poolKey);
			return;
		}
		self::$configPool[$poolKey] = $config;
		$this->currentConfig = $config;
		$this->dbAlias = $poolKey;
		$this->applyFlatConnectionNode($config, $poolKey);
	}

	/**
	 * 按隔离别名绑定配置
	 *
	 * @param string $aliasName 别名
	 * @return void
	 * @access private
	 */
	private function linkAliasConfig(string $aliasName): void
	{
		$dbConfig = $this->resolveAliasBundle($aliasName);
		if ($dbConfig === []) {
			return;
		}
		if (self::isRoleSplitConfig($dbConfig)) {
			$this->initRoleSplitBundle($dbConfig, $aliasName);
			return;
		}
		self::$configPool[$aliasName] = $dbConfig;
		$this->currentConfig = $dbConfig;
		$this->dbAlias = $aliasName;
		$this->applyFlatConnectionNode($dbConfig, $aliasName);
	}

	/**
	 * 读取默认库完整配置包（DBLIST.default）
	 *
	 * @return array|null
	 * @access private
	 */
	private function peekDefaultBundle(): ?array
	{
		foreach ([G('DBLIST'), C('DBLIST')] as $list) {
			if (is_array($list) && isset($list['default']) && is_array($list['default'])) {
				return $list['default'];
			}
		}
		$db = C('DB');
		if (is_array($db) && isset($db['default']) && is_array($db['default'])) {
			return $db['default'];
		}
		return null;
	}

	/**
	 * 从配置数组读取 PORT
	 *
	 * @param array $config 连接配置
	 * @return void
	 * @access protected
	 */
	protected function applyPortFromConfig(array $config): void
	{
		$this->port = isset($config['PORT']) && $config['PORT'] !== '' ? (int) $config['PORT'] : null;
	}

	/**
	 * 按隔离别名解析 DRIVER（别名配置优先，否则全局 DRIVER）
	 *
	 * @param string $alias 隔离库别名
	 * @return string 驱动短类名
	 * @access public
	 * @static
	 */
	public static function resolveDriverForAlias(string $alias): string
	{
		$alias = trim($alias);
		if ($alias === '' || $alias === 'default') {
			$cfg = null;
			foreach ([G('DBLIST'), C('DBLIST')] as $list) {
				if (is_array($list) && isset($list['default']) && is_array($list['default'])) {
					$cfg = $list['default'];
					break;
				}
			}
			if (is_array($cfg) && !empty($cfg['DRIVER'])) {
				return self::resolveDriverClass((string) $cfg['DRIVER']);
			}
			return self::resolveDriverClass((string) (G('DRIVER') ?: C('DRIVER') ?: 'pdomysql'));
		}

		$cfg = self::$configPool[$alias] ?? null;
		if (!is_array($cfg)) {
			foreach ([G('DBLIST'), C('DBLIST')] as $dbList) {
				if (is_array($dbList) && isset($dbList[$alias]) && is_array($dbList[$alias])) {
					$cfg = $dbList[$alias];
					break;
				}
			}
		}
		if (!is_array($cfg)) {
			$db = C('DB');
			if (is_array($db) && isset($db[$alias]) && is_array($db[$alias])) {
				$cfg = $db[$alias];
			}
		}
		if (is_array($cfg) && !empty($cfg['DRIVER'])) {
			return self::resolveDriverClass((string) $cfg['DRIVER']);
		}

		return self::resolveDriverClass((string) (G('DRIVER') ?: C('DRIVER') ?: 'pdomysql'));
	}

	/**
	 * 按 setLink 参数解析 DRIVER
	 *
	 * @param mixed $config 隔离别名、内联配置或空
	 * @return string 驱动短类名
	 * @access public
	 * @static
	 */
	public static function resolveDriverFromConfig(mixed $config): string
	{
		if (is_array($config)) {
			if (!empty($config['DRIVER'])) {
				return self::resolveDriverClass((string) $config['DRIVER']);
			}
			return self::resolveDriverForAlias('default');
		}
		if (is_string($config) && $config !== '') {
			[$alias] = self::parseAliasSpec($config);
			return self::resolveDriverForAlias($alias !== '' ? $alias : 'default');
		}
		return self::resolveDriverForAlias('default');
	}

	/**
	 * 当前实例实际使用的驱动短类名
	 *
	 * @return string
	 * @access public
	 */
	public function getResolvedDriver(): string
	{
		$cfg = $this->getCurrentConfig();
		if (is_array($cfg) && !empty($cfg['DRIVER'])) {
			return self::resolveDriverClass((string) $cfg['DRIVER']);
		}
		return self::resolveDriverForAlias($this->getDbAlias());
	}

	/**
	 * 解析 DRIVER 配置为驱动短类名
	 *
	 * 别名：pdo/pdomysql → PdoMysql；mysql/mysqli → Mysqli；pgsql/pdopgsql → PdoPgsql；sqlite/pdosqlite → PdoSqlite
	 *
	 * @param string $driver 配置中的 DRIVER 值
	 * @return string 短类名或完整命名空间类名
	 */
	public static function resolveDriverClass(string $driver): string
	{
		$driver = trim($driver);
		if ($driver === '' || str_contains($driver, '\\')) {
			return $driver;
		}
		$aliases = [
			'pdo' => 'PdoMysql',
			'pdomysql' => 'PdoMysql',
			'mysql' => 'Mysqli',
			'mysqli' => 'Mysqli',
			'pgsql' => 'PdoPgsql',
			'pdopgsql' => 'PdoPgsql',
			'postgres' => 'PdoPgsql',
			'postgresql' => 'PdoPgsql',
			'sqlite' => 'PdoSqlite',
			'pdosqlite' => 'PdoSqlite',
			'sqlsrv' => 'PdoSqlsrv',
			'pdosqlsrv' => 'PdoSqlsrv',
			'clickhouse' => 'ClickHouse',
		];
		$key = strtolower($driver);
		if (isset($aliases[$key])) {
			return $aliases[$key];
		}
		// 未命中别名表：保留原样（允许 DRIVER 直接写驱动短类名，如 MyCustomDriver）
		// 旧实现 ucfirst(strtolower($driver)) 会把驼峰拍平，导致自定义驱动类名找不到
		return ucfirst($driver);
	}

	/**
	 * 解析当前主库引擎短名（**单一事实来源**）
	 *
	 * 项目层与框架内多处需要判断「当前是哪种数据库」，统一在此收口，
	 * 避免各处各抄一份 match 导致行为漂移
	 * （历史上 Table / DatabaseRuntimeProbe 带 clickhouse 分支，PageDdl / DBService 不带）。
	 *
	 * 新增数据库时只需改 resolveDriverClass() 的别名表 + 本方法的 match。
	 *
	 * @param string|null $driver DRIVER 配置值或驱动短名；null 读全局配置
	 * @return string mysql|pgsql|sqlite|sqlsrv|clickhouse
	 * @access public
	 * @static
	 */
	public static function resolveEngine(?string $driver = null): string
	{
		if ($driver === null || $driver === '') {
			$driver = (string) (G('DRIVER') ?: C('DRIVER') ?: 'pdomysql');
		}
		$short = strtolower(self::resolveDriverClass($driver));

		return match ($short) {
			'pdosqlite', 'sqlite' => 'sqlite',
			'pdopgsql', 'pgsql' => 'pgsql',
			'pdosqlsrv', 'sqlsrv' => 'sqlsrv',
			'clickhouse' => 'clickhouse',
			default => 'mysql',
		};
	}

	/**
	 * 按驱动短类名解析方言类（FQCN）
	 *
	 * ClickHouse 等旁路无主库方言时回退 Mysql（仅避免空指针；旁路不应走 ORM 方言路径）
	 *
	 * @param string|null $driverShort 驱动短类名或 DRIVER 别名；null 读全局配置
	 * @return class-string<\Candy\Extend\DB\Dialect\DialectInterface>
	 * @access public
	 * @static
	 */
	public static function resolveDialectClass(?string $driverShort = null): string
	{
		if ($driverShort === null || $driverShort === '') {
			$driverShort = (string) (G('DRIVER') ?: C('DRIVER') ?: 'pdomysql');
		}
		$short = self::resolveDriverClass($driverShort);
		return match ($short) {
			'PdoSqlite' => \Candy\Extend\DB\Dialect\Sqlite::class,
			'PdoPgsql' => \Candy\Extend\DB\Dialect\Pgsql::class,
			'PdoSqlsrv' => \Candy\Extend\DB\Dialect\Sqlsrv::class,
			default => \Candy\Extend\DB\Dialect\Mysql::class,
		};
	}

	/**
	 * 当前实例对应的方言类
	 *
	 * @return class-string<\Candy\Extend\DB\Dialect\DialectInterface>
	 * @access public
	 */
	public function dialectClass(): string
	{
		return self::resolveDialectClass($this->getResolvedDriver());
	}

	/**
	 * 从业务 Model 同步 ORM 元数据到驱动连接
	 *
	 * BaseModel 通过组合持有连接；Model 上声明的 createTime / autoWriteTimestamp 等
	 * 须拷到本连接，insert/update 的 addTimeFields 才会生效
	 *
	 * @param object $model 业务 Model 实例（如 DemoItemModel）
	 * @return void
	 * @access public
	 */
	public function applyModelMeta(object $model): void
	{
		// 记住所属 Model，供 insert/update/delete 触发模型事件
		$this->modelObserver = $model;

		$keys = ['createTime', 'updateTime', 'deleteTime', 'autoWriteTimestamp', 'type', 'readonly', 'fillable', 'pk'];
		foreach ($keys as $key) {
			if (!property_exists($model, $key)) {
				continue;
			}
			try {
				$prop = new \ReflectionProperty($model, $key);
				if (method_exists($prop, 'isInitialized') && !$prop->isInitialized($model)) {
					continue;
				}
				$this->{$key} = $prop->getValue($model);
			} catch (\Throwable) {
				continue;
			}
		}

		if (property_exists($model, 'table')) {
			try {
				$prop = new \ReflectionProperty($model, 'table');
				if (!method_exists($prop, 'isInitialized') || $prop->isInitialized($model)) {
					$table = trim((string) $prop->getValue($model));
					if ($table !== '') {
						$this->setTable($table);
					}
				}
			} catch (\Throwable) {
				// 忽略：表名仍可由 BaseModel::setTable / M() 设置
			}
		}
	}

	/**
	 * @param string $sql SQL
	 * @param string $method 查询类型
	 * @param array $data 绑定参数
	 * @return mixed
	 */
	abstract public function query(string $sql, string $method = 'other', array $data = []): mixed;

	/**
	 * @param string $tabName 表名（无前缀）
	 * @return void
	 */
	abstract public function setTable(string $tabName): void;

	/**
	 * @return bool|null
	 */
	abstract public function beginTransaction(): ?bool;

	/**
	 * @return bool|null
	 */
	abstract public function commit(): ?bool;

	/**
	 * @return bool|null
	 */
	abstract public function rollBack(): ?bool;

	/**
	 * @return string
	 */
	abstract public function dbSize(): string;

	/**
	 * @return string
	 */
	abstract public function dbVersion(): string;
}
