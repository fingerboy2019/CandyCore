<?php
/***
 * Rpc 统一网络帧协议
 *
 * 业务说明：长度前缀分帧；自动识别 JSON / PackBinary 载荷；供框架 Rpc 服务端单端口双轨
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
 * Rpc 双载荷协议（Workerman Protocols 兼容）
 *
 * decode 返回 ['codec' => json|bin|sys, 'data' => mixed]
 * encode 前由服务端设置 self::$encodeCodec，与请求轨保持一致
 */
class Rpc
{
	public const MAX_PACKET_SIZE = 10 * 1024 * 1024;

	/** 当前连接响应使用的编解码：json|bin */
	public static string $encodeCodec = 'bin';

	/**
	 * 检查数据包完整性
	 *
	 * @param string $buffer 接收缓冲区
	 * @return int 完整包长；0=等待；-1=非法
	 */
	public static function input(string $buffer): int
	{
		if (strlen($buffer) < 4) {
			return 0;
		}
		$totalLength = unpack('Ntotal_length', $buffer)['total_length'] ?? 0;
		if ($totalLength <= 0 || $totalLength > self::MAX_PACKET_SIZE) {
			return -1;
		}
		return $totalLength;
	}

	/**
	 * 编码响应帧
	 *
	 * @param mixed $data 业务数组或 ping/pong
	 * @return string
	 */
	public static function encode(mixed $data): string
	{
		if ($data === 'ping' || $data === 'pong') {
			return self::packHead(4 + strlen($data)) . $data;
		}

		$codec = self::$encodeCodec === 'json' ? 'json' : 'bin';
		if ($codec === 'json') {
			return Json::encode(is_array($data) ? $data : ['error' => ['code' => -32600, 'message' => 'Encode expects array']]);
		}

		try {
			$body = PackBinary::pack(is_array($data) ? $data : [
				'error' => ['code' => -32600, 'message' => 'Encode expects array'],
			]);
		} catch (\Throwable $e) {
			$body = PackBinary::pack([
				'error' => ['code' => -32603, 'message' => 'Encode error: ' . $e->getMessage()],
			]);
		}
		return self::packHead(strlen($body) + 4) . $body;
	}

	/**
	 * 解码请求帧并识别载荷类型
	 *
	 * @param string $buffer 含长度头的完整包
	 * @return array{codec:string,data:mixed}
	 */
	public static function decode(string $buffer): array
	{
		$body = substr($buffer, 4);
		if ($body === '') {
			return ['codec' => 'json', 'data' => []];
		}

		if ($body === 'ping' || $body === 'pong') {
			return ['codec' => 'sys', 'data' => $body];
		}

		$first = $body[0];
		if ($first === '{' || $first === '[') {
			$decoded = json_decode($body, true);
			return [
				'codec' => 'json',
				'data'  => is_array($decoded) ? $decoded : [
					'error' => ['code' => -32700, 'message' => 'Invalid JSON payload'],
				],
			];
		}

		try {
			return ['codec' => 'bin', 'data' => PackBinary::tryUnpack($body) ?? [
				'error' => ['code' => -32700, 'message' => 'Invalid binary payload'],
			]];
		} catch (\Throwable $e) {
			return [
				'codec' => 'bin',
				'data'  => [
					'error' => ['code' => -32700, 'message' => 'Decode error: ' . $e->getMessage()],
				],
			];
		}
	}

	/**
	 * 按指定编解码打包（客户端发送用）
	 *
	 * @param mixed  $data  请求数组或 ping/pong
	 * @param string $codec json|bin
	 * @return string
	 */
	public static function encodeWith(mixed $data, string $codec): string
	{
		$prev = self::$encodeCodec;
		self::$encodeCodec = $codec === 'json' ? 'json' : 'bin';
		try {
			return self::encode($data);
		} finally {
			self::$encodeCodec = $prev;
		}
	}

	/**
	 * 打包 4 字节大端长度头
	 *
	 * @param int $len 整包总长
	 * @return string
	 */
	private static function packHead(int $len): string
	{
		return pack('N', $len);
	}
}
