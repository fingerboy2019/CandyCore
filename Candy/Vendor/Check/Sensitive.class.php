<?php
/***
 * 敏感词 DFA 处理
 *
 * 业务说明：进程内挂树；词列表权威源为 WordStore（Cache）；未 init 不可用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 12:31:43 $
 *
 * @package Check
 */

declare(strict_types=1);

namespace Check;

use Candy\Extend\HashMap;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Sensitive
{
	/** @var array<string,mixed> 运行配置片段 */
	protected array $config = [];

	/** @var int 待检测语句长度 */
	protected int $contentLength = 0;

	/** @var HashMap|null 敏感词库树 */
	protected HashMap|null $wordTree = null;

	/** @var array|null 最近一次命中词缓存 */
	protected array|null $badWordList = null;

	/** @var array 干扰因子 */
	protected array $interferenceFactors = [];

	/** @var array 临时删除（once）待恢复列表 */
	public array $removeList = [];

	/** @var int 进程内已加载的词库版本 */
	protected int $localVer = -1;

	protected static ?Sensitive $_instance = null;

	/**
	 * 单例
	 *
	 * @return self
	 */
	public static function instance(): self
	{
		if (!static::$_instance) {
			static::$_instance = new self();
		}
		return static::$_instance;
	}

	/**
	 * 静态转实例（仅当方法名不存在于类上时触发；推荐 Sensitive::instance()->is()）
	 *
	 * @param string $name 方法名
	 * @param array  $arguments 参数
	 * @return mixed
	 */
	public static function __callStatic($name, $arguments)
	{
		return static::instance()->{$name}(...$arguments);
	}

	/**
	 * 构造：只读配置，不扫 TXT、不建树（ensureTree 懒加载）
	 */
	public function __construct()
	{
		Bootstrap::loadHelpers();
		if (!Bootstrap::enabled()) {
			return;
		}
		$full = Bootstrap::config();
		$this->config = is_array($full['config'] ?? null) ? $full['config'] : [];
		$this->interferenceFactors = $full['interference_factors'] ?? [];
	}

	/**
	 * 未启用则中断
	 *
	 * @return void
	 */
	protected function assertEnabled(): void
	{
		WordStore::assertEnabled();
		if ($this->config === []) {
			$full = Bootstrap::config();
			$this->config = is_array($full['config'] ?? null) ? $full['config'] : [];
			$this->interferenceFactors = $full['interference_factors'] ?? [];
		}
	}

	/**
	 * 确保进程内树与 Cache 版本一致
	 *
	 * @return void
	 */
	protected function ensureTree(): void
	{
		$this->assertEnabled();
		$ver = WordStore::version();
		if ($this->wordTree !== null && $this->localVer === $ver) {
			return;
		}
		$words = WordStore::loadWords();
		$this->wordTree = new HashMap();
		foreach ($words as $word) {
			$this->buildWordToTree($word);
		}
		$this->localVer = $ver;
		$this->badWordList = null;
	}

	/**
	 * 被检测内容是否含敏感词|简写
	 *
	 * @param string $content 文本
	 * @return bool
	 */
	public function is(string $content): bool
	{
		return $this->check($content);
	}

	/**
	 * 被检测内容是否含敏感词
	 *
	 * @param string $content 文本
	 * @return bool
	 */
	public function check(string $content): bool
	{
		$this->ensureTree();
		$this->contentLength = sensitive_mb_strlen($content, 'utf-8');

		for ($length = 0; $length < $this->contentLength; $length++) {
			$matchFlag = 0;
			$tempMap = $this->wordTree;
			for ($i = $length; $i < $this->contentLength; $i++) {
				$keyChar = mb_substr($content, $i, 1, 'utf-8');

				if ($this->checkInterferenceFactor($keyChar)) {
					$matchFlag++;
					continue;
				}

				$nowMap = $tempMap->get($keyChar);
				if (empty($nowMap)) {
					break;
				}

				$tempMap = $nowMap;
				$matchFlag++;

				if ($nowMap->get('isEnd') === false) {
					continue;
				}

				$this->recoverRemove();
				return true;
			}

			if ($matchFlag <= 0) {
				continue;
			}
			$length += $matchFlag - 1;
		}

		$this->recoverRemove();
		return false;
	}

	/**
	 * 替换敏感词
	 *
	 * @param string $content 文本
	 * @param string $replaceChar 替换字符
	 * @param bool   $repeat 是否按词长重复字符
	 * @param int    $matchType 1=最小匹配
	 * @return string
	 */
	public function replace(string $content, string $replaceChar = '', bool $repeat = false, $matchType = 1): string
	{
		$this->ensureTree();
		if ($content === '') {
			return $content;
		}

		$replaceChar = $replaceChar !== '' ? $replaceChar : (string)($this->config['replace_char'] ?? '*');
		if (!$repeat) {
			$repeat = (bool)($this->config['repeat'] ?? false);
		}

		$badWordList = $this->badWordList ?: $this->get($content, $matchType);
		if ($badWordList === []) {
			return $content;
		}

		foreach ($badWordList as $badWord) {
			$hasReplacedChar = $replaceChar;
			if ($repeat) {
				$hasReplacedChar = $this->dfaBadWordConversChars($badWord, $replaceChar);
			}
			$content = str_replace($badWord, $hasReplacedChar, $content);
		}
		return $content;
	}

	/**
	 * 标记敏感词
	 *
	 * @param string $content 文本
	 * @param string $tag 标签名
	 * @param int    $matchType 匹配类型
	 * @return string
	 */
	public function mark(string $content, string $tag = '', $matchType = 1): string
	{
		$this->ensureTree();
		if ($content === '') {
			return $content;
		}

		if ($tag === '') {
			$tag = (string)($this->config['mark'] ?? 'mark');
		}
		$sTag = '<' . $tag . '>';
		$eTag = '</' . $tag . '>';

		$badWordList = $this->badWordList ?: $this->get($content, $matchType);
		if ($badWordList === []) {
			return $content;
		}

		foreach (array_unique($badWordList) as $badWord) {
			$content = str_replace($badWord, $sTag . $badWord . $eTag, $content);
		}
		return $content;
	}

	/**
	 * 提取敏感词
	 *
	 * @param string $content 文本
	 * @param int    $matchType 1=最小匹配
	 * @param int    $wordNum 限制数量，0=全部
	 * @return array
	 */
	public function get(string $content, $matchType = 1, $wordNum = 0): array
	{
		$this->ensureTree();
		$this->contentLength = sensitive_mb_strlen($content, 'utf-8');
		$badWordList = [];

		for ($length = 0; $length < $this->contentLength; $length++) {
			$matchFlag = 0;
			$flag = false;
			$tempMap = $this->wordTree;

			for ($i = $length; $i < $this->contentLength; $i++) {
				$keyChar = mb_substr($content, $i, 1, 'utf-8');

				if ($this->checkInterferenceFactor($keyChar)) {
					$matchFlag++;
					continue;
				}

				$nowMap = $tempMap->get($keyChar);
				if (empty($nowMap)) {
					break;
				}

				$tempMap = $nowMap;
				$matchFlag++;

				if ($nowMap->get('isEnd') === false) {
					continue;
				}

				$flag = true;
				if ((int)$matchType === 1) {
					break;
				}
			}

			if (!$flag) {
				$matchFlag = 0;
			}

			if ($matchFlag > 0) {
				$badWordList[] = $this->ltrimInterferenceFactorBadWord(
					mb_substr($content, $length, $matchFlag, 'utf-8')
				);
				if ($wordNum > 0 && count($badWordList) === (int)$wordNum) {
					$this->recoverRemove();
					return $badWordList;
				}
				$length += $matchFlag - 1;
			}
		}

		$this->recoverRemove();
		$this->badWordList = $badWordList;
		return $badWordList;
	}

	/**
	 * 添加敏感词（持久化：Cache + TXT + ver）
	 *
	 * @param string|array $words 词或列表（| 分隔）
	 * @return $this
	 */
	public function add(string|array $words): self
	{
		$this->assertEnabled();
		$add = $this->normalizeInputWords($words);
		if ($add === []) {
			return $this;
		}
		$all = WordStore::normalizeWords(array_merge(WordStore::loadWords(), $add));
		WordStore::saveWords($all);
		$this->rebuildFromStore();
		return $this;
	}

	/**
	 * 删除敏感词
	 *
	 * @param string|array $words 词
	 * @param bool         $once 仅本进程临时删除（检测后恢复），不落盘
	 * @return $this
	 */
	public function remove(string|array $words, bool $once = false): self
	{
		$this->ensureTree();
		$list = $this->normalizeInputWords($words);
		if ($list === []) {
			return $this;
		}

		if ($once) {
			foreach ($list as $word) {
				$this->removeToTree($word, true);
			}
			return $this;
		}

		$current = WordStore::loadWords();
		$removeMap = array_fill_keys($list, true);
		$kept = [];
		foreach ($current as $w) {
			if (!isset($removeMap[$w])) {
				$kept[] = $w;
			}
		}
		WordStore::saveWords($kept);
		$this->rebuildFromStore();
		return $this;
	}

	/**
	 * 从树上取消词尾标记（临时删除用）
	 *
	 * @param string $word 词
	 * @param bool   $once 是否记入恢复列表
	 * @return void
	 */
	public function removeToTree(string $word, bool $once = false): void
	{
		if ($this->wordTree === null || $word === '') {
			return;
		}
		$node = $this->wordTree;
		$len = sensitive_mb_strlen($word, 'utf-8');
		$path = [];
		for ($i = 0; $i < $len; $i++) {
			$ch = mb_substr($word, $i, 1, 'utf-8');
			$next = $node->get($ch);
			if (!$next instanceof HashMap) {
				return;
			}
			$path[] = [$node, $ch];
			$node = $next;
		}
		$node->put('isEnd', false);
		if ($once) {
			$this->removeList[] = $word;
		}
	}

	/**
	 * 恢复 once 删除的词
	 *
	 * @return bool
	 */
	public function recoverRemove(): bool
	{
		if ($this->removeList === []) {
			return false;
		}
		foreach ($this->removeList as $word) {
			$this->buildWordToTree($word);
		}
		$this->removeList = [];
		return true;
	}

	/**
	 * 自定义词库（仅重建进程树；持久化请用 add / WordStore）
	 *
	 * @param string|array $custom 文件路径或词数组
	 * @return $this
	 */
	public function custom(string|array $custom): self
	{
		$this->assertEnabled();
		if (is_string($custom)) {
			if (!is_file($custom)) {
				\Candy\Core\Debug::showMessage('The sensitive words file does not exist', 'Sensitive');
			}
			$words = WordStore::readWordFile($custom);
		} else {
			$words = WordStore::normalizeWords($custom);
		}
		WordStore::saveWords($words);
		$this->rebuildFromStore();
		return $this;
	}

	/**
	 * 兼容旧 API：文件建树 → 改为写入 WordStore
	 *
	 * @param string $file 路径
	 * @return $this
	 */
	public function setFile(string $file): self
	{
		return $this->custom($file);
	}

	/**
	 * 兼容旧 API：数组建树 → 写入 WordStore
	 *
	 * @param array|null $sensitiveWords 词列表
	 * @return $this
	 */
	public function setTree(array|null $sensitiveWords = null): self
	{
		if ($sensitiveWords === null || $sensitiveWords === []) {
			\Candy\Core\Debug::showMessage('The sensitive words cannot be empty', 'Sensitive');
		}
		return $this->custom($sensitiveWords);
	}

	/**
	 * 追加干扰因子
	 *
	 * @param array $interferenceFactors 因子
	 * @return $this
	 */
	public function interferenceFactor(array $interferenceFactors): self
	{
		$this->interferenceFactors = array_unique(array_merge($this->interferenceFactors, $interferenceFactors));
		return $this;
	}

	/**
	 * 去掉命中词前导干扰因子
	 *
	 * @param string $word 命中片段
	 * @return string
	 */
	public function ltrimInterferenceFactorBadWord(string $word): string
	{
		$characters = '';
		foreach ($this->interferenceFactors as $interferenceFactor) {
			$characters .= $interferenceFactor . '\\' . ' ' . $interferenceFactor;
		}
		return $characters !== '' ? ltrim($word, $characters) : $word;
	}

	/**
	 * 向词库添加并持久化（等同 add）
	 *
	 * @param string|array $data 新词
	 * @param bool         $append true 合并；false 以 $data 全量覆盖前先读当前再合并去重
	 * @return bool
	 */
	public function addWordToFile(string|array $data, bool $append = true): bool
	{
		$this->assertEnabled();
		$add = $this->normalizeInputWords($data);
		if ($append) {
			$this->add($add);
		} else {
			$all = WordStore::normalizeWords(array_merge(WordStore::loadWords(), $add));
			WordStore::saveWords($all);
			$this->rebuildFromStore();
		}
		return true;
	}

	/**
	 * 从 WordStore 重建本进程树
	 *
	 * @return void
	 */
	protected function rebuildFromStore(): void
	{
		$words = WordStore::loadWords();
		$this->wordTree = new HashMap();
		foreach ($words as $word) {
			$this->buildWordToTree($word);
		}
		$this->localVer = WordStore::version();
		$this->badWordList = null;
	}

	/**
	 * 规范化输入词
	 *
	 * @param string|array $words 输入
	 * @return list<string>
	 */
	protected function normalizeInputWords(string|array $words): array
	{
		if (is_string($words) && str_contains($words, '|')) {
			$words = explode('|', $words);
		}
		return WordStore::normalizeWords((array)$words);
	}

	/**
	 * 将单个敏感词写入树
	 *
	 * @param string $word 词
	 * @return void
	 */
	protected function buildWordToTree(string $word = ''): void
	{
		if ($word === '' || $this->wordTree === null) {
			return;
		}

		$tree = $this->wordTree;
		$wordLength = sensitive_mb_strlen($word, 'utf-8');
		for ($i = 0; $i < $wordLength; $i++) {
			$keyChar = mb_substr($word, $i, 1, 'utf-8');
			$tempTree = $tree->get($keyChar);
			if ($tempTree) {
				$tree = $tempTree;
			} else {
				$newTree = new HashMap();
				$newTree->put('isEnd', false);
				$tree->put($keyChar, $newTree);
				$tree = $newTree;
			}
			if ($i === $wordLength - 1) {
				$tree->put('isEnd', true);
			}
		}
	}

	/**
	 * 按词长生成替换串
	 *
	 * @param string $word 敏感词
	 * @param string $char 字符
	 * @return string
	 */
	protected function dfaBadWordConversChars(string $word, string $char): string
	{
		$str = '';
		$length = sensitive_mb_strlen($word, 'utf-8');
		for ($counter = 0; $counter < $length; ++$counter) {
			$str .= $char;
		}
		return $str;
	}

	/**
	 * 是否为干扰因子
	 *
	 * @param string $word 单字
	 * @return bool
	 */
	protected function checkInterferenceFactor(string $word): bool
	{
		return in_array($word, $this->interferenceFactors, true);
	}
}
