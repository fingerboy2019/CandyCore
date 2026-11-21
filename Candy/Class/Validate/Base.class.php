<?php
/***
 * 类规则验证器基类
 *
 * 业务说明：$rules / $scenes / $fields；运行时校验；可供 OpenAPI 读取 scene 规则
 * 规则 DSL 使用点号嵌套（profile.email、tags.*）；不再使用 XML
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 18:43:40 $
 *
 * @package Candy\Extend\Validate
 */

declare(strict_types=1);

namespace Candy\Extend\Validate;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 应用层 Validate 基类
 *
 * @example
 * ```php
 * final class UserValidate extends \Candy\Extend\Validate\Base {
 *   protected array $rules = ['name' => 'require|string|max:64'];
 *   protected array $scenes = ['store' => ['name']];
 * }
 * UserValidate::make($data)->scene('store')->check();
 * ```
 */
abstract class Base
{
	/** @var array<string,string|array<int|string,mixed>> 字段 => 规则串或规则数组 */
	protected array $rules = [];

	/** @var array<string,string> 字段中文名（文档/错误提示） */
	protected array $fields = [];

	/** @var array<string,list<string>> scene => 字段列表 */
	protected array $scenes = [];

	/** @var array<string,string> 自定义错误 message：字段.规则 => 文案 */
	protected array $messages = [];

	/** @var array<string,mixed> */
	protected array $data = [];

	protected string $currentScene = '';

	/** @var list<string> */
	protected array $errors = [];

	protected ?object $db = null;

	/**
	 * @param array<string,mixed> $data 待校验数据
	 */
	public function __construct(array $data = [])
	{
		$this->data = $data;
	}

	/**
	 * 工厂
	 *
	 * @param array<string,mixed> $data 数据
	 * @return static
	 */
	public static function make(array $data = []): static
	{
		return new static($data);
	}

	/**
	 * 指定场景
	 *
	 * @param string $scene 场景名
	 * @return static
	 */
	public function scene(string $scene): static
	{
		$this->currentScene = $scene;
		return $this;
	}

	/**
	 * 注入 DB（unique 等规则需要）
	 *
	 * @param object|null $db DBServer 实例
	 * @return static
	 */
	public function setDb(?object $db): static
	{
		$this->db = $db;
		return $this;
	}

	/**
	 * 替换待校验数据
	 *
	 * @param array<string,mixed> $data 数据
	 * @return static
	 */
	public function data(array $data): static
	{
		$this->data = $data;
		return $this;
	}

	/**
	 * 执行校验
	 *
	 * @param array<string,mixed>|null $data 空则用构造/data() 已设数据
	 * @return bool
	 */
	public function check(?array $data = null): bool
	{
		if ($data !== null) {
			$this->data = $data;
		}
		$this->errors = [];

		foreach ($this->activeFields() as $field) {
			$ruleDef = $this->ruleForField($field);
			if ($ruleDef === null || $ruleDef === '') {
				continue;
			}
			$value = $this->valueAt($field);
			$parts = $this->normalizeRules($ruleDef);
			$isRequire = $this->hasRule($parts, 'require') || $this->hasRule($parts, 'notnull');

			if (!$isRequire && $this->isEmpty($value) && !$this->isWildcardField($field)) {
				continue;
			}

			foreach ($parts as $part) {
				if (!$this->applyRule($field, $value, $part)) {
					break;
				}
			}
		}

		return $this->errors === [];
	}

	/**
	 * 首条错误
	 *
	 * @return string
	 */
	public function getError(): string
	{
		return $this->errors[0] ?? '';
	}

	/**
	 * 全部错误
	 *
	 * @return list<string>
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	/**
	 * 当前 scene 下的字段规则（供文档生成）
	 *
	 * @param string|null $scene 场景；null 用 currentScene 或全部
	 * @return array<string,string|array<int|string,mixed>>
	 */
	public function sceneRules(?string $scene = null): array
	{
		$prev = $this->currentScene;
		if ($scene !== null) {
			$this->currentScene = $scene;
		}
		$out = [];
		foreach ($this->activeFields() as $field) {
			$rule = $this->ruleForField($field);
			if ($rule !== null && $rule !== '') {
				$out[$field] = $rule;
			}
		}
		$this->currentScene = $prev;
		return $out;
	}

