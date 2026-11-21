<?php
/**
 * Candy框架全局函数库引导器
 *
 * 按 Tier 固定顺序加载各域 *.func.php；冷门函数见 lazy.func.php + FuncLoader。
 *
 * @author     刘森 <fingerboy@qq.com>
 * @date       2020-02-03 11:31:47
 */

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

// Tier 0：Autoload / Bootstrap 链依赖
foreach (['kernel', 'file', 'bootstrap'] as $name) {
	require __DIR__ . "/{$name}.func.php";
}

// Tier 1：请求生命周期常用
foreach ([
	'config', 'cache', 'di', 'tenant', 'request', 'url',
	'auth', 'event', 'env', 'response', 'debug',
] as $name) {
	require __DIR__ . "/{$name}.func.php";
}

// 懒加载 stub（实现见 archive / network / misc，由 FuncLoader match 加载）
require __DIR__ . '/lazy.func.php';

// Tier 2a：高频小工具（同步加载）
foreach (['lang', 'dict', 'custom_field', 'string', 'app', 'schedule', 'collection'] as $name) {
	require __DIR__ . "/{$name}.func.php";
}
