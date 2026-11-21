<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 生成数据库/模型容器键（隔离别名 + 可选强制角色）
 *
 * @param string|array|null $config 隔离别名或配置数组
 * @param string|null $role 强制角色 write|read
 * @return string
 */
function buildDbContainerKey(mixed $config, ?string $role = null): string
{
	$inlineRole = null;
	if (is_string($config) && $config !== '') {
		[$alias, $inlineRole] = \Candy\Extend\DB\DBServer::parseAliasSpec($config);
		$configKey = $alias !== '' ? $alias : $config;
	} elseif (is_array($config) && $config !== []) {
		$configKey = 'crc32_' . crc32(json_encode($config, JSON_UNESCAPED_UNICODE));
	} else {
		$configKey = 'default';
	}

	$effectiveRole = $role ?? $inlineRole;
	if ($effectiveRole !== null && $effectiveRole !== '' && strtolower($effectiveRole) !== 'auto') {
		$configKey .= '@' . \Candy\Extend\DB\DBServer::normalizeRoleName((string) $effectiveRole);
	}

	return $configKey;
}

/**
 * 按当前请求租户 ID 解析隔离库配置
 *
 * 供 M()/D() 第二参数为 true 时显式切到确定的隔离库。
 * 索库顺序：
 * 1. 可选钩子 getTenantDbConfig($tenantId) → 别名字符串或完整连接数组
 * 2. 运行时 DBLIST[tenantId] / DBLIST[t_{id}]
 * 3. 共享缓存 @tenant:db:{id}
 * 4. 缓存未命中 → 可选钩子 loadTenantDbConfig($id) 从总库拉取并 registerTenantDbConfig 补录
 *
 * JWT 只携带 tenantId；库连接优先缓存，未命中再回源总库补缓存。
 *
 * @return string|array 隔离别名或连接配置数组
 */
function resolveTenantDbLink(): string|array
{
	$tenantId = \Candy\Core\Bootstrap::tenantId();
	if ($tenantId === null || $tenantId === '') {
		$msg = 'M()/D() 第二参数为 true 时需要有效租户 ID（请实现 getTenantID 并保证当前请求已解析）';
		stop($msg);
		throw new \RuntimeException($msg);
	}

	// 1. 业务钩子（可完全接管；返回 null 则继续框架默认链路）
	if (function_exists('getTenantDbConfig')) {
		$cfg = getTenantDbConfig($tenantId);
		if (is_string($cfg) && $cfg !== '') {
			return $cfg;
		}
		if (is_array($cfg) && $cfg !== []) {
			// 钩子直接给了连接数组：注入 DBLIST，统一按别名索库
			hydrateTenantDbList($tenantId, $cfg);
			return $tenantId;
		}
	}

	// 2–4. DBLIST → 共享缓存 → 总库补录（loadTenantDbConfig）
	$resolved = resolveTenantDbConfig($tenantId);
	if ($resolved !== null && $resolved !== '') {
		return $resolved;
	}

	$msg = '未找到租户隔离库配置（TENANT_ID=' . $tenantId
		. '，请实现 loadTenantDbConfig 从总库补录，或登录时 registerTenantDbConfig / getTenantDbConfig）';
	stop($msg);
	throw new \RuntimeException($msg);
}

/**
 * 展开 M()/D() 第二参数
 *
 * true → 按当前 TENANT_ID 自动索库；其它值原样返回（别名 / 配置数组 / 默认）
 *
 * @param mixed $config 第二参数
 * @return mixed
 */
function expandDbLinkConfig(mixed $config): mixed
{
	return $config === true ? resolveTenantDbLink() : $config;
}

