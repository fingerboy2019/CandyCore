<?php
/**
 * Apidoc Vendor Hook 定义（可选手动 include）
 *
 * 推荐：php candy apidoc:init
 * 或：\Apidoc\Bootstrap::mergeTags($tags)
 *
 * @return array<string,list<string>>
 */
return [
	'route_start' => [
		\Apidoc\providers\CandyService::class,
	],
];
