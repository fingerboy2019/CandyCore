<?php
/***
 * API 硬签
 *
 * 业务说明：method+path+query+bodyHash+ts+nonce+appId；Header 传签；支持 hmac/rsa/ecdsa
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:47:57 $
 *
 * @package Candy\Extend\Signer\Api
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Api;

use Candy\Extend\Signer\Bootstrap;
use Candy\Core\Request;
use Candy\Extend\Signer\CredentialResolver;
use Candy\Extend\Signer\Kernel\CanonicalBuilder;
use Candy\Extend\Signer\Kernel\EngineFactory;
use Candy\Extend\Signer\Nonce\NonceStore;
use Candy\Extend\Signer\SignerException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * HTTP API 签名与验签
 */
final class ApiSigner
{
	/**
	 * 签发请求签名
	 *
	 * @param string $method 方法
	 * @param string $path 路径
	 * @param array<string,mixed> $query 查询参数
	 * @param string $body 原始 body
	 * @param string|null $appId 应用 ID（single 可空）
	 * @param string|null $nonce 空则自动生成
	 * @param int|null $timestamp 空则 time()
	 * @return array{
	 *   app_id:string,timestamp:int,nonce:string,signature:string,sign_type:string,
	 *   headers:array<string,string>
	 * }
	 */
	public static function sign(
		string $method,
		string $path,
		array $query = [],
		string $body = '',
		?string $appId = null,
		?string $nonce = null,
		?int $timestamp = null
	): array {
		$cred = CredentialResolver::resolveForSign($appId);
		$ts = $timestamp ?? time();
		$nonce = $nonce ?? bin2hex(random_bytes(16));
		$canonicalApp = self::canonicalAppId($appId, $cred->appId);

		$canonical = CanonicalBuilder::forApi($method, $path, $query, $body, $ts, $nonce, $canonicalApp);
		$engine = EngineFactory::make($cred->signType);
		$signature = $engine->sign($canonical, $cred);

		$app = $canonicalApp === '-' ? '' : $canonicalApp;
		$headers = self::buildHeaders($ts, $nonce, $signature, $app);

		return [
			'app_id'    => $app,
			'timestamp' => $ts,
			'nonce'     => $nonce,
			'signature' => $signature,
			'sign_type' => $cred->signType,
			'headers'   => $headers,
		];
	}

	/**
	 * 仅返回可挂到 HTTP 请求的签名头（等价 sign()['headers']）
	 *
	 * @param string $method 方法
	 * @param string $path 路径
	 * @param array<string,mixed> $query 查询
	 * @param string $body body
	 * @param string|null $appId 应用
	 * @return array<string,string>
	 */
	public static function signHeaders(
		string $method,
		string $path,
		array $query = [],
		string $body = '',
		?string $appId = null
	): array {
		return self::sign($method, $path, $query, $body, $appId)['headers'];
	}

	/**
	 * 合并业务头与签名头（签名头覆盖同名键）
	 *
	 * @param array<string,string> $base 业务头
	 * @param array<string,string> $signedHeaders sign()/signHeaders() 结果
	 * @return array<string,string>
	 */
	public static function mergeHeaders(array $base, array $signedHeaders): array
	{
		return array_merge($base, $signedHeaders);
	}

	/**
	 * 验签（标准读 Header；query 仅兼容回落）
	 *
	 * @param string $method 方法
	 * @param string $path 路径
	 * @param array<string,mixed> $query 查询
	 * @param string $body body
	 * @param array<string,mixed> $headers 头（小写键亦可）
	 * @return array{app_id:string,credential_app:string,sign_type:string}
	 */
	public static function verify(
		string $method,
		string $path,
		array $query,
		string $body,
		array $headers = []
	): array {
		Bootstrap::assertEnabled();
		$h = self::normalizeHeaders($headers);

		$appId = self::pick($h, ['x-app-id', 'x-appid', 'app-id'])
			?? (isset($query['_app']) ? (string)$query['_app'] : null)
			?? (isset($query['app_id']) ? (string)$query['app_id'] : null);

		$timestamp = self::pick($h, ['x-timestamp', 'x-time'])
			?? (isset($query['_t']) ? (string)$query['_t'] : null)
			?? (isset($query['timestamp']) ? (string)$query['timestamp'] : null);

		$nonce = self::pick($h, ['x-nonce'])
			?? (isset($query['_n']) ? (string)$query['_n'] : null)
			?? (isset($query['nonce']) ? (string)$query['nonce'] : null);

		$signature = self::pick($h, ['x-signature', 'x-sign'])
			?? (isset($query['_sign']) ? (string)$query['_sign'] : null)
			?? (isset($query['sign']) ? (string)$query['sign'] : null);

		if ($timestamp === null || $timestamp === '' || $nonce === null || $nonce === '' || $signature === null || $signature === '') {
			throw new SignerException(SignerException::ERR_PARAM, 'Missing timestamp/nonce/signature', 401);
		}

		$ts = (int)$timestamp;
		$now = time();
		$skew = Bootstrap::skew();
		if (abs($now - $ts) > $skew) {
			throw new SignerException(SignerException::ERR_EXPIRED, 'Timestamp out of skew window', 401);
		}

		$cred = CredentialResolver::resolve($appId);
		$canonicalApp = self::canonicalAppId($appId, $cred->appId);

		$canonical = CanonicalBuilder::forApi($method, $path, $query, $body, $ts, (string)$nonce, $canonicalApp);
		$engine = EngineFactory::make($cred->signType);
		if (!$engine->verify($canonical, (string)$signature, $cred)) {
			throw new SignerException(SignerException::ERR_SIGNATURE, 'Invalid signature', 403);
		}

		NonceStore::consume((string)$nonce, $cred->appId);

		return [
			'app_id'         => $canonicalApp === '-' ? '' : $canonicalApp,
			'credential_app' => $cred->appId,
			'sign_type'      => $cred->signType,
		];
	}

