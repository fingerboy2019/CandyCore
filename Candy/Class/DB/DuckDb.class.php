<?php
/***
 * DuckDB 本地分析客户端
 *
 * 业务说明：通过 duckdb CLI 执行只读 SQL（JSON 输出），不进入 DBServer ORM
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 15:08:27 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class DuckDb
{
	/** @var string 数据库文件路径（空或 :memory: 表示内存库） */
	protected string $path = ':memory:';

	/** @var string duckdb 可执行文件 */
	protected string $bin = 'duckdb';

	/** @var int 命令超时秒数 */
	protected int $timeout = 30;

	/**
	 * @param array{PATH?:string,BIN?:string,TIMEOUT?:int}|string|null $config 路径或配置
	 */
	public function __construct(array|string|null $config = null)
	{
		if (is_string($config) && $config !== '') {
			$this->path = $config;
			return;
		}
		if (!is_array($config)) {
			$config = $this->loadAliasConfig('duckdb');
		}
		if (is_array($config)) {
			if (!empty($config['PATH'])) {
				$this->path = (string) $config['PATH'];
			} elseif (!empty($config['DBNAME'])) {
				$this->path = (string) $config['DBNAME'];
			}
			if (!empty($config['BIN'])) {
				$this->bin = (string) $config['BIN'];
			}
			if (isset($config['TIMEOUT'])) {
				$this->timeout = max(1, (int) $config['TIMEOUT']);
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
	 * 多行查询
	 *
	 * @param string $sql SQL
	 * @return array<int, array<string,mixed>>
	 */
	public function select(string $sql): array
	{
		$rows = $this->runJson($sql);
		return is_array($rows) ? $rows : [];
	}

	/**
	 * 单行查询
	 *
	 * @param string $sql SQL
	 * @return array<string,mixed>|null
	 */
	public function find(string $sql): ?array
	{
		$rows = $this->select($sql);
		return $rows[0] ?? null;
	}

	/**
	 * 标量计数类查询
	 *
	 * @param string $sql SQL
	 * @return int
	 */
	public function total(string $sql): int
	{
		$row = $this->find($sql);
		if ($row === null) {
			return 0;
		}
		return (int) (array_values($row)[0] ?? 0);
	}

	/**
	 * 版本探测
	 *
	 * @return string
	 */
	public function version(): string
	{
		$row = $this->find('SELECT version() AS v');
		return (string) ($row['v'] ?? 'DuckDB');
	}

	/**
	 * CLI 是否可用
	 *
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		$bin = escapeshellcmd($this->bin);
		$out = [];
		$code = 0;
		@exec($bin . ' --version 2>&1', $out, $code);
		return $code === 0;
	}

	/**
	 * 执行 SQL 并以 JSON 解析结果行
	 *
	 * @param string $sql SQL
	 * @return array<int, array<string,mixed>>
	 */
	protected function runJson(string $sql): array
	{
		$start = microtime(true);
		$bin = escapeshellcmd($this->bin);
		$dbArg = ($this->path === '' || $this->path === ':memory:')
			? ''
			: ' ' . escapeshellarg($this->path);
		// -json：每行一个 JSON 对象；-c 执行单条语句
		$cmd = $bin . $dbArg . ' -json -c ' . escapeshellarg($sql) . ' 2>&1';

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
		if (!is_resource($proc)) {
			throw new \RuntimeException('无法启动 duckdb CLI：' . $this->bin);
		}
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);

		$ys = round((microtime(true) - $start) * 1000, 4);
		DbMetrics::recordQuery($sql, $ys, 'duckdb', 'read');

		$raw = trim((string) $stdout);
		if ($code !== 0) {
			$err = trim($raw !== '' ? $raw : (string) $stderr);
			throw new \RuntimeException('DuckDB 执行失败: ' . ($err !== '' ? $err : 'exit ' . $code));
		}

		if ($raw === '') {
			return [];
		}

		// 可能是 JSON 数组，或 NDJSON 行
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			if ($decoded === []) {
				return [];
			}
			if (array_is_list($decoded)) {
				return array_values(array_filter($decoded, 'is_array'));
			}
			return [$decoded];
		}

		$rows = [];
		foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$obj = json_decode($line, true);
			if (is_array($obj)) {
				$rows[] = $obj;
			}
		}
		return $rows;
	}
}

