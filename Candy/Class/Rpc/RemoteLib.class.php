<?php
/***
 * Rpc 远程类库传输与安装
 *
 * 业务说明：白名单导出 PHP 源码；AES 加密 + HMAC 验签；落盘后 require（禁止 eval）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:15:24 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

use Candy\Extend\Mcrypt;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 远程类库包：加密发送、验签解密、落盘加载
 */
final class RemoteLib
{
	public const PACKET_VERSION = 1;

	/**
	 * 将本地白名单文件打包为加密验签包（B 侧）
	 *
	 * @param string      $name     逻辑名（白名单键）
	 * @param string      $filePath 绝对路径 .php
	 * @param string      $fqcn     可选 FQCN
	 * @param string|null $secret   覆盖密钥
	 * @return array<string,mixed>
	 */
	public static function packFile(string $name, string $filePath, string $fqcn = '', ?string $secret = null): array
	{
		self::assertEnabled();
		$name = self::sanitizeName($name);
		$filePath = realpath($filePath) ?: '';
		if ($filePath === '' || !is_file($filePath) || !str_ends_with(strtolower($filePath), '.php')) {
			throw new \RuntimeException("RemoteLib pack: invalid file for {$name}", -32010);
		}

		$source = file_get_contents($filePath);
		if ($source === false || $source === '') {
			throw new \RuntimeException("RemoteLib pack: empty source {$name}", -32010);
		}
		if (!str_contains($source, '<?php') && !str_starts_with(ltrim($source), '<?php')) {
			throw new \RuntimeException("RemoteLib pack: not a PHP source {$name}", -32010);
		}

		$secret = $secret ?? Config::remoteLibSecret();
		if ($secret === '') {
			throw new \RuntimeException('RemoteLib: remote_lib.secret / apply_secret empty', -32010);
		}

		$sha256 = hash('sha256', $source);
		$ts = time();
		$nonce = bin2hex(random_bytes(16));
		$cipher = Mcrypt::aes($source, $secret, 'en');
		if ($cipher === '') {
			throw new \RuntimeException('RemoteLib pack: encrypt failed', -32010);
		}

		$packet = [
			'v'      => self::PACKET_VERSION,
			'name'   => $name,
			'fqcn'   => $fqcn,
			'sha256' => $sha256,
			'ts'     => $ts,
			'nonce'  => $nonce,
			'cipher' => $cipher,
		];
		$packet['sign'] = self::signPacket($packet, $secret);
		return $packet;
	}

	/**
	 * 验签、解密、校验哈希后落盘并 require（A 侧）
	 *
	 * @param array<string,mixed> $packet 加密包
	 * @param string|null         $secret 覆盖密钥
	 * @return string 落盘绝对路径
	 */
	public static function install(array $packet, ?string $secret = null): string
	{
		self::assertEnabled();
		$secret = $secret ?? Config::remoteLibSecret();
		if ($secret === '') {
			throw new \RuntimeException('RemoteLib: secret empty', -32011);
		}

		self::verifyPacket($packet, $secret);

		$name = self::sanitizeName((string)($packet['name'] ?? ''));
		$cipher = (string)($packet['cipher'] ?? '');
		$source = Mcrypt::aes($cipher, $secret, 'de');
		if ($source === '') {
			throw new \RuntimeException('RemoteLib install: decrypt failed', -32011);
		}

		$expectSha = (string)($packet['sha256'] ?? '');
		if ($expectSha === '' || !hash_equals($expectSha, hash('sha256', $source))) {
			throw new \RuntimeException('RemoteLib install: sha256 mismatch', -32011);
		}
		if (!str_contains($source, '<?php')) {
			throw new \RuntimeException('RemoteLib install: decrypted content is not PHP', -32011);
		}

		$dir = Config::remoteLibStorage();
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new \RuntimeException('RemoteLib install: cannot create storage', -32011);
		}

