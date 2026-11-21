<?php
/**
 * 框架核心服务注册
 *
 * 在 Bootstrap::boot() 阶段预热无状态单例，供 UrlParser / U() 等共享。
 * 请求级对象（View、ViewRenderer、Action）由容器按 newInstance 按需创建。
 *
 * @package Candy\Core
 */

declare(strict_types=1);

namespace Candy\Core;

use Candy\Core\Trait\Func\ArchiveService;
use Candy\Core\Trait\Func\CaptchaRenderer;
use Candy\Core\Trait\Func\MirrorClient;
use Candy\Core\Trait\Func\RpcBridge;
use Candy\Core\Trait\Url\PathInfoResolver;
use Candy\Core\Trait\Url\PathSegmentParser;
use Candy\Core\Trait\Url\StaticAssetGuard;
use Candy\Core\Trait\Url\UrlGenerator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Service
{
	/** @var list<class-string> 进程级无状态核心服务 */
	private const STATELESS_SERVICES = [
		UrlGenerator::class,
		PathInfoResolver::class,
		StaticAssetGuard::class,
		PathSegmentParser::class,
		UrlParser::class,
		ArchiveService::class,
		MirrorClient::class,
		RpcBridge::class,
		CaptchaRenderer::class,
	];

	/**
	 * Register
	 */
	public static function register(): void
	{
		$container = Container::getInstance();

		foreach (self::STATELESS_SERVICES as $class) {
			$container->make($class);
		}
	}
}
