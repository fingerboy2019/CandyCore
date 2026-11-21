<?php
/***
 * Rpc Op 构建器（B 侧）
 *
 * 业务说明：链式拼装 insert/update/delete 命令，最终交给 OpEnvelope::pack
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:50:20 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 构建 ops 列表
 *
 * 用法：
 * OpBuilder::make()
 *   ->update('Order', ['freight' => 12.5], ['id' => 1001])
 *   ->insert('Log', ['msg' => '...'])
 *   ->toEnvelope($data);
 */
final class OpBuilder
{
	/** @var list<array<string,mixed>> */
	private array $ops = [];

	/**
	 * 创建构建器
	 *
	 * @return self
	 */
	public static function make(): self
	{
		return new self();
	}

	/**
	 * 追加 insert
	 *
	 * @param string              $model  Model 名，支持 App://Name
	 * @param array<string,mixed> $fields 字段
	 * @return self
	 */
	public function insert(string $model, array $fields): self
	{
		$this->ops[] = [
			'op'     => Op::INSERT,
			'model'  => $model,
			'fields' => $fields,
		];
		return $this;
	}

	/**
	 * 追加 update
	 *
	 * @param string                   $model  Model 名
	 * @param array<string,mixed>      $fields 更新字段
	 * @param array<string,mixed>|null $where  条件；也可用 $id+$pk 简写
	 * @param int|string|null          $id     主键值简写
	 * @param string                   $pk     主键字段名，默认 id
	 * @return self
	 */
	public function update(
		string $model,
		array $fields,
		?array $where = null,
		int|string|null $id = null,
		string $pk = 'id',
	): self {
		$op = [
			'op'     => Op::UPDATE,
			'model'  => $model,
			'fields' => $fields,
			'pk'     => $pk,
		];
		if ($where !== null) {
			$op['where'] = $where;
		}
		if ($id !== null) {
			$op['id'] = $id;
		}
		$this->ops[] = $op;
		return $this;
	}

	/**
	 * 追加 delete
	 *
	 * @param string                   $model Model 名
	 * @param array<string,mixed>|null $where 条件
	 * @param int|string|null          $id    主键值简写
	 * @param string                   $pk    主键字段
	 * @return self
	 */
	public function delete(
		string $model,
		?array $where = null,
		int|string|null $id = null,
		string $pk = 'id',
	): self {
		$op = [
			'op'    => Op::DELETE,
			'model' => $model,
			'pk'    => $pk,
		];
		if ($where !== null) {
			$op['where'] = $where;
		}
		if ($id !== null) {
			$op['id'] = $id;
		}
		$this->ops[] = $op;
		return $this;
	}

	/**
	 * 当前 ops
	 *
	 * @return list<array<string,mixed>>
	 */
	public function all(): array
	{
		return $this->ops;
	}

	/**
	 * 打成信封
	 *
	 * @param mixed       $data       业务数据
	 * @param string|null $applyOrder query_op|op_query
	 * @return array<string,mixed>
	 */
	public function toEnvelope(mixed $data = null, ?string $applyOrder = null): array
	{
		return OpEnvelope::pack($data, $this->ops, [], $applyOrder);
	}
}

