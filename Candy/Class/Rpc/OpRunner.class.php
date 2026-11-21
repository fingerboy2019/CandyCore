<?php
/***
 * Rpc Op 执行器（A 侧）
 *
 * 业务说明：按信封中的 ops 调用本地 M() 完成 insert/update/delete；支持模型白名单与事务
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:50:20 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 在本机执行远端下发的写库命令
 */
final class OpRunner
{
	/**
	 * 执行信封中的 ops，返回业务 data 与每步结果
	 *
	 * @param mixed                $result  Rpc result 或已 normalize 的信封
	 * @param array<string,mixed>  $options 覆盖配置：transaction / models
	 * @return array{data:mixed,ops:list<array>,applied:list<mixed>}
	 * @throws \Throwable 校验失败或写库失败
	 */
	public static function apply(mixed $result, array $options = []): array
	{
		if (empty($options['skip_auth'])) {
			ApplyAuth::assertBeforeApply($result, isset($options['apply_secret']) ? (string)$options['apply_secret'] : null);
		}

		$envelope = OpEnvelope::normalize($result);
		$ops = $envelope[OpEnvelope::KEY_OPS];
		if ($ops === []) {
			return [
				'data'    => $envelope[OpEnvelope::KEY_DATA],
				'ops'     => [],
				'applied' => [],
			];
		}

		$useTx = (bool)($options['transaction'] ?? Config::opTransaction());
		$models = $options['models'] ?? Config::opModels();
		if (!is_array($models)) {
			$models = [];
		}

		foreach ($ops as $i => $op) {
			self::validateOp($op, $models, $i);
		}

		$applied = [];
		$started = false;
		$db = null;

		try {
			if ($useTx && count($ops) > 0 && function_exists('D')) {
				$db = D();
				if (is_object($db) && method_exists($db, 'beginTransaction')) {
					$db->beginTransaction();
					$started = true;
				}
			}

			foreach ($ops as $i => $op) {
				$applied[] = self::executeOne($op, $i);
			}

			if ($started && $db !== null && method_exists($db, 'commit')) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($started && $db !== null && method_exists($db, 'rollBack')) {
				$db->rollBack();
			}
			throw $e;
		}

		return [
			'data'    => $envelope[OpEnvelope::KEY_DATA],
			'ops'     => $ops,
			'applied' => $applied,
		];
	}

	/**
	 * 仅返回业务 data；若有 ops 则先 apply
	 *
	 * @param mixed               $result  Rpc result
	 * @param array<string,mixed> $options 同 apply
	 * @return mixed
	 */
	public static function applyAndData(mixed $result, array $options = []): mixed
	{
		return self::apply($result, $options)['data'];
	}

	/**
	 * 校验单条 op
	 *
	 * @param array<string,mixed> $op     命令
	 * @param list<string>        $models 模型白名单，空=不限制
	 * @param int                 $index  下标（报错用）
	 * @return void
	 */
	private static function validateOp(array $op, array $models, int $index): void
	{
		$type = (string)($op['op'] ?? '');
		if (!Op::isValid($type)) {
			throw new \InvalidArgumentException("RPC op[{$index}]: invalid op \"{$type}\"", -32602);
		}

		$model = trim((string)($op['model'] ?? ''));
		if ($model === '') {
			throw new \InvalidArgumentException("RPC op[{$index}]: model required", -32602);
		}
		if ($models !== [] && !in_array($model, $models, true)) {
			throw new \RuntimeException("RPC op[{$index}]: model not allowed: {$model}", -32003);
		}

		if ($type === Op::INSERT || $type === Op::UPDATE) {
			$fields = $op['fields'] ?? null;
			if (!is_array($fields) || $fields === []) {
				throw new \InvalidArgumentException("RPC op[{$index}]: fields required", -32602);
			}
		}

		if ($type === Op::UPDATE || $type === Op::DELETE) {
			$where = self::resolveWhere($op);
			if ($where === []) {
				throw new \InvalidArgumentException("RPC op[{$index}]: where or id required", -32602);
			}
		}
	}

	/**
	 * 执行单条
	 *
	 * @param array<string,mixed> $op    命令
	 * @param int                 $index 下标
	 * @return mixed 驱动返回值
	 */
	private static function executeOne(array $op, int $index): mixed
	{
		if (!function_exists('M')) {
			throw new \RuntimeException('RPC OpRunner requires M()');
		}

		$modelName = (string)$op['model'];
		$model = M($modelName);
		$type = (string)$op['op'];

		return match ($type) {
			Op::INSERT => $model->add($op['fields']),
			Op::UPDATE => $model->where(self::resolveWhere($op))->save($op['fields']),
			Op::DELETE => $model->where(self::resolveWhere($op))->delete(),
			default => throw new \InvalidArgumentException("RPC op[{$index}]: bad op", -32602),
		};
	}

	/**
	 * 解析 where：优先 where，否则 id+pk
	 *
	 * @param array<string,mixed> $op 命令
	 * @return array<string,mixed>
	 */
	private static function resolveWhere(array $op): array
	{
		if (isset($op['where']) && is_array($op['where']) && $op['where'] !== []) {
			return $op['where'];
		}
		if (array_key_exists('id', $op) && $op['id'] !== null && $op['id'] !== '') {
			$pk = (string)($op['pk'] ?? 'id');
			return [$pk => $op['id']];
		}
		return [];
	}
}