		$path = $dir . DIRECTORY_SEPARATOR . $name . '.class.php';
		$tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (file_put_contents($tmp, $source) === false) {
			throw new \RuntimeException('RemoteLib install: write failed', -32011);
		}
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			throw new \RuntimeException('RemoteLib install: rename failed', -32011);
		}

		require_once $path;
		return $path;
	}

	/**
	 * 经 Rpc 拉取并安装（A 侧便捷方法）
	 *
	 * @param string $name   白名单逻辑名
	 * @param object $client rpc('System') 代理
	 * @return string 落盘路径
	 */
	public static function fetchAndInstall(string $name, object $client): string
	{
		if (!is_callable([$client, 'fetchLib'])) {
			throw new \RuntimeException('RemoteLib: client must expose fetchLib', -32012);
		}
		$packet = $client->fetchLib($name);
		if (!is_array($packet)) {
			throw new \RuntimeException('RemoteLib: invalid fetchLib response', -32012);
		}
		return self::install($packet);
	}

	/**
	 * 本地已安装则直接 require；否则经 Rpc 拉取
	 *
	 * @param string      $name   逻辑名
	 * @param object|null $client 需要拉取时传入 System 客户端
	 * @return string 文件路径
	 */
	public static function ensure(string $name, ?object $client = null): string
	{
		$name = self::sanitizeName($name);
		$path = Config::remoteLibStorage() . DIRECTORY_SEPARATOR . $name . '.class.php';
		if (is_file($path)) {
			require_once $path;
			return $path;
		}
		if ($client === null) {
			throw new \RuntimeException("RemoteLib ensure: {$name} not installed and no client", -32012);
		}
		return self::fetchAndInstall($name, $client);
	}

	/**
	 * 解析白名单项为文件路径
	 *
	 * @param string $name 逻辑名
	 * @param string $def  绝对路径或 FQCN
	 * @return array{path:string,fqcn:string}
	 */
	public static function resolveAllowEntry(string $name, string $def): array
	{
		$def = trim($def);
		if ($def === '') {
			throw new \RuntimeException("RemoteLib: empty allow entry for {$name}", -32010);
		}

		if (is_file($def) || str_ends_with(strtolower($def), '.php')) {
			$path = realpath($def) ?: $def;
			return ['path' => $path, 'fqcn' => ''];
		}

		if (!class_exists($def) && !interface_exists($def) && !trait_exists($def)) {
			// 尝试自动加载
			if (function_exists('class_exists')) {
				@class_exists($def);
			}
		}
		if (!class_exists($def) && !interface_exists($def) && !trait_exists($def)) {
			throw new \RuntimeException("RemoteLib: class not found {$def}", -32010);
		}

		$ref = new \ReflectionClass($def);
		$file = $ref->getFileName();
		if ($file === false || !is_file($file)) {
			throw new \RuntimeException("RemoteLib: cannot locate file for {$def}", -32010);
		}
		return ['path' => $file, 'fqcn' => $def];
	}

	/**
	 * @param array<string,mixed> $packet
	 * @param string              $secret
	 * @return void
	 */
	private static function verifyPacket(array $packet, string $secret): void
	{
		$v = (int)($packet['v'] ?? 0);
		if ($v !== self::PACKET_VERSION) {
			throw new \RuntimeException('RemoteLib: unsupported packet version', -32011);
		}
		$ts = (int)($packet['ts'] ?? 0);
		$skew = Config::remoteLibSkew();
		if ($ts <= 0 || abs(time() - $ts) > $skew) {
			throw new \RuntimeException('RemoteLib: packet expired or bad ts', -32011);
		}
		$sign = (string)($packet['sign'] ?? '');
		$expect = self::signPacket($packet, $secret);
		if ($sign === '' || !hash_equals($expect, $sign)) {
			throw new \RuntimeException('RemoteLib: signature invalid', -32011);
		}
	}

	/**
	 * @param array<string,mixed> $packet
	 * @param string              $secret
	 * @return string
	 */
	private static function signPacket(array $packet, string $secret): string
	{
		$payload = [
			'v'      => (int)($packet['v'] ?? 0),
			'name'   => (string)($packet['name'] ?? ''),
			'fqcn'   => (string)($packet['fqcn'] ?? ''),
			'sha256' => (string)($packet['sha256'] ?? ''),
			'ts'     => (int)($packet['ts'] ?? 0),
			'nonce'  => (string)($packet['nonce'] ?? ''),
			'cipher' => (string)($packet['cipher'] ?? ''),
		];
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException('RemoteLib: sign encode failed', -32010);
		}
		return hash_hmac('sha256', $json, $secret);
	}

	/**
	 * @param string $name 逻辑名
	 * @return string
	 */
	private static function sanitizeName(string $name): string
	{
		$name = trim(str_replace(['\\', '/'], '_', $name));
		$name = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $name) ?? '';
		if ($name === '' || str_contains($name, '..')) {
			throw new \InvalidArgumentException('RemoteLib: invalid name', -32010);
		}
		return $name;
	}

	/**
	 * @return void
	 */
	private static function assertEnabled(): void
	{
		if (!Config::remoteLibEnabled()) {
			throw new \RuntimeException('RemoteLib is disabled (remote_lib.enabled)', -32013);
		}
	}
}

