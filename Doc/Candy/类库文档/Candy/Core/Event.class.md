# Event 类

## 概述

* Candy框架 Event事件处理类
 * 
 * 事件处理系统核心类，负责管理和分发系统中的各类事件，实现观察者模式。
 * 支持精确事件和通配符事件监听，提供灵活的事件注册与触发机制。
 * 实现了事件回调的自动解析和执行，以及事件响应的收集和处理。
 * 
 * 主要功能：
 * - 事件注册与触发机制
 * - 支持通配符事件监听（如：class.*）
 * - 事件回调自动解析与执行
 * - 事件响应收集与处理
 * - 事件缓存管理与清理
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @final 该类不允许被继承

## 主要方法


### emit()


### dispatch()


### list()


### getListeners()


### hasListener()


### clearCache()

