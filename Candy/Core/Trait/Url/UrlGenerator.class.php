<?php
/**
 * URL 生成器
 *
 * 入站：PATH_INFO 路由路径提取、URL 模式规范化重定向、API 后缀识别。
 * 出站：{@see build()} 组装 URL（原全局 U() 逻辑）；Api 模式下按 API_URL 追加前缀/专用域名。
 * 与 {@see PathSegmentParser}（PATH 解析）职责分离。
 *
 * @package Candy\Core\Trait\Url
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Url;

use Candy\Core\Debug;
use Candy\Core\Markdown;
use Candy\Core\Request;
use Candy\Core\Route;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class UrlGenerator
{
	/** @var array<string, bool> 控制器名是否为独立应用目录（build 缓存） */
	private static array $isAppCache = [];

	/**
	 * 组装 URL（支持 PATHINFO / REWRITE / GET 等模式）
	 *
	 * @param mixed ...$params 同全局 U() 可变参数
	 */
	public function build(...$params): string
	{
		$server = $this->requestServer();
		if (empty($params)) {
			return (string)($server['REQUEST_URI'] ?? '/');
		}

		$app = C('APP');
		$selfAppName = C('APPNAME');
		$isAddon = C('ISADDON');
		$uri = (string)($server['REQUEST_URI'] ?? '/');

		if (extract($params)) {
			$url ?: '';
			$suffix ?: '';
			$showurl ?? false;
			$script ?: '';
			$vars ?: [];
			$get ?: [];
			$tpl ?: '';
		} else {
			$url = '';
			$suffix = '';
			$showurl = false;
			$script = '';
			$vars = [];
			$get = [];
			$tpl = '';
		}

		$domain = $this->resolveOutboundOrigin(false);

		if (empty($url)) {
			$actions = $params[0];
		} else {
			$actions = $url;
			$url = '';
		}

		if (is_array($actions)) {
			isset($actions[4]) && $showurl = true;
			isset($actions[3]) && $suffix = $actions[3];
			isset($actions[2]) && $tpl = $actions[2];
			isset($actions[1]) && $vars = $actions[1];
			$actions = $actions[0];
		}

		if (!empty($vars)) {
			if (is_string($vars)) {
				parse_str($vars, $vars);
			}
		}
		$pvars = $params[1] ??= [];
		if (is_string($pvars)) {
			parse_str($pvars, $pvars);
		}
		$vars = array_merge($vars, $pvars);

		isset($params[2]) && $tpl = $params[2];
		isset($params[3]) && $suffix = $params[3];
		empty($suffix) && $suffix = C('URL_HTML_SUFFIX', '', '.html');
		isset($params[4]) && $showurl = true;

		if ($this->isAbsoluteOutboundUrl((string)$actions)) {
			$absoluteUrl = $this->appendOutboundQueryParams((string)$actions, $vars);
			$content = '';
			if (!empty($tpl)) {
				if (is_string($tpl)) {
					$absoluteUrl = str_replace('%href%', $absoluteUrl, $tpl);
				} elseif (is_array($tpl) && isset($tpl[0], $tpl[1]) && is_array($tpl[1])) {
					$urlSearch = ['%href%'];
					$urlReplace = [$absoluteUrl];
					foreach ($tpl[1] as $key => $replace) {
						$urlSearch[] = '%' . $key . '%';
						$urlReplace[] = $replace;
					}
					$content = str_replace($urlSearch, $urlReplace, (string)$tpl[0]);
				}
			}
			if (function_exists('checkUserAuth')) {
				$absoluteUrl = checkUserAuth((string)$actions, $absoluteUrl, $content);
			}
			return $absoluteUrl;
		}

		$urlDepr = C('URL_DEPR', '', '/');

		if (empty($actions)) {
			if (empty($vars)) {
				return (string)($server['REQUEST_URI'] ?? '/');
			}

			[$path, $args] = explode('?', (string)($server['REQUEST_URI'] ?? '/'));
			$extendArgs = '';
			$protectArgs = is_array(G('protectArgs')) ? G('protectArgs') : [];
			foreach ($vars as $k => $v) {
				if (!empty($path)) {
					if (!(($sps = stripos($path, $urlDepr . $k . $urlDepr)) === false)) {
						$start = $sps + strlen($k) + 2;
						$tmp = substr($path, $start);
						[$pv] = explode($urlDepr, $tmp, 2);
						stripos($pv, $suffix) === false OR $pv = str_replace($suffix, '', $pv);
						$path = substr($path, 0, $start) . $v . substr($path, $start + strlen($pv));
						continue;
					}
				}

				if (!empty($args)) {
					if (!(($sps = stripos($args, $k . '=')) === false)) {
						$start = $sps + strlen($k) + 1;
						$tmp = substr($args, $start);
						[$pv] = explode('&', $tmp, 2);
						$args = substr($args, 0, $start) . $v . substr($args, $start + strlen($pv));
						continue;
					}
				}

				if (C('URL_MODEL') == 1 && !in_array($k, $protectArgs)) {
					$extendArgs .= $urlDepr . $k . $urlDepr . $v;
				} else {
					$args .= (empty($args) ? '?' : '&') . $k . '=' . $v;
				}
			}

			if (!empty($extendArgs)) {
				if (stripos($path, $suffix) === false) {
					if ($path == '/') {
						$path .= 'Index' . $urlDepr . 'index' . $extendArgs . $suffix;
					} else {
						$path = explode($urlDepr, str_replace('/', $urlDepr, substr($path, 1, -1)));
						$pathNum = count($path);
						if ($pathNum > 2) {
							$path[0] == 'Home' && array_shift($path);
							$path = '/' . implode($urlDepr, $path);
						} else {
							$path = '/' . str_replace('/', $urlDepr, 'Index/index');
						}
						$path .= $extendArgs . (($urlDepr == '/' && $pathNum < 3) ? '/' : $suffix);
					}
				} else {
					$tmp = explode('/', str_replace($urlDepr, '/', substr(str_replace($suffix, '', $path), 1)));
					$pathNum = count($tmp);
					if ($pathNum < 3) {
						if ($tmp[0] == 'Home') {
							array_shift($tmp);
						}
						if (count($tmp) < 2) {
							if (count($tmp) == 0) {
								$tmp[] = 'Index';
							}
							$tmp[] = 'index';
						}
						$path = '/' . implode($urlDepr, $tmp);
					} else {
						$tmp[0] == 'Home' && array_shift($tmp);
						$path = '/' . implode($urlDepr, $tmp);
					}
					$path .= $extendArgs . $suffix;
				}
			}

			return $path . (empty($args) ? '' : '?' . $args);
		}

		if ($actions[0] === '.') {
			// 与下方出站规则一致：当前应用已绑定本 Host 时省略应用段，
			// 避免 portal.candy.com 上生成 /Portal/User/edit.json →「Portal控制器不存在」。
			$appName = (string)C('APPNAME');
			$segments = [(string)C('CONTROL'), (string)C('ACTION')];
			if (!$this->shouldOmitAppPathSegment($appName, $server)) {
				array_unshift($segments, $appName);
			}
			return '/' . implode(C('URL_DEPR', '', '/'), $segments) . $actions;
		}

		if ($actions[0] === '/' && strlen($actions) == 1) {
			return $this->finalizeApiOutboundUrl($domain . (string)($server['REQUEST_URI'] ?? '/'), '');
		}

		// 强制绝对地址时优先使用 API_URL.domain（无显式 @域名 时）
		// SERVER_PORT 在 Workerman 下常为 int，须宽松比较，否则会误判为非 80/443 而全站绝对 URL
		$serverPort = (string)($server['SERVER_PORT'] ?? '80');
		$forceAbsolute = ($actions[0] === '/' || $showurl || !in_array($serverPort, ['80', '443'], true));
		if ($forceAbsolute) {
			$actions[0] === '/' && $actions = substr($actions, 1);
			$domain = $this->resolveOutboundOrigin(true);
			$url = $domain . $url;
		} else {
			if (!empty($url)) {
				$url = '/' . $url;
			}
		}

		if ($actions[0] === '#') {
			return $actions;
		}

		if ($actions[0] === '@') {
			$host = substr($actions, 1);
			$origin = (isSsl() ? 'https://' : 'http://') . $host;
			return $this->finalizeApiOutboundUrl(
				str_replace($domain, $origin, $domain . $uri),
				$host
			);
		}

		$actions = str_replace(['https:://', 'http://'], ['AA&&BB', 'A&B'], $actions);

		if (strpos($actions, '/')) {
			$parts = explode('/', $actions, 3);
			$appName = $parts[0] ?? $selfAppName;
			$control = $parts[1] ?? '';
			$action = $parts[2] ?? '';

			if (empty($action)) {
				$action = $control;
				$control = $appName;
				$appName = $selfAppName;
			}
		} else {
			$action = $actions;
			$control = C('CONTROL');
			$appName = $selfAppName;
		}

		$last = '';
		if (stripos($action, '#')) {
			[$action, $last] = explode('#', $action, 2);
		}

		$do = '';
		if (stripos($action, '@')) {
			[$action, $do] = explode('@', $action, 2);
		}

		if (!empty($last) && stripos($last, '@')) {
			[$last, $do] = explode('@', $last, 2);
		}

		if (stripos($action, '.')) {
			[$action, $suffix] = explode('.', $action, 2);
			$suffix = '.' . $suffix;
		}

		$isCurrentAppBoundHost = $this->shouldOmitAppPathSegment((string)$appName, $server);

		if (!$isAddon && $appName != 'Home' && (G('default.reset') && G('default.reset') == $appName)) {
		} else {
			if (!isset(self::$isAppCache[$control])) {
				self::$isAppCache[$control] = is_dir(CANDYROOT . 'Application' . DS . $control);
			}

			if (str_starts_with($appName, 'Home') && self::$isAppCache[$control] === false) {
				$appName = str_replace('Home', '', $appName);
			}

			// 已绑定到当前 Host 的应用以根路径出站：portal.candy.com/Auth/index.html，
			// 而非 portal.candy.com/Portal/Auth/index.html。
			if (!$isCurrentAppBoundHost) {
				$get['g'] = ucfirst($appName);
			}
		}

		$get['m'] = $control;
		$get['a'] = $action;
		$get = array_merge($get, $vars);

		if (($server['SCRIPT_NAME'] ?? '/index.php') != '/index.php') {
			$script = (string)$server['SCRIPT_NAME'];
		}

		if (C('URL_MODEL') > 0 || isWebServer() || !empty($server['PATH_INFO'])) {
			$protectArgs = is_array(G('protectArgs')) ? G('protectArgs') : [];
			$url .= $script . '/';
			if (count($get) <= 3 && $get['m'] == 'Index' && $get['a'] == 'index') {
				unset($get['m']);
				unset($get['a']);
				if (($get['g'] ?? '') == 'Home' || empty($get['g'])) {
				} else {
					$url = $url . $get['g'] . '/';
				}
				unset($get['g']);
			} else {
				$url .= (empty($get['g']) ? '' : $get['g'] . $urlDepr) . $get['m'] . $urlDepr . $get['a'] . $urlDepr;
				unset($get['g']);
				unset($get['m']);
				unset($get['a']);

				if (count($get) > 0) {
					foreach ($get as $key => $value) {
						if (empty($value) || in_array($key, $protectArgs)) {
							if (empty($value)) {
								unset($get[$key]);
							}
							continue;
						}

						unset($get[$key]);
						$url .= $key . $urlDepr . $value . $urlDepr;
					}
				}
				$url = rtrim($url, $urlDepr) . (($urlDepr != '/' && $suffix == '/') ? C('URL_HTML_SUFFIX', '', '.html') : $suffix);
				if (count($get) > 0) {
					$url .= '?' . http_build_query($get);
				}
			}
		} else {
			$url = $url . (empty($script) ? '/' : $script) . '?' . http_build_query($get);
			unset($get);
		}

		empty($last) OR $url .= '#' . $last;

		// 显式 @域名 优先于 API_URL.domain
		if (!empty($do)) {
			$do = str_replace(['AA&&BB', 'A&B'], ['https:://', 'http://'], $do);
			$url = $this->applyExplicitOutboundHost($url, $do, $domain);
		}

		$url = str_replace(['%2B%26', '%26%2B'], ['"+ ', ' +"'], $url);

		$maps = (is_array(C('URL_MAP_RULES')) && C('URL_MAP_RULES')) ? C('URL_MAP_RULES') : [];
		foreach ($maps as $map => $rule) {
			if (stripos($url, str_replace('/', $urlDepr, $rule)) === false) {
				continue;
			}
			$url = str_replace(str_replace('/', $urlDepr, $rule), str_replace('/', $urlDepr, $map), $url);
		}

		$url = $url . (empty($get) ? '' : '?' . http_build_query($get));

		// Api 模式：追加前缀；强制绝对地址且无显式域名时改用 API_URL.domain
		$url = $this->finalizeApiOutboundUrl($url, (string)$do);

		if (!empty($tpl)) {
			if (is_string($tpl)) {
				$url = str_replace('%href%', $url, $tpl);
			} else {
				$urlSearch = ['%href%'];
				$urlReplace = [$url];
				foreach ($tpl[1] as $s => $r) {
					$urlSearch[] = '%' . $s . '%';
					$urlReplace[] = $r;
				}
				$content = str_replace($urlSearch, $urlReplace, $tpl[0]);
			}
		} else {
			$content = '';
		}

		if (function_exists('checkUserAuth')) {
			$url = checkUserAuth($actions, $url, $content);
		}

		return $url;
	}

	/**
	 * 是否处于 Api 出站模式
	 *
	 * 满足其一即可：配置 API_URL.mode、运行时 API_URL_MODE（入站匹配 api/v*）
	 *
	 * @return bool
	 * @access private
	 */
	private function isApiUrlMode(): bool
	{
		if (C('API_URL_MODE')) {
			return true;
		}
		$api = C('API_URL');
		return is_array($api) && !empty($api['mode']);
	}

	/**
	 * 读取 API_URL 配置数组
	 *
	 * @return array{prefix?: string, domain?: string, mode?: bool|int|string}
	 * @access private
	 */
	private function apiUrlConfig(): array
	{
		$api = C('API_URL');
		return is_array($api) ? $api : [];
	}

	/**
	 * 组装出站 Origin（scheme + host）
	 *
	 * preferApi 为 true 且 Api 模式并配置了 domain 时，使用 Api 专用域名
	 *
	 * @param bool $preferApi 是否优先 Api 域名
	 * @return string 如 https://api.example.com
	 * @access private
	 */
	private function resolveOutboundOrigin(bool $preferApi): string
	{
		$scheme = (isSsl() ? 'https://' : 'http://');
		if ($preferApi && $this->isApiUrlMode()) {
			$host = $this->normalizeApiDomainHost((string)($this->apiUrlConfig()['domain'] ?? ''));
			if ($host !== '') {
				return $scheme . $host;
			}
		}

		$server = $this->requestServer();
		return $scheme . ($server['HTTP_HOST'] ?? 'localhost');
	}

	/**
	 * 判断是否为应原样保留的完整绝对地址
	 *
	 * 完整地址用于统一登录、跨域回调等出站跳转，不能再按 MVC 路由拆解；否则
	 * URL 中的主机名会被误当成应用名，生成 /A&Bhost/path 形式的非法路径。
	 *
	 * @param string $url 待判断地址
	 * @return bool 是否为 HTTP(S) 绝对地址
	 * @access private
	 */
	private function isAbsoluteOutboundUrl(string $url): bool
	{
		return preg_match('~^https?://[^/\\s?#]+(?:[/?#]|$)~i', trim($url)) === 1;
	}

	/**
	 * 为完整绝对地址追加 URL 参数并保留片段
	 *
	 * @param string $url 原始绝对地址
	 * @param array $params 待追加参数
	 * @return string 处理后的绝对地址
	 * @access private
	 */
	private function appendOutboundQueryParams(string $url, array $params): string
	{
		if ($params === []) {
			return $url;
		}

		$fragment = '';
		$fragmentPosition = strpos($url, '#');
		if ($fragmentPosition !== false) {
			$fragment = substr($url, $fragmentPosition);
			$url = substr($url, 0, $fragmentPosition);
		}

		$query = http_build_query($params);
		if ($query === '') {
			return $url . $fragment;
		}

		$separator = str_contains($url, '?') ? '&' : '?';
		return $url . $separator . $query . $fragment;
	}

	/**
	 * 获取当前请求 SERVER 快照
	 *
	 * URL 出站生成只读取当前请求状态。Swoole 协程中从最终 R() 路由上下文
	 * 获取 Host、URI、PATH_INFO，避免读取其他请求的遗留超全局数组。
	 *
	 * @return array<string,mixed>
	 * @access private
	 */
	private function requestServer(): array
	{
		return I('server.', [], '');
	}

	/**
	 * 获取当前请求 GET 快照
	 *
	 * @return array<string,mixed>
	 * @access private
	 */
	private function requestGet(): array
	{
		return I('get.', [], '');
	}

	/**
	 * 同步 URL 解析后的 GET 参数到 R() 与遗留超全局
	 *
	 * @param array<string,mixed> $get 最终 GET 参数
	 * @return void
	 * @access private
	 */
	private function replaceRequestGet(array $get): void
	{
		if (Request::inCoroutine()) {
			R()->replaceGet($get);
		} else {
			$_GET = $get;
		}
	}

	/**
	 * 规范化 Api 域名配置（去掉 scheme / 路径，只留 host[:port]）
	 *
	 * @param string $domain 配置值
	 * @return string
	 * @access private
	 */
	private function normalizeApiDomainHost(string $domain): string
	{
		$domain = trim($domain);
		if ($domain === '') {
			return '';
		}
		if (preg_match('#^https?://#i', $domain)) {
			$host = parse_url($domain, PHP_URL_HOST);
			$port = parse_url($domain, PHP_URL_PORT);
			if (!is_string($host) || $host === '') {
				return '';
			}
			return $port ? ($host . ':' . $port) : $host;
		}

		return rtrim(explode('/', $domain, 2)[0], '/');
	}

	/**
	 * 将显式 @域名 应用到已生成 URL
	 *
	 * @param string $url 当前 URL
	 * @param string $do 显式域名或带 scheme 的主机
	 * @param string $fallbackOrigin 当前使用的 Origin（可能已是 Api 域名）
	 * @return string
	 * @access private
	 */
	private function applyExplicitOutboundHost(string $url, string $do, string $fallbackOrigin): string
	{
		$do = trim($do);
		if ($do === '') {
			return $url;
		}

		if (stripos($do, 'http') === false) {
			$newOrigin = (isSsl() ? 'https://' : 'http://') . $do;
		} else {
			$newOrigin = rtrim($do, '/');
		}

		if (!preg_match('#^https?://#i', $url)) {
			$url = $fallbackOrigin . (str_starts_with($url, '/') ? $url : '/' . $url);
		}

		return (string)preg_replace('#^https?://[^/]+#i', $newOrigin, $url, 1);
	}

	/**
	 * Api 出站收尾：前缀追加；无显式域名时校正 Api 专用域名
	 *
	 * @param string $url 已组装 URL
	 * @param string $explicitHost 显式 @域名（非空则不覆盖为 API_URL.domain）
	 * @return string
	 * @access private
	 */
	private function finalizeApiOutboundUrl(string $url, string $explicitHost): string
	{
		if (!$this->isApiUrlMode()) {
			return $url;
		}

		$api = $this->apiUrlConfig();
		$prefix = trim((string)($api['prefix'] ?? ''), '/');
		if ($prefix !== '') {
			$url = $this->injectApiPathPrefix($url, $prefix);
		}

		if ($explicitHost !== '') {
			return $url;
		}

		$apiHost = $this->normalizeApiDomainHost((string)($api['domain'] ?? ''));
		if ($apiHost === '' || !preg_match('#^https?://#i', $url)) {
			return $url;
		}

		$scheme = (isSsl() ? 'https://' : 'http://');
		return (string)preg_replace('#^https?://[^/]+#i', $scheme . $apiHost, $url, 1);
	}

	/**
	 * 在 PATHINFO 路径中注入 Api 前缀（跳过已含前缀；插在入口脚本之后）
	 *
	 * 例：/Crm/a.json → /api/v1/Crm/a.json；/index.php/Crm → /index.php/api/v1/Crm
	 *
	 * @param string $url 相对或绝对 URL
	 * @param string $prefix 不含首尾斜杠的前缀
	 * @return string
	 * @access private
	 */
	private function injectApiPathPrefix(string $url, string $prefix): string
	{
		$fragment = '';
		if (str_contains($url, '#')) {
			[$url, $fragment] = explode('#', $url, 2);
			$fragment = '#' . $fragment;
		}

		$query = '';
		if (str_contains($url, '?')) {
			[$url, $query] = explode('?', $url, 2);
			$query = '?' . $query;
		}

		$origin = '';
		$path = $url;
		if (preg_match('#^(https?://[^/]+)(/.*)?$#i', $url, $m)) {
			$origin = $m[1];
			$path = $m[2] ?? '/';
		}

		if ($path === '' || $path === '/') {
			$path = '/' . $prefix . '/';
			return $origin . $path . $query . $fragment;
		}

		$trimmed = ltrim($path, '/');
		if (str_starts_with($trimmed, $prefix . '/') || $trimmed === $prefix) {
			return $origin . $path . $query . $fragment;
		}

		// 入口脚本（非 index.php 时 SCRIPT_NAME 会出现在路径前）之后插入前缀
		if (preg_match('#^((?:/[^/]+\.php)?)(/.*)$#i', $path, $m)) {
			$path = ($m[1] === '' ? '' : $m[1]) . '/' . $prefix . $m[2];
		} else {
			$path = '/' . $prefix . '/' . $trimmed;
		}

		return $origin . $path . $query . $fragment;
	}

	/**
	 * 从 PATH_INFO 提取路由路径与后缀
	 *
	 * @return array{routePath: string, suffix: string}
	 */
	public function extractRoutePath(string $pathInfo): array
	{
		$urlSuffix = '';
		if (stripos($pathInfo, '.') === false) {
			$routePath = trim($pathInfo, '/');
		} else {
			$pathSegments = explode('.', $pathInfo);
			$suffixCandidate = array_pop($pathSegments);
			if (stripos($suffixCandidate, '/') === false) {
				$routePath = str_replace('.' . $suffixCandidate, '', trim($pathInfo, '/'));
				$urlSuffix = $suffixCandidate;
			} else {
				$routePath = trim($pathInfo, '/');
			}
		}

		return ['routePath' => $routePath, 'suffix' => $urlSuffix];
	}

	/**
	 * PATHINFO 模式下将多余 Query 参数合并进路径并 301 重定向
	 */
	public function redirectPathInfoExtraQuery(
		string &$routePath,
		string $urlSuffix,
		string $urlSeparator,
		int $urlMode,
		array $mirrorConfig,
	): void {
		$server = $this->requestServer();
		if (!C('APP_INIT_STATUS') || $urlMode != 1 || C('METHOD') !== 'GET'
			|| isset($mirrorConfig[$server['SERVER_NAME'] ?? ''])) {
			return;
		}

		$sourceGet = $this->requestGet();
		$queryParams = $sourceGet;
		unset($queryParams['g']);

		if (count($queryParams) === 0) {
			return;
		}

		if ((isset($queryParams['add']) && in_array($queryParams['add'], ['', 'addon', 'plug'], true))
			|| (isset($queryParams['init']) && $queryParams['init'] === 'mdblog')) {
			if (isset($queryParams['init']) && $queryParams['init'] === 'mdblog') {
				Markdown::start();
			}
			return;
		}

		$protectedArgNames = is_array(G('protectArgs')) ? G('protectArgs') : [];
		foreach ($queryParams as $paramName => $paramValue) {
			if (empty($paramValue) || in_array($paramName, $protectedArgNames, true)) {
				if (empty($paramValue)) {
					unset($queryParams[$paramName]);
				}
				continue;
			}

			unset($queryParams[$paramName]);
			$routePath .= $urlSeparator . $paramName . $urlSeparator . $paramValue;
		}

		if (count($queryParams) === count($sourceGet)) {
			return;
		}

		$globalBindings = G('bindDomainList') ?: [];
		$bindDomainList = T('bindDomainList', '', is_array($globalBindings) ? $globalBindings : []);
		if (C('APPNAME') !== 'Home'
			&& (empty($bindDomainList[C('APPNAME')]) || $bindDomainList[C('APPNAME')] !== ($server['HTTP_HOST'] ?? ''))) {
			$routePath = C('APPNAME') . $urlSeparator . $routePath;
		}

		$redirectPath = '/' . $routePath . '.' . $urlSuffix
			. (empty($queryParams) ? '' : '?' . http_build_query($queryParams));

		headers('Location:' . $redirectPath);
	}

	/**
	 * 处理 URL 后缀：API 模式、尾斜杠规范化、镜像代理
	 */
	public function applySuffixRules(string $urlSuffix, array $proxyMatch, array $mirrorConfig): void
	{
		$server = $this->requestServer();
		$urlSuffix = strtolower($urlSuffix);
		// RouteContextInit 对两段 MVC 改写跳过拼回后缀时，用此回落识别 API 模式
		if ($urlSuffix === '') {
			$stashed = strtolower((string)C('PATH_INFO_ORIG_SUFFIX', '', ''));
			if ($stashed !== '') {
				$urlSuffix = $stashed;
			}
		}

		match ($urlSuffix) {
			'json', 'xml' => (function () use ($urlSuffix) {
				C('API', $urlSuffix);
				Debug::addMsg('当前：API模式(' . strtoupper($urlSuffix) . '数据格式)');
			})(),
			default => (function () use ($urlSuffix, $proxyMatch, $mirrorConfig) {
				if (function_exists('apiFunc' . ucwords($urlSuffix))) {
					C('API', $urlSuffix);
					Debug::addMsg('当前：API模式(' . strtoupper($urlSuffix) . '数据格式)');
				} elseif (C('APP_INIT_STATUS') && substr((string)($server['PATH_INFO'] ?? ''), -1) !== '/' && $urlSuffix === '') {
					// 验证码等输出接口禁止尾斜杠 302，Nginx/PHP-FPM 下否则会循环重定向并打乱 Session/Cookie
					if (!$this->shouldSkipTrailingSlashRedirect((string)($server['PATH_INFO'] ?? ''))) {
						headers('Location:/' . ucfirst((string)($server['PATH_INFO'] ?? '')) . '/');
					}
				} elseif ($urlSuffix !== '' && C('URL_HTML_SUFFIX') !== '.' . $urlSuffix) {
					$shouldProxy = false;
					$mirrorRules = [];
					$mirrorTarget = $proxyMatch;

					if (count($proxyMatch) > 0) {
						$mirrorTarget = $proxyMatch[0];
						[$scheme, , $host] = explode('/', $mirrorTarget);
						$mirrorDomain = $scheme . '://' . $host;
						$shouldProxy = true;
					} else {
						$mirrorDomain = $server['SERVER_NAME'] ?? '';
					}

					if (isset($mirrorConfig[$mirrorDomain])) {
						$mirrorRules = $mirrorConfig[$mirrorDomain]['rules'];
						$shouldProxy = true;
					}

					if ($shouldProxy) {
						makeMirForSave($mirrorTarget, $mirrorRules);
					} else {
						is_null(C('S404')) && C('S404', 'URL不规范,请检查URL格式.');
					}
				}

				$urlModeMessage = stripos((string)($server['PATH_INFO'] ?? ''), C('URL_HTML_SUFFIX', '', '.html'))
					? '当前：PATHINFO模式(以' . C('URL_HTML_SUFFIX') . '结尾)'
					: '当前：PATHINFO模式.';
				Debug::addMsg($urlModeMessage);
			})()
		};
	}

	/**
	 * 路由对比后规范化路径分隔符
	 *
	 * RouteContextInit 已改写过 PATH_INFO 时跳过二次 contrastRoute，
	 * 避免 Seo/sitemap 等两段结果被 :mark/:filename 误匹配。
	 */
	public function normalizeRoutePath(string $routePath, string $urlSeparator): string
	{
		$contrasted = C('PATH_INFO_ROUTE_LOCKED')
			? $routePath
			: Route::contrastRoute($routePath);

		return $urlSeparator !== '/'
			? str_replace($urlSeparator, '/', $contrasted)
			: $contrasted;
	}

	/**
	 * 无 PATH_INFO 时：QueryString 模式解析、重定向与默认 g/m/a
	 */
	public function parseQueryStringMode(int $urlMode, string $urlSeparator): void
	{
		$server = $this->requestServer();
		if (!empty($server['QUERY_STRING']) && substr_count((string)$server['QUERY_STRING'], '?') > 0) {
			parse_str(str_replace('?', '&', (string)$server['QUERY_STRING']), $parsedQuery);
			$this->replaceRequestGet($parsedQuery);
		}

		if (C('APP_INIT_STATUS') && $urlMode == 1 && !empty($server['QUERY_STRING'])
			&& stripos((string)$server['QUERY_STRING'], '=')) {
			$this->redirectLegacyQueryToPathInfo($urlSeparator);
		} else {
			$this->applyQueryStringDefaults();
		}

		$get = $this->requestGet();
		$get['g'] = empty($get['g']) ? (G('default.app') ?: 'Home') : ucfirst($get['g']);
		$m = empty($get['m']) ? (G('default.control') ?: 'Index') : (string) $get['m'];
		$get['m'] = \Candy\Extend\Str::controlName($m);
		$get['a'] = empty($get['a']) ? (G('default.action') ?: 'index') : $get['a'];
		$this->replaceRequestGet($get);
	}

	/**
	 * Redirect Legacy Query To Path Info
	 * @param mixed $urlSeparator
	 */
	private function redirectLegacyQueryToPathInfo(string $urlSeparator): void
	{
		$sourceGet = $this->requestGet();
		$queryParams = $sourceGet;

		$appName = null;
		if (isset($queryParams['g'])) {
			if (is_null(C('APP')) && $queryParams['g'] !== 'Home') {
				$appName = ucfirst($queryParams['g']);
			}
			unset($queryParams['g']);
		}

		$controllerName = 'Index';
		$actionName = 'Index';
		if (isset($queryParams['m'])) {
			// 保留多驼峰：DemoItem 不可压成 Demoitem
			$controllerName = \Candy\Extend\Str::controlName((string) $queryParams['m']);
			unset($queryParams['m']);
			$actionName = isset($queryParams['a']) ? strtolower($queryParams['a']) : 'Index';
			unset($queryParams['a']);
		}

		$htmlSuffix = C('URL_HTML_SUFFIX', '', '.html');
		if (isset($queryParams['format'])) {
			$htmlSuffix = '.' . strtolower($queryParams['format']);
			unset($queryParams['format']);
		}

		$protectedParams = [];
		$protectedArgNames = is_array(G('protectArgs')) ? G('protectArgs') : [];
		if (!empty($protectedArgNames)) {
			foreach ($protectedArgNames as $protectedName) {
				if (isset($queryParams[$protectedName])) {
					$protectedParams[$protectedName] = $queryParams[$protectedName];
					unset($queryParams[$protectedName]);
				}
			}
		}

		if ((isset($queryParams['add']) && in_array($queryParams['add'], ['', 'addon', 'plug'], true))
			|| (isset($queryParams['init']) && $queryParams['init'] === 'mdblog')) {
			if (isset($queryParams['init']) && $queryParams['init'] === 'mdblog') {
				Markdown::start();
			}
			return;
		}

		if (count($queryParams) === 0 && !isset($sourceGet['g']) && !isset($sourceGet['m']) && !isset($sourceGet['a'])) {
			return;
		}

		$remainingQuery = count($queryParams) > 0 ? http_build_query($queryParams) : '';

		$routePath = $appName ? $appName . $urlSeparator : '';
		$routePath .= ($controllerName ?: (G('default.control') ?: 'Index')) . $urlSeparator;
		$routePath .= ($actionName ?: (G('default.action') ?: 'index')) . $urlSeparator;

		if (in_array(str_replace($urlSeparator, '/', $routePath), ['Home/Index/index/', 'Index/index/'], true)
			&& $remainingQuery === '') {
			$routePath = '';
		} else {
			$routePath .= str_replace(['&', '='], $urlSeparator, $remainingQuery);
		}

		$redirectUrl = '/';
		$server = $this->requestServer();
		if (($server['SCRIPT_NAME'] ?? '/index.php') !== '/index.php') {
			$redirectUrl = (string)$server['SCRIPT_NAME'];
		}

		$redirectUrl .= $routePath === '' ? '' : (rtrim($routePath, $urlSeparator) . $htmlSuffix);

		if (!empty($protectedParams)) {
			$redirectUrl .= '?' . http_build_query($protectedParams);
		}
		headers('Location:' . $redirectUrl);
	}

	/**
	 * Apply Query String Defaults
	 */
	private function applyQueryStringDefaults(): void
	{
		$get = $this->requestGet();
		if (isset($get['format']) && in_array(strtolower($get['format']), ['json', 'xml'], true)) {
			if (strtolower($get['format']) === 'json') {
				C('API', 'json');
				Debug::addMsg('当前：API模式(JSON数据格式)');
			} else {
				C('API', 'xml');
				Debug::addMsg('当前：API模式(XML数据格式)');
			}
			unset($get['format']);
		} elseif (!empty($get)) {
			Debug::addMsg('当前：常规URL传参模式');
		}

		if (isset($get['sessionid'])) {
			$sessionId = $get['sessionid'];
			unset($get['sessionid']);
			C('SESSID', $sessionId);
		}
		$this->replaceRequestGet($get);
	}

	/**
	 * 是否跳过 PATHINFO 尾斜杠规范化重定向
	 *
	 * 登录验证码等接口输出二进制内容，重定向会导致 Session/Cookie 错乱
	 *
	 * @param string $pathInfo PATH_INFO 路径
	 * @return bool
	 */
	private function shouldSkipTrailingSlashRedirect(string $pathInfo): bool
	{
		$path = strtolower(trim($pathInfo, '/'));
		// 验证码等输出接口禁止尾斜杠 302
		if (str_ends_with($path, 'login/code')
			|| str_ends_with($path, '/code')
			|| $path === 'code') {
			return true;
		}
		// CMS：/sitemap.xml、/robots.txt 路由剥扩展名后为 Seo/sitemap|robots，禁止污染 Location 头
		if ($path === 'seo/sitemap' || $path === 'seo/robots'
			|| str_ends_with($path, '/seo/sitemap')
			|| str_ends_with($path, '/seo/robots')) {
			return true;
		}
		// CMS 落地页友好路由映射为 Landing/index/slug/{slug}，继续规范化会自重定向。
		if ($path === 'landing/index' || str_starts_with($path, 'landing/index/slug/')) {
			return true;
		}
		// OAuth 2.1 / OIDC 与统一账号端点：正则路由匹配后尾部斜杠被剥离，
		// 放行尾斜杠重定向会形成无限循环
		if (str_starts_with($path, 'oauth/')
			|| str_starts_with($path, 'account/')
			|| $path === '.well-known/openid-configuration') {
			return true;
		}
		// Apidoc 文档站接口：Route::rule 回调，禁止尾斜杠 302（会打成 Location://）
		if ($path === 'apidoc' || str_starts_with($path, 'apidoc/')) {
			return true;
		}
		return false;
	}

	/**
	 * 判断出站 URL 是否可以省略应用路径段
	 *
	 * Portal 绑定租户域名根路径，跨应用生成 Portal 控制器地址时可以省略 Portal 段。
	 * 显式业务应用（如 /Crm/...）虽然会被域名中间件登记为同域路由别名，但其
	 * 控制器仍必须保留应用段；只有路由上下文明确把该应用作为根应用时才允许省略。
	 *
	 * @param string $appName 目标应用名
	 * @param array $server 请求 server 快照
	 * @return bool 是否省略应用段
	 * @access private
	 */
	private function shouldOmitAppPathSegment(string $appName, array $server): bool
	{
		$appName = trim($appName);
		if ($appName === '') {
			return false;
		}
		if (strcasecmp($appName, 'Portal') !== 0
			&& (is_null(C('APP')) || strcasecmp((string)C('APP'), $appName) !== 0)
		) {
			return false;
		}
		$globalBindings = G('bindDomainList') ?: [];
		$bindDomainList = T('bindDomainList', '', is_array($globalBindings) ? $globalBindings : []);
		if (!is_array($bindDomainList)) {
			$bindDomainList = [];
		}
		$currentHost = strtolower((string)preg_replace('/:\\d+$/', '', (string)($server['HTTP_HOST'] ?? '')));
		$boundHost = strtolower((string)($bindDomainList[$appName] ?? ''));
		return $boundHost !== '' && $boundHost === $currentHost;
	}
}
