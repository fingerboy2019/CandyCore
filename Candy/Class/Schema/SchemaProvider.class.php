<?php
/***
 * 响应 Schema 提供者接口
 *
 * 业务说明：描述 API data 形状，供 OpenAPI / 文档生成复用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 18:43:40 $
 *
 * @package Candy\Extend\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\Schema;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Schema 提供者
 */
interface SchemaProvider
{
	/**
	 * 组件名（OpenAPI components.schemas 键）
	 *
	 * @return string
	 */
	public static function openApiSchemaName(): string;

	/**
	 * 返回 components.schemas 片段
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function openApiSchemas(): array;
}

