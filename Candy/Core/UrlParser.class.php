<?php
/**
 * Candy框架 UrlParser URL解析类
 *
 * 编排层：镜像代理、路由模式解析与 Rpc 分支。
 * /Doc/、/Blog/ 经全站 URL 重写到 index.php 后，在本类最前劫持至 Markdown。
 *
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-26 11:35:31
 * @final 禁止被继承
 */

declare(strict_types=1);

namespace Candy\Core;

use Candy\Core\Trait\Url\PathInfoResolver;
use Candy\Core\Trait\Url\PathSegmentParser;
use Candy\Core\Trait\Url\StaticAssetGuard;
use Candy\Core\Trait\Url\UrlGenerator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class UrlParser
{
	/**
	 *   construct
	 * @param mixed $pathInfoResolver
	 * @param mixed $staticAssetGuard
	 * @param mixed $pathSegmentParser
	 * @param mixed $urlGenerator
	 * @return mixed
	 */
	public function __construct(
		private readonly PathInfoResolver $pathInfoResolver,
		private readonly StaticAssetGuard $staticAssetGuard,
		private readonly PathSegmentParser $pathSegmentParser,
		private readonly UrlGenerator $urlGenerator,
	) {
	}

	/**
	 * Parse Url
	 */
	public static function parseUrl(): void
	{
		Container::getInstance()->make(self::class)->parse();
	}

	/**
	 * Parse
	 */
	public function parse(): void
	{
		// Rpc Worker 已写入 G('rpc')（兼容旧键 G('jsonrpc')）时走参数适配，跳过 HTTP PATH_INFO
		$rpcCtx = G('rpc');
		if ($rpcCtx === null || $rpcCtx === '') {
			$rpcCtx = G('jsonrpc');
		}

		if (($rpcCtx === null || $rpcCtx === '') && isset($this->requestServer()['REQUEST_URI'])) {
			$this->parseHttpRequest();
		} else {
			$this->parseRpcRequest(is_array($rpcCtx) ? $rpcCtx : []);
		}

		// 所有 PATH / Query 路由改写完成后才读取 R()。此前读取会取得
		// onRequest 初始快照，导致 /Admin/ 被误判为 Home 路由。
		if (Request::inCoroutine()) {
			Request::refresh();
			C('CONTROL', R()->get('m'));
			C('ACTION', R()->get('a'));
			return;
		}

		C('CONTROL', I('get.m', null, ''));
		C('ACTION', I('get.a', null, ''));
	}

	/**
	 * Parse Http Request
	 */
	private function parseHttpRequest(): void
	{
		// 禁止直接探测 Doc/Blog 下除 Static 外的磁盘路径（正文 MD 须仅经 /Blog/ 渲染）
		$this->rejectDirectBlogDiskAccess();

		// 须在 try_files 命中物理目录之前由 index.php 接管；/Blog/ 勿在站点根下建同名空目录
		$this->hijackMarkdownRoutes();

		$mirrorConfig = readInc('Mirror');
		$mirrorRules = [];
		$server = $this->requestServer();
		preg_match('/^(https|http)?:\/\/[^s]+/i', substr((string)($server['REQUEST_URI'] ?? '/'), 1), $proxyMatch);

		if (isset($mirrorConfig['/']) && count($proxyMatch) === 0) {
			$mirrorRules = $mirrorConfig['/'];
			$mirrorUrl = $mirrorRules['host'] . (string)($server['REQUEST_URI'] ?? '/');
			makeMirForSave($mirrorUrl, $mirrorRules);
		}

		$this->pathInfoResolver->resolve();

		$server = $this->requestServer();
		if (!empty($server['PATH_INFO'])) {
			$this->staticAssetGuard->rejectIfStatic((string)$server['PATH_INFO']);
		}

		Route::initConfig();

		$urlMode = C('URL_MODEL', '', 1);
		$urlSeparator = C('URL_DEPR', '', '/');

		$server = $this->requestServer();
		if (empty($server['PATH_INFO']) === false) {
			$this->parsePathInfoRoute($urlMode, $urlSeparator, $mirrorConfig, $proxyMatch);
		} else {
			$this->urlGenerator->parseQueryStringMode($urlMode, $urlSeparator);
		}

		$get = $this->requestGet();
		$mirrorRouteKey = ($get['g'] ?? 'Home') === 'Home'
			? strtolower((string)($get['m'] ?? 'Index'))
			: strtolower((string)$get['g']);
		if (isset($mirrorConfig[$mirrorRouteKey])) {
			$mirrorRules = $mirrorConfig[$mirrorRouteKey]['rules'];
			$server = $this->requestServer();
			$uriSegments = explode('/', ltrim((string)($server['REQUEST_URI'] ?? '/'), '/'));
			array_shift($uriSegments);
			$mirrorUrl = $mirrorConfig[$mirrorRouteKey]['host'] . '/' . implode('/', $uriSegments);
			makeMirForSave($mirrorUrl, $mirrorRules);
		}

		Security::runMiddlewares('After');
	}

	/**
	 * 拦截对博客磁盘目录的直接 HTTP 访问
	 *
	 * 仅放行 /Doc/Blog/Static/；views、正文随机目录、历史 post 等均返回 404
	 * 注：.md 等静态扩展名若被 Nginx 直接输出，须配合站点 Nginx 规则（见博客模式文档）
	 *
	 * @return void
	 * @access private
	 */
	private function rejectDirectBlogDiskAccess(): void
	{
		$server = $this->requestServer();
		$uriLower = strtolower(explode('?', (string)($server['REQUEST_URI'] ?? ''), 2)[0]);
		if (preg_match('#^/doc/blog/(?!static/)#', $uriLower)) {
			respond('File Not Found.', 404);
		}
	}

	/**
	 * 劫持 /Doc/、/Blog/ 路径至 Markdown 文档站 / 博客
	 *
	 * 框架开启 URL 重写后，除静态资源外请求均汇入 index.php；
	 * /Doc/xxx、/Blog/xxx 并非真实目录访问，须在此分流，不可依赖 RouteContextInit 晚于路由的阶段。
	 *
	 * @return void
	 * @access private
	 */
	private function hijackMarkdownRoutes(): void
	{
		$server = $this->requestServer();
		$uriLower = strtolower(explode('?', (string)($server['REQUEST_URI'] ?? ''), 2)[0]);
		$standalone = \Candy\Extend\Doc\Blog\Config::isStandaloneHost();

		// 博客管理后台（独立域名：/{content_dir}/admin）
		if ($standalone && preg_match('#^/([a-z0-9]{8})/admin(?:/|$)#', $uriLower)) {
			\Candy\Extend\Doc\Blog\Admin\Gateway::start();
		}

		// 博客管理后台（路径模式：/Blog/{content_dir}/admin）
		if (preg_match('#^/blog/([a-z0-9]{8})/admin(?:/|$)#', $uriLower)) {
			\Candy\Extend\Doc\Blog\Admin\Gateway::start();
		}

		// 独立域名：整站由 Markdown 博客接管（blog_domain 为空时不进入）
		if ($standalone) {
			Markdown::start();
		}

		if (
			$uriLower === '/doc'
			|| str_starts_with($uriLower, '/doc/')
			|| $uriLower === '/blog'
			|| str_starts_with($uriLower, '/blog/')
		) {
			Markdown::start();
		}
	}

	/**
	 * Parse Path Info Route
	 * @param mixed $urlMode
	 * @param mixed $urlSeparator
	 * @param mixed $mirrorConfig
	 * @param mixed $proxyMatch
	 */
	private function parsePathInfoRoute(
		int $urlMode,
		string $urlSeparator,
		array $mirrorConfig,
		array $proxyMatch,
	): void {
		$server = $this->requestServer();
		['routePath' => $routePath, 'suffix' => $urlSuffix] = $this->urlGenerator->extractRoutePath((string)($server['PATH_INFO'] ?? ''));

		$this->urlGenerator->redirectPathInfoExtraQuery(
			$routePath,
			$urlSuffix,
			$urlSeparator,
			$urlMode,
			$mirrorConfig,
		);

		$mirrorConfig = readInc('Mirror');
		$this->urlGenerator->applySuffixRules($urlSuffix, $proxyMatch, $mirrorConfig);

		$normalizedPath = $this->urlGenerator->normalizeRoutePath($routePath, $urlSeparator);
		$this->pathSegmentParser->parseToGet($normalizedPath);
	}

	/**
	 * Rpc 请求上下文适配
	 *
	 * 将 G('rpc') 中的 g/m/a 与业务参数灌入 $_GET/$_POST，对齐 HTTP 解析后的超全局形态
	 * 供同进程内仍依赖 $_GET 的代码读取；真正方法调度由 Candy\Extend\Rpc\Dispatcher 完成
	 *
	 * @param array<string,mixed> $serverParams Rpc 上下文
	 * @return void
	 * @access private
	 */
	private function parseRpcRequest(array $serverParams): void
	{
		$post = [];
		$hasPost = false;
		if (isset($serverParams['post']) && is_array($serverParams['post'])) {
			$post = $serverParams['post'];
			$hasPost = true;
			if (!Request::inCoroutine()) {
				$_POST = $post;
			}
			unset($serverParams['post']);
		}
		unset($serverParams['payload']);
		if (Request::inCoroutine()) {
			if ($hasPost) {
				R()->replacePost($post);
			}
			R()->replaceGet($serverParams);
		} else {
			$_GET = $serverParams;
		}

		debug(0);
	}

	/** @return array<string,mixed> */
	private function requestServer(): array
	{
		return I('server.', [], '');
	}

	/** @return array<string,mixed> */
	private function requestGet(): array
	{
		return I('get.', [], '');
	}
}
