<?php
/**
 * 数据库连接配置管理 Trait
 *
 * 连接池配置映射；setLink 实现见 DBServer::setLink。
 *
 * @package Candy\Extend\DB\Trait
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-17 10:30:00
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Trait;

trait Config {
	/**
	 * 连接池配置映射（基于CRC32键值）
	 * @var array
	 */
	protected static $configPool = [];
	
	/**
	 * 连接池键名（用于PDO驱动识别不同的连接配置）
	 * @var string
	 */
	protected $dbPoolKey = '';

	/**
	 * 当前数据库连接配置（独立存储，不污染全局配置）
	 * @var array
	 */
	protected $currentConfig = [];

	/**
	 * 获取当前实例的数据库连接配置
	 *
	 * @return array 连接配置数组
	 */
	public function getCurrentConfig(): array
	{
		return $this->currentConfig;
	}

	/**
	 * 获取连接池配置映射
	 * 
	 * @return array 配置池数组
	 */
	public static function getConfigPool(): array
	{
		return self::$configPool;
	}

	/**
	 * 清空连接池配置（用于测试或重置）
	 * 
	 * @return void
	 */
	public static function clearConfigPool(): void
	{
		self::$configPool = [];
	}
}
