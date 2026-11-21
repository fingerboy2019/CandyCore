# Node 类

## 概述

*
 * Node类 - Candy框架权限节点管理系统
 * 
 * 用于管理框架中的权限节点、菜单节点和访问控制，提供节点验证、菜单生成、权限检查等核心功能。
 * 支持应用(APP)和插件(Addon)的权限节点统一管理，具备缓存机制提高性能。
 * 
 * 主要功能：
 * 1. 节点访问路径处理与标准化
 * 2. 权限验证与访问控制
 * 3. 菜单节点生成与管理
 * 4. 权限节点列表生成与缓存
 * 5. 用户权限应用与过滤
 * 6. APP和Addon节点统一管理
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com) $
 * @date 2019-12-05 16:03:32 $ 
 * @final 该类不允许被继承

## 主要方法


### full()


### current()


### checkAuth()


### getMethodsData()


### setMethodsData()


### saveMethodList()


### getAppMenuList()


### getMenuList()


### getAllNodeList()


### getAllAuthList()

