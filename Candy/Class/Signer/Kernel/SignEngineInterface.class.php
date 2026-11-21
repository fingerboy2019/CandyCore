<?php
/***
 * 签名引擎接口
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Kernel;

use Candy\Extend\Signer\Credential\Credential;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 签名 / 验签引擎
 */
interface SignEngineInterface
{
	/**
	 * 签发
	 *
	 * @param string $canonical 规范串
	 * @param Credential $credential 凭证
	 * @return string 签名（按配置编码）
	 */
	public function sign(string $canonical, Credential $credential): string;

	/**
	 * 验签
	 *
	 * @param string $canonical 规范串
	 * @param string $signature 签名
	 * @param Credential $credential 凭证
	 * @return bool
	 */
	public function verify(string $canonical, string $signature, Credential $credential): bool;
}
