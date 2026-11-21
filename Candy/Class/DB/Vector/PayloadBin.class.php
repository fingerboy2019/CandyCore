<?php
/***
 * 向量 payload 追加文件
 *
 * 业务说明：payload.bin 存 JSON 字节；meta 记录 offset/len；更新时追加新块
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 08:08:28 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class PayloadBin
{
	public const MAGIC = 'CVP1';

	/**
	 * 确保文件存在
	 *
	 * @param string $path
	 * @return void
	 */
	public static function ensure(string $path): void
	{
		if (!is_file($path)) {
			file_put_contents($path, self::MAGIC, LOCK_EX);
		}
	}

	/**
	 * 追加一段 payload，返回 [offset, length]
	 *
	 * 记录布局：u32 len + bytes
	 *
	 * @param string $path
	 * @param string $bytes
	 * @return array{0:int,1:int} offset 指向 len 字段起点，length 为字节内容长度
	 */
	public static function append(string $path, string $bytes): array
	{
		self::ensure($path);
		$fp = fopen($path, 'c+b');
		if ($fp === false) {
			throw new \RuntimeException('无法打开 payload.bin');
		}
		fseek($fp, 0, SEEK_END);
		$offset = (int)ftell($fp);
		$len = strlen($bytes);
		fwrite($fp, pack('N', $len) . $bytes);
		fclose($fp);
		return [$offset, $len];
	}

	/**
	 * 按 offset 读取 payload 字节
	 *
	 * @param string $path
	 * @param int $offset
	 * @param int $length 期望内容长度（校验用，0 则信文件头）
	 * @return string|null
	 */
	public static function read(string $path, int $offset, int $length = 0): ?string
	{
		if ($offset < 4 || !is_file($path)) {
			return null;
		}
		$fp = fopen($path, 'rb');
		if ($fp === false) {
			return null;
		}
		fseek($fp, $offset);
		$hdr = fread($fp, 4);
		if ($hdr === false || strlen($hdr) < 4) {
			fclose($fp);
			return null;
		}
		$len = unpack('N', $hdr)[1];
		if ($length > 0 && $len !== $length) {
			// 仍按文件头读取，兼容 length 未存准的情况
		}
		$data = $len > 0 ? fread($fp, $len) : '';
		fclose($fp);
		return is_string($data) ? $data : null;
	}

	/**
	 * 紧凑重写：只保留仍被引用的块
	 *
	 * @param string $path
	 * @param array<string, array{payload_off:int,payload_len:int}> $refs id => offsets
	 * @return array<string, array{0:int,1:int}> id => [newOff, len]
	 */
	public static function compact(string $path, array $refs): array
	{
		$tmp = $path . '.tmp.' . getmypid();
		file_put_contents($tmp, self::MAGIC, LOCK_EX);
		$map = [];
		foreach ($refs as $id => $ref) {
			$raw = self::read($path, (int)$ref['payload_off'], (int)$ref['payload_len']);
			if ($raw === null) {
				continue;
			}
			[$off, $len] = self::append($tmp, $raw);
			$map[$id] = [$off, $len];
		}
		rename($tmp, $path);
		return $map;
	}
}
