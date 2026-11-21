<?php
/**
 * 镜像代理客户端
 *
 * 提供本地 IP 探测、远程内容拉取与缓存、代理写入等功能。
 *
 * @package Candy\Core\Trait\Func
 * $Date: 2026-08-18 19:19:11 $
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Func;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class MirrorClient
{
	/** @var string|null 当前进程本机地址缓存 */
	private static ?string $localIp = null;

	/**
	 * 获取本地服务器 IP
	 *
	 * 首次调用时从 gethostbyname 获取结果并缓存到类静态变量；本机地址与请求无关，
	 * 不应写入 Swoole 协程间共享的 $_SERVER。
	 *
	 * @param string $host 主机名，留空自动检测
	 * @return string IP 地址
	 */
	public function getLocalIp(string $host = ''): string
	{
		return self::$localIp ??= gethostbyname(gethostname());
	}

	/**
	 * 拉取远程的镜像内容（带本地缓存）
	 *
	 * 优先读取本地缓存文件，过期或不存在时从远程 URL 获取。
	 *
	 * @param string $url 远程 URL
	 * @param array $config 配置项：dir（缓存目录）、skipCache、dataOnly、noproxy 等
	 * @return array{status:string,content:mixed,type?:string,headers?:array}
	 */
	public function getContents(string $url, array $config): array
	{
		$data = ['status' => 'expired', 'content' => ''];
		if (!$this->isPublicUrl($url)) {
			return $data;
		}

		$parts = parse_url($url);
		$host = (string)($parts['host'] ?? '');

		$mirbasepath = G('MirrPath') ?? C('RUNTIME') . 'MirrPath';
		$dir = $config['dir'] ??= $host;
		$savebase = rtrim($mirbasepath, '/') . '/' . $dir . '/';
		if (file_exists($savebase . 'hash/' . md5($url))) {
			$data['type'] = 'entity';
		}
		$cachedir = $savebase . 'data/';
		$cachefile = $cachedir . md5($url) . '._';
		$maxage = empty($config['maxage']) ? 7200 : $config['maxage'];

		if (is_file($cachefile) && filemtime($cachefile) > (time() - $maxage) && empty($config['skipCache'])) {
			return json_decode(file_get_contents($cachefile), true) ?: $data;
		}

		$opts = [
			'http' => [
				'method' => 'GET',
				'timeout' => 10,
				'follow_location' => 0,
				'max_redirects' => 0,
				'header' => "Accept-Encoding: gzip\r\n",
			],
			'https' => [
				'method' => 'GET',
				'timeout' => 10,
				'follow_location' => 0,
				'max_redirects' => 0,
				'header' => "Accept-Encoding: gzip\r\n",
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		];

		$context = stream_context_create($opts);
		$result = @file_get_contents($url, false, $context, 0, 33554432);

		if ($result === false) {
			if (is_file($cachefile)) {
				$data = json_decode(file_get_contents($cachefile), true) ?: $data;
				$data['status'] = 'cached';
				return $data;
			}
			return $data;
		}

		$data['status'] = 'live';
		if (isset($http_response_header)) {
			$data['headers'] = implode("\n", $http_response_header);
		}

		$decoded = @gzdecode($result);
		$data['content'] = $decoded !== false ? $decoded : $result;

		if (empty($config['dataOnly'])) {
			\Candy\Extend\Dir::create($cachedir);
			file_put_contents($cachefile, json_encode($data, JSON_UNESCAPED_UNICODE));
		}

		if (!empty($config['entity']) && $config['dir']) {
			$entityDir = $savebase . 'entity/';
			\Candy\Extend\Dir::create($entityDir);
			file_put_contents($entityDir . '/' . $config['entity'], $data['content']);
		}

		return $data;
	}

	/**
	 * 判断镜像地址是否为公网 HTTP(S) 地址。
	 *
	 * @param string $url 待请求地址
	 * @return bool 是否允许请求
	 */
	private function isPublicUrl(string $url): bool
	{
		$parts = parse_url(trim($url));
		if (!is_array($parts)) {
			return false;
		}
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
		if (!in_array($scheme, ['http', 'https'], true)
			|| $host === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
		) {
			return false;
		}

		$ips = [];
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			$ips[] = $host;
		} elseif (function_exists('dns_get_record')) {
			$records = @dns_get_record($host, DNS_A | DNS_AAAA);
			foreach (is_array($records) ? $records : [] as $record) {
				$ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
				if ($ip !== '') {
					$ips[] = $ip;
				}
			}
		}
		if ($ips === []) {
			$resolved = @gethostbynamel($host);
			$ips = is_array($resolved) ? $resolved : [];
		}
		if ($ips === []) {
			return false;
		}

		foreach (array_unique($ips) as $ip) {
			if (filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) === false) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 写入镜像缓存内容
	 *
	 * @param string $url 缓存键（URL）
	 * @param array $content 要缓存的数据
	 * @param array{dir?:string} $config 配置，dir 指定缓存子目录
	 * @return void
	 */
	public function setContents(string $url, array $content, $config): void
	{
		$mirbasepath = G('MirrPath') ?? C('RUNTIME') . 'MirrPath';
		$dir = $config['dir'] ??= '';
		$cachedir = rtrim($mirbasepath, '/') . '/' . $dir . '/data/';
		\Candy\Extend\Dir::create($cachedir);
		$file = $cachedir . md5($url) . '._';
		file_put_contents($file, json_encode($content, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * 请求远程 URL 并保存到本地缓存
	 *
	 * 通过 Snoopy 类发起请求，结果缓存到镜像目录。
	 *
	 * @param string $url 远程 URL
	 * @param array $config 配置项
	 * @return void
	 */
	public function makeForSave($url, $config = []): void
	{
		if (class_exists('\Extend\Snoopy') === false) {
			return;
		}

		$url = str_replace('&#47;', '/', $url);
		$config['noproxy'] = true;
		$content = $this->getContents($url, $config);
	}
}
