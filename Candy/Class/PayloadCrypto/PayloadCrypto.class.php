<?php
/***
 * PayloadCrypto 门面
 *
 * 业务说明：字节/JSON 加解密、AAD 构造、HTTP requestBody、Rpc params/payload 封包
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 17:48:19 $
 *
 * @package Candy\Extend\PayloadCrypto
 */

declare(strict_types=1);

namespace Candy\Extend\PayloadCrypto;

use Candy\Extend\PayloadCrypto\Kernel\Envelope;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 载荷加解密门面
 */
final class PayloadCrypto
{
	public const HEADER = 'X-Payload-Crypto';
	public const HEADER_TS = 'X-Payload-Ts';
	public const HEADER_NONCE = 'X-Payload-Nonce';

	public const T_PLAIN = 'PAYLOAD_CRYPTO_PLAIN';
	public const T_ACTIVE = 'PAYLOAD_CRYPTO_ACTIVE';
	public const T_APP = 'PAYLOAD_CRYPTO_APP';
	public const T_META = 'PAYLOAD_CRYPTO_META';

	/**
	 * 是否启用
	 *
	 * @return bool
	 */
	public static function enabled(): bool
	{
		return Bootstrap::enabled();
	}

	/**
	 * 加密字节
	 *
	 * @param string $plain 明文
	 * @param string $aad AAD
	 * @param string|null $appId 应用
	 * @return string 线上形态
	 */
	public static function encryptBytes(string $plain, string $aad = '', ?string $appId = null): string
	{
		$key = KeyResolver::resolve($appId);
		$useAad = Bootstrap::aadEnabled() ? $aad : '';
		return Envelope::pack($plain, $key, $useAad);
	}

	/**
	 * 解密字节
	 *
	 * @param string $wire 线上
	 * @param string $aad AAD
	 * @param string|null $appId 应用
	 * @return string 明文
	 */
	public static function decryptBytes(string $wire, string $aad = '', ?string $appId = null): string
	{
		$key = KeyResolver::resolve($appId);
		$useAad = Bootstrap::aadEnabled() ? $aad : '';
		return Envelope::unpack($wire, $key, $useAad);
	}

	/**
	 * JSON 编码后加密
	 *
	 * @param mixed $data 可 JSON 数据
	 * @param string $aad AAD
	 * @param string|null $appId 应用
	 * @return string
	 */
	public static function encryptJson(mixed $data, string $aad = '', ?string $appId = null): string
	{
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'JSON encode failed');
		}
		return self::encryptBytes($json, $aad, $appId);
	}

	/**
	 * 解密后 JSON 解码
	 *
	 * @param string $wire 线上
	 * @param string $aad AAD
	 * @param string|null $appId 应用
	 * @return mixed
	 */
	public static function decryptJson(string $wire, string $aad = '', ?string $appId = null): mixed
	{
		$json = self::decryptBytes($wire, $aad, $appId);
		$data = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'JSON decode failed after decrypt');
		}
		return $data;
	}

	/**
	 * 读取当前请求明文 body（中间件解密后优先）
	 *
	 * @return string
	 */
	public static function requestBody(): string
	{
		if (function_exists('T')) {
			$plain = T(self::T_PLAIN);
			if (is_string($plain)) {
				return $plain;
			}
		}
		$raw = @file_get_contents('php://input');
		return is_string($raw) ? $raw : '';
	}

	/**
	 * HTTP 请求 AAD
	 *
	 * @param string $method 方法
	 * @param string $path 路径
	 * @param int|string $timestamp 时间戳
	 * @param string $nonce nonce
	 * @param string $appId 应用；空则为 -
	 * @return string
	 */
	public static function aadHttpRequest(
		string $method,
		string $path,
		int|string $timestamp,
		string $nonce,
		string $appId = ''
	): string {
		$app = $appId !== '' ? $appId : '-';
		return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $app;
	}

	/**
	 * HTTP 响应 AAD
	 *
	 * @param string $method 方法
	 * @param string $path 路径
	 * @param string $appId 应用
	 * @return string
	 */
	public static function aadHttpResponse(string $method, string $path, string $appId = ''): string
	{
		$app = $appId !== '' ? $appId : '-';
		return 'RES' . "\n" . strtoupper($method) . "\n" . $path . "\n" . $app;
	}

	/**
	 * RPC AAD
	 *
	 * @param string $class 服务
	 * @param string $method 方法
	 * @param string|int $id 请求 id
	 * @param string $direction req|res
	 * @return string
	 */
	public static function aadRpc(string $class, string $method, string|int $id, string $direction): string
	{
		$dir = $direction === 'res' ? 'res' : 'req';
		return 'rpc' . "\n" . $class . "\n" . $method . "\n" . $id . "\n" . $dir;
	}

	/**
	 * 加密 Rpc params 数组
	 *
	 * @param array<mixed> $params 参数
	 * @param string $class 服务
	 * @param string $method 方法
	 * @param string|int $id 请求 id
	 * @param string|null $appId 应用
	 * @return string
	 */
	public static function sealRpcParams(
		array $params,
		string $class,
		string $method,
		string|int $id,
		?string $appId = null
	): string {
		$aad = self::aadRpc($class, $method, $id, 'req');
		return self::encryptJson($params, $aad, $appId);
	}

	/**
	 * 解密 Rpc params
	 *
	 * @param string $wire 信封
	 * @param string $class 服务
	 * @param string $method 方法
	 * @param string|int $id 请求 id
	 * @param string|null $appId 应用
	 * @return array<mixed>
	 */
	public static function openRpcParams(
		string $wire,
		string $class,
		string $method,
		string|int $id,
		?string $appId = null
	): array {
		$aad = self::aadRpc($class, $method, $id, 'req');
		$data = self::decryptJson($wire, $aad, $appId);
		if (!is_array($data)) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'RPC params must decrypt to array');
		}
		return $data;
	}

	/**
	 * 加密 Rpc 响应 inner（result/error）
	 *
	 * @param array{result?:mixed,error?:mixed} $inner 内层
	 * @param string $class 服务
	 * @param string $method 方法
	 * @param string|int $id 请求 id
	 * @param string|null $appId 应用
	 * @return string
	 */
	public static function sealRpcPayload(
		array $inner,
		string $class,
		string $method,
		string|int $id,
		?string $appId = null
	): string {
		$aad = self::aadRpc($class, $method, $id, 'res');
		return self::encryptJson($inner, $aad, $appId);
	}

	/**
	 * 解密 Rpc 响应 payload
	 *
	 * @param string $wire 信封
	 * @param string $class 服务
	 * @param string $method 方法
	 * @param string|int $id 请求 id
	 * @param string|null $appId 应用
	 * @return array{result:mixed,error:mixed}
	 */
	public static function openRpcPayload(
		string $wire,
		string $class,
		string $method,
		string|int $id,
		?string $appId = null
	): array {
		$aad = self::aadRpc($class, $method, $id, 'res');
		$data = self::decryptJson($wire, $aad, $appId);
		if (!is_array($data)) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'RPC payload must decrypt to object');
		}
		return [
			'result' => $data['result'] ?? null,
			'error'  => $data['error'] ?? null,
		];
	}
}

