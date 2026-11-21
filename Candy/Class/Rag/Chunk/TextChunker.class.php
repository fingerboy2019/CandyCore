<?php
/***
 * 纯文本 / Markdown 分片器
 *
 * 业务说明：按字符窗口 + overlap 切分；可选先按 Markdown 标题粗切
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Chunk
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Chunk;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class TextChunker
{
	private int $size;
	private int $overlap;
	private bool $splitByHeading;

	/**
	 * @param int $size 块最大字符数
	 * @param int $overlap 相邻块重叠字符数
	 * @param bool $splitByHeading 是否先按标题分段
	 */
	public function __construct(int $size = 800, int $overlap = 120, bool $splitByHeading = true)
	{
		$this->size = max(32, $size);
		$this->overlap = max(0, min($this->size - 1, $overlap));
		$this->splitByHeading = $splitByHeading;
	}

	/**
	 * 分片
	 *
	 * 返回列表项：seq（从 0）、text、char_offset（原文起始字符下标）
	 *
	 * @param string $text UTF-8 文本
	 * @return list<array{seq:int,text:string,char_offset:int}>
	 */
	public function chunk(string $text): array
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = trim($text);
		if ($text === '') {
			return [];
		}

		$sections = $this->splitByHeading ? $this->splitSections($text) : [['offset' => 0, 'text' => $text]];
		$chunks = [];
		$seq = 0;
		foreach ($sections as $section) {
			$sectionText = (string)$section['text'];
			$baseOffset = (int)$section['offset'];
			if (trim($sectionText) === '') {
				continue;
			}
			foreach ($this->window($sectionText) as $win) {
				$chunks[] = [
					'seq' => $seq++,
					'text' => $win['text'],
					'char_offset' => $baseOffset + $win['offset'],
				];
			}
		}
		return $chunks;
	}

	/**
	 * 按 Markdown AT 标题切成段落（无标题则整篇一段）
	 *
	 * @param string $text
	 * @return list<array{offset:int,text:string}>
	 */
	private function splitSections(string $text): array
	{
		$lines = explode("\n", $text);
		$sections = [];
		$buf = [];
		$bufStart = 0;
		$pos = 0;
		$lineCount = count($lines);

		for ($i = 0; $i < $lineCount; $i++) {
			$line = $lines[$i];
			$lineLen = mb_strlen($line, 'UTF-8');
			$isHeading = (bool)preg_match('/^#{1,6}\s+\S/', $line);
			if ($isHeading && $buf !== []) {
				$sections[] = [
					'offset' => $bufStart,
					'text' => implode("\n", $buf),
				];
				$buf = [];
				$bufStart = $pos;
			}
			if ($buf === []) {
				$bufStart = $pos;
			}
			$buf[] = $line;
			$pos += $lineLen + ($i < $lineCount - 1 ? 1 : 0);
		}
		if ($buf !== []) {
			$sections[] = [
				'offset' => $bufStart,
				'text' => implode("\n", $buf),
			];
		}
		return $sections !== [] ? $sections : [['offset' => 0, 'text' => $text]];
	}

	/**
	 * 滑动窗口切分单段
	 *
	 * @param string $text
	 * @return list<array{offset:int,text:string}>
	 */
	private function window(string $text): array
	{
		$len = mb_strlen($text, 'UTF-8');
		if ($len <= $this->size) {
			return [['offset' => 0, 'text' => $text]];
		}
		$step = max(1, $this->size - $this->overlap);
		$out = [];
		for ($off = 0; $off < $len; $off += $step) {
			$piece = mb_substr($text, $off, $this->size, 'UTF-8');
			if ($piece === '') {
				break;
			}
			$out[] = ['offset' => $off, 'text' => $piece];
			if ($off + $this->size >= $len) {
				break;
			}
		}
		return $out;
	}
}
