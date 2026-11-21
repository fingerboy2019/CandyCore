<?php
/***
 * Http CURL 选项装配
 *
 * 业务说明：统一 request/async 的 curl_setopt 逻辑、超时与 SSL 默认策略
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 19:19:11 $
 *
 * @package Candy\Extend\Network\Trait
 */

declare(strict_types=1);

namespace Candy\Extend\Network\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait HttpCurlOptions
{
	/**
	 * 解析连接超时与传输超时（秒）
	 *
	 * connect_timeout 可单独指定；未指定时与 timeout 相同（默认 15）
	 *
	 * @param array<string,mixed> $options 请求选项
	 * @return array{0:int,1:int} [connect_timeout, timeout]
	 */
	public static function resolveTimeouts(array $options): array
	{
		$timeout = isset($options['timeout']) ? (int) $options['timeout'] : 15;
		if ($timeout < 1) {
			$timeout = 15;
		}
		$connect = isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : $timeout;
		if ($connect < 1) {
			$connect = $timeout;
		}
		return [$connect, $timeout];
	}

	/**
	 * 是否开启 SSL 服务端证书校验
	 *
	 * @param array<string,mixed> $options 请求选项
	 * @return bool
	 */
	public static function shouldVerifySsl(array $options): bool
	{
		unset($options);
		return true;
	}

	/**
	 * 装配通用 CURL 选项（不含 URL、RETURNTRANSFER 流式分支）
	 *
	 * @param \CurlHandle|resource $ch curl 句柄
	 * @param string $method HTTP 方法
	 * @param string $url 请求 URL（User-Agent 选用）
	 * @param array<string,mixed> $options 请求选项
	 * @return void
	 */
	private static function applyCurlOptions($ch, string $method, string $url, array $options): void
	{
		// 默认不跟随重定向，避免配置地址跳转到未校验的主机。
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
		curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

		if (empty($options['http_version'])) {
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		} else {
			curl_setopt($ch, CURLOPT_HTTP_VERSION, $options['http_version']);
		}

		if (empty($options['max_redirs'])) {
			curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		} else {
			curl_setopt($ch, CURLOPT_MAXREDIRS, $options['max_redirs']);
		}

		if (empty($options['encoding'])) {
			curl_setopt($ch, CURLOPT_ENCODING, '');
		} else {
			curl_setopt($ch, CURLOPT_ENCODING, $options['encoding']);
		}

		if (empty($options['headers'])) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: ' . self::getUserAgent($method, $url)]);
		} else {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
		}

		if (empty($options['referer'])) {
			curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		} else {
			curl_setopt($ch, CURLOPT_REFERER, $options['referer']);
		}

		if (empty($options['cookie']) === false) {
			curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
		}

		if (empty($options['cookie_file']) === false) {
			curl_setopt($ch, CURLOPT_COOKIEJAR, $options['cookie_file']);
			curl_setopt($ch, CURLOPT_COOKIEFILE, $options['cookie_file']);
		}

		self::applyTimeouts($ch, $options);

		if (empty($options['port']) === false) {
			curl_setopt($ch, CURLOPT_PORT, $options['port']);
		}

