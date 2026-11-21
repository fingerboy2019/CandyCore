<?php
/**
 * Candy框架扩展模块管理类
 * 
 * Addon类是Candy框架中用于管理扩展模块的工具类，采用final修饰禁止继承
 * 负责扩展的检查、启用状态管理、权限验证等核心功能
 * 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-08-04 18:25:15
 * @package Candy\Core
 * @final 禁止继承此类
 */

declare(strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Addon {
	
	/**
	 * 解析 Addon 真实目录名（逻辑名，供 APPNAME / FQCN）
	 *
	 * URL / 路由里可能被写成 Creatorhub；磁盘可能是扁平 CreatorHub，
	 * 或历史 CamelCase 拆段 Creator/Hub。逻辑名始终去斜杠（CreatorHub），
	 * 磁盘相对路径见 resolveFsRelPath()。
	 *
	 * @param string $addonName 扩展名候选（目录名或任意大小写）
	 * @return string|null 逻辑目录名；未找到返回 null
	 * @static
	 */
	public static function resolveDirName(string $addonName): ?string
	{
		$rel = self::resolveFsRelPath($addonName);
		if ($rel === null) {
			return null;
		}
		// 嵌套 Creator/Hub → 逻辑名 CreatorHub（命名空间 / APPNAME）
		return AppPathResolver::logicalName($rel);
	}

	/**
	 * 解析 Addon 在 Addons/ 下的磁盘相对路径
	 *
	 * 委托 AppPathResolver：先 CamelCase 拆段，再扁平；校验目录内存在 conf.php。
	 *
	 * @param string $addonName 扩展名候选
	 * @return string|null 相对路径（如 Creator/Hub 或 CreatorHub）；未找到返回 null
	 * @static
	 */
	public static function resolveFsRelPath(string $addonName): ?string
	{
		$base = rtrim((string) C('ADDONPATH'), '/\\') . '/';
		if ($base === '/' || !is_dir($base)) {
			return null;
		}
		return AppPathResolver::probeFsRel(
			$base,
			$addonName,
			static fn(string $absDir): bool => is_dir($absDir) && is_file($absDir . '/conf.php')
		);
	}

	/**
	 * 解析 Addon 绝对根路径（尾随 /）
	 *
	 * @param string $addonName 扩展名候选
	 * @return string|null 如 {ADDONPATH}CreatorHub/；未找到返回 null
	 * @static
	 */
	public static function resolveRoot(string $addonName): ?string
	{
		$rel = self::resolveFsRelPath($addonName);
		if ($rel === null) {
			return null;
		}
		return rtrim((string) C('ADDONPATH'), '/\\') . '/' . $rel . '/';
	}

	/**
	 * 检查扩展模块是否启用
	 * 
	 * 验证指定名称的扩展模块是否存在且已启用
	 * 检查顺序：
	 * 1. 验证是否处于扩展模式
	 * 2. 检查扩展目录是否存在
	 * 3. 加载扩展配置信息
	 * 4. 检查扩展配置文件是否存在
	 * 5. 验证扩展是否已启用
	 * 
	 * @param string $addonName 扩展模块名称
	 * @return bool 扩展是否存在且已启用
	 * @static
	 */
	public static function checkAddon(string $addonName): bool
	{
		if(is_null(G('Addons'))) return false;
		$path = C('ADDONPATH');
		if(!is_dir($path)) return false;
		$root = self::resolveRoot($addonName);
		$dir = self::resolveDirName($addonName);
		if ($root === null || $dir === null) {
			return false;
		}
		isset($setting) OR $setting = loadConfig('Addon');
		$addon_list = $setting['ADDON_LIST'];
		$conffile = $root . 'conf.php';
		if(fileExistsCase($conffile) || is_file($conffile)){
			$conf = (array)include($conffile);
			!isset($conf['enable']) && $conf['enable'] = isset($addon_list[$dir]['enable'])
				? $addon_list[$dir]['enable']
				: ($addon_list[$addonName]['enable'] ?? 0);
			if($conf['enable']){
				return true;
			}else{
				return false;
			}
		}else{
			return false;
		}
	}
	
	/**
	 * 获取已启用的扩展模块列表
	 * 
	 * 返回所有已安装且启用的扩展模块信息
	 * 使用静态缓存优化性能，避免重复扫描
	 * 
	 * @param int $force 是否强制刷新缓存，0为使用缓存，非0为强制刷新
	 * @return array 已启用的扩展模块数组，以扩展名称为键
	 * @static
	 */
	public static function getEnableAddons(int $force = 0): array
	{
		if(is_null(G('Addons'))) return [];
		$addons = self::getAddons($force);
		static $enable_addons = [];
		if(!empty($enable_addons) && !$force) return $enable_addons;
		
		foreach($addons as $k=>$addon){
			if(!empty($addon['installed']) && !empty($addon['enable'])){
				$enable_addons[$k] = $addon;
			}
		}
		return $enable_addons;
	}
	
	/**
	 * 获取所有扩展模块信息（私有方法）
	 * 
	 * 内部方法，用于扫描和获取所有扩展模块的详细配置信息
	 * 支持从缓存或强制扫描获取，并按优先级排序
	 * 
	 * @param int $force 是否强制扫描所有目录，0为使用配置列表，非0为扫描目录
	 * @return array 扩展模块信息数组
	 * @access private
	 * @static
	 */
	private static function getAddons(int $force = 0): array
	{
		if(is_null(G('Addons'))) return [];
		static $addons = [];
		$path = C('ADDONPATH');
		if(!is_dir($path)) return [];
		$addon_path = C('INCPATH').'Addon.inc.php';
		$setting = is_file($addon_path) ? include($addon_path) : ['ADDON_LIST'=>[]];
		$addon_list = $setting['ADDON_LIST'];
		if($force){
			$arr = dirs($path);
		}else{
			$arr = array_keys($addon_list);
		}
		foreach($arr as $v){
			$conffile = $path.$v.'/conf.php';
			$conf = is_file($conffile) ? (array)include($conffile) : [];
			$conf['enable'] = $conf['enable'] ?? $addon_list[$v]['enable'] ?? 0;
			!isset($conf['installed']) && $conf['installed'] = isset($addon_list[$v]['installed']) ? $addon_list[$v]['installed'] : 0;
			!isset($conf['addonid']) && $conf['addonid'] = isset($addon_list[$v]['addonid']) ? $addon_list[$v]['addonid'] : 0;
			!isset($conf['rank']) && $conf['rank'] = isset($addon_list[$v]['rank']) ? $addon_list[$v]['rank'] : 100; // 按照正序排序
			$addons[$v] = $conf;
		}
		$addons = \Candy\Extend\Arr::arraySort($addons, 'rank');
		return $addons;
	}
	
	/**
	 * 检查扩展模块是否需要授权
	 * 
	 * 验证指定扩展模块是否配置了授权要求
	 * 用于判断扩展是否需要用户登录才能访问
	 * 
	 * @param string $addonName 扩展模块名称
	 * @return bool 扩展是否需要授权
	 * @static
	 */
	public static function getAddonAuth(string $addonName): bool
	{
		if(is_null(G('Addons'))) return false;
		$root = self::resolveRoot($addonName);
		if ($root === null) {
			return false;
		}
		$conffile = $root . 'conf.php';
		if(fileExistsCase($conffile) || is_file($conffile)){
			$conf = (array)include($conffile);
			if(isset($conf['auth']) && $conf['auth'] == 1){
				return true;
			}else{
				return false;
			}
		}else{
			return false;
		}
	}
}
