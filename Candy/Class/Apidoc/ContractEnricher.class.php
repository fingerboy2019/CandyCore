<?php
/***
 * Apidoc 契约补全器
 *
 * 业务说明：从 Validate / Schema 合并请求字段与响应 data；手写注解同名优先
 * 绑定：ApiContract 显式优先，否则按控制器短名约定推断
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 20:04:18 $
 *
 * @package Candy\Extend\Apidoc
 */

declare(strict_types=1);

namespace Candy\Extend\Apidoc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Apidoc\utils\Helper;
use Candy\Extend\Schema\{Base as SchemaBase, ResponseDataType};
use Candy\Extend\Validate\Base as ValidateBase;
use ReflectionClass;
use ReflectionMethod;

/**
 * ContractEnricher
 */
final class ContractEnricher
{
	/**
	 * 补全方法注解结构
	 *
	 * @param array<string,mixed> $methodAnnotations 已解析的方法注解
	 * @param ReflectionClass $refClass 控制器反射
	 * @param ReflectionMethod $refMethod 方法反射
	 * @param array<string,mixed> $config Apidoc 全局配置
	 * @param array<string,mixed> $currentApp 当前 app 配置
	 * @return array<string,mixed>
	 */
	public static function enrich(
		array $methodAnnotations,
		ReflectionClass $refClass,
		ReflectionMethod $refMethod,
		array $config = [],
		array $currentApp = []
	): array {
		$contractCfg = is_array($config['contract'] ?? null) ? $config['contract'] : [];
		if (($contractCfg['enable'] ?? true) === false) {
			return $methodAnnotations;
		}

		$binding = self::resolveBinding($methodAnnotations, $refClass, $refMethod, $contractCfg, $currentApp);
		if ($binding === null) {
			return $methodAnnotations;
		}

		if ($binding['validateClass'] !== '' && $binding['scene'] !== '') {
			$methodAnnotations = self::mergeValidateParams($methodAnnotations, $binding['validateClass'], $binding['scene']);
		}

		if ($binding['schemaClass'] !== '') {
			$methodAnnotations = self::mergeSchemaResponse(
				$methodAnnotations,
				$binding['schemaClass'],
				$binding['dataType']
			);
		}

		return $methodAnnotations;
	}

	/**
	 * 解析绑定（ApiContract > 约定）
	 *
	 * @param array<string,mixed> $methodAnnotations 注解
	 * @param ReflectionClass $refClass 类
	 * @param ReflectionMethod $refMethod 方法
	 * @param array<string,mixed> $contractCfg contract 配置
	 * @param array<string,mixed> $currentApp app
	 * @return array{validateClass:string,scene:string,schemaClass:string,dataType:string}|null
	 */
	private static function resolveBinding(
		array $methodAnnotations,
		ReflectionClass $refClass,
		ReflectionMethod $refMethod,
		array $contractCfg,
		array $currentApp
	): ?array {
		$explicit = self::readApiContract($methodAnnotations);
		$app = self::guessApp($refClass, $currentApp);
		$short = self::controllerShortName($refClass);

		$validateRef = (string)($explicit['validate'] ?? '');
		$schemaRef = (string)($explicit['schema'] ?? '');
		$scene = (string)($explicit['scene'] ?? '');
		$dataType = (string)($explicit['dataType'] ?? '');

		if ($validateRef === '' && $schemaRef === '') {
			// 约定：同名 Validate / Schema
			$validateRef = $short;
			$schemaRef = $short;
		}

		$validateClass = self::resolveValidateClass($validateRef, $app);
		$schemaClass = self::resolveSchemaClass($schemaRef, $app);

		if ($scene === '' && $validateClass !== '') {
			$scene = self::mapScene($refMethod->getName(), $contractCfg);
			if ($scene !== '' && class_exists($validateClass)) {
				/** @var ValidateBase $tmp */
				$tmp = $validateClass::make();
				if (!$tmp->hasScene($scene)) {
					$scene = '';
				}
			}
		}

		if ($validateClass === '' && $schemaClass === '') {
			return null;
		}

		// 仅约定且两侧类都不存在 → 不 enrich
		if ($explicit === [] && $validateClass !== '' && !class_exists($validateClass)) {
			$validateClass = '';
		}
		if ($explicit === [] && $schemaClass !== '' && !class_exists($schemaClass)) {
			$schemaClass = '';
		}
		if ($validateClass === '' && $schemaClass === '') {
			return null;
		}

		return [
			'validateClass' => $validateClass,
			'scene'         => $scene,
			'schemaClass'   => $schemaClass,
			'dataType'      => $dataType,
		];
	}