		if (empty($options['low_speed_limit']) === false) {
			curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, $options['low_speed_limit']);
		}
		if (empty($options['low_speed_time']) === false) {
			curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, $options['low_speed_time']);
		}

		if (empty($options['range']) === false) {
			curl_setopt($ch, CURLOPT_RANGE, $options['range']);
		}

		if (empty($options['resume_from']) === false) {
			curl_setopt($ch, CURLOPT_RESUME_FROM, $options['resume_from']);
		}

		if (empty($options['time_condition']) === false) {
			curl_setopt($ch, CURLOPT_TIMECONDITION, $options['time_condition']);
		}

		if (empty($options['time_value']) === false) {
			curl_setopt($ch, CURLOPT_TIMEVALUE, $options['time_value']);
		}

		if (empty($options['http_auth']) === false) {
			curl_setopt($ch, CURLOPT_HTTPAUTH, $options['http_auth']);
		}

		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		if (strtolower($method) === 'head') {
			curl_setopt($ch, CURLOPT_NOBODY, 1);
		} elseif (isset($options['data'])) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, self::buildQueryData($options['data']));
		}

		if (empty($options['upload']) === false) {
			curl_setopt($ch, CURLOPT_UPLOAD, $options['upload']);
		}

		if (empty($options['infile_size']) === false) {
			curl_setopt($ch, CURLOPT_INFILESIZE, $options['infile_size']);
		}

		if (empty($options['file_time']) === false) {
			curl_setopt($ch, CURLOPT_FILETIME, $options['file_time']);
		}

		if (empty($options['no_progress']) === false) {
			curl_setopt($ch, CURLOPT_NOPROGRESS, $options['no_progress']);
		}

		if (empty($options['progress_function']) === false) {
			curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $options['progress_function']);
		}

		if (empty($options['proxy']) === false) {
			[$ip, $port] = explode(':', (string) $options['proxy'], 2);
			curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_PROXY, $ip);
			curl_setopt($ch, CURLOPT_PROXYPORT, $port);
			curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
			if (empty($options['proxypass']) === false) {
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $options['proxypass']);
			}
		}

		if (empty($options['dns_cache_timeout']) === false) {
			curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, $options['dns_cache_timeout']);
		}

		if (empty($options['forbid_reuse']) === false) {
			curl_setopt($ch, CURLOPT_FORBID_REUSE, $options['forbid_reuse']);
		}
		if (empty($options['fresh_connect']) === false) {
			curl_setopt($ch, CURLOPT_FRESH_CONNECT, $options['fresh_connect']);
		}

		if (empty($options['ca_info']) === false) {
			curl_setopt($ch, CURLOPT_CAINFO, $options['ca_info']);
		}

		if (empty($options['ca_path']) === false) {
			curl_setopt($ch, CURLOPT_CAPATH, $options['ca_path']);
		}

		if (empty($options['stderr']) === false) {
			curl_setopt($ch, CURLOPT_STDERR, $options['stderr']);
		}

		if (empty($options['fail_on_error']) === false) {
			curl_setopt($ch, CURLOPT_FAILONERROR, $options['fail_on_error']);
		}

		if (empty($options['ssl_version']) === false) {
			curl_setopt($ch, CURLOPT_SSLVERSION, $options['ssl_version']);
		}

		if (empty($options['safe_upload']) === false) {
			curl_setopt($ch, CURLOPT_SAFE_UPLOAD, $options['safe_upload']);
		}

		if (empty($options['ip_resolve']) === false) {
			curl_setopt($ch, CURLOPT_IPRESOLVE, $options['ip_resolve']);
		}

		if (empty($options['proxy_tunnel']) === false) {
			curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $options['proxy_tunnel']);
		}

		if (empty($options['interface']) === false) {
			curl_setopt($ch, CURLOPT_INTERFACE, $options['interface']);
		}

		if (empty($options['max_connects']) === false) {
			curl_setopt($ch, CURLOPT_MAXCONNECTS, $options['max_connects']);
		}

		if (empty($options['tcp_nodelay']) === false) {
			curl_setopt($ch, CURLOPT_TCP_NODELAY, $options['tcp_nodelay']);
		}

		if (empty($options['buffer_size']) === false) {
			curl_setopt($ch, CURLOPT_BUFFERSIZE, $options['buffer_size']);
		}

		if (empty($options['webdav']) === false && is_array($options['webdav'])) {
			$user = (string) ($options['webdav']['user'] ?? '');
			$pass = (string) ($options['webdav']['password'] ?? '');
			if ($user !== '' || $pass !== '') {
				curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
			}
		}

		self::applySslOptions($ch, $options);
	}

	/**
	 * 设置连接/传输超时
	 *
	 * @param \CurlHandle|resource $ch curl 句柄
	 * @param array<string,mixed> $options 请求选项
	 * @return void
	 */
	private static function applyTimeouts($ch, array $options): void
	{
		[$connect, $timeout] = self::resolveTimeouts($options);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	}

	/**
	 * 应用 SSL/TLS 选项
	 *
	 * 强制开启服务端证书校验；旧版 ssl_verify/ssl=false 选项不再允许关闭校验
	 *
	 * @param \CurlHandle|resource $ch curl 句柄
	 * @param array<string,mixed> $options 请求选项
	 * @return void
	 */
	private static function applySslOptions($ch, array $options): void
	{
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		if (is_array($options['ssl'] ?? null)) {
			$ssl = $options['ssl'];
			if (!empty($ssl['key'])) {
				curl_setopt($ch, CURLOPT_SSLKEY, $ssl['key']);
			}
			if (!empty($ssl['cert'])) {
				curl_setopt($ch, CURLOPT_SSLCERT, $ssl['cert']);
			}
			if (!empty($ssl['key']) || !empty($ssl['cert'])) {
				curl_setopt($ch, CURLOPT_VERBOSE, true);
			}
		}
	}
}
