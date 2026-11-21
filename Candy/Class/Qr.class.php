<?php
/***
 * 通用二维码生成（纯 GD 渲染）
 *
 * 业务说明：将文本编码为 QR 矩阵后用 GD 输出 PNG；编码内核为 Vendor/QRcode（LGPL，无 Composer）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 02:12:00 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);

namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Qr
{
	/** 纠错级别：L/M/Q/H */
	public const EC_L = 'L';
	public const EC_M = 'M';
	public const EC_Q = 'Q';
	public const EC_H = 'H';

	/**
	 * 生成 PNG 二进制内容
	 *
	 * 使用内置 QRcode 命名空间编码器生成矩阵，再用 GD 绘制（不依赖 endroid）
	 * 需启用 gd 扩展；内容过长时由内核自动升版本，失败抛异常
	 *
	 * @param string $text 二维码内容（URL、文本等）
	 * @param int $pixel 每个模块像素边长，默认 6
	 * @param int $margin 外边白边模块数，默认 2
	 * @param string $ecLevel 纠错级别 L/M/Q/H，默认 M
	 * @return string PNG 二进制
	 * @throws \RuntimeException gd 不可用或编码失败
	 * @access public
	 * @static
	 */
	public static function png(string $text, int $pixel = 6, int $margin = 2, string $ecLevel = self::EC_M): string
	{
		$matrix = self::matrix($text, $ecLevel);
		return self::matrixToPng($matrix, $pixel, $margin);
	}

	/**
	 * 生成 data URI（便于直接嵌入 img src）
	 *
	 * @param string $text 内容
	 * @param int $pixel 模块像素
	 * @param int $margin 边距模块数
	 * @param string $ecLevel 纠错级别
	 * @return string data:image/png;base64,...
	 * @access public
	 * @static
	 */
	public static function dataUri(string $text, int $pixel = 6, int $margin = 2, string $ecLevel = self::EC_M): string
	{
		return 'data:image/png;base64,' . base64_encode(self::png($text, $pixel, $margin, $ecLevel));
	}

	/**
	 * 保存为 PNG 文件
	 *
	 * @param string $text 内容
	 * @param string $path 目标路径
	 * @param int $pixel 模块像素
	 * @param int $margin 边距模块数
	 * @param string $ecLevel 纠错级别
	 * @return bool 是否写入成功
	 * @access public
	 * @static
	 */
	public static function save(string $text, string $path, int $pixel = 6, int $margin = 2, string $ecLevel = self::EC_M): bool
	{
		$bin = self::png($text, $pixel, $margin, $ecLevel);
		$dir = dirname($path);
		if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
			Dir::create($dir);
		}
		return file_put_contents($path, $bin) !== false;
	}

	/**
	 * 获取二维码布尔矩阵（true=黑）
	 *
	 * @param string $text 内容
	 * @param string $ecLevel 纠错级别
	 * @return list<list<bool>>
	 * @throws \RuntimeException
	 * @access public
	 * @static
	 */
	public static function matrix(string $text, string $ecLevel = self::EC_M): array
	{
		self::loadEncoder();
		$level = self::mapEcLevel($ecLevel);
		$frame = \QRcode\QRcode::text($text, false, $level);
		if (!is_array($frame) || $frame === []) {
			throw new \RuntimeException('二维码编码失败');
		}
		$out = [];
		foreach ($frame as $row) {
			$line = [];
			$len = strlen((string) $row);
			for ($i = 0; $i < $len; $i++) {
				$line[] = ((string) $row[$i]) === '1';
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * 将矩阵绘制为 PNG
	 *
	 * @param list<list<bool>> $matrix 模块矩阵
	 * @param int $pixel 每模块像素
	 * @param int $margin 外边白边模块数
	 * @return string PNG 二进制
	 * @throws \RuntimeException
	 * @access private
	 * @static
	 */
	private static function matrixToPng(array $matrix, int $pixel, int $margin): string
	{
		if (!function_exists('imagecreatetruecolor')) {
			throw new \RuntimeException('需要启用 PHP gd 扩展才能生成二维码');
		}
		$pixel = max(1, $pixel);
		$margin = max(0, $margin);
		$rows = count($matrix);
		$cols = $rows > 0 ? count($matrix[0]) : 0;
		if ($rows < 1 || $cols < 1) {
			throw new \RuntimeException('二维码矩阵为空');
		}

		$width = ($cols + $margin * 2) * $pixel;
		$height = ($rows + $margin * 2) * $pixel;
		$im = imagecreatetruecolor($width, $height);
		if ($im === false) {
			throw new \RuntimeException('无法创建 GD 画布');
		}
		$white = imagecolorallocate($im, 255, 255, 255);
		$black = imagecolorallocate($im, 0, 0, 0);
		imagefilledrectangle($im, 0, 0, $width, $height, $white);

		for ($y = 0; $y < $rows; $y++) {
			for ($x = 0; $x < $cols; $x++) {
				if (empty($matrix[$y][$x])) {
					continue;
				}
				$x1 = ($x + $margin) * $pixel;
				$y1 = ($y + $margin) * $pixel;
				imagefilledrectangle($im, $x1, $y1, $x1 + $pixel - 1, $y1 + $pixel - 1, $black);
			}
		}

		ob_start();
		imagepng($im);
		$bin = (string) ob_get_clean();
		imagedestroy($im);
		if ($bin === '') {
			throw new \RuntimeException('PNG 输出失败');
		}
		return $bin;
	}

	/**
	 * 加载 QRcode 命名空间编码内核（仅一次）
	 *
	 * @return void
	 * @access private
	 * @static
	 */
	private static function loadEncoder(): void
	{
		if (class_exists('QRcode\\QRcode', false)) {
			return;
		}
		$path = CANDYPATH . 'Candy/Vendor/QRcode/QRcode.class.php';
		if (!is_file($path)) {
			throw new \RuntimeException('缺少 QRcode 编码内核：' . $path);
		}
		require_once $path;
	}

	/**
	 * 纠错级别映射到 QRcode 命名空间常量值
	 *
	 * @param string $ecLevel L/M/Q/H
	 * @return int
	 * @access private
	 * @static
	 */
	private static function mapEcLevel(string $ecLevel): int
	{
		$ecLevel = strtoupper($ecLevel);
		return match ($ecLevel) {
			'L' => 0,
			'Q' => 2,
			'H' => 3,
			default => 1,
		};
	}
}
