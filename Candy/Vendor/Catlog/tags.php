<?php
/**
 * Catlog Vendor Hook 定义（可选手动 include）
 *
 * 推荐：php candy catlog:init
 * 或：\Catlog\Bootstrap::mergeTags($tags)
 * UI 直接引用：/Candy/Vendor/Catlog/static（调试用，生产一般不挂载）
 *
 * @return array<string,list<string>>
 */
return [
	'app_begin' => [
		\Catlog\Xhprof::class . '::start',
	],
	'app_end' => [
		\Catlog\Xhprof::class . '::stop',
	],
	'app_stop' => [
		\Catlog\Xhprof::class . '::stop',
	],
	'view_xhprof' => [
		\Catlog\Xhprof::class . '::showResult',
	],
];
