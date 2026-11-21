# Unknown 类

## 概述

* Candy框架自动加载类
 * 
 * Autoload类负责框架的自动加载机制，采用final修饰禁止继承
 * 实现了PHP的spl_autoload_register接口，支持多种命名空间的类文件自动加载
 * 包含缓存机制以提升加载性能，兼容Composer自动加载
 * 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-25 10:00:00
 * @package Candy\Core
 * @final 禁止继承此类

## 主要方法


### loading()


### importClass()