/**
 * 获取对象实例
 *
 * 该函数是框架的对象容器访问入口，用于获取或创建类的实例。支持依赖注入、命名空间补充
 * 以及对象单例管理，是框架控制反转(IoC)容器的核心访问接口。
 *
 * 使用示例：
 * - 基本使用: N('User') - 获取User类的实例
 * - 带参数: N('User', ['id' => 1]) - 创建带初始化参数的User实例
 * - 强制新建: N('User', [], true) - 强制创建新的User实例，不使用单例
 * - 特殊类名: N('App') - 返回基础App类名(\Candy\Core\App)
 *
 * @param string $className 类名，支持省略命名空间的短类名
 * @param array $vars 类构造函数参数数组
 * @param bool $newInstance 是否强制创建新实例，默认为false(使用单例)
 * @param array $invokeFunction 反射函数组，用于特殊的实例化操作
 * @return object|string 返回类实例对象或静态类名
 */
function N(string $className = '',array $vars = [],bool $newInstance = false,array $invokeFunction = []): mixed
{
	//App 基类开始类
	if($className === 'App') return '\\Candy\\Core\\App';
	
	if(empty($className)){
		\Candy\Core\Debug::showMessage('N()方法，必须指类名！', 'PHP');
	}
	
	//返回类对象或静态类名
	return \Candy\Core\Container::getInstance()->make(replenishNamespace($className), $vars, $newInstance, $invokeFunction);
}

/**
 * 创建数据库操作对象
 *
 * 该函数是框架的核心数据库操作入口，用于获取或创建数据库连接对象。支持跨项目调用
 * 数据库操作，可根据指定的表名和配置信息初始化数据库驱动实例。
 *
 * 使用示例：
 * - 基本使用: D('User') - 获取User表的数据库操作对象
 * - 跨项目调用: D('Admin://User') - 获取Admin项目中User表的数据库操作对象
 * - 使用配置别名（库隔离）: D('User', 'tenant_a') - 使用名为 tenant_a 的隔离库配置
 * - 按当前租户 ID 自动索库: D('User', true) / D('User', true, 'read')
 * - 自定义配置: D('User', ['HOST' => 'localhost', 'USER' => 'root', 'PASS' => '123', 'DBNAME' => 'test']) - 使用自定义配置
 * - 仅获取数据库驱动: D('_dbDriver') - 仅获取数据库驱动实例，不指定表名
 *
 * 第二参数：库隔离别名、完整连接数组，或 true（按 TENANT_ID 自动索库）。
 * 第三参数可选 write|read 强制角色。紧凑写法：D('User', 'tenant_a@read')。
 *
 * @param string $tableName 表名，支持跨项目格式'项目://表名'，特殊值'_dbDriver'仅返回驱动实例
 * @param string|array|bool|null $config 库隔离别名、连接配置数组，或 true（租户自动索库）
 * @param string|null $role 连接角色 auto|write|read（可选）
 * @param string|null $driver 驱动别名（可选，覆盖全局 DRIVER，如 clickhouse）
 * @return object 数据库连接对象实例
 */
