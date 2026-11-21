<?php
/***
 * IP 地址工具门面
 *
 * 业务说明：收敛校验/公私网判断/客户端 IP/CIDR；底层委托 Network\Client，避免多处复制
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 02:12:00 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);

namespace Candy\Extend;

use Candy\Extend\Network\Client;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Ip
{
	/**
	 * 获取当前客户端 IP
	 *
	 * 委托 Client::getClientIp；$adv=true 时在可信代理下解析转发头
	 *
	 * @param bool $adv 是否解析代理头
	 * @return string
	 * @access public
	 * @static
	 */
	public static function get(bool $adv = true): string
	{
		return Client::getClientIp(0, $adv);
	}

	/**
	 * 规范化并校验 IP（非法返回空串）
	 *
	 * @param string $ip 原始 IP
	 * @return string 合法 IP 或 ''
	 * @access public
	 * @static
	 */
	public static function normalize(string $ip): string
	{
		return Client::normalizeIp($ip);
	}

	/**
	 * 是否合法 IPv4/IPv6
	 *
	 * @param string $ip IP
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isValid(string $ip): bool
	{
		return Client::normalizeIp($ip) !== '';
	}

	/**
	 * 是否私网 / 环回 / 保留地址
	 *
	 * @param string $ip IP
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isPrivate(string $ip): bool
	{
		return Client::isPrivateIp($ip);
	}

	/**
	 * 是否公网地址（合法且非私网）
	 *
	 * @param string $ip IP
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isPublic(string $ip): bool
	{
		return self::isValid($ip) && !Client::isPrivateIp($ip);
	}

	/**
	 * IP 类型：public / private / invalid
	 *
	 * @param string $ip IP
	 * @return string
	 * @access public
	 * @static
	 */
	public static function getType(string $ip): string
	{
		if (!self::isValid($ip)) {
			return 'invalid';
		}
		return Client::isPrivateIp($ip) ? 'private' : 'public';
	}

	/**
	 * 是否落在 CIDR / 通配 / 精确范围
	 *
	 * @param string $ip 待测 IP
	 * @param string $range 范围表达式
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function inRange(string $ip, string $range): bool
	{
		return Client::ipInRange($ip, $range);
	}
}

