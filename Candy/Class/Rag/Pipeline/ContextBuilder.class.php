<?php
/***
 * 检索结果上下文拼装
 *
 * 业务说明：将 hits 格式化为带引用编号的上下文字符串，供业务层拼 prompt
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Pipeline
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Pipeline;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ContextBuilder
{
	/**
	 * 拼装上下文
	 *
	 * opts：
	 * - max_chars：总字符上限（默认 6000，0=不截断）
	 * - header：可选前置说明
	 *
	 * @param list<array{id?:string,score?:float,text?:string,payload?:array}> $hits
	 * @param array<string,mixed> $opts
	 * @return string
	 */
	public function build(array $hits, array $opts = []): string
	{
		$maxChars = isset($opts['max_chars']) ? (int)$opts['max_chars'] : 6000;
		$header = isset($opts['header']) ? (string)$opts['header'] : '';
		$parts = [];
		if ($header !== '') {
			$parts[] = $header;
		}

		$used = $header !== '' ? mb_strlen($header, 'UTF-8') + 2 : 0;
		$n = 1;
		foreach ($hits as $hit) {
			if (!is_array($hit)) {
				continue;
			}
			$text = trim((string)($hit['text'] ?? ''));
			if ($text === '' && is_array($hit['payload'] ?? null)) {
				$text = trim((string)(($hit['payload']['text'] ?? $hit['payload']['content'] ?? '')));
			}
			if ($text === '') {
				continue;
			}
			$id = (string)($hit['id'] ?? '');
			$score = isset($hit['score']) ? round((float)$hit['score'], 4) : null;
			$meta = $id !== '' ? $id : ('#' . $n);
			if ($score !== null) {
				$meta .= ' score=' . $score;
			}
			$block = '[' . $n . '] (' . $meta . ")\n" . $text;
			$blockLen = mb_strlen($block, 'UTF-8');
			if ($maxChars > 0 && $used + $blockLen + 2 > $maxChars) {
				$remain = $maxChars - $used - 2;
				if ($remain < 64) {
					break;
				}
				$block = mb_substr($block, 0, $remain, 'UTF-8') . '…';
				$parts[] = $block;
				break;
			}
			$parts[] = $block;
			$used += $blockLen + 2;
			$n++;
		}

		return implode("\n\n", $parts);
	}
}
