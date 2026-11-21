<?php
/***
 * MCP Tool：系统信息（只读）
 *
 * 业务说明：返回 PHP / Candy 路径 / DEBUG 等环境摘要
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 20:36:57 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\ToolInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SystemInfo implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'system_info';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Return Candy runtime summary: PHP version, SAPI, paths, DEBUG flag.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function risk(): string
	{
		return 'readonly';
	}

	/**
	 * {@inheritdoc}
	 */
	public function inputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => (object) [],
			'additionalProperties' => false,
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function outputSchema(): ?array
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function handle(array $args): array
	{
		$root = defined('CANDYROOT') ? (string) CANDYROOT : (string) C('CANDYROOT');
		$path = defined('CANDYPATH') ? (string) CANDYPATH : '';

		return [
			'ok'   => true,
			'data' => [
				'php_version'   => PHP_VERSION,
				'php_sapi'      => PHP_SAPI,
				'candy_version' => defined('CANDY_VERSION') ? CANDY_VERSION : null,
				'candy_root'    => rtrim($root, '/\\'),
				'candy_path'    => rtrim($path, '/\\'),
				'debug'         => defined('DEBUG') ? (int) DEBUG : null,
				'os'            => PHP_OS_FAMILY,
				'timezone'      => date_default_timezone_get(),
				'time'          => date('c'),
			],
		];
	}
}
