<?php
/**
 * Candy框架安全防护核心类
 *
 * 门面类：组合 CsrfGuard / InputFilter（含 SqlInjectionGuard、XssSanitizer）/ IpAccess / MiddlewareRunner。
 * **非 final**：项目中间件可 `extends Security`（如 JwtAuthMiddleware）。
 *
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-27 11:31:47
 */

declare(strict_types=1);

namespace Candy\Core;

use Candy\Core\Trait\Security\CsrfGuard;
use Candy\Core\Trait\Security\InputFilter;
use Candy\Core\Trait\Security\IpAccess;
use Candy\Core\Trait\Security\MiddlewareRunner;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Security
{
	use CsrfGuard;
	use InputFilter;
	use IpAccess;
	use MiddlewareRunner;

	/**
	 * 安全系统入口：加载中间件并初始化 CSRF 防护
	 * @return void
	 */
	public static function run(): void
	{
		self::loadMiddleware();
		self::initCsrfProtection();
	}
}
