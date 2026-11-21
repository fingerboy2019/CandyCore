<?php
/**
 * Candy框架 数据库备份与还原服务类
 * 
 * 提供完整的数据库管理功能，包括：
 * - 数据库表结构和数据的备份
 * - 支持分卷备份（可配置卷大小）
 * - 支持数据压缩（可选GZIP压缩）
 * - 数据库表还原功能
 * - 表优化、修复、清空和删除操作
 * - 表结构查看功能
 * 
 * 核心特点：
 * - 支持分批次处理大数据量，避免内存溢出
 * - 支持多表批量操作
 * - 提供详细的备份元信息
 * - 安全的SQL注入防护
 * - 资源自动管理（自动关闭文件流）
 * 
 * 使用场景：
 * - 网站后台的数据库备份功能
 * - 定期自动备份系统
 * - 数据库维护工具
 * - 数据迁移和恢复
 * 
 * @package Candy\Extend\DB
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-05 12:00:00
 * @final 最终类，不允许继承
 */
 
declare(strict_types=1);
namespace Candy\Extend\DB;
use Candy\Extend\DB\Dialect\Mysql as MysqlDialect;
use Candy\Extend\DB\Dialect\Pgsql as PgsqlDialect;
use Candy\Extend\DB\Dialect\Sqlite as SqliteDialect;
use Candy\Extend\DB\Dialect\Sqlsrv as SqlsrvDialect;
use \Candy\Core\Debug;

final class DBService {
    /**
     * 备份文件信息 part - 卷号，name - 文件名（通过构造函数参数提升）
     * @var array
     */
    private array $backupFile = [];

    /**
     * 当前打开文件大小
     * @var integer
     */
    private int $size = 0;

    /**
     * 备份配置
     * @var array
     */
    private readonly array $config;

	/**
     * 文件指针（fopen资源或GzFile对象）
     * @var resource|\GzFile|null
     */
    private $fp = null;

	/**
     * 数据库对象
     * @var DBServer
     */
    private DBServer $db;

	/**
     * 表前缀
     * @var string
     */
    private string $tablePrefix = '';

	/**
     * 数据库表列表
     * @var array
     */
    private array $tableList = [];

	/**
     * 执行顺序
     * @var int
     */
    private int $order = 0;

	/**
     * 初始化
     * @var int
     */
    private int $init = 0;

    /**
     * 数据库备份构造方法
	 *
     * @param $file   备份或还原的文件信息
     * @param $config 备份配置信息
     * @param $type   执行类型，export - 备份数据， import - 还原数据
	 *	$config = array(
	 *		'path'     => './', //备份文件目录
	 *		'part'     => 3145728, //3MB
	 *		'compress' => 0,    //是否压缩
	 *		'level'    => 4,	//压缩水平
     *    );
	 * N('DB\\DBService',[['name'=>'test','part'=>0], $config])
     */
    public function __construct(
        private array $file = [],
        array $config = []
    ) {
        $this->config = $config;
        $this->db = D();
        // 表前缀以配置 C() 为准；G() 进程缓存未必已同步 TABPREFIX
        $this->tablePrefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
        $this->backupFile = $file;
    }

    /**
     * 打开一个卷，用于写入数据
	 *
     * @param $size 写入数据的大小
     */
    private function open(int $size = 0): void
	{
        if($this->fp){
            $this->size += $size;
            if($this->size > $this->config['part']){
                $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
                $this->fp = null;
                $this->backupFile['part']++;
                $this->create();
            }
        } else {
            $backuppath = $this->config['path'];
            $filename   = "{$backuppath}{$this->backupFile['name']}-{$this->backupFile['part']}.sql";
            if($this->config['compress']){
                $filename = "{$filename}.gz";
                $this->fp = @gzopen($filename, "a{$this->config['level']}");
            } else {
                $this->fp = @fopen($filename, 'a');
            }
            if(!$this->fp){
                Debug::showMessage('备份文件打开失败，请检查目录权限：' . $filename, 'SQL');
                return;
            }
            $this->size = (is_file($filename) ? (int)filesize($filename) : 0) + $size;
        }
    }

