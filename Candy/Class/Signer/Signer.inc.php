<?php
/**
 * Signer 配置模板（框架扩展类库）
 *
 * 默认不启用；执行 `php candy signer:init` 后发布到 Source/Inc/Signer.inc.php
 *
 * mode=single：小项目，可不传 X-App-Id，填 default 即可
 * mode=multi ：开放多租户，客户端必须带 X-App-Id，密钥写在 apps
 */
return [
	'enabled' => false,

	// single=小项目无 AppId；multi=必须 AppId
	'mode' => 'single',

	// multi 下未知 AppId 是否回退 default（生产建议 false）
	'allow_default_fallback' => false,

	'skew' => 300,
	'signature_encoding' => 'hex', // hex|base64

	'nonce' => [
		'enabled' => true,
		'prefix'  => 'signer:nonce:',
	],

	'default' => [
		'sign_type'   => 'hmac', // hmac|rsa|ecdsa
		'secret'      => '',     // init 可自动生成
		'public_key'  => '',
		'private_key' => '',
		'enable'      => true,
	],

	/*
	 * multi 示例（mode=multi 时启用）：
	 *
	 * 'apps' => [
	 *     'demo_hmac' => [
	 *         'sign_type' => 'hmac',
	 *         'secret'    => 'replace-me',
	 *         'enable'    => true,
	 *     ],
	 *     'demo_rsa' => [
	 *         'sign_type'   => 'rsa',
	 *         // 服务端验签只需公钥；若本机也要代签则填 private_key
	 *         'public_key'  => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
	 *         'private_key' => '',
	 *         'enable'      => true,
	 *     ],
	 *     'demo_ecdsa' => [
	 *         'sign_type'  => 'ecdsa', // 仅 P-256 / prime256v1
	 *         'public_key' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
	 *         'enable'     => true,
	 *     ],
	 * ],
	 */
	'apps' => [],

	'download' => [
		'aes_key' => '',
		'default_expire' => 3600,
		// 解密后路径必须命中此前缀之一（空=不校验）
		'path_allow_prefix' => [],
	],
];

