<?php
/***
 * MCP 调用 Admin 应用上下文引导
 *
 * 业务说明：委托 Bootstrap::bootRuntime / loadLang，为 Tool 准备 APP_PATH 与 DB
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-22 17:44:50 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

use Candy\Core\Bootstrap;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class AdminContext
{
	/**
	 * 加载默认库连接并切到指定应用
	 *
	 * @param string $app 应用目录名（Admin / Home）
	 * @return void
	 */
	public static function boot(string $app = 'Admin'): void
	{
		Bootstrap::bootRuntime($app);
	}

	/**
	 * 合并指定语言包键到 LANGINFO（可选）
	 *
	 * @param string $langFile 语言文件基名，如 Testlib
	 * @return void
	 */
	public static function loadLang(string $langFile): void
	{
		$lg = G('LANG') ?: 'zh-cn';
		G('LANG', $lg);
		$lang = is_array(C('LANGINFO')) ? C('LANGINFO') : [];
		$file = rtrim((string) C('APP_PATH'), '/\\') . '/Languages/' . $lg . '/' . $langFile . '.lang.php';
		if (is_file($file)) {
			$chunk = include $file;
			if (is_array($chunk)) {
				$lang += $chunk;
			}
		}
		C('LANGINFO', $lang);
	}
}