    /**
     * 写入初始数据
	 *
     * @return boolean true - 写入成功，false - 写入失败
     */
    private function create(): bool
	{
        $engine = $this->resolveEngine();
        $sql  = "-- -----------------------------\n";
        $sql .= "-- Candy Data Transfer ({$engine})\n";
        $sql .= "-- \n";
        $sql .= "-- Host     : " . (C('HOST') ?? G('HOST') ?? '') . "\n";
        $sql .= "-- Database : " . $this->getDbName() . "\n";
        $sql .= "-- \n";
        $sql .= "-- Part : #{$this->backupFile['part']}\n";
        $sql .= "-- Date : " . date("Y-m-d H:i:s") . "\n";
        $sql .= "-- -----------------------------\n\n";
        if ($engine === 'mysql') {
            $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        }
        return $this->write($sql);
    }

    /**
     * 写入SQL语句
	 *
     * @param  $sql 要写入的SQL语句
     * @return boolean     true - 写入成功，false - 写入失败！
     */
    private function write(string $sql): bool
	{
        $size = strlen($sql);
        
        //由于压缩原因，无法计算出压缩后的长度，这里假设压缩率为50%，
        //一般情况压缩率都会高于50%；
        $size = $this->config['compress'] ? (int)ceil($size / 2) : $size;
        
        $this->open($size);
        if(!$this->fp){
            return false;
        }
        return $this->config['compress'] ? @gzwrite($this->fp, $sql) !== false : @fwrite($this->fp, $sql) !== false;
    }

    /**
     * 获取数据库所有表名
	 *
     * @param  $sql 要写入的SQL语句
     * @return boolean     true - 写入成功，false - 写入失败！
     */
    private function getTableList(): void
	{
		if(count($this->tableList) == 0){
			$this->tableList = SchemaMeta::listTableNames();
			$this->order = 0;
		}
    }

