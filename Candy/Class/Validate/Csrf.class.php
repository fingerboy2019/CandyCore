<?php
/***
 * CSRF 令牌工具
 *
 * 业务说明：从表单/请求中生成与校验 token；原 Core\Validate 令牌逻辑迁此
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 18:43:40 $
 *
 * @package Candy\Extend\Validate
 */

declare(strict_types=1);

namespace Candy\Extend\Validate;

use Candy\Core\Request;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * CSRF Token
 */
final class Csrf
{
	/**
	 * 生成令牌
	 *
	 * @return string
	 */
	public static function create(): string
	{
		$host = I('server.HTTP_HOST', 'candy', '');
		return \Candy\Extend\Mcrypt::code((string)$host);
	}

	/**
	 * 校验令牌
	 *
	 * 空 token 视为通过（与历史行为一致）
	 *
	 * @param string $token 提交的 token
	 * @return bool
	 */
	public static function check(string $token): bool
	{
		if ($token === '') {
			return true;
		}
		$lastkey = function_exists('session') ? session('CSRFTOKEN') : null;
		$key = \Candy\Extend\Mcrypt::code($token, '', 'de');
		$host = (string)I('server.HTTP_HOST', '', '');
		if ($lastkey != $token && $key !== null && $key !== '' && $key == $host) {
			if (function_exists('session')) {
				session('CSRFTOKEN', $token);
			}
			return true;
		}
		return false;
	}
}
