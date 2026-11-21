<?php
/**
 * SQL Server PDO 驱动（pdo_sqlsrv）
 *
 * 继承抽象 Pdo，使用 Dialect\Sqlsrv；需安装 Microsoft ODBC + pdo_sqlsrv（PHP 8.3+）。
 * 配置：DRIVER=pdosqlsrv 或 sqlsrv。
 *
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-17 15:08:27
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Driver;

use Candy\Core\Debug;
use Candy\Extend\DB\Dialect\Sqlsrv as SqlsrvDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

require_once __DIR__ . '/Pdo.class.php';

class PdoSqlsrv extends Pdo
{
	/**
	 * @return string
	 */
	protected function driverLabel(): string
	{
		return 'SQL Server';
	}

	/**
	 * SQL Server 使用方括号标识符
	 *
	 * @return array{0:string,1:string}
	 */
	protected function sqlIdentQuotes(): array
	{
		return ['[', ']'];
	}

	/**
	 * OFFSET/FETCH 分页
	 *
	 * @param int $limit 条数
	 * @param int $offset 偏移
	 * @return string
	 */
	protected function formatLimitSql(int $limit, int $offset = 0): string
	{
		return SqlsrvDialect::limitSql($limit, $offset);
	}

	/**
	 * @return \PDO|null
	 */
	protected function getDB(): ?\PDO
	{
		if (!extension_loaded('pdo_sqlsrv') && !in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
			Debug::showMessage('请开启 pdo_sqlsrv 扩展（需 Microsoft ODBC Driver 17/18）。', $this->driverLabel());
			return null;
		}

		self::$count++;
		Debug::addMsg('<b>第 ' . self::$count . ' 次连接数据库</b>', 2);

		try {
			$port = (int) ($this->port ?? 1433);
			$server = $this->host . ($port > 0 ? ',' . $port : '');
			$dsn = 'sqlsrv:Server=' . $server . ';Database=' . $this->dbname;
			$pdo = new \PDO($dsn, (string) $this->user, (string) $this->pass, $this->basePdoOptions());
			return $pdo;
		} catch (\PDOException $e) {
			Debug::showMessage($e->getMessage(), $this->driverLabel());
			return null;
		}
	}

	/**
	 * @param string $tabName 无前缀表名
	 * @return void
	 */
	public function setTable(string $tabName): void
	{
		[$hit, $fields, $auto] = $this->loadTableSchemaCache($tabName);
		if ($hit) {
			$this->fieldList = $fields;
			$this->auto = $auto;
			$this->tabName = $this->physicalTableName($tabName);
			return;
		}

		try {
			$pdo = $this->connect();
			if ($pdo === null) {
				stop();
			}
			$this->tabName = $this->physicalTableName($tabName);
			$stmt = $pdo->prepare(SqlsrvDialect::columnsMetaSql());
			$stmt->execute([$this->tabName]);
			$auto = 'no';
			$fields = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				[$fields, $auto] = SqlsrvDialect::mapColumnRow($row, $fields, $auto);
			}

			$idStmt = $pdo->prepare(SqlsrvDialect::identityCheckSql($this->tabName));
			$idStmt->execute([$this->tabName]);
			if ($idStmt->fetch(\PDO::FETCH_ASSOC)) {
				$auto = 'yes';
			}

			if (!array_key_exists('pri', $fields) && $fields !== []) {
				$fields['pri'] = array_shift($fields);
			}
			$this->saveTableSchemaCache($tabName, $fields, $auto);
			$this->fieldList = $fields;
			$this->auto = $auto;
		} catch (\PDOException $e) {
			Debug::showMessage($e->getMessage(), $this->driverLabel());
		}
	}

	/**
	 * @return string
	 */
	public function dbSize(): string
	{
		$pdo = $this->connect();
		$stmt = $pdo->query(SqlsrvDialect::databaseSizeSql((string) $this->tablePrefix));
		$row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
		return sizeCount((int) ($row['sz'] ?? 0));
	}
}
