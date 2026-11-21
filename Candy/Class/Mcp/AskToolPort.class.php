<?php
/***
 * 问数 Agent Tool 端口
 *
 * 业务说明：抽象 AskToolBridge，便于单测注入假桥接
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 11:37:41 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface AskToolPort
{
	/**
	 * OpenAI tools[] 定义
	 *
	 * @return list<array<string,mixed>>
	 */
	public function openaiTools(): array;

	/**
	 * 调用 Tool
	 *
	 * @param string $name
	 * @param array<string,mixed> $arguments
	 * @return array{ok:bool,name:string,result:array,content_for_llm:string,error?:string}
	 */
	public function invoke(string $name, array $arguments): array;
}