    /**
     * 备份数据库表结构和数据
     *
     * 支持备份单个表或所有表，自动处理大数据量，分批次进行备份。
     * 备份内容包括表结构定义和数据记录，支持分卷存储。
     *
     * @param string $table 要备份的表名，留空则备份所有表
     * @param int $start 数据备份的起始行号，用于分批处理大数据表
     * @return boolean true - 备份成功，false - 备份失败
     * @example
     * ```php
     * // 备份所有表
     * $service = new DBService(['name' => 'backup_2024'], ['path' => './backup/', 'part' => 5242880]);
     * $result = $service->backup();
     *
     * // 备份指定表
     * $service->backup('users');
     *
     * // 分批备份大数据表
     * $service->backup('large_table', 10000);
     * ```
     */
    public function backup(string $table = '', int $start = 0): bool
	{
		//初始化
		if($this->init == 0){
			$this->init = 1;
			if(false === $this->create()){
				Debug::showMessage('文件写入失败，请检查权限！', 'SQL');
				return false;
            }
		}

		//使用循环替代递归，避免栈溢出
		$currentTable = $table;
		$currentStart = $start;

		while(true){
			//获取处理的表名
			if(empty($currentTable)){
				//整理库表
				$this->getTableList();
				$currentTable = $this->tableList[$this->order] ?: '';
			}else{
				$currentTable = (stripos($currentTable, $this->tablePrefix) === false ? $this->tablePrefix : '') . $currentTable;
			}

			//没有表表示完成
			if(empty($currentTable)){
				return count($this->tableList) > 0;
			}

			//备份表结构
			if(0 == $currentStart){
				$createSql = $this->fetchCreateTableSql($currentTable);
				if($createSql === ''){
					Debug::showMessage('无法读取表结构：' . $currentTable, 'SQL');
					return false;
				}
				$qi = $this->quoteIdent($currentTable);
				$sql  = "\n";
				$sql .= "-- -----------------------------\n";
				$sql .= "-- Table structure for {$qi}\n";
				$sql .= "-- -----------------------------\n";
				$sql .= 'DROP TABLE IF EXISTS ' . $qi . ";\n";
				// SchemaMeta 近似 DDL 可能含多条语句（如 PG 的 COMMENT ON），保留中间分号
				$createSql = rtrim(trim($createSql));
				if ($createSql !== '' && !str_ends_with($createSql, ';')) {
					$createSql .= ';';
				}
				$sql .= $createSql . "\n\n";
				if(false === $this->write($sql)){
					return false;
				}
			}

			//数据总数
			$qi = $this->quoteIdent($currentTable);
			$count = $this->db->query('SELECT COUNT(*) AS count FROM ' . $qi, 'total');

			//备份表数据
			if($count){
				//写入数据注释
				if(0 == $currentStart){
					$sql  = "-- -----------------------------\n";
					$sql .= "-- Records of {$qi}\n";
					$sql .= "-- -----------------------------\n";
					if(false === $this->write($sql)){
						return false;
					}
				}

				//备份数据记录（按方言 LIMIT）
				$limitFragment = $this->limitSql(1000, $currentStart);
				$result = $this->db->query('SELECT * FROM ' . $qi . $limitFragment, 'select');
				if (!is_array($result)) {
					$result = [];
				}
				foreach ($result as $row) {
					$sql = 'INSERT INTO ' . $qi . ' VALUES (';
					foreach($row as $v){
						$sql .= $this->escapeBackupValue($v) . ',';
					}
					$sql = trim($sql, ',').");\n";
					if(false === $this->write($sql)){
						return false;
					}
				}

				//还有更多数据，继续处理当前表的下一批
				if($count > $currentStart + 1000){
					$currentStart += 1000;
					continue;
				}
			}

			//备份下一表
			if(($this->order + 1) < count($this->tableList) && $currentStart == 0){
				$this->order++;
				$currentTable = '';
				$currentStart = 0;
				continue;
			}else{
				//所有表备份完成
				if($currentStart == 0 && !empty($currentTable)){
					return true;
				}
				return true;
			}
		}

		return false;
    }

    /**
     * 从备份文件还原数据库
     * 
     * 支持从单个或多个备份文件中还原数据，可指定起始位置继续还原。
     * 自动处理压缩的备份文件，按SQL语句分号分隔执行。
     * 
     * @param string $file 指定的备份文件路径，留空则根据file数组查找所有匹配的备份文件
     * @param int $start 还原的起始位置（字节数），用于中断后继续还原
     * @return boolean true - 还原成功，false - 还原失败
     * @example
     * ```php
     * // 还原所有备份文件
     * $service = new DBService(['name' => 'backup_2024'], ['path' => './backup/']);
     * $result = $service->import();
     * 
     * // 还原指定备份文件
     * $service->import('./backup/backup_2024-1.sql');
     * 
     * // 从指定位置继续还原
     * $service->import('./backup/backup_2024-1.sql', 102400);
     * ```
     */
    public function import(string $file = '', int $start = 0): bool
	{
		if(empty($file)){
			$path  = $this->config['path'] . $this->backupFile['name'] . '-*.sql*';
			$files = glob($path);
			sort($files); // 按文件名排序，确保顺序正确
		}else{
			$files = [$file];
		}

		if(count($files) > 0){
			foreach($files as $file){
				if($this->config['compress']){
					$gz   = gzopen($file, 'r');
					$size = 0;
				} else {
					$size = filesize($file);
					$gz   = fopen($file, 'r');
				}

				if(!$gz){
					Debug::showMessage('无法打开备份文件: ' . $file, 'SQL');
					return false;
				}

				$sql  = '';
				if($start){
					$this->config['compress'] ? gzseek($gz, $start) : fseek($gz, $start);
				}

				//开始导入
				while($line = $this->config['compress'] ? gzgets($gz) : fgets($gz)){
					$sql .= $line;
					if(preg_match('/.*;$/', trim($sql))){
						if(false !== $this->db->query($sql, 'other')){
							$start += strlen($sql);
							$sql = ''; // 清空已执行的SQL
						} else {
							Debug::showMessage('SQL命令执行失败，请检查权限！', 'SQL');
							$this->config['compress'] ? gzclose($gz) : fclose($gz);
							return false;
						}
					}
				}

				$this->config['compress'] ? gzclose($gz) : fclose($gz);
				$start = 0; // 重置start，后续文件从0开始
			}
		}

		return true;
    }
	
