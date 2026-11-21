<?php
/**
 * Candy框架 框架Hook钩子类
 * 
 * Hook类是Candy框架的钩子系统核心类，提供了插件管理、行为扩展、标签监听等功能。
 * 它支持两种主要的钩子机制：静态文件钩子（用于模板和控制器编译）和动态执行钩子（基于事件标签）。
 * 
 * 主要功能：
 * - 插件的启用、禁用和管理
 * - 行为的注册、执行和监听
 * - 标签系统的事件处理
 * - 模板钩子的动态替换
 * - 插件配置的缓存管理
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @final 该类不允许被继承
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Hook {
	/**
     * 钩子标签与行为的映射关系
     * 存储所有已注册的标签及其对应的行为列表
     * @var array 键为标签名，值为行为列表数组
     */
    private static $tags = [];

    /**
     * 行为别名映射
     * 存储行为名称到具体实现的映射关系，便于通过别名调用行为
     * @var array 键为行为别名，值为实际行为类名或闭包
     */
    protected static $bind = [];

    /**
     * 插件配置缓存
     * 缓存已加载的插件配置信息，避免重复读取
     * @var array|null 插件配置数组或null
     * @access private 私有静态属性，仅在类内部访问
     */
    private static $hookConfig = null;
	
    /**
     * 处理钩子文件内容
     * 
     * 读取并处理指定钩子文件的内容，支持千人千面特性的文件优先级。
     * 处理过程包括去除文件开头的exit语句和PHP标签，确保内容可以安全合并。
     * 
     * @param string $hookfile 钩子文件路径
     * @return string 处理后的钩子文件内容字符串
     */
	private static function processHook(string $hookfile): string
	{
		$s = '';
		$hooks = self::getEnableHooks();
		$hooknames = array_keys($hooks);
		foreach($hooknames as $v){
			if(empty($hooks[$v]))
				continue;
			
			$hook = C('PLUGPATH') . $v . '/Hook/' .$hookfile;
			
			// 租户钩子优先：Plugins/{Plug}/Hook/Tenant/{id}/…
			$hookTenant = \Candy\Core\Bootstrap::tenantOverlayFile(C('PLUGPATH') . $v . '/', 'Hook', $hookfile);
			if ($hookTenant !== null) {
				$hook = $hookTenant;
			}

			//千人千面（在选中路径的同级 Hallmark/{value}/ 下试覆写）
			$hallmark = C('HALLMARK');
			if ($hallmark !== null && $hallmark !== '') {
				$dir = dirname($hook);
				$base = basename($hook);
				$hook2 = $dir . '/Hallmark/' . $hallmark . '/' . $base;
				$hook = is_file($hook2) ? $hook2 : $hook;
			}
			
			if(!is_file($hook))
				continue;
			
			$s2 = stripWhitespace(file_get_contents($hook));			
			$s2 = preg_replace('#^<\?php\s*exit;\?>\s{0,2}#i', '', $s2);
			if(substr($s2, 0, 5) == '<?php' && substr($s2, -2, 2) == '?>'){
				$s2 = substr($s2, 5, -2);		
			}
			$s .= $s2;
		}
		return $s;
	}
	
    /**
     * 获取已启用的插件列表
     * 
     * 根据插件的安装状态和启用状态筛选出当前可用的插件。
     * 支持强制刷新和缓存机制，提高性能。
     * 
     * @param bool $force 是否强制刷新插件列表，true表示重新获取，false表示使用缓存
     * @return array 已启用的插件数组，键为插件名称，值为插件配置
     */
	public static function getEnableHooks(bool $force = true): array
	{
		$force === true ? $hooks = self::getHooks() : $hooks = [];
		
		static $enable_hooks = [];
		if(!empty($enable_hooks) && !$force) return $enable_hooks;
		foreach($hooks as $k=>$hook){
			if((isset($hook['installed']) && $hook['installed'] == 1 && isset($hook['enable']) && $hook['enable'] == 1) || $k == C('RAPPNAME') || $k == $hook['pertain']){
				$enable_hooks[$k] = $hook;
			}
		}
		return $enable_hooks;
	}
	
    /**
     * 获取所有插件配置
     * 
     * 加载并缓存所有插件的配置信息，包括插件目录中的配置文件和全局插件配置。
     * 支持插件排序功能，并添加当前项目同名插件。
     * 
     * @return array 所有插件的配置数组，已按rank排序
     */
    public static function getHooks(): array
    {
        if (self::$hookConfig !== null) {
            return self::$hookConfig;
        }

        $path = C('PLUGPATH');
        if (!is_dir($path)) return [];
        
        $hook_path = C('INCPATH').'Plug.inc.php';
        $setting = is_file($hook_path) ? include($hook_path) : [];
        $hook_list = $setting['PLUG_LIST'] ?? [];
        
        $hooks = [];
        foreach (dirs($path) as $v) {
            $conffile = $path.$v.'/conf.php';
            if (is_file($conffile)) {
                $conf = (array)include($conffile);
                $conf += [
                    'enable' => $hook_list[$v]['enable'] ?? 0,
                    'installed' => $hook_list[$v]['installed'] ?? 0,
                    'plugid' => $hook_list[$v]['plugid'] ?? 0,
                    'rank' => $hook_list[$v]['rank'] ?? 100,
                    'pertain' => $hook_list[$v]['pertain'] ?? ''
                ];
                $hooks[$v] = $conf;
            }
        }

        // 添加当前项目同名插件
        $hooks[C('RAPPNAME')] = ['rank' => 100];
        
        self::$hookConfig = \Candy\Extend\Arr::arraySort($hooks, 'rank');
        return self::$hookConfig;
    }
	
    /**
     * 处理钩子回调接口
     * 
     * 作为正则表达式替换回调函数，用于在模板或代码中动态替换钩子标签。
     * 从匹配结果中提取钩子文件路径并调用processHook方法处理。
     * 
     * @param array $matches 正则表达式匹配结果数组，索引1包含钩子文件路径
     * @return string 处理后的钩子内容
     */
	public static function processHookCallback(array $matches): string
	{
		$s = $matches[1];
		return self::processHook($s);
	}
	
	/**
     * 注册行为别名
     * 
     * 为行为定义别名，方便通过简短名称调用具体行为。
     * 支持单个别名注册和批量注册多个别名。
     * 
     * @param string|array $name 行为别名或包含多个别名的数组
     * @param mixed $behavior 实际行为类名或闭包（当$name为字符串时有效）
     * @return string 当前类名，支持链式调用
     */
    public static function alias($name, $behavior = null)
    {
        if (is_array($name)) {
            self::$bind = array_merge(self::$bind, $name);
        } else {
            self::$bind[$name] = $behavior;
        }

        return static::class;
    }

    /**
     * 动态添加行为到标签
     * 
     * 为指定标签动态注册行为，支持单个行为和行为数组的注册。
     * 提供覆盖模式和位置控制功能，可以控制行为的执行顺序。
     * 
     * @param string $tag 标签名称
     * @param mixed $behavior 行为名称、闭包或行为数组
     * @param bool $first 是否添加到队列开头（优先级高）
     * @return void
     */
    public static function add($tag, $behavior, $first = false)
    {
        if (is_array($behavior) && !is_callable($behavior)) {
            if (!array_key_exists('_overlay', $behavior)) {
				isset(self::$tags[$tag]) OR self::$tags[$tag] = [];
                self::$tags[$tag] = array_merge(self::$tags[$tag], $behavior);
            } else {
                unset($behavior['_overlay']);
                self::$tags[$tag] = $behavior;
            }
        } elseif ($first) {
            array_unshift(self::$tags[$tag], $behavior);
        } else {
            self::$tags[$tag][] = $behavior;
        }
    }

    /**
     * 批量导入标签与行为配置
     * 
     * 一次性导入多个标签及其对应的行为配置。
     * 支持递归合并和直接覆盖两种模式，并自动设置标签常量。
     * 
     * @param array $tags 标签配置数组，格式为[标签名 => 行为列表]
     * @param bool $recursive 是否递归合并现有配置，true为合并，false为覆盖
     * @return void
     */
    public static function import(array $tags, $recursive = true)
    {
        if ($recursive) {
            foreach ($tags as $tag => $behavior) {
                self::add($tag, $behavior);
            }
        } else {
            self::$tags = $tags + self::$tags;
        }
		
		//标记
		$keys = array_keys($tags);
		foreach ($keys as $key) {
			C('TAGS_' . strtoupper($key), true);
		}
    }

    /**
     * 获取标签配置信息
     * 
     * 获取指定标签的行为配置，或所有已注册标签的配置信息。
     * 当不指定标签名时，返回所有标签的完整配置。
     * 
     * @param string $tag 标签名称，留空获取所有标签配置
     * @return array 指定标签的行为配置数组，或所有标签的配置数组
     */
    public static function get($tag = '')
    {
        if (empty($tag)) {
            //获取全部的插件信息
            return self::$tags;
        }

        return array_key_exists($tag, self::$tags) ? self::$tags[$tag] : [];
    }
	
    /**
     * 监听并触发标签事件
     * 
     * 监听指定标签并按顺序执行所有注册的行为。
     * 支持单次模式（只获取一个有效返回值）和完整模式（获取所有行为的返回值）。
     * 当某个行为返回false时，会中断执行链。
     * 
     * @param string $tag 标签名称
     * @param mixed $params 传递给行为的参数
     * @param bool $once 是否只获取第一个有效返回值
     * @return mixed 如果$once为true则返回单个返回值，否则返回所有行为的返回值数组
     */
	public static function listen($tag, $params = null, $once = false)
    {
        $results = [];
        $tags    = self::get($tag);
		
		if(empty($tags)) return false;
		
        foreach ($tags as $key => $name) {
            $results[$key] = self::execTag($name, $tag, $params);

            if (false === $results[$key] || (!is_null($results[$key]) && $once)) {
                break;
            }
        }

        return $once ? end($results) : $results;
    }

    /**
     * 执行指定行为
     * 
     * 执行单个行为，可以是闭包、数组形式的方法调用或通过别名注册的行为。
     * 自动处理行为别名解析，并使用容器执行行为。
     * 
     * @param mixed $class 行为类名、别名、闭包或[类名, 方法名]格式的数组
     * @param mixed $params 传递给行为的参数
     * @return mixed 行为执行的返回值
     */
    public static function exec($class, $params = null)
    {
        if ($class instanceof \Closure || is_array($class)) {
            $method = $class;
        } else {
            if (isset(self::$bind[$class])) {
                $class = self::$bind[$class];
            }
            $method = [$class, 'run'];
        }

        return Container::getInstance()->invoke($method, [$params]);
    }

    /**
     * 执行标签对应的单个行为
     * 
     * 执行标签中的单个行为，支持闭包、静态方法调用和实例方法调用。
     * 提供异常捕获和备用执行方案，确保行为执行的稳定性。
     * 
     * @access protected 保护方法，仅在类内部调用
     * @param mixed $class 行为类名、闭包或静态方法名
     * @param string $tag 标签名称
     * @param mixed $params 传递给行为的参数
     * @return mixed 行为执行的返回值
     */
    protected static function execTag($class, $tag = '', $params = null)
    {
		if ($class instanceof \Closure) {
            $call  = $class;
            $class = 'Closure';
        } elseif (strpos($class, '::')) {
            $call = $class;
        } else {
            $call  = [$class, 'run'];
        }
		//调用
		try {
			//静态调用
			$result = Container::getInstance()->invoke($call, [$params]);
		} catch (\Exception $e) {
			$instance = Container::getInstance()->make($class, [], true);
			$result = Container::getInstance()->invokeMethod([$instance, 'run'], [$params]);
		}
		
        return $result;
    }

    /**
     * 清除所有钩子相关缓存
     * 
     * 重置所有与钩子系统相关的静态变量，包括插件配置缓存、标签映射和行为绑定。
     * 用于在插件更新或配置变更后强制重新加载最新配置。
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        self::$hookConfig = null;
        self::$tags = [];
        self::$bind = [];
    }
}
