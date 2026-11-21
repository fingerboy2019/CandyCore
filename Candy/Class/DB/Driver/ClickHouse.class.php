<?php
/***
 * ClickHouse HTTP 分析驱动
 *
 * 业务说明：分析型查询与结构化单行写入，不走关系 ORM；配置 HOST/PORT/DBNAME/USER/PASS，别名可设 DRIVER=clickhouse
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 22:10:00 $
 *
 * @package Candy\Extend\DB\Driver
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Driver;

use Candy\Extend\DB\DBServer;
use Candy\Extend\DB\DbMetrics;
use Candy\Core\Debug;
use Candy\Extend\Network\Http;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class ClickHouse extends DBServer
{
	/** @var string HTTP 基址 */
	protected string $httpBase = '';

	/**
	 * 应用连接配置并构建 HTTP 基址
	 *
	 * @param mixed $config 隔离别名或配置数组
	 * @param string|null $role 连接角色
	 * @return void
	 */
	public function setLink(mixed $config, ?string $role = null): void
	{
		parent::setLink($config, $role);
		$port = (int) ($this->port ?? 8123);
		$this->httpBase = 'http://' . $this->host . ':' . $port;
	}

	/**
	 * HTTP 查询 ClickHouse
	 *
	 * @param string $sql SQL
	 * @param string $method 查询类型
	 * @param array $data 未使用（请自行参数化 SQL）
	 * @return mixed
	 */
	public function query(string $sql, string $method = 'other', array $data = []): mixed
	{
		if ($data !== []) {
			// 本驱动走 HTTP 文本协议，不支持占位符绑定；静默丢弃会让调用方误以为参数生效
			throw new \RuntimeException(
				'ClickHouse 驱动不支持参数绑定，请用 literal() 预拼 SQL 后调用'
			);
		}
		$startTime = microtime(true);

		$url = $this->httpBase . '/?database=' . rawurlencode((string) $this->dbname)
			. '&default_format=JSON';
		$headers = [
			'Content-Type: text/plain',
		];
		if ($this->user !== '') {
			$headers[] = 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->pass);
		}
		$resp = Http::post($url, $sql, ['headers' => $headers, 'timeout' => 30]);
		$httpCode = (int) (is_array($resp) ? ($resp['http_code'] ?? 0) : 0);
		// Network\Http 返回 content；兼容误用 body 的旧调用方
		$body = is_array($resp)
			? (string) ($resp['content'] ?? $resp['body'] ?? '')
			: (string) $resp;

		$ys = round((microtime(true) - $startTime) * 1000, 4);
		DbMetrics::recordQuery($sql, $ys, $this->getDbAlias(), $this->getDbActiveRole());

		if ($httpCode >= 400 || $body === '') {
			Debug::addMsg('ClickHouse HTTP ' . $httpCode . ': ' . mb_substr($body, 0, 200), 2);
			return match (strtolower($method)) {
				'select' => [],
				'find' => null,
				'total' => 0,
				default => false,
			};
		}

		$json = json_decode($body, true);
		if (!is_array($json)) {
			Debug::addMsg('ClickHouse 响应非 JSON: ' . mb_substr($body, 0, 200), 2);
			return match (strtolower($method)) {
				'select' => [],
				'find' => null,
				'total' => 0,
				default => $body,
			};
		}

		if (!empty($json['exception'])) {
			Debug::addMsg('ClickHouse 查询错误: ' . (string) $json['exception'], 2);
			return match (strtolower($method)) {
				'select' => [],
				'find' => null,
				'total' => 0,
				default => false,
			};
		}

		$rows = $json['data'] ?? [];

		return match (strtolower($method)) {
			'select' => is_array($rows) ? $rows : [],
			'find' => is_array($rows) && isset($rows[0]) ? $rows[0] : null,
			'total' => is_array($rows) && isset($rows[0]) ? (int) (array_values($rows[0])[0] ?? 0) : 0,
			default => $body,
		};
	}

	/**
	 * 执行 ClickHouse 多行只读查询。
	 *
	 * @param string $sql 已由分析查询构造器生成的只读 SQL
	 * @return array<int, array<string, mixed>> 查询结果
	 */
	public function fetchRows(string $sql): array
	{
		$result = $this->query($sql, 'select');

		return is_array($result) ? $result : [];
	}

	/**
	 * 执行 ClickHouse 单行只读查询。
	 *
	 * @param string $sql 已由分析查询构造器生成的只读 SQL
	 * @return array<string, mixed>|null 查询结果
	 */
	public function fetchRow(string $sql): ?array
	{
		$result = $this->query($sql, 'find');

		return is_array($result) ? $result : null;
	}

	/**
	 * 执行 ClickHouse 统计查询。
	 *
	 * @param string $sql 已由分析查询构造器生成的只读 SQL
	 * @return int 统计值
	 */
	public function fetchScalarCount(string $sql): int
	{
		return (int) $this->query($sql, 'total');
	}

	/**
	 * 通过 ClickHouse 专用协议写入一行结构化数据。
	 *
	 * 调用方只提供白名单表名、列名和值；INSERT 文本的方言编译与 HTTP
	 * 执行统一收口在驱动内，业务层不得直接调用 query() 写入分析库。
	 *
	 * @param string $table 目标表名
	 * @param array<string, mixed> $row 列名到值的映射
	 * @return bool 是否写入成功
	 */
	public function insertRow(string $table, array $row): bool
	{
		$sql = $this->buildInsertSql($table, $row);
		if ($sql === null) {
			return false;
		}

		return $this->query($sql, 'other') !== false;
	}

	/**
	 * 编译 ClickHouse 单行 INSERT 文本。
	 *
	 * 该方法只接受标识符白名单，值按 ClickHouse 文本协议转义；仅供驱动
	 * 的结构化写入入口使用，避免上层拼接 SQL。
	 *
	 * @param string $table 目标表名
	 * @param array<string, mixed> $row 列名到值的映射
	 * @return string|null SQL；参数不安全时返回 null
	 */
	protected function buildInsertSql(string $table, array $row): ?string
	{
		if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
			return null;
		}

		$columns = [];
		$values = [];
		foreach ($row as $column => $value) {
			$column = (string) $column;
			if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
				continue;
			}
			$columns[] = '`' . $column . '`';
			$values[] = $this->literal($value);
		}
		if ($columns === []) {
			return null;
		}

		return 'INSERT INTO `' . $table . '` (' . implode(', ', $columns)
			. ') VALUES (' . implode(', ', $values) . ')';
	}

	/**
	 * 将 PHP 值转换为 ClickHouse 文本协议字面量。
	 *
	 * @param mixed $value 字段值
	 * @return string 已转义字面量
	 */
	protected function literal(mixed $value): string
	{
		if ($value === null) {
			return 'NULL';
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		if (is_array($value)) {
			$json = json_encode($value, JSON_UNESCAPED_UNICODE);
			$value = $json === false ? '' : $json;
		}

		$text = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);

		return "'" . $text . "'";
	}

	/**
	 * 设置逻辑表名（分析库无 ORM 结构探测）
	 *
	 * @param string $tabName 表名
	 * @return void
	 */
	public function setTable(string $tabName): void
	{
		$this->tabName = $this->physicalTableName($tabName);
		$this->fieldList = ['pri' => 'id'];
		$this->auto = 'no';
	}

	/**
	 * @return bool|null
	 */
	public function beginTransaction(): ?bool
	{
		Debug::addMsg('ClickHouse 不支持事务', 2);
		return false;
	}

	/**
	 * @return bool|null
	 */
	public function commit(): ?bool
	{
		return false;
	}

	/**
	 * @return bool|null
	 */
	public function rollBack(): ?bool
	{
		return false;
	}

	/**
	 * 库占用体积
	 *
	 * @return string
	 */
	public function dbSize(): string
	{
		$row = $this->query('SELECT sum(bytes) AS sz FROM system.parts WHERE active', 'find');
		return sizeCount((int) ($row['sz'] ?? 0));
	}

	/**
	 * 服务器版本
	 *
	 * @return string
	 */
	public function dbVersion(): string
	{
		$row = $this->query('SELECT version() AS v', 'find');
		return (string) ($row['v'] ?? 'ClickHouse');
	}
}
