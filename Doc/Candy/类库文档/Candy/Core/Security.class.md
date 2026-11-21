# Unknown 类

## 概述

* Candy框架安全防护核心类
 * 
 * 该类提供了完整的安全防护功能，包括：
 * - CSRF令牌生成和验证
 * - 请求参数过滤（XSS防护）
 * - SQL注入防护
 * - IP黑白名单管理
 * - 中间件机制
 * - 攻击日志记录
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-27 11:31:47
 * 非 final：项目中间件可 extends Security

## 主要方法


### run()


### generateCsrfToken()


### verifyCsrfToken()


### runMiddlewares()


### _delTokenName()


### _setTokenName()


### _removeXss()


### IPList()


### add()


### delete()

