<?php
/***
 * Rpc 本地查询类型常量
 *
 * 业务说明：B 无库时返回查询方案，A 用 QueryRunner 在本地执行 find/select/count
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:54:32 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Query 类型
 */
final class Query
{
	public const FIND   = 'find';
	public const SELECT = 'select';
	public const COUNT  = 'count';

	/** @var list<string> */
	public const ALL = [self::FIND, self::SELECT, self::COUNT];

	/**
	 * @param string $op 查询类型
	 * @return bool
	 */
	public static function isValid(string $op): bool
	{
		return in_array($op, self::ALL, true);
	}
}

