<?php
/***
 * CandyDoc 文档中心入口文件
 * 用于项目创建后查看文档
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2024-01-01 12:00:00 $   
 */

declare(strict_types=1);

// 引入框架核心
require dirname(str_replace("\\", DIRECTORY_SEPARATOR, __DIR__)) . DIRECTORY_SEPARATOR . 'Candy/Autoload.php';

// 启动文档中心应用
N('App')::run(true);