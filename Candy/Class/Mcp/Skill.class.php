<?php
/***
 * 技能（Skill）抽象 — 框架层技能系统
 *
 * 业务说明：把"一组工具 + 一段系统提示 + 权限声明"封装为可动态装配的单元，
 * 供 AskAgentRuntime 按任务意图加载，替代"一次性塞全部工具"。
 * 向后兼容：不改变现有 ToolInterface/Registry 单工具路径。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-01 11:20:00 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface Skill
{
	/**
	 * 技能唯一名（如 "db_query"、"knowledge"、"system"）
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * 技能用途描述，供 SkillLoader / 协调器判断何时加载
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * 加载时注入系统提示的片段（可空）
	 *
	 * @return string|null
	 */
	public function systemPrompt(): ?string;

	/**
	 * 该技能暴露的工具名集合（须先在 Registry 注册）
	 *
	 * @return list<string>
	 */
	public function toolNames(): array;

	/**
	 * 技能级权限声明
	 *
	 * 常用键：
	 * - requires_grant:bool 是否要求数据库授权作用域（与 AskGrantBinder tid 校验衔接）
	 * - risk:string       技能整体风险分类（info|medium|high），缺省随工具
	 *
	 * @return array<string,mixed>
	 */
	public function capabilities(): array;
}
