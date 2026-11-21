<?php
declare(strict_types=1);
namespace Catlog\XhprofLib\Display;

use \Catlog\Xhprof;
use \Catlog\XhprofLib\Utils\{XhprofLib,XHProfRunsDefault};

/**
 * Xhprof性能分析结果显示类
 * 负责生成和展示XHProf性能分析报告的HTML页面
 *
 * @author System
 * @version 1.0
 * @since 2026-01-16
 */
class XhprofDisplay
{
	
    /**
     * 获取基础路径
     * 用于生成链接URL
     * 
     * @return string 基础路径URL
     */
    public static function base_path()
    {
        return U('/'.C('CONTROL') .'/'. C('ACTION'));
    }
    
    /**
     * 默认排序列
     * wt表示Wall Time（墙钟时间）
     * @var string
     */
    public static $sort_col = "wt";
    
    /**
     * 是否为差异模式
     * 用于比较两个运行结果
     * @var bool
     */
    public static $diff_mode = false;
    
    /**
     * 是否显示调用次数
     * @var bool
     */
    public static $display_calls = true;


    /**
     * 可排序的列列表
     * 包含所有可以作为排序依据的性能指标
     * 
     * @var array
     */
    public static $sortable_columns = [
        "fn" => 1,        // 函数名
        "ct" => 1,        // 调用次数
        "wt" => 1,        // 墙钟时间（总耗时）
        "excl_wt" => 1,   // 排除子函数的墙钟时间
        "ut" => 1,        // 用户CPU时间
        "excl_ut" => 1,   // 排除子函数的用户CPU时间
        "st" => 1,        // 系统CPU时间
        "excl_st" => 1,   // 排除子函数的系统CPU时间
        "mu" => 1,        // 内存使用量
        "excl_mu" => 1,   // 排除子函数的内存使用量
        "pmu" => 1,       // 峰值内存使用量
        "excl_pmu" => 1,  // 排除子函数的峰值内存使用量
        "cpu" => 1,       // CPU总时间
        "excl_cpu" => 1,  // 排除子函数的CPU总时间
        "samples" => 1,   // 采样数
        "excl_samples" => 1 // 排除子函数的采样数
    ];

    /**
     * 视图栏样式
     * @var string
     */
    public static $vwbar;
    
    /**
     * 默认栏样式
     * @var string
     */
    public static $vbar;
    
    /**
     * 蓝色栏样式
     * @var string
     */
    public static $vbbar;
    
    /**
     * 红色栏样式（用于表示性能下降）
     * @var string
     */
    public static $vrbar;
    
    /**
     * 绿色栏样式（用于表示性能提升）
     * @var string
     */
    public static $vgbar;
	
    /**
     * 性能指标描述
     * 包含各种性能指标的中文显示名称
     * 
     * @var array
     */
    public static $descriptions = [
        "fn" => "函数/方法名",
        "ct" => "调用<br>次数",
        "Calls%" => "调用<br>次数<br>占比",
        
        "wt" => "总耗时<br>(微秒)",
        "IWall%" => "总耗时<br>占比",
        "excl_wt" => "自身耗时<br>(微秒)",
        "EWall%" => "自身耗时<br>占比",
        
        "ut" => "Incl. User<br>(microsecs)",
        "IUser%" => "IUser%",
        "excl_ut" => "Excl. User<br>(microsec)",
        "EUser%" => "EUser%",
        
        "st" => "Incl. Sys <br>(microsec)",
        "ISys%" => "ISys%",
        "excl_st" => "Excl. Sys <br>(microsec)",
        "ESys%" => "ESys%",
        
        "cpu" => "总<br>CPU时间<br>(微秒)",
        "ICpu%" => "总<br>CPU时间<br>占比",
        "excl_cpu" => "自身<br>CPU时间<br>(微秒)",
        "ECpu%" => "自身<br>CPU时间<br>占比",
        
        "mu" => "总<br>内存占用<br>(bytes)",
        "IMUse%" => "总<br>内存占用<br>占比",
        "excl_mu" => "自身<br>内存占用<br>(bytes)",
        "EMUse%" => "自身<br>内存占用<br>占比",
        
        "pmu" => "总<br>内存峰值<br>(bytes)",
        "IPMUse%" => "总<br>内存峰值<br>占比",
        "excl_pmu" => "自身<br>内存峰值<br>(bytes)",
        "EPMUse%" => "自身<br>内存峰值<br>占比",
        
        "samples" => "Incl. Samples",
        "ISamples%" => "ISamples%",
        "excl_samples" => "Excl. Samples",
        "ESamples%" => "ESamples%",
    ];

    /**
     * 性能指标格式化回调函数
     * 定义各种性能指标的显示格式化方式
     * 
     * @var array
     */
    public static $format_cbk = [
        "fn" => "",                          // 函数名无需格式化
        "ct" => "self::xhprof_count_format",  // 调用次数使用自定义计数格式化
        "Calls%" => "self::xhprof_percent_format",  // 调用次数占比使用百分比格式化
        "wt" => "number_format",             // 总耗时使用数字格式化
        "IWall%" => "self::xhprof_percent_format",  // 总耗时占比使用百分比格式化
        "excl_wt" => "number_format",        // 自身耗时使用数字格式化
        "EWall%" => "self::xhprof_percent_format",  // 自身耗时占比使用百分比格式化
        
        "ut" => "number_format",             // 用户CPU时间使用数字格式化
        "IUser%" => "self::xhprof_percent_format",  // 用户CPU时间占比使用百分比格式化
        "excl_ut" => "number_format",        // 自身用户CPU时间使用数字格式化
        "EUser%" => "self::xhprof_percent_format",  // 自身用户CPU时间占比使用百分比格式化
        
        "st" => "number_format",             // 系统CPU时间使用数字格式化
        "ISys%" => "self::xhprof_percent_format",  // 系统CPU时间占比使用百分比格式化
        "excl_st" => "number_format",        // 自身系统CPU时间使用数字格式化
        "ESys%" => "self::xhprof_percent_format",  // 自身系统CPU时间占比使用百分比格式化
        
        "cpu" => "number_format",            // CPU总时间使用数字格式化
        "ICpu%" => "self::xhprof_percent_format",  // CPU总时间占比使用百分比格式化
        "excl_cpu" => "number_format",       // 自身CPU总时间使用数字格式化
        "ECpu%" => "self::xhprof_percent_format",  // 自身CPU总时间占比使用百分比格式化
        
        "mu" => "number_format",             // 内存使用量使用数字格式化
        "IMUse%" => "self::xhprof_percent_format",  // 内存使用量占比使用百分比格式化
        "excl_mu" => "number_format",        // 自身内存使用量使用数字格式化
        "EMUse%" => "self::xhprof_percent_format",  // 自身内存使用量占比使用百分比格式化
        
        "pmu" => "number_format",            // 峰值内存使用量使用数字格式化
        "IPMUse%" => "self::xhprof_percent_format",  // 峰值内存使用量占比使用百分比格式化
        "excl_pmu" => "number_format",       // 自身峰值内存使用量使用数字格式化
        "EPMUse%" => "self::xhprof_percent_format",  // 自身峰值内存使用量占比使用百分比格式化
        
        "samples" => "number_format",        // 采样数使用数字格式化
        "ISamples%" => "self::xhprof_percent_format",  // 采样数占比使用百分比格式化
        "excl_samples" => "number_format",   // 自身采样数使用数字格式化
        "ESamples%" => "self::xhprof_percent_format",  // 自身采样数占比使用百分比格式化
    ];


