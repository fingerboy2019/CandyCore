<?php
/***
 * 通用二进制网络帧协议
 *
 * 业务说明：长度前缀分帧 + Candy PackBinary 载荷，供 NoSQL / Rpc 等框架组件共用
 * 帧格式：4 字节大端总长（含头）+ 正文；正文为 Pack 包或裸 ping/pong
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:30:34 $
 *
 * @package Candy\Extend\Protocols
 */

namespace Candy\Extend\Protocols;

use Candy\Core\Impl\Pack\Binary as PackBinary;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 通用二进制网络帧协议（Workerman Protocols 兼容）
 *
 * ================================
 * 协议格式
 * ================================
 *
 * +------------------+-------------------+
 * | 4字节长度头(N)   | N-4 字节数据体     |
 * +------------------+-------------------+
 *
 * - 长度头：大端序 uint32，表示整包总字节数（含长度头本身）
 * - 数据体：Candy\Core\Impl\Pack\Binary 序列化结果，或裸字符串 ping/pong
 *
 * ================================
 * Workerman 用法
 * ================================
 *
 * ```php
 * class_alias(\Candy\Extend\Protocols\Binary::class, 'Protocols\Binary');
 * // 监听：Binary://0.0.0.0:2404
 * ```
 *
 * 客户端与服务端必须共用本类 encode/decode，禁止混用 PHP serialize。
 */
class Binary
{
	/** 单包最大长度（字节），超出 input() 返回 -1 由 Workerman 断连 */
	public const MAX_PACKET_SIZE = 10 * 1024 * 1024;

	/**
	 * 检查数据包完整性（粘包 / 半包）
	 *
	 * @param string $buffer 接收缓冲区
	 * @return int 完整包长；0=继续等待；-1=非法包应关闭连接
	 */
	public static function input(string $buffer): int
	{
		if (strlen($buffer) < 4) {
			return 0;
		}
		$unpackData = unpack('Ntotal_length', $buffer);
		$totalLength = $unpackData['total_length'] ?? 0;

		if ($totalLength <= 0 || $totalLength > self::MAX_PACKET_SIZE) {
			return -1;
		}

		return $totalLength;
	}

	/**
	 * 编码：PHP 数据 → 带长度头的二进制帧
	 *
	 * - 裸系统命令：'ping' / 'pong'（长度头 = 4 + strlen）
	 * - 业务数据：须为 array，经 PackBinary::pack 后加长度头
	 * - 非数组且非 ping/pong：打成错误结构包，避免静默空包
	 *
	 * @param mixed $data 要发送的数据
	 * @return string 完整数据包
	 */
	public static function encode(mixed $data): string
	{
		try {
			if ($data === 'ping' || $data === 'pong') {
				return self::packSystemCommand($data);
			}

			$bin = PackBinary::pack(is_array($data) ? $data : [
				'code' => 500,
				'msg'  => 'Encode expects array',
			]);
			if ($bin === '') {
				$bin = PackBinary::pack([
					'code' => 500,
					'msg'  => 'Internal encoding error: empty pack result',
				]);
			}
			return self::packHead(strlen($bin) + 4) . $bin;
		} catch (\Throwable $e) {
			$bin = PackBinary::pack([
				'code' => 500,
				'msg'  => 'Internal encoding error: ' . $e->getMessage(),
			]);
			return self::packHead(strlen($bin) + 4) . $bin;
		}
	}

	/**
	 * 解码：完整二进制帧 → PHP 数据
	 *
	 * @param string $buffer 含长度头的完整包
	 * @return mixed 数组、ping/pong 字符串，或解码失败时的错误结构
	 */
	public static function decode(string $buffer): mixed
	{
		try {
			$buffer = substr($buffer, 4);

			if ($buffer === '') {
				return [];
			}

			return match ($buffer) {
				'ping' => 'ping',
				'pong' => 'pong',
				default => PackBinary::tryUnpack($buffer) ?? [
					'cmd' => 'error',
					'msg' => 'Decode error: invalid binary payload',
				],
			};
		} catch (\Throwable $e) {
			return [
				'cmd' => 'error',
				'msg' => 'Decode error: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * 打包裸系统命令帧（长度头含头+正文）
	 *
	 * @param string $cmd 命令正文，如 ping / pong
	 * @return string 完整数据包
	 */
	private static function packSystemCommand(string $cmd): string
	{
		return self::packHead(4 + strlen($cmd)) . $cmd;
	}

	/**
	 * 打包 4 字节大端长度头
	 *
	 * @param int $len 整包总长度（含本长度头 4 字节）
	 * @return string
	 */
	private static function packHead(int $len): string
	{
		return pack('N', $len);
	}
}
