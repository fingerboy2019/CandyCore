<?php
/***
 * 数据库脚本执行器
 *
 * 业务说明：为脚手架与迁移工具统一提供带方言驱动的脚本拆分、过滤与执行边界
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 16:30:00 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SqlScriptExecutor
{
	/**
	 * 按 SQL 字符串边界拆分脚本并过滤导入控制语句。
	 *
	 * 仅在单引号、双引号与注释之外识别分号；MySQL 版本注释以可执行语句保留，
	 * 普通注释、SET 与 USE 导入控制语句会被跳过。
	 *
	 * @param string $script SQL 脚本原文
	 * @return array<int, string> 可执行语句
	 */
	public static function split(string $script): array
	{
		$statements = [];
		$buffer = '';
		$inString = false;
		$stringChar = '';
		$commentType = '';
		$preserveBlockComment = false;
		$length = strlen($script);

		for ($index = 0; $index < $length; $index++) {
			$char = $script[$index];
			$next = $index + 1 < $length ? $script[$index + 1] : '';

			if ($commentType === 'line') {
				if ($char === "\n") {
					$commentType = '';
					$buffer .= "\n";
				}
				continue;
			}

			if ($commentType === 'block') {
				if ($char === '*' && $next === '/') {
					if ($preserveBlockComment) {
						$buffer .= '*/';
					}
					$index++;
					$commentType = '';
					$preserveBlockComment = false;
					continue;
				}
				if ($preserveBlockComment) {
					$buffer .= $char;
				}
				continue;
			}

			if ($inString) {
				$buffer .= $char;
				if ($char === $stringChar && ($index === 0 || $script[$index - 1] !== '\\')) {
					if ($next === $stringChar) {
						$buffer .= $next;
						$index++;
						continue;
					}
					$inString = false;
				}
				continue;
			}

			if ($char === "'" || $char === '"') {
				$buffer .= $char;
				$inString = true;
				$stringChar = $char;
				continue;
			}

			if (($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($script[$index + 2]))) || $char === '#') {
				$commentType = 'line';
				if ($char === '-') {
					$index++;
				}
				continue;
			}

			if ($char === '/' && $next === '*') {
				$preserveBlockComment = $index + 2 < $length && $script[$index + 2] === '!';
				$commentType = 'block';
				if ($preserveBlockComment) {
					$buffer .= '/*!';
					$index += 2;
				} else {
					if ($buffer !== '' && !ctype_space($buffer[strlen($buffer) - 1])) {
						$buffer .= ' ';
					}
					$index++;
				}
				continue;
			}

			$buffer .= $char;

			if ($char !== ';') {
				continue;
			}

			$statement = trim($buffer);
			$buffer = '';
			if (self::isExecutable($statement)) {
				$statements[] = $statement;
			}
		}

		$tail = trim($buffer);
		if (self::isExecutable($tail)) {
			$statements[] = $tail;
		}

		return $statements;
	}

	/**
	 * 使用指定数据库驱动逐句执行脚本。
	 *
	 * 驱动返回 false 时抛出异常，避免脚手架在部分建表失败后继续写入安装标记。
	 *
	 * @param object $db 框架数据库驱动
	 * @param iterable<int, string> $statements SQL 语句集合
	 * @return void
	 */
	public static function execute(object $db, iterable $statements): void
	{
		foreach ($statements as $statement) {
			$statement = trim((string) $statement);
			if ($statement === '') {
				continue;
			}

			if ($db->query($statement, 'other') === false) {
				throw new \RuntimeException('数据库脚本执行失败：' . self::preview($statement));
			}
		}
	}

	/**
	 * 判断脚本片段是否为可执行语句。
	 *
	 * @param string $statement 脚本片段
	 * @return bool 是否执行
	 */
	private static function isExecutable(string $statement): bool
	{
		if ($statement === '' || str_starts_with($statement, '--')) {
			return false;
		}
		if (str_starts_with($statement, '/*') && !str_starts_with($statement, '/*!')) {
			return false;
		}

		return !preg_match('/^(SET|USE)\s/i', $statement);
	}

	/**
	 * 截断脚本片段，避免异常消息携带完整大段数据。
	 *
	 * @param string $statement SQL 语句
	 * @return string 截断后的 SQL
	 */
	private static function preview(string $statement): string
	{
		return strlen($statement) > 160 ? substr($statement, 0, 160) . '…' : $statement;
	}
}