	/**
	 * @param array<string,mixed> $methodAnnotations 注解
	 * @return array<string,string>
	 */
	private static function readApiContract(array $methodAnnotations): array
	{
		$raw = $methodAnnotations['apiContract'] ?? null;
		if ($raw === null || $raw === '' || $raw === true) {
			return [];
		}
		if (!is_array($raw)) {
			return [];
		}
		// 可能被包成单元素列表
		if (isset($raw[0]) && is_array($raw[0])) {
			$raw = $raw[0];
		}
		$out = [];
		foreach (['validate', 'scene', 'schema', 'dataType'] as $k) {
			if (isset($raw[$k]) && is_string($raw[$k]) && $raw[$k] !== '') {
				$out[$k] = $raw[$k];
			}
		}
		return $out;
	}

	/**
	 * @param ReflectionClass $refClass 类
	 * @param array<string,mixed> $currentApp app
	 * @return string
	 */
	private static function guessApp(ReflectionClass $refClass, array $currentApp): string
	{
		$ns = $refClass->getNamespaceName();
		if (preg_match('#APP\\\\Control\\\\([^\\\\]+)#', $ns, $m)) {
			return $m[1];
		}
		$key = (string)($currentApp['key'] ?? '');
		return $key !== '' ? ucfirst($key) : 'Admin';
	}

	/**
	 * @param ReflectionClass $refClass 类
	 * @return string
	 */
	private static function controllerShortName(ReflectionClass $refClass): string
	{
		$short = $refClass->getShortName();
		if (str_ends_with($short, 'Action')) {
			$short = substr($short, 0, -6);
		}
		return $short;
	}

	/**
	 * @param string $method 方法名
	 * @param array<string,mixed> $contractCfg 配置
	 * @return string
	 */
	private static function mapScene(string $method, array $contractCfg): string
	{
		$method = strtolower($method);
		$map = is_array($contractCfg['scene_map'] ?? null) ? $contractCfg['scene_map'] : [];
		$default = [
			'add' => 'add',
			'create' => 'add',
			'store' => 'add',
			'edit' => 'mod',
			'update' => 'mod',
			'save' => 'mod',
			'mod' => 'mod',
		];
		$map = array_change_key_case(array_merge($default, $map), CASE_LOWER);
		return (string)($map[$method] ?? $method);
	}

	/**
	 * @param string $ref 短名或 FQCN
	 * @param string $app 应用
	 * @return string
	 */
	private static function resolveValidateClass(string $ref, string $app): string
	{
		$ref = trim($ref);
		if ($ref === '') {
			return '';
		}
		if (str_contains($ref, '\\')) {
			$class = ltrim($ref, '\\');
			return class_exists($class) ? $class : $class;
		}
		$name = str_ends_with($ref, 'Validate') ? $ref : $ref . 'Validate';
		return 'APP\\Validate\\' . $app . '\\' . $name;
	}

	/**
	 * @param string $ref 短名或 FQCN
	 * @param string $app 应用
	 * @return string
	 */
	private static function resolveSchemaClass(string $ref, string $app): string
	{
		$ref = trim($ref);
		if ($ref === '') {
			return '';
		}
		if (str_contains($ref, '\\')) {
			return ltrim($ref, '\\');
		}
		$name = str_ends_with($ref, 'Schema') ? $ref : $ref . 'Schema';
		return 'APP\\Schema\\' . $app . '\\' . $name;
	}

