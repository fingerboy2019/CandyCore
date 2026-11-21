<?php
/***
 * Rpc 查询方案构建器（B 侧，无库）
 *
 * 业务说明：拼装 find/select/count 方案，由 A 本地 QueryRunner 查真实数据
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:54:32 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 构建 queries 列表
 *
 * 用法：
 * QueryBuilder::make()
 *   ->find('Order', ['id' => 1], as: 'order')
 *   ->select('OrderItem', ['oid' => 1], field: 'id,name', as: 'items')
 *   ->toEnvelope();
 */
final class QueryBuilder
{
	/** @var list<array<string,mixed>> */
	private array $queries = [];

	/** @var list<array<string,mixed>> */
	private array $ops = [];

	/**
	 * @return self
	 */
	public static function make(): self
	{
		return new self();
	}

	/**
	 * 单条查询
	 *
	 * @param string              $model Model 名
	 * @param array<string,mixed> $where 条件
	 * @param string|null         $as    结果写入 data 的键
	 * @param string|null         $field 字段
	 * @return self
	 */
	public function find(string $model, array $where = [], ?string $as = null, ?string $field = null): self
	{
		$this->queries[] = self::row(Query::FIND, $model, $where, $as, $field);
		return $this;
	}

	/**
	 * 列表查询
	 *
	 * @param string              $model  Model
	 * @param array<string,mixed> $where  条件
	 * @param string|null         $as     结果键
	 * @param string|null         $field  字段
	 * @param string|null         $order  排序，如 id desc
	 * @param int|null            $limit  条数
	 * @param int|null            $offset 偏移
	 * @return self
	 */
	public function select(
		string $model,
		array $where = [],
		?string $as = null,
		?string $field = null,
		?string $order = null,
		?int $limit = null,
		?int $offset = null,
	): self {
		$row = self::row(Query::SELECT, $model, $where, $as, $field);
		if ($order !== null && $order !== '') {
			$row['order'] = $order;
		}
		if ($limit !== null) {
			$row['limit'] = $limit;
		}
		if ($offset !== null) {
			$row['offset'] = $offset;
		}
		$this->queries[] = $row;
		return $this;
	}

	/**
	 * 计数
	 *
	 * @param string              $model Model
	 * @param array<string,mixed> $where 条件
	 * @param string|null         $as    结果键
	 * @return self
	 */
	public function count(string $model, array $where = [], ?string $as = null): self
	{
		$this->queries[] = self::row(Query::COUNT, $model, $where, $as, null);
		return $this;
	}

	/**
	 * 附带写库 ops（少见：查完再写）
	 *
	 * @param list<array<string,mixed>> $ops OpBuilder::all() 结果
	 * @return self
	 */
	public function withOps(array $ops): self
	{
		$this->ops = array_values($ops);
		return $this;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all(): array
	{
		return $this->queries;
	}

	/**
	 * 打成信封（B 无真实行数据，data 可为元信息）
	 *
	 * @param mixed       $data       可选种子/元数据
	 * @param string|null $applyOrder query_op|op_query
	 * @return array<string,mixed>
	 */
	public function toEnvelope(mixed $data = null, ?string $applyOrder = null): array
	{
		return OpEnvelope::pack($data, $this->ops, $this->queries, $applyOrder);
	}

	/**
	 * @param string              $op
	 * @param string              $model
	 * @param array<string,mixed> $where
	 * @param string|null         $as
	 * @param string|null         $field
	 * @return array<string,mixed>
	 */
	private static function row(string $op, string $model, array $where, ?string $as, ?string $field): array
	{
		$row = [
			'op'    => $op,
			'model' => $model,
			'where' => $where,
		];
		if ($as !== null && $as !== '') {
			$row['as'] = $as;
		}
		if ($field !== null && $field !== '') {
			$row['field'] = $field;
		}
		return $row;
	}
}

