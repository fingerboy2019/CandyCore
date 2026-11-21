<?php
/***
 * Rpc 查询方案执行器（A 侧有库）
 *
 * 业务说明：执行信封中的 queries，将结果按 as 合并进 data；供 B 无库、A 查库场景
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:54:32 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 在本机执行远端下发的查询方案
 */
final class QueryRunner
{
	/**
	 * 执行 queries，合并进 data
	 *
	 * @param mixed               $result  信封或 normalize 结果
	 * @param array<string,mixed> $options models 白名单覆盖
	 * @return array{data:mixed,queries:list<array>,fetched:array<string,mixed>}
	 */
	public static function apply(mixed $result, array $options = []): array
	{
		if (empty($options['skip_auth'])) {
			ApplyAuth::assertBeforeApply($result, isset($options['apply_secret']) ? (string)$options['apply_secret'] : null);
		}

		$envelope = OpEnvelope::normalize($result);
		$queries = $envelope[OpEnvelope::KEY_QUERIES];
		$data = $envelope[OpEnvelope::KEY_DATA];
		$fetched = [];

		if ($queries === []) {
			return [
				'data'    => $data,
				'queries' => [],
				'fetched' => [],
			];
		}

		$models = $options['models'] ?? Config::queryModels();
		if (!is_array($models)) {
			$models = [];
		}

		foreach ($queries as $i => $q) {
			self::validate($q, $models, $i);
			$key = (string)($q['as'] ?? ('q' . $i));
			$value = self::executeOne($q, $i);
			$fetched[$key] = $value;
		}

		$data = self::mergeData($data, $fetched);

		return [
			'data'    => $data,
			'queries' => $queries,
			'fetched' => $fetched,
			'ops'     => $envelope[OpEnvelope::KEY_OPS],
		];
	}

	/**
	 * 执行查询后返回合并后的 data
	 *
	 * @param mixed               $result  信封
	 * @param array<string,mixed> $options 选项
	 * @return mixed
	 */
	public static function applyAndData(mixed $result, array $options = []): mixed
	{
		return self::apply($result, $options)['data'];
	}

	/**
	 * @param array<string,mixed> $q
	 * @param list<string>        $models
	 * @param int                 $index
	 * @return void
	 */
	private static function validate(array $q, array $models, int $index): void
	{
		$type = (string)($q['op'] ?? '');
		if (!Query::isValid($type)) {
			throw new \InvalidArgumentException("RPC query[{$index}]: invalid op \"{$type}\"", -32602);
		}
		$model = trim((string)($q['model'] ?? ''));
		if ($model === '') {
			throw new \InvalidArgumentException("RPC query[{$index}]: model required", -32602);
		}
		if ($models !== [] && !in_array($model, $models, true)) {
			throw new \RuntimeException("RPC query[{$index}]: model not allowed: {$model}", -32003);
		}
	}

	/**
	 * @param array<string,mixed> $q
	 * @param int                 $index
	 * @return mixed
	 */
	private static function executeOne(array $q, int $index): mixed
	{
		if (!function_exists('M')) {
			throw new \RuntimeException('RPC QueryRunner requires M()');
		}

		$model = M((string)$q['model']);
		$where = isset($q['where']) && is_array($q['where']) ? $q['where'] : [];
		$field = isset($q['field']) ? (string)$q['field'] : '';

		if ($field !== '') {
			$model = $model->field($field);
		}
		if ($where !== []) {
			$model = $model->where($where);
		}

		$type = (string)$q['op'];
		if ($type === Query::FIND) {
			return $model->find();
		}

		if ($type === Query::COUNT) {
			if (method_exists($model, 'count')) {
				return $model->count();
			}
			// 兼容：无 count 时用 select 行数
			$rows = $model->select();
			return is_array($rows) ? count($rows) : 0;
		}

		// select
		if (isset($q['order']) && is_string($q['order']) && $q['order'] !== '') {
			$model = $model->order($q['order']);
		}
		$limit = isset($q['limit']) ? (int)$q['limit'] : null;
		$offset = isset($q['offset']) ? (int)$q['offset'] : null;
		if ($limit !== null && $limit >= 0) {
			if ($offset !== null && $offset >= 0) {
				$model = $model->limit($offset, $limit);
			} else {
				$model = $model->limit($limit);
			}
		}

		return $model->select();
	}

	/**
	 * 将 fetched 合并进 data
	 *
	 * data 为 null → 直接用 fetched；为数组 → 按键合并；其它标量 → ['value'=>data]+fetched
	 *
	 * @param mixed                $data
	 * @param array<string,mixed>  $fetched
	 * @return mixed
	 */
	private static function mergeData(mixed $data, array $fetched): mixed
	{
		if ($fetched === []) {
			return $data;
		}
		if ($data === null) {
			return $fetched;
		}
		if (is_array($data)) {
			return array_merge($data, $fetched);
		}
		return array_merge(['value' => $data], $fetched);
	}
}

