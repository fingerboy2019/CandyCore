<?php
declare(strict_types=1);
namespace Catlog\XhprofLib\Utils;

/**
 * XHProf运行数据管理接口
 * 定义了性能分析运行数据的获取和保存方法
 * 所有XHProf运行数据管理器都必须实现此接口
 *
 * @author System
 * @version 1.0
 * @since 2026-01-16
 */
interface XHProfRuns
{
	/**
	 * 获取指定ID的性能分析运行数据
	 * 
	 * @param string $run_id 运行ID
	 * @param string $type 命名空间
	 * @param string $run_desc 运行描述（引用传递）
	 * @return array 性能分析数据
	 */
	public static function get_run($run_id, $type, &$run_desc);
	
	/**
	 * 保存性能分析运行数据
	 * 
	 * @param array $xhprof_data 性能分析数据
	 * @param string $type 命名空间
	 * @param string|null $run_id 运行ID（可选）
	 * @return string|false 运行ID或false（如果未保存）
	 */
	public static function save_run($xhprof_data, $type, $run_id = null);
}
