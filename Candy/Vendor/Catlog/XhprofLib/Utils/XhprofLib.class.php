<?php

declare(strict_types=1);

namespace Catlog\XhprofLib\Utils;

use \Catlog\XhprofLib\Display\XhprofDisplay;
use \Catlog\Xhprof;

/**
 * Xhprof性能分析工具库类
 * 提供性能分析数据处理、指标初始化、数据转换等核心功能
 *
 * @author System
 * @version 1.0
 * @since 2026-01-16
 */
class XhprofLib
{


    /**
     * 显示Xhprof错误信息
     * 将错误信息传递给模板消息显示函数
     * 
     * @param string $message 错误信息内容
     * @return void
     */
    public static function xhprof_error(string $message)
    {
        showExceptionMsg('Xhprof', $message);
    }

    /**
     * 获取XHProf可能收集的性能指标列表
     * 这些指标在报告时需要包含/排除处理
     * 
     * @return array 性能指标数组，键为指标缩写，值为包含名称、单位和描述的数组
     */
    public static function xhprof_get_possible_metrics()
    {
        static $possible_metrics = [
            "wt" => ["Wall", "microsecs", "walltime"],
            "ut" => ["User", "microsecs", "user cpu time"],
            "st" => ["Sys", "microsecs", "system cpu time"],
            "cpu" => ["Cpu", "microsecs", "cpu time"],
            "mu" => ["MUse", "bytes", "memory usage"],
            "pmu" => ["PMUse", "bytes", "peak memory usage"],
            "samples" => ["Samples", "samples", "cpu time"]
        ];
        return $possible_metrics;
    }

    /**
     * 初始化要显示的性能指标
     * 根据原始数据中的信息确定要显示的指标
     * 
     * @param array $xhprof_data 原始性能分析数据
     * @param mixed $rep_symbol 报告的符号（函数名），如果为空则显示所有函数
     * @param mixed $sort 排序方式
     * @param bool $diff_report 是否为差异报告
     * @return void
     */
    public static function init_metrics(array $xhprof_data, $rep_symbol, $sort,bool $diff_report = false)
    {
        $sort_col = XhprofDisplay::$sort_col;
        $sortable_columns = XhprofDisplay::$sortable_columns;
        if (!empty($sort)) {
            if (array_key_exists($sort, $sortable_columns)) {
                $sort_col = $sort;
            } else {
                \Candy\Core\Log::input("Invalid Sort Key $sort specified in URL");
            }
        }

        $display_calls = true;
        if (!isset($xhprof_data["main()"]["wt"])) {
            if ($sort_col == "wt") $sort_col = "samples";
            $display_calls = false;
        }

        if (!empty($rep_symbol)) $sort_col = str_replace("excl_", "", $sort_col);
        $stats = ["fn"];
        if ($display_calls) $stats = ["fn", "ct", "Calls%"];
        $pc_stats = $stats;
        $possible_metrics = self::xhprof_get_possible_metrics($xhprof_data);
        foreach ($possible_metrics as $metric => $desc) {
            if (!isset($xhprof_data["main()"][$metric])) continue;
            $metrics[] = $metric;
            $stats[] = $metric;
            $stats[] = "I" . $desc[0] . "%";
            $stats[] = "excl_" . $metric;
            $stats[] = "E" . $desc[0] . "%";
            $pc_stats[] = $metric;
            $pc_stats[] = "I" . $desc[0] . "%";
        }
        XhprofDisplay::$metrics = $metrics;
        XhprofDisplay::$stats = $stats;
        XhprofDisplay::$pc_stats = $pc_stats;
        XhprofDisplay::$diff_mode = $diff_report;
        XhprofDisplay::$sort_col = $sort_col;
        XhprofDisplay::$display_calls = $display_calls;
    }

