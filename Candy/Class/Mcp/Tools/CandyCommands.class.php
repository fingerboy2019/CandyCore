<?php
/***
 * MCP Tool：列出 Candy CLI 命令（只读）
 *
 * 业务说明：合并框架默认命令与 Command.inc / StandardCommand.inc 注册表
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 20:36:57 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\ToolInterface;
use Console\Command;
use Console\Initiator;
use ReflectionClass;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class CandyCommands implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'candy_commands';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'List registered php candy CLI commands (name + description).';
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
			'properties' => [
				'filter' => [
					'type'        => 'string',
					'description' => 'Optional substring filter on command name',
				],
			],
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
		$filter = isset($args['filter']) ? trim((string) $args['filter']) : '';
		$map = $this->collectCommandMap();
		$rows = [];

		foreach ($map as $name => $handler) {
			if ($filter !== '' && !str_contains($name, $filter)) {
				continue;
			}
			$rows[] = [
				'name'        => $name,
				'description' => $this->resolveDescription($handler),
				'source'      => $this->resolveSource($handler),
			];
		}

		usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

		return [
			'ok'   => true,
			'data' => [
				'total'    => count($rows),
				'commands' => $rows,
			],
		];
	}

	/**
	 * 合并框架默认 + 项目 Command / StandardCommand
	 *
	 * @return array<string, mixed>
	 */
	private function collectCommandMap(): array
	{
		$defaults = [];
		try {
			$ref = new ReflectionClass(Initiator::class);
			$props = $ref->getDefaultProperties();
			if (isset($props['defaultCommands']) && is_array($props['defaultCommands'])) {
				$defaults = $props['defaultCommands'];
			}
		} catch (\Throwable) {
			$defaults = [];
		}

		$project = loadConfig('Command');
		$project = is_array($project) ? $project : [];

		$standard = loadConfig('StandardCommand');
		$standard = is_array($standard) ? $standard : [];

		return array_merge($defaults, $project, $standard);
	}

	/**
	 * 解析命令说明
	 *
	 * @param mixed $handler 类名或闭包
	 * @return string
	 */
	private function resolveDescription(mixed $handler): string
	{
		if ($handler instanceof \Closure) {
			return '(closure)';
		}
		if (!is_string($handler) || !class_exists($handler)) {
			return '';
		}
		try {
			$obj = new $handler();
			if ($obj instanceof Command) {
				return $obj->getDescription();
			}
		} catch (\Throwable) {
			return '';
		}

		return '';
	}

	/**
	 * 标记来源类型
	 *
	 * @param mixed $handler 类名或闭包
	 * @return string
	 */
	private function resolveSource(mixed $handler): string
	{
		if ($handler instanceof \Closure) {
			return 'standard';
		}
		if (!is_string($handler)) {
			return 'unknown';
		}
		if (str_starts_with($handler, 'APP\\Command\\')) {
			return 'project';
		}
		if (str_starts_with($handler, 'Console\\')) {
			return 'framework';
		}

		return 'other';
	}
}
