<?php
/**
 * PATH_INFO 环境解析器
 *
 * 负责请求环境初始化、Workerman PATH_INFO 兼容与路径规范化。
 * 静态资源拦截由编排层显式调用 {@see StaticAssetGuard}，与本类解耦。
 *
 * @package Candy\Core\Trait\Url
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Url;

use Candy\Core\Request;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class PathInfoResolver
{
	/**
	 * 解析并规范化 PATH_INFO（不含静态资源守卫）
	 */
	public function resolve(): void
	{
		$this->applyRequestMethodFlags();
		$this->resolveWorkermanPathInfo();
		$this->fallbackPathInfoFromRequestUri();
		$this->normalizePathInfo();
		$this->syncRequestContext();
	}

	/**
	 * 将路径解析后的兼容超全局同步回 R() 请求快照
	 *
	 * 路由历史逻辑仍会改写 $_SERVER['PATH_INFO'] 与 $_GET；Swoole 中不能让
	 * 后续业务继续读取 onRequest 初始快照，因此仅在本阶段完整结束后刷新。
	 *
	 * @return void
	 */
	private function syncRequestContext(): void
	{
		if (Request::inCoroutine()) {
			Request::refresh();
		}
	}

	/**
	 * Apply Request Method Flags
	 */
	private function applyRequestMethodFlags(): void
	{
		$server = $this->requestServer();
		if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
			return;
		}

		C('METHOD', 'POST');

		if (isset($this->requestPost()['removexss'])) {
			C('REMOVEXSS', true);
		}
	}

	/**
	 * Resolve Workerman Path Info
	 */
	private function resolveWorkermanPathInfo(): void
	{
		if (!isWebServer()) {
			return;
		}

		$this->setServer('PATH_INFO', '');
		$requestPath = '';
		$queryString = '';
		$server = $this->requestServer();

		if ((isset($server['REQUEST_URI']) && strlen((string)$server['REQUEST_URI']) > 1)
			|| !empty($server['QUERY_STRING'])
			|| ($server['QUERY_STRING'] ?? '') === 'add') {
			$requestUri = $server['REQUEST_URI'] ?? $server['QUERY_STRING'];
			if (!isset($server['REQUEST_URI'])) {
				$this->setServer('REQUEST_URI', $requestUri);
			}
			$uriParts = explode('?', $requestUri);
			$requestPath = $uriParts[0];
			$queryString = $uriParts[1] ?? '';
		}

		if ($requestPath !== '/' && $requestPath !== '') {
			$this->setServer('PATH_INFO', urldecode($requestPath));
		}

		if ($queryString !== '' && $queryString !== 'add') {
			parse_str($queryString, $parsedQuery);
			$this->replaceGet($parsedQuery);
		}
	}

	/**
	 * Fallback Path Info From Request Uri
	 */
	private function fallbackPathInfoFromRequestUri(): void
	{
		$server = $this->requestServer();
		if (empty($server['PATH_INFO']) && !empty($server['REQUEST_URI'])) {
			$this->setServer('PATH_INFO', explode('?', (string)$server['REQUEST_URI'])[0]);
		}
	}

	/**
	 * Normalize Path Info
	 */
	private function normalizePathInfo(): void
	{
		$server = $this->requestServer();
		$pathInfo = $server['PATH_INFO'] ?? null;
		if ($pathInfo === null) {
			return;
		}

		if (str_contains($pathInfo, '.php')) {
			[$scriptPath, $pathAfterPhp] = explode('.php', $pathInfo, 2);
			$this->setServer('PATH_INFO', dirname($scriptPath) . '/' . $pathAfterPhp);
			$server = $this->requestServer();
		}

		if (!isset($server['PATH_INFO'])) {
			return;
		}

		$pathInfo = (string)$server['PATH_INFO'];
		while ($pathInfo !== '' && !ctype_alnum($pathInfo[0])) {
			$pathInfo = substr($pathInfo, 1);
		}
		$this->setServer('PATH_INFO', $pathInfo);
	}

	/** @return array<string,mixed> */
	private function requestServer(): array
	{
		return I('server.', [], '');
	}

	/** @return array<string,mixed> */
	private function requestPost(): array
	{
		return I('post.', [], '');
	}

	/** 同步路径改写到 R() 与兼容 SERVER。 */
	private function setServer(string $key, mixed $value): void
	{
		if (Request::inCoroutine()) {
			R()->setServer($key, $value);
		} else {
			$_SERVER[$key] = $value;
		}
	}

	/** 同步查询串重解析到 R() 与兼容 GET。 */
	private function replaceGet(array $data): void
	{
		if (Request::inCoroutine()) {
			R()->replaceGet($data);
		} else {
			$_GET = $data;
		}
	}
}
