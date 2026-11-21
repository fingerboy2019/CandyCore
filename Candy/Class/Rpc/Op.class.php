<?php
/***
 * Rpc 本地写库操作类型常量
 *
 * 业务说明：B 返回 ops 清单，A 用 OpRunner 执行；仅允许 insert/update/delete
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:50:20 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Op 类型
 */
final class Op
{
	public const INSERT = 'insert';
	public const UPDATE = 'update';
	public const DELETE = 'delete';

	/** @var list<string> */
	public const ALL = [self::INSERT, self::UPDATE, self::DELETE];

	/**
	 * 是否合法 op 名
	 *
	 * @param string $op 操作名
	 * @return bool
	 */
	public static function isValid(string $op): bool
	{
		return in_array($op, self::ALL, true);
	}
}

