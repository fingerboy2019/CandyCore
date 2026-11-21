<?php
/***
 * Node类 - Candy框架权限节点管理系统
 * 
 * 用于管理框架中的权限节点、菜单节点和访问控制，提供节点验证、菜单生成、权限检查等核心功能。
 * 支持应用(APP)和插件(Addon)的权限节点统一管理，具备缓存机制提高性能。
 * 
 * 主要功能：
 * 1. 节点访问路径处理与标准化
 * 2. 权限验证与访问控制
 * 3. 菜单节点生成与管理
 * 4. 权限节点列表生成与缓存
 * 5. 用户权限应用与过滤
 * 6. APP和Addon节点统一管理
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com) $
 * @date 2026-10-24 12:35:00 $
 * @final 该类不允许被继承
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Node {
	/**
	 * 操作方法列表缓存
	 * 
	 * 用于存储系统中所有需要权限验证的节点方法列表，作为本地缓存使用
	 * 当Memcache不可用时，此数组将用于存储节点方法数据
	 * 
	 * @var array
	 */
	public static $methods = [];
	/** @var array<string, array<string, array>> 菜单列表进程内缓存 [locale][appName] */
	private static $menuListCache = [];
	/** @var array<string, array> 权限节点进程内缓存 [appName] */
	private static $authListCache = [];
	/** @var array<string, array> APP 菜单聚合缓存 [locale] */
	private static $appMenuListCache = [];
	/** @var array<string, array> 全量节点/权限/菜单聚合缓存 */
	private static $allNodeListCache = [];
	private static $allAuthListCache = [];
	private static $appAuthListCache = [];
	private static $addonAuthListCache = [];
	private static $allMenuListCache = [];
	private static $addonMenuListCache = [];
	private static $takeAuthListCache = [];

	/**
	 * 是否启用节点/注解扫描持久化缓存
	 *
	 * - `DEBUG=1`（开发）：先比较控制器源码清单指纹，未变化时复用节点缓存，变化时自动重扫
	 * - `DEBUG=0`（生产）：返回 true，复用 G() / Memcache / 进程内缓存
	 *
	 * 生产环境仍建议在发布后调用 {@see self::clearNodeCache()} 或执行
	 * `php candy deploy:post` / `php candy clear -c`，以主动失效跨版本缓存。
	 */
	public static function useNodeCache(): bool
	{
		// 策略（2026-11-19 用户确认）：**DEBUG 模式一律不读写节点/注解缓存**，
		// 保证开发时改注解、改控制器后立即生效，不需要清缓存；
		// 生产环境（DEBUG=0）走「进程内静态 + G()/共享缓存」加速。
		if(isDebug()){
			// 顺手清一次历史脏缓存（早期版本在 debug 下也写过缓存）
			\Candy\Extend\Attribute\utils\Tool::getNodeSourceRevision();
			return false;
		}
		return true;
	}

	/**
	 * 读取 G() 持久化节点缓存。
	 *
	 * DEBUG 模式由源码指纹负责先完成版本校验，再读取这里的共享结果。
	 */
	private static function readGlobalCache(string $key): ?array
	{
		if(!self::useNodeCache()) return null;
		$data = G($key);
		return is_array($data) ? $data : null;
	}

	/**
	 * 写入 G() 持久化节点缓存。
	 */
	private static function writeGlobalCache(string $key, array $data): void
	{
		if(self::useNodeCache()){
			G($key, $data, 0);
		}
	}

	/**
	 * 合并写入 G() 持久化节点缓存的子项。
	 */
	private static function patchGlobalCache(string $key, string $subKey, array $value): void
	{
		if(!self::useNodeCache()) return;
		$data = self::readGlobalCache($key) ?? [];
		$data[$subKey] = $value;
		self::writeGlobalCache($key, $data);
	}

	/**
	 * 当前请求语言（菜单/权限文案按语言分缓存）
	 */
	private static function currentLang(): string
	{
		return G('LANG') ?: 'zh-cn';
	}
	/**
     * 获取标准访问节点
     * 
     * 将输入的节点路径标准化，确保节点格式为完整的三段式路径：app/control/action
     * 支持处理三种情况：
     * 1. 空节点：返回当前访问节点
     * 2. 单段节点：自动补全为当前应用和控制器下的方法
     * 3. 两段节点：自动补全为当前应用下的控制器和方法
     * 4. 三段节点：直接处理并返回标准化格式
     * 
     * @param string|null $node 节点路径，可以是null、单段、两段或三段格式
     * @return string 标准化后的完整节点路径(app/control/action)
     */
    public static function full(?string $node = null): string
    {
        if(empty($node)) return self::current();
        if(stripos($node, '/') === false){
            $node = C('APPNAME') . '/' . C('CONTROL') . '/' . $node;
        }else{
			$nodes = explode('/', $node);
			if(count($nodes) == 2){
				$node = C('APPNAME') . '/' . $nodes[0] . '/' . $nodes[1];
			}
        }
        return \Candy\Extend\Str::parseString(trim($node, " /"));
    }

	/**
	 * 将权限节点路径规范为 parseString 形态（App/Control_Name/action）
	 *
	 * 扫描目录常见为源码 PascalCase（如 PurchasePrice），而 current()/full() 会插入下划线。
	 * 比对前先把控制器段经 controlName 还原再 parseString，避免 Purchase_Price 被二次处理成 Purchase__Price。
	 *
	 * @param string $path 原始节点路径
	 * @return string 规范化路径；空输入返回空串
	 */
	public static function normalizeAuthPath(string $path): string
	{
		$path = trim($path, " /");
		if ($path === '') {
			return '';
		}
		$parts = explode('/', $path, 3);
		if (count($parts) < 3) {
			return \Candy\Extend\Str::parseString($path);
		}
		$parts[1] = \Candy\Extend\Str::controlName($parts[1]);
		return \Candy\Extend\Str::parseString($parts[0] . '/' . $parts[1] . '/' . $parts[2]);
	}
	
	/**
     * 获取当前访问节点
     * 
     * 根据当前请求上下文，组合应用名称、控制器和操作方法，生成当前访问的完整节点路径
     * 节点格式为：app/control/action
     * 
     * @return string 当前访问的完整节点路径
     */
    public static function current(): string
    {
        return self::normalizeAuthPath(C('APPNAME') . '/' . C('CONTROL') . '/' . C('ACTION'));
    }

	/**
	 * 判断控制器是否声明为整类免 RBAC 扫描与校验
	 *
	 * 控制器内定义空的 __noAuth() 或 closeAuth() 即可；
	 * Node 将跳过对该控制器的方法注解循环解析，并在 checkAuth 中直接放行。
	 *
	 * @param \ReflectionClass $refClass 控制器反射类
	 * @return bool
	 */
	public static function isNoAuthController(\ReflectionClass $refClass): bool
	{
		return $refClass->hasMethod('closeAuth') || $refClass->hasMethod('__noAuth');
	}

	/**
	 * 解析当前操作的 actInfo（仅反射目标方法，不遍历全部 public 方法）
	 *
	 * @param \ReflectionClass $refClass 控制器反射类
	 * @param string $action 操作方法名
	 * @param string $real 完整节点路径
	 * @return array<string, mixed>|null
	 */
	private static function resolveActionActInfo(\ReflectionClass $refClass, string $action, string $real): ?array
	{
		if(!$refClass->hasMethod($action)){
			return null;
		}

		$method = $refClass->getMethod($action);
		if(!$method->isPublic() || str_starts_with($method->name, '_')){
			return null;
		}

		$methodData = \Candy\Extend\Attribute\utils\Tool::parseMethodAnnotation($refClass, $method);
		if($methodData === []){
			return null;
		}

		$methodData['Action'] = $real;
		if(function_exists('localizeActInfo')){
			$methodData = localizeActInfo($methodData);
		}

		C('actInfo', $methodData);
		return $methodData;
	}
	
	/**
     * 强制验证访问权限
     *
     * 验证指定节点的访问权限，支持多种权限检查机制：
     * 1. 超级管理员(Admin)角色直接通过权限验证
     * 2. 检查控制器是否有closeAuth或__noAuth方法(表示无需权限验证)
     * 3. 检查方法注释中是否包含@Authtrue标记(表示需要权限验证)
     * 4. 根据用户拥有的权限节点列表进行验证
     *
     * 兼容 JWT 模式：
     * - 优先从 JWT Token 获取用户角色和权限
     * - 如果 JWT 不可用，回退到 Session 模式
     *
     * @param ?string $node 要验证的节点路径，null表示当前节点
     * @param array $auths 用户权限节点数组，默认为空(将自动从 JWT/Session 获取)
     * @return array 包含两个元素的数组：[是否需要权限检查, 是否通过权限验证]
     */
    public static function checkAuth(?string $node = null,array $auths = []): array
    {
		//取出默认操作（Node 路径经 parseString 后可能是 Demo_Item）
        $real = self::normalizeAuthPath($node === null ? (C('APPNAME') . '/' . C('CONTROL') . '/' . C('ACTION')) : self::full($node));

		//解析出应用类名；控制器段还原为源码 PascalCase（Demo_Item → DemoItem）
        [$appName, $control, $action] = explode('/', $real);
		$controlClass = \Candy\Extend\Str::controlName($control);
		// 按目标应用是否 Addon 解析 FQCN，禁止写死 Control（扩展入口走 Addon\{App}\…Action）
		$fqcn = \Candy\Core\Trait\App\RuntimeClassLoader::resolveFqcn('action', $appName, $controlClass . 'Action');
		$className = $fqcn !== '' ? ('\\' . $fqcn) : ('\\APP\\Control\\' . $appName . '\\' . $controlClass . 'Action');

		//获取权限节点
		$refClass = Container::getInstance()->invokeReflectionClass($className);

		C('actInfo', null);
		$methodData = null;
		if(is_null($node)){
			$methodData = self::resolveActionActInfo($refClass, $action, $real);
		}

		// 获取用户角色（兼容 JWT 模式）
		$userRole = self::_getUserRole();

		//默认超级管理员角色
        if($userRole === 'Admin') return [false, true];

		// 业务平台 is_admin / is_owner：约定在已购应用内全量放行（短路职权勾选，仍受套餐模块限制）
		// 读取中间件已写入的 TenantPermission 求交结果，不另造身份标记。
		// Portal 模块为可选项：未安装时跳过租户权限求交（S() 找不到类会直接 exit，try/catch 拦不住）
		$portalPermissionFile = dirname(__DIR__, 2) . '/Application/Portal/Services/TenantPermission.class.php';
		if (is_file($portalPermissionFile)) {
			try {
				$resolution = S('Portal://TenantPermission')->currentResolution();
				if (is_array($resolution) && !empty($resolution['is_admin'])) {
					foreach ((array)($resolution['allowed_apps'] ?? []) as $allowedApp) {
						if (strcasecmp((string)$allowedApp, $appName) === 0) {
							return [false, true];
						}
					}
				}
			} catch (\Throwable) {
				// 求交结果未就绪时保持后续节点比对
			}
		}

		// 整控制器免 RBAC：跳过权限列表比对（免扫描控制器仍解析当前 actInfo 供日志等使用）
		if(self::isNoAuthController($refClass)) return [false, true];

		// 指定节点校验时需单独解析目标方法注解
		if($methodData === null){
			$method = $refClass->hasMethod($action) ? $refClass->getMethod($action) : null;
			if($method && $method->isPublic() && !str_starts_with($method->name, '_')){
				$methodData = \Candy\Extend\Attribute\utils\Tool::parseMethodAnnotation($refClass, $method);
			}
		}

		// 未声明 auth: true 的节点不参与 RBAC，登录后即可访问
		if(($methodData['Auth'] ?? false) !== true) return [false, true];

		//检查节点权限（兼容 JWT 模式）
		if(empty($auths)){
			$auths = self::_getUserAuths();
		}

		if(!empty($auths)){
			if (array_is_list($auths)) {
				$normalized = [];
				foreach ($auths as $path) {
					$normalized[self::normalizeAuthPath((string)$path)] = true;
				}
				return [true, isset($normalized[$real])];
			}
			$controlKeys = array_values(array_unique(array_filter([
				$control,
				$controlClass,
				\Candy\Extend\Str::parseString($controlClass),
			], static fn(string $value): bool => $value !== '')));
			foreach ($controlKeys as $controlKey) {
				$nodes = $auths[$appName][$controlKey] ?? null;
				if (!is_array($nodes)) {
					continue;
				}
				if (in_array($real, $nodes, true) || in_array($action, $nodes, true)) {
					return [true, true];
				}
				foreach ($nodes as $nodePath) {
					if (is_string($nodePath) && self::normalizeAuthPath($nodePath) === $real) {
						return [true, true];
					}
				}
			}
			return [true, false];
		}

		return [true, false];
    }

	/**
	 * 获取当前用户角色（支持配置钩子自定义实现）
	 *
	 * 优先级：
	 * 1. 配置的钩子函数 get_user_role
	 * 2. JWT Token（如果配置了 user_role_field）
	 * 3. Session 回退
	 *
	 * @return string 用户角色
	 */
	private static function _getUserRole(): string
	{
		//角色读取钩子
		if(function_exists('getUserRole')){
			return (string)getUserRole();
		}

		// 授权码临时职权：优先于超级管理员默认逻辑
		if ((int)session('loginAuthDutyId') > 0 || (int)session('loginAuthGroupId') > 0) {
			return (string)(session('userRole') ?: 'User');
		}
		
		// JWT：uid=1 为超级管理员，跳过权限过滤
		try {
			$userId = \Candy\Extend\JwtToken::getCurrentId();
			if ($userId === 1) {
				return 'Admin';
			}
			$extend = \Candy\Extend\JwtToken::getExtend();
			if(!empty($extend) && isset($extend['role'])){
				return (string)$extend['role'];
			}
		} catch (\Exception $e) {}
		
		return session('userRole') ?: 'self';
	}

	/**
	 * 获取当前用户权限节点（按角色表达式推导并缓存）
	 *
	 * 优先级：
	 * 1. 配置的钩子函数 get_user_auths
	 * 2. 根据角色/补充权限生成缓存键并读取推导结果
	 *
	 * @return array 用户权限节点数组
	 */
	private static function _getUserAuths(): array
	{
		//权限读取钩子
		if(function_exists('getUserAuths')){
			return getUserAuths();
		}
		
		$context = self::permissionContext();
		if ($context === []) return [];
		return self::resolvePermissionCache(
			$context['expression'],
			$context['cacheId']
		);
	}

	/**
	 * 从当前登录身份读取角色表达式，构造权限缓存上下文。
	 * 总后台使用固定租户 000000；租户应用可通过 session tenantId 覆盖。
	 *
	 * @return array{expression:string,cacheId:string}|array
	 */
	private static function permissionContext(): array
	{
		// Admin 身份在菜单 force / checkAuth 处短路，不推导节点表
		if (self::_getUserRole() === 'Admin') {
			return [];
		}

		// 优先读 Session 表达式（Portal bindSessionPermission / 总后台登录写入）。
		// 不得先要求 JwtToken::getCurrentId()>0：JWT 未就绪或 CLI/子请求时会整表变空，
		// getAppMenuList(force) 只剩 need_auth=false，业务平台「系统设置」整组消失。
		$sessionExpression = session('permissionExpression');
		$sessionRoleIds = session('permissionRoleIds');
		if (is_string($sessionExpression) && $sessionExpression !== '' && is_array($sessionRoleIds)) {
			$normalized = self::normalizePermissionExpression($sessionExpression);
			$roleIds = array_values(array_unique(array_filter(array_map('intval', $sessionRoleIds))));
			return [
				'expression' => $normalized['expression'],
				'cacheId' => self::buildPermissionCacheId(
					'roles-' . ($roleIds !== [] ? implode('-', $roleIds) : 'none'),
					$normalized['digest'],
					(string)(session('tenantId') ?: '000000')
				),
			];
		}

		try {
			$userId = (int)\Candy\Extend\JwtToken::getCurrentId();
		} catch (\Throwable) {
			$sessionUser = session('adminUser');
			$userId = (int)((is_array($sessionUser) ? ($sessionUser['userid'] ?? 0) : 0) ?: session('userId'));
		}
		if ($userId <= 0) {
			return [];
		}

		// 任意已绑定的业务平台会话（含演示租户号 000000）：禁止回落总后台 Admin/OrgDuty。
		// 旧逻辑把 session tenantId 缺省成 000000 且「非 000000 才拦截」，导致演示租户
		// 在表达式未就绪时被平台职权裁菜单，「系统设置」等 auth 叶子整组消失（不稳定）。
		$sessionTenantId = trim((string)(session('tenantId') ?: ''));
		if ($sessionTenantId !== '') {
			return [];
		}

		try {
			$admin = M('Admin');
			$dutyIds = $admin->listUserDutyIds($userId);
			if (!is_array($dutyIds) || $dutyIds === []) {
				$user = $admin->where(['uid' => $userId])->find();
				$dutyIds = [(int)($user['duty_id'] ?? 0)];
			} else {
				$user = $admin->where(['uid' => $userId])->find();
			}
			$dutyIds = array_values(array_unique(array_filter(array_map('intval', $dutyIds))));
			$expressions = [];
			foreach ($dutyIds as $dutyId) {
				$group = M('OrgDuty')->where(['duty_id' => $dutyId])->find();
				if (is_array($group) && !empty($group['auth'])) $expressions[] = (string)$group['auth'];
				if (is_array($group) && ($group['role'] ?? '') === 'Manage') $expressions[] = 'Admin:*';
			}
			$plus = (string)($user['userplusauth'] ?? $user['userPlusAuth'] ?? '');
			if ($plus !== '') $expressions[] = $plus;
			$normalized = self::normalizePermissionExpression(implode(',', $expressions));
			$roleId = implode('-', $dutyIds);
			$cacheId = self::buildPermissionCacheId(
				'roles-' . ($roleId !== '' ? $roleId : 'none'),
				$normalized['digest'],
				$tenantId
			);
			return ['expression' => $normalized['expression'], 'cacheId' => $cacheId];
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * 判断权限树是否包含指定节点（完整路径）
	 *
	 * 存储格式为 Admin/Control/action；兼容仅 action 名的历史数据
	 *
	 * @param array $auths 用户权限树
	 * @param string $fullPath 完整节点路径
	 * @return bool
	 */
	private static function hasAuthNode(array $auths, string $fullPath): bool
	{
		$fullPath = self::normalizeAuthPath($fullPath);
		$parts = explode('/', trim($fullPath, '/'));
		if (count($parts) < 3) {
			return false;
		}

		$app = $parts[0];
		$control = $parts[1];
		$action = implode('/', array_slice($parts, 2));
		$controlClass = \Candy\Extend\Str::controlName($control);

		// 嵌套格式：$auths[$app][$control] = [action...]（旧权限缓存）
		foreach (array_values(array_unique([$control, $controlClass])) as $controlKey) {
			if (isset($auths[$app][$controlKey]) && is_array($auths[$app][$controlKey])) {
				$nodes = $auths[$app][$controlKey];
				if (in_array($fullPath, $nodes, true) || in_array($action, $nodes, true)) {
					return true;
				}
				foreach ($nodes as $nodePath) {
					if (is_string($nodePath) && self::normalizeAuthPath($nodePath) === $fullPath) {
						return true;
					}
				}
			}
		}

		// 扁平格式：['App/Control/action', ...]（derivePermissionExpression 输出）
		if (in_array($fullPath, $auths, true)) {
			return true;
		}
		foreach ($auths as $nodePath) {
			if (is_string($nodePath) && self::normalizeAuthPath($nodePath) === $fullPath) {
				return true;
			}
		}
		return false;
	}
	
	/**
     * 获取节点方法数据缓存
     * 
     * 优先从共享缓存获取节点方法数据缓存，如果缓存不可用，则从类静态变量获取。
     * 节点方法属于代码元数据，不随租户变化，必须使用共享键避免每个租户重复压缩写入。
     * 此方法提供统一的缓存访问接口，确保在不同环境下都能正常工作
     * 
     * @return array 节点方法数据数组
     */
    public static function getMethodsData():array
    {
		// DEBUG 模式先检查源码指纹，确保进程内旧数据不会绕过自动失效。
		$useCache = self::useNodeCache();
		if(!empty(self::$methods)){
			return self::$methods;
		}

		if($useCache && !\Candy\Core\Cache::isFileDriver() && $memCache = \Candy\Core\Cache::store()){
			// 缓存驱动未命中时可能返回 null（各驱动对默认值的实现不一致），
			// 这里必须收敛成数组：方法声明返回 array，返回 null 会直接 TypeError 打崩首页。
			$loaded = $memCache->get(\Candy\Core\Cache::sharedKey('NodeMethods'), []);
			self::$methods = is_array($loaded) ? $loaded : [];
		}

		return is_array(self::$methods) ? self::$methods : [];
    }
	
	/**
     * 设置节点方法数据缓存
     * 
     * 将节点方法数据保存到共享缓存，优先使用 Redis/Memcache，如果不可用则保存到类静态变量。
     * 节点方法是全局代码元数据，不应绑定当前租户的缓存作用域。
     * 此方法提供统一的缓存设置接口，确保数据一致性
     * 
     * @param array $data 要缓存的节点方法数据
     * @return void
     */
    public static function setMethodsData($data): void
    {
		self::$methods = $data;

		$useCache = self::useNodeCache();
		if($useCache && !\Candy\Core\Cache::isFileDriver() && $memCache = \Candy\Core\Cache::store()){
			$memCache->set(\Candy\Core\Cache::sharedKey('NodeMethods'), $data);
		}
    }
	
	/**
	 * 保存节点权限信息
	 * 
	 * 将新的节点权限列表合并到现有缓存中，使用数组合并方式保留原有数据并更新新增数据
	 * 此方法用于动态添加新的权限节点到系统中
	 * 
	 * @param array $methodList 要保存的节点权限列表数组
	 * @return void
	 */
	public static function saveMethodList(array $methodList = []): void
	{
		self::setMethodsData(array_merge(self::getMethodsData(), $methodList));
	}
	
	/**
     * 获取所有 APP 菜单节点
     *
     * 按 App.inc 顺序与 G('appNode')['menu'] 组装应用菜单。
     * 进程内缓存只存「全量」Controls（与 getMenuList 一致）；force 仅影响返回值。
     * 禁止把按用户职权裁过的结果写入缓存，否则同一 Worker 内后登录的所有者会丢「系统设置」等菜单。
     *
     * @param bool $force 是否按当前身份过滤叶子；业务平台壳菜单应传 false，再由 TenantMenu 求交
     * @return array [appName=>['Name'=>应用名,'Controls'=>分组菜单]]
     */
    public static function getAppMenuList(bool $force = true): array
    {
    	if(is_null(G('appNode')) || empty(G('appNode')['menu'])){
    		// R-A2 告警：appNode 缺失/漏配会导致整应用菜单消失，DEBUG 下记录调用来源
    		if (isDebug()) {
    			\Candy\Core\Log::input(
    				"appNode menu config missing: " . (is_null(G('appNode')) ? 'null' : json_encode(G('appNode'), JSON_UNESCAPED_UNICODE)),
    				-1,
    				C('LOGPATH') . 'node-appnode-missing-' . date('Y-m-d') . '.log'
    			);
    		}
    		return [];
    	}
    	$appNode = G('appNode')['menu'];
    	$lang = self::currentLang();
    	$cacheKey = self::menuCacheKey($lang, (array)$appNode);

    	$menus = null;
    	if(self::useNodeCache() && isset(self::$appMenuListCache[$cacheKey])){
    		$menus = self::$appMenuListCache[$cacheKey];
    	}

		if ($menus === null) {
			$menus = [];
			$apps = dirs(C('CANDYROOT') . 'Application');
			$appOrder = loadConfig('App');

			if(count($appOrder) > 0){
				foreach($appOrder as $appName=>$info){
					if(in_array($appName, $apps)){
						if(isset($menus[$appName]) || !in_array($appName, $appNode)) continue;
						$key = array_keys($apps, $appName)[0];
						unset($apps[$key]);
						// 全量入缓存；权限裁剪见下方 applyForceToAppMenuTree / 调用方二次过滤
						$menulist = self::getMenuList($appName, false);
						if($menulist){
							$menus[$appName] = [
								'Name'=>$info['name'],
								'Controls'=>$menulist,
							];
						}
					}
				}
			}

			foreach($apps as $appName){
				if(isset($menus[$appName]) || !in_array($appName, $appNode)) continue;
				$menulist = self::getMenuList($appName, false);
				if($menulist){
					$menus[$appName] = [
						'Name'=>$appName . '菜单列表',
						'Controls'=>$menulist,
					];
				}
			}

			if(self::useNodeCache()){
				self::$appMenuListCache[$cacheKey] = $menus;
			}
		}

		return self::applyForceToAppMenuTree($menus, $force);
    }

	/**
	 * 对多应用菜单树按 force 过滤各应用 Controls
	 *
	 * @param array<string,array> $menus getAppMenuList 全量树
	 * @param bool $force 是否按当前身份过滤
	 * @return array<string,array>
	 */
	private static function applyForceToAppMenuTree(array $menus, bool $force): array
	{
		if (!$force) {
			return $menus;
		}
		$out = [];
		foreach ($menus as $appName => $appInfo) {
			if (!is_array($appInfo) || !isset($appInfo['Controls']) || !is_array($appInfo['Controls'])) {
				continue;
			}
			$filtered = self::applyMenuListForceFilter($appInfo['Controls'], true);
			if ($filtered === []) {
				continue;
			}
			$appInfo['Controls'] = $filtered;
			$out[$appName] = $appInfo;
		}
		return $out;
	}
	
	/**
     * 获取菜单节点列表
     * 
     * 获取指定应用的菜单节点列表，支持权限验证过滤
     * 从控制器和方法的注释中解析菜单信息，根据@Menu属性生成菜单
     * DEBUG 和生产模式都会缓存结果；源码指纹变化时自动失效并重建。
     * 
     * @param string|null $appName 应用名称，null表示当前应用
     * @param bool $force 是否强制执行权限过滤，如果为true且用户非管理员，则只返回用户有权限的菜单
     * @return array 菜单节点列表，格式为[group=>['name'=>分组名称,'menus'=>[节点路径=>菜单标题]]]
     */
    public static function getMenuList(?string $appName = null,bool $force = false): array
    {
		empty($appName) && $appName = C('APPNAME');
		$lang = G('LANG') ?: 'zh-cn';
		$cacheKey = 'nodeMenuList_' . $lang;

		// 缓存必须存「全量」菜单；force 只影响返回值。
		// 否则 Portal 以 User 身份先扫 App.inc 里的 Crm 时，空结果会写入缓存，壳菜单整块丢失「客户管理」。
		$appNodes = null;
		if(self::useNodeCache() && isset(self::$menuListCache[$lang][$appName])){
			$appNodes = self::$menuListCache[$lang][$appName];
		} elseif(self::useNodeCache()){
			$cached = self::readGlobalCache($cacheKey);
			if(is_array($cached) && isset($cached[$appName])){
				self::$menuListCache[$lang][$appName] = $cached[$appName];
				$appNodes = $cached[$appName];
			}
		}

		if ($appNodes === null) {
			$appNodes = [];
			$classList = \Candy\Extend\Attribute\utils\Tool::getClassList($appName);

			foreach($classList as $node => $info){
				foreach($info['Actions'] as $method => $menu){
					if(($menu['Menu'] ?? false) === true){
						$key = $appName . '/' . $node . '/' . $method;
						$group = $menu['Group'] ?? $node;
						$groupLang = L(strtoupper($group) . '_NAME');
						if($groupLang){
							$appNodes[$group]['Name'] = $groupLang;
						}elseif($node === $group && empty($appNodes[$group]['Name'])){
							$appNodes[$group]['Name'] = $info['Name'];
						}

						if (isset($info['GroupOrder'])) {
							$ctrlGroup = (string)($info['Group'] ?? $node);
							if ($ctrlGroup === $group) {
								$groupOrder = (int)$info['GroupOrder'];
								if (!isset($appNodes[$group]['Order']) || $groupOrder < (int)$appNodes[$group]['Order']) {
									$appNodes[$group]['Order'] = $groupOrder;
								}
							}
						}

						$menuLangKey = 'MENU_' . strtoupper($node) . '_' . strtoupper($method);
						if ($lang === 'zh-cn') {
							$menuTitle = $menu['Title'];
						} else {
							$menuTitle = L($menuLangKey);
							if ($menuTitle === '') {
								$menuTitle = L(strtoupper($node) . '_NAME');
							}
							if ($menuTitle === '') {
								$menuTitle = $menu['Title'];
							}
						}
						$item = isset($menu['Icon'])
							? ['icon' => $menu['Icon'], 'name' => $menuTitle]
							: ['name' => $menuTitle];
						// 供 force 过滤：是否要求鉴权节点（不输出到前端）
						$item['_auth'] = ($menu['Auth'] ?? false) === true;
						// 临时 Order：注解 order 优先，否则 classOrder；排序后剥离
						$effectiveOrder = self::resolveItemOrder($menu, $info);
						if ($effectiveOrder !== null) {
							$item['Order'] = $effectiveOrder;
						}
						$appNodes[$group]['Menus'][$key] = $item;
					}
				}
			}

			self::mergeGroupOrderFromClassList($appNodes, $classList);
			$appNodes = self::sortGroupedNodeList($appNodes);
			foreach ($appNodes as $groupKey => $groupData) {
				if (!empty($groupData['Menus']) && is_array($groupData['Menus'])) {
					$appNodes[$groupKey]['Menus'] = self::sortAssocItemsByOrder($groupData['Menus']);
				}
			}

			if(self::useNodeCache()){
				self::$menuListCache[$lang][$appName] = $appNodes;
				self::patchGlobalCache($cacheKey, $appName, $appNodes);
			}
		}

		return self::applyMenuListForceFilter($appNodes, $force);
    }

	/**
	 * 按 force / 当前身份过滤菜单副本
	 *
	 * Admin 或 force=false：原样返回（去掉内部 _auth 标记）。
	 * force=true 且非 Admin：仅保留无权校验或已授权的叶子。
	 *
	 * @param array $appNodes 全量分组菜单
	 * @param bool $force 是否按用户职权过滤
	 * @return array
	 */
	private static function applyMenuListForceFilter(array $appNodes, bool $force): array
	{
		$userRole = self::_getUserRole();
		$auths = $userRole === 'Admin' ? true : ($force ? self::_getUserAuths() : false);
		$out = [];
		foreach ($appNodes as $group => $groupData) {
			$menus = [];
			foreach ((array)($groupData['Menus'] ?? []) as $key => $item) {
				if (!is_array($item)) {
					continue;
				}
				$needAuth = array_key_exists('_auth', $item)
					? (bool)$item['_auth']
					: (bool)($item['need_auth'] ?? false);
				unset($item['_auth']);
				// 供业务平台壳菜单二次过滤：免鉴权叶子不依赖职权勾选
				$item['need_auth'] = $needAuth;
				if (is_array($auths) && $needAuth && !self::hasAuthNode($auths, (string)$key)) {
					continue;
				}
				$menus[$key] = $item;
			}
			if ($menus === []) {
				continue;
			}
			$groupData['Menus'] = $menus;
			$out[$group] = $groupData;
		}
		return $out;
	}

	/**
     * 获取授权节点列表
     * 
     * 获取指定应用中需要权限验证的节点列表
     * 从控制器和方法的注释中解析权限信息，根据@Auth属性生成权限节点
     * 在非调试模式下会缓存结果以提高性能
     * 
     * @param string|null $appName 应用名称，null表示当前应用
     * @return array 权限节点列表，格式为[group=>['name'=>分组名称,'auths'=>[节点路径=>权限标题]]]
     */
    private static function getAuthList(?string $appName = null): array
    {
		empty($appName) && $appName = C('APPNAME');

		if(self::useNodeCache() && isset(self::$authListCache[$appName])){
			return self::$authListCache[$appName];
		}

		if(self::useNodeCache()){
			$cached = self::readGlobalCache('nodeAuthList');
			if(is_array($cached) && isset($cached[$appName])){
				self::$authListCache[$appName] = $cached[$appName];
				return $cached[$appName];
			}
		}

		$appAuth = [];
		$classList = \Candy\Extend\Attribute\utils\Tool::getClassList($appName);
		foreach($classList as $node => $info){
			foreach($info['Actions'] as $method => $auth){
				// 仅收录注解显式声明 auth: true 的节点
				if (($auth['Auth'] ?? false) !== true) {
					continue;
				}
				$key = self::normalizeAuthPath($appName .'/'.$node.'/'.$method);
				$group = $auth['Group'] ?? $node;
				$appAuth[$group]['name'] = L(strtoupper($group) . '_NAME') ?: $info['Name'];

				if (isset($info['GroupOrder'])) {
					$ctrlGroup = (string)($info['Group'] ?? $node);
					if ($ctrlGroup === $group) {
						$groupOrder = (int)$info['GroupOrder'];
						if (!isset($appAuth[$group]['Order']) || $groupOrder < (int)$appAuth[$group]['Order']) {
							$appAuth[$group]['Order'] = $groupOrder;
						}
					}
				}

				$menuLangKey = 'MENU_' . strtoupper($node) . '_' . strtoupper($method);
				$authItem = [
					'title' => L($menuLangKey) ?: $auth['Title'],
				];
				$effectiveOrder = self::resolveItemOrder($auth, $info);
				if ($effectiveOrder !== null) {
					$authItem['Order'] = $effectiveOrder;
				}
				$appAuth[$group]['auths'][$key] = $authItem;
			}
		}

		self::mergeGroupOrderFromClassList($appAuth, $classList);
		$appAuth = self::sortGroupedNodeList($appAuth);
		foreach ($appAuth as $groupKey => $groupData) {
			if (empty($groupData['auths']) || !is_array($groupData['auths'])) {
				continue;
			}
			// 排序后还原为「路径 => 标题」字符串，保持对外结构不变
			$sorted = self::sortAssocItemsByOrder($groupData['auths']);
			$titles = [];
			foreach ($sorted as $path => $item) {
				$titles[$path] = is_array($item) ? (string)($item['title'] ?? '') : (string)$item;
			}
			$appAuth[$groupKey]['auths'] = $titles;
		}

		if(self::useNodeCache()){
			self::$authListCache[$appName] = $appAuth;
			self::patchGlobalCache('nodeAuthList', $appName, $appAuth);
		}

        return $appAuth;
    }
	
	/**
     * 获取所有有效APP节点
     * 
     * 获取系统中所有应用(APP)和启用的插件(Addon)的节点信息
     * 扫描Application目录获取所有应用，并获取每个应用的控制器和方法列表
     * 结果会被缓存以提高性能
     * 
     * @return array 所有应用节点信息数组，格式为[appName=>['name'=>应用名称,'controls'=>控制器列表]]
     */
    public static function getAllNodeList(): array
    {
		if(self::useNodeCache() && !empty(self::$allNodeListCache)){
			return self::$allNodeListCache;
		}

		$nodes = [];
		$apps = dirs(C('CANDYROOT') . 'Application');

		foreach($apps as $appName){
			$methodList = \Candy\Extend\Attribute\utils\Tool::getClassList($appName);
			if($methodList != false){
				$nodes[$appName] = [
					'Name'=>$appName.'项目',
					'Controls'=>$methodList
				];
			}
		}

		$addons = Addon::getEnableAddons(1);
		if(count($addons) > 0){
			foreach($addons as $appName=>$addon){
				$methodList = \Candy\Extend\Attribute\utils\Tool::getClassList($appName, 'addon');
				if($methodList != false){
					$nodes[$appName] = [
						'Name'=>$addon['name'],
						'Controls'=>$methodList
					];
				}
			}
		}

		if(self::useNodeCache()){
			self::$allNodeListCache = $nodes;
		}

		return $nodes;
	}
	
	/**
     * 获取所有验证权限的节点
     * 
     * 获取系统中所有需要权限验证的节点列表，包括所有应用和插件
     * 合并应用权限节点和插件权限节点，提供统一的权限节点访问接口
     * 结果会被缓存以提高性能
     * 
     * @return array 所有权限节点数组，包含应用和插件的权限节点信息
     */
    public static function getAllAuthList(): array
    {
		if(self::useNodeCache() && !empty(self::$allAuthListCache)){
			return self::$allAuthListCache;
		}

		$auths = array_merge(self::getAppAuthList(), self::getAddonAuthList());

		if(self::useNodeCache()){
			self::$allAuthListCache = $auths;
		}

		return $auths;
	}

	/**
	 * 获取权限分配用节点树（按 classGroup 分组）
	 *
	 * 仅包含注解 auth: true 的方法节点
	 * 分组与 Node::getAuthList 一致，使用控制器 classGroup（无则回退控制器名）
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function getAuthAssignTree(): array
	{
		$tree = [];
		$appNames = loadConfig('App');

		if (is_null(G('appNode')) || empty(G('appNode')['auth'])) {
			return $tree;
		}

		foreach (G('appNode')['auth'] as $appName) {
			$classList = \Candy\Extend\Attribute\utils\Tool::getClassList($appName);
			if (empty($classList)) {
				continue;
			}

			/** @var array<string, array{title:string,leaves:array<int,array{id:string,title:string}>}> $groupMap */
			$groupMap = [];

			foreach ($classList as $controlName => $info) {
				foreach ($info['Actions'] as $method => $action) {
					if (($action['Auth'] ?? false) !== true) {
						continue;
					}

					$groupKey = (string)($action['Group'] ?? $controlName);
					$path = self::normalizeAuthPath($appName . '/' . $controlName . '/' . $method);
					$menuLangKey = 'MENU_' . strtoupper($controlName) . '_' . strtoupper($method);

					if (!isset($groupMap[$groupKey])) {
						$groupMap[$groupKey] = [
							'title' => self::resolveAuthGroupTitle($groupKey, $info),
							'leaves' => [],
						];
					}

					$groupMap[$groupKey]['leaves'][] = [
						'id' => $path,
						'title' => L($menuLangKey) ?: ($action['Title'] ?? $method),
						'Order' => self::resolveItemOrder($action, $info),
					];
				}
			}

			if ($groupMap === []) {
				continue;
			}

			self::mergeGroupOrderFromClassList($groupMap, $classList);
			$groupMap = self::sortGroupedNodeList($groupMap);

			$appNode = [
				'id' => $appName,
				'title' => $appNames[$appName]['name'] ?? $appName,
				'children' => [],
			];

			foreach ($groupMap as $groupKey => $groupData) {
				$leaves = self::sortListItemsByOrder($groupData['leaves'] ?? []);
				$appNode['children'][] = [
					'id' => $appName . '_' . $groupKey,
					'title' => $groupData['title'],
					'children' => $leaves,
				];
			}

			$tree[] = $appNode;
		}

		return $tree;
	}

	/**
	 * 从控制器扫描结果合并各分组的 classGroupOrder
	 *
	 * 同一分组内多个控制器声明顺序时取最小值
	 *
	 * @param array<string, array<string, mixed>> $groups 分组节点（菜单或权限）
	 * @param array<string, array<string, mixed>> $classList 控制器扫描列表
	 * @return void
	 */
	private static function mergeGroupOrderFromClassList(array &$groups, array $classList): void
	{
		foreach ($classList as $node => $info) {
			if (!isset($info['GroupOrder'])) {
				continue;
			}
			$ctrlGroup = (string)($info['Group'] ?? $node);
			if (!isset($groups[$ctrlGroup])) {
				continue;
			}
			$order = (int)$info['GroupOrder'];
			if (!isset($groups[$ctrlGroup]['Order']) || $order < (int)$groups[$ctrlGroup]['Order']) {
				$groups[$ctrlGroup]['Order'] = $order;
			}
		}
	}

	/**
	 * 按 classGroupOrder 对分组节点排序
	 *
	 * 已声明 Order 的分组按数值升序；未声明的保持原插入顺序并排在已排序分组之后
	 *
	 * @param array<string, array<string, mixed>> $groups
	 * @return array<string, array<string, mixed>>
	 */
	private static function sortGroupedNodeList(array $groups): array
	{
		if ($groups === []) {
			return [];
		}

		$items = [];
		$seq = 0;
		foreach ($groups as $key => $value) {
			$items[] = [
				'key' => $key,
				'value' => $value,
				'order' => $value['Order'] ?? null,
				'seq' => $seq++,
			];
		}

		usort($items, static function (array $a, array $b): int {
			return self::compareOrderSeqKey(
				$a['order'],
				$b['order'],
				$a['seq'],
				$b['seq'],
				(string)$a['key'],
				(string)$b['key']
			);
		});

		$result = [];
		foreach ($items as $item) {
			$value = $item['value'];
			unset($value['Order']);
			$result[$item['key']] = $value;
		}

		return $result;
	}

	/**
	 * 解析菜单/权限叶子的有效排序值
	 *
	 * 优先级：方法注解 Order（Annotation order）→ 控制器 classOrder → null
	 *
	 * @param array<string, mixed> $action 方法注解信息
	 * @param array<string, mixed> $ctrlInfo 控制器扫描信息（含 Order）
	 * @return int|null
	 */
	private static function resolveItemOrder(array $action, array $ctrlInfo): ?int
	{
		if (array_key_exists('Order', $action) && $action['Order'] !== null && $action['Order'] !== '') {
			return (int)$action['Order'];
		}
		if (isset($ctrlInfo['Order'])) {
			return (int)$ctrlInfo['Order'];
		}
		return null;
	}

	/**
	 * 对关联数组叶子按 Order 升序排序（键为节点路径）
	 *
	 * 排序后剥离临时 Order 字段，保持对外结构不变
	 *
	 * @param array<string, mixed> $items
	 * @return array<string, mixed>
	 */
	private static function sortAssocItemsByOrder(array $items): array
	{
		if ($items === []) {
			return [];
		}

		$wrapped = [];
		$seq = 0;
		foreach ($items as $key => $value) {
			$order = null;
			if (is_array($value) && array_key_exists('Order', $value)) {
				$order = $value['Order'];
				unset($value['Order']);
			}
			$wrapped[] = [
				'key' => (string)$key,
				'value' => $value,
				'order' => $order,
				'seq' => $seq++,
			];
		}

		usort($wrapped, static function (array $a, array $b): int {
			return self::compareOrderSeqKey(
				$a['order'],
				$b['order'],
				$a['seq'],
				$b['seq'],
				$a['key'],
				$b['key']
			);
		});

		$result = [];
		foreach ($wrapped as $row) {
			$result[$row['key']] = $row['value'];
		}
		return $result;
	}

	/**
	 * 对列表型叶子（权限分配树）按 Order 升序排序
	 *
	 * @param array<int, array<string, mixed>> $leaves
	 * @return array<int, array<string, mixed>>
	 */
	private static function sortListItemsByOrder(array $leaves): array
	{
		if ($leaves === []) {
			return [];
		}

		$wrapped = [];
		$seq = 0;
		foreach ($leaves as $leaf) {
			$order = $leaf['Order'] ?? null;
			unset($leaf['Order']);
			$wrapped[] = [
				'value' => $leaf,
				'order' => $order,
				'seq' => $seq++,
				'key' => (string)($leaf['id'] ?? ''),
			];
		}

		usort($wrapped, static function (array $a, array $b): int {
			return self::compareOrderSeqKey(
				$a['order'],
				$b['order'],
				$a['seq'],
				$b['seq'],
				$a['key'],
				$b['key']
			);
		});

		$result = [];
		foreach ($wrapped as $row) {
			$result[] = $row['value'];
		}
		return $result;
	}

	/**
	 * 比较两项排序：有 Order 升序；无 Order 靠后；同值按 key；再按稳定序号
	 *
	 * @param mixed $aOrder
	 * @param mixed $bOrder
	 * @param int $aSeq
	 * @param int $bSeq
	 * @param string $aKey
	 * @param string $bKey
	 * @return int
	 */
	private static function compareOrderSeqKey(
		mixed $aOrder,
		mixed $bOrder,
		int $aSeq,
		int $bSeq,
		string $aKey,
		string $bKey
	): int {
		$aHas = $aOrder !== null && $aOrder !== '';
		$bHas = $bOrder !== null && $bOrder !== '';
		if ($aHas && $bHas) {
			$cmp = (int)$aOrder <=> (int)$bOrder;
			if ($cmp !== 0) {
				return $cmp;
			}
			$cmp = strcmp($aKey, $bKey);
			return $cmp !== 0 ? $cmp : ($aSeq <=> $bSeq);
		}
		if ($aHas !== $bHas) {
			return $aHas ? -1 : 1;
		}
		$cmp = strcmp($aKey, $bKey);
		return $cmp !== 0 ? $cmp : ($aSeq <=> $bSeq);
	}

	/**
	 * 解析权限分组显示名称
	 *
	 * 优先语言包 {GROUP}_NAME，其次控制器 @name
	 *
	 * @param string $groupKey 分组键（classGroup 或控制器名）
	 * @param array<string, mixed> $controlInfo 控制器扫描信息
	 * @return string
	 */
	private static function resolveAuthGroupTitle(string $groupKey, array $controlInfo): string
	{
		$groupLang = L(strtoupper($groupKey) . '_NAME');
		if ($groupLang && $groupLang !== strtoupper($groupKey) . '_NAME') {
			return $groupLang;
		}

		$name = trim((string)($controlInfo['Name'] ?? ''));
		if ($name !== '' && $name !== $groupKey . '控制器') {
			return $name;
		}

		return $groupKey;
	}
	
	/**
     * 获取所有APP验证权限的节点
     * 
     * 获取系统中所有应用的权限验证节点列表
     * 只会返回在appNode配置中允许进行权限验证的应用
     * 结果会被缓存以提高性能
     * 
     * @return array 所有应用权限节点数组，格式为[appName=>['name'=>应用名称,'list'=>权限列表]]
     */
    private static function getAppAuthList(): array
    {
		if(self::useNodeCache() && !empty(self::$appAuthListCache)){
			return self::$appAuthListCache;
		}

		$auths = [];
		$apps = dirs(C('CANDYROOT') . 'Application');
		$appNames = loadConfig('App');

		if(is_null(G('appNode')) || empty(G('appNode')['auth'])){
			return [];
		}
		$appNode = G('appNode')['auth'];

		foreach($apps as $appName){
			if(!in_array($appName, $appNode)) continue;
			$authlist = self::getAuthList($appName);
			if($authlist){
				$auths[$appName] = [
					'name'=>isset($appNames[$appName]) ? $appNames[$appName]['name'] : $appName,
					'list'=>$authlist,
				];
			}
		}

		if(self::useNodeCache()){
			self::$appAuthListCache = $auths;
		}

		return $auths;
	}
	
	/**
     * 获取所有Addon验证权限的节点
     * 
     * 获取系统中所有启用的插件(Addon)的权限验证节点列表
     * 只处理已经启用的插件，确保安全性
     * 结果会被缓存以提高性能
     * 
     * @return array 所有插件权限节点数组，包含各插件的权限节点信息
     */
    private static function getAddonAuthList(): array
    {
		if(self::useNodeCache() && !empty(self::$addonAuthListCache)){
			return self::$addonAuthListCache;
		}

		$auths = [];
		$addons = Addon::getEnableAddons(1);
		if(count($addons) > 0){
			foreach($addons as $appName=>$addon){
				$authlist = self::getAuthList($appName);
				if($authlist){
					// 与 getAppAuthList 同结构：['name'=>…, 'list'=>分组权限]，供 takeAuthList 扁平化
					$auths[$appName] = [
						'name' => (string)($addon['name'] ?? $appName),
						'list' => $authlist,
					];
				}
			}
		}

		if(self::useNodeCache()){
			self::$addonAuthListCache = $auths;
		}

		return $auths;
	}
	
	/**
     * 获取权限节点列表（扁平化）
     * 
     * 获取所有需要权限验证的节点列表，并将其扁平化为一维数组
     * 只返回节点路径，不包含标题信息，便于快速检查权限
     * 在非调试模式下会缓存结果以提高性能
     * 
     * @return array 扁平的权限节点路径数组
     */
	public static function takeAuthList(): array
    {
		if(self::useNodeCache() && !empty(self::$takeAuthListCache)){
			return self::$takeAuthListCache;
		}

		if(self::useNodeCache()){
			$cached = self::readGlobalCache('checkAuthList');
			if(!empty($cached)){
				self::$takeAuthListCache = $cached;
				return $cached;
			}
		}

		$authlist = [];
		$auths = self::getAllAuthList();
		foreach($auths as $app){
			foreach($app['list'] as $groupAuths){
				$authlist = array_merge($authlist, $groupAuths['auths']);
			}
		}
		$authlist = array_keys($authlist);

		if(self::useNodeCache()){
			self::$takeAuthListCache = $authlist;
			self::writeGlobalCache('checkAuthList', $authlist);
		}

		return $authlist;
	}

	/**
	 * 将展开的权限节点组合为紧凑权限表达式。
	 *
	 * 表达式示例：Admin:Member{add,edit},Post{add},User{*}。
	 * 该字符串作为角色/用户权限的持久化格式，不包含 Base64 或序列化数据。
	 *
	 * @param array<int, string> $paths
	 * @param array<int, string>|null $catalog 全量权限目录，默认读取 Node 扫描结果
	 * @return string
	 */
	public static function composePermissionExpression(array $paths, ?array $catalog = null): string
	{
		$catalog ??= self::takeAuthList();
		$selected = [];
		foreach ($paths as $path) {
			if (!is_string($path) || trim($path) === '') continue;
			$parts = explode('/', trim($path, '/'));
			if (count($parts) < 3) continue;
			$app = $parts[0];
			$control = $parts[1];
			$action = implode('/', array_slice($parts, 2));
			$selected[$app][$control][$action] = true;
		}

		$available = [];
		foreach ($catalog as $path) {
			if (!is_string($path)) continue;
			$parts = explode('/', trim($path, '/'));
			if (count($parts) < 3) continue;
			$available[$parts[0]][$parts[1]][implode('/', array_slice($parts, 2))] = true;
		}

		$apps = [];
		foreach ($selected as $app => $controls) {
			$allApp = isset($available[$app]) && self::permissionMapContains($controls, $available[$app]);
			if ($allApp) {
				$apps[] = $app . ':*';
				continue;
			}
			$controlExpr = [];
			ksort($controls);
			foreach ($controls as $control => $actions) {
				ksort($actions);
				$actionNames = array_keys($actions);
				if (isset($available[$app][$control]) && self::permissionSetContains($actions, $available[$app][$control])) {
					$controlExpr[] = $control . ':*';
				} elseif (count($actionNames) === 1) {
					$controlExpr[] = $control . ':' . $actionNames[0];
				} elseif ($actionNames !== []) {
					$controlExpr[] = $control . '{' . implode(',', $actionNames) . '}';
				}
			}
			foreach ($controlExpr as $expr) {
				$apps[] = $app . ':' . $expr;
			}
		}

		return implode(',', $apps);
	}

	/** @param array<string, array<string, array<string, bool>>> $selected @param array<string, array<string, bool>> $available */
	private static function permissionMapContains(array $selected, array $available): bool
	{
		foreach ($available as $control => $actions) {
			if (!isset($selected[$control]) || !self::permissionSetContains($selected[$control], $actions)) return false;
		}
		return $selected !== [] && count($selected) === count($available);
	}

	/** @param array<string, bool> $selected @param array<string, bool> $available */
	private static function permissionSetContains(array $selected, array $available): bool
	{
		return $selected !== [] && count($selected) === count($available)
			&& array_diff_key($available, $selected) === [];
	}

	/**
	 * 规范化权限表达式并计算摘要，供权限缓存键使用。
	 *
	 * @param string|array<int, string> $expression
	 * @return array{expression:string,digest:string}
	 */
	public static function normalizePermissionExpression(string|array $expression): array
	{
		if (is_array($expression)) $expression = self::composePermissionExpression($expression);
		$expression = preg_replace('/\s+/', '', trim($expression)) ?: '';
		$items = $expression === '' ? [] : self::splitPermissionExpression($expression);
		$items = array_values(array_unique($items));
		sort($items, SORT_STRING);
		$normalized = implode(',', $items);
		return [
			'expression' => $normalized,
			'digest' => $normalized === '' ? 'none' : substr(hash('sha256', $normalized), 0, 32),
		];
	}

	/** @return array<int, string> 按顶层逗号拆分表达式，保留 {...} 内的动作逗号 */
	private static function splitPermissionExpression(string $expression): array
	{
		$items = [];
		$buffer = '';
		$depth = 0;
		$length = strlen($expression);
		for ($i = 0; $i < $length; $i++) {
			$char = $expression[$i];
			if ($char === '{') $depth++;
			if ($char === '}' && $depth > 0) $depth--;
			if ($char === ',' && $depth === 0) {
				if (trim($buffer) !== '') $items[] = trim($buffer);
				$buffer = '';
				continue;
			}
			$buffer .= $char;
		}
		if (trim($buffer) !== '') $items[] = trim($buffer);
		return $items;
	}

	/**
	 * 根据权限表达式推导最终节点集合。
	 *
	 * @param string $expression
	 * @param array<int, string>|null $catalog
	 * @return array<int, string>
	 */
	public static function derivePermissionExpression(string $expression, ?array $catalog = null): array
	{
		$useRuntimeCatalog = $catalog === null;
		$catalog ??= self::takeAllNodeList();
		$catalog = array_values(array_unique(array_filter($catalog, 'is_string')));
		$result = [];
		foreach (self::splitPermissionExpression(str_replace(' ', '', trim($expression))) as $item) {
			$parts = explode(':', $item, 2);
			if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') continue;
			$app = $parts[0];
			$target = $parts[1];
			foreach ($catalog as $path) {
				[$pathApp, $pathControl, $pathAction] = array_pad(explode('/', trim($path, '/'), 3), 3, '');
				if ($pathApp !== $app) continue;
				if ($target === '*') {
					$result[] = $path;
					continue;
				}
				$control = '';
				$actions = null;
				if (str_contains($target, '{')) {
					$pos = strpos($target, '{');
					$control = substr($target, 0, $pos);
					$actions = trim(substr($target, $pos + 1), '{}');
				} else {
					$targetParts = explode(':', $target, 2);
					$control = $targetParts[0] ?? '';
					$actions = $targetParts[1] ?? null;
				}
				if ($control !== $pathControl) continue;
				if ($actions === null) {
					$result[] = $path;
					continue;
				}
				if ($actions === '*') {
					$result[] = $path;
				} elseif (in_array($pathAction, array_map('trim', explode(',', $actions)), true)) {
					$result[] = $path;
				}
			}
		}
		// 非 auth:true 节点不需要角色授权，始终加入有效权限集合。
		if ($useRuntimeCatalog) {
			foreach (self::takeOpenNodeList() as $path) {
				$result[] = $path;
			}
		}
		return array_values(array_unique($result));
	}

	/** 获取所有节点，包含无需 auth:true 的免授权节点。 */
	public static function takeAllNodeList(): array
	{
		$paths = [];
		foreach (self::getAllNodeList() as $appName => $app) {
			foreach (($app['Controls'] ?? []) as $controlName => $control) {
				foreach (($control['Actions'] ?? []) as $actionName => $action) {
					$paths[] = self::normalizeAuthPath($appName . '/' . $controlName . '/' . $actionName);
				}
			}
		}
		return array_values(array_unique($paths));
	}

	/**
	 * 获取所有应用和已启用插件中显式声明 auth:true 的权限节点。
	 *
	 * 与 takeAuthList() 不同，本方法不读取 appNode，因此不会受当前租户套餐或
	 * 当前会话扫描范围影响。适合权限巡检、静态审计等只读场景建立完整页面全集。
	 * 方法只使用节点扫描结果，不写入全局权限上下文。
	 *
	 * @return list<string> 全量 auth:true 节点路径
	 */
	public static function takeAllAuthList(): array
	{
		$paths = [];
		foreach (self::getAllNodeList() as $appName => $app) {
			foreach (($app['Controls'] ?? []) as $controlName => $control) {
				foreach (($control['Actions'] ?? []) as $actionName => $action) {
					if (($action['Auth'] ?? false) !== true) {
						continue;
					}
					$paths[] = self::normalizeAuthPath($appName . '/' . $controlName . '/' . $actionName);
				}
			}
		}
		return array_values(array_unique(array_filter($paths)));
	}

	/** 获取不声明 auth:true 的免授权节点。 */
	public static function takeOpenNodeList(): array
	{
		$paths = [];
		foreach (self::getAllNodeList() as $appName => $app) {
			foreach (($app['Controls'] ?? []) as $controlName => $control) {
				foreach (($control['Actions'] ?? []) as $actionName => $action) {
					if (($action['Auth'] ?? false) !== true) {
						$paths[] = self::normalizeAuthPath($appName . '/' . $controlName . '/' . $actionName);
					}
				}
			}
		}
		return array_values(array_unique($paths));
	}

	/**
	 * 读取或生成权限推导缓存。
	 *
	 * @return array<int, string>
	 */
	public static function resolvePermissionCache(string $expression, string $cacheId, ?array $catalog = null, int $ttl = 7200): array
	{
		if (!self::useNodeCache()) {
			return self::derivePermissionExpression($expression, $catalog);
		}
		$cache = Cache::store();
		if ($cache) {
			$cached = $cache->get($cacheId);
			if (is_array($cached)) return $cached;
		}
		$derived = self::derivePermissionExpression($expression, $catalog);
		if ($cache) $cache->set($cacheId, $derived, $ttl);
		return $derived;
	}

	/**
	 * 生成权限推导缓存 ID。调用方可传入自定义组合标识，版本边界由 Node 强制附加。
	 */
	public static function buildPermissionCacheId(string $customId, string $supplementDigest = 'none', string $tenantId = '000000', string $roleVersion = '0', string $packageVersion = '0'): string
	{
		$nodeVersion = (string)(G('node_permission_version') ?: '1');
		return 'permission:' . rawurlencode($tenantId) . ':' . rawurlencode($customId)
			. ':node-' . $nodeVersion . ':role-' . $roleVersion . ':pkg-' . $packageVersion . ':plus-' . $supplementDigest;
	}
	
	/**
     * 获取所有菜单节点
     * 
     * 获取系统中所有的菜单节点列表，包括所有应用和插件
     * 合并应用菜单节点和插件菜单节点，提供统一的菜单节点访问接口
     * 结果会被缓存以提高性能
     * 
     * @return array 所有菜单节点数组，包含应用和插件的菜单节点信息
     */
    public static function getAllMenuList(bool $force = true): array
    {
		$lang = self::currentLang();
		$cacheKey = self::menuCacheKey($lang, (array)(G('appNode')['menu'] ?? []));
		$menus = null;
		if(self::useNodeCache() && isset(self::$allMenuListCache[$cacheKey])){
			$menus = self::$allMenuListCache[$cacheKey];
		}

		if ($menus === null) {
			// 缓存全量：应用 + Addon；force 只在返回前裁剪，避免跨用户污染
			$menus = array_merge(self::getAppMenuList(false), self::getAddonMenuList());
			if(self::useNodeCache()){
				self::$allMenuListCache[$cacheKey] = $menus;
			}
		}

		return self::applyForceToAppMenuTree($menus, $force);
	}

	/**
	 * 按语言与当前菜单扫描范围（appNode）计算菜单缓存键。
	 *
	 * getAppMenuList 按 G('appNode')['menu'] 过滤应用，若缓存键只含语言，
	 * 同一进程内先构建租户 A 再构建租户 B 会命中 A 的缓存，导致 B 的
	 * 模块菜单（如生产型 MES）丢失。缓存键必须纳入 appNode 签名。
	 *
	 * @param string $lang 语言
	 * @param array $appNode 当前菜单扫描范围
	 * @return string 缓存键
	 */
	private static function menuCacheKey(string $lang, array $appNode): string
	{
		if ($appNode === []) {
			return $lang . ':all';
		}

		return $lang . ':' . md5(implode(',', $appNode));
	}
	
	/**
     * 获取所有Addon菜单节点
     * 
     * 获取系统中所有启用的插件(Addon)的菜单节点列表
     * 只处理已经启用的插件，确保安全性
     * 结果会被缓存以提高性能
     * 
     * @return array 所有插件菜单节点数组，格式为[appName=>['name'=>插件名称,'controls'=>菜单列表]]
     */
    private static function getAddonMenuList(): array
    {
		$lang = self::currentLang();
		if(self::useNodeCache() && isset(self::$addonMenuListCache[$lang])){
			return self::$addonMenuListCache[$lang];
		}

		$menus = [];
		$addons = Addon::getEnableAddons(1);
		if(count($addons) > 0){
			foreach($addons as $appName=>$addon){
				$menulist = self::getMenuList($appName);
				if($menulist){
					$menus[$appName] = [
						'Name'=>$addon['name'],
						'Controls'=>$menulist,
					];
				}
			}
		}

		if(self::useNodeCache()){
			self::$addonMenuListCache[$lang] = $menus;
		}

		return $menus;
	}
	
    /**
     * 清除节点/菜单/注解扫描缓存（统一失效入口）
     *
     * 与 {@see self::useNodeCache()} 配对：源码变更会自动失效，发布或手工同步后仍可主动调用。
     * 清理范围：进程内静态缓存、G() 持久化键、共享缓存 `NodeMethods`、注解扫描缓存。
     *
     * 触发场景：
     * - 修改 @Auth / @Menu / 注解 title 后
     * - 菜单 CRUD 或节点同步后（业务层应调用本方法）
     * - 部署发布后：`php candy deploy:post` 或 `php candy clear -c`
     *
     * @return void
     */
    public static function clearNodeCache(): void
    {
        self::$methods = [];
        self::$menuListCache = [];
        self::$authListCache = [];
        self::$appMenuListCache = [];
        self::$allNodeListCache = [];
        self::$allAuthListCache = [];
        self::$appAuthListCache = [];
        self::$addonAuthListCache = [];
        self::$allMenuListCache = [];
        self::$addonMenuListCache = [];
        self::$takeAuthListCache = [];

        G('nodeAuthList', null);
        G('nodeMenuList', null);
        G('nodeMethodList', null);
        G('checkAuthList', null);
        G('checkMenuList', null);
        $langConfigFile = C('CANDYROOT') . 'Source/Inc/Lang.inc.php';
        if(is_file($langConfigFile)){
            $langConfig = include $langConfigFile;
            if(!empty($langConfig['locales']) && is_array($langConfig['locales'])){
                foreach(array_keys($langConfig['locales']) as $locale){
                    G('nodeMenuList_' . $locale, null);
                    G('checkMenuList_' . $locale, null);
                }
            }
        }
        resetSharedGlobalCache();
        if(!\Candy\Core\Cache::isFileDriver() && $memCache = \Candy\Core\Cache::store()){
            $memCache->delete(\Candy\Core\Cache::sharedKey('NodeMethods'));
        }
        if(class_exists(\Candy\Extend\Attribute\utils\Tool::class, false)){
            \Candy\Extend\Attribute\utils\Tool::resetScanCache();
        }
    }

	/**
     * 应用用户权限
     * 
     * 根据用户权限过滤系统节点，确保用户只能访问有权限的节点
     * 支持重置缓存，适用于用户权限变更后刷新权限
     * 超级管理员直接返回所有权限节点
     * 
	 * @param array $auths 用户权限节点数组，默认为空(将按当前身份读取推导缓存)
     * @param bool $force 是否强制重置所有缓存，默认为false
     * @return array 用户实际拥有的有效权限节点数组
     */
    public static function applyUserAuth(array $auths = [],bool $force = false): array
    {
		//清除缓存
        if ($force) {
            self::clearNodeCache();
        }
		
		//获取个人权限
		empty($auths) && $auths = self::_getUserAuths();
		
		//获取所有验证节点
		$authlist = self::takeAuthList();
		
		//超级管理员直接返回所有节点
		if(session('userRole') === 'Admin') return $authlist ?: [];
		
		foreach($auths as $key=>$auth){
			if(in_array($auth, $authlist)) continue;
			unset($auths[$key]);
		}
		
		return $auths ?: [];
    }
}
