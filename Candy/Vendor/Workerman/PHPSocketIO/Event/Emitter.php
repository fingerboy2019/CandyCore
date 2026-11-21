<?php

namespace PHPSocketIO\Event;

use PHPSocketIO\Debug;

/**
 * 事件发射器类
 * 
 * 该类用于实现事件监听、触发和管理，是Socket.IO事件系统的核心组件。
 * 
 * @package PHPSocketIO\Event
 */
class Emitter
{
    /**
     * 事件监听器映射表
     * 
     * 格式：[event=>[[listener1, once?], [listener2,once?], ..], ..]
     * - event: 事件名称
     * - listener: 事件回调函数
     * - once: 是否只触发一次（0: 否, 1: 是）
     * 
     * @var array
     */
    protected $_eventListenerMap = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
        Debug::debug('Emitter __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Emitter __destruct');
    }

    /**
     * 添加事件监听器
     * 
     * @param string $event_name 事件名称
     * @param callable $listener 事件回调函数
     * @return Emitter 返回当前实例，支持链式调用
     */
    public function on($event_name, $listener): Emitter
    {
        $this->emit('newListener', $event_name, $listener);
        $this->_eventListenerMap[$event_name][] = [$listener, 0];
        return $this;
    }

    /**
     * 添加一次性事件监听器（只触发一次）
     * 
     * @param string $event_name 事件名称
     * @param callable $listener 事件回调函数
     * @return Emitter 返回当前实例，支持链式调用
     */
    public function once($event_name, $listener): Emitter
    {
        $this->_eventListenerMap[$event_name][] = [$listener, 1];
        return $this;
    }

    /**
     * 移除指定事件的指定监听器
     * 
     * @param string $event_name 事件名称
     * @param callable $listener 事件回调函数
     * @return Emitter 返回当前实例，支持链式调用
     */
    public function removeListener($event_name, $listener): Emitter
    {
        if (! isset($this->_eventListenerMap[$event_name])) {
            return $this;
        }
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            if ($item[0] === $listener) {
                $this->emit('removeListener', $event_name, $listener);
                unset($this->_eventListenerMap[$event_name][$key]);
            }
        }
        if (empty($this->_eventListenerMap[$event_name])) {
            unset($this->_eventListenerMap[$event_name]);
        }
        return $this;
    }

    /**
     * 移除指定事件的所有监听器或所有事件的所有监听器
     * 
     * @param string|null $event_name 事件名称，为null时移除所有事件的监听器
     * @return Emitter 返回当前实例，支持链式调用
     */
    public function removeAllListeners($event_name = null): Emitter
    {
        $this->emit('removeListener', $event_name);
        if (null === $event_name) {
            $this->_eventListenerMap = [];
            return $this;
        }
        unset($this->_eventListenerMap[$event_name]);
        return $this;
    }

    /**
     * 获取指定事件的所有监听器
     * 
     * @param string $event_name 事件名称
     * @return array 监听器数组
     */
    public function listeners($event_name): array
    {
        if (empty($this->_eventListenerMap[$event_name])) {
            return [];
        }
        $listeners = [];
        foreach ($this->_eventListenerMap[$event_name] as $item) {
            $listeners[] = $item[0];
        }
        return $listeners;
    }

    /**
     * 触发事件
     * 
     * @param string|null $event_name 事件名称
     * @param mixed ...$args 事件参数
     * @return bool 是否成功触发事件
     */
    public function emit($event_name = null)
    {
        if (empty($event_name) || empty($this->_eventListenerMap[$event_name])) {
            return false;
        }
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            $args = func_get_args();
            unset($args[0]);
            call_user_func_array($item[0], $args);
            // 检查是否为一次性监听器
            if ($item[1]) {
                unset($this->_eventListenerMap[$event_name][$key]);
                if (empty($this->_eventListenerMap[$event_name])) {
                    unset($this->_eventListenerMap[$event_name]);
                }
            }
        }
        return true;
    }
}
