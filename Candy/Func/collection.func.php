<?php
/**
 * Candy框架 数据集函数库
 *
 * 提供与 Laravel 同名的 collect() 助手，配合 Candy\Extend\Collection 使用。
 *
 * @author     刘森 <fingerboy@qq.com>
 * @date       2026-11-13
 */

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

if (!function_exists('collect')) {
	/**
	 * 创建数据集对象（Laravel collect 等价）
	 *
	 * @param mixed $items 数组 / Collection / 可转数组的值
	 * @return \Candy\Extend\Collection
	 * @example
	 * ```php
	 * collect([1, 2, 3])->sum();                                  // 6
	 * collect($rows)->groupBy('department')->all();
	 * collect(M('Users')->select())->pluck('real_name')->implode(',');
	 * ```
	 */
	function collect(mixed $items = []): \Candy\Extend\Collection
	{
		if ($items instanceof \Candy\Extend\Collection) {
			return $items;
		}

		return new \Candy\Extend\Collection($items);
	}
}
