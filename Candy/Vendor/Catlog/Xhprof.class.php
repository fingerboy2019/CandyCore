<?php
/***
 * Xhprof 性能分析入口
 *
 * 业务说明：DEBUG/开关控制下采样；数据写入 Cache；报告经 view_xhprof 输出（可鉴权）
 * UI 静态资源直接引用 /Candy/Vendor/Catlog/static（调试工具，一般不挂生产）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 11:36:27 $
 *
 * @package Catlog
 */

declare(strict_types=1);

namespace Catlog;

use Catlog\XhprofLib\Display\XhprofDisplay;
use Catlog\XhprofLib\Utils\XHProfRunsDefault;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Xhprof
{
	/** @var float 仅记录响应超过指定秒数的请求（0=全部） */
	public static $time_limit = 0;

	/** @var array<int,string> 忽略的 URL */
	public static $ignore_url_arr = ['/test'];

	/** @var string 缓存键前缀 */
	public static $key_prefix = 'xhprof';

	/** @var int 仅保留最近 N 条 */
	public static $log_num = 1000;

	/** @var float 列表耗时超过该秒数标红 */
	public static $view_wtred = 3;

	/**
	 * UI 静态资源 URL 前缀（包内目录，直接 Web 访问）
	 *
	 * @var string
	 */
	public static $ui_html = '/Candy/Vendor/Catlog/static';

	/** @var string 符号查找 URL */
	public static $symbol_lookup_url = '';

	/** @var bool 是否已启动采样 */
	public static $started = false;

	/**
	 * 启动性能分析（Hook：app_begin）
	 *
	 * @return void
	 */
	public static function start(): void
	{
		Bootstrap::applyConfig();

		if (!Bootstrap::enabled() || self::$started) {
			return;
		}

		// 【debug 硬阻挡】采样属于开发期工具：只有 DEBUG 生效时才允许启动。
		// 全量函数级采样（NO_BUILTINS + CPU + MEMORY）每请求约 3ms、并产生一次压缩落库，
		// 直接落在响应时间上；生产与普通访问一律不采，不受 require_debug 配置影响。
		if (!isDebug()) {
			return;
		}
		if (!extension_loaded('xhprof')) {
			return;
		}
		if (!Bootstrap::cacheReady()) {
			return;
		}

		C('XHPROF_ON', null);
		\xhprof_enable(XHPROF_FLAGS_NO_BUILTINS | XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
		self::$started = true;
	}

	/**
	 * 停止并落库（Hook：app_end / app_stop）
	 *
	 * @return void
	 */
	public static function stop(): void
	{
		if (!self::$started) {
			return;
		}
		if (!is_null(C('XHPROF_ON'))) {
			return;
		}

		C('XHPROF_ON', true);
		self::$started = false;

		if (!function_exists('xhprof_disable')) {
			return;
		}

		$xhprof_data = \xhprof_disable();
		if (!is_array($xhprof_data) || $xhprof_data === []) {
			return;
		}

		XHProfRunsDefault::save_run($xhprof_data, 'xhprof_foo');
	}

	/**
	 * 读取报告 token
	 *
	 * 优先取 URL 查询串中的 xhprof（即使 PATHINFO 也带了同名参数）
	 * 避免详情页搜索拼成「旧 /xhprof/token.html?xhprof=新」时仍解析旧 token
	 *
	 * @return string|null
	 */
	private static function requestXhprofToken(): ?string
	{
		$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
		$query = parse_url($uri, PHP_URL_QUERY);
		if (is_string($query) && $query !== '') {
			$qs = [];
			parse_str($query, $qs);
			if (!empty($qs['xhprof']) && is_string($qs['xhprof'])) {
				return $qs['xhprof'];
			}
		}
		if (function_exists('I')) {
			$v = I('get.xhprof', null);
			return is_string($v) && $v !== '' ? $v : null;
		}
		return isset($_GET['xhprof']) && is_string($_GET['xhprof']) ? $_GET['xhprof'] : null;
	}

	/**
	 * 展示报告（Hook：view_xhprof）
	 *
	 * @return void
	 */
	public static function showResult(): void
	{
		Bootstrap::applyConfig();
		C('XHPROF_ON', true);

		if (!Bootstrap::authorizeReport()) {
			\Candy\Core\Respond::create(self::authFormHtml(), 'html', 401)->send();
			return;
		}

		$run = $wts = $symbol = $sort = $run1 = $run2 = $source = $params = null;
		// 查询串中的 xhprof 优先于 PATHINFO，避免「旧 path + ?xhprof=新」时仍读到旧 token
		$xhprof = self::requestXhprofToken();
		if ($xhprof !== null && $xhprof !== '') {
			$decoded = is_string($xhprof)
				? (function_exists('json') ? json(base64_decode($xhprof, true) ?: '', 'un') : json_decode(base64_decode($xhprof, true) ?: '', true))
				: null;
			if (is_array($decoded)) {
				$run = $decoded['run'] ?? null;
				$wts = $decoded['wts'] ?? null;
				$symbol = $decoded['symbol'] ?? null;
				$sort = $decoded['sort'] ?? null;
				$run1 = $decoded['run1'] ?? null;
				$run2 = $decoded['run2'] ?? null;
				$source = $decoded['source'] ?? null;
				$params = $decoded;
			}
		}

		$ui = rtrim((string)self::$ui_html, '/') ?: '/Candy/Vendor/Catlog/static';
		$body = XhprofDisplay::displayXHProfReport($params, $source, $run, $wts, $symbol, $sort, $run1, $run2);
		$isDetail = $run !== null && $run !== '';

		$html = '<!DOCTYPE html><html lang="zh-CN"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Catlog · ' . ($isDetail ? '运行详情' : '采样列表') . '</title>'
			. XhprofDisplay::xhprof_include_js_css($ui)
			. '</head><body class="catlog-ui">'
			. self::shellOpen($isDetail ? '运行详情' : '采样列表', $isDetail ? '函数耗时 / 内存 / 调用关系' : '最近请求性能采样')
			. '<div class="catlog-panel"><div class="catlog-panel-title">'
			. '<h1>' . ($isDetail ? '运行详情' : '采样列表') . '</h1>'
			. '<p>' . ($isDetail ? '点击列头可排序' : '点击地址进入函数级分析') . '</p>'
			. '</div>'
			. $body
			. '</div>'
			. self::shellClose()
			. '<script>jQuery(function($){ var $t=$("#table_id_example"); if($t.length && $.fn.DataTable && !$.fn.DataTable.isDataTable($t)){ $t.DataTable({order:[[2,"desc"]], pageLength:25}); } });</script>'
			. '</body></html>';

		\Candy\Core\Respond::create($html, 'html', 200)->send();
	}

	/**
	 * 鉴权页 HTML
	 *
	 * @return string
	 */
	private static function authFormHtml(): string
	{
		$action = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');
		$ui = rtrim((string)self::$ui_html, '/') ?: '/Candy/Vendor/Catlog/static';
		$css = htmlspecialchars($ui . '/css/catlog-ui.css', ENT_QUOTES, 'UTF-8');

		return '<!DOCTYPE html><html lang="zh-CN"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Catlog · 鉴权</title>'
			. '<link rel="stylesheet" href="' . $css . '">'
			. '</head><body class="catlog-ui">'
			. '<div class="catlog-auth"><div class="catlog-auth-card">'
			. '<div class="catlog-brand"><div class="catlog-mark">CL</div>'
			. '<div class="catlog-brand-text"><strong>CATLOG</strong><span>Performance Profiler</span></div></div>'
			. '<h1>进入分析台</h1>'
			. '<p class="hint">查看 Xhprof 采样需要验证访问密码</p>'
			. '<form method="post" action="' . $action . '">'
			. '<label class="catlog-field"><span>Password</span>'
			. '<input type="password" name="password" autofocus autocomplete="current-password" placeholder="••••••••"></label>'
			. '<button type="submit" class="catlog-btn">解锁报告</button>'
			. '</form>'
			. '<p class="catlog-auth-foot">密码配置：<code>Source/Inc/Catlog.inc.php</code> → <code>auth.password</code></p>'
			. '</div></div></body></html>';
	}

	/**
	 * 页面顶栏
	 *
	 * @param string $title 标题
	 * @param string $sub   副标题
	 * @return string
	 */
	private static function shellOpen(string $title, string $sub): string
	{
		$now = date('Y-m-d H:i:s');
		return '<div class="catlog-shell">'
			. '<header class="catlog-topbar">'
			. '<div class="catlog-brand"><div class="catlog-mark">CL</div>'
			. '<div class="catlog-brand-text"><strong>CATLOG</strong><span>' . htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') . '</span></div></div>'
			. '<div class="catlog-meta">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '<br>' . $now . '</div>'
			. '</header>';
	}

	/**
	 * 关闭壳层
	 *
	 * @return string
	 */
	private static function shellClose(): string
	{
		return '</div>';
	}
}