    /**
     * 获取$xhprof_data中存在的指标列表
     * 检查可能的指标中哪些实际存在于数据中
     * 
     * @param array $xhprof_data 性能分析数据
     * @return array 实际存在的指标列表
     */
    public static function xhprof_get_metrics($xhprof_data)
    {
        $possible_metrics = self::xhprof_get_possible_metrics();
        $metrics = [];
        foreach ($possible_metrics as $metric => $desc) {
            if (!isset($xhprof_data["main()"][$metric])) continue;
            $metrics[] = $metric;
        }
        return $metrics;
    }
	
    /**
     * 解析父子关系键
     * 将"parent==>child"格式的字符串解析为父级和子级数组
     * 
     * @param string $parent_child 父子关系字符串
     * @return array 包含父级和子级的数组
     */
    public static function xhprof_parse_parent_child($parent_child)
    {
        $ret = explode("==>", $parent_child);
        if (isset($ret[1])) return $ret;
        return [null, $ret[0]];
    }
	
    /**
     * 构建父子关系键
     * 将父级和子级组合成"parent==>child"格式的字符串
     * 
     * @param mixed $parent 父级，如果为空则只返回子级
     * @param string $child 子级
     * @return string 构建的父子关系键字符串
     */
    public static function xhprof_build_parent_child_key($parent, $child)
    {
        if ($parent) return $parent . "==>" . $child;
        return $child;
    }
	
	public static function xhprof_valid_run($run_id, $raw_data)
	{

		$main_info = $raw_data["main()"];
		if (empty($main_info)) {
			self::xhprof_error("XHProf: main() missing in raw data for Run ID: $run_id");
			return false;
		}

		if (isset($main_info["wt"])) {
			$metric = "wt";
		} else if (isset($main_info["samples"])) {
			$metric = "samples";
		} else {
			self::xhprof_error("XHProf: Wall Time information missing from Run ID: $run_id");
			return false;
		}

		foreach ($raw_data as $info) {
			$val = $info[$metric];
			if ($val < 0) {
				self::xhprof_error("XHProf: $metric should not be negative: Run ID $run_id"
					. serialize($info));
				return false;
			}
			if ($val > (86400000000)) {
				self::xhprof_error("XHProf: $metric > 1 day found in Run ID: $run_id "
					. serialize($info));
				return false;
			}
		}
		return true;
	}
	
	public static function xhprof_trim_run($raw_data, $functions_to_keep)
	{


		$function_map = array_fill_keys($functions_to_keep, 1);
		$function_map['main()'] = 1;
		$new_raw_data = [];
		foreach ($raw_data as $parent_child => $info) {
			[$parent, $child] = self::xhprof_parse_parent_child($parent_child);
			if (isset($function_map[$parent]) || isset($function_map[$child])) {
				$new_raw_data[$parent_child] = $info;
			}
		}

		return $new_raw_data;
	}
	
    /**
     * 归一化指标值
     * 对所有运行的指标值进行归一化处理，使它们在0到1之间
     * 
     * @param array $raw_data 原始性能分析数据
     * @param int $num_runs 运行次数
     * @return array 归一化后的指标值数组
     */
	public static function xhprof_normalize_metrics($raw_data, $num_runs)
	{

		if (empty($raw_data) || ($num_runs == 0)) return $raw_data;
		$raw_data_total = [];
		if (isset($raw_data["==>main()"]) && isset($raw_data["main()"])) self::xhprof_error("XHProf Error: both ==>main() and main() set in raw data...");
		foreach ($raw_data as $parent_child => $info) {
			foreach ($info as $metric => $value) {
				$raw_data_total[$parent_child][$metric] = ($value / $num_runs);
			}
		}

		return $raw_data_total;
	}
	
