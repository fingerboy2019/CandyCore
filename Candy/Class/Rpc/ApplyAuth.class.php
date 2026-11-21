<?php
/***
 * Rpc 本地落地验权（ops / queries）
 *
 * 业务说明：普通 result 不验权；含 ops 或 queries 时须 HMAC 签名，A 执行前校验
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:01:52 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Apply 验权：共享密钥 HMAC-SHA256
 *
 * 信封字段 auth: { ts, nonce, sign }
 * 签名正文：data + queries + ops + apply_order（不含 auth）的稳定 JSON
 */
final class ApplyAuth
{
	public const KEY_AUTH = 'auth';

	/**
	 * 是否需要对当前信封验权（存在非空 ops 或 queries）
	 *
	 * @param mixed $result Rpc result
	 * @return bool
	 */
	public static function requiresAuth(mixed $result): bool
	{
		$env = OpEnvelope::normalize($result);
		return $env[OpEnvelope::KEY_OPS] !== [] || $env[OpEnvelope::KEY_QUERIES] !== [];
	}

	/**
	 * 为信封签名（B 侧在 return 前调用）
	 *
	 * @param array<string,mixed> $envelope 信封
	 * @param string|null         $secret   密钥，默认读配置
	 * @return array<string,mixed> 带 auth 的信封
	 */
	public static function sign(array $envelope, ?string $secret = null): array
	{
		$secret = self::resolveSecret($secret);
		if ($secret === '') {
			throw new \RuntimeException('RPC apply_secret is empty; cannot sign envelope', -32004);
		}

		$ts = time();
		$nonce = bin2hex(random_bytes(8));
		$sign = self::computeSign($envelope, $ts, $nonce, $secret);

		$envelope[self::KEY_AUTH] = [
			'ts'    => $ts,
			'nonce' => $nonce,
			'sign'  => $sign,
		];
		return $envelope;
	}

	/**
	 * 有 ops/queries 时自动签名；纯 data 信封原样返回
	 *
	 * @param array<string,mixed> $envelope 信封
	 * @param string|null         $secret   密钥
	 * @return array<string,mixed>
	 */
	public static function signIfNeeded(array $envelope, ?string $secret = null): array
	{
		if (!self::requiresAuth($envelope)) {
			return $envelope;
		}
		if (!Config::applyAuth()) {
			return $envelope;
		}
		return self::sign($envelope, $secret);
	}

	/**
	 * 执行前断言验权通过（无 ops/queries 或未开启 apply_auth 则直接通过）
	 *
	 * @param mixed       $result Rpc result
	 * @param string|null $secret 密钥
	 * @return void
	 * @throws \RuntimeException 验权失败
	 */
	public static function assertBeforeApply(mixed $result, ?string $secret = null): void
	{
		if (!self::requiresAuth($result)) {
			return;
		}
		if (!Config::applyAuth()) {
			return;
		}
		if (!is_array($result) || !self::verify($result, $secret)) {
			throw new \RuntimeException('RPC apply auth failed: invalid or missing signature', -32005);
		}
	}

	/**
	 * 校验签名
	 *
	 * @param array<string,mixed> $envelope 信封
	 * @param string|null         $secret   密钥
	 * @return bool
	 */
	public static function verify(array $envelope, ?string $secret = null): bool
	{
		$secret = self::resolveSecret($secret);
		if ($secret === '') {
			return false;
		}

		$auth = $envelope[self::KEY_AUTH] ?? null;
		if (!is_array($auth)) {
			return false;
		}

		$ts = (int)($auth['ts'] ?? 0);
		$nonce = (string)($auth['nonce'] ?? '');
		$sign = (string)($auth['sign'] ?? '');
		if ($ts <= 0 || $nonce === '' || $sign === '') {
			return false;
		}

		$skew = Config::applySkew();
		if (abs(time() - $ts) > $skew) {
			return false;
		}

		$expect = self::computeSign($envelope, $ts, $nonce, $secret);
		return hash_equals($expect, $sign);
	}

	/**
	 * 计算签名
	 *
	 * @param array<string,mixed> $envelope 信封（可含 auth，计算时忽略）
	 * @param int                 $ts      时间戳
	 * @param string              $nonce   随机串
	 * @param string              $secret  密钥
	 * @return string hex HMAC
	 */
	private static function computeSign(array $envelope, int $ts, string $nonce, string $secret): string
	{
		unset($envelope[self::KEY_AUTH]);
		$payload = [
			'data'        => $envelope[OpEnvelope::KEY_DATA] ?? null,
			'queries'     => $envelope[OpEnvelope::KEY_QUERIES] ?? [],
			'ops'         => $envelope[OpEnvelope::KEY_OPS] ?? [],
			'apply_order' => $envelope[OpEnvelope::KEY_APPLY_ORDER] ?? null,
			'ts'          => $ts,
			'nonce'       => $nonce,
		];
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException('RPC apply sign payload encode failed', -32004);
		}
		return hash_hmac('sha256', $json, $secret);
	}

	/**
	 * @param string|null $secret 覆盖密钥
	 * @return string
	 */
	private static function resolveSecret(?string $secret): string
	{
		if ($secret !== null) {
			return $secret;
		}
		return Config::applySecret();
	}
}