function D(string $tableName='_dbDriver',mixed $config = [],?string $role = null,?string $driver = null):object
{
	// true：按当前请求 TENANT_ID 解析隔离库（确定的分库操作）
	$config = expandDbLinkConfig($config);

	$app='';
	if(strstr($tableName, '://')){
		//跨项目调用
		[$app, $tableName] = explode('://',$tableName);
		$path = C('CANDYROOT') . 'Application/' . strtolower($app).'/';
	}else{
		$app = C('APPNAME');
		$path = C('APP_PATH');
	}

	// 生成唯一的数据库对象名称，包含配置信息与可选角色
	$configKey = buildDbContainerKey($config, $role);
	if ($driver !== null && $driver !== '') {
		$configKey .= '@drv_' . \Candy\Extend\DB\DBServer::resolveDriverClass((string) $driver);
	}
	
	//db名 - 包含表名、应用名和配置键，确保不同配置生成不同实例
	$dbName = $tableName . '_' . $app . '_' . $configKey;

	//根据命名空间判断路径
	$driver = $driver ?? G('DRIVER');
	$driverName = '';
	if(stripos($driver, '\\') === false){
		// 统一驱动名称（含 pdo→PdoMysql 等别名）
		$driver = \Candy\Extend\DB\DBServer::resolveDriverClass((string) $driver);
		//没有命名空间
		if (is_file(CANDYPATH . 'Candy/Class/DB/Driver/' . $driver . '.class.php')){
			//内置
			$driverName = '\\Candy\\Extend\\DB\\Driver\\'. $driver;
			$driverPath = CANDYPATH . 'Candy/Class/DB/Driver/' . $driver . '.class.php';
		} elseif (is_file(C('SOURCEPATH') . 'Class/' . $driver . '.class.php')) {
			//项目通用驱动
			$driverName = '\\APP\\Extend\\'. $driver;
			$driverPath = C('SOURCEPATH') . 'Class/' . $driver . '.class.php';
		} elseif (is_file(C('SOURCEPATH') . 'Vendor/' . $driver . '.php')){
			$driverName = $driver;
			$driverPath = C('SOURCEPATH') . 'Vendor/' . $driver . '.php';
		} elseif (isset($config['DB']['path']) && is_file($config['DB']['path'])){
			$driverName = $config['DB']['name'];
			$driverPath = $config['DB']['path'];
		}
	} else {
		//有命名空间
		$driverRightPath = trim(strtr($driver, '\\', '/'), '/');
		if (stripos($driver, 'APP\\Extend\\') !== false && is_file(C('SOURCEPATH') . 'Class/' . $driverRightPath . '.class.php')){
			//项目通用驱动
			$driverName = $driver;
			$driverPath = C('SOURCEPATH') . 'Class/' . $driverRightPath . '.class.php';
		}elseif (is_file(C('SOURCEPATH') . 'Vendor/' . $driverRightPath . '.php')){
			//第三方驱动
			$driverName = $driver;
			$driverPath = C('SOURCEPATH') . 'Vendor/' . $driverRightPath . '.php';
		}
	}

	//检查驱动是否存在
	if(empty($driverName)){
		showExceptionMsg('shutdown', '数据库驱动'. $driver .'不存在');
	} else {
		if(class_exists($driverName) === false && is_file($driverPath)){
			require_once $driverPath;
		}
	}

	//db对象 - 从容器中获取或创建
	$db = \Candy\Core\Container::getInstance()->get($dbName);

	$newObj = false;
	if (is_null($db)) {
		$newObj = true;
		$db = \Candy\Core\Container::getInstance()->make($driverName);
		if (is_object($db)) {
			\Candy\Core\Container::getInstance()->instance($dbName, $db);
		}
	}

	//选择数据库 - 传入配置参数与角色
	$db->setLink($config, $role);

	//表结构
	if($newObj && $tableName != '_dbDriver'){
		$db->setTable(N('Str')::snake($tableName));
	}

	return $db;
}

/**
 * 创建数据模型操作对象
 *
 * 该函数用于实例化指定的数据模型对象，支持默认模型和跨项目模型的调用，
 * 并可以设置数据库连接配置和表名。在Web服务器环境下会自动克隆对象，确保数据安全。
 * 支持多数据库（库隔离）与读写分库：第二参数为隔离别名，第三参数为 write|read。
 *
 * 使用示例：
 * ```php
 * $userModel = M('User');
 * $userModel = M('User', 'tenant_a');
 * $userModel = M('User', true);              // 按当前 TENANT_ID 自动索库
 * $userModel = M('User', true, 'read');
 * $userModel = M('User', 'tenant_a', 'read');
 * $userModel = M('User', 'tenant_a@write');
 * ```
 *
 * @param string $className 模型类名，支持跨项目调用（格式：项目名://模型名）
 * @param string|array|bool|null $config 库隔离别名、连接配置数组，或 true（租户自动索库）
 * @param string|null $role 连接角色 auto|write|read（可选）
 * @return object 返回实例化的模型对象
 *
 * @note
 * - config 为隔离别名；配置了 write/read 的别名将自动按 SQL 类型路由
 * - config 为 true 时按 Bootstrap::tenantId() 索库（须已解析 TENANT_ID 且库配置已注册）
 * - 无数据库表的业务逻辑请使用 S()，M() 仅用于有对应数据表的 Model
 */
