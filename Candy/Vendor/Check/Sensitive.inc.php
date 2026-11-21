<?php
/**
 * Check / Sensitive 配置模板（Vendor 内置）
 *
 * 默认不放入 Source/Inc；执行 `php candy check:init` 后复制到：
 *   Source/Inc/Sensitive.inc.php
 *
 * 未 init 前 Sensitive 不可用；init 不改 Application/tags.php（按需调用）
 */
return [
	// 总开关：模板默认 false；check:init 发布时改为 true
	'enabled' => false,

	// Cache 键前缀 → {prefix}:words / {prefix}:ver
	'cache_prefix' => 'check:sensitive',

	// 种子与回写路径；空则使用 Vendor/Check/word/SensitiveWord.txt
	'word_file' => '',

	// 种子来源：file（TXT）| array（sensitive_words）；运行匹配只读 Cache
	'mode' => 'file',

	'config' => [
		'repeat' => true,
		'replace_char' => '*',
		'mark' => 'mark',
	],

	'interference_factors' => [
		' ', '&', '*', '/', '|', '@', '.', '^', '~', '$',
	],

	// mode=array 时使用
	'sensitive_words' => [
		'工口',
		'里番',
		'性感美女',
	],
];
