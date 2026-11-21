<?php
/***
 * 高精度数学与金额计算
 *
 * 业务说明：基于 bcmath 的加减乘除、折扣税费与简单统计；金额场景避免浮点误差
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 02:12:00 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);

namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Math
{
	/** 默认小数位 */
	private const DEFAULT_SCALE = 10;

	/**
	 * 高精度加法
	 *
	 * @param string|int|float $a 左操作数
	 * @param string|int|float $b 右操作数
	 * @param int|null $scale 小数位，null 用默认
	 * @return string
	 * @access public
	 * @static
	 */
	public static function add(string|int|float $a, string|int|float $b, ?int $scale = null): string
	{
		self::assertBcmath();
		return bcadd(self::num($a), self::num($b), $scale ?? self::DEFAULT_SCALE);
	}

	/**
	 * 高精度减法
	 *
	 * @param string|int|float $a 被减数
	 * @param string|int|float $b 减数
	 * @param int|null $scale 小数位
	 * @return string
	 * @access public
	 * @static
	 */
	public static function sub(string|int|float $a, string|int|float $b, ?int $scale = null): string
	{
		self::assertBcmath();
		return bcsub(self::num($a), self::num($b), $scale ?? self::DEFAULT_SCALE);
	}

	/**
	 * 高精度乘法
	 *
	 * @param string|int|float $a 乘数
	 * @param string|int|float $b 乘数
	 * @param int|null $scale 小数位
	 * @return string
	 * @access public
	 * @static
	 */
	public static function mul(string|int|float $a, string|int|float $b, ?int $scale = null): string
	{
		self::assertBcmath();
		return bcmul(self::num($a), self::num($b), $scale ?? self::DEFAULT_SCALE);
	}

	/**
	 * 高精度除法
	 *
	 * 除数为 0 时抛异常
	 *
	 * @param string|int|float $a 被除数
	 * @param string|int|float $b 除数
	 * @param int|null $scale 小数位
	 * @return string
	 * @throws \InvalidArgumentException
	 * @access public
	 * @static
	 */
	public static function div(string|int|float $a, string|int|float $b, ?int $scale = null): string
	{
		self::assertBcmath();
		$divisor = self::num($b);
		if (bccomp($divisor, '0', $scale ?? self::DEFAULT_SCALE) === 0) {
			throw new \InvalidArgumentException('除数不能为 0');
		}
		return bcdiv(self::num($a), $divisor, $scale ?? self::DEFAULT_SCALE);
	}

	/**
	 * 金额格式化（固定小数位，默认 2）
	 *
	 * @param string|int|float $amount 金额
	 * @param int $scale 小数位
	 * @return string
	 * @access public
	 * @static
	 */
	public static function money(string|int|float $amount, int $scale = 2): string
	{
		self::assertBcmath();
		$scale = max(0, $scale);
		return bcadd(self::num($amount), '0', $scale);
	}

	/**
	 * 折扣后金额：price * rate（rate=0.8 表示八折）
	 *
	 * @param string|int|float $price 原价
	 * @param string|int|float $rate 折扣系数
	 * @param int $scale 小数位，默认 2
	 * @return string
	 * @access public
	 * @static
	 */
	public static function discount(string|int|float $price, string|int|float $rate, int $scale = 2): string
	{
		return self::money(self::mul($price, $rate, max($scale, 4)), $scale);
	}

	/**
	 * 税费金额：amount * rate（rate=0.13 表示 13%）
	 *
	 * @param string|int|float $amount 计税基数
	 * @param string|int|float $rate 税率
	 * @param int $scale 小数位
	 * @return string
	 * @access public
	 * @static
	 */
	public static function tax(string|int|float $amount, string|int|float $rate, int $scale = 2): string
	{
		return self::money(self::mul($amount, $rate, max($scale, 4)), $scale);
	}

	/**
	 * 算术平均值
	 *
	 * @param array<int|string|float> $values 数值列表
	 * @param int|null $scale 小数位
	 * @return string
	 * @throws \InvalidArgumentException 空数组
	 * @access public
	 * @static
	 */
	public static function average(array $values, ?int $scale = null): string
	{
		self::assertBcmath();
		if ($values === []) {
			throw new \InvalidArgumentException('average 需要非空数组');
		}
		$sum = '0';
		foreach ($values as $v) {
			$sum = bcadd($sum, self::num($v), $scale ?? self::DEFAULT_SCALE);
		}
		return bcdiv($sum, (string) count($values), $scale ?? self::DEFAULT_SCALE);
	}

	/**
	 * 中位数
	 *
	 * @param array<int|string|float> $values 数值列表
	 * @param int|null $scale 小数位
	 * @return string
	 * @throws \InvalidArgumentException
	 * @access public
	 * @static
	 */
	public static function median(array $values, ?int $scale = null): string
	{
		self::assertBcmath();
		if ($values === []) {
			throw new \InvalidArgumentException('median 需要非空数组');
		}
		$nums = array_map(static fn($v) => self::num($v), array_values($values));
		usort($nums, static fn(string $a, string $b): int => bccomp($a, $b, $scale ?? self::DEFAULT_SCALE));
		$n = count($nums);
		$mid = intdiv($n, 2);
		if ($n % 2 === 1) {
			return bcadd($nums[$mid], '0', $scale ?? self::DEFAULT_SCALE);
		}
		return bcdiv(bcadd($nums[$mid - 1], $nums[$mid], $scale ?? self::DEFAULT_SCALE), '2', $scale ?? self::DEFAULT_SCALE);
	}

	/**
	 * 规范为 bcmath 可用的数字字符串
	 *
	 * @param string|int|float $value 输入
	 * @return string
	 * @access private
	 * @static
	 */
	private static function num(string|int|float $value): string
	{
		if (is_string($value)) {
			$value = trim($value);
			if ($value === '' || !is_numeric($value)) {
				throw new \InvalidArgumentException('非法数字: ' . $value);
			}
			return $value;
		}
		return (string) $value;
	}

	/**
	 * 确保 bcmath 可用
	 *
	 * @return void
	 * @throws \RuntimeException
	 * @access private
	 * @static
	 */
	private static function assertBcmath(): void
	{
		if (!extension_loaded('bcmath')) {
			throw new \RuntimeException('需要启用 PHP bcmath 扩展');
		}
	}
}

