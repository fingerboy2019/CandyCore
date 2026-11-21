<?php
/***
 * 数据库驱动契约接口
 *
 * 与 DBServer 抽象方法对齐，供驱动解析与测试断言使用。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 10:25:00 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface DriverInterface
{
	/**
	 * 执行 SQL
	 *
	 * @param string $sql SQL
	 * @param string $method select|find|total|insert|update|delete|other
	 * @param array $data 绑定参数
	 * @return mixed
	 */
	public function query(string $sql, string $method = 'other', array $data = []): mixed;

	/**
	 * 探测并缓存表结构
	 *
	 * @param string $tabName 无前缀表名
	 * @return void
	 */
	public function setTable(string $tabName): void;

	/**
	 * Begin Transaction
	 * @return ?bool;
	 */
	public function beginTransaction(): ?bool;

	/**
	 * Commit
	 * @return ?bool;
	 */
	public function commit(): ?bool;

	/**
	 * Roll Back
	 * @return ?bool;
	 */
	public function rollBack(): ?bool;

	/**
	 * Db Size
	 * @return string;
	 */
	public function dbSize(): string;

	/**
	 * Db Version
	 * @return string;
	 */
	public function dbVersion(): string;
}

