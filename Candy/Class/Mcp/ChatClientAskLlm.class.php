<?php
/***
 * ChatClient 问数端口适配
 *
 * 业务说明：将 Rag ChatClient 适配为 AskLlmPort
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 11:37:41 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

use Candy\Extend\Rag\Llm\ChatClient;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ChatClientAskLlm implements AskLlmPort
{
	/**
	 * @param ChatClient $client 真实 Chat 客户端
	 */
	public function __construct(private ChatClient $client)
	{
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat(array $messages, array $opts = []): array
	{
		return $this->client->chat($messages, $opts);
	}
}
