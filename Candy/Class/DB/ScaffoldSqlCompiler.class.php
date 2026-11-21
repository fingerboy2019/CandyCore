<?php
/***
 * 脚手架 SQL 方言编译器
 *
 * 业务说明：将受控 MySQL dump 转为 SQLite 中间态，并按目标方言改写固定结构片段
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 14:14:30 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use Candy\Extend\DB\Dialect\Sqlite as SqliteDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ScaffoldSqlCompiler
{
	/**
	 * 从 MySQL dump 提取 SQLite 可执行语句。
	 *
	 * 仅解析脚手架模板约定的 DROP/CREATE TABLE 与 INSERT 结构；不接受任意
	 * DDL 作为输入，解析失败会抛出异常交给安装流程处理。
	 *
	 * @param string $raw MySQL dump 原文
	 * @return array<int, string> SQLite 语句
	 */
	public static function extractSqliteStatements(string $raw): array
	{
		$inserts = [];
		if (preg_match_all('/INSERT INTO `[^`]+` VALUES[^;]+;/s', $raw, $insertMatches)) {
			$inserts = $insertMatches[0];
		}

		$raw = preg_replace('/\/\*!.*?\*\/;?/s', '', $raw) ?? $raw;
		$raw = preg_replace('/^--.*$/m', '', $raw) ?? $raw;
		$raw = preg_replace('/LOCK TABLES `[^`]+` WRITE;\s*/', '', $raw) ?? $raw;
		$raw = preg_replace('/UNLOCK TABLES;\s*/', '', $raw) ?? $raw;
		$raw = preg_replace('/ALTER TABLE `[^`]+` DISABLE KEYS;\s*/', '', $raw) ?? $raw;
		$raw = preg_replace('/ALTER TABLE `[^`]+` ENABLE KEYS;\s*/', '', $raw) ?? $raw;

		$statements = [];
		$indexes = [];
		if (!preg_match_all(
			'/DROP TABLE IF EXISTS `([^`]+)`;\s*CREATE TABLE `\\1` \((.*?)\)\s*ENGINE=[^;]+;/s',
			$raw,
			$matches,
			PREG_SET_ORDER
		)) {
			throw new \RuntimeException('未解析到 CREATE TABLE 语句');
		}

		foreach ($matches as $match) {
			$table = (string) $match[1];
			$statements[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
			[$create, $tableIndexes] = self::convertCreateTable($table, (string) $match[2]);
			$statements[] = $create;
			$indexes = array_merge($indexes, $tableIndexes);
		}

		foreach ($inserts as $insert) {
			$statements[] = trim($insert);
		}

		return array_merge($statements, $indexes);
	}

	/**
	 * 将 SQLite 中间态语句改写为 PostgreSQL 方言。
	 *
	 * @param string $sql SQLite 语句
	 * @return string PostgreSQL 语句
	 */
	public static function toPgsql(string $sql): string
	{
		$sql = preg_replace('/`([^`]+)`/', '"$1"', $sql) ?? $sql;
		$sql = str_ireplace('INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL', 'SERIAL PRIMARY KEY', $sql);
		$sql = str_ireplace("DEFAULT (datetime('now'))", 'DEFAULT CURRENT_TIMESTAMP', $sql);
		$sql = preg_replace('/\bAUTOINCREMENT\b/i', '', $sql) ?? $sql;

		return $sql;
	}

	/**
	 * 将 SQLite 中间态语句改写为 SQL Server 方言。
	 *
	 * @param string $sql SQLite 语句
	 * @return string SQL Server 语句；不支持的空语句返回空串
	 */
	public static function toSqlsrv(string $sql): string
	{
		$sql = preg_replace('/`([^`]+)`/', '[$1]', $sql) ?? $sql;
		$sql = preg_replace(
			'/DROP\s+TABLE\s+IF\s+EXISTS\s+(\[[^\]]+\]|"[^"]+"|\w+)\s*;?/i',
			'IF OBJECT_ID(N\'$1\', N\'U\') IS NOT NULL DROP TABLE $1;',
			$sql
		) ?? $sql;
		$sql = str_ireplace(
			'INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL',
			'INT IDENTITY(1,1) PRIMARY KEY NOT NULL',
			$sql
		);
		$sql = str_ireplace("DEFAULT (datetime('now'))", 'DEFAULT GETDATE()', $sql);
		$sql = preg_replace('/\bAUTOINCREMENT\b/i', '', $sql) ?? $sql;
		$sql = preg_replace('/\bTEXT\b/i', 'NVARCHAR(MAX)', $sql) ?? $sql;
		$sql = preg_replace('/\bREAL\b/i', 'FLOAT', $sql) ?? $sql;
		$sql = preg_replace('/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS/i', 'CREATE INDEX', $sql) ?? $sql;

		return trim($sql);
	}

	/**
	 * 将单条 MySQL CREATE TABLE 转为 SQLite。
	 *
	 * @param string $table 表名
	 * @param string $body CREATE TABLE 括号内原文
	 * @return array{0:string,1:array<int,string>} 建表语句与索引语句
	 */
	private static function convertCreateTable(string $table, string $body): array
	{
		$lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
		$columns = [];
		$constraints = [];
		$indexes = [];
		$primaryColumn = null;

		foreach ($lines as $line) {
			$line = rtrim(trim($line), ',');
			if ($line === '') {
				continue;
			}

			if (preg_match('/^PRIMARY KEY \(`([^`]+)`\)$/i', $line, $match)) {
				$primaryColumn = self::normalizeColumnIdent($match[1]);
				continue;
			}
			if (preg_match('/^UNIQUE KEY `[^`]+` \((.+)\)$/i', $line, $match)) {
				$constraints[] = 'UNIQUE (' . self::normalizeBacktickIdentifiers($match[1]) . ')';
				continue;
			}
			if (preg_match('/^UNIQUE KEY `([^`]+)`$/i', $line, $match)) {
				$constraints[] = 'UNIQUE (`' . self::normalizeColumnIdent($match[1]) . '`)';
				continue;
			}
			if (preg_match('/^KEY `([^`]+)` \((.+)\)$/i', $line, $match)) {
				$indexName = self::normalizeColumnIdent($match[1]);
				$indexes[] = 'CREATE INDEX IF NOT EXISTS `' . $table . '_' . $indexName
					. '` ON `' . $table . '` (' . self::normalizeBacktickIdentifiers($match[2]) . ');';
				continue;
			}

			if (!preg_match('/^`([^`]+)`\s+(.+)$/i', $line, $match)) {
				continue;
			}
			$column = self::normalizeColumnIdent($match[1]);
			$definition = self::convertColumnDef($match[2]);
			if (str_contains(strtoupper($match[2]), 'AUTO_INCREMENT')) {
				$primaryColumn = $column;
				$definition = 'INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL';
			}
			$columns[] = '`' . $column . '` ' . $definition;
		}

		if ($primaryColumn !== null) {
			$foundPrimary = false;
			foreach ($columns as $index => $columnLine) {
				if (!preg_match('/^`' . preg_quote($primaryColumn, '/') . '`\s+/i', $columnLine)) {
					continue;
				}
				if (!str_contains(strtoupper($columnLine), 'PRIMARY KEY')) {
					$columns[$index] = '`' . $primaryColumn . '` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL';
				}
				$foundPrimary = true;
				break;
			}
			if (!$foundPrimary) {
				array_unshift($columns, '`' . $primaryColumn . '` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL');
			}
		}

		$parts = array_merge($columns, $constraints);
		$create = 'CREATE TABLE `' . $table . '` (' . "\n  "
			. implode(",\n  ", $parts) . "\n);";

		return [$create, $indexes];
	}

	/**
	 * 转换 MySQL 列定义为 SQLite 兼容定义。
	 *
	 * @param string $definition 列定义片段
	 * @return string SQLite 列定义
	 */
	private static function convertColumnDef(string $definition): string
	{
		$definition = preg_replace("/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $definition) ?? $definition;
		$definition = preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $definition) ?? $definition;
		$definition = str_ireplace('DEFAULT CURRENT_TIMESTAMP', "DEFAULT (datetime('now'))", $definition);
		$definition = preg_replace('/\bunsigned\b/i', '', $definition) ?? $definition;
		$definition = preg_replace('/\b(big|medium|small)?int\(\d+\)/i', 'INTEGER', $definition) ?? $definition;
		$definition = preg_replace('/\btinyint\(\d+\)/i', 'INTEGER', $definition) ?? $definition;
		$definition = preg_replace('/\bvarchar\(\d+\)/i', 'TEXT', $definition) ?? $definition;
		$definition = preg_replace('/\bmediumtext\b/i', 'TEXT', $definition) ?? $definition;
		$definition = preg_replace('/\bdecimal\(\d+,\d+\)/i', 'REAL', $definition) ?? $definition;
		$definition = preg_replace('/\bdatetime\b/i', 'TEXT', $definition) ?? $definition;
		$definition = preg_replace('/\bdate\b/i', 'TEXT', $definition) ?? $definition;
		$definition = preg_replace('/\s+AUTO_INCREMENT\b/i', '', $definition) ?? $definition;
		$definition = preg_replace('/\s+/', ' ', trim($definition)) ?? $definition;

		return $definition;
	}

	/**
	 * 统一 SQLite 列名大小写。
	 *
	 * @param string $name 列名
	 * @return string 规范化列名
	 */
	private static function normalizeColumnIdent(string $name): string
	{
		return SqliteDialect::normalizeColumnName($name);
	}

	/**
	 * 将 SQL 片段内反引号标识符统一为 SQLite 列名大小写。
	 *
	 * @param string $sql 含反引号标识符的片段
	 * @return string 规范化片段
	 */
	private static function normalizeBacktickIdentifiers(string $sql): string
	{
		return preg_replace_callback(
			'/`([^`]+)`/',
			static fn (array $match): string => '`' . SqliteDialect::normalizeColumnName($match[1]) . '`',
			$sql
		) ?? $sql;
	}
}