function M(string $className = '', mixed $config = [], ?string $role = null):object
{
	if(empty($className))
		\Candy\Core\Debug::showMessage('M()方法必须传参！', 'Model');

	// true：按当前请求 TENANT_ID 解析隔离库（确定的分库操作）
	$config = expandDbLinkConfig($config);

	$app = '';
	if(strstr($className, '://')){
		//跨项目调用
		[$app, $className] = explode('://',$className);
		$path = C('CANDYROOT') . 'Application/' . strtolower($app).'/';
	}else{
		$app = C('APPNAME');
		$path = C('APP_PATH');
	}

	// 补全 Model 类名（固定 Model 后缀，无表逻辑请使用 S()）；租户有覆写时带 Tenant\{id}
	$runtimeClass = ucfirst($className) . 'Model';
	$modelClass = '\\' . \Candy\Core\Trait\App\RuntimeClassLoader::resolveFqcn('model', $app, $runtimeClass);
	
	$configKey = buildDbContainerKey($config, $role);
	$tenantSuffix = \Candy\Core\Trait\App\RuntimeClassLoader::tenantKeySuffix();
	
	$modelName = 'model_' . $className . '_' . $app . '_' . $configKey . $tenantSuffix;

	//db对象 - 从容器中获取或创建
	$model = \Candy\Core\Container::getInstance()->get($modelName);

	if (is_null($model)) {
		// Model 实例按「应用 + 连接 + 读写角色」隔离；不能再复用同一类的单例，
		// 否则后续 setLink() 会把先前模型的连接和表状态一起改写。
		$model = \Candy\Core\Container::getInstance()->make($modelClass, [], true);
		if (is_object($model)) {
			\Candy\Core\Container::getInstance()->instance($modelName, $model);
		}
	}
	
	// 选择数据库 - 传入配置参数与角色
	$model->setLink($config, $role);

	// 表结构
	$model->setTable(\Candy\Extend\Str::snake($className));
	$model->path = $path;
	
	return $model;
}

/**
 * 创建服务操作对象
 *
 * 该函数用于实例化指定的服务对象，支持默认服务和跨项目服务的调用，
 * 并可以设置数据库连接配置和表名。在Web服务器环境下会自动克隆对象，确保数据安全。
 * 支持多数据库切换，通过config参数可以指定不同的数据库连接。
 *
 * 使用示例：
 * ```php
 * // 创建默认应用的服务对象
 * $userService = S('User');
 *
 * // 跨项目调用服务
 * $adminUserService = S('Admin://User');
 * ```
 *
 * @param string $className 服务类名，支持跨项目调用（格式：项目名://服务名）
 * @return object 返回实例化的服务对象
 *
 * @note
 * - 当className为空时，会抛出错误提示
 * - 跨项目调用时，className格式为：项目名://服务名
 */
function S(string $className = ''):object
{
	if(empty($className))
		\Candy\Core\Debug::showMessage('S()方法必须传参！', 'Service');

	$app = '';
	if(strstr($className, '://')){
		//跨项目调用
		[$app, $className] = explode('://',$className);
	}else{
		$app = C('APPNAME');
	}

	//补全类名（租户有覆写时带 Tenant\{id}）
	$runtimeClass = ucfirst($className) . 'Service';
	$serviceClass = '\\' . \Candy\Core\Trait\App\RuntimeClassLoader::resolveFqcn('service', $app, $runtimeClass);
	$tenantSuffix = \Candy\Core\Trait\App\RuntimeClassLoader::tenantKeySuffix();
		
	$serviceName = 'service_' . $className . '_' . $app . $tenantSuffix;

	// db对象 - 从容器中获取或创建
	// Service 注册为 scoped：常驻模式下每请求结束被清掉，
	// 避免 $userMapCache 之类的请求级状态带到下一个请求（Workerman/Swoole）
	$container = \Candy\Core\Container::getInstance();

	if (!$container->isScoped($serviceName)) {
		$container->scoped($serviceName, $serviceClass);
	}

	$service = $container->get($serviceName);

	if (is_null($service)) {
		// 不用强制新建：flushScopedInstances() 已经清了 $instances[$concreteClass]，
		// make() 本来就会重建；强制新建反而会让「类不存在」这类原有容错提前炸掉
		$service = $container->make($serviceClass);
		if (is_object($service)) {
			$container->instance($serviceName, $service);
		}
	}
		
	return $service;
}

