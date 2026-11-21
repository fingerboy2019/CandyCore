<?php
/**
 * Client 客户端信息获取类
 *
 * 功能描述：采集与解析访客 IP、浏览器、操作系统、地理位置、语言与来源。
 *
 * 主要能力：
 * - IP：支持 IPv4/IPv6、可信代理链（X-Forwarded-For 等）、请求级缓存
 * - 浏览器 / 操作系统：现代 UA 识别顺序（Edge/Edg、Chrome、微信等）
 * - 地理位置：私网判定 + 原生 curl 短超时查询（可自定义 API；失败不中断主流程）
 * - 语言 / Referer / UA 原文读取
 *
 * @package Candy\Extend\Network
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-09-09 16:07:16
 * @final 此类不可被继承
 */

declare(strict_types=1);
namespace Candy\Extend\Network;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Client {

	/** @var array<string, string> 请求内 IP 缓存，键为 "adv:type" */
	private static array $ipCache = [];

	/** @var array<string, string> 请求内地理位置缓存 */
	private static array $locationCache = [];

	/** @var string|null 当前请求标识（Workerman 长驻进程下用于失效 static 缓存） */
	private static ?string $requestCacheKey = null;

	/**
	 * 读取当前请求的 SERVER 快照
	 *
	 * Swoole 协程中必须从 R() 取得不可串扰的请求数据；传统 Web/FPM
	 * 及非协程 CLI 仍保持 $_SERVER 的既有兼容行为。
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestServer(): array
	{
		return I('server.', [], '');
	}

	/**
	 * 是否在 Swoole 协程请求内
	 *
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function inCoroutineRequest(): bool
	{
		return \Candy\Core\Request::inCoroutine();
	}

	/**
	 * 获取 IP 请求缓存
	 *
	 * @return array<string,string>
	 * @access private
	 * @static
	 */
	private static function getIpCache(): array
	{
		if (self::inCoroutineRequest()) {
			return (array) \C('NETWORK_CLIENT_IP_CACHE', '', []);
		}
		return self::$ipCache;
	}

	/**
	 * 保存 IP 请求缓存
	 *
	 * @param array<string,string> $cache 缓存内容
	 * @return void
	 * @access private
	 * @static
	 */
	private static function setIpCache(array $cache): void
	{
		if (self::inCoroutineRequest()) {
			\C('NETWORK_CLIENT_IP_CACHE', $cache);
			return;
		}
		self::$ipCache = $cache;
	}

	/**
	 * 获取 IP 地理位置请求缓存
	 *
	 * @return array<string,string>
	 * @access private
	 * @static
	 */
	private static function getLocationCache(): array
	{
		if (self::inCoroutineRequest()) {
			return (array) \C('NETWORK_CLIENT_LOCATION_CACHE', '', []);
		}
		return self::$locationCache;
	}

	/**
	 * 保存 IP 地理位置请求缓存
	 *
	 * @param array<string,string> $cache 缓存内容
	 * @return void
	 * @access private
	 * @static
	 */
	private static function setLocationCache(array $cache): void
	{
		if (self::inCoroutineRequest()) {
			\C('NETWORK_CLIENT_LOCATION_CACHE', $cache);
			return;
		}
		self::$locationCache = $cache;
	}

	/**
	 * 请求切换时清空 IP/地理 static 缓存，避免 Workerman 下串请求
	 *
	 * @return void
	 * @access private
	 * @static
	 */
	private static function syncRequestCache(): void
	{
		if (self::inCoroutineRequest()) {
			return;
		}
		$server = self::requestServer();
		$key = (string) ($server['REQUEST_TIME_FLOAT'] ?? $server['REQUEST_TIME'] ?? '')
			. '|' . ($server['REMOTE_ADDR'] ?? '')
			. '|' . ($server['HTTP_X_FORWARDED_FOR'] ?? '')
			. '|' . ($server['HTTP_X_REAL_IP'] ?? '')
			. '|' . ($server['HTTP_CF_CONNECTING_IP'] ?? '');
		if (self::$requestCacheKey !== $key) {
			self::$requestCacheKey = $key;
			self::$ipCache = [];
			self::$locationCache = [];
		}
	}

