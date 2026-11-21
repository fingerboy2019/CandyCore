<?php
/***
 * 应用层响应 Schema 基类
 *
 * 业务说明：声明 data 形状；实现 SchemaProvider 供 OpenAPI / 文档生成
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 19:28:53 $
 *
 * @package Candy\Extend\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\Schema;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 应用 Schema 基类（源码短名，编译后追加 Schema 后缀）
 */
abstract class Base implements SchemaProvider
{
	/**
	 * 响应 data 形态：item / list / page / none
	 *
	 * @return ResponseDataType
	 */
	public static function dataType(): ResponseDataType
	{
		return ResponseDataType::Item;
	}

	/**
	 * OpenAPI 组件名，默认取短类名（去掉 Schema 后缀）
	 *
	 * @return string
	 */
	public static function openApiSchemaName(): string
	{
		$short = (new \ReflectionClass(static::class))->getShortName();
		return str_ends_with($short, 'Schema') ? substr($short, 0, -6) : $short;
	}

	/**
	 * 子类实现：返回 components.schemas 片段
	 *
	 * @return array<string,array<string,mixed>>
	 */
	abstract public static function openApiSchemas(): array;
}

