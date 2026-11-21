<?php
/***
 * 数据库通用方法 Trait
 *
 * 业务说明：构造与魔术方法、SQL 状态读写、clearSql
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-17 19:20:04 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);
namespace Candy\Extend\DB\Trait;
use Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Common {
    
	/**
	 * 构造函数
	 *
	 * 初始化数据库操作基类实例。
	 * 在静态调用中用于返回类实体实例，支持链式操作。
	 *
	 * @return void
	 */
	public function __construct(){}
	
	/**
	 * 用来获取表名
	 */
	public function __get(string $pro): mixed
	{
		if($pro == 'tabName')
			return $this->tabName;
		return null;
	}

	/**
	 * 设置当前查询的主表别名。
	 *
	 * 显式 JOIN 查询必须先设置主表别名，避免多表字段出现歧义；旧式
	 * join([...]) 链式语法不受影响。
	 *
	 * @param string $alias 主表别名
	 * @return object 当前数据库对象
	 */
	public function alias(string $alias): object
	{
		$alias = self::normalizeJoinIdentifier($alias, '主表别名');
		$this->sql['alias'] = [$alias];

		return $this;
	}

	/**
	 * 添加安全显式 JOIN，或兼容旧式三元组 JOIN 配置。
	 *
	 * 显式形式为 join($table, $alias, $type, $on)，其中 $on 是列对列
	 * 比较数组，例如 [['o.id', '=', 'i.order_id']]；表名、别名、操作符
	 * 与列名均经过白名单校验，业务值应继续通过 where() 绑定。
	 *
	 * @param mixed ...$args 显式 JOIN 参数或旧式三元组配置
	 * @return object 当前数据库对象
	 */
	public function join(...$args): object
	{
		if (!isset($this->sql['join']) || !is_array($this->sql['join'])) {
			$this->sql['join'] = [];
		}

		if (count($args) === 1 && is_array($args[0])) {
			if ($args[0] === []) {
				throw new \InvalidArgumentException('JOIN 配置不能为空');
			}
			$this->sql['join'][] = $args[0];

			return $this;
		}

		if (count($args) !== 4) {
			throw new \InvalidArgumentException('显式 JOIN 必须传入表名、别名、类型和 ON 条件');
		}

		[$table, $alias, $type, $on] = $args;
		if (!is_string($table) || !is_string($alias) || !is_string($type) || !is_array($on)) {
			throw new \InvalidArgumentException('显式 JOIN 参数格式不合法');
		}
		$table = self::normalizeJoinIdentifier($table, 'JOIN 表名');
		$alias = self::normalizeJoinIdentifier($alias, 'JOIN 别名');
		$type = strtoupper(trim($type));
		if (!in_array($type, ['LEFT', 'INNER', 'RIGHT', 'CROSS'], true)) {
			throw new \InvalidArgumentException('不支持的 JOIN 类型');
		}

		if ($on !== [] && count($on) === 3 && !is_array($on[0] ?? null)) {
			$on = [$on];
		}
		$conditions = [];
		foreach ($on as $condition) {
			if (!is_array($condition) || count($condition) !== 3) {
				throw new \InvalidArgumentException('JOIN ON 条件必须是列、操作符、列三元组');
			}
			$operator = strtoupper(trim((string)$condition[1]));
			if (!in_array($operator, ['=', '<>', '!=', '>', '>=', '<', '<='], true)) {
				throw new \InvalidArgumentException('JOIN ON 操作符不合法');
			}
			$conditions[] = [
				'left' => self::normalizeJoinIdentifier($condition[0], 'JOIN 左列'),
				'operator' => $operator,
				'right' => self::normalizeJoinIdentifier($condition[2], 'JOIN 右列'),
			];
		}
		if ($type !== 'CROSS' && $conditions === []) {
			throw new \InvalidArgumentException('非 CROSS JOIN 必须提供 ON 条件');
		}

		$this->sql['join'][] = [
			'__explicit' => true,
			'table' => $table,
			'alias' => $alias,
			'type' => $type,
			'on' => $conditions,
		];

		return $this;
	}

	/**
	 * 校验 JOIN 标识符并返回标准形式。
	 *
	 * @param mixed $value 原始标识符
	 * @param string $label 标识符用途
	 * @return string 标准标识符
	 */
	private static function normalizeJoinIdentifier(mixed $value, string $label): string
	{
		$value = trim((string)$value);
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $value)) {
			throw new \InvalidArgumentException($label . '不合法');
		}

		return $value;
	}

	/**
	 * 魔术方法：处理连贯操作
	 * 
	 * 用于处理 field()、where()、order()、limit()、group()、having() 等连贯操作方法。
	 * 这些方法用于构建 SQL 语句的各个部分，支持链式调用。
	 * 
	 * @param string $methodName 方法名称
	 * @param array $args 方法参数
	 * @return object 返回当前对象，支持链式调用
	 * @example
	 * ```php
	 * // 链式调用示例
	 * $db->field('id,name')->where(['status'=>1])->order('id desc')->select();
	 * ```
	 */
	public function __call(string $methodName,array $args): object
	{
		$methodName = strtolower($methodName);
		if(array_key_exists($methodName, $this->sql)){
			if(empty($args[0]) || (is_string($args[0]) && trim($args[0]) === '')){
				$this->sql[$methodName] = '';
			}else{
				$this->sql[$methodName] = $args;
			}

			if($methodName == 'limit'){
				if($args[0] == '0')
					$this->sql[$methodName] = $args;
			}	
		}else{
			throw new \BadMethodCallException($this::class . '::' . $methodName . '() 不存在');
		}
		return $this;
	}

	/**
	 * 重置 SQL 构建参数
	 */
	protected function resetSql(): void
	{
		$this->sql = $this->defaults;
	}

	/**
	 * 获取当前完整的 SQL 状态（用于验证前后状态保护）
	 *
	 * @return array 完整的 SQL 状态数组
	 */
	public function getSqlState(): array
	{
		return $this->sql;
	}

	/**
	 * 恢复 SQL 状态（用于验证后恢复原有条件）
	 *
	 * @param array $state 要恢复的 SQL 状态数组
	 * @return void
	 */
	public function setSqlState(array $state): void
	{
		$this->sql = $state;
	}
    
	/**
	 * 获取或设置SQL构建参数
	 *
	 * 用于获取或修改SQL构建过程中使用的各种参数，如where条件、字段、排序等。
	 * 支持在原子操作模式下保存和读取参数状态。
	 *
	 * @param string $name 参数名称
	 * @param mixed $value 参数值，不传则获取参数值
	 * @return mixed 获取模式下返回参数值，设置模式下返回null
	 * @example
	 * ```php
	 * // 获取参数值
	 * $where = $db->sqlArgs('where');
	 *
	 * // 设置参数值
	 * $db->sqlArgs('field', ['id', 'name', 'age']);
	 * ```
	 */
	public function sqlArgs(string $name,array $value = []): mixed
	{

		if(empty($value)){
			return $this->sql[$name] ?? '';
		}else{
			//where 
			$this->sql[$name] = $value;
		}

		return '';
	}

	/**
	 * 清空当前 SQL 构建状态
	 *
	 * 等价于 resetSql()，供外部显式重置链式条件。
	 *
	 * @return void
	 */
	public function clearSql(): void
	{
		$this->resetSql();
	}

	/**
	 * 获取最近一次操作的提示消息
	 *
	 * @return string 错误或提示文本
	 */
	public function getMsg(): string
	{
		return $this->msg;
	}
}