    /**
     * 差异模式下的性能指标描述
     * 用于比较两个性能分析结果时的显示名称
     * 
     * @var array
     */
    public static $diff_descriptions = [
        "fn" => "Function Name",
        "ct" => "Calls Diff",
        "Calls%" => "Calls<br>Diff%",
        
        "wt" => "Incl. Wall<br>Diff<br>(microsec)",
        "IWall%" => "IWall<br> Diff%",
        "excl_wt" => "Excl. Wall<br>Diff<br>(microsec)",
        "EWall%" => "EWall<br>Diff%",
        
        "ut" => "Incl. User Diff<br>(microsec)",
        "IUser%" => "IUser<br>Diff%",
        "excl_ut" => "Excl. User<br>Diff<br>(microsec)",
        "EUser%" => "EUser<br>Diff%",
        
        "cpu" => "Incl. CPU Diff<br>(microsec)",
        "ICpu%" => "ICpu<br>Diff%",
        "excl_cpu" => "Excl. CPU<br>Diff<br>(microsec)",
        "ECpu%" => "ECpu<br>Diff%",
        
        "st" => "Incl. Sys Diff<br>(microsec)",
        "ISys%" => "ISys<br>Diff%",
        "excl_st" => "Excl. Sys Diff<br>(microsec)",
        "ESys%" => "ESys<br>Diff%",
        
        "mu" => "Incl.<br>MemUse<br>Diff<br>(bytes)",
        "IMUse%" => "IMemUse<br>Diff%",
        "excl_mu" => "Excl.<br>MemUse<br>Diff<br>(bytes)",
        "EMUse%" => "EMemUse<br>Diff%",
        
        "pmu" => "Incl.<br> PeakMemUse<br>Diff<br>(bytes)",
        "IPMUse%" => "IPeakMemUse<br>Diff%",
        "excl_pmu" => "Excl.<br>PeakMemUse<br>Diff<br>(bytes)",
        "EPMUse%" => "EPeakMemUse<br>Diff%",
        
        "samples" => "Incl. Samples Diff",
        "ISamples%" => "ISamples Diff%",
        "excl_samples" => "Excl. Samples Diff",
        "ESamples%" => "ESamples Diff%",
    ];

    /**
     * 统计数据列表
     * 包含所有需要显示的性能指标
     * @var array
     */
    public static $stats = [];
    
    /**
     * 父子调用统计数据列表
     * 用于显示函数的父子调用关系统计
     * @var array
     */
    public static $pc_stats = [];
    
    /**
     * 总统计值
     * @var int|float
     */
    public static $totals = 0;
    
    /**
     * 第一个运行结果的总统计值
     * 用于差异比较模式
     * @var int|float
     */
    public static $totals_1 = 0;
    
    /**
     * 第二个运行结果的总统计值
     * 用于差异比较模式
     * @var int|float
     */
    public static $totals_2 = 0;
    
    /**
     * 性能指标列表
     * 包含当前运行结果中实际存在的性能指标
     * @var array|null
     */
    public static $metrics = null;
	
    /**
     * 生成所需的CSS样式表和JavaScript引用
     *
     * 如果调用脚本（如index.php）位于与'xhprof_html'目录不同的位置，
     * 调用者必须提供'xhprof_html'目录的URL路径，以便在生成的HTML中指定
     * 样式表和JavaScript的正确位置。
     *
     * @param string|null $ui_dir_url_path UI资源目录的URL路径
     * @return string HTML字符串，包含所有必要的CSS和JavaScript引用
     */
    public static function xhprof_include_js_css(?string $ui_dir_url_path = null)
    {
        if (empty($ui_dir_url_path)) {
            $ui_dir_url_path = '/Candy/Vendor/Catlog/static';
        }
        $ui_dir_url_path = rtrim($ui_dir_url_path, '/');

        // 样式（catlog-ui 最后加载覆盖旧主题；带 mtime 防缓存）
        $echo_page = "<link href='$ui_dir_url_path/css/xhprof.css' rel='stylesheet' type='text/css' />";
        $echo_page .= "<link href='$ui_dir_url_path/css/bootstrap.css' rel='stylesheet' type='text/css' />";
        $echo_page .= "<link href='$ui_dir_url_path/css/dataTables.bootstrap.css' rel='stylesheet' type='text/css' />";
        $echo_page .= "<link href='$ui_dir_url_path/jquery/jquery.tooltip.css' rel='stylesheet' type='text/css' />";
        $echo_page .= "<link href='$ui_dir_url_path/jquery/jquery.autocomplete.css' rel='stylesheet' type='text/css' />";
        $ui_css_ver = @filemtime(__DIR__ . '/../../static/css/catlog-ui.css') ?: time();
        $echo_page .= "<link href='$ui_dir_url_path/css/catlog-ui.css?v=$ui_css_ver' rel='stylesheet' type='text/css' />";

        $echo_page .= "<script src='$ui_dir_url_path/jquery/jquery-3.0.0.min.js'></script>";
        $echo_page .= "<script src='$ui_dir_url_path/jquery/jquery.tooltip.js'></script>";
        $echo_page .= "<script src='$ui_dir_url_path/jquery/jquery.autocomplete.js'></script>";
        $ui_js_ver = @filemtime(__DIR__ . '/../../static/js/xhprof_report.js') ?: time();
        $echo_page .= "<script src='$ui_dir_url_path/js/xhprof_report.js?v=$ui_js_ver'></script>";
        $echo_page .= "<script src='$ui_dir_url_path/js/bootstrap.min.js'></script>";
        $echo_page .= "<script src='$ui_dir_url_path/js/jquery.dataTables.min.js'></script>";
        $echo_page .= "<script src='$ui_dir_url_path/js/dataTables.bootstrap.js'></script>";
        return $echo_page;
    }


    /**
     * 格式化计数数字
     * 用于将调用次数等计数类型的数字格式化为易读的形式
     * 
     * @param int $num 要格式化的数字
     * @return string 格式化后的数字字符串
     */
    public static function xhprof_count_format(int $num)
    {
        // 四舍五入到小数点后3位
        $num = round($num, 3);
        
        // 如果是整数，则使用整数格式显示
        if (round($num) == $num) return number_format($num);
        
        // 否则保留3位小数
        return number_format($num, 3);
    }

    /**
     * 格式化百分比数字
     * 用于将小数格式化为百分比形式
     * 
     * @param int|float $s 要格式化的数字（0-1之间）
     * @param int $precision 小数点后保留的位数，默认1位
     * @return string 格式化后的百分比字符串
     */
    public static function xhprof_percent_format(int|float $s, int $precision = 1)
    {
        return sprintf('%.' . $precision . 'f%%', 100 * $s);
    }

    /**
     * 将一系列操作（如链接、表单等）组合成HTML列表并返回
     * 
     * @param array $actions 操作列表，每个元素是一个HTML字符串
     * @return string 包含所有操作的HTML列表字符串
     */
    public static function xhprof_render_actions(array $actions)
    {
        $out = [];
        if (count($actions)) {
            $out[] = '<ul class="xhprof_actions">';
            foreach ($actions as $action) {
                $out[] = '<li>' . $action . '</li>';
            }
            $out[] = '</ul>';
        }

        return implode('', $out);
    }