	/**
	 * 显式重置请求内缓存（可选，供 app_begin 等钩子调用）
	 *
	 * @return void
	 */
	public static function resetRequestCache(): void
	{
		if (self::inCoroutineRequest()) {
			\C('NETWORK_CLIENT_IP_CACHE', []);
			\C('NETWORK_CLIENT_LOCATION_CACHE', []);
			return;
		}
		self::$requestCacheKey = null;
		self::$ipCache = [];
		self::$locationCache = [];
	}

	/**
	 * 获取 Accept-Language 原始值
	 *
	 * @return string 完整 Accept-Language，无则空串
	 */
	public static function getUserLang(): string
	{
		$server = self::requestServer();
		$lang = (string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? '');
		return $lang === '' ? '' : htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * 解析首选语言标签（如 zh-CN）
	 *
	 * 取 q 值最高的一项；缺省权重按 1.0 处理
	 *
	 * @return string 首选语言标签，无法解析时返回空串
	 */
	public static function getPreferredLang(): string
	{
		$server = self::requestServer();
		$raw = (string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? '');
		if ($raw === '') {
			return '';
		}
		$best = '';
		$bestQ = -1.0;
		foreach (explode(',', $raw) as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}
			$bits = explode(';', $part);
			$tag = trim($bits[0]);
			$q = 1.0;
			if (isset($bits[1]) && preg_match('/q\s*=\s*([0-9.]+)/i', $bits[1], $m)) {
				$q = (float) $m[1];
			}
			if ($tag !== '' && $q > $bestQ) {
				$bestQ = $q;
				$best = $tag;
			}
		}
		return $best;
	}

	/**
	 * 获取客户端 IP
	 *
	 * $adv=true（默认）时：仅当 REMOTE_ADDR 为可信代理（配置或私网网关）才解析
	 * CF-Connecting-IP / True-Client-IP / X-Real-IP / X-Forwarded-For 链，取「自右向左第一个非可信代理」地址
	 * $adv=false 时：仅返回 REMOTE_ADDR
	 * 结果做 filter_var 校验；请求内静态缓存（不再写入 session，避免 type/adv 串值）
	 *
	 * @param int $type 0=IP 字符串；1=IPv4 无符号长整型字符串（IPv6 时返回 "0"）
	 * @param bool $adv 是否解析代理头
	 * @return string
	 */
	public static function getClientIp(int $type = 0, bool $adv = true): string
	{
		self::syncRequestCache();
		$type = $type ? 1 : 0;
		$cacheKey = ($adv ? '1' : '0') . ':' . $type;
		$ipCache = self::getIpCache();
		if (isset($ipCache[$cacheKey])) {
			return $ipCache[$cacheKey];
		}

		$ip = self::resolveClientIp($adv);
		if ($type === 1) {
			$long = self::ipv4ToLong($ip);
			$result = $long !== null ? (string) $long : '0';
		} else {
			$result = $ip;
		}

		$ipCache[$cacheKey] = $result;
		self::setIpCache($ipCache);
		return $result;
	}

	/**
	 * 解析客户端 IP（不做 type 转换）
	 *
	 * @param bool $adv 是否解析代理头
	 * @return string 合法 IP，失败时 127.0.0.1
	 * @access private
	 * @static
	 */
	private static function resolveClientIp(bool $adv): string
	{
		$server = self::requestServer();
		$remote = self::normalizeIp((string) ($server['REMOTE_ADDR'] ?? ''));
		if ($remote === '') {
			$remote = '127.0.0.1';
		}

		if (!$adv) {
			return $remote;
		}

		// 仅当直连端是可信代理时，才采信转发头，防止客户端伪造 XFF
		if (!self::isTrustedProxy($remote)) {
			return $remote;
		}

		$candidates = self::collectForwardedIps();
		// 链尾追加 REMOTE_ADDR，自右向左跳过可信代理
		$candidates[] = $remote;
		for ($i = count($candidates) - 1; $i >= 0; $i--) {
			$ip = self::normalizeIp($candidates[$i]);
			if ($ip === '') {
				continue;
			}
			if (!self::isTrustedProxy($ip)) {
				return $ip;
			}
		}

		return $remote;
	}