	/**
	 * @param array<string,mixed> $methodAnnotations 注解
	 * @param string $validateClass FQCN
	 * @param string $scene 场景
	 * @return array<string,mixed>
	 */
	private static function mergeValidateParams(array $methodAnnotations, string $validateClass, string $scene): array
	{
		if (!class_exists($validateClass) || !is_subclass_of($validateClass, ValidateBase::class)) {
			return $methodAnnotations;
		}
		/** @var ValidateBase $validator */
		$validator = $validateClass::make();
		if (!$validator->hasScene($scene)) {
			return $methodAnnotations;
		}

		$auto = [];
		$labels = $validator->fieldLabels();
		$messages = $validator->fieldMessages();
		foreach ($validator->sceneRules($scene) as $field => $ruleDef) {
			$parts = self::normalizeRuleParts($ruleDef);
			$required = self::ruleHas($parts, 'require') || self::ruleHas($parts, 'notnull');
			$type = self::inferType($parts);
			$desc = $labels[$field] ?? $field;
			if (isset($messages[$field . '.require'])) {
				// 保留 label 作 desc，require 文案不覆盖标题
			}
			$auto[] = [
				'name'     => $field,
				'type'     => $type,
				'required' => $required,
				'desc'     => $desc,
				'default'  => '',
			];
		}

		if ($auto === []) {
			return $methodAnnotations;
		}

		$existing = [];
		if (!empty($methodAnnotations['param']) && is_array($methodAnnotations['param'])) {
			$existing = $methodAnnotations['param'];
		}
		// 手写优先：先 auto 再 existing
		$methodAnnotations['param'] = Helper::arrayMergeAndUnique('name', $auto, $existing);
		return $methodAnnotations;
	}

	/**
	 * @param array<string,mixed> $methodAnnotations 注解
	 * @param string $schemaClass FQCN
	 * @param string $dataTypeOverride 覆盖
	 * @return array<string,mixed>
	 */
	private static function mergeSchemaResponse(array $methodAnnotations, string $schemaClass, string $dataTypeOverride): array
	{
		if (!class_exists($schemaClass) || !is_subclass_of($schemaClass, SchemaBase::class)) {
			return $methodAnnotations;
		}

		$schemas = $schemaClass::openApiSchemas();
		$name = $schemaClass::openApiSchemaName();
		$component = $schemas[$name] ?? (is_array($schemas) ? reset($schemas) : null);
		if (!is_array($component)) {
			return $methodAnnotations;
		}

		$dataType = $dataTypeOverride !== ''
			? strtolower($dataTypeOverride)
			: $schemaClass::dataType()->value;

		$dataChildren = self::openApiPropertiesToFields($component);

		if ($dataType === ResponseDataType::List->value) {
			$dataNode = [
				'name'         => 'data',
				'type'         => 'array',
				'required'     => true,
				'desc'         => '业务数据列表',
				'childrenType' => 'object',
				'children'     => $dataChildren,
			];
		} elseif ($dataType === ResponseDataType::Page->value) {
			$dataNode = [
				'name'     => 'data',
				'type'     => 'array',
				'required' => true,
				'desc'     => '分页列表',
				'children' => $dataChildren,
			];
			// page：在 responseSuccess 根级补 count（手写优先）
			$countField = [
				'name'     => 'count',
				'type'     => 'int',
				'required' => false,
				'desc'     => '总记录数',
			];
			$resp = is_array($methodAnnotations['responseSuccess'] ?? null) ? $methodAnnotations['responseSuccess'] : [];
			$methodAnnotations['responseSuccess'] = Helper::arrayMergeAndUnique('name', [$countField], $resp);
		} elseif ($dataType === ResponseDataType::None->value) {
			$dataNode = [
				'name'     => 'data',
				'type'     => 'object',
				'required' => false,
				'desc'     => '无业务数据',
				'children' => [],
			];
		} else {
			$dataNode = [
				'name'     => 'data',
				'type'     => 'object',
				'required' => true,
				'desc'     => '业务数据',
				'children' => $dataChildren,
				'main'     => true,
			];
		}

		$resp = is_array($methodAnnotations['responseSuccess'] ?? null) ? $methodAnnotations['responseSuccess'] : [];
		$merged = false;
		foreach ($resp as $i => $item) {
			if (!is_array($item) || ($item['name'] ?? '') !== 'data') {
				continue;
			}
			$existingChildren = is_array($item['children'] ?? null) ? $item['children'] : [];
			$item['children'] = Helper::arrayMergeAndUnique('name', $dataChildren, $existingChildren);
			if ($dataType === ResponseDataType::List->value || $dataType === ResponseDataType::Page->value) {
				$item['type'] = $item['type'] ?? 'array';
			}
			$resp[$i] = $item;
			$merged = true;
			break;
		}
		if (!$merged) {
			// 无 data 节点时追加（外壳 code/message 通常已由全局 responses 提供）
			$resp = Helper::arrayMergeAndUnique('name', $resp, [$dataNode]);
		}
		$methodAnnotations['responseSuccess'] = $resp;

		// returned：若为空则写入 data 子字段，供 mergeResponseSuccessParam 路径兼容
		if (empty($methodAnnotations['returned']) && $dataChildren !== [] && $dataType !== ResponseDataType::None->value) {
			$methodAnnotations['returned'] = $dataChildren;
		} elseif (!empty($methodAnnotations['returned']) && is_array($methodAnnotations['returned']) && $dataChildren !== []) {
			$methodAnnotations['returned'] = Helper::arrayMergeAndUnique(
				'name',
				$dataChildren,
				$methodAnnotations['returned']
			);
		}

		return $methodAnnotations;
	}