	/**
	 * 字段标题表
	 *
	 * @return array<string,string>
	 */
	public function fieldLabels(): array
	{
		return $this->fields;
	}

	/**
	 * 自定义错误文案表（供 Apidoc 等读取）
	 *
	 * @return array<string,string>
	 */
	public function fieldMessages(): array
	{
		return $this->messages;
	}

	/**
	 * 是否声明了指定 scene
	 *
	 * @param string $scene 场景名
	 * @return bool
	 */
	public function hasScene(string $scene): bool
	{
		return $scene !== '' && isset($this->scenes[$scene]) && is_array($this->scenes[$scene]);
	}

	/**
	 * 解析字段规则（场景可覆盖：'mod' => ['name' => 'unique:gid']）
	 *
	 * @param string $field 字段
	 * @return string|array<int|string,mixed>|null
	 */
	protected function ruleForField(string $field): string|array|null
	{
		$scene = $this->currentScene;
		if ($scene !== '' && isset($this->scenes[$scene]) && is_array($this->scenes[$scene])) {
			$entry = $this->scenes[$scene];
			if (array_key_exists($field, $entry)) {
				$override = $entry[$field];
				if (is_string($override) || is_array($override)) {
					return $override;
				}
			}
		}
		return $this->rules[$field] ?? null;
	}

	/**
	 * @param string|null $scene 场景
	 * @return list<string>
	 */
	protected function activeFields(?string $scene = null): array
	{
		$scene = $scene ?? $this->currentScene;
		if ($scene !== '' && isset($this->scenes[$scene]) && is_array($this->scenes[$scene])) {
			$fields = [];
			foreach ($this->scenes[$scene] as $key => $value) {
				// 列表项：['name']；覆盖项：['name' => 'require|unique']
				$fields[] = is_int($key) ? (string)$value : (string)$key;
			}
			return $fields;
		}
		return array_keys($this->rules);
	}

	/**
	 * @param string|array<int|string,mixed> $ruleDef 规则
	 * @return list<string|array<string,mixed>>
	 */
	protected function normalizeRules(string|array $ruleDef): array
	{
		if (is_array($ruleDef)) {
			$parts = [];
			foreach ($ruleDef as $k => $v) {
				if (is_int($k)) {
					$parts[] = (string)$v;
				} else {
					$parts[] = [$k => $v];
				}
			}
			return $parts;
		}
		$chunks = preg_split('/\|/', $ruleDef) ?: [];
		return array_values(array_filter(array_map('trim', $chunks), static fn (string $s): bool => $s !== ''));
	}