    /**
     * 聚合多个性能分析运行
     * 对多个运行的指标值进行聚合处理，将它们合并到一个数组中
     * 
     * @param array $runs 运行ID数组
     * @param array $wts 权重数组，用于不同运行的指标值
     * @param string $source 数据源
     * @param bool $use_script_name 是否使用脚本名称
     * @return array 聚合后的指标值数组
     */
	public static function xhprof_aggregate_runs(
		$runs,
		$wts,
		$source = "phprof",
		$use_script_name = false
	) {

		$raw_data_total = null;
		$raw_data			 = null;
		$metrics				= [];

		$run_count = count($runs);
		$wts_count = count($wts);

		if (($run_count == 0) ||
			(($wts_count > 0) && ($run_count != $wts_count))
		) {
			return [
				'description' => 'Invalid input..',
				'raw'	=> null
			];
		}

		$bad_runs = [];
		foreach ($runs as $idx => $run_id) {
			$raw_data = XHProfRunsDefault::get_run($run_id, $source, $description);
			if ($idx == 0) {
				foreach ($raw_data["main()"] as $metric => $val) {
					if ($metric != "pmu" && isset($val)) $metrics[] = $metric;
				}
			}

			if (!self::xhprof_valid_run($run_id, $raw_data)) {
				$bad_runs[] = $run_id;
				continue;
			}

			if ($use_script_name) {
				$page = $description;
				if ($page) {
					foreach ($raw_data["main()"] as $metric => $val) {
						$fake_edge[$metric] = $val;
						$new_main[$metric]	= $val + 0.00001;
					}
					$raw_data["main()"] = $new_main;
					$raw_data[self::xhprof_build_parent_child_key(
						"main()",
						"__script::$page"
					)]
						= $fake_edge;
				} else {
					$use_script_name = false;
				}
			}
			$wt = ($wts_count == 0) ? 1 : $wts[$idx];
			foreach ($raw_data as $parent_child => $info) {
				if ($use_script_name) {
					if (substr($parent_child, 0, 9) == "main()==>") {
						$child = substr($parent_child, 9);
						if (substr($child, 0, 10) != "__script::") {
							$parent_child = self::xhprof_build_parent_child_key(
								"__script::$page",
								$child
							);
						}
					}
				}

				if (!isset($raw_data_total[$parent_child])) {
					foreach ($metrics as $metric) {
						$raw_data_total[$parent_child][$metric] = ($wt * $info[$metric]);
					}
				} else {
					foreach ($metrics as $metric) {
						$raw_data_total[$parent_child][$metric] += ($wt * $info[$metric]);
					}
				}
			}
		}

		$runs_string = implode(",", $runs);
		$wts_string = "";
		$normalization_count = $run_count;
		if (isset($wts)) {
			$wts_string	= "in the ratio (" . implode(":", $wts) . ")";
			$normalization_count = array_sum($wts);
		}

		$run_count = $run_count - count($bad_runs);
		$data['description'] = "Aggregated Report for $run_count runs: " .
			"$runs_string $wts_string\n";
		$data['raw'] = self::xhprof_normalize_metrics(
			$raw_data_total,
			$normalization_count
		);
		$data['bad_runs'] = $bad_runs;

		return $data;
	}
	
    /**
     * 计算扁平化信息
     * 对所有运行的指标值进行扁平化处理，将它们合并到一个数组中
     * 
     * @param array $raw_data 原始性能分析数据
     * @param array &$overall_totals 输出参数，用于存储所有运行的指标总和
     * @return void
     */
	public static function xhprof_compute_flat_info($raw_data, &$overall_totals)
	{

		$display_calls = XhprofDisplay::$display_calls;
		$metrics = self::xhprof_get_metrics($raw_data);
		$overall_totals = [
			"ct" => 0,
			"wt" => 0,
			"ut" => 0,
			"st" => 0,
			"cpu" => 0,
			"mu" => 0,
			"pmu" => 0,
			"samples" => 0
		];

		$symbol_tab = self::xhprof_compute_inclusive_times($raw_data);
		foreach ($metrics as $metric) {
			$overall_totals[$metric] = $symbol_tab["main()"][$metric];
		}

		foreach ($symbol_tab as $symbol => $info) {
			foreach ($metrics as $metric) {
				$symbol_tab[$symbol]["excl_" . $metric] = $symbol_tab[$symbol][$metric];
			}
			if ($display_calls) $overall_totals["ct"] += $info["ct"];
		}
		if(false==is_array($raw_data)) return $symbol_tab;
		foreach ($raw_data as $parent_child => $info) {
			[$parent, $child] = self::xhprof_parse_parent_child($parent_child);
			if ($parent) {
				foreach ($metrics as $metric) {
					if (isset($symbol_tab[$parent])) $symbol_tab[$parent]["excl_" . $metric] -= $info[$metric];
				}
			}
		}

		return $symbol_tab;
	}
	
