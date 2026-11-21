<?php
/***
 * Candy框架 初始化文件
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2019-07-24 18:52:59 $   
 */
 
//语法严格模式开启
declare(strict_types=1);

//命名空间
namespace Candy\Core;

//初始化
if(defined('CLIWORKING') === false){
	//一次初始标识 define的部分全放这里 用于兼容workerman
	if (PHP_SAPI === 'cli' || str_starts_with(php_sapi_name(), 'cli')) {
		define('CLIWORKING', true);
	}
	
	//入口标识
	define('CANDY', true);
	
	//分隔符声明
	define('DS', DIRECTORY_SEPARATOR);
	
	//框架目录
	define('CANDYPATH', dirname(str_replace("\\", DS, __DIR__)) . DS);	//CANDY框架的根路径
	
	//包含框架中的函数库文件
	if(function_exists('requireCache') === false){
		require(CANDYPATH . 'Candy/Func/global.func.php');
	}

	//CLI 模式（workerman / swoole 常驻、命令行）先把 C() 配置清零：
	//  位置要求：CLIWORKING 已定义、C() 函数已加载、且**在本文件第一次调用 C() 之前**。
	//  原因：常驻进程是多请求复用同一个 PHP 进程，父进程/上一次运行的残留配置若不清掉，
	//  会被后续请求继承，配置就在请求之间串用；清空后每个进程都从干净状态开始，
	//  才能保证「并发串行」的语义成立。
	//  FPM 不定义 CLIWORKING，不受影响。
	if (defined('CLIWORKING')) {
		C(null, null);
	}

	//5.0动态项目路径 实现单框架多项目
	if(defined('ALLINONE')){
		$appDir = CANDYPATH . 'Applies';

		//优先根据域名判断项目
		if(isset($_SERVER['HTTP_HOST'])){
			$domain = $_SERVER['HTTP_HOST'];
			$Applies = $appDir . DS . $domain . DS;	//根据域名判断项目目录
		}else{
			$Applies = $appDir . DS . 'default'. DS;	//默认项目目录
		}

		//判断项目是否存在
		if(!is_dir($appDir)){
			//不存在报错404
			C('S404', 'File Not Found.'); 
		}
	}else{
		$Applies = dirname(str_replace("\\", DS, __DIR__)) . DS;
	}

	//初始化框架
	define('CANDYROOT', $Applies);
	define('INCPATH', CANDYROOT . 'Source' . DS . 'Inc' . DS);
	C('CANDYROOT', CANDYROOT);
	C('INCPATH', INCPATH);
	C('SOURCEPATH', CANDYROOT . 'Source' . DS);
	
	//框架版本
	if(defined('CANDY_VERSION') === false){
		require_once(CANDYPATH.'Candy/Version.php');
	}

	//初始化
	if(fileExistsCase(INCPATH . 'Init.inc.php'))
		//直接引用防止缓存带来不可变换
		include_once(INCPATH . 'Init.inc.php');
	
	//加载自动类库
	requireCache(CANDYPATH .'Candy/Core/Autoload.class.php');

	//框架自动加载类
	Autoload::loading();

	//注意：节点/注解派生缓存（Node::clearNodeCache / Tool::resetScanCache）**刻意不在这里重置**。
	//  Autoload 在 FPM 下每请求都会执行，在此清共享缓存会把生产缓存打穿；
	//  它们的失效点只有两个：常驻代码换代 reload（RouteRegistry::onWorkerReload）与部署清缓存命令。
	
	//初始化debug
	Debug::start();
	
	//开启系统函数替代模式
	if(is_file(CANDYPATH . '.reload') && class_exists('\Extend\Adapter')){
		\Extend\Adapter::init();
	}

	//debug模式
	(defined('DEBUG') && DEBUG == 1) && debug(1);

	set_exception_handler([Debug::class, 'exceptionFormat']);
}

//基础目录
C('CANDYROOT', CANDYROOT);
C('ADDONPATH', CANDYROOT . 'Addons' . DS);	//扩展目录
C('PLUGPATH', CANDYROOT . 'Plugins' . DS);	//插件目录
C('SOURCEPATH', CANDYROOT . 'Source' . DS);	//配置目录
C('INCPATH', CANDYROOT . 'Source' . DS . 'Inc' . DS);	//配置目录

//声明Runtime路径
if(C('C_RUN_PATH')){
	C('RUNTIME', rtrim(C('C_RUN_PATH'), DS). DS);		
}else{
	C('RUNTIME', CANDYROOT . 'Runtime' . DS);
}
C('LOGPATH', C('RUNTIME') . 'Logs' . DS);	//log目录

//引入全局tags
//⚠️ 必须在上面「CLI 配置重置 + 基础目录重建」之后：
//  1) 原来的位置在 C(null,null) 之前，常驻模式下 TAGS_* 会被重置清空 → listen() 第一行就 return；
//  2) 但也不能紧贴重置之后——重置把 INCPATH 也清了，tags.php 里的 Bootstrap（Catlog/Apidoc）
//     会因读不到配置而返回空表，表现为 $tagConfig 为空、hooks 不注册。
$tagsPath = CANDYROOT . 'Application/tags.php';
$tagConfig = is_file($tagsPath) ? include($tagsPath) : [];
if(empty($tagConfig) === false && is_array($tagConfig)){
	Hook::import($tagConfig);
}

//监听应用程序初始化
listen('app_init');

//授权相关
$authcode = C('SOURCEPATH').'Inc/AuthCode.txt';
if(file_exists($authcode)){
	$authcode = file_get_contents($authcode);
	C('AUTHCODE', $authcode);
}

//检查初始化状态
$structFile = C('RUNTIME').'init.lock';  //初始化锁
fileExistsCase($structFile) && C('APP_INIT_STATUS', true);