/**
 * 异步调用控制器类
 *
 * 该函数用于异步实例化并调用控制器类，支持跨应用调用、插件调用，
 * 可在不同应用间或插件间进行无缝切换和调用。
 *
 * 使用示例：
 * - 同应用调用: A('index/index') - 调用当前应用的index控制器的index方法
 * - 跨应用调用: A('admin://user/login') - 调用admin应用的user控制器的login方法
 * - 插件调用: A('Addon://plugin/action') - 调用插件的action方法
 *
 * @param string|null $className 类名或调用路径，格式可为：控制器/方法 或 应用://控制器/方法
 * @return void
 * @throws Exception 当类不存在或参数无效时可能抛出异常
 */
function A(?string $className = null):void
{
	if(is_null($className)){
		\Candy\Core\Debug::showMessage('<font color=\'red\'>必须为A()函数提供参数!', 'Shutdown');
	}else{
		$app = null;
		$actionName = null;
		$newApp = false;
		if(strpos($className, '://')){
			[$app, $className] = explode('://', $className);
			//不是同一个APP
			$newApp = true;
		}
		
		//检查appname
		is_null($app) && $app = C('APPNAME');
		
		//解析控制器
		if(strpos($className, '/')){
			[$className, $actionName] = explode('/', $className, 2);
		}
		
		//没有传递控制器名称
		if(is_null($actionName)){
			$actionName = $className;
			$className = C('CONTROL');
		}
		
		//对象名称（保留多驼峰：DemoItem → DemoItemAction）
		$className = \Candy\Extend\Str::controlName((string) $className);
		$classObjName = 'APP\\Control\\'. $app . '\\' . $className .'Action';
		
		//重置项目路径
		$appPath = C('CANDYROOT') . 'Application/'. $app .'/';
		C('APP_PATH', $appPath);
		
		//跨项目调用
		if($newApp){
			if($app == 'Addon'){
				$app = $className;
				//插件项目
				$isAddon = \Candy\Core\Addon::checkAddon($app);
				if($isAddon){
					C('ISADDON', true);
					
					//重新定义项目
					C('APPNAME', $app);
					
					//重置项目路径
					$appPath = C('CANDYROOT') .'Addons/'. $app .'/';
					C('APP_PATH', $appPath);
					
					//加载项目公用父类 common
					$commonpath = C('RUNTIME') .'Controls/Addons/'. $app . '/' . strtolower($app).'common.class.php';
					$inres = requireCache($commonpath);
					if($inres){
						\Candy\Core\Debug::addMsg('<b> '. ucfirst($app) .'Common </b>类', 1);
					}
					
					//再次分化出控制器和操作
					[$className, $actionName] = explode('/', $actionName, 2);
					$className = \Candy\Extend\Str::controlName((string) $className);
					
					//加载控制器类
					$classpath = C('RUNTIME') .'Controls/Addons/'. $app . '/' . strtolower($className).'action.class.php';
					
					//重新定义对象名称
					$classObjName = 'APP\\Addon\\' . ucfirst(strtolower($app)) .'\\' . $className .'Action';
					
					$inres = requireCache($classpath);
					if($inres){
						\Candy\Core\Debug::addMsg('<b> '. ucfirst($className) .' </b>类', 1);
					}
				}else{
					\Candy\Core\Debug::showMessage($className .'插件不存在！', 'Shutdown');
				}
			}else{
				//加载项目公用父类 common
				$commonpath = C('RUNTIME').'Controls/'. $app .'/'. strtolower($app).'common.class.php';
				$inres = requireCache($commonpath);
				if($inres){
					\Candy\Core\Debug::addMsg('<b> '. ucfirst($app) .'Common </b>类', 1);
				}
				
				//加载控制器类
				$classpath = C('RUNTIME').'Controls/'. $app .'/'. strtolower($className).'action.class.php';
				$inres = requireCache($classpath);
				if($inres){
					\Candy\Core\Debug::addMsg('<b> '. ucfirst($className) .' </b>类', 1);
				}
			}
		}
		
		//重置语言库
		\Candy\Core\Debug::addMsg('[<b>Languages file</b>] 语言库重置为 ：'. $app .'项目', 3);
		$LANG = [];
		$clangfile = C('APP_PATH') .'Languages/'. G('LANG') .'/Common.lang.php'; //项目公共语言库
		if(fileExistsCase($clangfile))
			$LANG += include $clangfile; // 语言文件 return 数组，须 += 合并（否则 LANGINFO 被清空）

		//操作语言库
		$routeControl = I('get.m', '', '');
		$langfile = C('APP_PATH') .'Languages/'. G('LANG') .'/'. \Candy\Extend\Str::controlName((string) $routeControl).'.lang.php';
		if(fileExistsCase($langfile))
			$LANG += include $langfile;

		$langPath = str_replace(C('CANDYROOT'), '/', $langfile);
		\Candy\Core\Debug::addMsg('[<b>Languages file</b>] 当前项目语言库路径：'. $langPath, 3);
		//储存语言
		C('LANGINFO', $LANG);
		
		// 创建对象
		$classObj = N($classObjName);
		
		// 重置操作：须同步 C('CONTROL'/'ACTION')。View 构造读取二者，
		// 仅改 $_GET 或动态属性时 A('Article/read') 仍会按原 Cms/resolve 找模板。
		$classObj->control = $className;
		$classObj->action = $actionName;
		C('CONTROL', $className);
		C('ACTION', $actionName);
		if (isSwooleCoroutine()) {
			R()->mergeGet(['m' => $className, 'a' => $actionName]);
		} else {
			$_GET['m'] = $className;
			$_GET['a'] = $actionName;
		}
		
		// 重置模版类库
		initTplPath($app);
		
		// 运行
		$classObj->working();
	}
}

