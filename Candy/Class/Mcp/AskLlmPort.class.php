<?php
/***
 * 问数 Agent LLM 端口
 *
 * 业务说明：抽象 ChatClient.chat，便于单测注入假模型
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 11:37:41 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface AskLlmPort
{
	/**
	 * 发送对话（可含 tools / tool_calls）
	 *
	 * @param list<array<string,mixed>> $messages
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:array<string,mixed>}
	 */
	public function chat(array $messages, array $opts = []): array;
}
