<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 检查用户是否具有指定节点的访问权限
 *
 * 该函数用于验证当前用户是否拥有对指定节点（如菜单、操作）的访问权限，
 * 支持自定义权限数组验证。
 * 主要用于权限控制和访问验证场景。
 *
 * 使用示例：
 * - 检查当前节点权限: checkAuth()
 * - 检查特定节点权限: checkAuth('admin/user')
 * - 使用自定义权限检查: checkAuth('admin/user', ['admin', 'editor'])
 *
 * @param string|null $node 要检查的节点标识，为null时检查当前节点，默认为null
 * @param array $auths 自定义权限数组，为空时使用当前用户权限，默认为空数组
 * @return bool 如果用户拥有访问权限返回true，否则返回false
 */
function checkAuth(?string $node = null,array $auths = []): bool
{
	return \Candy\Core\Node::checkAuth($node, $auths)[0];
}

/**
 * 应用并获取用户权限
 *
 * 该函数用于应用用户权限设置并获取当前用户的权限数组，
 * 支持传入自定义权限和强制刷新缓存。
 * 主要用于权限验证和访问控制场景。
 *
 * 使用示例：
 * - 获取当前用户权限: applyUserAuth()
 * - 使用自定义权限: applyUserAuth(['admin', 'user'])
 * - 强制刷新缓存: applyUserAuth([], true)
 *
 * @param array $auths 自定义的权限数组，为空时使用当前用户权限，默认为空数组
 * @param bool $force 是否强制刷新缓存，true表示不使用缓存而重新获取，默认为false
 * @return array 返回处理后的权限数组
 */
function applyUserAuth(array $auths = [],bool $force = false):array
{
	return \Candy\Core\Node::applyUserAuth($auths, $force);
}

/**
 * 添加IP到黑名单或白名单
 *
 * 该函数用于将IP地址添加到安全过滤的黑名单或白名单中，
 * 支持单个IP地址和IP地址范围的添加。
 *
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单，默认为'b'
 * @param string|array $ip IP地址或IP地址范围数组，范围数组格式为[开始IP, 结束IP]
 * @param int $expTime 过期时间（秒），默认为0（永不过期）
 * @return bool 操作是否成功
 */
function addIPList(string $type = 'b', mixed $ip = '',int $expTime = 0, string $scope = 'project'): bool
{
	$data['type'] = $type;
	$data['scope'] = $scope;
	if(is_array($ip)){
		[$data['ip_start'], $data['ip_end']] = $ip;
	}else{
		$data['ip_start'] = $ip;
	}
	// 原来 $expTime 被丢弃，导致「封 2 小时」实际是永久封禁
	if(!empty($expTime)){
		$data['exp_time'] = intval($expTime);
	}
	return \Candy\Core\Security::add($data);
}

/**
 * 删除IP黑、白名单
 *
 * @param $type	名单类型
 * @param $ip	IP地址
 */
function delIPList(string $type = 'b', mixed $ip = '', int $expTime = 0, string $scope = 'project'):bool
{
	$data['type'] = $type;
	$data['scope'] = $scope;
	$expTime > 0 && $data['exp_time'] = $expTime;
	if(is_array($ip)){
		[$data['ip_start'], $data['ip_end']] = $ip;
	}else{
		$data['ip_start'] = $ip;
	}
	return \Candy\Core\Security::delete($data);
}

/**
 * 检查IP是否在指定的黑名单或白名单中
 *
 * 该函数用于验证IP地址是否存在于系统的黑名单或白名单中，
 * 可用于IP访问控制和安全过滤。
 *
 * 使用示例：
 * - 检查IP是否在黑名单: checkIP('b', '192.168.1.1')
 * - 检查IP是否在白名单: checkIP('w', '192.168.1.1')
 * - 使用默认黑名单检查: checkIP('', '192.168.1.1')
 *
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单，默认为'b'
 * @param string $ip 要检查的IP地址，默认为空字符串（将使用当前访问者IP）
 * @return bool 如果IP在指定名单中返回true，否则返回false
 */
function checkIP(string $type = 'b',string $ip = '', string $scope = 'project'): bool
{
	return \Candy\Core\Security::check($type, $ip, $scope);
}

/**
 * 清除IP名单表中的记录
 *
 * 该函数用于清理系统中的IP黑名单或白名单记录，
 * 可选择性地清除特定类型的名单或全部名单。
 *
 * 使用示例：
 * - 清除所有黑名单: cleanIPList('b')
 * - 清除所有白名单: cleanIPList('w')
 * - 清除所有IP名单: cleanIPList()
 * - 重置并清除黑名单: cleanIPList('b', true)
 *
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单，空字符串表示所有类型，默认为空
 * @param bool $reset 是否重置标识，true表示重置（完全清除），false表示常规清除，默认为false
 * @return void 无返回值
 */
function cleanIPList(string $type = '',bool $reset = false, string $scope = ''): void
{
	\Candy\Core\Security::clean($type, $reset, $scope);
}

/**
 * 获取IP列表
 *
 * 该函数用于获取系统中配置的IP黑名单或白名单数据，
 * 通过调用Security类的getAll方法实现IP列表的统一管理。
 *
 * 使用示例：
 * - 获取所有黑名单IP: getIPList('b')
 * - 获取所有白名单IP: getIPList('w')
 *
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单，默认为'b'
 * @return array 返回IP列表数据
 */
function getIPList(string $type = 'b', string $scope = ''):array
{
	return \Candy\Core\Security::getAll($type, $scope);
}

/**
 * 安全过滤函数
 * 
 * 对字符串进行安全过滤，移除可能导致XSS攻击和其他安全问题的特殊字符。
 * 此函数会替换URL编码的空格、引号以及其他特殊字符，确保数据在展示或存储时更安全。
 * 
 * @param string $string 待过滤的字符串
 * @return string 过滤后的安全字符串
 * 
 * @example
 * ```php
 * // 过滤用户输入的表单数据
 * $safeUsername = safeReplace($_POST['username']);
 * 
 * // 过滤URL参数
 * $safeParam = safeReplace($_GET['param']);
 * ```
 * 
 * @note
 * 该函数会替换以下字符：
 * - URL编码的空格(%20)
 * - URL编码的单引号(%27和%2527)
 * - 星号(*)
 * - 双引号("")
 * - 单引号(')
 * - 分号(;)
 * - 小于号(<)
 * - 大于号(>)
 * - 花括号({})
 * - 反斜杠(\\)
 */
function safeReplace(string $string): string
{
	return str_replace(['%20','%27','%2527','*','"',"'",';','<','>',"{",'}','\\'],['','','','','','','','&lt;','&gt;','','',''],$string);
}
