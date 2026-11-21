<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 判断当前环境是否为Web服务器环境
 *
 * 该函数用于检测当前应用是否运行在Web服务器环境下。通过检查CLIWORKING常量是否定义
 * 以及是否存在GATEWAY_CLIENT_ID服务器变量来确定。
 *
 * 使用示例：
 * ```php
 * if (isWebServer()) {
 *     // Web服务器环境下的处理逻辑
 *     $requestMethod = $_SERVER['REQUEST_METHOD'];
 * } else {
 *     // 命令行或其他环境下的处理逻辑
 *     $args = $argv;
 * }
 * ```
 *
 * @return boolean 如果是Web服务器环境返回true，否则返回false
 */
function isWebServer()
{
	if (isSwooleCoroutine()) {
		return defined('CLIWORKING');
	}
	return defined('CLIWORKING') && isset($_SERVER['GATEWAY_CLIENT_ID']) === false;
}

/**
 * 判断当前代码是否运行在 Swoole 协程内。
 *
 * 扩展加载状态在进程内只检测一次；协程 ID 必须按当前调用读取，不能缓存为全局
 * true/false，否则长驻进程会把一个请求协程的状态误用于其他执行上下文。
 */
function isSwooleCoroutine(): bool
{
	return isRequestCoroutine();
}

/** 当前是否运行在 Candy 请求协程中（Swoole、Workerman Fiber 或 Swow）。 */
function isRequestCoroutine(): bool
{
	// PHP-FPM / Apache / CGI 下绝不是请求协程；禁止探测/自动加载 Workerman，
	// 否则一旦误触发自动加载，isCoroutine() 可能在错误驱动路径上反复分配直至 OOM。
	$sapi = PHP_SAPI;
	if ($sapi === 'fpm-fcgi' || $sapi === 'cgi-fcgi' || $sapi === 'apache2handler' || $sapi === 'litespeed') {
		return false;
	}

	static $swooleLoaded = null;
	if ($swooleLoaded === null) {
		$swooleLoaded = extension_loaded('swoole') && class_exists(\Swoole\Coroutine::class);
	}
	if ($swooleLoaded && \Swoole\Coroutine::getCid() > 0) {
		return true;
	}
	if (!class_exists('Workerman\\Coroutine', false)) {
		return false;
	}
	// Workerman 类文件加载期间可能尚未完成 driverClass 初始化；此时不能触发
	// typed static property 的 Error，否则 CLI 引导会被环境探测打断。
	try {
		return \Workerman\Coroutine::isCoroutine();
	} catch (\Error) {
		return false;
	}
}

/**
 * Windows操作系统检测函数
 * 
 * 用于检测当前服务器运行的操作系统是否为Windows系列，通过检查PHP_OS常量
 * 进行判断，忽略大小写差异。
 * 
 * @return bool 如果是Windows操作系统返回true，否则返回false
 * 
 * @example
 * ```php
 * // 根据操作系统执行不同的文件操作
 * if (isWinOS()) {
 *     // Windows路径分隔符处理
 *     $path = str_replace('/', '\\', $path);
 * } else {
 *     // 类Unix系统路径处理
 *     $path = str_replace('\\', '/', $path);
 * }
 * 
 * // 操作系统特定配置
 * $config = [
 *     'temp_dir' => isWinOS() ? 'C:/tmp/' : '/tmp/',
 *     'line_ending' => isWinOS() ? "\r\n" : "\n"
 * ];
 * ```
 * 
 * @note
 * - 函数通过strpos(strtolower(PHP_OS), 'win') === 0进行判断
 * - 可用于文件路径处理、命令执行、系统特定功能适配等场景
 * - 与PHP_OS_FAMILY常量（PHP 7.2+）功能类似，但提供了更好的兼容性
 */
function isWinOS(): bool
{
	return strpos(strtolower(PHP_OS), 'win') === 0 ? true : false;
}