    /**
     * 计算差异报告
     * 对两个性能分析运行的指标值进行差异计算，生成差异报告
     * 
     * @param array $xhprof_data1 第一个性能分析运行数据
     * @param array $xhprof_data2 第二个性能分析运行数据
     * @return array 差异报告数组
     */
	public static function xhprof_compute_diff($xhprof_data1, $xhprof_data2)
	{
		$display_calls = XhprofDisplay::$display_calls;

		// use the second run to decide what metrics we will do the diff on
		$metrics = self::xhprof_get_metrics($xhprof_data2);
		$xhprof_delta = $xhprof_data2;
		foreach ($xhprof_data1 as $parent_child => $info) {

			if (!isset($xhprof_delta[$parent_child])) {
				$xhprof_delta[$parent_child] = [];
				if ($display_calls) $xhprof_delta[$parent_child] = ["ct" => 0];
				foreach ($metrics as $metric) {
					$xhprof_delta[$parent_child][$metric] = 0;
				}
			}

			if ($display_calls) $xhprof_delta[$parent_child]["ct"] -= $info["ct"];
			foreach ($metrics as $metric) {
				$xhprof_delta[$parent_child][$metric] -= $info[$metric];
			}
		}

		return $xhprof_delta;
	}
	
    /**
     * 计算包含时间的指标值
     * 对所有运行的指标值进行包含时间的计算，将它们合并到一个数组中
     * 
     * @param array $raw_data 原始性能分析数据
     * @return array 包含时间的指标值数组
     */
	public static function xhprof_compute_inclusive_times($raw_data)
	{
		$display_calls = XhprofDisplay::$display_calls;
		$metrics = self::xhprof_get_metrics($raw_data);
		$symbol_tab = [];
		if(false==is_array($raw_data)) return $symbol_tab;
		foreach ($raw_data as $parent_child => $info) {
			[$parent, $child] = self::xhprof_parse_parent_child($parent_child);
			if ($parent == $child) {
				self::xhprof_error("Error in Raw Data: parent & child are both: $parent");
				return;
			}

			if (!isset($symbol_tab[$child])) {
				$symbol_tab[$child] = [];
				if ($display_calls) $symbol_tab[$child] = ["ct" => $info["ct"]];
				foreach ($metrics as $metric) {
					$symbol_tab[$child][$metric] = $info[$metric];
				}
			} else {
				if ($display_calls) $symbol_tab[$child]["ct"] += $info["ct"];
				foreach ($metrics as $metric) {
					$symbol_tab[$child][$metric] += $info[$metric];
				}
			}
		}

		return $symbol_tab;
	}
	
