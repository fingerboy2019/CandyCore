<?php
/***
 * 对象 Schema 建造器
 *
 * 业务说明：链式声明 properties，输出 components.schemas 片段
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
 * ObjectSchema
 */
final class ObjectSchema
{
	private string $name;

	/** @var array<string,array<string,mixed>> */
	private array $properties = [];

	/** @var list<string> */
	private array $required = [];

	/**
	 * @param string $name 组件名
	 */
	private function __construct(string $name)
	{
		$this->name = $name;
	}

	/**
	 * @param string $name 组件名
	 * @return self
	 */
	public static function make(string $name): self
	{
		return new self($name);
	}

	/**
	 * @param string $name 属性名
	 * @param array<string,mixed> $schema 属性 schema
	 * @param bool $required 是否必填
	 * @return self
	 */
	public function property(string $name, array $schema, bool $required = false): self
	{
		$this->properties[$name] = $schema;
		if ($required) {
			$this->required[] = $name;
		}
		return $this;
	}

	/**
	 * prop 别名
	 *
	 * @param string $name 名
	 * @param array<string,mixed> $schema schema
	 * @param bool $required 必填
	 * @return self
	 */
	public function prop(string $name, array $schema, bool $required = false): self
	{
		return $this->property($name, $schema, $required);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function components(): array
	{
		$body = [
			'type'       => 'object',
			'properties' => $this->properties,
		];
		if ($this->required !== []) {
			$body['required'] = array_values(array_unique($this->required));
		}
		return [$this->name => $body];
	}
}

