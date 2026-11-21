<?php
/***
 * MCP Tool 注册表
 *
 * 业务说明：按白名单与风险分级持有已启用 Tool 实例；并支持技能（Skill）注册，
 * 供框架层技能系统把"一组工具 + 提示 + 权限"封装为可装配单元。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-01 11:25:00 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Registry
{
	/** @var array<string, ToolInterface> */
	private array $tools = [];

	/** @var array<string, Skill> */
	private array $skills = [];

	/**
	 * 注册 Tool（同名覆盖）
	 *
	 * @param ToolInterface $tool Tool 实例
	 * @return void
	 */
	public function register(ToolInterface $tool): void
	{
		$this->tools[$tool->name()] = $tool;
	}

	/**
	 * 是否已注册 Tool
	 *
	 * @param string $name Tool 名
	 * @return bool
	 */
	public function has(string $name): bool
	{
		return isset($this->tools[$name]);
	}

	/**
	 * 取单个 Tool
	 *
	 * @param string $name Tool 名
	 * @return ToolInterface|null
	 */
	public function get(string $name): ?ToolInterface
	{
		return $this->tools[$name] ?? null;
	}

	/**
	 * 全部 Tool（按名称排序）
	 *
	 * @return array<string, ToolInterface>
	 */
	public function all(): array
	{
		$tools = $this->tools;
		ksort($tools);

		return $tools;
	}

	/**
	 * 转为 MCP tools/list 条目
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listDefinitions(): array
	{
		$out = [];
		foreach ($this->all() as $tool) {
			$item = [
				'name'        => $tool->name(),
				'description' => $tool->description(),
				'inputSchema' => $tool->inputSchema(),
			];
			// 仅声明了出参 Schema 时上报，避免空对象干扰旧客户端
			$output = $tool->outputSchema();
			if ($output !== null) {
				$item['outputSchema'] = $output;
			}
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * 注册 Skill（同名覆盖）
	 *
	 * @param Skill $skill 技能实例
	 * @return void
	 */
	public function registerSkill(Skill $skill): void
	{
		$this->skills[$skill->name()] = $skill;
	}

	/**
	 * 全部 Skill（按名称排序）
	 *
	 * @return array<string, Skill>
	 */
	public function skills(): array
	{
		$skills = $this->skills;
		ksort($skills);

		return $skills;
	}

	/**
	 * 取单个 Skill
	 *
	 * @param string $name 技能名
	 * @return Skill|null
	 */
	public function skillFor(string $name): ?Skill
	{
		return $this->skills[$name] ?? null;
	}

	/**
	 * 取技能声明的工具定义（仅返回已注册且被该技能引用的工具）
	 *
	 * @param string $name 技能名
	 * @return array<string, ToolInterface>
	 */
	public function toolsFor(string $name): array
	{
		$skill = $this->skills[$name] ?? null;
		if ($skill === null) {
			return [];
		}
		$out = [];
		foreach ($skill->toolNames() as $toolName) {
			if (isset($this->tools[$toolName])) {
				$out[$toolName] = $this->tools[$toolName];
			}
		}

		return $out;
	}
}
