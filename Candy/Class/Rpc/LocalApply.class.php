<?php
/***
 * Rpc 本地落地流水线
 *
 * 业务说明：默认先查后写（query_op）；可配置/信封/调用参数改为先写再查（op_query）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:56:00 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * A 侧统一执行远端下发的 queries / ops
 */
final class LocalApply
{
	/** 先查询再写库（默认） */
	public const ORDER_QUERY_OP = 'query_op';

	/** 先写库再查询 */
	public const ORDER_OP_QUERY = 'op_query';

	/**
	 * 执行查询方案与写库命令
	 *
	 * @param mixed               $result  信封
	 * @param array<string,mixed> $options 含 apply_order；其余传给 QueryRunner / OpRunner
	 * @return array{data:mixed,queries:list<array>,ops:list<array>,fetched:array,applied:list<mixed>,apply_order:string}
	 */
	public static function run(mixed $result, array $options = []): array
	{
		ApplyAuth::assertBeforeApply($result, isset($options['apply_secret']) ? (string)$options['apply_secret'] : null);

		$order = self::resolveOrder($result, $options);
		$runnerOpts = $options;
		unset($runnerOpts['apply_order'], $runnerOpts['apply_secret'], $runnerOpts['skip_auth']);

		// 已在入口验权，子 Runner 跳过重复校验
		$runnerOpts['skip_auth'] = true;

		if ($order === self::ORDER_OP_QUERY) {
			return self::runWriteThenQuery($result, $runnerOpts, $order);
		}

		return self::runQueryThenWrite($result, $runnerOpts, $order);
	}

	/**
	 * 仅返回合并后的业务 data
	 *
	 * @param mixed               $result  信封
	 * @param array<string,mixed> $options 选项
	 * @return mixed
	 */
	public static function runAndData(mixed $result, array $options = []): mixed
	{
		return self::run($result, $options)['data'];
	}

	/**
	 * 先查后写
	 *
	 * @param mixed               $result
	 * @param array<string,mixed> $options
	 * @param string              $order
	 * @return array<string,mixed>
	 */
	private static function runQueryThenWrite(mixed $result, array $options, string $order): array
	{
		$afterQuery = QueryRunner::apply($result, $options);
		$forWrite = [
			OpEnvelope::KEY_DATA     => $afterQuery['data'],
			OpEnvelope::KEY_OPS      => $afterQuery['ops'] ?? OpEnvelope::ops($result),
			OpEnvelope::KEY_QUERIES  => [],
		];
		$afterOps = OpRunner::apply($forWrite, $options);

		return [
			'data'        => $afterOps['data'],
			'queries'     => $afterQuery['queries'],
			'ops'         => $afterOps['ops'],
			'fetched'     => $afterQuery['fetched'],
			'applied'     => $afterOps['applied'],
			'apply_order' => $order,
		];
	}

	/**
	 * 先写后查（写完再按方案读最新数据）
	 *
	 * @param mixed               $result
	 * @param array<string,mixed> $options
	 * @param string              $order
	 * @return array<string,mixed>
	 */
	private static function runWriteThenQuery(mixed $result, array $options, string $order): array
	{
		$envelope = OpEnvelope::normalize($result);
		$forWrite = [
			OpEnvelope::KEY_DATA    => $envelope[OpEnvelope::KEY_DATA],
			OpEnvelope::KEY_OPS     => $envelope[OpEnvelope::KEY_OPS],
			OpEnvelope::KEY_QUERIES => [],
		];
		$afterOps = OpRunner::apply($forWrite, $options);

		$forQuery = [
			OpEnvelope::KEY_DATA    => $afterOps['data'],
			OpEnvelope::KEY_QUERIES => $envelope[OpEnvelope::KEY_QUERIES],
			OpEnvelope::KEY_OPS     => [],
		];
		$afterQuery = QueryRunner::apply($forQuery, $options);

		return [
			'data'        => $afterQuery['data'],
			'queries'     => $afterQuery['queries'],
			'ops'         => $afterOps['ops'],
			'fetched'     => $afterQuery['fetched'],
			'applied'     => $afterOps['applied'],
			'apply_order' => $order,
		];
	}

	/**
	 * 解析执行顺序：调用参数 > 信封 apply_order > 配置 > 默认 query_op
	 *
	 * @param mixed               $result  信封
	 * @param array<string,mixed> $options 选项
	 * @return string
	 */
	private static function resolveOrder(mixed $result, array $options): string
	{
		if (isset($options['apply_order'])) {
			return self::normalizeOrder((string)$options['apply_order']);
		}

		if (is_array($result) && isset($result[OpEnvelope::KEY_APPLY_ORDER])) {
			return self::normalizeOrder((string)$result[OpEnvelope::KEY_APPLY_ORDER]);
		}

		return self::normalizeOrder(Config::applyOrder());
	}

	/**
	 * @param string $order 原始顺序
	 * @return string
	 */
	private static function normalizeOrder(string $order): string
	{
		$order = strtolower(trim($order));
		return $order === self::ORDER_OP_QUERY ? self::ORDER_OP_QUERY : self::ORDER_QUERY_OP;
	}
}

