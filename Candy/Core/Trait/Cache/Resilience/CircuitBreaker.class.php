<?php
/**
 * 熔断器（网络驱动共用）
 *
 * 支持 half-open：恢复窗口到期后仅放行一次探测请求，成功则关闭，失败则重新打开
 *
 * @package Candy\Core\Trait\Cache\Resilience
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-18
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Resilience;

use Throwable;

trait CircuitBreaker
{
	private int $circuitFailureCount = 0;

	private float $circuitOpenTime = 0.0;

	private bool $circuitIsOpen = false;

	/** half-open：正在探测中，其它请求仍拒绝 */
	private bool $circuitHalfOpen = false;

	private bool $circuitProbeInFlight = false;

	private bool $circuitBreakerEnabled = true;

	private int $circuitFailureThreshold = 5;

	private int $circuitRecoverySeconds = 10;

	/**
	 * @param array<string, mixed> $config 驱动配置或 circuitBreaker 子配置
	 */
	protected function configureCircuitBreaker(array $config): void
	{
		$cb = is_array($config['circuitBreaker'] ?? null) ? $config['circuitBreaker'] : $config;

		if (array_key_exists('enabled', $cb) || array_key_exists('enableCircuitBreaker', $cb)) {
			$this->circuitBreakerEnabled = (bool)($cb['enabled'] ?? $cb['enableCircuitBreaker']);
		}

		$this->circuitFailureThreshold = max(
			1,
			(int)($cb['failureThreshold'] ?? $cb['fuseFailureThreshold'] ?? 5)
		);
		$this->circuitRecoverySeconds = max(
			1,
			(int)($cb['recoveryTime'] ?? $cb['fuseRecoveryTime'] ?? 10)
		);
	}

	protected function isCircuitBreakerEnabled(): bool
	{
		return $this->circuitBreakerEnabled;
	}

	/**
	 * 是否允许发起远端请求
	 *
	 * 打开态：未到恢复时间 → 拒绝；到期 → 进入 half-open，仅允许一条探测
	 */
	protected function isFuseAllow(): bool
	{
		if (!$this->circuitBreakerEnabled || !$this->circuitIsOpen) {
			return true;
		}

		if (microtime(true) - $this->circuitOpenTime < $this->circuitRecoverySeconds) {
			return false;
		}

		// 恢复窗口已过：半开探测
		if ($this->circuitHalfOpen && $this->circuitProbeInFlight) {
			return false;
		}

		$this->circuitHalfOpen = true;
		$this->circuitProbeInFlight = true;
		return true;
	}

	protected function recordSuccess(): void
	{
		if (!$this->circuitBreakerEnabled) {
			return;
		}

		$this->circuitFailureCount = 0;
		$this->circuitIsOpen = false;
		$this->circuitHalfOpen = false;
		$this->circuitProbeInFlight = false;
	}

	protected function recordFailure(): void
	{
		if (!$this->circuitBreakerEnabled) {
			return;
		}

		// 半开探测失败：重新打开并重置计时
		if ($this->circuitHalfOpen) {
			$this->circuitIsOpen = true;
			$this->circuitHalfOpen = false;
			$this->circuitProbeInFlight = false;
			$this->circuitOpenTime = microtime(true);
			$this->circuitFailureCount = $this->circuitFailureThreshold;
			return;
		}

		$this->circuitFailureCount++;
		if ($this->circuitFailureCount >= $this->circuitFailureThreshold) {
			$this->circuitIsOpen = true;
			$this->circuitOpenTime = microtime(true);
			$this->circuitHalfOpen = false;
			$this->circuitProbeInFlight = false;
		}
	}

	protected function recordCircuitOutcome(bool $success): void
	{
		$success ? $this->recordSuccess() : $this->recordFailure();
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @param T $fallback
	 * @return T
	 */
	protected function executeWithFuse(callable $operation, mixed $fallback): mixed
	{
		if (!$this->isFuseAllow()) {
			return $fallback;
		}

		try {
			$result = $operation();
			$this->recordSuccess();

			return $result;
		} catch (Throwable) {
			$this->recordFailure();

			return $fallback;
		}
	}

	/**
	 * @param callable(): bool $operation
	 */
	protected function executeWithFuseBool(callable $operation, bool $fallback = false): bool
	{
		if (!$this->isFuseAllow()) {
			return $fallback;
		}

		try {
			$result = (bool)$operation();
			$this->recordCircuitOutcome($result);

			return $result;
		} catch (Throwable) {
			$this->recordFailure();

			return $fallback;
		}
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @param T $fallback
	 * @return T
	 */
	protected function executeWithFuseOutcome(callable $operation, mixed $fallback, callable $isSuccess): mixed
	{
		if (!$this->isFuseAllow()) {
			return $fallback;
		}

		try {
			$result = $operation();
			$this->recordCircuitOutcome((bool)$isSuccess($result));

			return $result;
		} catch (Throwable) {
			$this->recordFailure();

			return $fallback;
		}
	}
}
