<?php
/**
 * Candy框架自动加载类
 * 
 * Autoload类负责框架的自动加载机制，采用final修饰禁止继承
 * 实现了PHP的spl_autoload_register接口，支持多种命名空间的类文件自动加载
 * 包含缓存机制以提升加载性能，兼容Composer自动加载
 * 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-25 10:00:00
 * @package Candy\Core
 * @final 禁止继承此类
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Autoload {
	
	/**
	 * 初始化自动加载配置
	 * 
	 * 注册框架的自动加载函数到spl_autoload_register
	 * 同时检查并集成Composer自动加载机制
	 * 该方法应在应用程序启动时调用
	 * 
	 * @return void
	 * @static
	 * @access public
	 */
	public static function loading(): void
	{
		//注册自动加载类
		spl_autoload_register(['Candy\Core\Autoload', 'importClass']);
		
		//兼容Composer自动加载
		if(is_file(CANDYPATH .'Vendor/autoload.php')){
			require(CANDYPATH .'Vendor/autoload.php');
		}
	}
	
	/**
	 * 判断是否应在调试面板「Framework → 自动加载」中展示
	 *
	 * 规则（返回 null 表示仅加载、不展示）：
	 * - 全局静默：Annotation / Workerman 等第三方大包（任意路径）
	 * - **仅 Candy/Core/** 做分级过滤；项目/Extend/Vendor 等扩展类库仍按原 stype 展示
	 * - Candy/Core/Cache：只展示基类 + 驱动
	 * - Candy/Core 其余：只展示一级核心类（App/Node/…）
	 *
	 * @param string $className 规范化类路径（/ 分隔）
	 * @param int $defaultType Debug::addMsg 默认类型
	 * @return int|null 展示类型；null 为静默加载
	 */
	private static function resolveAutoloadDebugType(string $className, int $defaultType): ?int
	{
		static $discardFragments = [
			'Annotation', 'Apidoc', 'Catlog', 'Doctrine', 'Firebase', 'QL', 'Workerman',
		];
		foreach ($discardFragments as $frag) {
			if (stripos($className, $frag) !== false) {
				return null;
			}
		}

		// 非 Candy/Core：不过滤（项目内置 / 框架 Extend / Vendor 等仍展示在对应 Tab）
		if (!str_starts_with($className, 'Candy/Core')) {
			return $defaultType;
		}

		if (preg_match('#/(Trait|Pack)(/|$)#', $className)) {
			return null;
		}

		if (str_contains($className, '/Impl/Modifier/')) {
			return null;
		}

		// Cache 抽象类 + Impl 驱动（Redis/File/Memory…）；Trait/Pack 等一律静默
		if ($className === 'Candy/Core/Cache' || preg_match('#^Candy/Core/Impl/Cache/[^/]+$#', $className)) {
			return $defaultType;
		}
		if (str_starts_with($className, 'Candy/Core/Cache') || str_starts_with($className, 'Candy/Core/Impl/')) {
			return null;
		}

		if (str_starts_with($className, 'Candy/Core/')) {
			$rest = substr($className, strlen('Candy/Core/'));
			if ($rest !== '' && str_contains($rest, '/')) {
				return null;
			}
		}

		$extraSilent = C('autoloadDebugSilent');
		if (is_array($extraSilent)) {
			foreach ($extraSilent as $pattern) {
				if (!is_string($pattern) || $pattern === '') {
					continue;
				}
				if (@preg_match($pattern, $className) === 1 || str_contains($className, $pattern)) {
					return null;
				}
			}
		}

		return $defaultType;
	}

	/**
	 * 自动导入类文件
	 * 
	 * 处理类的自动加载，支持多种命名空间格式
	 * 搜索顺序包括项目内置扩展、框架内置扩展、外置扩展等
	 * 实现了缓存机制以减少IO操作，提升性能
	 * 
	 * @param string $className 要加载的类名（包含命名空间）
	 * @return void
	 * @static
	 * @access public
	 */
	public static function importClass(string $className): void
	{
		static $_autoLoadFiles = [];
		
		//命名空间引导
		$className = trim(strtr($className, '\\', '/'), '/');
		
		//加载过停止往下执行
		if (isset($_autoLoadFiles[$className])) return ;
		
		if(in_array($className, ['memcached', 'Redis']) === false){
			$sclassName = $className;
			$list = [];
			$stype = 1;
			//单名对象 外置库  N()方法带来的
			if(stripos($className, '/') === false){
				$list = [
					C('APP_PATH') .'Vendor/' . $className . '.class.php',			//项目内置扩展类 标准名
					C('APP_PATH') .'Vendor/' . $className . '.php',					//项目内置扩展类
					CANDYPATH . 'Candy/Vendor/' . $className . '.class.php',			  //框架内置扩展类
					CANDYPATH . 'Candy/Class/Vendor/' . $className . '.class.php',			//框架内全局类
					C('CANDYROOT') . 'Source/Vendor/' . $className . '.class.php',	//框架外置扩展类  标准名
					C('CANDYROOT') . 'Source/Vendor/' . $className . '.php',		//框架外置扩展类
				];
				
				//特殊路径类库（用 C 勿用 G，避免启动期初始化 Cache）
				$specialClass = C('specialClass');
				if ($specialClass === '' || $specialClass === null) {
					$specialClass = readInc('Specialclass');
					C('specialClass', $specialClass);
				}
				isset($specialClass[$className]) && $list[] = C('CANDYROOT') . 'Source/Vendor/' . $specialClass[$className]['path'] .'/'.$specialClass[$className]['filename'].'.php';
			}else{
				//分离ClassName
				$actions = '';
				if(substr_count($className, '/') > 1){
					[$space, $type, $actions] = explode('/', $className, 3);
				}else{
					[$space, $type] = explode('/', $className, 2);
				}
				//处理加载路径
				switch($space){
					case 'Candy':
						$includePath = match($type){
							'Core' => CANDYPATH . $className .'.class.php',
							'Extend' => CANDYPATH . 'Candy/Class/'. str_replace('Candy/Extend/', '', $className) .'.class.php',
							'APP' => C('SOURCEPATH') . 'Common/Base.class.php',
							default => ''
						};

						//没有报错
						if(empty($includePath)){
							showExceptionMsg('shutdown', '<font color=\'red\'>核心类库'. $className .'不存在!</font>');
						}

						$list[] = $includePath;
						$tmparr = explode('/', $className);
						$sclassName = ucfirst(array_pop($tmparr));
						$stype = $type == 'APP' ? 1 : 5;
						break;
					case 'APP':
						// 解析出 App 和控制（支持租户 NS：Admin/Tenant/{id}/IndexAction）
						// Middleware/Event/Extend/Command 的 actions 仅为类路径（无应用段），不可当 control 必填
						$parts = explode('/', (string) $actions, 2);
						$app = $parts[0] ?? '';
						$control = $parts[1] ?? '';
						if ($control !== '' && preg_match('#^Tenant/[A-Za-z0-9]{6}/(.+)$#', $control, $tenantSeg)) {
							$control = $tenantSeg[1];
						}

						$needsAppControl = in_array($type, ['Control', 'Addon', 'Model', 'Service', 'Validate', 'Schema', 'Rpc'], true);
						if ($needsAppControl && ($app === '' || $control === '')) {
							showExceptionMsg('shutdown', '<font color=\'red\'>项目类库'. $className .'不存在!</font>');
							return;
						}
						
						// 过滤 Annotation 注释类库
						if($control === 'Annotation'){
							return ;
						} else {
							// Jobs 子目录：计划任务处理器，直接 require，禁止走 Service 编译（会错误追加 Service 后缀）
							if ($type === 'Service' && str_starts_with(str_replace('\\', '/', $control), 'Jobs/')) {
								$jobRel = str_replace('\\', '/', $control) . '.class.php';
								$appRoot = \Candy\Core\Bootstrap::resolveAppRoot($app);
								$jobFile = $appRoot . 'Services/' . $jobRel;
								$tenantJob = \Candy\Core\Bootstrap::tenantOverlayFile($appRoot, 'Services', $jobRel);
								if ($tenantJob !== null) {
									$jobFile = $tenantJob;
								}
								if (is_file($jobFile)) {
									$list[] = $jobFile;
									break;
								}
							}
							$isAddonClass = $type === 'Addon';
							$result = match($type){
								// CommonAction 须走 action 编译（类名带 Action）；Common/Base 走 common
								'Control', 'Addon' => in_array($control, ['Base', 'Common', 'CommonExtend'], true)
									? App::common($control, $app, $isAddonClass)
									: App::controler($control, $app, $isAddonClass),
								'Model' => App::model($control, $app),
								'Service' => App::service($control, $app),
								'Validate' => App::validate($control, $app),
								'Schema' => App::schema($control, $app),
								'Rpc' => CANDYPATH . 'Application/' . $app . '/Rpc/' . $control . '.class.php',
								'Command', 'Extend' => $type == 'Command' ? C('CANDYROOT').'Application/Command/'. $actions .'.class.php' : C('SOURCEPATH').'Class/'. str_replace('APP/Extend/', '', $className) .'.class.php',
								'Event' => C('SOURCEPATH').'Event/'. str_replace('APP/Event/', '', $className) .'.class.php',
								'Middleware' => C('SOURCEPATH').'Middleware/'. str_replace('APP/Middleware/', '', $className) .'.class.php',
								default => '',
							};

							if(is_string($result)){
								if(empty($result)){
									showExceptionMsg('shutdown', '<font color=\'red\'>项目类库'. $className .'不存在!</font>');
								} else {
									$list[] = $result;
								}
							}
						}
						break;
					default:
						//带命名空间的外置类库
						$list = [
							C('APP_PATH') .'Vendor/' . $className . '.class.php',			//项目内置扩展类
							C('CANDYROOT') . 'Source/Vendor/' . $className . '.class.php',	//框架外置扩展类 标准名
							C('CANDYROOT') . 'Source/Vendor/' . $className . '.php',		//框架外置扩展类
							CANDYPATH. 'Candy/Vendor/' . $className . '.class.php',			//框架内置扩展类 标准名
							CANDYPATH. 'Candy/Vendor/' . $className . '.php',				//框架内置扩展类
							// Workerman 类文件根目录已经位于 Vendor/Workerman，类名只需去掉
							// 顶层命名空间；旧写法会把 Coroutine/ 重复拼接一层，导致
							// Workerman\Coroutine\Context 在 Fiber 模式下无法加载。
							CANDYPATH. 'Candy/Vendor/Workerman/' . (str_starts_with($className, 'Workerman/Coroutine/') ? substr($className, strlen('Workerman/')) : $className) . '.php',		// Workerman 框架内置扩展类
						];
						
						//特殊路径类库（用 C 勿用 G，避免启动期初始化 Cache）
						$specialClass = C('specialClass');
						if ($specialClass === '' || $specialClass === null) {
							$specialClass = readInc('Specialclass');
							C('specialClass', $specialClass);
						}
						if(!empty($specialClass)){
							foreach($specialClass as $sclassName=>$sclassPath){
								if(strpos($className, $sclassName) !== false){
									$list[] = C('CANDYROOT'). 'Source/Vendor/'. str_replace($sclassName, $sclassPath, $className) .'.php';
									break;
								}
							}
						}
				}
			}
			
			//加载类库
			foreach($list as $classpath){
				if(requireCache($classpath)){
					$_autoLoadFiles[$className] = true;
					if(str_contains($sclassName, '/')){
						$tmparr = explode('/', $sclassName);
						$sclassName = ucfirst(array_pop($tmparr));
					}
					$debugType = self::resolveAutoloadDebugType($className, $stype);
					if ($debugType !== null) {
						Debug::addMsg('<b> '. $sclassName .' </b>类', $debugType);
					}
					break;
				}
			}
		}
	}
}
