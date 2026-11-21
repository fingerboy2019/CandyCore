<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 事件监听器触发函数
 * 
 * 触发指定标签的事件监听器，执行注册的钩子函数，并返回处理结果。
 * 支持传递参数给监听器，并可选择是否只执行一次。
 * 
 * @param string $tag 事件标签名，用于标识要触发的事件
 * @param mixed $params 传递给监听器的参数，默认为null
 * @param bool $once 是否仅执行一次监听器，默认为false
 * @return mixed 监听器的执行结果，如果标签未注册则返回false
 * 
 * @example
 * ```php
 * // 触发页面渲染前的事件
 * listen('app_begin', ['controller' => $controller]);
 * 
 * // 触发只执行一次的事件
 * listen('data_init', ['user_id' => 1], true);
 * ```
 */
function listen($tag, $params = null, $once = false): mixed
{
	if(is_null(C('TAGS_'.strtoupper($tag)))) return false;
	return \Candy\Core\Hook::listen($tag, $params, $once);
}

/**
 * 执行事件方法
 *
 * 该函数是框架事件系统的调度入口，用于触发指定名称的事件并传递相关数据。
 * 事件系统采用观察者模式，允许多个监听器响应同一个事件。
 *
 * 使用示例：
 * ```php
 * // 触发用户登录事件并传递用户数据
 * $result = dispatch('user_login', ['user_id' => 1, 'username' => 'admin']);
 * 
 * // 触发系统初始化事件
 * dispatch('system_init', []);
 * ```
 *
 * @param string $eventName 事件名称，用于标识要触发的事件
 * @param mixed $data 传递给事件监听器的数据，可以是任意类型
 * @return array|null 事件监听器的返回结果数组，无监听器时返回null
 */
function dispatch(string $eventName,mixed $data): mixed
{
	return \Candy\Core\Event::dispatch($eventName, $data);
}

/**
 * 安全执行事件方法（忽略错误）
 *
 * 该函数是框架事件系统的安全执行入口，用于触发指定名称的事件并传递相关数据，
 * 与dispatch函数不同，emit函数会忽略事件执行过程中产生的错误，确保程序继续执行。
 * 采用观察者模式，允许多个监听器响应同一个事件。
 *
 * 使用示例：
 * ```php
 * // 安全触发用户登录事件，忽略可能出现的错误
 * $result = emit('user_login', ['user_id' => 1, 'username' => 'admin']);
 * 
 * // 安全触发系统初始化事件
 * emit('system_init', []);
 * ```
 *
 * @param string $eventName 事件名称，用于标识要触发的事件
 * @param mixed $data 传递给事件监听器的数据，可以是任意类型
 * @return array|null 事件监听器的返回结果数组，无监听器时返回null，发生错误时可能返回null
 */
function emit(string $eventName,mixed $data): mixed
{
	return \Candy\Core\Event::emit($eventName, $data);
}