    /**
     * 去除性能分析运行中的指标值
     * 对所有运行的指标值进行去除处理，根据指定的百分比筛选出重要指标
     * 
     * @param array $raw_data 原始性能分析数据
     * @param float $prune_percent 去除百分比，0-100之间的浮点数
     * @return array 去除后的性能分析运行数据
     */
	public static function xhprof_prune_run($raw_data, $prune_percent)
	{

		$main_info = $raw_data["main()"];
		if (empty($main_info)) {
			self::xhprof_error("XHProf: main() missing in raw data");
			return false;
		}

		// raw data should contain either wall time or samples information...
		if (isset($main_info["wt"])) {
			$prune_metric = "wt";
		} else if (isset($main_info["samples"])) {
			$prune_metric = "samples";
		} else {
			self::xhprof_error("XHProf: for main() we must have either wt "
				. "or samples attribute set");
			return false;
		}

		// determine the metrics present in the raw data..
		$metrics = [];
		foreach ($main_info as $metric => $val) {
			if (isset($val)) $metrics[] = $metric;
		}

		$prune_threshold = (($main_info[$prune_metric] * $prune_percent) / 100.0);
		self::init_metrics($raw_data, null, null, false);
		$flat_info = self::xhprof_compute_inclusive_times($raw_data);

		foreach ($raw_data as $parent_child => $info) {

			[$parent, $child] = self::xhprof_parse_parent_child($parent_child);
			if ($flat_info[$child][$prune_metric] < $prune_threshold) {
				unset($raw_data[$parent_child]); // prune the edge
			} else if (
				$parent &&
				($parent != "__pruned__()") &&
				($flat_info[$parent][$prune_metric] < $prune_threshold)
			) {
				$pruned_edge = self::xhprof_build_parent_child_key("__pruned__()", $child);
				if (isset($raw_data[$pruned_edge])) {
					foreach ($metrics as $metric) {
						$raw_data[$pruned_edge][$metric] += $raw_data[$parent_child][$metric];
					}
				} else {
					$raw_data[$pruned_edge] = $raw_data[$parent_child];
				}

				unset($raw_data[$parent_child]); // prune the edge
			}
		}

		return $raw_data;
	}
	
    /**
     * 生成链接
     * 根据参数生成一个链接，用于跳转到性能分析报告
     * 
     * @param array $params 链接参数
     * @return string 生成的链接
     */
	public static function xhprof_get_link($params)
	{
		return U('/' . C('CONTROL') .'/'. C('ACTION'), ['xhprof'=>base64_encode(json($params))]);
	}
	
    /**
     * 设置数组元素
     * 对数组进行指定键值对的设置
     * 
     * @param array $arr 数组
     * @param string $k 键
     * @param mixed $v 值
     * @return array 修改后的数组
     */
	public static function xhprof_array_set($arr, $k, $v)
	{
		$arr[$k] = $v;
		return $arr;
	}
	
    /**
     * 从数组中 unset指定键
     * 对数组进行指定键的 unset 操作
     * 
     * @param array $arr 数组
     * @param string $k 键
     * @return array 修改后的数组
     */
	public static function xhprof_array_unset($arr, $k)
	{
		unset($arr[$k]);
		return $arr;
	}
	
    /**
     * 获取参数值
     * 从请求参数中获取指定键的值，若不存在则返回默认值
     * 
     * @param string $param 参数名
     * @param string $default 默认值
     * @return mixed 参数值或默认值
     */
	public static function xhprof_get_param_helper($param)
	{
	$xhprof = I('get.xhprof', null);
		$get_data = is_null($xhprof) ? [] : json(base64_decode($xhprof), 'un');
		return $get_data[$param] ??= null;
	}
	
    /**
     * 获取字符串参数值
     * 从请求参数中获取指定键的字符串值，若不存在则返回默认值
     * 
     * @param string $param 参数名
     * @param string $default 默认值
     * @return string 参数值或默认值
     */
	public static function xhprof_get_string_param($param, $default = '')
	{
		$val = self::xhprof_get_param_helper($param);
		if ($val === null) return $default;
		return $val;
	}
	
    /**
     * 获取无符号整数参数值
     * 从请求参数中获取指定键的无符号整数值，若不存在则返回默认值
     * 
     * @param string $param 参数名
     * @param string $default 默认值
     * @return int 参数值或默认值
     */
	public static function xhprof_get_uint_param($param, $default = 0)
	{
		$val = self::xhprof_get_param_helper($param);
		if ($val === null) $val = $default;
		$val = trim($val);
		if (ctype_digit($val)) return $val;
		self::xhprof_error("$param is $val. It must be an unsigned integer.");
		return null;
	}
	
