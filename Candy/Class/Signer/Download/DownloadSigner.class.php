<?php
/***
 * 下载链接签名
 *
 * 业务说明：_t/_e/_sign；可选 AES 加密参数；对齐 url-signer 场景
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Download
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Download;

use Candy\Extend\Signer\Bootstrap;
use Candy\Extend\Signer\CredentialResolver;
use Candy\Extend\Signer\Kernel\CanonicalBuilder;
use Candy\Extend\Signer\Kernel\EngineFactory;
use Candy\Extend\Signer\SignerException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 临时下载 URL 签名
 */
final class DownloadSigner
{
	/**
	 * 生成签名 URL
	 *
	 * @param string $url 基址（可含已有 query）
	 * @param array<string,mixed> $params 业务参数
	 * @param int|null $expire 有效秒数
	 * @param string|null $appId 应用
	 * @param bool $encryptValues 是否 AES 加密字符串参数值
	 * @return string
	 */
	public static function sign(
		string $url,
		array $params = [],
		?int $expire = null,
		?string $appId = null,
		bool $encryptValues = false
	): string {
		$cred = CredentialResolver::resolveForSign($appId);
		$cfg = Bootstrap::config()['download'] ?? [];
		$expire = $expire ?? (int)($cfg['default_expire'] ?? 3600);
		$expire = max(1, $expire);

		if ($encryptValues) {
			foreach ($params as $k => $v) {
				if (is_string($v) && $v !== '') {
					$params[$k] = self::encrypt($v);
				}
			}
		}

		$params['_t'] = (string)time();
		$params['_e'] = (string)$expire;
		if ($appId) {
			$params['_app'] = $appId;
		}

		$canonical = CanonicalBuilder::forParams($params);
		$engine = EngineFactory::make($cred->signType);
		$params['_sign'] = $engine->sign($canonical, $cred);

		$parts = parse_url($url);
		$base = self::buildBase($parts);
		$query = [];
		if (!empty($parts['query'])) {
			parse_str($parts['query'], $query);
		}
		$query = array_merge($query, $params);
		return $base . '?' . http_build_query($query);
	}

	/**
	 * 验签查询参数
	 *
	 * @param array<string,mixed> $params 含 _sign/_t/_e
	 * @return array<string,mixed> 去掉签名字段后的参数
	 */
	public static function validate(array $params): array
	{
		Bootstrap::assertEnabled();
		$sign = (string)($params['_sign'] ?? $params['sign'] ?? '');
		$t = (int)($params['_t'] ?? 0);
		$e = (int)($params['_e'] ?? 0);
		if ($sign === '' || $t <= 0 || $e <= 0) {
			throw new SignerException(SignerException::ERR_PARAM, 'Missing download sign fields');
		}
		if (time() > ($t + $e)) {
			throw new SignerException(SignerException::ERR_EXPIRED, 'Download link expired', 403);
		}

		$appId = isset($params['_app']) ? (string)$params['_app'] : null;
		$cred = CredentialResolver::resolve($appId);

		$check = $params;
		unset($check['_sign'], $check['sign']);
		$canonical = CanonicalBuilder::forParams($check);
		$engine = EngineFactory::make($cred->signType);
		if (!$engine->verify($canonical, $sign, $cred)) {
			throw new SignerException(SignerException::ERR_SIGNATURE, 'Invalid download signature');
		}

		unset($params['_sign'], $params['sign'], $params['_t'], $params['_e'], $params['_app']);
		return $params;
	}

	/**
	 * AES-256-CBC 加密（openssl）
	 *
	 * @param string $plain 明文
	 * @return string base64(iv+cipher)
	 */
	public static function encrypt(string $plain): string
	{
		$key = self::aesKey();
		$iv = random_bytes(16);
		$cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
		if ($cipher === false) {
			throw new SignerException(SignerException::ERR_PARAM, 'AES encrypt failed');
		}
		return base64_encode($iv . $cipher);
	}

	/**
	 * AES 解密
	 *
	 * @param string $encoded 密文
	 * @return string|false
	 */
	public static function decrypt(string $encoded): string|false
	{
		$key = self::aesKey();
		$raw = base64_decode($encoded, true);
		if ($raw === false || strlen($raw) < 17) {
			return false;
		}
		$iv = substr($raw, 0, 16);
		$cipher = substr($raw, 16);
		$plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
		return $plain === false ? false : $plain;
	}

	/**
	 * 解密参数并可选校验路径前缀
	 *
	 * @param string $value 密文
	 * @return string
	 */
	public static function decryptParam(string $value): string
	{
		$plain = self::decrypt($value);
		if ($plain === false) {
			throw new SignerException(SignerException::ERR_PARAM, 'Decrypt failed');
		}
		$prefixes = Bootstrap::config()['download']['path_allow_prefix'] ?? [];
		if (is_array($prefixes) && $prefixes !== []) {
			$ok = false;
			foreach ($prefixes as $p) {
				$p = (string)$p;
				if ($p !== '' && str_starts_with($plain, $p)) {
					$ok = true;
					break;
				}
			}
			if (!$ok) {
				throw new SignerException(SignerException::ERR_PARAM, 'Path not allowed');
			}
		}
		return $plain;
	}

	/**
	 * AES key 32 bytes
	 *
	 * @return string
	 */
	private static function aesKey(): string
	{
		$cfg = Bootstrap::config()['download'] ?? [];
		$key = (string)($cfg['aes_key'] ?? '');
		if ($key === '') {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'download.aes_key not configured');
		}
		return hash('sha256', $key, true);
	}

	/**
	 * 拼回 base URL
	 *
	 * @param array<string,mixed> $parts parse_url
	 * @return string
	 */
	private static function buildBase(array $parts): string
	{
		if (empty($parts['scheme']) || empty($parts['host'])) {
			// 相对路径
			$path = $parts['path'] ?? '';
			return $path !== '' ? $path : '/';
		}
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		$path = $parts['path'] ?? '';
		return $parts['scheme'] . '://' . $parts['host'] . $port . $path;
	}
}
