# 行为类名 类

## 概述

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

## 主要方法


### getEnableHooks()


### getHooks()


### processHookCallback()


### alias()


### add()


### import()


### get()


### listen()


### exec()


### clearCache()

