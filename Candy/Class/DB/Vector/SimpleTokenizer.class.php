<?php
/***
 * 简易分词器（BM25）
 *
 * 业务说明：空白/标点切分；中英混排按脚本边界拆开；CJK 输出单字与 bigram
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-22 01:56:06 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * SimpleTokenizer
 */
final class SimpleTokenizer
{
	/**
	 * 分词算法版本（变更后须重建 BM25 / Wiki FTS）
	 */
	public const VERSION = 2;

	/** @var list<string> */
	private array $stopWords;

	private int $minTokenLength;

	/**
	 * @param list<string>|null $stopWords 停用词（小写）
	 * @param int $minTokenLength 拉丁词最小长度
	 */
	public function __construct(?array $stopWords = null, int $minTokenLength = 2)
	{
		$this->stopWords = array_map('strtolower', $stopWords ?? self::defaultStopWords());
		$this->minTokenLength = max(1, $minTokenLength);
	}

	/**
	 * @return list<string>
	 */
	public static function defaultStopWords(): array
	{
		return [
			'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'are', 'was', 'were',
			'的', '了', '在', '是', '我', '有', '和', '就', '不', '人', '都', '一', '一个', '上', '也', '很', '到', '说', '要', '去', '你',
		];
	}

	/**
	 * 分词
	 *
	 * 先按标点拆段，再按「汉字 run / 拉丁数字 run」拆开，避免「谁说RAG一定要」整串成一词
	 *
	 * @param string $text 原文
	 * @return list<string>
	 */
	public function tokenize(string $text): array
	{
		$text = trim($text);
		if ($text === '') {
			return [];
		}
		// 统一小写拉丁；保留中日韩
		$text = mb_strtolower($text, 'UTF-8');
		$parts = preg_split('/[^\p{L}\p{N}\p{Han}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts)) {
			return [];
		}

		$tokens = [];
		$stop = array_fill_keys($this->stopWords, true);
		foreach ($parts as $part) {
			$part = (string)$part;
			if ($part === '') {
				continue;
			}
			foreach ($this->scriptRuns($part) as $run) {
				$this->emitRun($run['text'], $run['kind'], $stop, $tokens);
			}
		}
		return $tokens;
	}

	/**
	 * 将一段连续「字母数字汉字」拆成脚本 run
	 *
	 * kind: han | latin（含数字）
	 *
	 * @param string $part
	 * @return list<array{kind:string,text:string}>
	 * @access private
	 */
	private function scriptRuns(string $part): array
	{
		// 汉字一块；拉丁字母与数字一块（保持 api2 / bge 一类标识完整）
		if (!preg_match_all('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+|[a-z0-9]+/u', $part, $matches)) {
			return [['kind' => 'latin', 'text' => $part]];
		}
		$runs = [];
		foreach ($matches[0] as $m) {
			$m = (string)$m;
			if ($m === '') {
				continue;
			}
			$kind = preg_match('/^[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $m) ? 'han' : 'latin';
			$runs[] = ['kind' => $kind, 'text' => $m];
		}
		return $runs;
	}

	/**
	 * @param string $text
	 * @param string $kind han|latin
	 * @param array<string,bool> $stop
	 * @param list<string> $tokens
	 * @return void
	 * @access private
	 */
	private function emitRun(string $text, string $kind, array $stop, array &$tokens): void
	{
		if ($kind === 'han') {
			$len = mb_strlen($text, 'UTF-8');
			for ($i = 0; $i < $len; $i++) {
				$ch = mb_substr($text, $i, 1, 'UTF-8');
				if (!isset($stop[$ch])) {
					$tokens[] = $ch;
				}
				if ($i + 1 < $len) {
					$bi = mb_substr($text, $i, 2, 'UTF-8');
					if (!isset($stop[$bi])) {
						$tokens[] = $bi;
					}
				}
			}
			return;
		}

		if (mb_strlen($text, 'UTF-8') < $this->minTokenLength) {
			return;
		}
		if (isset($stop[$text])) {
			return;
		}
		$tokens[] = $text;
	}
}
