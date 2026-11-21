<?php
/***
 * Check 词库权威源
 *
 * 业务说明：Cache 存词列表与版本；TXT 仅种子与回写；运行匹配不读盘
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 12:31:43 $
 *
 * @package Check
 */

declare(strict_types=1);

namespace Check;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Cache + TXT 双写词库存储
 */
final class WordStore
{
	/**
	 * 断言已启用
	 *
	 * @return void
	 */
	public static function assertEnabled(): void
	{
		if (!Bootstrap::enabled()) {
			\Candy\Core\Debug::showMessage(
				'Check 未启用：请先执行 php candy check:init',
				'Sensitive'
			);
		}
	}

	/**
	 * 配置前缀
	 *
	 * @return string
	 */
	public static function prefix(): string
	{
		$config = Bootstrap::config();
		$prefix = (string)($config['cache_prefix'] ?? 'check:sensitive');
		return $prefix !== '' ? $prefix : 'check:sensitive';
	}

	/**
	 * 词列表 Cache 键
	 *
	 * @return string
	 */
	public static function wordsKey(): string
	{
		return self::prefix() . ':words';
	}

	/**
	 * 版本 Cache 键
	 *
	 * @return string
	 */
	public static function verKey(): string
	{
		return self::prefix() . ':ver';
	}

	/**
	 * 种子/回写词库文件路径
	 *
	 * @return string
	 */
	public static function wordFile(): string
	{
		$config = Bootstrap::config();
		$file = trim((string)($config['word_file'] ?? ''));
		if ($file !== '' && is_file($file)) {
			return $file;
		}
		$mode = $config['mode'] ?? 'file';
		if (is_string($mode) && $mode !== 'file' && $mode !== 'array' && is_file($mode)) {
			return $mode;
		}
		return Bootstrap::defaultWordFile();
	}

	/**
	 * Cache 实例
	 *
	 * @return \Candy\Core\Cache
	 */
	public static function cache(): \Candy\Core\Cache
	{
		if (!class_exists(\Candy\Core\Cache::class)) {
			\Candy\Core\Debug::showMessage('Cache 不可用', 'Sensitive');
		}
		$cache = \Candy\Core\Cache::store();
		if (!is_object($cache) || !method_exists($cache, 'get') || !method_exists($cache, 'set')) {
			\Candy\Core\Debug::showMessage('Cache 驱动不支持 get/set', 'Sensitive');
		}
		return $cache;
	}

	/**
	 * 当前版本号
	 *
	 * @return int
	 */
	public static function version(): int
	{
		self::assertEnabled();
		$ver = self::cache()->get(self::verKey(), 0);
		return is_numeric($ver) ? (int)$ver : 0;
	}

	/**
	 * 读取词列表（只读 Cache；空则冷启动从 TXT/数组灌入一次）
	 *
	 * @return list<string>
	 */
	public static function loadWords(): array
	{
		self::assertEnabled();
		$cache = self::cache();
		$raw = $cache->get(self::wordsKey(), null);
		if (is_array($raw) && $raw !== []) {
			return self::normalizeWords($raw);
		}

		// 冷启动：从种子灌入
		self::seedFromFile(false);
		$raw = $cache->get(self::wordsKey(), []);
		return is_array($raw) ? self::normalizeWords($raw) : [];
	}

	/**
	 * 持久化词列表：Cache + ver++ + 回写 TXT
	 *
	 * @param array<int,string> $words 词列表
	 * @return void
	 */
	public static function saveWords(array $words): void
	{
		self::assertEnabled();
		$words = self::normalizeWords($words);
		$cache = self::cache();
		$cache->set(self::wordsKey(), $words, 0);
		$ver = self::version();
		$cache->set(self::verKey(), $ver + 1, 0);
		self::writeWordFile($words);
	}

	/**
	 * 从种子灌入 Cache（TXT 或配置数组）
	 *
	 * @param bool $force 已有词是否强制覆盖
	 * @return array{code:int,message:string,count?:int,ver?:int,skipped?:bool}
	 */
	public static function seedFromFile(bool $force = false): array
	{
		self::assertEnabled();
		$cache = self::cache();
		$existing = $cache->get(self::wordsKey(), null);
		if (!$force && is_array($existing) && $existing !== []) {
			$ver = self::version();
			return [
				'code'    => 0,
				'skipped' => true,
				'message' => 'cache already has words',
				'count'   => count(self::normalizeWords($existing)),
				'ver'     => $ver,
			];
		}

		$config = Bootstrap::config();
		$mode = $config['mode'] ?? 'file';
		$words = [];
		if ($mode === 'array') {
			$words = self::normalizeWords($config['sensitive_words'] ?? []);
		} else {
			$file = self::wordFile();
			if (!is_file($file)) {
				return ['code' => 1, 'message' => 'word file missing: ' . $file];
			}
			$words = self::readWordFile($file);
		}

		if ($words === []) {
			return ['code' => 1, 'message' => 'seed words empty'];
		}

		$cache->set(self::wordsKey(), $words, 0);
		$cache->set(self::verKey(), max(1, self::version() + 1), 0);

		return [
			'code'    => 0,
			'message' => 'seeded ' . count($words) . ' words',
			'count'   => count($words),
			'ver'     => self::version(),
		];
	}

	/**
	 * 规范化词列表
	 *
	 * @param mixed $words 原始
	 * @return list<string>
	 */
	public static function normalizeWords(mixed $words): array
	{
		if (!is_array($words)) {
			return [];
		}
		$out = [];
		foreach ($words as $w) {
			if (!is_string($w) && !is_numeric($w)) {
				continue;
			}
			$t = trim((string)$w);
			if ($t === '') {
				continue;
			}
			$out[$t] = $t;
		}
		return array_values($out);
	}

	/**
	 * 读取 TXT 词库
	 *
	 * @param string $file 路径
	 * @return list<string>
	 */
	public static function readWordFile(string $file): array
	{
		$words = [];
		$handle = fopen($file, 'rb');
		if ($handle === false) {
			\Candy\Core\Debug::showMessage('Read word file failed', 'Sensitive');
		}
		while (!feof($handle)) {
			$line = fgets($handle);
			if (!is_string($line)) {
				continue;
			}
			$line = str_replace(["'", ' ', "\r", "\n", ','], '', $line);
			$line = trim($line);
			if ($line !== '') {
				$words[$line] = $line;
			}
		}
		fclose($handle);
		return array_values($words);
	}

	/**
	 * 全量回写 TXT
	 *
	 * @param list<string> $words 词列表
	 * @return void
	 */
	public static function writeWordFile(array $words): void
	{
		$file = self::wordFile();
		$dir = dirname($file);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			\Candy\Core\Debug::showMessage('Cannot create word dir: ' . $dir, 'Sensitive');
		}
		$body = implode(PHP_EOL, $words);
		if (@file_put_contents($file, $body) === false) {
			\Candy\Core\Debug::showMessage('Cannot write word file: ' . $file, 'Sensitive');
		}
	}
}