	/**
	 * 收集转发头中的 IP 列表（顺序：左=更靠近客户端）
	 *
	 * @return list<string>
	 * @access private
	 * @static
	 */
	private static function collectForwardedIps(): array
	{
		$server = self::requestServer();
		// 云 / CDN 单值头优先拼到链左侧
		foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP'] as $key) {
			if (!empty($server[$key])) {
				$ip = self::normalizeIp((string) $server[$key]);
				if ($ip !== '') {
					return [$ip];
				}
			}
		}

		$xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
		if ($xff === '') {
			$client = (string) ($server['HTTP_CLIENT_IP'] ?? '');
			$ip = self::normalizeIp($client);
			return $ip !== '' ? [$ip] : [];
		}

		$list = [];
		foreach (explode(',', $xff) as $part) {
			$ip = self::normalizeIp(trim($part));
			if ($ip !== '' && strcasecmp($ip, 'unknown') !== 0) {
				$list[] = $ip;
			}
		}
		return $list;
	}

	/**
	 * 规范化并校验 IP（支持带端口的 IPv4、[IPv6]:port）
	 *
	 * @param string $ip 原始地址
	 * @return string 合法 IP 或空串
	 * @access public
	 * @static
	 */
	public static function normalizeIp(string $ip): string
	{
		$ip = trim($ip);
		if ($ip === '') {
			return '';
		}
		// IPv4:port
		if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m)) {
			$ip = $m[1];
		}
		// [IPv6]:port
		if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $ip, $m)) {
			$ip = $m[1];
		}
		if (filter_var($ip, FILTER_VALIDATE_IP)) {
			return $ip;
		}
		return '';
	}

	/**
	 * 是否私网 / 环回 / 链路本地（含 IPv6）
	 *
	 * @param string $ip IP 地址
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isPrivateIp(string $ip): bool
	{
		$ip = self::normalizeIp($ip);
		if ($ip === '') {
			return false;
		}
		return filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) === false;
	}

	/**
	 * REMOTE_ADDR 是否视为可信反向代理
	 *
	 * 配置 Client.inc.php 的 trusted_proxies（IP 或 CIDR）；未配置时：私网/环回视为本机网关（常见 Nginx 同机反代）
	 *
	 * @param string $ip 直连 IP
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function isTrustedProxy(string $ip): bool
	{
		$ip = self::normalizeIp($ip);
		if ($ip === '') {
			return false;
		}
		$cfg = self::config();
		$trusted = $cfg['trusted_proxies'] ?? null;
		if (is_array($trusted) && $trusted !== []) {
			foreach ($trusted as $rule) {
				$rule = trim((string) $rule);
				if ($rule === '') {
					continue;
				}
				if ($rule === $ip || self::ipInRange($ip, $rule)) {
					return true;
				}
			}
			return false;
		}
		// 默认：同机 / 内网反代可信；公网直连则不采信伪造头
		return self::isPrivateIp($ip);
	}

	/**
	 * IPv4 转无符号长整型；非 IPv4 返回 null
	 *
	 * @param string $ip IP
	 * @return int|null
	 * @access private
	 * @static
	 */
	private static function ipv4ToLong(string $ip): ?int
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			return null;
		}
		$long = ip2long($ip);
		if ($long === false) {
			return null;
		}
		return (int) sprintf('%u', $long);
	}

	/**
	 * 读取可选配置 Source/Inc/Client.inc.php
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function config(): array
	{
		if (!function_exists('loadConfig')) {
			return [];
		}
		$cfg = loadConfig('Client');
		return is_array($cfg) ? $cfg : [];
	}

	/**
	 * 查询 IP 地理位置文案
	 *
	 * 私网直接返回中文说明；公网用原生 curl 短超时拉取（禁止回调全局 getLocation，
	 * 也禁止走 Http::get——后者失败会 Debug::showMessage 直接中断登录/写日志）。
	 * 太平洋接口默认 GBK，自动转 UTF-8。
	 *
	 * @param string $ip 空则取当前客户端 IP
	 * @param string $api 自定义 URL，可用 {ip} 占位
	 * @return string
	 */
	public static function getLocation(string $ip = '', string $api = ''): string
	{
		self::syncRequestCache();
		if ($ip === '') {
			$ip = self::getClientIp();
		}
		$ip = self::normalizeIp($ip);
		if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
			return '本机地址';
		}
		if (self::isPrivateIp($ip)) {
			return '局域网地址';
		}
		$locationCache = self::getLocationCache();
		if (isset($locationCache[$ip])) {
			return $locationCache[$ip];
		}

		$text = '';
		// 禁止回调全局 getLocation()：无 IP2Location 时会回落到本方法形成死递归。
		// 禁止走 Http::get()：curl/DNS 失败会 Debug::showMessage 直接中断业务请求。
		if ($api === '') {
			$cfg = self::config();
			$api = (string) ($cfg['location_api'] ?? 'https://whois.pconline.com.cn/ipJson.jsp?json=true&ip={ip}');
		}
		$url = str_replace('{ip}', rawurlencode($ip), $api);
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			if ($ch !== false) {
				curl_setopt_array($ch, [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_CONNECTTIMEOUT => 2,
					CURLOPT_TIMEOUT => 2,
					CURLOPT_HTTPHEADER => ['Accept: */*', 'User-Agent: CandyClient/1.0'],
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_SSL_VERIFYHOST => 0,
				]);
				$body = curl_exec($ch);
				$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if ($body !== false && $code >= 200 && $code < 300 && $body !== '') {
					$text = self::parseLocationBody((string) $body, $ip);
				}
			}
		}

		$text = trim($text);
		if ($text === '') {
			$text = '未知';
		}
		$locationCache[$ip] = $text;
		self::setLocationCache($locationCache);
		return $text;
	}

	/**
	 * 解析地理接口响应（JSON 或纯文本，兼容 GBK）
	 *
	 * @param string $body 响应体
	 * @param string $ip 查询 IP
	 * @return string
	 * @access private
	 * @static
	 */
	private static function parseLocationBody(string $body, string $ip): string
	{
		$utf8 = $body;
		if (!mb_check_encoding($body, 'UTF-8')) {
			$converted = @mb_convert_encoding($body, 'UTF-8', 'GBK,GB2312,UTF-8');
			if (is_string($converted) && $converted !== '') {
				$utf8 = $converted;
			}
		}
		$utf8 = trim($utf8);
		$json = json_decode($utf8, true);
		if (is_array($json)) {
			$parts = array_filter([
				(string) ($json['pro'] ?? $json['region'] ?? ''),
				(string) ($json['city'] ?? ''),
				(string) ($json['addr'] ?? $json['isp'] ?? ''),
			], static fn(string $s): bool => $s !== '');
			if ($parts !== []) {
				return implode(' ', $parts);
			}
			if (!empty($json['addr'])) {
				return (string) $json['addr'];
			}
		}
		// 去掉接口可能回显的 IP 前缀噪音
		$utf8 = preg_replace('/^\s*' . preg_quote($ip, '/') . '\s*/', '', $utf8) ?? $utf8;
		return trim($utf8);
	}

	/**
	 * 获取 HTTP Referer（已转义）
	 *
	 * @return string
	 */
	public static function getUrlSource(): string
	{
		$server = self::requestServer();
		$ref = (string) ($server['HTTP_REFERER'] ?? '');
		return $ref === '' ? '' : htmlspecialchars($ref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * 获取 User-Agent（已转义，展示用）
	 *
	 * @return string
	 */
	public static function getUserAgent(): string
	{
		$ua = self::rawUserAgent();
		return $ua === '' ? '' : htmlspecialchars($ua, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * 原始 UA（解析用，不转义）
	 *
	 * @return string
	 * @access private
	 * @static
	 */
	private static function rawUserAgent(): string
	{
		$server = self::requestServer();
		return (string) ($server['HTTP_USER_AGENT'] ?? '');
	}

	/**
	 * 识别浏览器名称与主次版本
	 *
	 * 匹配顺序：微信 → Edge(Edg) → Opera → Chrome/CriOS → Firefox → Safari → IE/Trident → 国产壳
	 *
	 * @return string 如 "Chrome 119.0"，未知为 Others
	 */
	public static function getUserBrowser(): string
	{
		$ua = self::rawUserAgent();
		if ($ua === '') {
			return 'Others';
		}

		if (stripos($ua, 'MicroMessenger') !== false) {
			if (preg_match('/MicroMessenger\/([\d.]+)/i', $ua, $m)) {
				return '微信 ' . $m[1];
			}
			return '微信';
		}

		// Chromium Edge（须先于 Chrome）
		if (preg_match('/Edg(?:e|A|iOS)?\/([\d.]+)/i', $ua, $m)) {
			return 'Edge ' . self::shortVersion($m[1]);
		}
		if (preg_match('/Edge\/([\d.]+)/i', $ua, $m)) {
			return 'Edge ' . self::shortVersion($m[1]);
		}

		if (preg_match('/OPR\/([\d.]+)/i', $ua, $m) || preg_match('/Opera\/([\d.]+)/i', $ua, $m)) {
			return 'Opera ' . self::shortVersion($m[1]);
		}
		if (preg_match('/Opera.+Version\/([\d.]+)/i', $ua, $m)) {
			return 'Opera ' . self::shortVersion($m[1]);
		}

		if (preg_match('/(?:Chrome|CriOS)\/([\d.]+)/i', $ua, $m) && stripos($ua, 'Chromium') === false) {
			// 排除已匹配的 Edge/Opera
			if (stripos($ua, 'Edg') === false && stripos($ua, 'OPR/') === false) {
				return 'Chrome ' . self::shortVersion($m[1]);
			}
		}

		if (preg_match('/Firefox\/([\d.]+)/i', $ua, $m) || preg_match('/FxiOS\/([\d.]+)/i', $ua, $m)) {
			return 'Firefox ' . self::shortVersion($m[1]);
		}

		if (preg_match('/MSIE\s([\d.]+)/i', $ua, $m) || preg_match('/Trident.*rv:([\d.]+)/i', $ua, $m)) {
			return 'IE ' . self::shortVersion($m[1]);
		}

		// 纯 Safari（含 Version/，且无 Chrome）
		if (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false && stripos($ua, 'Chromium') === false) {
			if (preg_match('/Version\/([\d.]+)/i', $ua, $m)) {
				return 'Safari ' . self::shortVersion($m[1]);
			}
			return 'Safari';
		}

		if (stripos($ua, '360SE') !== false || stripos($ua, '360EE') !== false) {
			return '360浏览器';
		}
		if (stripos($ua, 'MetaSr') !== false || stripos($ua, 'SE 2.') !== false || stripos($ua, 'SogouMobileBrowser') !== false) {
			return '搜狗浏览器';
		}
		if (preg_match('/UCBrowser\/([\d.]+)/i', $ua, $m) || preg_match('/UC\s?Browser\/([\d.]+)/i', $ua, $m)) {
			return 'UC ' . self::shortVersion($m[1]);
		}
		if (stripos($ua, 'QQBrowser') !== false || stripos($ua, 'MQQBrowser') !== false) {
			if (preg_match('/(?:MQQBrowser|QQBrowser)\/([\d.]+)/i', $ua, $m)) {
				return 'QQ浏览器 ' . self::shortVersion($m[1]);
			}
			return 'QQ浏览器';
		}

		return 'Others';
	}

	/**
	 * 识别操作系统
	 *
	 * 顺序：iOS → Android → Windows → macOS → 常见 Linux/BSD
	 *
	 * @return string
	 */
	public static function getUserOS(): string
	{
		$ua = self::rawUserAgent();
		if ($ua === '') {
			return 'Others';
		}

		// iOS 须先于 Mac
		if (preg_match('/\b(iPhone|iPad|iPod)\b/i', $ua)) {
			if (preg_match('/OS\s([\d_]+)/i', $ua, $m)) {
				return 'iOS ' . str_replace('_', '.', $m[1]);
			}
			return 'iOS';
		}

		// Android 须先于 Linux
		if (preg_match('/Android\s([\d.]+)/i', $ua, $m)) {
			return 'Android ' . $m[1];
		}
		if (stripos($ua, 'Android') !== false) {
			return 'Android';
		}

		if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
			$map = [
				'10.0' => 'Windows 10/11',
				'6.3' => 'Windows 8.1',
				'6.2' => 'Windows 8',
				'6.1' => 'Windows 7',
				'6.0' => 'Windows Vista',
				'5.1' => 'Windows XP',
				'5.0' => 'Windows 2000',
			];
			$ver = $m[1];
			return $map[$ver] ?? ('Windows NT ' . $ver);
		}
		if (stripos($ua, 'Windows') !== false) {
			return 'Windows';
		}

		if (preg_match('/Mac OS X ([\d._]+)/i', $ua, $m) || preg_match('/Macintosh.*OS X ([\d._]+)/i', $ua, $m)) {
			return 'macOS ' . str_replace('_', '.', $m[1]);
		}
		if (stripos($ua, 'Macintosh') !== false) {
			return 'macOS';
		}

		foreach ([
			'Ubuntu' => '/Ubuntu[\/\s]?([\d.]+)?/i',
			'Debian' => '/Debian[\/\s]?([\d.]+)?/i',
			'Fedora' => '/Fedora/i',
			'CentOS' => '/CentOS/i',
			'FreeBSD' => '/FreeBSD/i',
			'OpenBSD' => '/OpenBSD/i',
			'NetBSD' => '/NetBSD/i',
		] as $name => $pattern) {
			if (preg_match($pattern, $ua, $m)) {
				return !empty($m[1]) ? ($name . ' ' . $m[1]) : $name;
			}
		}

		if (stripos($ua, 'Linux') !== false || stripos($ua, 'X11') !== false) {
			return 'Linux';
		}
		if (stripos($ua, 'CrOS') !== false) {
			return 'ChromeOS';
		}

		return 'Others';
	}

	/**
	 * 截取主.次版本，避免过长 build 号
	 *
	 * @param string $version 完整版本
	 * @return string
	 * @access private
	 * @static
	 */
	private static function shortVersion(string $version): string
	{
		if (preg_match('/^(\d+)(?:\.(\d+))?/', $version, $m)) {
			return isset($m[2]) ? ($m[1] . '.' . $m[2]) : $m[1];
		}
		return $version;
	}

	/**
	 * 判断 IP 是否落在 CIDR / 通配符范围（IPv4；IPv6 仅支持精确相等）
	 *
	 * @param string $ip 待测 IP
	 * @param string $range 如 192.168.0.0/16、10.0.*.*、或单个 IP
	 * @return bool
	 */
	public static function ipInRange(string $ip, string $range): bool
	{
		$ip = self::normalizeIp($ip);
		$range = trim($range);
		if ($ip === '' || $range === '') {
			return false;
		}

		// 精确匹配（含 IPv6）
		if ($ip === $range) {
			return true;
		}

		// IPv6：仅精确或简单前缀（未实现完整 CIDR 时保守返回 false）
		if (str_contains($ip, ':') || str_contains($range, ':')) {
			if (str_contains($range, '/') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				return self::ipv6InCidr($ip, $range);
			}
			return false;
		}

		if (str_contains($range, '/')) {
			[$subnet, $bits] = explode('/', $range, 2);
			$subnet = self::normalizeIp($subnet);
			$bits = (int) $bits;
			if ($subnet === '' || $bits < 0 || $bits > 32) {
				return false;
			}
			$ipLong = ip2long($ip);
			$subnetLong = ip2long($subnet);
			if ($ipLong === false || $subnetLong === false) {
				return false;
			}
			$mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
			return ($ipLong & $mask) === ($subnetLong & $mask);
		}

		if (str_contains($range, '*')) {
			$regex = '/^' . str_replace(['*', '.'], ['[0-9]+', '\.'], $range) . '$/';
			return preg_match($regex, $ip) === 1;
		}

		return false;
	}

	/**
	 * IPv6 CIDR 判定
	 *
	 * @param string $ip IPv6
	 * @param string $cidr 如 2001:db8::/32
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function ipv6InCidr(string $ip, string $cidr): bool
	{
		[$subnet, $bits] = explode('/', $cidr, 2);
		$bits = (int) $bits;
		$subnet = self::normalizeIp($subnet);
		if ($subnet === '' || $bits < 0 || $bits > 128) {
			return false;
		}
		$ipBin = @inet_pton($ip);
		$subBin = @inet_pton($subnet);
		if ($ipBin === false || $subBin === false) {
			return false;
		}
		$bytes = intdiv($bits, 8);
		$remain = $bits % 8;
		if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
			return false;
		}
		if ($remain === 0) {
			return true;
		}
		$mask = (0xFF << (8 - $remain)) & 0xFF;
		return (ord($ipBin[$bytes]) & $mask) === (ord($subBin[$bytes]) & $mask);
	}
}
