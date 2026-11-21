<?php
/***
 * 雪花 ID 生成器
 *
 * 业务说明：分布式 64 位趋势递增唯一 ID（41 位时间 + 5 位机房 + 5 位机器 + 12 位序列）
 * 默认返回字符串，避免 JSON/JavaScript 大整数精度丢失
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 21:55:00 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Snowflake {

	/** 自定义纪元：2020-01-01 00:00:00 UTC（毫秒） */
	public const EPOCH_MS = 1_577_836_800_000;

	private const WORKER_BITS = 5;
	private const DATACENTER_BITS = 5;
	private const SEQUENCE_BITS = 12;

	private const MAX_WORKER_ID = (1 << self::WORKER_BITS) - 1;
	private const MAX_DATACENTER_ID = (1 << self::DATACENTER_BITS) - 1;
	private const MAX_SEQUENCE = (1 << self::SEQUENCE_BITS) - 1;

	private const TIMESTAMP_SHIFT = self::WORKER_BITS + self::DATACENTER_BITS + self::SEQUENCE_BITS;
	private const DATACENTER_SHIFT = self::WORKER_BITS + self::SEQUENCE_BITS;
	private const WORKER_SHIFT = self::SEQUENCE_BITS;

	private int $workerId;
	private int $datacenterId;
	private int $sequence = 0;
	private int $lastTimestamp = -1;

	private static ?self $default = null;

	/**
	 * 构造雪花生成器
	 *
	 * @param int $workerId 机器 ID（0–31）
	 * @param int $datacenterId 机房 ID（0–31）
	 * @throws \InvalidArgumentException 参数越界
	 */
	public function __construct(int $workerId = 0, int $datacenterId = 0)
	{
		if ($workerId < 0 || $workerId > self::MAX_WORKER_ID) {
			throw new \InvalidArgumentException('workerId 必须在 0–' . self::MAX_WORKER_ID . ' 之间');
		}
		if ($datacenterId < 0 || $datacenterId > self::MAX_DATACENTER_ID) {
			throw new \InvalidArgumentException('datacenterId 必须在 0–' . self::MAX_DATACENTER_ID . ' 之间');
		}
		$this->workerId = $workerId;
		$this->datacenterId = $datacenterId;
	}

	/**
	 * 生成下一个雪花 ID（字符串）
	 *
	 * @return string
	 */
	public function nextId(): string
	{
		return (string) $this->nextIdInt();
	}

	/**
	 * 生成下一个雪花 ID（整数，仅 64 位 PHP 环境安全）
	 *
	 * @return int
	 */
	public function nextIdInt(): int
	{
		$timestamp = $this->currentTimeMillis();

		if ($timestamp < $this->lastTimestamp) {
			throw new \RuntimeException('系统时钟回拨，拒绝生成雪花 ID');
		}

		if ($timestamp === $this->lastTimestamp) {
			$this->sequence = ($this->sequence + 1) & self::MAX_SEQUENCE;
			if ($this->sequence === 0) {
				$timestamp = $this->waitNextMillis($this->lastTimestamp);
			}
		} else {
			$this->sequence = 0;
		}

		$this->lastTimestamp = $timestamp;

		$delta = $timestamp - self::EPOCH_MS;
		if ($delta < 0) {
			throw new \RuntimeException('当前时间早于雪花纪元');
		}

		return ($delta << self::TIMESTAMP_SHIFT)
			| ($this->datacenterId << self::DATACENTER_SHIFT)
			| ($this->workerId << self::WORKER_SHIFT)
			| $this->sequence;
	}

	/**
	 * 使用默认配置生成 ID（快捷入口）
	 *
	 * 配置项 C('snowflake')：worker_id、datacenter_id
	 * 或环境变量 SNOWFLAKE_WORKER_ID、SNOWFLAKE_DATACENTER_ID
	 *
	 * @return string
	 */
	public static function id(): string
	{
		return self::defaultInstance()->nextId();
	}

	/**
	 * 解析雪花 ID 结构
	 *
	 * @param string|int $id 雪花 ID
	 * @return array{timestamp_ms:int,datetime:string,datacenter_id:int,worker_id:int,sequence:int}
	 */
	public static function parse(string|int $id): array
	{
		$value = is_int($id) ? $id : (int) $id;
		$sequence = $value & self::MAX_SEQUENCE;
		$workerId = ($value >> self::WORKER_SHIFT) & self::MAX_WORKER_ID;
		$datacenterId = ($value >> self::DATACENTER_SHIFT) & self::MAX_DATACENTER_ID;
		$timestampMs = ($value >> self::TIMESTAMP_SHIFT) + self::EPOCH_MS;

		return [
			'timestamp_ms' => $timestampMs,
			'datetime' => gmdate('Y-m-d H:i:s', (int) floor($timestampMs / 1000)),
			'datacenter_id' => $datacenterId,
			'worker_id' => $workerId,
			'sequence' => $sequence,
		];
	}

	/**
	 * 获取默认单例（按配置 / 环境变量）
	 *
	 * @return self
	 */
	public static function defaultInstance(): self
	{
		if (self::$default instanceof self) {
			return self::$default;
		}

		$cfg = [];
		if (function_exists('C')) {
			$raw = C('snowflake');
			if (is_array($raw)) {
				$cfg = $raw;
			}
		}

		$worker = (int) ($cfg['worker_id'] ?? getenv('SNOWFLAKE_WORKER_ID') ?: 0);
		$datacenter = (int) ($cfg['datacenter_id'] ?? getenv('SNOWFLAKE_DATACENTER_ID') ?: 0);

		self::$default = new self($worker, $datacenter);
		return self::$default;
	}

	/**
	 * 重置默认单例（测试用）
	 *
	 * @return void
	 */
	public static function resetDefault(): void
	{
		self::$default = null;
	}

	/**
	 * 当前毫秒时间戳
	 *
	 * @return int
	 */
	private function currentTimeMillis(): int
	{
		return (int) floor(microtime(true) * 1000);
	}

	/**
	 * 同毫秒内序列用尽时等待下一毫秒
	 *
	 * @param int $lastTimestamp 上一时间戳
	 * @return int
	 */
	private function waitNextMillis(int $lastTimestamp): int
	{
		$timestamp = $this->currentTimeMillis();
		while ($timestamp <= $lastTimestamp) {
			usleep(100);
			$timestamp = $this->currentTimeMillis();
		}
		return $timestamp;
	}
}

