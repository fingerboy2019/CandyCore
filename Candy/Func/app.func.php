<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 获取应用目录路径
 * 
 * 提供灵活的应用目录路径获取功能，支持获取当前应用路径、指定应用路径以及插件路径。
 * 函数会自动检测应用类型，并返回正确的目录路径，对不存在的应用会尝试检查是否为插件。
 * 
 * @param string $appName 应用名称，默认为空（获取当前应用路径）
 * @param string|null $type 应用类型，可选值为'addon'（插件）或null（自动检测）
 * @return string|bool 成功时返回应用目录的绝对路径，失败时返回false
 * 
 * @example
 * ```php
 * // 获取当前应用路径
 * $currentAppPath = getAppPath();
 * 
 * // 获取指定应用路径
 * $homeAppPath = getAppPath('Home');
 * 
 * // 获取插件路径
 * $addonPath = getAppPath('PluginName', 'addon');
 * 
 * // 自动检测并获取应用或插件路径
 * $path = getAppPath('UnknownApp');
 * // 如果'UnknownApp'是插件，将返回插件路径；如果是普通应用，返回应用路径；都不是则返回false
 * ```
 * 
 * @note
 * - 当$appName为空时，返回当前活动应用的路径
 * - 当$type指定为'addon'时，直接返回插件目录路径，不进行验证
 * - 函数会先检查是否为普通应用，然后再检查是否为插件
 * - 路径末尾包含目录分隔符
 */
function getAppPath(string $appName = '',?string $type = null): mixed
{
	if(empty($appName))
		return C('APP_PATH');
	
	if($type == 'addon'){
		return C('CANDYROOT') .'Addons/'. $appName .'/';
	}else{
		//判断是否存在 不存在则默认Home
		$appPath = C('CANDYROOT').'Application/'. $appName .'/';
		if(is_dir($appPath)){
			return $appPath;
		}else{
			//不存在操作检查是否为Addon
			$isAddon = \Candy\Core\Addon::checkAddon($appName);
			if($isAddon){
				return C('CANDYROOT').'Addons/'. $appName .'/';
			}
		}
	}
	
	return false;
}

/**
 * 获取操作名称
 *
 * 该函数用于获取完整的操作名称，通过调用Node类的full方法实现，
 * 可用于获取当前操作或指定操作的完整路径。
 *
 * 使用示例：
 * - 获取当前操作完整名称: getOperate()
 * - 获取指定操作完整名称: getOperate('user/index')
 *
 * @param string $name 操作名称，默认为空（获取当前操作）
 * @return string 返回完整的操作名称
 */
function getOperate(string $name = ''):string
{
	return \Candy\Core\Node::full($name);
}

/**
 * 判断是否存在同名插件的操作
 *
 * 该函数用于检查是否存在与当前操作同名的插件。目前为占位函数，需要在后续版本中实现具体逻辑。
 *
 * @return mixed 函数实现后应返回是否存在同名插件的结果
 * @note 该函数目前为占位函数，尚未实现具体逻辑
 */
function hasPlugins()
{
	//占位吧  需要在写
}

/**
 * 引入非规则扩展类库
 *
 * 该函数用于加载和引入项目中的各类扩展类库文件，支持多种路径格式和特殊引用方式。
 * 特别支持以@开头的特殊路径，用于快速引入应用内部的控制器或模型类。
 *
 * 使用示例：
 * ```php
 * // 引入Vendor目录下的类库
 * import('Util.Helper'); // 引入SOURCEPATH/Vendor/Util/Helper.php
 * 
 * // 引入应用控制器
 * import('@.app.User'); // 引入当前应用的User控制器
 * 
 * // 引入应用模型
 * import('@.model.User'); // 引入当前应用的User模型
 * 
 * // 自定义起始路径
 * import('Custom.Library', '/path/to/libs/', '.class.php');
 * ```
 *
 * @param string $path 文件路径，支持点号分隔的路径格式，@开头表示特殊路径
 * @param string $startpath 起始路径，默认为SOURCEPATH/Vendor/
 * @param string $suffix 文件后缀，默认为.php
 * @return bool 引入成功返回true，失败返回false
 */
