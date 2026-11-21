<?php
/**
 * 库隔离 + 读写分库（主从）连接角色 Trait
 *
 * 与 Config trait 配合：隔离别名选数据空间，write/read 选节点。
 * 扁平配置（含 HOST）保持向后兼容；含 write/read（或 master/slave）时启用角色路由。
 *
 * @package Candy\Extend\DB\Trait
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-17 09:25:00
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Core\Debug;
use Candy\Extend\DB\Dialect\Sqlite as SqliteDialect;

trait DbLinkRole
{
	/** @var array 当前实际连接节点（扁平或 write/read 节点） */
	protected array $activeConnectionConfig = [];

	/** @var string 角色模式：auto|write|read */
	protected string $dbRoleMode = 'auto';

	/** @var bool 当前别名是否配置了读写分库 */
	protected bool $dbRoleSplit = false;

	/** @var array 读写分库完整配置（write/read 节点） */
	protected array $dbRoleBundle = [];

	/** @var string 当前已应用的连接角色 */
	protected string $dbActiveRole = 'write';

	/** @var string 库隔离别名（扁平连接默认 default） */
	protected string $dbAlias = 'default';

	/** @var int 事务嵌套深度（>0 时强制 write） */
	protected int $dbTransactionDepth = 0;

	/** @var array<string,int> 只读从库轮询游标 */
	protected static array $readReplicaCursor = [];

	/**
	 * 解析别名规格（支持 tenant_a@read 紧凑写法）
	 *
	 * @param string $spec 别名或 alias@role
	 * @return array{0:string,1:?string} [别名, 内联角色或 null]
	 * @access public
	 * @static
	 */
	public static function parseAliasSpec(string $spec): array
	{
		$spec = trim($spec);
		if ($spec === '') {
			return ['', null];
		}
		if (!str_contains($spec, '@')) {
			return [$spec, null];
		}
		[$alias, $role] = explode('@', $spec, 2);
		$alias = trim($alias);
		$role = trim(strtolower($role));
		if ($alias === '') {
			return [$spec, null];
		}
		return [$alias, self::normalizeRoleName($role)];
	}

	/**
	 * 规范化角色名（write/read；兼容 master/slave）
	 *
	 * @param string $role 角色
	 * @return string write|read|auto
	 * @access public
	 * @static
	 */
	public static function normalizeRoleName(string $role): string
	{
		$role = strtolower(trim($role));
		return match ($role) {
			'read', 'slave', 'slaves', 'replica' => 'read',
			'write', 'master', 'primary' => 'write',
			'auto', '' => 'auto',
			default => 'auto',
		};
	}

	/**
	 * 是否为读写分库配置（无顶层 HOST，含 write/read 等键）
	 *
	 * @param array $config 配置数组
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isRoleSplitConfig(array $config): bool
	{
		if (isset($config['HOST'])) {
			return false;
		}
		return isset($config['write']) || isset($config['master'])
			|| isset($config['read']) || isset($config['slave']) || isset($config['slaves']);
	}

	/**
	 * 从别名配置解析表前缀（读写分库取 write 节点）
	 *
	 * @param array $config 别名配置
	 * @return string
	 * @access public
	 * @static
	 */
	public static function resolveTablePrefixFromAliasConfig(array $config): string
	{
		if (self::isRoleSplitConfig($config)) {
			$write = self::extractWriteNode($config);
			return (string) ($write['TABPREFIX'] ?? '');
		}
		return (string) ($config['TABPREFIX'] ?? '');
	}

	/**
	 * 提取写库节点配置
	 *
	 * @param array $bundle 读写分库配置
	 * @return array
	 * @access public
	 * @static
	 */
	public static function extractWriteNode(array $bundle): array
	{
		$node = $bundle['write'] ?? $bundle['master'] ?? [];
		return is_array($node) ? $node : [];
	}

	/**
	 * 按查询方法推断默认角色
	 *
	 * @param string $method 查询类型
	 * @return string write|read
	 * @access public
	 * @static
	 */
	public static function roleForQueryMethod(string $method): string
	{
		$method = strtolower(preg_replace('/^.*::/', '', $method) ?? $method);
		return in_array($method, ['select', 'find', 'total'], true) ? 'read' : 'write';
	}

	/**
	 * 强制使用写库
	 *
	 * @return $this
	 * @access public
	 */
	public function useWrite(): static
	{
		$this->dbRoleMode = 'write';
		if ($this->dbRoleSplit) {
			$this->applyRole('write');
		}
		return $this;
	}

	/**
	 * 强制使用读库
	 *
	 * @return $this
	 * @access public
	 */
	public function useRead(): static
	{
		$this->dbRoleMode = 'read';
		if ($this->dbRoleSplit) {
			$this->applyRole('read');
		}
		return $this;
	}

	/**
	 * 恢复自动读写路由
	 *
	 * @return $this
	 * @access public
	 */
	public function useAuto(): static
	{
		$this->dbRoleMode = 'auto';
		return $this;
	}

	/**
	 * 获取隔离库别名
	 *
	 * @return string
	 * @access public
	 */
	public function getDbAlias(): string
	{
		return $this->dbAlias !== '' ? $this->dbAlias : 'default';
	}

	/**
	 * 获取当前角色模式
	 *
	 * @return string auto|write|read
	 * @access public
	 */
	public function getDbRoleMode(): string
	{
		return $this->dbRoleMode;
	}

	/**
	 * 获取当前实际连接角色
	 *
	 * @return string write|read
	 * @access public
	 */
	public function getDbActiveRole(): string
	{
		return $this->dbActiveRole;
	}

	/**
	 * 查询前切换连接角色（事务内强制 write）
	 *
	 * @param string $method 查询 method
	 * @return void
	 * @access protected
	 */
	protected function switchRoleForQuery(string $method): void
	{
		if (!$this->dbRoleSplit) {
			return;
		}
		if ($this->dbTransactionDepth > 0) {
			$this->applyRole('write');
			return;
		}
		if ($this->dbRoleMode !== 'auto') {
			$this->applyRole($this->dbRoleMode);
			return;
		}
		$this->applyRole(self::roleForQueryMethod($method));
	}

	/**
	 * 事务开始时登记深度并切到写库
	 *
	 * @return void
	 * @access protected
	 */
	protected function onTransactionBegin(): void
	{
		$this->dbTransactionDepth++;
		$this->switchRoleForQuery('insert');
	}

	/**
	 * 事务结束（提交或回滚）时递减深度
	 *
	 * @return void
	 * @access protected
	 */
	protected function onTransactionEnd(): void
	{
		if ($this->dbTransactionDepth > 0) {
			$this->dbTransactionDepth--;
		}
	}

	/**
	 * 初始化读写分库别名配置
	 *
	 * @param array $bundle 完整配置
	 * @param string $alias 隔离别名
	 * @return void
	 * @access protected
	 */
	protected function initRoleSplitBundle(array $bundle, string $alias): void
	{
		$write = self::extractWriteNode($bundle);
		if (!$this->validateConnectionNode($write, "数据库配置别名 '{$alias}' 的 write/master")) {
			return;
		}

		$this->dbRoleSplit = true;
		$this->dbRoleBundle = $bundle;
		$this->dbAlias = $alias;
		self::$configPool[$alias] = $bundle;
		$this->currentConfig = $bundle;
		$this->tablePrefix = (string) ($write['TABPREFIX'] ?? '');
	}

	/**
	 * 应用扁平连接节点到当前实例
	 *
	 * @param array $config 连接参数
	 * @param string $poolKey 连接池键
	 * @return void
	 * @access protected
	 */
	protected function applyFlatConnectionNode(array $config, string $poolKey): void
	{
		if (!$this->validateConnectionNode($config, '数据库配置')) {
			return;
		}

		$this->dbRoleSplit = false;
		$this->dbRoleBundle = [];
		$this->dbActiveRole = 'write';
		$this->activeConnectionConfig = $config;
		$this->dbPoolKey = $poolKey;
		$this->charset = $config['CHARSET'] ?? 'utf8';
		$this->host = $config['HOST'];
		$this->user = $config['USER'];
		$this->pass = $config['PASS'];
		$this->dbname = $config['DBNAME'];
		$this->tablePrefix = $config['TABPREFIX'] ?? '';
		$this->port = isset($config['PORT']) && $config['PORT'] !== '' ? (int) $config['PORT'] : null;
		$this->afterApplyConnectionNode($config);
	}

	/**
	 * 连接节点应用后的驱动专用钩子（如 SQLite 路径）
	 *
	 * @param array $config 节点配置
	 * @return void
	 * @access protected
	 */
	protected function afterApplyConnectionNode(array $config): void
	{
		$driver = strtolower((string) (G('DRIVER') ?: C('DRIVER') ?: ''));
		$isSqlite = in_array($driver, ['sqlite', 'pdosqlite'], true) || self::isSqliteNodeConfig($config);
		if ($isSqlite && method_exists($this, 'applySqliteNode')) {
			$this->applySqliteNode($config);
		}
	}

	/**
	 * 按角色应用读写分库中的某一节点
	 *
	 * @param string $role write|read
	 * @return void
	 * @access protected
	 */
	protected function applyRole(string $role): void
	{
		if (!$this->dbRoleSplit) {
			return;
		}
		$node = $this->extractRoleNode($role);
		if ($node === null) {
			$node = self::extractWriteNode($this->dbRoleBundle);
			$role = 'write';
		}
		if (!$this->validateConnectionNode($node, "数据库别名 '{$this->dbAlias}' 的 {$role} 节点")) {
			return;
		}

		$poolKey = $this->dbAlias . '_' . $role;
		if ($role === 'read' && $this->dbRoleMode === 'auto') {
			$poolKey .= '_' . crc32(json_encode($node, JSON_UNESCAPED_UNICODE));
		}

		if ($this->dbActiveRole === $role && $this->dbPoolKey === $poolKey) {
			return;
		}
		$this->dbPoolKey = $poolKey;
		$this->dbActiveRole = $role;
		$this->charset = $node['CHARSET'] ?? 'utf8';
		$this->host = $node['HOST'];
		$this->user = $node['USER'];
		$this->pass = $node['PASS'];
		$this->dbname = $node['DBNAME'];
		if ($this->tablePrefix === '') {
			$this->tablePrefix = $node['TABPREFIX'] ?? '';
		}
		$this->port = isset($node['PORT']) && $node['PORT'] !== '' ? (int) $node['PORT'] : null;
		$this->activeConnectionConfig = $node;
		$this->afterApplyConnectionNode($node);
	}

	/**
	 * setLink 完成后应用初始连接（setTable 等需要连库）
	 *
	 * @return void
	 * @access protected
	 */
	protected function applyInitialRoleAfterSetLink(): void
	{
		if (!$this->dbRoleSplit) {
			return;
		}
		$role = $this->dbRoleMode === 'auto' ? 'write' : $this->dbRoleMode;
		$this->applyRole($role);
	}

	/**
	 * 从读写分库配置中取指定角色节点（读库支持多从轮询）
	 *
	 * @param string $role write|read
	 * @return array|null
	 * @access protected
	 */
	protected function extractRoleNode(string $role): ?array
	{
		if ($role === 'write') {
			$node = self::extractWriteNode($this->dbRoleBundle);
			return $node !== [] ? $node : null;
		}

		$read = $this->dbRoleBundle['read']
			?? $this->dbRoleBundle['slave']
			?? $this->dbRoleBundle['slaves']
			?? null;

		if ($read === null) {
			return null;
		}

		if (is_array($read) && isset($read['HOST'])) {
			return $read;
		}

		if (!is_array($read) || $read === []) {
			return null;
		}

		$replicas = array_values(array_filter($read, static fn($item) => is_array($item) && isset($item['HOST'])));
		if ($replicas === []) {
			return null;
		}

		$cursorKey = $this->dbAlias . ':read';
		$index = self::$readReplicaCursor[$cursorKey] ?? 0;
		$node = $replicas[$index % count($replicas)];
		self::$readReplicaCursor[$cursorKey] = $index + 1;
		return $node;
	}

	/**
	 * 按别名加载配置包
	 *
	 * 优先实例配置池，其次 G/C 的 DBLIST（Bootstrap 写入 C('DBLIST')）
	 *
	 * @param string $aliasName 隔离别名
	 * @return array
	 * @access protected
	 */
	protected function resolveAliasBundle(string $aliasName): array
	{
		if (isset(self::$configPool[$aliasName])) {
			return self::$configPool[$aliasName];
		}

		$dbList = G('DBLIST');
		if (!is_array($dbList) || !isset($dbList[$aliasName])) {
			$fromC = C('DBLIST');
			$dbList = is_array($fromC) ? $fromC : [];
		}
		if (is_array($dbList) && isset($dbList[$aliasName]) && is_array($dbList[$aliasName])) {
			return $dbList[$aliasName];
		}

		Debug::showMessage("数据库配置别名 '{$aliasName}' 不存在。请检查配置文件 Source/Inc/Config.inc.php 中的 DB 设置或通过 G('DBLIST', [...]) 注册配置。", 'Sql');
		stop();
		return [];
	}

	/**
	 * 是否为 SQLite 扁平配置（PATH/DSN/文件型 DBNAME）
	 *
	 * @param array $config 配置
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isSqliteNodeConfig(array $config): bool
	{
		if (!empty($config['PATH']) || !empty($config['DSN'])) {
			return true;
		}
		if (!empty($config['HOST'])) {
			return false;
		}
		$dbname = (string) ($config['DBNAME'] ?? '');
		return $dbname !== '' && (str_contains($dbname, '/') || str_ends_with($dbname, '.db'));
	}

	/**
	 * 校验连接节点必填字段（SQLite 校验 PATH）
	 *
	 * @param array $config 节点配置
	 * @param string $label 错误标签
	 * @return bool
	 * @access protected
	 */
	protected function validateConnectionNode(array $config, string $label): bool
	{
		if (self::isSqliteNodeConfig($config)) {
			$path = SqliteDialect::resolvePath($config);
			if ($path === '') {
				Debug::showMessage($label . ' 的 SQLite PATH/DBNAME 无效', 'Sql');
				stop();
				return false;
			}
			return true;
		}

		$host = $config['HOST'] ?? null;
		$user = $config['USER'] ?? null;
		$pass = $config['PASS'] ?? null;
		$dbname = $config['DBNAME'] ?? null;

		if (is_null($host) || is_null($user) || is_null($pass) || is_null($dbname)) {
			$missingFields = [];
			if (is_null($host)) {
				$missingFields[] = 'HOST';
			}
			if (is_null($user)) {
				$missingFields[] = 'USER';
			}
			if (is_null($pass)) {
				$missingFields[] = 'PASS';
			}
			if (is_null($dbname)) {
				$missingFields[] = 'DBNAME';
			}
			Debug::showMessage($label . ' 配置不完整，缺少以下参数: ' . implode(', ', $missingFields), 'Sql');
			stop();
			return false;
		}
		return true;
	}

	/**
	 * 从全局 C() 扁平配置初始化默认连接
	 *
	 * @return void
	 * @access protected
	 */
	protected function initFlatFromGlobal(): void
	{
		$node = [
			'HOST' => C('HOST'),
			'USER' => C('USER'),
			'PASS' => C('PASS'),
			'DBNAME' => C('DBNAME'),
			'CHARSET' => C('CHARSET') ?? 'utf8',
			'TABPREFIX' => C('TABPREFIX') ?? '',
			'PORT' => C('PORT'),
		];

		$path = C('PATH');
		if ($path !== null && $path !== '') {
			$node['PATH'] = $path;
		}
		$dsn = C('DSN');
		if ($dsn !== null && $dsn !== '') {
			$node['DSN'] = $dsn;
		}

		$driver = strtolower((string) (G('DRIVER') ?: C('DRIVER') ?: ''));

		if (self::isSqliteNodeConfig($node) || in_array($driver, ['sqlite', 'pdosqlite'], true)) {
			$this->dbAlias = 'default';
			$this->applyFlatConnectionNode($node, 'default');
			return;
		}

		if (is_null(C('HOST')) || is_null(C('USER')) || is_null(C('PASS')) || is_null(C('DBNAME'))
			|| empty(C('HOST')) || empty(C('USER')) || empty(C('PASS')) || empty(C('DBNAME'))) {
			Debug::showMessage('请先配置数据库。在配置文件 Source/Inc/Config.inc.php 中设置 DB 配置。', 'Sql');
			stop();
			return;
		}

		$this->dbAlias = 'default';
		$this->applyFlatConnectionNode($node, 'default');
	}
}
