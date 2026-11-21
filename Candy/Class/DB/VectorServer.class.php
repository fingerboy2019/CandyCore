<?php
/***
 * 向量框架门面（类比 DBServer 的统一入口角色）
 *
 * 业务说明：项目无需再写 Admin Service 封装；V() / VectorServer::link() 直接调用
 * 路由仍走 VectorRouter（pgvector 优先，回退 PHP 嵌入式）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 08:20:56 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class VectorServer
{
	private ?string $alias;
	/** @var array<string,mixed> */
	private array $options;

	/**
	 * @param string|null $alias 库/连接别名
	 * @param array $options force / table / dimensions / prefer_pgvector 等
	 */
	private function __construct(?string $alias = null, array $options = [])
	{
		$this->alias = $alias;
		$this->options = $options;
	}

	/**
	 * 取得带绑定上下文的门面实例（推荐经 V() 调用）
	 *
	 * @param string|null $alias
	 * @param array $options
	 * @return self
	 */
	public static function link(?string $alias = null, array $options = []): self
	{
		return new self($alias, $options);
	}

	/**
	 * 合并本次调用的覆盖选项
	 *
	 * @param array $options
	 * @return self 新实例（不可变链式）
	 */
	public function with(array $options): self
	{
		return new self($this->alias, array_merge($this->options, $options));
	}

	/**
	 * 强制 PHP 嵌入式后端
	 *
	 * @return self
	 */
	public function usePhp(): self
	{
		return $this->with(['force' => VectorRouter::BACKEND_PHP]);
	}

	/**
	 * 强制 pgvector 后端
	 *
	 * @return self
	 */
	public function usePgvector(): self
	{
		return $this->with(['force' => VectorRouter::BACKEND_PG]);
	}

	/**
	 * @return array{backend:string,reason:string}
	 */
	public function resolve(): array
	{
		return VectorRouter::resolve($this->alias, $this->options);
	}

	/**
	 * @return array{code:int,message:string,data?:array}
	 */
	public function ping(): array
	{
		return VectorRouter::ping($this->alias, $this->options);
	}

	/**
	 * @return array{code:int,message:string,data?:array}
	 */
	public function open(): array
	{
		return $this->ping();
	}

	/**
	 * @param string $id
	 * @param array $vector
	 * @param array|string|null $payloadOrContent
	 * @return array{code:int,message:string,data?:array,backend?:string}
	 */
	public function upsert(string $id, array $vector, array|string|null $payloadOrContent = null): array
	{
		return VectorRouter::upsert($id, $vector, $payloadOrContent, $this->alias, $this->options);
	}

	/**
	 * @param array $query
	 * @param int $topK
	 * @param string|null $metric
	 * @param array<string,mixed>|null $filter
	 * @return array{code:int,message:string,data?:list,backend?:string}
	 */
	public function search(
		array $query,
		int $topK = 10,
		?string $metric = null,
		?array $filter = null
	): array {
		$opts = $this->options;
		if ($metric !== null) {
			$opts['metric'] = $metric;
		}
		if ($filter !== null) {
			$opts['filter'] = $filter;
		}
		return VectorRouter::search($query, $topK, $this->alias, $opts);
	}

	/**
	 * BM25 全文（仅 PHP 嵌入式；pg 后端自动回退 PHP）
	 *
	 * @param string $query
	 * @param int $topK
	 * @param array<string,mixed>|null $filter
	 * @return array{code:int,message:string,data?:list,backend?:string}
	 */
	public function textSearch(string $query, int $topK = 10, ?array $filter = null): array
	{
		$opts = $this->options;
		if ($filter !== null) {
			$opts['filter'] = $filter;
		}
		return VectorRouter::textSearch($query, $topK, $this->alias, $opts);
	}

	/**
	 * 混合检索（仅 PHP 嵌入式）
	 *
	 * @param array $vector
	 * @param string $text
	 * @param int $topK
	 * @param string $mode
	 * @param float $vectorWeight
	 * @param float $textWeight
	 * @param string|null $metric
	 * @param array<string,mixed>|null $filter
	 * @return array{code:int,message:string,data?:list,backend?:string}
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
		$opts = $this->options;
		$opts['hybrid_mode'] = $mode;
		$opts['vector_weight'] = $vectorWeight;
		$opts['text_weight'] = $textWeight;
		if ($metric !== null) {
			$opts['metric'] = $metric;
		}
		if ($filter !== null) {
			$opts['filter'] = $filter;
		}
		return VectorRouter::hybridSearch($vector, $text, $topK, $this->alias, $opts);
	}

	/**
	 * @param string $id
	 * @return array{code:int,message:string,backend?:string}
	 */
	public function delete(string $id): array
	{
		return VectorRouter::delete($id, $this->alias, $this->options);
	}

	/**
	 * @return array{code:int,message:string,backend?:string}
	 */
	public function optimize(): array
	{
		return VectorRouter::optimize($this->alias, $this->options);
	}

	/**
	 * 静态快捷：不持上下文时直接调用
	 *
	 * @param string $id
	 * @param array $vector
	 * @param array|string|null $payloadOrContent
	 * @param string|null $alias
	 * @param array $options
	 * @return array
	 */
	public static function put(
		string $id,
		array $vector,
		array|string|null $payloadOrContent = null,
		?string $alias = null,
		array $options = []
	): array {
		return VectorRouter::upsert($id, $vector, $payloadOrContent, $alias, $options);
	}

	/**
	 * 静态快捷检索
	 *
	 * @param array $query
	 * @param int $topK
	 * @param string|null $alias
	 * @param array $options
	 * @return array
	 */
	public static function query(
		array $query,
		int $topK = 10,
		?string $alias = null,
		array $options = []
	): array {
		return VectorRouter::search($query, $topK, $alias, $options);
	}

	/**
	 * 清除后端解析缓存
	 *
	 * @return void
	 */
	public static function clearResolveCache(): void
	{
		VectorRouter::clearResolveCache();
	}
}