    /**
     * 获取浮点数参数值
     * 从请求参数中获取指定键的浮点数值，若不存在则返回默认值
     * 
     * @param string $param 参数名
     * @param string $default 默认值
     * @return float 参数值或默认值
     */
	public static function xhprof_get_float_param($param, $default = 0)
	{
		$val = self::xhprof_get_param_helper($param);
		if ($val === null) $val = $default;
		$val = trim($val);
		if (true) return (float)$val;
		self::xhprof_error("$param is $val. It must be a float.");
		return null;
	}
	
    /**
     * 获取布尔值参数值
     * 从请求参数中获取指定键的布尔值，若不存在则返回默认值
     * 
     * @param string $param 参数名
     * @param string $default 默认值
     * @return bool 参数值或默认值
     */
	public static function xhprof_get_bool_param($param, $default = false)
	{
		$val = self::xhprof_get_param_helper($param);

		if ($val === null) $val = $default;
		$val = trim($val);
		return match (strtolower($val)) {
			'0', '1' => (bool)$val,
			'true', 'on', 'yes' => true,
			'false', 'off', 'no' => false,
			default => (function() use ($param, $val) {
				self::xhprof_error("$param is $val. It must be a valid boolean string.");
				return null;
			})(),
		};
	}
	
    /**
     * 获取匹配函数名
     * 从性能分析运行数据中获取所有包含指定字符串的函数名
     * 
     * @param string $q 搜索字符串
     * @param array $xhprof_data 性能分析运行数据
     * @return array 匹配的函数名数组
     */
	public static function xhprof_get_matching_functions($q, $xhprof_data)
	{

		$matches = [];
		foreach ($xhprof_data as $parent_child => $info) {
			[$parent, $child] = self::xhprof_parse_parent_child($parent_child);
			if (stripos($parent, $q) !== false) $matches[$parent] = 1;
			if (stripos($child, $q) !== false) $matches[$child] = 1;
		}
		$res = array_keys($matches);
		asort($res);
		return ($res);
	}
	
    /**
     * 检查URL是否被忽略
     * 检查当前请求是否在忽略列表中，若在则返回false，否则返回true
     * 
     * @return bool 是否忽略忽略列表中的URL
     */
	public static function isIgnore()
	{
		$ignoreArr = Xhprof::$ignore_url_arr;
		if (is_array($ignoreArr) === false || empty($ignoreArr)) return true;
		
		//循环
		foreach ($ignoreArr as $value) {
			if(stripos($value, '*') === false){
				//全匹配
				if($_SERVER['REQUEST_URI'] == $value) return false;
			}else{
				//通配符
				if(stripos($value, '*') === 0){
					//包含 *url  *url*
					if(substr($value, -1) == '*'){
						//包含 *url*
						$value = substr($value, 1, strlen($value)-2);
						if(strpos($_SERVER['REQUEST_URI'], $value) > 0) return false;
					}else{
						//结尾 *url
						$value = substr($value, 1, strlen($value)-1);
						[,$last] = explode($value, $_SERVER['REQUEST_URI'], 2);
						if(empty($last)) return false;
					}
				}else{
					//开头  url*
					$value = substr($value, 0, strlen($value)-2);
					if(strpos($_SERVER['REQUEST_URI'], $value) === 0) return false;
				}
			}
		}
		
		return true;
	}

	/**
	 * 取客户端ip
	 * @return mixed|string
	 */
	public static function xhprof_get_ip()
	{
		return \Candy\Extend\Network\Client::getClientIp();
	}

	/**
	 * 获取请求详情
	 * @param $run_id
	 */
	public static function getRequestLog($run_id)
	{
		$key = Xhprof::$key_prefix . ":request_log:" . $run_id;
		$cache = \Candy\Core\Cache::store();
		$info = $cache->get($key);
		if ($info) return json_decode($info, true);
		return false;
	}
}