	/**
	 * @param list<string|array<string,mixed>> $parts 规则段
	 * @param string $name 规则名
	 * @return bool
	 */
	protected function hasRule(array $parts, string $name): bool
	{
		foreach ($parts as $part) {
			[$rule] = $this->parseRulePart($part);
			if (strcasecmp($rule, $name) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string|array<string,mixed> $part 一段规则
	 * @return array{0:string,1:?string}
	 */
	protected function parseRulePart(string|array $part): array
	{
		if (is_array($part)) {
			$key = (string)array_key_first($part);
			$val = $part[$key] ?? null;
			return [$key, is_array($val) ? implode(',', $val) : (string)$val];
		}
		if (str_contains($part, ':')) {
			[$name, $arg] = explode(':', $part, 2);
			return [trim($name), trim($arg)];
		}
		return [trim($part), null];
	}

	/**
	 * @param string $field 字段
	 * @param mixed $value 值
	 * @param string|array<string,mixed> $part 规则
	 * @return bool 是否继续下一条规则
	 */
	protected function applyRule(string $field, mixed $value, string|array $part): bool
	{
		[$rule, $arg] = $this->parseRulePart($part);
		$rule = strtolower($rule);

		$ok = match ($rule) {
			'require', 'notnull' => !$this->isEmpty($value),
			'string' => is_string($value) || is_numeric($value),
			'integer', 'int' => $this->isIntLike($value),
			'number', 'float', 'numeric' => is_numeric($value),
			'boolean', 'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', true, false], true),
			'array' => is_array($value),
			'email' => is_string($value) && (bool)preg_match("/^[\w.+-]+@[\w-]+(\.[\w-]+)+$/", $value),
			'url' => is_string($value) && (bool)filter_var($value, FILTER_VALIDATE_URL),
			'max' => $this->checkMax($value, $arg),
			'min' => $this->checkMin($value, $arg),
			'length' => $this->checkLength($value, (string)$arg),
			'between' => $this->checkBetween($value, (string)$arg),
			'in' => $this->checkIn($value, (string)$arg, false),
			'notin' => $this->checkIn($value, (string)$arg, true),
			'confirm' => $this->checkConfirm($value, (string)$arg),
			'regex' => is_string($value) && $arg !== null && @preg_match($arg, $value) === 1,
			'unique' => $this->checkUnique($field, $value, $arg),
			'alpha' => is_string($value) && (bool)preg_match('/^[A-Za-z]+$/', $value),
			'alphanum' => is_string($value) && (bool)preg_match('/^[A-Za-z0-9]+$/', $value),
			'mobile' => is_string($value) && (bool)preg_match('/^1\d{10}$/', $value),
			default => $this->checkCustom($rule, $field, $value, $arg),
		};

		if ($ok) {
			return true;
		}
		$this->errors[] = $this->messageFor($field, $rule, $arg);
		return false;
	}

	/**
	 * 子类可覆盖：方法名同规则名
	 *
	 * @param string $rule 规则
	 * @param string $field 字段
	 * @param mixed $value 值
	 * @param string|null $arg 参数
	 * @return bool
	 */
	protected function checkCustom(string $rule, string $field, mixed $value, ?string $arg): bool
	{
		if (method_exists($this, $rule)) {
			return (bool)$this->{$rule}($value, $arg, $field);
		}
		$this->errors[] = "未知验证规则: {$rule}";
		return false;
	}

	/**
	 * @param string $field 点号路径或 tags.*
	 * @return mixed
	 */
	protected function valueAt(string $field): mixed
	{
		if (str_contains($field, '.*')) {
			// 数组项规则在 checkArrayItems 中处理；此处返回父数组
			$parent = explode('.*', $field)[0];
			return $this->valueAt($parent);
		}
		if (!str_contains($field, '.')) {
			return $this->data[$field] ?? null;
		}
		$cursor = $this->data;
		foreach (explode('.', $field) as $seg) {
			if (!is_array($cursor) || !array_key_exists($seg, $cursor)) {
				return null;
			}
			$cursor = $cursor[$seg];
		}
		return $cursor;
	}

	/**
	 * Is Wildcard Field
	 * @param mixed $field
	 * @return bool
	 */
	protected function isWildcardField(string $field): bool
	{
		return str_contains($field, '.*');
	}

	/**
	 * Is Empty
	 * @param mixed $value
	 * @return bool
	 */
	protected function isEmpty(mixed $value): bool
	{
		if ($value === null) {
			return true;
		}
		if (is_string($value) && trim($value) === '') {
			return true;
		}
		if (is_array($value) && $value === []) {
			return true;
		}
		return false;
	}

	/**
	 * Is Int Like
	 * @param mixed $value
	 * @return bool
	 */
	protected function isIntLike(mixed $value): bool
	{
		if (is_int($value)) {
			return true;
		}
		return is_string($value) && preg_match('/^-?\d+$/', $value) === 1;
	}

	/**
	 * Check Max
	 * @param mixed $value
	 * @param mixed $arg
	 * @return bool
	 */
	protected function checkMax(mixed $value, ?string $arg): bool
	{
		$n = (float)$arg;
		if (is_array($value)) {
			return count($value) <= $n;
		}
		if (is_numeric($value) && !is_string($value)) {
			return (float)$value <= $n;
		}
		return mb_strlen((string)$value) <= $n;
	}

	/**
	 * Check Min
	 * @param mixed $value
	 * @param mixed $arg
	 * @return bool
	 */
	protected function checkMin(mixed $value, ?string $arg): bool
	{
		$n = (float)$arg;
		if (is_array($value)) {
			return count($value) >= $n;
		}
		if (is_numeric($value) && !is_string($value)) {
			return (float)$value >= $n;
		}
		return mb_strlen((string)$value) >= $n;
	}

	/**
	 * Check Length
	 * @param mixed $value
	 * @param mixed $arg
	 * @return bool
	 */
	protected function checkLength(mixed $value, string $arg): bool
	{
		$len = is_array($value) ? count($value) : mb_strlen((string)$value);
		if (str_contains($arg, ',')) {
			[$min, $max] = array_map('trim', explode(',', $arg, 2));
			return $len >= (int)$min && $len <= (int)$max;
		}
		if (str_contains($arg, '-')) {
			[$min, $max] = array_map('trim', explode('-', $arg, 2));
			if ($max === '') {
				return $len >= (int)$min;
			}
			return $len >= (int)$min && $len <= (int)$max;
		}
		return $len === (int)$arg;
	}

	/**
	 * Check Between
	 * @param mixed $value
	 * @param mixed $arg
	 * @return bool
	 */
	protected function checkBetween(mixed $value, string $arg): bool
	{
		[$min, $max] = array_map('trim', explode(',', $arg, 2));
		$n = (float)$value;
		return $n >= (float)$min && $n <= (float)$max;
	}

	/**
	 * Check In
	 * @param mixed $value
	 * @param mixed $arg
	 * @param mixed $not
	 * @return bool
	 */
	protected function checkIn(mixed $value, string $arg, bool $not): bool
	{
		$list = array_map('trim', explode(',', $arg));
		$hit = in_array((string)$value, $list, true) || in_array($value, $list, false);
		return $not ? !$hit : $hit;
	}

	/**
	 * Check Confirm
	 * @param mixed $value
	 * @param mixed $arg
	 * @return bool
	 */
	protected function checkConfirm(mixed $value, string $arg): bool
	{
		$other = $this->valueAt($arg);
		return (string)$value === (string)$other;
	}

	/**
	 * unique 或 unique:主键字段（更新时排除自身）
	 *
	 * @param string $field 字段
	 * @param mixed $value 值
	 * @param string|null $arg 主键名
	 * @return bool
	 */
	protected function checkUnique(string $field, mixed $value, ?string $arg): bool
	{
		if ($this->isEmpty($value)) {
			return true;
		}
		if ($this->db === null || !method_exists($this->db, 'where')) {
			$this->errors[] = 'unique 规则需要绑定 DB 实例';
			return false;
		}
		$col = $field;
		if (str_contains($field, '.')) {
			$col = substr($field, strrpos($field, '.') + 1);
		}
		$old = method_exists($this->db, 'getSqlState') ? $this->db->getSqlState() : null;
		$rows = $this->db->where([$col => $value])->select();
		if ($old !== null && method_exists($this->db, 'setSqlState')) {
			$this->db->setSqlState($old);
		}
		if (!is_array($rows) || $rows === []) {
			return true;
		}
		if ($arg === null || $arg === '') {
			return count($rows) === 0;
		}
		$pk = strtolower($arg);
		$data = array_change_key_case($this->data, CASE_LOWER);
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = array_change_key_case($row, CASE_LOWER);
			if ((string)($row[$pk] ?? '') === (string)($data[$pk] ?? '') && array_key_exists($pk, $data)) {
				continue;
			}
			return false;
		}
		return true;
	}

	/**
	 * Message For
	 * @param mixed $field
	 * @param mixed $rule
	 * @param mixed $arg
	 * @return string
	 */
	protected function messageFor(string $field, string $rule, ?string $arg): string
	{
		$key = $field . '.' . $rule;
		if (isset($this->messages[$key])) {
			return $this->messages[$key];
		}
		if (isset($this->messages[$field])) {
			return $this->messages[$field];
		}
		$label = $this->fields[$field] ?? $field;
		return match ($rule) {
			'require', 'notnull' => "{$label}不能为空",
			'email' => "{$label}格式不正确",
			'unique' => "{$label}已存在",
			'confirm' => "{$label}确认不一致",
			'max' => "{$label}超出最大限制",
			'min' => "{$label}低于最小限制",
			default => "{$label}验证失败({$rule})",
		};
	}
}

