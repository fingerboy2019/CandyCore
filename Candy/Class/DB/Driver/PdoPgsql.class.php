<?php
/**
 * PostgreSQL PDO 驱动
 *
 * 继承抽象 Pdo，使用 Dialect\Pgsql 探测表结构。
 * 配置：DRIVER=pdopgsql；历史 pgsql 仍解析到本类。
 *
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-17 10:26:00
 */
declare(strict_types=1);
namespace Candy\Extend\DB\Driver;

use Candy\Core\Debug;
use Candy\Extend\DB\Dialect\Pgsql as PgsqlDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

require_once __DIR__ . '/Pdo.class.php';

class PdoPgsql extends Pdo {

	protected function driverLabel(): string
	{
		return 'PostgreSQL';
	}

	protected function getDB(): ?\PDO
	{
		self::$count++;
		Debug::addMsg('<b>第 ' . self::$count . ' 次连接数据库</b>', 2);
		try {
			$dsn = 'pgsql:host=' . $this->host
				. ';port=' . (int) ($this->port ?? 5432)
				. ';dbname=' . $this->dbname . ';sslmode=disable';
			$pdo = new \PDO($dsn, $this->user, $this->pass, $this->basePdoOptions());
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
			$stmt = $pdo->prepare(PgsqlDialect::columnsMetaSql());
			$stmt->execute([$this->tabName]);
			$auto = 'no';
			$fields = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				[$fields, $auto] = PgsqlDialect::mapColumnRow($row, $fields, $auto);
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

	public function dbSize(): string
	{
		$pdo = $this->connect();
		$stmt = $pdo->query(PgsqlDialect::databaseSizeSql((string) $this->dbname, (string) $this->tablePrefix));
		$size = 0;
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$size += (int) ($row['sz'] ?? 0);
		}
		return sizeCount($size);
	}
}
