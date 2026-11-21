<?php
/**
 *插件路由配置
 **/

return [
	'URL_MODEL'				=>	1,					//URL访问模式   0  兼容模式  1 统一模式
	'URL_ROUTER_ON'   		=> true,				//开启路由
	//'URL_DEPR'   			=> '-',					// URL 路径分隔符（冻结键 URL_DEPR，默认 /，不可为 .）
	'URL_HTML_SUFFIX'		=>'.shtml',				//伪静态后缀
	'URL_MAP_RULES' 		=> [ 				//静态路由规则
		'show/index'        => 'index/index',
	],
	'URL_ROUTE_RULES' 		=> [ 				//动态路由规则
		'show/:id'        => 'index/index',
	],
];
