<?php
/***
 * float32 编解码
 *
 * 业务说明：向量与二进制定长记录互转（机器字节序 pack f）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 07:59:38 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Float32
{
	/**
	 * 将 float 数组打包为定长二进制
	 *
	 * @param array<int,float|int|string> $vector
	 * @param int $dimensions
	 * @return string
	 */
	public static function pack(array $vector, int $dimensions): string
	{
		$out = '';
		for ($i = 0; $i < $dimensions; $i++) {
			$out .= pack('f', (float)($vector[$i] ?? 0.0));
		}
		return $out;
	}

	/**
	 * 解包定长二进制为 float 数组
	 *
	 * @param string $bin
	 * @param int $dimensions
	 * @return array<int,float>
	 */
	public static function unpack(string $bin, int $dimensions): array
	{
		$need = $dimensions * 4;
		if (strlen($bin) < $need) {
			$bin = str_pad($bin, $need, "\0");
		}
		$arr = unpack('f' . $dimensions, substr($bin, 0, $need));
		if (!is_array($arr)) {
			return array_fill(0, $dimensions, 0.0);
		}
		return array_values($arr);
	}
}