function import(string $path,string $startpath = '',string $suffix = '.php'): bool
{
	//基础路径转换
	$path = explode('.', $path);
	
	//本项目调用
	if($path[0] == '@'){
		array_shift($path);
		$m = strtolower($path[0]);
		array_shift($path);
		if($m == 'app'){
			$path = C('RUNTIME'). 'Controls/' . C('APPNAME') . '/' . strtolower($path[0]) . 'action.class.php';
		}else{
			$path = C('RUNTIME'). 'Models/' . C('APPNAME'). '/' . strtolower($path[0]) . 'model.class.php';
		}
		
		return requireCache($path);
	}
	
	//默认开始路径
	empty($startpath) && $startpath = C('SOURCEPATH') . 'Vendor/';
	
	//文件路径
	$path = $startpath . implode('/', $path) . $suffix;
	
	return requireCache($path);
}

/**
 * 引入非规则类库
 * 
 * 该函数提供了一种灵活的方式来引入项目中的类库文件，支持使用点分隔符指定路径，
 * 并允许自定义起始目录和文件后缀。内部使用requireCache函数确保文件只被加载一次，
 * 提高性能并避免重复定义错误。
 * 
 * @param string $path 类库路径，使用点分隔符（如："Vendor.Util.Date"）
 * @param string $startpath 起始目录路径，默认为空（使用配置中的SOURCEPATH + 'Vendor/'）
 * @param string $suffix 文件后缀，默认为'.php'
 * @return bool 引入成功返回true，失败返回false
 * 
 * @example
 * ```php
 * // 引入默认Vendor目录下的类库
 * vendor('Util.Helper'); // 实际引入: /项目根目录/Vendor/Util/Helper.php
 * 
 * // 引入自定义目录下的类库
 * vendor('Custom.Library', '/path/to/libs/', '.class.php'); // 实际引入: /path/to/libs/Custom/Library.class.php
 * ```
 * 
 * @note
 * - 使用requireCache函数进行文件加载，避免重复加载
 * - 路径中的点(.)会被转换为目录分隔符(/)
 * - 支持自定义起始目录和文件后缀，增强了灵活性
 */
function vendor(string $path,string $startpath = '',string $suffix = '.php'): bool
{
	//基础路径转换
	$path = explode('.', $path);
	
	//默认开始路径
	empty($startpath) && $startpath = C('SOURCEPATH') . 'Vendor/';
	
	//文件路径
	$path = $startpath . implode('/', $path) . $suffix;
	
	return requireCache($path);
}

/**
 * 获取网络时间戳
 *
 * 该函数是time()方法的增强版，支持获取网络标准时间戳，确保时间的准确性。
 * 当定义了NETWORKTIME常量时，会尝试从外部API获取时间，优先使用淘宝接口，
 * 失败时尝试苏宁接口，最后才使用本地时间作为后备方案。
 *
 * 使用示例：
 * - 获取当前时间戳: gmTime()
 * - 启用网络时间前需要定义常量: define('NETWORKTIME', true);
 *
 * @return int 返回Unix时间戳（秒）
 */
function gmTime(): int
{
	if(defined('NETWORKTIME')){
		$api = 'http://api.m.taobao.com/rest/api3.do?api=mtop.common.getTimestamp';	//淘宝接口
		$json = @file_get_contents($api);
		$arr = json($json,'un');
		if(empty($arr['data']['t'])){
			//失败调用苏宁接口
			$api = 'http://quan.suning.com/getSysTime.do';
			$json = @file_get_contents($api);
			$arr = json($json,'un');
			// 修复：有值时解析，为空时返回本地时间
			if(!empty($arr['sysTime2'])){
				return strtotime($arr['sysTime2']);
			}else{
				return time();
			}
		}else{
			return (int)($arr['data']['t'] / 1000); // 淘宝返回毫秒，转换为秒
		}
	}else{
		return time();
	}
}