    /**
     * 优化数据库表
     * 
     * 执行OPTIMIZE TABLE命令优化指定的表或所有表，
     * 用于回收已删除记录占用的空间，提高查询性能。
     * 
     * @param array $tables 要优化的表名数组，留空则优化所有表
     * @return void
     * @example
     * ```php
     * // 优化所有表
     * $service->optimize();
     * 
     * // 优化指定的表
     * $service->optimize(['users', 'orders', 'products']);
     * ```
     */
    public function optimize(array $tables = []): void
	{
        if(empty($tables)){
			$this->getTableList();
			$tables = implode(',', $this->tableList);
		}else{
			$tables = implode(',', $tables);
		}
		
		$this->db->query('OPTIMIZE TABLE '.$this->safeReplace($tables));
    }
	
    /**
     * 修复损坏的数据库表
     * 
     * 执行REPAIR TABLE命令修复指定的表或所有表，
     * 用于修复因崩溃、断电等原因导致的数据表损坏。
     * 
     * @param array $tables 要修复的表名数组，留空则修复所有表
     * @return void
     * @example
     * ```php
     * // 修复所有表
     * $service->repair();
     * 
     * // 修复指定的表
     * $service->repair(['users', 'orders']);
     * ```
     */
    public function repair(array $tables = []): void
	{
        if(empty($tables)){
			$this->getTableList();
			$tables = implode(',', $this->tableList);
		}else{
			$tables = implode(',', $tables);
		}
		
		$this->db->query('REPAIR TABLE '.$this->safeReplace($tables));
    }
	
    /**
     * 获取数据库表的创建语句
     * 
     * 查询指定表的完整CREATE TABLE语句，包含表结构、索引、约束等所有定义信息。
     * 用于查看表结构或在其他数据库中重建相同结构的表。
     * 
     * @param string $table 要查询的表名
     * @return string 表的CREATE TABLE语句，如果表名为空则返回空字符串
     * @example
     * ```php
     * // 获取表结构
     * $createSql = $service->structure('users');
     * echo $createSql; // 输出: CREATE TABLE `users` (id INT AUTO_INCREMENT PRIMARY KEY, ...)
     * ```
     */
    public function structure(string $table = ''): string
	{
		if(empty($table)) return '';
		return $this->fetchCreateTableSql($table);
    }
	 
    /**
     * 清空表数据
     * 
     * 执行TRUNCATE TABLE命令删除表中的所有数据，但保留表结构。
     * 比DELETE语句更高效，会重置自增计数器。
     * 
     * @param string $table 要清空的表名
     * @return void
     * @example
     * ```php
     * // 清空指定表
     * $service->truncate('temporary_data');
     * ```
     */
    public function truncate(string $table = ''): void
	{
		if(!empty($table))
			$this->db->query('TRUNCATE '.$this->safeReplace($table));
	}
	
    /**
     * 删除整个数据库表
     * 
     * 执行DROP TABLE命令删除指定的表，包括表结构和所有数据。
     * 此操作不可逆，请谨慎使用。
     * 
     * @param string $table 要删除的表名
     * @return void
     * @example
     * ```php
     * // 删除指定表
     * $service->delete('obsolete_table');
     * ```
     */
    public function delete(string $table = ''): void
	{
		if(!empty($table))
			$this->db->query('DROP TABLE '.$this->safeReplace($table));
	}
	
