# App 类

## 概述

* Candy框架 核心应用类
 * 
 * App类是Candy框架的核心控制类，负责整个框架的初始化、运行流程控制和资源管理。
 * 作为框架的中心控制器，它协调各组件工作，处理请求路由，管理类自动加载，并确保
 * 应用的正确初始化与执行。
 * 
 * 此类被定义为final，禁止被继承，确保框架核心逻辑的稳定性和一致性。
 * 
 * 核心功能包括：
 * - 应用程序初始化和配置加载
 * - 路由解析和控制器分发
 * - 类自动加载和编译
 * - 会话管理和缓存控制
 * - 中间件处理和事件监听
 * - 长连接支持和WebSocket处理
 * 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @package Candy\Core
 * @final 禁止被继承

## 主要方法


### Init()


### wsRun()


### run()


### common()


### model()


### controler()


### getRootPath()


### getBasePath()