/**
 * 向量旁路门面（框架级，无需各项目再写 Service）
 *
 * 类比 D() 取得库连接：V() 取得 VectorServer，内部经 VectorRouter 选 pgvector / PHP。
 *
 * 示例：
 * ```php
 * V();                       // 默认库 Data/vector/default/（有租户则 Data/vector/t/{id}/default/）
 * V('faq')->upsert(...);     // 独立库 Data/vector[/t/{id}]/faq/
 * V('products')->search($q); // Data/vector[/t/{id}]/products/
 * V()->usePhp()->textSearch('关键词', 5);
 * V()->usePhp()->hybridSearch($q, '关键词', 10);
 * V('faq', ['force' => 'php', 'hnsw_heuristic' => true])->search($q, 10);
 * V('faq', ['shared' => true]); // 强制平台默认空间，不注入租户段
 * ```
 *
 * @param string|null $alias 向量库名（不传/空 → default）；PHP 落盘 Data/vector[/t/{id}]/{alias}/，PG 为连接别名
 * @param array $options force / table / dimensions / prefer_pgvector / hnsw_heuristic 等
 * @return \Candy\Extend\DB\VectorServer
 */
function V(?string $alias = null, array $options = []): \Candy\Extend\DB\VectorServer
{
	return \Candy\Extend\DB\VectorServer::link($alias, $options);
}
