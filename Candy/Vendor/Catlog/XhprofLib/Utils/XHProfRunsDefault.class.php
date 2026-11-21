<?php

declare(strict_types=1);

namespace Catlog\XhprofLib\Utils;

use \Catlog\Xhprof;

/**
 * XHProf运行数据存储和检索类
 * 实现XHProfRuns接口，使用Redis作为存储引擎
 * 负责性能分析数据的保存、获取和管理
 *
 * @author System
 * @version 1.0
 * @since 2026-01-16
 */
class XHProfRunsDefault implements XHProfRuns
{
    /**
     * 存储目录
     * @var string
     */
    protected static $dir = null;

    /**
     * 构造函数
     * 初始化存储目录，优先使用传入的目录，其次使用php.ini配置，最后使用默认目录
     * 
     * @param string|null $dir 存储目录路径
     */
    public function __construct($dir = null)
    {
        if (empty($dir)) {
            $dir = ini_get("xhprof.output_dir");
            if (empty($dir)) {
                $dir = "/tmp";
                XhprofLib::xhprof_error("Warning: Must specify directory location for XHProf runs. " .
                    "Trying {$dir} as default. You can either pass the " .
                    "directory location as an argument to the constructor " .
                    "for XHProfRuns_Default() or set xhprof.output_dir " .
                    "ini param.");
            }
        }
        self::$dir = $dir;
    }

    /**
     * 获取指定ID的性能分析运行数据
     * 
     * @param string $run_id 运行ID
     * @param string $type 命名空间
     * @param string $run_desc 运行描述（引用传递）
     * @return array 性能分析数据
     */
    public static function get_run($run_id, $type, &$run_desc)
    {
        $cache = self::get_cache();
        $run_desc = "XHProf Run (Namespace=$type)";
        $res = $cache->get(Xhprof::$key_prefix . ':xhprof_log:' . $run_id);
        if ($res === null || $res === false || $res === '') {
            return [];
        }
        if (is_array($res)) {
            return $res;
        }
        if (is_string($res)) {
            $json = json_decode($res, true);
            if (is_array($json)) {
                return $json;
            }
            // 兼容旧 serialize 数据
            $legacy = @unserialize($res, ['allowed_classes' => false]);
            return is_array($legacy) ? $legacy : [];
        }
        return [];
    }

    /**
     * 保存性能分析运行数据
     * 实现XHProfRuns接口的save_run方法
     * 
     * @param array $xhprof_data 性能分析数据
     * @param string $type 命名空间
     * @param string|null $run_id 运行ID（可选）
     * @return string|false 运行ID或false（如果未保存）
     */
    public static function save_run($xhprof_data, $type, $run_id = null)
    {
        //根据响应时间判断是否需要记录
        if (Xhprof::$time_limit > 0 && $xhprof_data['main()']['wt'] < (Xhprof::$time_limit * 1000 * 1000)) return false;
        
        //根据忽略配置判断是否忽略当前请求
        if (!XhprofLib::isIgnore()) return false;
        
        //控制日志长度
        self::_checkLogNum();
        
        //数据存储至redis
        $run_id = self::_saveToCache($xhprof_data);
        return $run_id;
    }
    
    /**
     * 控制日志长度
     * @return bool
     */
    protected static function _checkLogNum()
    {
		$cache = self::get_cache();
        $num = $cache->incr(Xhprof::$key_prefix . ":run_id_num");
        if ($num > Xhprof::$log_num) {
            $old_run_id = $cache->rPop(Xhprof::$key_prefix . ':run_id');
            if ($old_run_id !== null && $old_run_id !== false && $old_run_id !== '') {
                $cache->delete(Xhprof::$key_prefix . ':request_log:' . $old_run_id);
                $cache->delete(Xhprof::$key_prefix . ':xhprof_log:' . $old_run_id);
            }
            $cache->decr(Xhprof::$key_prefix . ':run_id_num');
        }
        return true;
    }

