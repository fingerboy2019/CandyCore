<?php
/**
 * Candy框架 Event事件处理类
 * 
 * 事件处理系统核心类，负责管理和分发系统中的各类事件，实现观察者模式。
 * 支持精确事件和通配符事件监听，提供灵活的事件注册与触发机制。
 * 实现了事件回调的自动解析和执行，以及事件响应的收集和处理。
 * 
 * 【分工（2026-11-14 确立，专事专办）】
 *   Event = 业务事件总线。用于「发生了什么」的通知：ticket.assigned / order.paid / model.afterInsert。
 *            支持运行时注册（on/once/off）+ 配置文件（Source/Inc/Event.inc.php）+ 优先级 + 通配符。
 *   Hook  = 插件钩子体系。用于「框架生命周期扩展点」：route_start / app_end / action_begin。
 *            它是插件清单（读 Candy/Plug/各插件目录/conf.php 的 PLUG_LIST、按 rank 排序），不是业务事件总线。
 *   两者不要混用：业务通知用 Event，框架扩展点用 Hook。
 *
 * 主要功能：
 * - 事件注册与触发机制（on / once / off / emit / dispatch）
 * - 优先级（priority 越大越先执行；未指定 = 0，与配置文件中的监听器同级）
 * - 支持通配符事件监听（如：ticket.*）
 * - 事件回调自动解析与执行（含监听器实例缓存，同类别重复 new）
 * - 事件响应收集与处理（halt 短路、返回 false 中断）
 * - 事件缓存管理与清理
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @final 该类不允许被继承
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Event {
  	/**
      * 事件配置存储数组
      * @var array 存储事件配置信息的数组
      */
     protected static $events = [];
  
     /**
      * 精确事件监听器映射
      * @var array 键为精确事件名称，值为监听器数组，格式为[id => 监听器]
      */
     protected static $eventMap = [];
  
     /**
      * 前缀事件监听器映射
      * @var array 键为事件前缀，值为监听器数组，格式为[id => 监听器]
      */
     protected static $prefixEventMap = [];

    /**
     * 监听器ID计数器
     * @var int 用于为每个监听器分配唯一ID的计数器
     */
    protected static $id = 0;

    /**
     * 事件配置对象缓存
     * @var array|null 缓存的事件配置，避免重复读取配置文件
     * @access private 私有静态属性，仅在类内部访问
     */
    private static $eventConfig = null;

    /**
     * 监听器实例缓存
     * @var array 键为类名，值为已实例化对象，避免 convertCallable / normalizeListener 反复 new
     */
    private static $instances = [];

    /**
     * 触发指定名称的事件
     * 
     * 根据事件名称获取对应的监听器列表，并依次执行这些监听器。
     * 可以控制是否在第一个非空响应时停止执行，以及收集所有监听器的响应。
     * 
     * @param string $event_name 事件名称
     * @param mixed $data 传递给事件监听器的数据
     * @param bool $halt 是否在接收到第一个非空响应时停止执行
     * @param string $type 事件触发类型，默认为'emit'
     * @return array|null 当$halt为true时返回第一个非空响应或null，否则返回所有监听器的响应数组
     */
    public static function emit(string $event_name, mixed $data, bool $halt = false, string $type = 'emit'): mixed
    {
        $listeners = static::getListeners($event_name, $type);
        $responses = [];
        
        foreach ($listeners as $listener) {
            $response = $listener($data, $event_name);
            $responses[] = $response;
            
            if ($halt && $response !== null) {
                return $response;
            }
            
            if ($response === false) {
                break;
            }
        }
        
        return $halt ? null : $responses;
    }
    
    /**
     * 分发指定名称的事件（emit方法的别名）
     * 
     * 这是emit方法的别名，提供了一致的API调用方式。
     * 功能与emit方法完全相同，只是默认的事件触发类型为'dispatch'。
     * 
     * @param string $event_name 事件名称
     * @param mixed $data 传递给事件监听器的数据
     * @param bool $halt 是否在接收到第一个非空响应时停止执行
     * @param string $type 事件触发类型，默认为'dispatch'
     * @return array|null 当$halt为true时返回第一个非空响应或null，否则返回所有监听器的响应数组
     */
    public static function dispatch(string $event_name,mixed $data,bool $halt = false,string $type = 'dispatch'): mixed
    {
        return static::emit($event_name, $data, $halt , $type);
    }

    /**
     * 获取所有已注册的事件监听器列表
     * 
     * 返回系统中所有已注册的事件监听器，包括精确匹配和通配符匹配的监听器。
     * 结果以ID为键，值为[事件名称, 监听器]的二维数组，并按ID排序。
     * 
     * @return array 已注册的事件监听器列表，格式为[id => [事件名称, 监听器]]
     */
    public static function list(): array
    {
        $listeners = [];
		$eventConfig = readInc('Event');
		if(empty($eventConfig) === false)
			foreach($eventConfig as $event_name=>$callback){
				static::getListeners($event_name);
			}
		
        foreach (static::$eventMap as $event_name => $callback_items) {
            foreach ($callback_items as $id => $callback_item) {
                $listeners[$id] = [$event_name, $callback_item];
            }
        }
        foreach (static::$prefixEventMap as $event_name => $callback_items) {
            foreach ($callback_items as $id => $callback_item) {
                $listeners[$id] = [$event_name . '*', $callback_item];
            }
        }
        ksort($listeners);
        return $listeners;
    }

    /**
     * 获取指定事件的监听器列表
     * 
     * 根据事件名称获取对应的监听器，包括精确匹配的监听器和通配符匹配的监听器。
     * 如果事件尚未初始化，则会通过getEvents方法初始化该事件的监听器。
     * 
     * @param string $event_name 事件名称
     * @param string $type 事件类型，默认为'dispatch'
     * @return callable[] 事件监听器数组，按ID排序
     */
    public static function getListeners(string $event_name,string $type = 'dispatch'):array
    {
		if(isset(static::$eventMap[$event_name]) === false){
			//初始化
			static::getEvents($event_name, $type);
		}
		
		//事件监听集合
        $listeners = static::$eventMap[$event_name];
        foreach (static::$prefixEventMap as $name => $callback_items) {
            if (strpos($event_name, $name) === 0) {
                $listeners = array_merge($listeners, $callback_items);
            }
        }
        ksort($listeners);
        return $listeners;
    }

    /**
     * 检查指定事件是否有监听器
     * 
     * 判断某个事件是否已经注册了监听器，包括精确匹配和通配符匹配的监听器。
     * 
     * @param string $event_name 事件名称
     * @return bool 如果事件有监听器则返回true，否则返回false
     */
    public static function hasListener(string $event_name): bool
    {
        return !empty(static::getListeners($event_name));
    }
	
	/**
     * 初始化事件监听器
     * 
     * 根据事件配置文件初始化指定事件的监听器。
     * 支持精确事件和通配符事件的初始化，会自动解析回调函数。
     * 
     * @param string $event_name 事件名称
     * @param string $type 事件类型
     * @return void
     */
    protected static function getEvents(string $event_name, string $type): void
    {
        if (self::$eventConfig === null) {
            self::$eventConfig = readInc('Event') ?: [];
        }

        [$class] = explode('.', $event_name, 2);
        $wildcard = $class . '.*';

        // 非通配事件处理
        if ($event_name != $wildcard && !isset(self::$eventMap[$event_name])) {
            if (!empty(self::$eventConfig[$event_name])) {
                foreach (self::$eventConfig[$event_name] as $callback) {
                    $callback = static::convertCallable($callback, $type);
                    if ($callback) {
                        self::$eventMap[$event_name][++self::$id] = $callback;
                    }
                }
            } else {
                self::$eventMap[$event_name] = [];
            }
        }

        // 通配事件处理
        if (!isset(self::$prefixEventMap[$class]) && !empty(self::$eventConfig[$wildcard])) {
            foreach (self::$eventConfig[$wildcard] as $callback) {
                $callback = static::convertCallable($callback, $type);
                if ($callback) {
                    self::$prefixEventMap[$class][++self::$id] = $callback;
                }
            }
        }
    }
	
    /**
     * 将配置的回调转换为可调用对象
     * 
     * 解析配置文件中的回调定义，将其转换为有效的PHP可调用对象。
     * 支持类方法格式的回调，并处理可能出现的异常。
     * 
     * @param array $callbacks 回调配置数组，格式为[类名, 方法名]
     * @param string $type 事件类型，用于错误处理
     * @return array|bool 成功时返回[实例, 方法名]的可调用数组，失败时返回false
     */
    protected static function convertCallable(array $callbacks, string $type): array|bool
    {
        try {
            $callback = array_values($callbacks);
            if (count($callback) >= 2 && 
                is_string($callback[0]) && 
                class_exists($callback[0]) && 
                method_exists($callback[0], $callback[1])) {
                return [static::instance($callback[0]), $callback[1]];
            }
        } catch (\Throwable $e) {
            $path = $e->getFile();
            $msg = sprintf('[%s:%d] %s', $path, $e->getLine(), $e->getMessage());
            
            if ($type == 'dispatch') {
                showExceptionMsg('shutdown', $msg);
            }
            
            Log::input($msg, -1, C('LOGPATH') . 'event-' . date('Y-m-d') .'.log');
        }
        return false;
    }
    
    /**
     * 注册事件监听器（运行时注册，推荐入口）
     *
     * 与配置文件 Source/Inc/Event.inc.php 中的监听器共存；配置文件里的优先级视为 0。
     * 事件名以「.*」结尾时按前缀通配符注册（如 ticket.* 会匹配 ticket.assigned）。
     *
     * @param string $event_name 事件名，如 ticket.assigned；或前缀通配 ticket.*
     * @param mixed  $listener   Closure / 'Class::method' / [类名,方法名] / [对象,方法名] / 类名（调 ->run()）
     * @param int    $priority   优先级，数字越大越先执行，默认 0
     * @return void
     */
    public static function on(string $event_name, mixed $listener, int $priority = 0): void
    {
        $cb = static::normalizeListener($listener, 'on');
        if ($cb === null) {
            return;
        }

        // 前缀通配符：ticket.*
        if (substr($event_name, -2) === '.*') {
            $prefix = substr($event_name, 0, -2);
            static::$prefixEventMap[$prefix][static::nextId($priority)] = $cb;
            return;
        }

        // 从配置初始化该事件（若尚未初始化），再追加运行时监听器
        if (isset(static::$eventMap[$event_name]) === false) {
            static::getEvents($event_name, 'on');
            if (isset(static::$eventMap[$event_name]) === false) {
                static::$eventMap[$event_name] = [];
            }
        }

        static::$eventMap[$event_name][static::nextId($priority)] = $cb;
    }

    /**
     * 注册一次性事件监听器
     *
     * 第一次触发后自动移除，不会被执行第二次。
     *
     * @param string $event_name 事件名
     * @param mixed  $listener   监听器
     * @param int    $priority   优先级
     * @return void
     */
    public static function once(string $event_name, mixed $listener, int $priority = 0): void
    {
        $target = static::normalizeListener($listener, 'once');
        if ($target === null) {
            return;
        }

        $wrapper = null;
        $wrapper = static function (...$args) use ($event_name, $target, &$wrapper) {
            // 注册进映射表的是 $wrapper 本身，必须移除它（不是 $target，否则永远移不掉）
            static::off($event_name, $wrapper);
            return $target(...$args);
        };

        static::on($event_name, $wrapper, $priority);
    }

    /**
     * 移除事件监听器
     *
     * @param string $event_name 事件名
     * @param mixed  $listener   要移除的监听器；传 null 表示移除该事件的全部监听器
     * @return void
     */
    public static function off(string $event_name, mixed $listener = null): void
    {
        $target = ($listener === null) ? null : static::normalizeListener($listener, 'off');

        // 精确事件
        if (isset(static::$eventMap[$event_name])) {
            if ($target === null) {
                static::$eventMap[$event_name] = [];
            } else {
                foreach (static::$eventMap[$event_name] as $id => $cb) {
                    if ($cb === $target) {
                        unset(static::$eventMap[$event_name][$id]);
                    }
                }
            }
        }

        // 前缀通配符：'ticket.*' → 前缀 'ticket'
        $prefix = (substr($event_name, -2) === '.*') ? substr($event_name, 0, -2) : $event_name;
        if (isset(static::$prefixEventMap[$prefix])) {
            if ($target === null) {
                static::$prefixEventMap[$prefix] = [];
            } else {
                foreach (static::$prefixEventMap[$prefix] as $id => $cb) {
                    if ($cb === $target) {
                        unset(static::$prefixEventMap[$prefix][$id]);
                    }
                }
            }
        }
    }

    /**
     * 生成监听器排序键（优先级 + 注册顺序）
     *
     * ksort 升序执行，故把优先级取负后放高位；低位是自增序号保证同优先级按注册先后。
     *
     * @param int $priority 优先级
     * @return int 排序键
     */
    private static function nextId(int $priority = 0): int
    {
        return (-$priority) * 1000000 + (++self::$id);
    }

    /**
     * 统一监听器写法为可调用结构
     *
     * 支持：Closure / 'Class::method' / [类名,方法名] / [对象,方法名] / 纯类名（调 ->run()）/ 函数名
     *
     * @param mixed  $listener 监听器
     * @param string $type     来源（仅用于日志）
     * @return callable|null
     */
    private static function normalizeListener(mixed $listener, string $type = 'on'): callable|null
    {
        if ($listener instanceof \Closure) {
            return $listener;
        }

        if (is_string($listener)) {
            if (strpos($listener, '::') !== false) {
                return $listener;
            }
            if (class_exists($listener)) {
                return [static::instance($listener), 'run'];
            }
            if (function_exists($listener)) {
                return $listener;
            }
            Log::input('[' . $type . '] 监听器无法解析: ' . $listener, -1, C('LOGPATH') . 'event-' . date('Y-m-d') . '.log');
            return null;
        }

        if (is_array($listener) && count($listener) >= 2) {
            $class  = $listener[0];
            $method = (string) $listener[1];

            if (is_object($class)) {
                return [$class, $method];
            }
            if (is_string($class) && class_exists($class) && method_exists($class, $method)) {
                return [static::instance($class), $method];
            }
            Log::input('[' . $type . '] 监听器类/方法不存在: ' . (is_string($class) ? $class : gettype($class)) . '::' . $method, -1, C('LOGPATH') . 'event-' . date('Y-m-d') . '.log');
            return null;
        }

        return is_callable($listener) ? $listener : null;
    }

    /**
     * 监听器实例缓存（同一类只 new 一次）
     *
     * @param string $class 类名
     * @return object 实例
     */
    private static function instance(string $class): object
    {
        return self::$instances[$class] ??= new $class();
    }

    /**
     * 清除事件缓存
     * 
     * 重置所有与事件相关的静态变量，包括事件配置、事件映射和前缀事件映射。
     * 这将强制系统重新从配置文件加载事件定义，适用于需要刷新事件配置的场景。
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        self::$eventConfig = null;
        self::$eventMap = [];
        self::$prefixEventMap = [];
        self::$instances = [];
        self::$id = 0;
    }
}
