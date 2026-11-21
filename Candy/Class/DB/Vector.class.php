<?php
/***
 * 嵌入式 PHP 向量旁路客户端
 *
 * 业务说明：框架内自研向量库门面；数据在 {CANDYROOT}/Data/vector[/t/{id}]/{alias}/（V(alias)，默认 default）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 23:50:25 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use Candy\Extend\DB\Vector\Config;
use Candy\Extend\DB\Vector\Engine;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Vector
{
	private ?Engine $engine = null;
	private string $alias = 'default';
	/** @var array<string,mixed> */
	private array $options = [];

	/**
	 * @param string|null $alias 库别名
	 * @param array $options dimensions/path/m 等覆盖
	 */
	public function __construct(?string $alias = null, array $options = [])
	{
		$this->alias = $alias !== null && trim($alias) !== '' ? trim($alias) : 'default';
		$this->options = $options;
	}

	/**
	 * 打开库（可切换 alias）
	 *
	 * @param string|null $alias
	 * @param array $options
	 * @return self
	 */
	public function open(?string $alias = null, array $options = []): self
	{
		if ($alias !== null && trim($alias) !== '') {
			$this->alias = trim($alias);
		}
		if ($options !== []) {
			$this->options = array_merge($this->options, $options);
		}
		$this->engine = new Engine(Config::from($this->alias, $this->options));
		$this->engine->open();
		return $this;
	}

	/**
	 * 探测目录可写与配置
	 *
	 * @return array{code:int,message:string,data?:array}
	 */
	public function ping(): array
	{
		$cfg = Config::from($this->alias, $this->options);
		$dir = $cfg->dataDir;
		$parent = dirname($dir);
		$writable = (is_dir($dir) && is_writable($dir))
			|| (is_dir($parent) && is_writable($parent))
			|| (!is_dir($parent) && is_writable(dirname($parent)));
		if (!$writable) {
			return [
				'code' => 1,
				'message' => 'Data/vector 目录不可写',
				'data' => ['dir' => $dir],
			];
		}
		return [
			'code' => 0,
			'message' => 'ok',
			'data' => [
				'dir' => $dir,
				'dimensions' => $cfg->dimensions,
				'alias' => $cfg->alias,
			],
		];
	}

	/**
	 * @return Engine
	 */
	protected function engine(): Engine
	{
		if ($this->engine === null) {
			$this->open();
		}
		/** @var Engine $e */
		$e = $this->engine;
		return $e;
	}

	/**
	 * @param string $id
	 * @param array $vector
	 * @param array|null $payload 元数据（可含 text）
	 * @param string|null $text BM25 正文
	 * @return array{code:int,message:string,data?:array}
	 */
	public function upsert(string $id, array $vector, ?array $payload = null, ?string $text = null): array
	{
		return $this->engine()->upsert($id, $vector, $payload, $text);
	}

	/**
	 * @param string $id
	 * @return array{code:int,message:string}
	 */
	public function delete(string $id): array
	{
		return $this->engine()->delete($id);
	}

	/**
	 * @param array $query
	 * @param int $topK
	 * @param string|null $metric cosine|euclidean|dot|manhattan
	 * @param array<string,mixed>|null $filter metadata 过滤
	 * @return array{code:int,message:string,data?:list}
	 */
	public function search(
		array $query,
		int $topK = 10,
		?string $metric = null,
		?array $filter = null
	): array {
		return $this->engine()->search($query, $topK, $metric, $filter);
	}

	/**
	 * BM25 全文检索
	 *
	 * @param string $query
	 * @param int $topK
	 * @param array<string,mixed>|null $filter
	 * @return array{code:int,message:string,data?:list}
	 */
	public function textSearch(string $query, int $topK = 10, ?array $filter = null): array
	{
		return $this->engine()->textSearch($query, $topK, $filter);
	}

	/**
	 * 混合检索
	 *
	 * @param array $vector
	 * @param string $text
	 * @param int $topK
	 * @param string $mode rrf|weighted
	 * @param float $vectorWeight
	 * @param float $textWeight
	 * @param string|null $metric
	 * @param array<string,mixed>|null $filter
	 * @return array{code:int,message:string,data?:list}
	 */
	public function hybridSearch(
		array $vector,
		string $text,
		int $topK = 10,
		string $mode = 'rrf',
		float $vectorWeight = 0.7,
		float $textWeight = 0.3,
		?string $metric = null,
		?array $filter = null
	): array {
		return $this->engine()->hybridSearch(
			$vector,
			$text,
			$topK,
			$mode,
			$vectorWeight,
			$textWeight,
			$metric,
			$filter
		);
	}

	/**
	 * @return array{code:int,message:string,data?:array}
	 */
	public function stats(): array
	{
		return $this->engine()->stats();
	}

	/**
	 * @return array{code:int,message:string,data?:array}
	 */
	public function optimize(): array
	{
		return $this->engine()->optimize();
	}
}