	/**
	 * 验签当前 PHP 请求（自动取 method/path/query/body/headers）
	 *
	 * @param string|null $path 覆盖 path；空则从 REQUEST_URI 解析
	 * @return array{app_id:string,credential_app:string,sign_type:string}
	 */
	public static function verifyRequest(?string $path = null): array
	{
		$method = strtoupper((string)I('server.REQUEST_METHOD', 'GET', ''));
		if ($path === null || $path === '') {
			$uri = (string)I('server.REQUEST_URI', '/', '');
			$parsed = parse_url($uri, PHP_URL_PATH);
			$path = is_string($parsed) && $parsed !== '' ? $parsed : '/';
		}
		$body = I('raw');
		$query = I('get.', [], '');
		return self::verify($method, $path, $query, $body, self::collectRequestHeaders());
	}

	/**
	 * 从当前请求收集 Header（兼容无 getallheaders 的环境）
	 *
	 * @return array<string,string>
	 */
	public static function collectRequestHeaders(): array
	{
		if (Request::inCoroutine()) {
			return array_map(static fn(mixed $value): string => is_array($value) ? (string)($value[0] ?? '') : (string)$value, R()->header());
		}
		if (function_exists('getallheaders')) {
			$h = getallheaders();
			if (is_array($h) && $h !== []) {
				$out = [];
				foreach ($h as $k => $v) {
					$out[(string)$k] = is_array($v) ? (string)($v[0] ?? '') : (string)$v;
				}
				return $out;
			}
		}
		$out = [];
		foreach (I('server.', [], '') as $k => $v) {
			if (!is_string($k) || !str_starts_with($k, 'HTTP_')) {
				continue;
			}
			$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
			$out[$name] = (string)$v;
		}
		return $out;
	}

	/**
	 * 规范串用的 AppId（single 空 → `-`）
	 *
	 * @param string|null $appId 请求侧 AppId
	 * @param string $credentialApp 凭证 appId
	 * @return string
	 */
	private static function canonicalAppId(?string $appId, string $credentialApp): string
	{
		$appId = $appId !== null ? trim($appId) : null;
		if ($appId === '') {
			$appId = null;
		}
		if (Bootstrap::mode() === 'multi') {
			if ($appId !== null) {
				return $appId;
			}
			return $credentialApp !== '' && $credentialApp !== 'default' ? $credentialApp : '-';
		}
		return ($appId === null) ? '-' : $appId;
	}

	/**
	 * 组装标准签名头
	 *
	 * @param int $ts 时间戳
	 * @param string $nonce nonce
	 * @param string $signature 签名
	 * @param string $appId 空则不带 X-App-Id
	 * @return array<string,string>
	 */
	private static function buildHeaders(int $ts, string $nonce, string $signature, string $appId): array
	{
		$headers = [
			'X-Timestamp' => (string)$ts,
			'X-Nonce'     => $nonce,
			'X-Signature' => $signature,
		];
		if ($appId !== '') {
			$headers['X-App-Id'] = $appId;
		}
		return $headers;
	}

	/**
	 * Header 小写化
	 *
	 * @param array<string,mixed> $headers 头
	 * @return array<string,string>
	 */
	private static function normalizeHeaders(array $headers): array
	{
		$out = [];
		foreach ($headers as $k => $v) {
			if (is_array($v)) {
				$v = $v[0] ?? '';
			}
			$out[strtolower((string)$k)] = (string)$v;
		}
		return $out;
	}

	/**
	 * 取第一个非空头
	 *
	 * @param array<string,string> $headers 头
	 * @param list<string> $keys 键
	 * @return string|null
	 */
	private static function pick(array $headers, array $keys): ?string
	{
		foreach ($keys as $k) {
			if (isset($headers[$k]) && $headers[$k] !== '') {
				return $headers[$k];
			}
		}
		return null;
	}
}