    /**
     * 生成HTML链接标签
     * 用于创建带有各种属性的链接元素
     * 
     * @param string $content 链接显示内容
     * @param string $href 链接URL
     * @param string $class CSS类名
     * @param string $id HTML元素ID
     * @param string $title 标题属性
     * @param string $target 目标窗口属性
     * @param string $onclick 点击事件处理函数
     * @param string $style 内联样式
     * @param string $access 快捷键
     * @param string $onmouseover 鼠标悬停事件处理函数
     * @param string $onmouseout 鼠标移出事件处理函数
     * @param string $onmousedown 鼠标按下事件处理函数
     * @return string HTML链接字符串
     */
    public static function xhprof_render_link($content, $href, $class = '', $id = '', $title = '', $target = '', $onclick = '', $style = '', $access = '', $onmouseover = '', $onmouseout = '', $onmousedown = '')
    {
        if (!$content) return '';
        
        // 如果没有href，则生成span标签
        $link = '<span';
        if ($href) $link = '<a href="' . ($href) . '"';
        
        // 添加各种属性
        if ($class)	$link .= ' class="' . ($class) . '"';
        if ($id) $link .= ' id="' . ($id) . '"';
        if ($title) $link .= ' title="' . ($title) . '"';
        if ($target) $link .= ' target="' . ($target) . '"';
        if ($onclick && $href) $link .= ' onclick="' . ($onclick) . '"';
        if ($style && $href) $link .= ' style="' . ($style) . '"';
        if ($access && $href) $link .= ' accesskey="' . ($access) . '"';
        if ($onmouseover) $link .= ' onmouseover="' . ($onmouseover) . '"';
        if ($onmouseout) $link .= ' onmouseout="' . ($onmouseout) . '"';
        if ($onmousedown) $link .= ' onmousedown="' . ($onmousedown) . '"';

        $link .= '>';
        $link .= $content;
        
        // 关闭标签
        if ($href) {
            $link .= '</a>';
        } else {
            $link .= '</span>';
        }

        return $link;
    }


    /**
     * 排序回调函数
     * 用于对性能分析数据进行排序
     * 
     * @param array $a 第一个比较元素
     * @param array $b 第二个比较元素
     * @return int 比较结果，-1表示$a排在$b前面，1表示$b排在$a前面，0表示相等
     */
    public static function sort_cbk($a, $b)
    {
        $sort_col = self::$sort_col;
        $diff_mode = self::$diff_mode;
        
        // 如果按函数名排序，则进行字符串比较
        if ($sort_col == "fn") {
            $left = strtoupper($a["fn"]);
            $right = strtoupper($b["fn"]);
            if ($left == $right) return 0;
            return ($left < $right) ? -1 : 1;
        } else {
            // 否则按数值大小排序
            $left = $a[$sort_col];
            $right = $b[$sort_col];
            
            // 在差异模式下，使用绝对值进行比较
            if ($diff_mode) {
                $left = abs($left);
                $right = abs($right);
            }
            
            if ($left == $right)	return 0;
            return ($left > $right) ? -1 : 1;
        }
    }


    /**
     * 获取性能指标的描述文本
     * 根据当前是否为差异模式返回不同的描述
     * 
     * @param string $stat 性能指标名称
     * @return string 性能指标的描述文本
     */
    public static function stat_description($stat)
    {
        $descriptions = self::$descriptions;
        $diff_descriptions = self::$diff_descriptions;
        $diff_mode = self::$diff_mode;
        
        // 默认使用普通描述
        $result = $descriptions[$stat];
        
        // 如果是差异模式，则使用差异描述
        if ($diff_mode) $result = $diff_descriptions[$stat];
        
        return $result;
    }

