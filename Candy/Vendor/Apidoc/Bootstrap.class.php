<?php
/***
 * Apidoc 可选启用入口（包内）
 *
 * 业务说明：Hook 合并与 enable 开关；非框架 Extend，随 Vendor/Apidoc 分发
 * 启用：php candy apidoc:init
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:51:43 $
 *
 * @package Apidoc
 */

namespace Apidoc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 文档站可选挂载（默认不注册路由）
 */
final class Bootstrap
{
	/**
	 * 供 Application/tags.php 合并的 Hook 表
	 *
	 * @return array<string,list<string>>
	 */
	public static function hooks(): array
	{
		if (!self::enabled()) {
			return [];
		}

		return [
			'route_start' => [
				\Apidoc\providers\CandyService::class,
			],
		];
	}

	/**
	 * 是否启用（仅读 Source/Inc/Apidoc.inc.php）
	 *
	 * @return bool
	 */
	public static function enabled(): bool
	{
		$config = function_exists('loadConfig') ? loadConfig('Apidoc') : [];
		if (!is_array($config) || $config === []) {
			return false;
		}
		return !empty($config['enable']);
	}

	/**
	 * 合并项目 tags 与本包 hooks
	 *
	 * @param array<string,mixed> $tags 项目已有标签
	 * @return array<string,mixed>
	 */
	public static function mergeTags(array $tags = []): array
	{
		$hooks = self::hooks();
		if ($hooks === []) {
			return $tags;
		}
		foreach ($hooks as $name => $list) {
			$existing = $tags[$name] ?? [];
			if (!is_array($existing)) {
				$existing = [$existing];
			}
			$tags[$name] = array_values(array_unique(array_merge($existing, $list)));
		}
		return $tags;
	}
}
