<?php
/**
 * Catlog 配置模板（Vendor 内置）
 *
 * 默认不放入 Source/Inc；执行 `php candy catlog:init` 后复制到：
 *   Source/Inc/Catlog.inc.php
 *
 * 依赖：PHP xhprof 扩展 + 可用 Cache（List 能力，File/Redis 等均可）
 */
return [
	// 总开关：init 发布后默认 true；生产务必改 false
	'enable'         => false,
	// 仅 DEBUG 模式采样（推荐 true）
	'require_debug'  => true,
	// 仅记录耗时超过 N 秒的请求（0=全部）
	'time_limit'     => 0,
	// 忽略 URL（精确或 * 通配）
	'ignore_url_arr' => [
		'/test',
		'*catlog*',
		'*xhprof*',
		'*view_xhprof*',
	],
	'key_prefix'     => 'xhprof',
	'log_num'        => 1000,
	'view_wtred'     => 3,
	// UI 直接引用包内目录（调试工具，生产一般不挂载）
	'ui_html'        => '/Candy/Vendor/Catlog/static',
	'symbol_lookup_url' => '',
	'auth'           => [
		'enable'   => true,
		'password' => 'change-me',
	],
];
