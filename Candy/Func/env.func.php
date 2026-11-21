<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 获取环境变量配置值
 * 
 * 从.env配置文件中获取指定的环境变量值。支持获取单个变量值或所有变量，
 * 当变量不存在时可返回默认值。
 * 
 * @param string|int|null $key 要获取的环境变量键名，为null时返回所有环境变量
 * @param mixed $default 当环境变量不存在时返回的默认值，默认为空字符串
 * @return mixed 获取到的环境变量值或默认值
 * 
 * @example
 * ```php
 * // 获取单个环境变量
 * $debug = envGet('APP_DEBUG');
 * 
 * // 获取带默认值的环境变量
 * $port = envGet('DB_PORT', 3306);
 * 
 * // 获取配置组中的值
 * $host = envGet('database.host');
 * 
 * // 获取所有环境变量
 * $allEnvs = envGet();
 * ```
 */
function envGet(mixed $key = null, mixed $default = ''): mixed
{
	return N('Env')->get($key, $default);
}

/**
 * 设置环境变量配置
 * 
 * 向.env配置文件中设置环境变量值。支持设置单个变量或多个变量，
 * 自动处理配置组结构，并支持特殊值类型（null、true、false）的正确存储。
 * 
 * @param array|string|int $key 环境变量键名，可以是单个键名或键值对数组
 * @param mixed $val 要设置的值，当key为数组时忽略此参数
 * @return int 成功设置的环境变量数量
 * 
 * @example
 * ```php
 * // 设置单个环境变量
 * $count = envSet('APP_DEBUG', true);
 * 
 * // 设置多个环境变量
 * $count = envSet([
 *     'DB_HOST' => 'localhost',
 *     'DB_PORT' => 3306,
 *     'DB_NAME' => 'example_db'
 * ]);
 * 
 * // 设置配置组中的值
 * $count = envSet('database.user', 'admin');
 * ```
 */
function envSet(mixed $key, mixed $val = ""): int
{
	return N('Env')->set($key, $val);
}

/**
 * 删除环境变量配置
 * 
 * 从.env配置文件中删除指定的环境变量或环境变量组。支持删除单个变量或多个变量，
 * 并可选择是否在组为空时删除整个组。
 * 
 * @param array|string|int $key 要删除的环境变量键名，可以是单个键名或键名数组
 * @param bool $delGroup 当环境变量组为空时是否删除整个组，默认为true
 * @return int 成功删除的环境变量数量
 * 
 * @example
 * ```php
 * // 删除单个环境变量
 * $count = envDel('APP_DEBUG');
 * 
 * // 删除多个环境变量
 * $count = envDel(['DB_HOST', 'DB_PORT']);
 * 
 * // 删除配置组中特定项但保留组
 * $count = envDel('database.host', false);
 * ```
 */
function envDel(mixed $key, bool $delGroup = true): int
{
	return N('Env')->del($key, $delGroup);
}