	/**
	 * OpenAPI properties → Apidoc 字段列表
	 *
	 * @param array<string,mixed> $component schema 组件
	 * @return list<array<string,mixed>>
	 */
	private static function openApiPropertiesToFields(array $component): array
	{
		$props = is_array($component['properties'] ?? null) ? $component['properties'] : [];
		$required = is_array($component['required'] ?? null) ? $component['required'] : [];
		$fields = [];
		foreach ($props as $name => $prop) {
			if (!is_string($name) || !is_array($prop)) {
				continue;
			}
			$type = (string)($prop['type'] ?? 'string');
			$type = match ($type) {
				'integer' => 'int',
				'boolean' => 'boolean',
				'number' => 'float',
				default => $type,
			};
			$fields[] = [
				'name'     => $name,
				'type'     => $type,
				'required' => in_array($name, $required, true),
				'desc'     => (string)($prop['description'] ?? $name),
				'default'  => '',
			];
		}
		return $fields;
	}

	/**
	 * @param string|array<int|string,mixed> $ruleDef 规则
	 * @return list<string>
	 */
	private static function normalizeRuleParts(string|array $ruleDef): array
	{
		if (is_array($ruleDef)) {
			$parts = [];
			foreach ($ruleDef as $k => $v) {
				$parts[] = is_int($k) ? (string)$v : (string)$k;
			}
			return $parts;
		}
		$chunks = preg_split('/\|/', $ruleDef) ?: [];
		return array_values(array_filter(array_map('trim', $chunks), static fn (string $s): bool => $s !== ''));
	}

	/**
	 * @param list<string> $parts 规则段
	 * @param string $name 名
	 * @return bool
	 */
	private static function ruleHas(array $parts, string $name): bool
	{
		foreach ($parts as $part) {
			$rule = str_contains($part, ':') ? explode(':', $part, 2)[0] : $part;
			if (strcasecmp(trim($rule), $name) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<string> $parts 规则段
	 * @return string
	 */
	private static function inferType(array $parts): string
	{
		foreach ($parts as $part) {
			$rule = strtolower(str_contains($part, ':') ? explode(':', $part, 2)[0] : $part);
			$map = [
				'int' => 'int',
				'integer' => 'int',
				'numeric' => 'float',
				'float' => 'float',
				'bool' => 'boolean',
				'boolean' => 'boolean',
				'array' => 'array',
				'email' => 'string',
				'url' => 'string',
				'mobile' => 'string',
				'string' => 'string',
			];
			if (isset($map[$rule])) {
				return $map[$rule];
			}
		}
		return 'string';
	}
}