function isSsl(): bool
{
	$httpsopen = G('cfgs.httpsopen');
	$server = I('server');
	if(isset($server['HTTPS']) && ('1' == $server['HTTPS'] || 'on' == strtolower($server['HTTPS']))){
		return true;
	}elseif(isset($server['SERVER_PORT']) && ('443' == $server['SERVER_PORT'])){
		return true;
	}elseif($httpsopen == 1){
		return true;
	}
	return false;
}

/**
 * 移动设备检测函数
 * 
 * 用于检测当前访问的设备是否为移动设备，通过多种方式进行判断，包括HTTP头信息、
 * User-Agent字符串分析等。函数采用多重判断机制，确保检测结果的准确性。
 * 
 * @return bool 如果是移动设备返回true，否则返回false
 * 
 * @example
 * ```php
 * // 根据设备类型显示不同内容
 * if (isMobile()) {
 *     // 显示移动端内容或模板
 *     $template = 'mobile/index.html';
 * } else {
 *     // 显示PC端内容或模板
 *     $template = 'pc/index.html';
 * }
 * 
 * // 在模板路径初始化时使用
 * $templatePath = isMobile() ? 'views/mobile/' : 'views/pc/';
 * ```
 * 
 * @note
 * - 函数首先检查HTTP_X_WAP_PROFILE和HTTP_PROFILE头信息
 * - 然后检查HTTP_CLIENT是否为PhoneClient
 * - 接着检查HTTP_VIA中是否包含wap关键字
 * - 再通过正则表达式在User-Agent中匹配常见移动设备关键字
 * - 最后通过HTTP_ACCEPT头信息进行补充判断
 * - 判断结果可用于响应式设计、设备适配等场景
 */
function isMobile(): bool
{
	$server = I('server');
	// 如果有HTTP_X_WAP_PROFILE则一定是移动设备  
	if (isset($server['HTTP_X_WAP_PROFILE']))
		return true;  
	
	// 如果有HTTP_PROFILE则一定是移动设备  
	if (isset($server['HTTP_PROFILE']))
		return true;  
	  
	//此条摘自TPM智能切换模板引擎，适合TPM开发  
	if(isset ($server['HTTP_CLIENT']) && 'PhoneClient'==$server['HTTP_CLIENT'])
		return true;  
	
	//如果via信息含有wap则一定是移动设备,部分服务商会屏蔽该信息  
	if (isset($server['HTTP_VIA']))
		//找不到为flase,否则为true  
		return stristr($server['HTTP_VIA'], 'wap') ? true : false;
	//判断手机发送的客户端标志,兼容性有待提高  
	if (isset($server['HTTP_USER_AGENT'])){
		$clientkeywords = ['nokia','sony','ericsson','mot','samsung','htc','sgh','lg','sharp','sie-','philips','panasonic','alcatel','lenovo','iphone','ipod','blackberry','meizu','android','netfront','symbian','ucweb','windowsce','palm','operamini','operamobi','openwave','nexusone','cldc','midp','wap','mobile'];  
		//从HTTP_USER_AGENT中查找手机浏览器的关键字  
		if (preg_match("/(" . implode('|', $clientkeywords) . ")/i", strtolower($server['HTTP_USER_AGENT']))){
			return true;  
		}  
	}  
	//协议法，因为有可能不准确，放到最后判断  
	if (isset($server['HTTP_ACCEPT'])){
		// 如果只支持wml并且不支持html那一定是移动设备  
		// 如果支持wml和html但是wml在html之前则是移动设备  
		if ((strpos($server['HTTP_ACCEPT'], 'vnd.wap.wml') !== false) && (strpos($server['HTTP_ACCEPT'], 'text/html') === false || (strpos($server['HTTP_ACCEPT'], 'vnd.wap.wml') < strpos($server['HTTP_ACCEPT'], 'text/html')))){
			return true;  
		}  
	}  
	return false;  
}