    /**
     * 生成性能分析报告
     * 根据参数生成单个运行结果的报告或两个运行结果的差异报告
     * 
     * @param array $url_params URL参数
     * @param string|null $rep_symbol 报告的符号（函数名），如果为空则生成所有函数的报告
     * @param string $run1 第一个运行结果的ID
     * @param string $run1_desc 第一个运行结果的描述
     * @param array $run1_data 第一个运行结果的性能数据
     * @param string $run2 第二个运行结果的ID（可选，用于差异报告）
     * @param string $run2_desc 第二个运行结果的描述（可选）
     * @param array $run2_data 第二个运行结果的性能数据（可选）
     * @return string 生成的HTML报告
     */
    public static function profiler_report($url_params, $rep_symbol, $run1, $run1_desc, $run1_data, $run2 = 0, $run2_desc = "", $run2_data = [])
    {
        $totals = 0;
        $totals_1 = 0;
        $totals_2 = 0;

        $diff_mode = self::$diff_mode;
        $base_path = self::base_path();

        // 如果指定了符号，则只保留与该符号相关的数据
        if (!empty($rep_symbol)) {
            $run1_data = XhprofLib::xhprof_trim_run($run1_data, [$rep_symbol]);
            if ($diff_mode) $run2_data = XhprofLib::xhprof_trim_run($run2_data, [$rep_symbol]);
        }
        
        // 计算扁平信息
        $symbol_tab = XhprofLib::xhprof_compute_flat_info($run1_data, $totals);
        self::$totals = $totals;
        
        // 如果是差异模式，计算差异数据
        if ($diff_mode) {
            $run_delta = XhprofLib::xhprof_compute_diff($run1_data, $run2_data);
            $symbol_tab	= XhprofLib::xhprof_compute_flat_info($run_delta, $totals);
            $symbol_tab1 = XhprofLib::xhprof_compute_flat_info($run1_data, $totals_1);
            $symbol_tab2 = XhprofLib::xhprof_compute_flat_info($run2_data, $totals_2);
            self::$totals = $totals;
            self::$totals_1 = $totals_1;
            self::$totals_2 = $totals_2;
        }
        
        // 生成第一个运行结果的描述
        $run1_txt = sprintf(
            "<b>Run #%s:</b> %s",
            $run1,
            $run1_desc
        );

        // 设置基础URL参数
        $base_url_params = XhprofLib::xhprof_array_unset(XhprofLib::xhprof_array_unset($url_params, 'symbol'), 'all');
        if ($diff_mode) {
            $diff_text = "Diff";
            $base_url_params = XhprofLib::xhprof_array_unset($base_url_params, 'run1');
            $base_url_params = XhprofLib::xhprof_array_unset($base_url_params, 'run2');
            
            // 生成第一个运行结果的链接
            $run1_link = self::xhprof_render_link(
                'View Run #' . $run1,
                XhprofLib::xhprof_get_link(array_merge($base_url_params, ['run'=>$run1]))
            );
            
            // 生成第二个运行结果的描述和链接
            $run2_txt = sprintf(
                "<b>Run #%s:</b> %s",
                $run2,
                $run2_desc
            );

            $run2_link = self::xhprof_render_link(
                'View Run #' . $run2,
                XhprofLib::xhprof_get_link(array_merge($base_url_params, ['run'=>$run2]))
            );
        } else {
            $diff_text = "Run";
        }

        // 设置报告的操作链接
        $links = [];
        if ($diff_mode) {
            $inverted_params = $url_params;
            $inverted_params['run1'] = $url_params['run2'];
            $inverted_params['run2'] = $url_params['run1'];

            // 查看不同的运行结果或反转当前差异
            $links[] = $run1_link;
            $links[] = $run2_link;
            $links[] = self::xhprof_render_link(
                'Invert ' . $diff_text . ' Report',
                XhprofLib::xhprof_get_link($inverted_params)
            );
        }

        // 函数名搜索（参数写入 data-params，供 JS 合并进 xhprof 后再跳转）
        $paramsAttr = htmlspecialchars(
            json_encode($url_params, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            ENT_QUOTES,
            'UTF-8'
        );
        $links[] = '<div class="input-group catlog-fn-search" style="width: 420px;">'
            . '<input type="text" class="function_typeahead form-control" id="catlogFnSearch"'
            . ' data-params="' . $paramsAttr . '"'
            . ' placeholder="查找 函数/方法名..." autocomplete="off">'
            . '<span class="input-group-btn">'
            . '<button class="btn btn-default" type="button" id="funcSub">搜索</button>'
            . '</span></div>';
        $echo_page = self::xhprof_render_actions($links);

        // 生成数据表格
        if (!empty($rep_symbol)) {
            if (!isset($symbol_tab[$rep_symbol])) {
                $echo_page .= '<div class="container-fluid" style="width: 90%"> <div class="row"> <div class="col-xs-12">';	//开始
                $echo_page .= "<hr>Symbol <b>$rep_symbol</b> not found in XHProf run</b><hr>";
                $echo_page .= '</div></div></div>';
            }

            // 生成单个函数的报告，包含父子调用信息
            if ($diff_mode) {
                $info1 = $symbol_tab1[$rep_symbol] ??= null;
                $info2 = $symbol_tab2[$rep_symbol] ??= null;
                $echo_page .= self::symbol_report($url_params, $run_delta, $symbol_tab[$rep_symbol], $rep_symbol, $run1, $info1, $run2, $info2);
            } else {
                $echo_page .= self::symbol_report($url_params, $run1_data, $symbol_tab[$rep_symbol], $rep_symbol, $run1);
            }
        } else {
            // 生成所有函数的扁平报告
            $echo_page .= self::full_report($url_params, $symbol_tab, $run1, $run2);
        }
        return $echo_page;
    }

	/**
	 * 计算百分比
	 * 计算两个数值的百分比，保留两位小数
	 * 
	 * @param int|float $a 要计算百分比的数值
	 * @param int|float $b 要用作分母的数值
	 * @return string 计算结果百分比字符串，保留两位小数
	 */
	public static function pct($a, $b)
	{
		$res = "N/A";
		if ($b != 0) $res = (round(($a * 1000 / $b)) / 10);
		return $res;
	}

	/**
	 * 获取打印类名
	 * 根据数值和是否加粗判断使用不同的类名
	 * 
	 * @param int|float $num 要判断的数值
	 * @param bool $bold 是否加粗显示
	 * @return string 类别名
	 * @param bool $bold 是否加粗显示
	 * @return string 类别名
	 */
	public static function get_print_class($num, $bold)
	{
		$vbar = self::$vbar;
		$vbbar = self::$vbbar;
		$vrbar = self::$vrbar;
		$vgbar = self::$vgbar;
		$diff_mode = self::$diff_mode;

		if ($bold) {
			if ($diff_mode) {
				$class = $vrbar; // red (regression)
				if ($num <= 0) $class = $vgbar; // green (improvement)
			} else {
				$class = $vbbar; // blue
			}
		} else {
			$class = $vbar;	// default (black)
		}

		return $class;
	}

	/**
	 * 打印数值单元格
	 * 生成并返回包含数值的HTML单元格元素
	 * 
	 * @param int|float $num 要打印的数值
	 * @param callable $fmt_func 格式化函数，用于对数值进行格式化（可选）
	 * @param bool $bold 是否加粗显示
	 * @param string|null $attributes 元素属性（可选）
	 * @return string 单元格HTML字符串
	 */
	public static function print_td_num($num, $fmt_func, $bold = false, $attributes = null)
	{

		$class = self::get_print_class($num, $bold);
		if (!empty($fmt_func) && is_numeric($num)) {
			$num = call_user_func($fmt_func, $num);
		}
		return "<td $attributes $class>$num</td>\n";
	}

	/**
	 * 打印百分比单元格
	 * 生成并返回包含百分比的HTML单元格元素
	 * 
	 * @param int|float $numer 要打印的数值
	 * @param int|float $denom 要用作分母的数值
	 * @param bool $bold 是否加粗显示
	 * @param string|null $attributes 元素属性（可选）
	 * @return string 单元格HTML字符串
	 */
	public static function print_td_pct($numer, $denom, $bold = false, $attributes = null)
	{
		$class = self::get_print_class($numer, $bold);
		$pct = "N/A%";
		if ($denom != 0) $pct = self::xhprof_percent_format($numer / abs($denom));
		return "<td $attributes $class>$pct</td>\n";
	}

	/**
	 * 打印函数信息单元格
	 * 生成并返回包含函数信息的HTML单元格元素
	 * 
	 * @param array $url_params URL参数
	 * @param string $info 函数信息
	 * @return string 单元格HTML字符串
	 */
	public static function print_function_info($url_params, $info)
	{
		static $odd_even = 0;

		$totals = self::$totals;
		$sort_col = self::$sort_col;
		$metrics = self::$metrics;
		$format_cbk = self::$format_cbk;
		$display_calls = self::$display_calls;
		$base_path = self::base_path();

		// Toggle $odd_or_even（斑马纹改由 CSS class，避免浅色 bgcolor 盖住深色主题文字）
		$odd_even = 1 - $odd_even;
		$echo_page = "";
		if ($odd_even) {
			$echo_page .= '<tr class="catlog-row">';
		} else {
			$echo_page .= '<tr class="catlog-row catlog-row-alt">';
		}

		$href = XhprofLib::xhprof_get_link(array_merge($url_params, ['symbol'=>$info["fn"]]));

		$echo_page .= '<td class="catlog-fn">';
		$echo_page .= self::xhprof_render_link($info["fn"], $href);
		$echo_page .= self::print_source_link($info);
		$echo_page .= "</td>\n";

		if ($display_calls) {
			// Call Count..
			$echo_page .= self::print_td_num($info["ct"], $format_cbk["ct"], ($sort_col == "ct"));
			$echo_page .= self::print_td_pct($info["ct"], $totals["ct"], ($sort_col == "ct"));
		}

		// Other metrics..
		foreach ($metrics as $metric) {
			// Inclusive metric
			$echo_page .= self::print_td_num(
				$info[$metric],
				$format_cbk[$metric],
				($sort_col == $metric)
			);
			$echo_page .= self::print_td_pct(
				$info[$metric],
				$totals[$metric],
				($sort_col == $metric)
			);

			// Exclusive Metric
			$echo_page .= self::print_td_num(
				$info["excl_" . $metric],
				$format_cbk["excl_" . $metric],
				($sort_col == "excl_" . $metric)
			);
			$echo_page .= self::print_td_pct(
				$info["excl_" . $metric],
				$totals[$metric],
				($sort_col == "excl_" . $metric)
			);
		}

		$echo_page .= "</tr>\n";
		return $echo_page;
	}

	/**
	 * 打印扁平数据单元格
	 * 生成并返回包含扁平数据的HTML单元格元素
	 * 
	 * @param array $url_params URL参数
	 * @param string $title 标题
	 * @param array $flat_data 打印数据的HTML字符串
	 * @param int $limit 显示的项目数量（可选）
	 * @return string 单元格HTML字符串
	 */
	public static function print_flat_data($url_params, $title, $flat_data, $limit)
	{

		$stats = self::$stats;
		$sortable_columns = self::$sortable_columns;
		$vwbar = self::$vwbar;
		$base_path = self::base_path();
		$size	= count($flat_data);
		if (!$limit) {							// no limit
			$limit = $size;
			$display_link = "";
		} else {
			$display_link = self::xhprof_render_link(" [ <b class=bubble>display all </b>]", XhprofLib::xhprof_get_link(array_merge($url_params, ['all'=>1])));
		}


		//$echo_page ='<h3 align=center>'.$title.' '.$display_link.'</h3><br>';
		$echo_page = '<div class="catlog-table-wrap">';
		$echo_page .= '<table class="table table-bordered catlog-detail-table">';
		$echo_page .= '<tr class="catlog-th-row" align=right>';

		foreach ($stats as $stat) {
			$desc = self::stat_description($stat);
			if (array_key_exists($stat, $sortable_columns)) {
				$href = XhprofLib::xhprof_get_link(array_merge($url_params, ['sort'=>$stat]));
				$header = self::xhprof_render_link($desc, $href);
			} else {
				$header = $desc;
			}

			if ($stat == "fn")
				$echo_page .= "<th align=left class=\"catlog-fn\">$header</th>";
			else $echo_page .= "<th " . $vwbar . ">$header</th>";
		}
		$echo_page .= "</tr>\n";

		if ($limit >= 0) {
			$limit = min($size, $limit);
			for ($i = 0; $i < $limit; $i++) {
				$echo_page .= self::print_function_info($url_params, $flat_data[$i]);
			}
		} else {
			// if $limit is negative, print abs($limit) items starting from the end
			$limit = min($size, abs($limit));
			for ($i = 0; $i < $limit; $i++) {
				$echo_page .= self::print_function_info($url_params, $flat_data[$size - $i - 1]);
			}
		}
		$echo_page .= "</table>";
		$echo_page .= "</div>";

		if ($display_link) $echo_page .= '<div style="text-align: left; padding: 2em">' . $display_link . '</div>';
		return $echo_page;
	}

	/**
	 * 生成完整报告
	 * 根据参数生成单个运行结果的报告或两个运行结果的差异报告
	 * 
	 * @paramparam array $url_params URL参数
	 * @param array $symbol_tab 所有函数的扁平数据
	 * @param string $run1 第一个运行结果的ID
	 * @param string $run2 第二个运行结果的ID（可选）
	 * @return string 生成的HTML报告
	 */
	public static function full_report($url_params, $symbol_tab, $run1, $run2)
	{
		$vwbar = self::$vwbar;
		$vbar = self::$vbar;
		$totals = self::$totals;
		$totals_1 = self::$totals_1;
		$totals_2 = self::$totals_2;
		$metrics = self::$metrics;
		$diff_mode = self::$diff_mode;
		$descriptions = self::$descriptions;
		$sort_col = self::$sort_col;
		$format_cbk = self::$format_cbk;
		$display_calls = self::$display_calls;
		$base_path = self::base_path();
		
		$echo_page = '<div class="container-fluid" style="width: 90%"> <div class="row"> <div class="col-xs-12">';	//开始
		$possible_metrics = XhprofLib::xhprof_get_possible_metrics();
		$echo_page .= '<div class="well well-sm catlog-summary">';
		if ($diff_mode) {
			$base_url_params = XhprofLib::xhprof_array_unset(XhprofLib::xhprof_array_unset($url_params,'run1'),'run2');
			$href1 = XhprofLib::xhprof_get_link(array_merge($base_url_params, ['run'=>$run1]));
			$href2 = XhprofLib::xhprof_get_link(array_merge($base_url_params, ['run'=>$run2]));

			$echo_page .= "<h3><center>Overall Diff Summary</center></h3>";
			$echo_page .= '<table class="table table-bordered catlog-summary-table" width="30%" align=center>' . "\n";
			$echo_page .= '<tr class="catlog-th-row" align=right>';
			$echo_page .= "<th></th>";
			$echo_page .= "<th $vwbar>" . self::xhprof_render_link("Run #$run1", $href1) . "</th>";
			$echo_page .= "<th $vwbar>" . self::xhprof_render_link("Run #$run2", $href2) . "</th>";
			$echo_page .= "<th $vwbar>Diff</th>";
			$echo_page .= "<th $vwbar>Diff%</th>";
			$echo_page .= '</tr>';

			if ($display_calls) {
				$echo_page .= '<tr>';
				$echo_page .= "<td>Number of Function Calls</td>";
				$echo_page .= self::print_td_num($totals_1["ct"], $format_cbk["ct"]);
				$echo_page .= self::print_td_num($totals_2["ct"], $format_cbk["ct"]);
				$echo_page .= self::print_td_num($totals_2["ct"] - $totals_1["ct"], $format_cbk["ct"], true);
				$echo_page .= self::print_td_pct($totals_2["ct"] - $totals_1["ct"], $totals_1["ct"], true);
				$echo_page .= '</tr>';
			}

			foreach ($metrics as $metric) {
				$m = $metric;
				$echo_page .= '<tr>';
				$echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
				$echo_page .= self::print_td_num($totals_1[$m], $format_cbk[$m]);
				$echo_page .= self::print_td_num($totals_2[$m], $format_cbk[$m]);
				$echo_page .= self::print_td_num($totals_2[$m] - $totals_1[$m], $format_cbk[$m], true);
				$echo_page .= self::print_td_pct($totals_2[$m] - $totals_1[$m], $totals_1[$m], true);
				$echo_page .= '<tr>';
			}
			$echo_page .= '</table>';

			//		$callgraph_report_title = '[View Regressions/Improvements using CallGraph Diff]';

		} else {
			$echo_page .= "<p><center>\n";
			
			//取信息
			$request_info = XhprofLib::getRequestLog($url_params['run']) ?: [];
			$request_uri = isset($request_info['request_uri']) ? urldecode($request_info['request_uri']) : "";
			$method = $request_info['method'] ?? "";
			$create_time_text = "";
			if (isset($request_info['create_time']) && !empty($request_info['create_time'])) $create_time_text = date('Y-m-d H:i:s', $request_info['create_time']);
			$ip = $request_info['ip'] ?? "";

			$echo_page .= '<table class="table table-bordered catlog-summary-table" cellpadding=2 cellspacing=1 width="100%" align=center>' . "\n";
			$echo_page .= '<tr class="catlog-row">';
			$echo_page .= "<td style='text-align:center;font-weight:bold;height: 36px;' colspan='8'>{$request_uri}</td>";
			$echo_page .= "</tr>";

			$echo_page .= '<tr class="catlog-row catlog-row-alt">';
			$echo_page .= "<td style='text-align:right; font-weight:bold'>请求方法：</td>";
			$echo_page .= "<td>{$method}</td>";
			$echo_page .= "<td style='text-align:right; font-weight:bold'>请求时间：</td>";
			$echo_page .= "<td>{$create_time_text}</td>";
			$echo_page .= "<td style='text-align:right; font-weight:bold'>来源IP：</td>";
			$echo_page .= "<td>{$ip}</td>";
			
			if ($display_calls) {
				$echo_page .= "<td style='text-align:right; font-weight:bold'>函数/方法调用总次数：</td>";
				$echo_page .= "<td>" . number_format($totals['ct']) . "</td>";
			}
			$echo_page .= "</tr>";
			$echo_page .= "<tr>";
			foreach ($metrics as $metric) {
				$echo_page .= "<td style='text-align:right; font-weight:bold'>"
					. str_replace("<br>", " ", self::stat_description($metric)) . "：</td>";
				$echo_page .= "<td>" . number_format($totals[$metric]) .	" "
					. $possible_metrics[$metric][1] . "</td>";
			}
			$echo_page .= "</tr>";

			$echo_page .= "</table>";
			$echo_page .= "</center></p>\n";
		}
		$echo_page .= '</div>';
		$echo_page .= '</div></div><div class="row"> <div class="col-xs-12">';
		$flat_data = [];
		foreach ($symbol_tab as $symbol => $info) {
			if(defined('DEV') === false && (stripos($symbol,'Candy') !== false || stripos($symbol,'load::') !== false)){
				continue;
			}else{
				$tmp = $info;
				$tmp["fn"] = $symbol;
				$flat_data[] = $tmp;
			}
		}
		usort($flat_data, 'self::sort_cbk');
		
		$all = false;
		$limit = 100;
		// all=1 时仍封顶，避免门户首页等大采样一次渲染数千行导致报告页极慢
		$maxAllRows = 500;
		if (!empty($url_params['all'])) {
			$all = true;
			$limit = $maxAllRows;
		}

		$desc = str_replace("<br>", " ", $descriptions[$sort_col]);
		if ($diff_mode) {
			$title = "Top 100 <i style='color:red'>Regressions</i>/"
				. "<i style='color:green'>Improvements</i>: "
				. "Sorted by $desc Diff";
			if ($all) {
				$title = "Total Diff Report (top {$maxAllRows}): Sorted by absolute value of regression/improvement in $desc";
			}
		} else {
			$title = "Displaying top $limit public static functions: Sorted by $desc";
			if ($all) {
				$title = "Sorted by $desc (capped at {$maxAllRows} rows)";
			}
		}
		$echo_page .= self::print_flat_data($url_params, $title, $flat_data, $limit);
		$echo_page .= '</div></div></div>';	//结束
		return $echo_page;
	}
	
	/**
	 * 获取提示属性
	 * 根据类型名和指标名生成提示属性
	 * 
	 * @param string $type 类型名
	 * @param string $metric 指标名
	 * @return string 提示属性
	 */
	public static function get_tooltip_attributes($type, $metric)
	{
		return "type='$type' metric='$metric'";
	}

	/**
	 * 打印子函数信息单元格
	 * 生成并返回包含提示属性的HTML单元格元素
	 * 
	 * @param array $info 子函数信息
	 * @param array $base_ct 基础调用次数
	 * @param array $base_info 基础信息
	 * @param bool $parent 是否为父函数
	 * @return string 单元格HTML字符串
	 */
	public static function pc_info($info, $base_ct, $base_info, $parent)
	{
		$sort_col = self::$sort_col;
		$metrics = self::$metrics;
		$format_cbk = self::$format_cbk;
		$display_calls = self::$display_calls;
		$type = "Child";
		if ($parent) $type = "Parent";
		if ($display_calls) {
			$mouseoverct = self::get_tooltip_attributes($type, "ct");
			/* call count */
			self::print_td_num($info["ct"], $format_cbk["ct"], ($sort_col == "ct"), $mouseoverct);
			self::print_td_pct($info["ct"], $base_ct, ($sort_col == "ct"), $mouseoverct);
		}

		/* Inclusive metric values	*/
		foreach ($metrics as $metric) {
			self::print_td_num(
				$info[$metric],
				$format_cbk[$metric],
				($sort_col == $metric),
				self::get_tooltip_attributes($type, $metric)
			);
			self::print_td_pct(
				$info[$metric],
				$base_info[$metric],
				($sort_col == $metric),
				self::get_tooltip_attributes($type, $metric)
			);
		}
	}

	/**
	 * 打印子函数信息单元格
	 * 生成并返回包含子函数信息的HTML单元格元素
	 * 
	 * @param array $url_params URL参数
	 * @param array $results 子函数信息数组
	 * @param array $base_ct 基础调用次数
	 * @param array $base_info 基础信息
	 * @param bool $parent 是否为父函数
	 * @return string 单元格HTML字符串
	 */
	public static function print_pc_array($url_params, $results, $base_ct, $base_info, $parent, $run1, $run2) {
		$base_path = self::base_path();
		$title = 'Child public static function';
		if ($parent) $title = 'Parent public static function';
		if (count($results) > 1) $title .= 's';
		$echo_page = '<tr class="catlog-section-row"><td colspan="99">';
		$echo_page .= "<b><i><center>" . $title . "</center></i></b>";
		$echo_page .= "</td></tr>";

		$odd_even = 0;
		foreach ($results as $info) {
			$href = XhprofLib::xhprof_get_link(array_merge($url_params, ['symbol'=>$info["fn"]]));

			$odd_even = 1 - $odd_even;
			if ($odd_even) {
				$echo_page .= '<tr class="catlog-row">';
			} else {
				$echo_page .= '<tr class="catlog-row catlog-row-alt">';
			}

			$echo_page .= '<td class="catlog-fn">' . self::xhprof_render_link($info["fn"], $href);
			$echo_page .= self::print_source_link($info);
			$echo_page .= "</td>";
			$echo_page .= self::pc_info($info, $base_ct, $base_info, $parent);
			$echo_page .= "</tr>";
		}
		return $echo_page;
	}

	/**
	 * 打印源文件链接单元格
	 * 生成并返回包含源文件链接的HTML单元格元素
	 * 
	 * @param array $info 子函数信息
	 * @return string 单元格HTML字符串
	 */
	public static function print_source_link($info)
	{
		$echo_page = "";
		if (strncmp($info['fn'], 'run_init', 8) && $info['fn'] !== 'main()') {
			if (Xhprof::$symbol_lookup_url) {
				$link = self::xhprof_render_link(
					'source',
					Xhprof::$symbol_lookup_url . '?symbol=' . rawurlencode($info["fn"])
				);
				$echo_page .= ' (' . $link . ')';
			}
		}
		return $echo_page;
	}
	
	/**
	 * 打印指标摘要单元格
	 * 生成并返回包含指标摘要的HTML单元格元素
	 * 
	 * @param array $symbol_info 指标信息数组
	 * @param string $stat 指标名
	 * @param array $symbol_info[$stat] 指标信息数组
	 */
	public static function print_symbol_summary($symbol_info, $stat, $base)
	{

		$val = $symbol_info[$stat];
		$desc = str_replace("<br>", " ", self::stat_description($stat));

		$echo_page = "$desc: </td>";
		$echo_page .= number_format($val);
		$echo_page .= " (" . self::pct($val, $base) . "% of overall)";
		if (substr($stat, 0, 4) == "excl") {
			$func_base = $symbol_info[str_replace("excl_", "", $stat)];
			$echo_page .= " (" . self::pct($val, $func_base) . "% of this public static function)";
		}
		$echo_page .= "<br>";
		return $echo_page;
	}
	
	/**
	 * 打印指标摘要单元格
	 * 生成并返回包含指标摘要的HTML单元格元素
	 * 
	 * @param array $url_params URL参数
	 * @param array $run_data 运行数据
	 * @param array $symbol_info 指标信息数组
	 * @param string $rep_symbol 指标名
	 * @param string $run1 第一个运行结果的ID
	 * @param array $symbol_info1 第一个运行结果的指标信息数组
	 * @param int $run2 第二个运行结果的ID
	 * @param array $symbol_info2 第二个运行结果的指标信息数组
	 * @return string 单元格HTML字符串
	 */
	public static function symbol_report($url_params, $run_data, $symbol_info, $rep_symbol, $run1, $symbol_info1 = null, $run2 = 0, $symbol_info2 = null) {
		$vwbar = self::$vwbar;
		$vbar = self::$vbar;
		$totals = self::$totals;
		$pc_stats = self::$pc_stats;
		$sortable_columns = self::$sortable_columns;
		$metrics = self::$metrics;
		$diff_mode = self::$diff_mode;
		$descriptions = self::$descriptions;
		$format_cbk = self::$format_cbk;
		$sort_col = self::$sort_col;
		$display_calls = self::$display_calls;
		$base_path = self::base_path();

		$echo_page = '<div class="container-fluid" style="width: 90%;"> <div class="row"> <div class="col-xs-12">';	//开始
		$possible_metrics = XhprofLib::xhprof_get_possible_metrics();
		$diff_text = "";
		$regr_impr = "";
		if ($diff_mode) {
			$diff_text = "<b>Diff</b>";
			$regr_impr = "<i style='color:red'>Regression</i>/<i style='color:green'>Improvement</i>";
		}

		if ($diff_mode) {

			$base_url_params = XhprofLib::xhprof_array_unset(XhprofLib::xhprof_array_unset($url_params, 'run1'), 'run2');
			$href1 = XhprofLib::xhprof_get_link(array_merge($base_url_params, ['run'=>$run1]));
			$href2 = XhprofLib::xhprof_get_link(array_merge($base_url_params, ['run'=>$run2]));

			$echo_page .= "<h3 align=center>$regr_impr summary for $rep_symbol<br><br></h3>";
			$echo_page .= '<table class="table table-bordered catlog-summary-table" width="30%" align=center>' . "\n";
			$echo_page .= '<tr class="catlog-th-row" align=right>';
			$echo_page .= "<th align=left class=\"catlog-fn\">$rep_symbol</th>";
			$echo_page .= "<th $vwbar><a href=" . $href1 . ">Run #$run1</a></th>";
			$echo_page .= "<th $vwbar><a href=" . $href2 . ">Run #$run2</a></th>";
			$echo_page .= "<th $vwbar>Diff</th>";
			$echo_page .= "<th $vwbar>Diff%</th>";
			$echo_page .= '</tr>';
			$echo_page .= '<tr class="catlog-row">';

			if ($display_calls) {
				$echo_page .= "<td>Number of Function Calls</td>";
				$echo_page .= self::print_td_num($symbol_info1["ct"], $format_cbk["ct"]);
				$echo_page .= self::print_td_num($symbol_info2["ct"], $format_cbk["ct"]);
				$echo_page .= self::print_td_num(
					$symbol_info2["ct"] - $symbol_info1["ct"],
					$format_cbk["ct"],
					true
				);
				$echo_page .= self::print_td_pct(
					$symbol_info2["ct"] - $symbol_info1["ct"],
					$symbol_info1["ct"],
					true
				);
				$echo_page .= '</tr>';
			}


			foreach ($metrics as $metric) {
				$m = $metric;

				// Inclusive stat for metric
				$echo_page .= '<tr>';
				$echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
				$echo_page .= self::print_td_num($symbol_info1[$m], $format_cbk[$m]);
				$echo_page .= self::print_td_num($symbol_info2[$m], $format_cbk[$m]);
				$echo_page .= self::print_td_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], true);
				$echo_page .= self::print_td_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], true);
				$echo_page .= '</tr>';

