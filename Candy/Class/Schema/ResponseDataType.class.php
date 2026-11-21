<?php
/***
 * 响应 data 包装类型
 *
 * 业务说明：Item / List / Page / None，对齐统一 {code,message,data} 外壳
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 18:43:40 $
 *
 * @package Candy\Extend\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\Schema;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 响应 data 形态
 */
enum ResponseDataType: string
{
	case Item = 'item';
	case List = 'list';
	case Page = 'page';
	case None = 'none';
}

