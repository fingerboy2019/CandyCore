<?php
/***
 * MCP Tool 调用审计
 *
 * 业务说明：记录 server/tool/参数摘要/耗时/成败；敏感键脱敏；日志走 stderr
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 20:29:43 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Audit
{
	/** @var list<string> 参数键名（小写）命中则脱敏 */
	private const SENSITIVE_KEYS = [
		'password', 'passwd', 'pwd', 'secret', 'token', 'dsn', 'authorization', 'api_key', 'apikey',
	];

	/**
	 * 写一条审计日志
	 *
	 * @param string               $server  server 名
	 * @param string               $tool    Tool 名
	 * @param array<string, mixed> $args    原始参数
	 * @param bool                 $ok      是否成功
	 * @param int                  $ms      耗时毫秒
	 * @param string               $detail  补充说明（错误或摘要）
	 * @return void
	 */
	public static function record(
		string $server,
		string $tool,
		array $args,
		bool $ok,
		int $ms,
		string $detail = ''
	): void {
		$payload = [
			'ts'     => date('c'),
			'server' => $server,
			'tool'   => $tool,
			'ok'     => $ok ? 1 : 0,
			'ms'     => $ms,
			'args'   => self::summarizeArgs($args),
		];
		if ($detail !== '') {
			$payload['detail'] = self::truncate($detail, 200);
		}

		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		Bootstrap::log('audit ' . ($json !== false ? $json : '{}'));
	}

	/**
	 * 参数摘要（脱敏 + 截断）
	 *
	 * @param array<string, mixed> $args 原始参数
	 * @return array<string, mixed>
	 */
	public static function summarizeArgs(array $args): array
	{
		$out = [];
		foreach ($args as $key => $value) {
			$k = (string) $key;
			if (self::isSensitiveKey($k)) {
				$out[$k] = '***';
				continue;
			}
			if (is_scalar($value) || $value === null) {
				$out[$k] = self::truncate((string) $value, 120);
				continue;
			}
			if (is_array($value)) {
				$out[$k] = '[array:' . count($value) . ']';
				continue;
			}
			$out[$k] = '[' . get_debug_type($value) . ']';
		}

		return $out;
	}

	/**
	 * 键名是否敏感
	 *
	 * @param string $key 参数名
	 * @return bool
	 */
	private static function isSensitiveKey(string $key): bool
	{
		$lower = strtolower($key);
		foreach (self::SENSITIVE_KEYS as $needle) {
			if ($lower === $needle || str_contains($lower, $needle)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 截断字符串
	 *
	 * @param string $text 原文
	 * @param int    $max  最大长度
	 * @return string
	 */
	private static function truncate(string $text, int $max): string
	{
		if (strlen($text) <= $max) {
			return $text;
		}

		return substr($text, 0, $max) . '…';
	}
}

