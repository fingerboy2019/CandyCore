<?php
/***
 * 技能装载器（SkillLoader）
 *
 * 业务说明：根据任务输入从已注册技能中选择应加载的子集，合并其工具与系统提示。
 * 阶段定位：本实现不做自然语言意图建模——中文/模糊输入全量回退（安全且可用）；
 * 精确路由由技能名锚点触发，智能意图路由留待 P2 协调器（SkillCoordinator）。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-01 11:40:00 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SkillLoader
{
	/** @var array<string, Skill> */
	private array $skills = [];

	/**
	 * @param array<string, Skill> $skills 备选技能（key = 技能名）
	 */
	public function __construct(array $skills = [])
	{
		foreach ($skills as $name => $skill) {
			if ($skill instanceof Skill) {
				$this->skills[(string)$name] = $skill;
			}
		}
	}

	/**
	 * 追加技能
	 *
	 * @param Skill $skill
	 * @return void
	 */
	public function add(Skill $skill): void
	{
		$this->skills[$skill->name()] = $skill;
	}

	/**
	 * 全部候选技能
	 *
	 * @return array<string, Skill>
	 */
	public function all(): array
	{
		return $this->skills;
	}

	/**
	 * 按任务输入选择技能子集
	 *
	 * 匹配规则（从宽松到保守）：
	 * 1. 输入小写化后，包含某技能名 → 命中该技能；
	 * 2. 输入包含描述中的"英文标识/空格词"（as-is 小写）→ 命中；
	 * 3. 以上均未命中 → 全部回退（技能集非空时），保证可用性。
	 *
	 * @param string $input 任务输入（用户消息 / intent 词）
	 * @return array<string, Skill>
	 */
	public function select(string $input): array
	{
		if ($this->skills === []) {
			return [];
		}
		$haystack = mb_strtolower(trim($input));
		if ($haystack === '') {
			return $this->skills;
		}

		$hit = [];
		foreach ($this->skills as $name => $skill) {
			// 锚点：技能名（唯一、小写、适合精确包含）
			if ($name !== '' && str_contains($haystack, mb_strtolower($name))) {
				$hit[$name] = $skill;
				continue;
			}
			// 描述中的英文/ASCII 词（含空格分隔），对标识符类描述可精确命中
			foreach ($this->asciiTerms($skill->description()) as $term) {
				if ($term !== '' && str_contains($haystack, $term)) {
					$hit[$name] = $skill;
					break;
				}
			}
		}

		return $hit !== [] ? $hit : $this->skills;
	}

	/**
	 * 抽取描述中的 ASCII 词（英文/数字），用于精确锚点
	 *
	 * @param string $description
	 * @return list<string>
	 * @private
	 */
	private function asciiTerms(string $description): array
	{
		preg_match_all('/[A-Za-z0-9_\-]{2,}/', $description, $m);
		$out = [];
		foreach ($m[0] as $w) {
			$out[mb_strtolower($w)] = true;
		}

		return array_keys($out);
	}

	/**
	 * 合并选中技能的系统提示片段（用换行拼接）
	 *
	 * @param array<string, Skill> $selected
	 * @return string 追加到主 system prompt 的片段（可为空串）
	 */
	public function combinedSystemPrompt(array $selected): string
	{
		$parts = [];
		foreach ($selected as $skill) {
			$sp = $skill->systemPrompt();
			if ($sp !== null && $sp !== '') {
				$parts[] = trim($sp);
			}
		}

		return $parts === [] ? '' : "\n" . implode("\n", $parts);
	}

	/**
	 * 汇总选中技能声明的工具名（去重）
	 *
	 * @param array<string, Skill> $selected
	 * @return list<string>
	 */
	public function collectedToolNames(array $selected): array
	{
		$names = [];
		foreach ($selected as $skill) {
			foreach ($skill->toolNames() as $n) {
				$names[$n] = true;
			}
		}

		return array_keys($names);
	}
}
