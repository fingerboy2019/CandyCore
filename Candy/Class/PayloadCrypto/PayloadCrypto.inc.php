<?php
/**
 * PayloadCrypto 配置模板（框架扩展类库）
 *
 * 默认不启用；执行 `php candy payload-crypto:init` 后发布到 Source/Inc/PayloadCrypto.inc.php
 *
 * mode=single：填 default.key 即可
 * mode=multi ：按 appId 取 apps 中密钥（HTTP X-App-Id / RPC app_id）
 */
return [
	'enabled' => false,

	// single=默认密钥；multi=按应用选 key
	'mode' => 'single',

	// multi 下未知 AppId 是否回退 default（生产建议 false）
	'allow_default_fallback' => false,

	'default' => [
		// 32 字节密钥：hex(64) 或 base64；init 自动生成 hex
		'key'    => '',
		'enable' => true,
	],

	/*
	 * multi 示例：
	 * 'apps' => [
	 *     'tenant_a' => [
	 *         'key'    => '…',
	 *         'enable' => true,
	 *     ],
	 * ],
	 */
	'apps' => [],

	// base64=文本传输；raw=裸二进制（RPC bin 轨可选）
	'encoding' => 'base64',

	'aad' => [
		'enabled' => true,
	],
];