    /**
     * 获取当前数据库名
     *
     * 优先读取配置 C()，避免 G() 进程缓存未同步导致为空
     *
     * @return string
     * @access private
     */
    private function getDbName(): string
    {
        return (string)(C('DBNAME') ?? G('DBNAME') ?? '');
    }

    /**
     * 转义备份 INSERT 中的字段值
     *
     * NULL 输出关键字 NULL；数值与布尔按字面量写入；字符串加引号并转义
     *
     * @param mixed $value 字段原始值
     * @return string SQL 片段（不含尾逗号）
     * @access private
     */
    private function escapeBackupValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            // PostgreSQL boolean 字面量；MySQL 亦接受 1/0，TRUE/FALSE 在 MySQL 也会被当成 1/0
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_resource($value)) {
            return 'NULL';
        }
        $str = (string)$value;
        if ($this->resolveEngine() === 'pgsql') {
            return "'" . str_replace(["'", "\\"], ["''", "\\\\"], $str) . "'";
        }
        return "'" . str_replace("\r\n", '\r\n', addslashes($str)) . "'";
    }

    /**
     * 当前引擎短名
     *
     * @return string mysql|pgsql|sqlite|sqlsrv
     * @access private
     */
    private function resolveEngine(): string
    {
        return DBServer::resolveEngine();
    }

    /**
     * 按引擎引用标识符
     *
     * @param string $ident
     * @return string
     * @access private
     */
    private function quoteIdent(string $ident): string
    {
        return match ($this->resolveEngine()) {
            'pgsql' => PgsqlDialect::quoteIdent($ident),
            'sqlite' => SqliteDialect::quoteIdent($ident),
            'sqlsrv' => SqlsrvDialect::quoteIdent($ident),
            default => MysqlDialect::quoteIdent($ident),
        };
    }

    /**
     * 分页片段
     *
     * @param int $limit
     * @param int $offset
     * @return string
     * @access private
     */
    private function limitSql(int $limit, int $offset = 0): string
    {
        return match ($this->resolveEngine()) {
            'pgsql' => PgsqlDialect::limitSql($limit, $offset),
            'sqlite' => SqliteDialect::limitSql($limit, $offset),
            'sqlsrv' => SqlsrvDialect::limitSql($limit, $offset),
            default => MysqlDialect::limitSql($limit, $offset),
        };
    }

    /**
     * 读取建表 SQL（MySQL SHOW CREATE / 其它走 SchemaMeta 近似 DDL）
     *
     * @param string $table
     * @return string
     * @access private
     */
    private function fetchCreateTableSql(string $table): string
    {
        if ($this->resolveEngine() === 'mysql') {
            $result = $this->db->query('SHOW CREATE TABLE ' . MysqlDialect::quoteIdent($table), 'find');
            if (!is_array($result) || $result === []) {
                return '';
            }
            $result = array_change_key_case($result, CASE_LOWER);
            return trim((string)($result['create table'] ?? ''));
        }
        try {
            SchemaMeta::resetProvider();
            return trim(SchemaMeta::getCreateTableSql($table));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 安全替换
     * 
     * 移除字符串中的特殊字符，防止SQL注入
     * 
     * @param string $string 要处理的字符串
     * @return string 处理后的安全字符串
     */
    private function safeReplace(string $string): string
	{
		return str_replace(['`',"\\",'&',' ',"'",'"','/','*','<','>',"\r","\t","\n","#"], '', $string);
	}

    /**
     * 关闭备份文件句柄
     *
     * GZIP 文件在句柄未关闭时 filesize 可能为 0，校验落盘前须先关闭
     *
     * @return void
     */
    public function close(): void
	{
        if(!is_null($this->fp)){
            $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
            $this->fp = null;
        }
    }

    /**
     * 析构方法，用于关闭文件资源
     */
    public function __destruct()
	{
        $this->close();
    }
}