/**
 * 模板路径初始化函数
 * 
 * 设置和初始化应用的模板相关路径，支持自定义模板路径、多风格模板、
 * 移动端适配以及模板缓存和编译路径设置。该函数在应用初始化时被调用，
 * 为视图渲染提供必要的路径支持。
 * 
 * @param string $appName 应用名称，默认为当前应用
 * @return void
 * 
 * @example
 * ```php
 * // 初始化当前应用的模板路径
 * initTplPath();
 * 
 * // 初始化指定应用的模板路径
 * initTplPath('Admin');
 * ```
 * 
 * @note
 * - 函数会根据APP_TPL_PATH配置处理自定义模板路径
 * - 支持多风格模板，通过C('TPLSTYLE')配置
 * - 自动检测移动设备并切换到mobile目录（如果存在）
 * - 设置模板编译目录和缓存目录
 * - 对于插件应用，有特殊的路径处理逻辑
 */
function initTplPath(string $appName = ''): void
{
	empty($appName) && $appName = C('APPNAME');
	//初始化路径
	C(['TPL_PATH'=>null, 'TEMPLATE_DIR'=>null]);
	
	//分解模板路径
	$appTplPath = C('APP_TPL_PATH');
	if(!is_null($appTplPath)){
		//多项目自定义目录
		if(is_array($appTplPath)){
			foreach($appTplPath as $tplPath){
				[$app, $path] = explode('@', $tplPath);
				if($app == $appName){
					C('TPL_PATH', trim(trim($path, './'),'/'));
					break;
				}
			}
		}else{
			[$app, $path] = explode('@', $appTplPath);
			if($app == $appName){
				C('TPL_PATH', trim(trim($path, './'),'/'));
			}
		}
	}
	
	//添加模版目录判断
	// 控制器已锁定则跟锁；否则 AppPathResolver 拆段优先、扁平兜底；Runtime 键用逻辑名
	$isAddon = !is_null(C('ISADDON'));
	$logicalName = \Candy\Core\AppPathResolver::logicalName($appName);
	$lockedRoot = \Candy\Core\AppPathResolver::lockedRootFor($logicalName);
	if (is_string($lockedRoot) && $lockedRoot !== '') {
		$pathAppName = (string) (C('APP_FS_REL') ?: $logicalName);
		$logicalName = (string) (C('APPNAME') ?: $logicalName);
	} elseif ($isAddon) {
		$pathAppName = \Candy\Core\Addon::resolveFsRelPath($appName) ?: $logicalName;
	} else {
		$pathAppName = \Candy\Core\AppPathResolver::resolveApplicationFsRel($appName) ?: $logicalName;
	}
	$configuredTplPath = C('TPL_PATH');
	if ($isAddon) {
		// 扩展 Views 默认无风格子目录；若存在 TPLSTYLE 目录再套用
		$tplPath = C('CANDYROOT') . 'Addons/' . $pathAppName . '/Views';
		$style = (string) C('TPLSTYLE');
		if ($style !== '' && is_dir($tplPath . '/' . $style)) {
			$tplPath .= '/' . $style;
		} else {
			C('TPLSTYLE', '');
		}
	} else {
		$tplPath = is_null($configuredTplPath)
			? C('CANDYROOT') . 'Application/' . $pathAppName . '/Views/' . C('TPLSTYLE')
			: C('CANDYROOT') . $configuredTplPath . '/' . C('TPLSTYLE');
		if (!is_dir($tplPath)) {
			$tplPath = dirname($tplPath);
			C('TPLSTYLE', '');
		}
	}
	
	//浏览模式
	if(isMobile()){
		//手机模式
		$lastPath = is_dir($tplPath . '/mobile') ? '/mobile' : (is_dir($tplPath . '/pc') ? '/pc' : '');
	}else{
		$lastPath = is_dir($tplPath . '/pc') ? '/pc' : '';
	}
	C('LASTPATH', $lastPath);
	
	//模板资源路径
	$bootResKey = 'c_res_'. $pathAppName;
	if(!empty($$bootResKey)){
		C('C_RES', $$bootResKey);
		\Candy\Core\Debug::addMsg('[<b>APP</b>] 当前应用( <b>'. $pathAppName .'</b> )的资源请求位置：'. C('C_RES'), 3);
	}else {
		if(!$isAddon){
			// Runtime 缓存键用逻辑名，避免 Comps/Creator/Hub 与 CreatorHub 双份
			C('TEMPLATE_DIR', $tplPath . $lastPath);
			$GLOBALS['res']= str_replace(C('CANDYROOT'), '/', C('TEMPLATE_DIR')) .'/Resource/'; 
			C('COMPILE_DIR', C('CANDYROOT') .'Runtime/Comps/'. $logicalName .'/'. C('TPLSTYLE') . $lastPath);
			C('CACHE_DIR', C('CANDYROOT') .'Runtime/Cache/'. $logicalName .'/'. C('TPLSTYLE') . $lastPath);
			
			//当前应用模板的资源
			C('C_RES', rtrim($GLOBALS['res'], '/'));
			\Candy\Core\Debug::addMsg('[<b>APP</b>] 当前应用( <b>'. $pathAppName .'</b> )的资源请求位置（默认）：'. C('C_RES'), 3);
		}else{
			C('TEMPLATE_DIR', $tplPath . $lastPath);
			$GLOBALS['res']= str_replace(C('CANDYROOT'), '/', C('TEMPLATE_DIR')) .'/Resource/'; 
			
			C('COMPILE_DIR', C('CANDYROOT') .'Runtime/Comps/Addons/'. $logicalName .  C('LASTPATH'));
			//创建缓存目录
			if(!is_dir(C('COMPILE_DIR')))
				\Candy\Extend\Dir::create(C('COMPILE_DIR'));
			
			C('CACHE_DIR', C('CANDYROOT') .'Runtime/Cache/Addons/'. $logicalName .  C('LASTPATH'));
			//创建缓存目录
			if(!is_dir(C('CACHE_DIR')))
				\Candy\Extend\Dir::create(C('CACHE_DIR'));
			
			//当前应用模板的资源
			C('C_RES', rtrim($GLOBALS['res'], '/'));
			\Candy\Core\Debug::addMsg('[<b>APP</b>] 当前插件( <b>'. $pathAppName .'</b> )的资源请求位置（默认）：'. C('C_RES'), 3);
		}
	}
}

