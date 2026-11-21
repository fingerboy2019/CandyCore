<?php
/***
 * CandyCms 入口文件
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2019-07-24 23:27:52 $   
 */

//语法严格模式开启
declare(strict_types=1);

//单框架多项目
//defined('ALLINONE') OR define('ALLINONE', true);

//引入框架
require __DIR__ . '/Candy/Autoload.php';

//开启运行
N('App')::run();