				// AVG (per call) Inclusive stat for metric
				$echo_page .= '<tr>';
				$echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . " per call </td>";
				$avg_info1 = 'N/A';
				$avg_info2 = 'N/A';
				if ($symbol_info1['ct'] > 0) $avg_info1 = ($symbol_info1[$m] / $symbol_info1['ct']);
				if ($symbol_info2['ct'] > 0) $avg_info2 = ($symbol_info2[$m] / $symbol_info2['ct']);
				$echo_page .= self::print_td_num($avg_info1, $format_cbk[$m]);
				$echo_page .= self::print_td_num($avg_info2, $format_cbk[$m]);
				$echo_page .= self::print_td_num($avg_info2 - $avg_info1, $format_cbk[$m], true);
				$echo_page .= self::print_td_pct($avg_info2 - $avg_info1, $avg_info1, true);
				$echo_page .= '</tr>';

				// Exclusive stat for metric
				$m = "excl_" . $metric;
				$echo_page .= '<tr style="border-bottom: 1px solid black;">';
				$echo_page .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
				$echo_page .= self::print_td_num($symbol_info1[$m], $format_cbk[$m]);
				$echo_page .= self::print_td_num($symbol_info2[$m], $format_cbk[$m]);
				$echo_page .= self::print_td_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], true);
				$echo_page .= self::print_td_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], true);
				$echo_page .= '</tr>';
			}
			$echo_page .= '</table>';
		}

		$echo_page .= "<h4><center>";
		$echo_page .= "Parent/Child $regr_impr report for <b>$rep_symbol</b>";

		$callgraph_href = "$base_path/CallGraph.php?"
			. http_build_query(XhprofLib::xhprof_array_set($url_params, 'func', $rep_symbol));

		$echo_page .= "</center></h4>";

		// Parent/Child 明细表（去掉内联 bgcolor / nobr，改由 catlog-ui 主题控制）
		$echo_page .= '<table class="table table-bordered catlog-detail-table" width="100%" align=center>' . "\n";
		$echo_page .= '<tr class="catlog-th-row" align=right>';

		foreach ($pc_stats as $stat) {
			$desc = self::stat_description($stat);
			if (array_key_exists($stat, $sortable_columns)) {
				$href = XhprofLib::xhprof_get_link(array_merge($url_params, ['sort'=>$sort]));
				$header = self::xhprof_render_link($desc, $href);
			} else {
				$header = $desc;
			}

			if ($stat == "fn")
				$echo_page .= "<th align=left class=\"catlog-fn\">$header</th>";
			else $echo_page .= "<th " . $vwbar . ">$header</th>";
		}
		$echo_page .= "</tr>";

		$echo_page .= '<tr class="catlog-section-row"><td colspan="99">';
		$echo_page .= "<b><i><center>Current Function</center></i></b>";
		$echo_page .= "</td></tr>";

		$echo_page .= '<tr class="catlog-row">';
		// make this a self-reference to facilitate copy-pasting snippets to e-mails
		$echo_page .= '<td class="catlog-fn"><a href="">' . $rep_symbol . '</a>';
		$echo_page .= self::print_source_link(['fn' => $rep_symbol]);
		$echo_page .= "</td>";

		if ($display_calls) {
			// Call Count
			$echo_page .= self::print_td_num($symbol_info["ct"], $format_cbk["ct"]);
			$echo_page .= self::print_td_pct($symbol_info["ct"], $totals["ct"]);
		}

		// Inclusive Metrics for current public static function
		foreach ($metrics as $metric) {
			$echo_page .= self::print_td_num($symbol_info[$metric], $format_cbk[$metric], ($sort_col == $metric));
			$echo_page .= self::print_td_pct($symbol_info[$metric], $totals[$metric], ($sort_col == $metric));
		}
		$echo_page .= "</tr>";
		$echo_page .= '<tr class="catlog-row catlog-row-alt">';
		$echo_page .= "<td style='text-align:right;color:var(--cl-accent-2)'>"
			. "Exclusive Metrics $diff_text for Current Function</td>";

		if ($display_calls) {
			// Call Count
			$echo_page .= "<td $vbar></td>";
			$echo_page .= "<td $vbar></td>";
		}

		// Exclusive Metrics for current public static function
		foreach ($metrics as $metric) {
			$echo_page .= self::print_td_num(
				$symbol_info["excl_" . $metric],
				$format_cbk["excl_" . $metric],
				($sort_col == $metric),
				self::get_tooltip_attributes("Child", $metric)
			);
			$echo_page .= self::print_td_pct(
				$symbol_info["excl_" . $metric],
				$symbol_info[$metric],
				($sort_col == $metric),
				self::get_tooltip_attributes("Child", $metric)
			);
		}
		$echo_page .= "</tr>";

		// list of callers/parent public static functions
		$results = [];
		$base_ct = 0;
		if ($display_calls) $base_ct = $symbol_info["ct"];
		foreach ($metrics as $metric) {
			$base_info[$metric] = $symbol_info[$metric];
		}
		foreach ($run_data as $parent_child => $info) {
			[$parent, $child] = XhprofLib::xhprof_parse_parent_child($parent_child);
			if (($child == $rep_symbol) && ($parent)) {
				$info_tmp = $info;
				$info_tmp["fn"] = $parent;
				$results[] = $info_tmp;
			}
		}
		usort($results, 'self::sort_cbk');

		if (count($results) > 0) {
			$echo_page .= self::print_pc_array($url_params, $results, $base_ct, $base_info, true, $run1, $run2);
		}

		// list of callees/child public static functions
		$results = [];
		$base_ct = 0;
		foreach ($run_data as $parent_child => $info) {
			[$parent, $child] = XhprofLib::xhprof_parse_parent_child($parent_child);
			if ($parent == $rep_symbol) {
				$info_tmp = $info;
				$info_tmp["fn"] = $child;
				$results[] = $info_tmp;
				if ($display_calls) $base_ct += $info["ct"];
			}
		}
		usort($results, 'self::sort_cbk');

		if (count($results)) {
			$echo_page .= self::print_pc_array($url_params, $results, $base_ct, $base_info, false, $run1, $run2);
		}

		$echo_page .= "</table>";
		$echo_page .= '</div></div></div>';	//结束

		// These will be used for pop-up tips/help.
		// Related javascript code is in: xhprof_report.js
		$echo_page .= "\n";
		$echo_page .= '<script language="javascript">' . "\n";
		$echo_page .= "var func_name = '\"" . $rep_symbol . "\"';\n";
		$echo_page .= "var total_child_ct	= " . $base_ct . ";\n";
		if ($display_calls) $echo_page .= "var func_ct	 = " . $symbol_info["ct"] . ";\n";
		$echo_page .= "var func_metrics = new [];\n";
		$echo_page .= "var metrics_col	= new [];\n";
		$echo_page .= "var metrics_desc	= new [];\n";
		if ($diff_mode) {
			$echo_page .= "var diff_mode = true;\n";
		} else {
			$echo_page .= "var diff_mode = false;\n";
		}
		$column_index = 3; // First three columns are Func Name, Calls, Calls%
		foreach ($metrics as $metric) {
			$echo_page .= "func_metrics[\"" . $metric . "\"] = " . round($symbol_info[$metric]) . ";\n";
			$echo_page .= "metrics_col[\"" . $metric . "\"] = " . $column_index . ";\n";
			$echo_page .= "metrics_desc[\"" . $metric . "\"] = \"" . $possible_metrics[$metric][2] . "\";\n";

			// each metric has two columns..
			$column_index += 2;
		}
		$echo_page .= '</script>';
		$echo_page .= "\n";
		return	$echo_page;
	}
	
	/**
	 * 打印单运行运行报告
	 * 生成并返回包含单运行运行报告的HTML字符串
	 * 
	 * @param array $url_params URL参数
	 * @param array $xhprof_data XHProf数据
	 * @param string $run_desc 运行描述
	 * @param string $rep_symbol 指标名
	 * @param string $sort 排序字段
	 * @param string $run 运行ID
	 * @return string 单元格HTML字符串
	 */
	public static function profiler_single_run_report($url_params, $xhprof_data, $run_desc, $rep_symbol, $sort, $run) {
		XhprofLib::init_metrics($xhprof_data, $rep_symbol, $sort, false);
		return self::profiler_report($url_params, $rep_symbol, $run, $run_desc, $xhprof_data);
	}
	
	/**
	 * 打印差异运行报告
	 * 生成并返回包含差异运行报告的HTML字符串
	 * 
	 * @param array $url_params URL参数
	 * @param array $xhprof_data1 XHProf数据1
	 * @param string $run1_desc 运行描述1
	 * @param string $run2_desc 运行描述2
	 * @param array $xhprof_data2 XHProf数据2
	 * @return string 单元格HTML字符串
	 */
	public static function profiler_diff_report( $url_params, $xhprof_data1, $run1_desc, $xhprof_data2, $run2_desc, $rep_symbol, $sort, $run1, $run2) {
		// Initialize what metrics we'll display based on data in Run2
		XhprofLib::init_metrics($xhprof_data2, $rep_symbol, $sort, true);
		return self::profiler_report($url_params, $rep_symbol, $run1, $run1_desc, $xhprof_data1, $run2, $run2_desc, $xhprof_data2);
	}
	
	/**
	 * 打印XHProf报告
	 * 生成并返回包含XHProf报告的HTML字符串
	 * 
	 * @param array $url_params URL参数
	 * @param string $source 源文件
	 * @param string $run 运行ID
	 * @param string $wts 权重字符串
	 * @param string $symbol 指标名
	 * @param string $sort 排序字段
	 * @param string $run1 运行ID1
	 * @param string $run2 运行ID2
	 * @return string 单元格HTML字符串
	 */
	public static function displayXHProfReport($url_params, $source, $run, $wts, $symbol, $sort, $run1, $run2) {
		$data = self::show_nav($url_params);
		if ($run) {															// specific run to display?
			$runs_array = explode(",", $run);
			if (count($runs_array) == 1) {
				$xhprof_data = XHProfRunsDefault::get_run($runs_array[0], $source, $description);
			} else {
				$wts_array = null;
				if (!empty($wts)) $wts_array	= explode(",", $wts);
				$datas = XhprofLib::xhprof_aggregate_runs($runs_array, $wts_array, $source, false);
				$xhprof_data = $datas['raw'];
				$description = $datas['description'];
			}

			$data .= self::profiler_single_run_report($url_params, $xhprof_data, $description, $symbol, $sort, $run);
		} else if ($run1 && $run2) {									// diff report for two runs
			$xhprof_data1 = XHProfRunsDefault::get_run($run1, $source, $description1);
			$xhprof_data2 = XHProfRunsDefault::get_run($run2, $source, $description2);
			$data .= self::profiler_diff_report($url_params, $xhprof_data1, $description1, $xhprof_data2, $description2, $symbol, $sort, $run1, $run2);
		} else {
			$data .= XHProfRunsDefault::list_runs();
		}
		return $data;
	}

	/**
	 * 打印导航栏
	 * 生成并返回包含导航栏的HTML字符串
	 * 
	 * @param array $url_params URL参数
	 * @return string 单元格HTML字符串
	 */
	public static function show_nav($url_params)
	{
		$base_path = self::base_path();
		$base_url_params = XhprofLib::xhprof_array_unset($url_params, 'symbol');
		$top_link_query_string = XhprofLib::xhprof_get_link($base_url_params);
		$li_html = "";
		if (isset($url_params['run']) && isset($url_params['symbol'])) {
			$li_html = <<<HTML
<li><a href="{$base_path}">首页</a></li>
<li><a href="{$top_link_query_string}">运行报告</a></li>
<li class="active"><a >方法详情</a></li>
HTML;
		} else if (isset($url_params['run'])) {
			$li_html = <<<HTML
<li><a href="{$base_path}">首页</a></li>
<li class="active"><a href="{$top_link_query_string}">运行报告</a></li>
HTML;
		} else {
			$li_html = <<<HTML
<li class="active"><a href="{$base_path}">首页</a></li>
HTML;
		}

		$title_html = <<<HTML
<nav class="navbar navbar-inverse navbar-static-top">
	<div class="container" style="width: 90%;">
		<div class="navbar-header">
			<button class="navbar-toggle" data-toggle="collapse" data-target="#response">
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
			</button>
			<a class="navbar-brand">XHProf性能分析</a>
		</div>

		<!-- 移动端响应式 -->
		<div class="collapse navbar-collapse" id="response">
		<ul class="nav navbar-nav navbar-left">
			{$li_html}
		</ul>

		<ul class="nav navbar-nav navbar-right">
			<li><a href="https://github.com/erikwang2013/xhprof-webman" target="_blank" title="感谢Star支持！比心心！">>>> GitHub</a></li>
		</ul>
		</div>
	</div>
</nav>
HTML;

		return $title_html;
	}
}
