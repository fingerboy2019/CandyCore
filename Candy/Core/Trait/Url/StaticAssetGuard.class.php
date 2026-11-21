<?php
/**
 * 静态资源路径守卫
 *
 * 拦截不应进入 MVC 路由的静态文件请求（css/js/图片等），直接返回 404。
 *
 * @package Candy\Core\Url
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Url;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class StaticAssetGuard
{
	/** @var list<string> 应直接 404 的静态资源扩展名 */
	private const EXTENSIONS = [
		'.css', '.jpg', '.gif', '.png', '.ico', '.mp4', '.txt',
		'.eot', '.svg', '.ttf', '.woff', '.woff2',
	];

	/**
	 * Is Static Asset
	 * @param mixed $pathInfo
	 * @return bool
	 */
	public function isStaticAsset(string $pathInfo): bool
	{
		$lowerPath = strtolower(ltrim($pathInfo, '/'));
		// CMS robots.txt 由 Seo 控制器输出，禁止当静态资源直接 404
		if ($lowerPath === 'robots.txt') {
			return false;
		}

		foreach (self::EXTENSIONS as $extension) {
			if (str_ends_with($lowerPath, $extension)) {
				return true;
			}
		}

		// 仅拦截「404 错误页」路径本身，禁止把任意含 "404" 子串的业务 URI
		// （如邀请 token …88404ca5…）误判为静态资源
		if (preg_match('#(^|/)404(\.[a-z0-9]+)?$#', $lowerPath) === 1) {
			return true;
		}

		return str_ends_with($lowerPath, '.js') && !str_ends_with($lowerPath, '.json');
	}

	/**
	 * Reject If Static
	 * @param mixed $pathInfo
	 */
	public function rejectIfStatic(string $pathInfo): void
	{
		if ($this->isStaticAsset($pathInfo)) {
			respond('File Not Found.', 404);
		}
	}
}
