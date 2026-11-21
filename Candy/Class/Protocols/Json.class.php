<?php
/***
 * 长度前缀 JSON 网络帧协议
 *
 * 业务说明：与 Binary 相同的 4 字节长度分帧，正文为 UTF-8 JSON，供 Rpc 调试轨使用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:30:34 $
 *
 * @package Candy\Extend\Protocols
 */

namespace Candy\Extend\Protocols;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * JSON 载荷帧协议（Workerman Protocols 兼容）
 *
 * 帧：| 4B 总长(N) | JSON 正文 |
 * 裸命令：ping / pong（与 Binary 一致）
 */
class Json
{
	/** 单包最大长度（字节） */
	public const MAX_PACKET_SIZE = 10 * 1024 * 1024;

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
	 * 编码：数组或裸 ping/pong → 长度帧
	 *
	 * @param mixed $data 业务数组或 ping/pong
	 * @return string
	 */
	public static function encode(mixed $data): string
	{
		if ($data === 'ping' || $data === 'pong') {
			return self::packHead(4 + strlen($data)) . $data;
		}

		$json = json_encode(
			is_array($data) ? $data : ['error' => ['code' => -32600, 'message' => 'Encode expects array']],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ($json === false) {
			$json = '{"error":{"code":-32603,"message":"json_encode failed"}}';
		}
		return self::packHead(strlen($json) + 4) . $json;
	}

	/**
	 * 解码：完整帧 → 数组或 ping/pong
	 *
	 * @param string $buffer 含长度头的完整包
	 * @return mixed
	 */
	public static function decode(string $buffer): mixed
	{
		$body = substr($buffer, 4);
		if ($body === '') {
			return [];
		}
		if ($body === 'ping' || $body === 'pong') {
			return $body;
		}
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			return [
				'error' => [
					'code'    => -32700,
					'message' => 'Invalid JSON payload',
				],
			];
		}
		return $decoded;
	}

	/**
	 * 打包 4 字节大端长度头
	 *
	 * @param int $len 整包总长（含头）
	 * @return string
	 */
	private static function packHead(int $len): string
	{
		return pack('N', $len);
	}
}

