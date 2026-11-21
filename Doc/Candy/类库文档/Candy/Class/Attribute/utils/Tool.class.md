# Tool 类

## 概述

* Attribute工具类
 * 
 * 该类提供了一套完整的属性和文档注释处理工具，用于解析控制器类及其方法的
 * 注释信息，支持PHP 8 Attributes和传统文档注释两种方式，主要用于权限控制、
 * 菜单生成和文档自动生成等功能。
 * 
 * @package Candy\Extend\Attribute\utils
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-03 20:08:03

## 主要方法


### getAnnotationInfo()


### parseMethodAnnotation()


### getClassList()


### /**
	 * 获取控制器类的方法节点列表
	 * 
	 * 该方法用于获取指定控制器类的所有方法节点信息，优先从缓存中获取，
	 * 如果缓存中不存在，则通过反射和注释解析获取，并更新缓存。
	 * 
	 * @param string $appName 应用名称
	 * @param string $control 控制器类名（包含命名空间）
	 * @return array 方法节点列表数组，键为方法名，值为方法的详细信息
	 * 结构示例：
	 * [
	 *     'methodName' => [
	 *         'Title' => '方法标题',
	 *         'Auth' => true|false, // 是否需要权限
	 *         'Menu' => true|false, // 是否显示在菜单
	 *         'Log' => true|false,  // 是否记录日志
	 *         'Desc' => '方法描述'
	 *     ]
	 * ]
	 */
	private static function getMethodList(string $appName,string $control):()


### /**
	 * 扫描插件控制器
	 *
	 * 该方法用于扫描指定应用下的所有插件控制器，目前为空实现，可在后续版本中扩展。
	 * 主要用于支持插件系统的控制器识别和处理。
	 *
	 * @param string $appName 应用名称
	 * @return array 插件控制器列表
	 */
	private static function eachAddonController(string $appName):()


### /**
	 * 扫描控制器文件并创建反射对象
	 *
	 * 该方法扫描指定应用下的控制器目录，识别所有控制器文件，
	 * 排除Common控制器，为每个控制器创建反射对象，并按指定顺序排序。
	 * 支持缓存机制，避免重复扫描和反射操作。
	 *
	 * @param string|null $appName 应用名称，默认为null
	 * @param string|null $type 控制器类型，可选参数
	 * @return array 控制器反射对象列表，键为类名，值为反射对象
	 */
	private static function eachController(?string $appName = null,?string $type = null):()


### /**
     * 生成控制器类的完整类名
     *
     * 该方法根据应用名称、控制器名称和类型，生成符合命名空间规范的完整类名。
     * 自动添加Action后缀（除Common和CommonExtend控制器外）。
     *
     * @param string $appName 应用名称
     * @param string $className 控制器名称
     * @param string $type 控制器类型，可选值：'app'（默认）或 'addon'
     * @return string 完整的类名（包含命名空间）
     */
	private static function classCacheFile(string $appName,string $className,string $type = 'app'):()


### /**
	 * 处理注解参数
	 */
	private static function AnnotationParams(array $args):()

