<?php
/***
 * MCP 查数结果脱敏
 *
 * 业务说明：按内置敏感列名 + db_query.deny_columns 掩码单元格
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:32:59 $
 *
 * @package Candy\Extend\Mcp\QueryForm
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\QueryForm;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Mask
{
	/** @var list<string> */
	private const SENSITIVE = [
		'password', 'passwd', 'userpass', 'userlockpass', 'lockpass', 'pwd', 'secret', 'token', 'salt',
		'api_key', 'apikey', 'private_key', 'access_token', 'refresh_token', 'auth_code',
	];

	/**
	 * 脱敏多行
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	public static function rows(array $rows): array
	{
		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$out[] = self::row($row);
		}

		return $out;
	}

	/**
	 * 脱敏一行
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function row(array $row): array
	{
		$deny = self::SENSITIVE;
		$extra = Config::dbQuery()['deny_columns'];
		foreach ($extra as $c) {
			$deny[] = $c;
		}

		$out = [];
		foreach ($row as $k => $v) {
			$key = (string) $k;
			$lower = strtolower($key);
			if (str_contains($lower, '.')) {
				$parts = explode('.', $lower);
				$lower = (string) end($parts);
			}
			$hit = false;
			foreach ($deny as $needle) {
				if ($lower === $needle || str_contains($lower, $needle) || str_ends_with($lower, 'pass')) {
					$hit = true;
					break;
				}
			}
			$out[$key] = $hit ? '***' : $v;
		}

		return $out;
	}
}
