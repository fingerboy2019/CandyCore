<?php
/***
 * 规范串构建
 *
 * 业务说明：API 硬签与下载侧共用的参数规范化
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:47:57 $
 *
 * @package Candy\Extend\Signer\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Kernel;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Canonical string builder
 */
final class CanonicalBuilder
{
	/** @var list<string> 签名字段（API/下载均排除） */
	private const SIGN_SKIP = [
		'_sign', 'sign', 'signature',
		'x-signature', 'x_signature',
	];

	/**
	 * API 元数据：已进入规范串独立行，不得再进 canonicalQuery
	 * （否则 Header 签发与 query 回落会对不齐）
	 *
	 * @var list<string>
	 */
	private const API_META_SKIP = [
		'_t', '_n', '_app',
		'timestamp', 'nonce', 'app_id', 'appid',
		'x-timestamp', 'x-nonce', 'x-app-id', 'x-appid', 'x-time',
	];

	/**
	 * API 规范串
	 *
	 * ts/nonce/appId 只出现在独立行，不参与 query 段
	 *
	 * @param string $method HTTP 方法
	 * @param string $path URI path（不含 query）
	 * @param array<string,mixed> $query 查询参数
	 * @param string $body 原始 body
	 * @param string|int $timestamp 时间戳
	 * @param string $nonce 一次性串
	 * @param string $appId 应用 ID，无租户用 -
	 * @return string
	 */
	public static function forApi(
		string $method,
		string $path,
		array $query,
		string $body,
		string|int $timestamp,
		string $nonce,
		string $appId
	): string {
		$method = strtoupper(trim($method));
		$path = self::normalizePath($path);
		$queryStr = self::canonicalQuery($query, true);
		$bodyHash = hash('sha256', $body);
		$app = $appId !== '' ? $appId : '-';
		return $method . "\n"
			. $path . "\n"
			. $queryStr . "\n"
			. $bodyHash . "\n"
			. (string)$timestamp . "\n"
			. $nonce . "\n"
			. $app;
	}

	/**
	 * 下载 / 通用参数签名串（字典序 k=v&...）
	 *
	 * 保留 _t/_e/_app；仅剔除签名字段
	 *
	 * @param array<string,mixed> $params 参数（勿含 _sign）
	 * @return string
	 */
	public static function forParams(array $params): string
	{
		return self::canonicalQuery($params, false);
	}

	/**
	 * 规范化 path
	 *
	 * @param string $path 路径
	 * @return string
	 */
	public static function normalizePath(string $path): string
	{
		$path = trim($path);
		if ($path === '') {
			return '/';
		}
		if ($path[0] !== '/') {
			$path = '/' . $path;
		}
		return $path;
	}

	/**
	 * 查询串规范化
	 *
	 * @param array<string,mixed> $params 参数
	 * @param bool $apiMode true=API（再剔元数据）；false=下载
	 * @return string
	 */
	public static function canonicalQuery(array $params, bool $apiMode = false): string
	{
		$filtered = [];
		foreach ($params as $k => $v) {
			$key = (string)$k;
			if ($key === '' || self::shouldSkip($key, $apiMode)) {
				continue;
			}
			if (is_array($v) || is_object($v)) {
				$v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			} elseif (is_bool($v)) {
				$v = $v ? '1' : '0';
			} elseif ($v === null) {
				$v = '';
			} else {
				$v = (string)$v;
			}
			$filtered[$key] = $v;
		}
		ksort($filtered, SORT_STRING);
		$parts = [];
		foreach ($filtered as $k => $v) {
			$parts[] = rawurlencode($k) . '=' . rawurlencode($v);
		}
		return implode('&', $parts);
	}

	/**
	 * 是否跳过签名字段 / API 元数据
	 *
	 * @param string $key 键名
	 * @param bool $apiMode API 模式
	 * @return bool
	 */
	private static function shouldSkip(string $key, bool $apiMode): bool
	{
		$lower = strtolower($key);
		if (in_array($lower, self::SIGN_SKIP, true)) {
			return true;
		}
		if (str_starts_with($lower, 'x-signature')) {
			return true;
		}
		if ($apiMode && in_array($lower, self::API_META_SKIP, true)) {
			return true;
		}
		return false;
	}
}
