<?php
/***
 * MongoDB 旁路文档客户端
 *
 * 业务说明：基于 PECL mongodb 扩展提供集合 CRUD 与文档助手；不注册为默认 DRIVER
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 21:53:40 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\WriteConcern;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Mongo
{
	/** 文档助手默认集合名 */
	public const DEFAULT_DOC_COLLECTION = 'docs';

	/** @var string 隔离别名 */
	protected string $alias = 'mongo';

	/** @var string 连接 URI */
	protected string $uri = 'mongodb://127.0.0.1:27017';

	/** @var string 数据库名 */
	protected string $dbname = 'candy_docs';

	/** @var Manager|null */
	protected ?Manager $manager = null;

	/**
	 * @param array<string,mixed>|string|null $config 配置数组、URI 字符串或 null（读别名 mongo）
	 * @param string|null $alias 隔离别名（仅 config 为 null 时生效）
	 */
	public function __construct(array|string|null $config = null, ?string $alias = null)
	{
		if (is_string($alias) && $alias !== '') {
			$this->alias = $alias;
		}
		if (is_string($config) && $config !== '') {
			$this->uri = $config;
			return;
		}
		if (!is_array($config)) {
			$config = $this->loadAliasConfig($this->alias);
		}
		if (is_array($config)) {
			if (!empty($config['URI'])) {
				$this->uri = (string) $config['URI'];
			} elseif (!empty($config['HOST'])) {
				$host = (string) $config['HOST'];
				$port = (int) ($config['PORT'] ?? 27017);
				$user = (string) ($config['USER'] ?? '');
				$pass = (string) ($config['PASS'] ?? '');
				$auth = '';
				if ($user !== '') {
					$auth = rawurlencode($user);
					if ($pass !== '') {
						$auth .= ':' . rawurlencode($pass);
					}
					$auth .= '@';
				}
				$this->uri = 'mongodb://' . $auth . $host . ':' . $port;
			}
			if (!empty($config['DBNAME'])) {
				$this->dbname = (string) $config['DBNAME'];
			}
		}
	}

	/**
	 * 从隔离别名读取配置
	 *
	 * @param string $alias 别名
	 * @return array<string,mixed>
	 */
	protected function loadAliasConfig(string $alias): array
	{
		foreach ([G('DBLIST'), C('DBLIST')] as $list) {
			if (is_array($list) && isset($list[$alias]) && is_array($list[$alias])) {
				return $list[$alias];
			}
		}
		$db = C('DB');
		if (is_array($db) && isset($db[$alias]) && is_array($db[$alias])) {
			return $db[$alias];
		}
		return [];
	}

	/**
	 * PECL mongodb 扩展是否已加载
	 *
	 * @return bool
	 */
	public function hasExtension(): bool
	{
		return extension_loaded('mongodb') && class_exists(Manager::class);
	}

	/**
	 * 当前库名
	 *
	 * @return string
	 */
	public function dbname(): string
	{
		return $this->dbname;
	}

	/**
	 * 当前别名
	 *
	 * @return string
	 */
	public function alias(): string
	{
		return $this->alias;
	}

	/**
	 * 获取 Manager（懒加载）
	 *
	 * @return Manager|null 无扩展时返回 null
	 */
	public function manager(): ?Manager
	{
		if (!$this->hasExtension()) {
			return null;
		}
		if ($this->manager === null) {
			$this->manager = new Manager($this->uri);
		}
		return $this->manager;
	}

	/**
	 * 命名空间 collection → db.collection
	 *
	 * @param string $collection 集合名
	 * @return string
	 */
	public function ns(string $collection): string
	{
		$collection = $this->assertCollection($collection);
		return $this->dbname . '.' . $collection;
	}

	/**
	 * 连通性探测
	 *
	 * @return array{code:int,message:string,data?:array{available:bool,extension:bool,dbname:string,alias:string,version?:string}}
	 */
	public function ping(): array
	{
		if (!$this->hasExtension()) {
			return [
				'code' => 1,
				'message' => '未安装 PECL mongodb 扩展（php84-pecl install mongodb）',
				'data' => [
					'available' => false,
					'extension' => false,
					'dbname' => $this->dbname,
					'alias' => $this->alias,
				],
			];
		}

		try {
			$manager = $this->manager();
			if ($manager === null) {
				return [
					'code' => 1,
					'message' => '无法创建 MongoDB Manager',
					'data' => [
						'available' => false,
						'extension' => true,
						'dbname' => $this->dbname,
						'alias' => $this->alias,
					],
				];
			}
			$cursor = $manager->executeCommand($this->dbname, new Command(['ping' => 1]));
			$cursor->toArray();
			$version = '';
			try {
				$info = $manager->executeCommand('admin', new Command(['buildInfo' => 1]))->toArray();
				$version = (string) ($info[0]->version ?? '');
			} catch (Throwable) {
				$version = '';
			}
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => [
					'available' => true,
					'extension' => true,
					'dbname' => $this->dbname,
					'alias' => $this->alias,
					'version' => $version,
				],
			];
		} catch (Throwable $e) {
			return [
				'code' => 1,
				'message' => 'MongoDB 不可用：' . $e->getMessage(),
				'data' => [
					'available' => false,
					'extension' => true,
					'dbname' => $this->dbname,
					'alias' => $this->alias,
				],
			];
		}
	}

	/**
	 * 插入单条文档
	 *
	 * @param string $collection 集合
	 * @param array<string,mixed> $document 文档
	 * @return array{code:int,message:string,data?:array{inserted_id:string}}
	 */
	public function insert(string $collection, array $document): array
	{
		$gate = $this->requireReady();
		if ($gate !== null) {
			return $gate;
		}
		if ($document === []) {
			return ['code' => 1, 'message' => '文档为空'];
		}

		try {
			$bulk = new BulkWrite();
			$id = $bulk->insert($document);
			$this->manager()->executeBulkWrite($this->ns($collection), $bulk, $this->writeOptions());
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => ['inserted_id' => $this->idToString($id)],
			];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 查询多条
	 *
	 * @param string $collection 集合
	 * @param array<string,mixed> $filter 过滤条件
	 * @param array{limit?:int,skip?:int,sort?:array<string,int>} $options 选项
	 * @return array{code:int,message:string,data?:array<int,array<string,mixed>>}
	 */
	public function find(string $collection, array $filter = [], array $options = []): array
	{
		$gate = $this->requireReady();
		if ($gate !== null) {
			return $gate;
		}

		try {
			$queryOpts = [];
			if (isset($options['limit'])) {
				$queryOpts['limit'] = max(1, (int) $options['limit']);
			}
			if (isset($options['skip'])) {
				$queryOpts['skip'] = max(0, (int) $options['skip']);
			}
			if (isset($options['sort']) && is_array($options['sort'])) {
				$queryOpts['sort'] = $options['sort'];
			}
			$cursor = $this->manager()->executeQuery(
				$this->ns($collection),
				new Query($filter, $queryOpts)
			);
			$rows = [];
			foreach ($cursor as $doc) {
				$rows[] = $this->bsonToArray($doc);
			}
			return ['code' => 0, 'message' => 'ok', 'data' => $rows];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 查询单条
	 *
	 * @param string $collection 集合
	 * @param array<string,mixed> $filter 过滤
	 * @return array{code:int,message:string,data?:array<string,mixed>|null}
	 */
	public function findOne(string $collection, array $filter = []): array
	{
		$res = $this->find($collection, $filter, ['limit' => 1]);
		if (($res['code'] ?? 1) !== 0) {
			return $res;
		}
		$row = $res['data'][0] ?? null;
		return [
			'code' => 0,
			'message' => 'ok',
			'data' => is_array($row) ? $row : null,
		];
	}

	/**
	 * 更新文档（默认 multi=false）
	 *
	 * @param string $collection 集合
	 * @param array<string,mixed> $filter 过滤
	 * @param array<string,mixed> $update 更新操作（须含 $set / $unset 等算子，或整文档替换时传 replace=true）
	 * @param array{multi?:bool,upsert?:bool,replace?:bool} $options 选项
	 * @return array{code:int,message:string,data?:array{matched:int,modified:int,upserted:int}}
	 */
	public function update(string $collection, array $filter, array $update, array $options = []): array
	{
		$gate = $this->requireReady();
		if ($gate !== null) {
			return $gate;
		}
		if ($update === []) {
			return ['code' => 1, 'message' => '更新内容为空'];
		}

		try {
			$bulk = new BulkWrite();
			$writeOpts = [
				'multi' => !empty($options['multi']),
				'upsert' => !empty($options['upsert']),
			];
			if (!empty($options['replace'])) {
				// 无 $ 算子的文档视为整文档替换
				$bulk->update($filter, $update, $writeOpts);
			} else {
				if (!$this->isOperatorDoc($update)) {
					$update = ['$set' => $update];
				}
				$bulk->update($filter, $update, $writeOpts);
			}
			$result = $this->manager()->executeBulkWrite(
				$this->ns($collection),
				$bulk,
				$this->writeOptions()
			);
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => [
					'matched' => $result->getMatchedCount(),
					'modified' => $result->getModifiedCount(),
					'upserted' => $result->getUpsertedCount(),
				],
			];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 删除文档
	 *
	 * @param string $collection 集合
	 * @param array<string,mixed> $filter 过滤
	 * @param bool $multi 是否多条
	 * @return array{code:int,message:string,data?:array{deleted:int}}
	 */
	public function delete(string $collection, array $filter, bool $multi = true): array
	{
		$gate = $this->requireReady();
		if ($gate !== null) {
			return $gate;
		}
		if ($filter === []) {
			return ['code' => 1, 'message' => '禁止空过滤条件全表删除'];
		}

		try {
			$bulk = new BulkWrite();
			$bulk->delete($filter, ['limit' => $multi ? 0 : 1]);
			$result = $this->manager()->executeBulkWrite(
				$this->ns($collection),
				$bulk,
				$this->writeOptions()
			);
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => ['deleted' => $result->getDeletedCount()],
			];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 确保文档集合索引（doc_id 唯一 + tags）
	 *
	 * @param string $collection 集合名
	 * @return array{code:int,message:string}
	 */
	public function ensureDocIndexes(string $collection = self::DEFAULT_DOC_COLLECTION): array
	{
		$gate = $this->requireReady();
		if ($gate !== null) {
			return $gate;
		}

		try {
			$manager = $this->manager();
			$manager->executeCommand($this->dbname, new Command([
				'createIndexes' => $this->assertCollection($collection),
				'indexes' => [
					[
						'key' => ['doc_id' => 1],
						'name' => 'uniq_doc_id',
						'unique' => true,
					],
					[
						'key' => ['tags' => 1],
						'name' => 'idx_tags',
					],
				],
			]));
			return ['code' => 0, 'message' => 'ok'];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 按 doc_id upsert 业务文档
	 *
	 * @param string $docId 业务文档 ID
	 * @param array{title?:string,body?:string,tags?:array<int,string>,meta?:array<string,mixed>} $fields 字段
	 * @param string $collection 集合
	 * @return array{code:int,message:string}
	 */
	public function upsertDoc(string $docId, array $fields, string $collection = self::DEFAULT_DOC_COLLECTION): array
	{
		$docId = trim($docId);
		if ($docId === '') {
			return ['code' => 1, 'message' => 'doc_id 不能为空'];
		}

		$set = [
			'doc_id' => $docId,
			'updated_at' => date('Y-m-d H:i:s'),
		];
		if (array_key_exists('title', $fields)) {
			$set['title'] = (string) $fields['title'];
		}
		if (array_key_exists('body', $fields)) {
			$set['body'] = (string) $fields['body'];
		}
		if (array_key_exists('tags', $fields)) {
			$tags = $fields['tags'];
			$set['tags'] = is_array($tags)
				? array_values(array_map('strval', $tags))
				: [];
		}
		if (array_key_exists('meta', $fields)) {
			$meta = $fields['meta'];
			$set['meta'] = is_array($meta) ? $meta : [];
		}

		return $this->update(
			$collection,
			['doc_id' => $docId],
			['$set' => $set],
			['upsert' => true]
		);
	}

	/**
	 * 按 doc_id 读取文档
	 *
	 * @param string $docId 业务 ID
	 * @param string $collection 集合
	 * @return array{code:int,message:string,data?:array<string,mixed>|null}
	 */
	public function getDoc(string $docId, string $collection = self::DEFAULT_DOC_COLLECTION): array
	{
		$docId = trim($docId);
		if ($docId === '') {
			return ['code' => 1, 'message' => 'doc_id 不能为空'];
		}
		return $this->findOne($collection, ['doc_id' => $docId]);
	}

	/**
	 * 按标签检索文档
	 *
	 * @param string $tag 标签
	 * @param int $limit 条数
	 * @param string $collection 集合
	 * @return array{code:int,message:string,data?:array<int,array<string,mixed>>}
	 */
	public function searchByTag(string $tag, int $limit = 20, string $collection = self::DEFAULT_DOC_COLLECTION): array
	{
		$tag = trim($tag);
		if ($tag === '') {
			return ['code' => 1, 'message' => 'tag 不能为空'];
		}
		$limit = max(1, min(200, $limit));
		return $this->find(
			$collection,
			['tags' => $tag],
			['limit' => $limit, 'sort' => ['updated_at' => -1]]
		);
	}

	/**
	 * 按 doc_id 删除文档
	 *
	 * @param string $docId 业务 ID
	 * @param string $collection 集合
	 * @return array{code:int,message:string,data?:array{deleted:int}}
	 */
	public function deleteDoc(string $docId, string $collection = self::DEFAULT_DOC_COLLECTION): array
	{
		$docId = trim($docId);
		if ($docId === '') {
			return ['code' => 1, 'message' => 'doc_id 不能为空'];
		}
		return $this->delete($collection, ['doc_id' => $docId], false);
	}

	/**
	 * executeBulkWrite 选项（mongodb 2.x 须为数组）
	 *
	 * @return array{writeConcern:WriteConcern}
	 */
	protected function writeOptions(): array
	{
		return ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY)];
	}

	/**
	 * 扩展与连接就绪检查
	 *
	 * @return array{code:int,message:string}|null
	 */
	protected function requireReady(): ?array
	{
		if (!$this->hasExtension()) {
			return [
				'code' => 1,
				'message' => '未安装 PECL mongodb 扩展（php84-pecl install mongodb）',
			];
		}
		if ($this->manager() === null) {
			return ['code' => 1, 'message' => '无法创建 MongoDB Manager'];
		}
		return null;
	}

	/**
	 * 校验集合名
	 *
	 * @param string $collection 集合名
	 * @return string
	 */
	protected function assertCollection(string $collection): string
	{
		$collection = trim($collection);
		if ($collection === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $collection)) {
			throw new \InvalidArgumentException('非法集合名');
		}
		return $collection;
	}

	/**
	 * 是否为更新算子文档
	 *
	 * @param array<string,mixed> $doc 文档
	 * @return bool
	 */
	protected function isOperatorDoc(array $doc): bool
	{
		foreach (array_keys($doc) as $key) {
			if (is_string($key) && str_starts_with($key, '$')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * BSON 文档转 PHP 数组（递归）
	 *
	 * @param mixed $value BSON / 标量
	 * @return mixed
	 */
	protected function bsonToArray(mixed $value): mixed
	{
		if ($value instanceof \MongoDB\BSON\ObjectId) {
			return (string) $value;
		}
		if ($value instanceof \MongoDB\BSON\UTCDateTime) {
			return $value->toDateTime()->format('Y-m-d H:i:s');
		}
		if ($value instanceof \MongoDB\BSON\Binary) {
			return base64_encode($value->getData());
		}
		if ($value instanceof \stdClass) {
			$value = (array) $value;
		}
		if (is_array($value)) {
			$out = [];
			foreach ($value as $k => $v) {
				$out[$k] = $this->bsonToArray($v);
			}
			return $out;
		}
		return $value;
	}

	/**
	 * 写入返回的 _id 转字符串
	 *
	 * @param mixed $id 插入 ID
	 * @return string
	 */
	protected function idToString(mixed $id): string
	{
		if ($id instanceof \MongoDB\BSON\ObjectId) {
			return (string) $id;
		}
		if (is_scalar($id)) {
			return (string) $id;
		}
		return '';
	}
}

