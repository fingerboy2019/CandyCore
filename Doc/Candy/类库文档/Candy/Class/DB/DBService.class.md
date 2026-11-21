# DBService 类

## 概述

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

## 主要方法


### /**
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
        $this->tablePrefix = G('TABPREFIX');
        $this->backupFile = $file;
    }

    /**
     * 打开一个卷，用于写入数据
	 *
     * @param $size 写入数据的大小
     */
    private function open(int $size = 0):()


### /**
     * 写入初始数据
	 *
     * @return boolean true - 写入成功，false - 写入失败
     */
    private function create():()


### /**
     * 写入SQL语句
	 *
     * @param  $sql 要写入的SQL语句
     * @return boolean     true - 写入成功，false - 写入失败！
     */
    private function write(string $sql):()


### /**
     * 获取数据库所有表名
	 *
     * @param  $sql 要写入的SQL语句
     * @return boolean     true - 写入成功，false - 写入失败！
     */
    private function getTableList():()


### /**
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
    public function backup(string $table = '', int $start = 0):()


### /**
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
    public function import(string $file = '', int $start = 0):()


### /**
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
    public function optimize(array $tables = []):()


### /**
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
    public function repair(array $tables = []):()


### /**
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
    public function structure(string $table = ''):()


### /**
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
    public function truncate(string $table = ''):()

