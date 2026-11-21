# Str 类

## 概述

* Candy框架 字符串操作工具类
 * 
 * 该类提供了一系列静态方法，用于处理各种字符串操作，包括字符串判断、转换、格式化、随机生成等功能。
 * 所有方法都是静态的，无需实例化即可使用，同时类内部使用缓存机制优化性能。
 * 
 * 主要功能：
 * - 字符串包含、开头、结尾判断
 * - 随机字符串生成
 * - 大小写转换
 * - 字符串长度获取和截取
 * - 驼峰命名与下划线命名互相转换
 * - 唯一ID生成（数字和日期格式）
 * - 敏感词过滤
 * - 数据获取和配置生成辅助功能
 * - 数组到字符串的转换
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-03 14:00:00
 * @final 该类为最终类，不允许继承
 * @requires PHP 7.0+
 * @requires mbstring 扩展
 * 
 * @example
 * ```php
 * // 检查字符串包含
 * $result = Str::contains

## 主要方法


### contains()


### endsWith()


### startsWith()


### random()


### lower()


### upper()


### length()


### substr()


### snake()


### camel()