/**
 * 获取当前项目名称
 * @return string 项目名称
 */
function getCurrentProjectName(): string {
    // ALLINONE模式下根据域名获取项目名称
    $host = (string)I('server.HTTP_HOST', '', '');
    if (defined('ALLINONE') && $host !== '') {
        return md5($host);
    }
    
    // 从APP_PATH路径提取项目名称
    $appPath = C('APP_PATH');
    if ($appPath) {
        // 解析路径获取项目名称
        $pathParts = explode(DS, trim($appPath, DS));
        return md5(end($pathParts));
    }
    
    // 默认项目名称
    return 'default';
}

/**
 * 清除系统运行时产生的所有缓存和临时文件
 *
 * 该函数用于清理系统运行过程中产生的各类缓存文件、编译文件和日志文件，
 * 可以有效释放磁盘空间并确保系统使用最新的代码和配置。
 * 当系统遇到缓存相关问题或进行版本更新后，通常需要调用此函数清理缓存。
 *
 * 清理内容包括：
 * - 缓存文件 (Cache目录)
 * - 模板编译文件 (Comps目录)
 * - 控制器编译文件 (Controls目录)
 * - 数据编译文件 (Data目录)
 * - 日志文件 (Logs目录)
 * - 模型编译文件 (Models目录)
 *
 * 使用示例：
 * - 清理所有运行时文件: cleanRuntime()
 *
 * @return void 无返回值
 */
function cleanRuntime(): void
{
	\Candy\Extend\Dir::del(C('RUNTIME').'Cache');	//清除缓存文件
	\Candy\Extend\Dir::del(C('RUNTIME').'Comps');	//清除模版编译文件
	\Candy\Extend\Dir::del(C('RUNTIME').'Controls');	//清除控制器编译文件
	\Candy\Extend\Dir::del(C('RUNTIME').'Data');	//清除数据编译文件
	\Candy\Extend\Dir::del(C('RUNTIME').'Logs');	//清除Log文件
	\Candy\Extend\Dir::del(C('RUNTIME').'Models');	//清除模型编译文件
}
