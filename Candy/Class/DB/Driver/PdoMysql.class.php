<?php
/**
 * MySQL / MariaDB PDO 驱动
 *
 * 继承抽象基类 Pdo，实现 mysql DSN、DESC 表结构与 SHOW TABLE STATUS 库体积。
 * 配置：DRIVER=pdomysql（推荐）；历史值 pdo 仍会解析到本类。
 *
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-17 10:27:00
 */
declare(strict_types=1);
namespace Candy\Extend\DB\Driver;

use Candy\Core\Debug;
use Candy\Extend\DB\Dialect\Mysql as MysqlDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

require_once __DIR__ . '/Pdo.class.php';

class PdoMysql extends Pdo {

	protected function driverLabel(): string
	{
		return 'Mysql';
	}

	protected function getDB(): ?\PDO
	{
		self::$count++;
		Debug::addMsg('<b>第 ' . self::$count . ' 次连接数据库</b>', 2);

		try {
			$port = (int) ($this->port ?? 3306);
			$charset = (string) ($this->charset ?: 'utf8mb4');
			$dsn = 'mysql:host=' . $this->host
				. ';port=' . $port
				. ';dbname=' . $this->dbname
				. ';charset=' . $charset;

			$options = $this->basePdoOptions();
			$options[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;

			$pdo = new \PDO($dsn, $this->user, $this->pass, $options);

			if ($charset !== '') {
				$pdo->exec("SET NAMES '" . str_replace("'", "''", $charset) . "'");
			}

			return $pdo;
		} catch (\PDOException $e) {
			Debug::showMessage($e->getMessage(), $this->driverLabel());
			return null;
		}
	}

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
			$stmt = $pdo->prepare(MysqlDialect::describeTableSql($this->tabName));
			if ($stmt === false) {
				Debug::showMessage('数据库有问题请检查', $this->driverLabel());
				return;
			}
			$stmt->execute();

			$auto = 'no';
			$fields = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				[$fields, $auto] = MysqlDialect::mapDescribeRow($row, $fields, $auto);
			}
			if (!array_key_exists('pri', $fields)) {
				$fields['pri'] = array_shift($fields);
			}

			$this->saveTableSchemaCache($tabName, $fields, $auto);
			$this->fieldList = $fields;
			$this->auto = $auto;
		} catch (\PDOException $e) {
			Debug::showMessage($e->getMessage(), $this->driverLabel());
		}
	}

	public function dbSize(): string
	{
		$sql = MysqlDialect::databaseSizeSql(
			(string) ($this->dbname ?: G('DBNAME')),
			(string) $this->tablePrefix
		);
		$pdo = $this->connect();
		$stmt = $pdo->prepare($sql);
		$stmt->execute();
		$size = 0;
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$size += (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0);
		}
		return sizeCount($size);
	}
}