    /**
     * 数据存储至 Cache（List + KV）
     * @return string
     */
    protected static function _saveToCache($xhprof_data)
    {
        $run_id = class_exists(\Candy\Extend\Snowflake::class)
            ? (string)\Candy\Extend\Snowflake::id()
            : str_replace('.', '', uniqid('', true));
		$cache = self::get_cache();
        $cache->lPush(Xhprof::$key_prefix . ":run_id", [$run_id]);
        $wt = 0;
        $mu = 0;
        if (!empty($xhprof_data['main()']['wt']) && $xhprof_data['main()']['wt'] > 0) {
            $wt = round($xhprof_data['main()']['wt'] / 1000000, 4);
            $mu = round($xhprof_data['main()']['mu'] / 1024 / 1024, 4);
        }
		
        $row = [
            'request_uri' => (isSsl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
            'method'      => C('METHOD') ?: ($_SERVER['REQUEST_METHOD'] ?? ''),
            'wt'          => $wt,
            'mu'          => $mu,
            'ip'          => XhprofLib::xhprof_get_ip(),
            'create_time' => time(),
        ];
        $key = Xhprof::$key_prefix . ':request_log:' . $run_id;
        $cache->set($key, json_encode($row, JSON_UNESCAPED_UNICODE));
        $key = Xhprof::$key_prefix . ':xhprof_log:' . $run_id;
        $payload = json_encode($xhprof_data, JSON_UNESCAPED_UNICODE);
        if ($payload !== false && $payload !== '') {
            $cache->set($key, $payload);
        }
        return $run_id;
    }
    
    /**
     * 生成性能分析运行列表的HTML表格
     * 从Redis中获取所有请求数据并生成分页表格
     * 
     * @return string HTML表格字符串
     */
    public static function list_runs2()
    {
        $echo_page = "<meta charset='utf-8'>";
        $echo_page .= "<hr/>Existing runs:\n<ul>\n";
        $echo_page .= '<li><small class="small_filemtime">请求时间</small><small class="small_wt">耗时(s)</small><small class="small_wt">内存(MB)</small><small class="small_log">xhprof日志</small><small class="small_method">Method</small><small>请求url</small></li>';
        //取所有请求数据
		$cache = self::get_cache();
        $run_id_lists = $cache->lRange(Xhprof::$key_prefix . ':run_id', 0, Xhprof::$log_num);
        foreach ($run_id_lists as $run_id) {
            $res = $cache->get(Xhprof::$key_prefix . ":request_log:" . $run_id);
            if (!$res) continue;
            $request_arr = is_array($res) ? $res : json_decode((string)$res, true);
            if (!is_array($request_arr)) continue;
            //耗时是否标红显示
            $wtClass = $request_arr['wt'] > Xhprof::$view_wtred ? "red" : "";
            $echo_page .= '<li><small class="small_filemtime">'
                . date("Y-m-d H:i:s", $request_arr['create_time'])
                . '</small><small class="small_wt ' . $wtClass . '">' . $request_arr['wt'] . '</small></small><small class="small_wt">' . $request_arr['mu'] . '</small><small class="small_log"><a href="' . XhprofLib::xhprof_get_link(['run'=>$run_id,'source'=>'xhprof_foo','requrl'=>$request_arr['request_uri']]) . '">'
                . $run_id . "</a></small>"
                . '<small class="small_method">' . $request_arr['method'] . '</small>'
                . "<small>" . $request_arr['request_uri'] . "</small></li>\n";
        }
        $echo_page .= "</ul>\n";
        return $echo_page;
    }
	
	/**
     * 获取缓存实例
     * 用于操作Redis缓存
     * 
     * @return object 缓存实例
     */
    public static function get_cache(){
        $cache = \Candy\Core\Cache::store();
        if (!is_object($cache)) {
            \Candy\Core\Debug::showMessage('需要开启 Cache 才可以使用 Catlog！', 'Cache');
        }
        return $cache;
    }

    /**
     * 生成性能分析运行列表的HTML表格
     * 从Redis中获取所有请求数据并生成分页表格
     * 
     * @return string HTML表格字符串
     */
    public static function list_runs()
    {
        $cache = self::get_cache();
        
        // 取所有请求数据
        $run_id_lists = $cache->lRange(Xhprof::$key_prefix . ':run_id', 0, Xhprof::$log_num);
        $table_html = "";
        
        foreach ($run_id_lists as $run_id) {
            $res = $cache->get(Xhprof::$key_prefix . ":request_log:" . $run_id);
            if (!$res) continue;
            
            $request_arr = is_array($res) ? $res : json_decode((string)$res, true);
            if (!is_array($request_arr)) continue;
            
            // 根据耗时判断是否需要标红显示
            $method = strtoupper((string)($request_arr['method'] ?? ''));
            $pillClass = $method === 'POST' ? 'method-pill is-post' : 'method-pill';
            $wtClass = ((float)($request_arr['wt'] ?? 0) > Xhprof::$view_wtred) ? 'red num' : 'num';
            $uri = htmlspecialchars((string)($request_arr['request_uri'] ?? ''), ENT_QUOTES, 'UTF-8');
            // 默认进详情只展示 Top 函数（无 all）；全量渲染对门户首页等大采样极慢
            $link = XhprofLib::xhprof_get_link([
                'run' => $run_id,
                'source' => 'xhprof_foo',
                'requrl' => $request_arr['request_uri'],
            ]);

            $tr = '<tr>'
                . '<td><span class="' . $pillClass . '">' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '</span></td>'
                . '<td><a href="' . $link . '">' . $uri . '</a></td>'
                . '<td class="num">' . date("Y-m-d H:i:s", (int)($request_arr['create_time'] ?? time())) . '</td>'
                . '<td class="' . $wtClass . '">' . htmlspecialchars((string)($request_arr['wt'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="num">' . htmlspecialchars((string)($request_arr['mu'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="num">' . htmlspecialchars((string)($request_arr['ip'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
            $table_html .= $tr;
        }

        $str_html = <<<HTML
<table id="table_id_example" class="table table-hover catlog-runs">
    <thead>
        <tr>
            <th width="72">方法</th>
            <th>请求地址</th>
            <th width="170">请求时间</th>
            <th width="110">耗时 (s)</th>
            <th width="120">内存 (MB)</th>
            <th width="120">IP</th>
        </tr>
    </thead>
    <tbody>
        {$table_html}
    </tbody>
</table>
HTML;
        return $str_html;
    }
}