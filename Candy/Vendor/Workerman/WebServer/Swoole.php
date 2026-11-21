<?php
/***
 * Candy Swoole HTTP 适配（实验性）
 *
 * 业务说明：对齐 WebServer\Server 的虚拟主机 / PATHINFO / 静态与 PHP；请求数据完全使用协程上下文
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-09-18 04:43:03 $
 *
 * @package WebServer
 */

namespace WebServer;

use Candy\Core\Container;
use Candy\Core\Trait\View\CompileCache;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleHttpServer;
use Throwable;

/**
 * Swoole 版内置 Web 入口（实验）
 */
class Swoole
{
	/** @var array<string, array{root:string,custom404?:string,additionHeader?:array}> */
	protected array $serverRoot = [];

	/** 本地 IP/localhost 访问时使用的默认站点，行为与 FPM 默认站点一致。 */
	protected ?array $defaultServerRoot = null;

	/** @var list<string> 需要强制跳 https 的域名（实例级；static 仅保留向后兼容） */
	protected array $compelList = [];

	/** @var list<string> */
	public static array $urlCompelList = [];

	/** @var array<string,mixed> 构造期设过的端口选项（证书等），后续 set() 必须带着一起下发 */
	protected array $baseOptions = [];

	/** @var array<int,true> 本实例上属于 SSL 的监听端口 */
	protected array $sslPorts = [];

	protected SwooleHttpServer $swooleServer;

	/** 当前监听器按 SNI 域名映射的证书配置。 */
	protected array $sslSniCerts = [];

	private const STATIC_LOG_SKIP = [
		'.css', '.jpg', '.jpeg', '.gif', '.png', '.ico', '.mp4', '.txt', '.js',
		'.eot', '.svg', '.ttf', '.woff', '.woff2', '.webp', '.map',
	];

	private const VHOST_MAX_DEPTH = 8;

	/**
	 * @param string $address 如 0.0.0.0:8080
	 * @param array $contextOption 可含 ssl.local_cert / ssl.local_pk
	 */
	public function __construct(string $address, array $contextOption = [])
	{
		if (!class_exists(SwooleHttpServer::class)) {
			throw new \RuntimeException('未安装 Swoole 扩展，无法使用 WebServer\\Swoole');
		}

		$host = '0.0.0.0';
		$port = 8080;
		if (str_contains($address, ':')) {
			[$host, $portPart] = explode(':', $address, 2);
			$port = (int) $portPart;
		}

		$isSsl = !empty($contextOption['ssl']);
		$mode = defined('SWOOLE_PROCESS') ? SWOOLE_PROCESS : 2;
		$sockType = defined('SWOOLE_SOCK_TCP') ? SWOOLE_SOCK_TCP : 1;
		if ($isSsl && defined('SWOOLE_SSL')) {
			$sockType |= SWOOLE_SSL;
		}

		$this->swooleServer = new SwooleHttpServer($host, $port, $mode, $sockType);
		// ⚠️ 证书必须与 enable_coroutine 在同一次 set() 里给全：
		// Swoole 的 Port 在第一次 set() 时就会初始化 SSL 上下文，
		// 先 set(enable_coroutine) 再 set(证书) 会报 ssl_context_create() require ssl_cert_file。
		$swooleOptions = ['enable_coroutine' => true];
		if ($isSsl) {
			$swooleOptions['ssl_cert_file'] = (string) ($contextOption['ssl']['local_cert'] ?? '');
			$swooleOptions['ssl_key_file'] = (string) ($contextOption['ssl']['local_pk'] ?? '');
		}
		if ($isSsl) {
			$this->sslPorts[$port] = true;
		}
		$this->baseOptions = $swooleOptions;
		$this->swooleServer->set($swooleOptions);

		$this->swooleServer->on('Request', [$this, 'onRequest']);
	}

	/**
	 * @param string $domain 域名
	 * @param string|array $config root 或配置数组
	 */
	public function addRoot(string $domain, string|array $config): void
	{
		if (is_string($config)) {
			$config = ['root' => $config];
		}
		if (empty($config['root']) || !is_string($config['root'])) {
			throw new \InvalidArgumentException('WebServer\\Swoole addRoot 需要 root');
		}
		$config['root'] = rtrim($config['root'], "/\\");
		$this->serverRoot[$domain] = $config;
		$this->defaultServerRoot ??= $config;
	}

	/** 为当前 TLS 监听器追加一个域名证书（Swoole 4.6+ SNI）。 */
	public function addSslCertificate(string $domain, array $ssl): void
	{
		if (empty($ssl['local_cert']) || empty($ssl['local_pk'])) {
			throw new \InvalidArgumentException('SNI 证书必须同时配置 local_cert 和 local_pk');
		}
		$this->sslSniCerts[$domain] = [
			'ssl_cert_file' => (string)$ssl['local_cert'],
			'ssl_key_file' => (string)$ssl['local_pk'],
		];
		$this->swooleServer->set(['ssl_sni_certs' => $this->sslSniCerts]);
	}

	/**
	 * 追加一个监听地址（同一 Swoole Server 的 addListener 端口）
	 *
	 * 一个进程只能 start() 一次，所以 80 + 443 这种组合必须走这里；
	 * 站点映射（addRoot）是全实例共享的，按 Host 分发，与端口无关。
	 *
	 * @param string                            $address       如 0.0.0.0:443
	 * @param array<string,mixed>               $contextOption 可含 ssl.local_cert / ssl.local_pk
	 * @param array<string,array<string,string>> $sniCerts     域名 => ['local_cert'=>..,'local_pk'=>..]
	 */
	public function addPort(string $address, array $contextOption = [], array $sniCerts = []): void
	{
		$host = '0.0.0.0';
		$port = 80;
		if (str_contains($address, ':')) {
			[$host, $portPart] = explode(':', $address, 2);
			$port = (int) $portPart;
		}

		$isSsl = !empty($contextOption['ssl']);
		$sockType = defined('SWOOLE_SOCK_TCP') ? SWOOLE_SOCK_TCP : 1;
		if ($isSsl && defined('SWOOLE_SSL')) {
			$sockType |= SWOOLE_SSL;
		}

		if ($isSsl) {
			$this->sslPorts[$port] = true;
		}
		$listener = $this->swooleServer->addListener($host, $port, $sockType);
		if ($listener === false) {
			throw new \RuntimeException('Swoole addListener 失败：' . $address);
		}

		$options = ['open_http_protocol' => true];
		if ($isSsl) {
			$options['ssl_cert_file'] = (string) ($contextOption['ssl']['local_cert'] ?? '');
			$options['ssl_key_file'] = (string) ($contextOption['ssl']['local_pk'] ?? '');
			$sni = [];
			foreach ($sniCerts as $domain => $ssl) {
				if (empty($ssl['local_cert']) || empty($ssl['local_pk'])) {
					continue;
				}
				$sni[(string) $domain] = [
					'ssl_cert_file' => (string) $ssl['local_cert'],
					'ssl_key_file'  => (string) $ssl['local_pk'],
				];
			}
			if ($sni !== []) {
				$options['ssl_sni_certs'] = $sni;
			}
		}
		$listener->set($options);
		$listener->on('Request', [$this, 'onRequest']);
	}

	/**
	 * 设置「强制跳转 https」的域名列表
	 *
	 * @param list<string> $domains
	 */
	public function setCompelList(array $domains): void
	{
		$this->compelList = array_values(array_unique(array_map('strval', $domains)));
	}

	/**
	 * Swoole Request 回调（同步处理，避免与超全局并发）
	 */
	public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
	{
		// 标记为 WebServer 模式，使 stop() 抛出 jump_exit 而非调用 exit()
		defined('CLIWORKING') OR define('CLIWORKING', true);

		$this->resetRequestState();
		$reqAdapter = null;
		try {
			// 协程下同时写入 Coroutine::getContext，避免多协程覆盖 Container 绑定
			$reqAdapter = new SwooleRequestAdapter($swooleRequest);
			$resAdapter = new SwooleResponseAdapter($swooleResponse);
			$requestUuid = \createUuidV4();
			$requestServer = $this->requestServerData($swooleRequest);
			$requestServer['HTTP_HOST'] = (string)($swooleRequest->header['host'] ?? '');
			$requestServer['REDIRECT_REQUEST_UUID'] = $requestUuid;
			\Candy\Core\Request::setInstance(new \Candy\Core\Request([
				'get' => is_array($swooleRequest->get ?? null) ? $swooleRequest->get : [],
				'post' => is_array($swooleRequest->post ?? null) ? $swooleRequest->post : [],
				'cookie' => is_array($swooleRequest->cookie ?? null) ? $swooleRequest->cookie : [],
				'files' => is_array($swooleRequest->files ?? null) ? $swooleRequest->files : [],
				'server' => $requestServer,
				'headers' => is_array($swooleRequest->header ?? null) ? $swooleRequest->header : [],
				'method' => (string)($swooleRequest->server['request_method'] ?? 'GET'),
				'uri' => (string)($swooleRequest->server['request_uri'] ?? '/'),
				'ip' => (string)($swooleRequest->server['remote_addr'] ?? ''),
			]));
			if (\Candy\Core\Request::inCoroutine()) {
				$ctx = \Swoole\Coroutine::getContext();
				$ctx['_container_Request'] = $reqAdapter;
				$ctx['_container_Response'] = $resAdapter;
			} else {
				Container::getInstance()->bind('Request', $reqAdapter);
				Container::getInstance()->bind('Response', $resAdapter);
			}

			$hostHeader = (string) ($swooleRequest->header['host'] ?? '');
			$host = parse_url('http://' . $hostHeader, PHP_URL_HOST) ?: $hostHeader;

			$compel = $this->compelList !== [] ? $this->compelList : self::$urlCompelList;
			// Swoole 不保证下发 server['https']（实测 SSL 端口上该键缺失，会导致 https 请求被无限跳转），
			// 改为按「本次请求落在的本地端口是否属于 SSL 监听」判断。
			$localPort = (int) ($swooleRequest->server['server_port'] ?? 0);
			$isHttps = (string) ($swooleRequest->server['https'] ?? '') !== ''
				|| ($localPort > 0 && isset($this->sslPorts[$localPort]));
			if (in_array($host, $compel, true) && !$isHttps) {
				$swooleResponse->status(302);
				// 跳转目标用不带端口的 host：80 -> 443 时 $hostHeader 就是域名，
				// 但从 8080 之类的端口访问时 $hostHeader 会带端口，跳过去仍然是错的。
				$swooleResponse->header(
					'Location',
					'https://' . $host . ($swooleRequest->server['request_uri'] ?? '/')
				);
				$swooleResponse->end();
				$this->writeAccessLog(302, 0);
				return;
			}

			$siteConfig = $this->matchVirtualHost($host);
			if ($siteConfig === null) {
				$this->sendError($swooleResponse, 502, 'Bad Gateway');
				$this->writeAccessLog(502, 24);
				return;
			}

			$this->applyAdditionHeaders($swooleResponse, $siteConfig);
			$this->handleRequestUri($swooleRequest, $swooleResponse, $siteConfig);
		} catch (Throwable $e) {
			$swooleResponse->status(500);
			$swooleResponse->header('Content-Type', 'text/html; charset=utf-8');
			$body = 'Server Error: ' . $e->getMessage();
			$swooleResponse->end($body);
			$this->writeAccessLog(500, strlen($body));
		} finally {
			$reqAdapter?->saveSession();
			$this->clearRequestScope();
		}
	}

	/** 将 Swoole 请求数据标准化为 Candy Request 所需的 SERVER 快照。 */
	protected function requestServerData(SwooleRequest $req): array
	{
		$server = [];
		foreach (($req->server ?? []) as $k => $v) {
			$server[strtoupper((string) $k)] = $v;
		}
		foreach (($req->header ?? []) as $k => $v) {
			$server['HTTP_' . strtoupper(str_replace('-', '_', (string) $k))] = $v;
		}
		$server['REQUEST_URI'] = (string)($req->server['request_uri'] ?? '/');
		$server['REQUEST_METHOD'] = (string)($req->server['request_method'] ?? 'GET');
		$server['REMOTE_ADDR'] = (string)($req->server['remote_addr'] ?? '');
		$server['PATH_INFO'] = (string)(parse_url($server['REQUEST_URI'], PHP_URL_PATH) ?: '/');
		return $server;
	}

	/**
	 * 清除当前 Swoole 请求的 T() 数据桶与 UUID
	 *
	 * @return void
	 */
	protected function clearRequestScope(): void
	{
		if (function_exists('T')) {
			$uuid = \Candy\Core\Request::inCoroutine()
				? (string)R()->server('REDIRECT_REQUEST_UUID', '')
				: (string)($_SERVER['REDIRECT_REQUEST_UUID'] ?? '');
			if ($uuid !== '') {
				\T(null, null, null, $uuid);
			}
		}
		\Candy\Core\Request::clearInstance();
	}

	/**
	 * 清除上一请求留下的路由、编译文件和控制器实例。
	 */
	protected function resetRequestState(): void
	{
		$this->clearRequestScope();
		foreach (['APP', 'CONTROL', 'ACTION', 'S404', 'TEMPLATE_DIR', 'API', 'ISADDON', 'APP_PATH_LOCKED', 'APP_FS_REL', 'APP_FS_ROOT', 'APP_CONTROL_FILE', 'APP_CONTROL_VIA'] as $key) {
			\C($key, null);
		}
		CompileCache::clearRequestFileCache();
		Container::getInstance()->flushActionInstances();
		if (!\Candy\Core\Request::inCoroutine()) {
			\Candy\Core\Respond::$buff = '';
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	protected function matchVirtualHost(string $host): ?array
	{
		$name = $host;
		for ($depth = 0; $depth < self::VHOST_MAX_DEPTH; $depth++) {
			if (isset($this->serverRoot[$name])) {
				return $this->serverRoot[$name];
			}
			$parts = explode('.', $name);
			if (count($parts) <= 2) {
				break;
			}
			array_shift($parts);
			$name = '*.' . implode('.', $parts);
			if (isset($this->serverRoot[$name])) {
				return $this->serverRoot[$name];
			}
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false || in_array(strtolower($host), ['localhost', '::1'], true)) {
			return $this->defaultServerRoot;
		}
		// 与 Workerman Server::resolveVirtualHost 对齐：'*' 作为兜底站点
		if (isset($this->serverRoot['*'])) {
			return $this->serverRoot['*'];
		}
		return $this->serverRoot[$host] ?? null;
	}

	/**
	 * @param array<string,mixed> $siteConfig
	 */
	protected function handleRequestUri(
		SwooleRequest $req,
		SwooleResponse $res,
		array $siteConfig
	): void {
		$uri = (string) ($req->server['request_uri'] ?? '/');
		$path = parse_url($uri, PHP_URL_PATH) ?: '/';
		$rootDir = rtrim((string) $siteConfig['root'], '/');
		$filePath = $rootDir . $path;
		$extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

		if ($extension === '') {
			$filePath = str_ends_with($filePath, '/') ? $filePath . 'index.php' : $filePath . '/index.php';
			$extension = 'php';
		}

		$this->setRequestServer('DOCUMENT_ROOT', $rootDir);

		if ($extension === 'php' && !is_file($filePath)) {
			$workingDir = dirname($filePath);
			if (is_dir($workingDir) && is_file($workingDir . '/index.html')) {
				$filePath = $workingDir . '/index.html';
				$extension = 'html';
			} elseif (is_file($rootDir . '/index.php')) {
				$filePath = $rootDir . '/index.php';
				$extension = 'php';
			}
		} elseif ($extension !== 'php' && !is_file($filePath)) {
			// 非 PHP 文件不存在时 fallback 到 index.php，支持框架 PATHINFO 路由
			$filePath = $rootDir . '/index.php';
			$extension = 'php';
		}
		$this->setRequestServer('SCRIPT_FILENAME', $extension === 'php' ? $filePath : $rootDir . '/index.php');
		$this->setRequestServer('SCRIPT_NAME', $extension === 'php' ? '/index.php' : $path);

		$realFile = is_file($filePath) ? realpath($filePath) : false;
		$realRoot = realpath($rootDir);
		if ($realFile === false || $realRoot === false || !str_starts_with($realFile, $realRoot)) {
			$this->sendError($res, 404, 'Not Found', $siteConfig['custom404'] ?? null);
			$this->writeAccessLog(404, 0);
			return;
		}

		if ($extension === 'php') {
			$this->executePhpFile($realFile, $req, $res, $rootDir);
			return;
		}

		$this->sendStaticFile($res, $req, $realFile);
	}

	/** 写入当前运行模式的请求服务器快照。 */
	protected function setRequestServer(string $key, mixed $value): void
	{
		if (\Candy\Core\Request::inCoroutine()) {
			R()->setServer($key, $value);
			return;
		}
		$_SERVER[$key] = $value;
	}


	protected function executePhpFile(
		string $filePath,
		SwooleRequest $req,
		SwooleResponse $res,
		string $rootDir
	): void {
		$accept = strtolower((string) ($req->header['accept'] ?? ''));
		if ($accept === 'text/event-stream') {
			$adapter = Container::getInstance()->get('Response');
			if ($adapter instanceof SwooleResponseAdapter) {
				$adapter->header('Content-Type', 'text/event-stream');
				$adapter->header('Cache-Control', 'no-cache');
				$adapter->header('Connection', 'keep-alive');
				$adapter->header('X-Accel-Buffering', 'no');
				$adapter->status(200);
			} else {
				$res->header('Content-Type', 'text/event-stream');
				$res->header('Cache-Control', 'no-cache');
				$res->header('Connection', 'keep-alive');
				$res->header('X-Accel-Buffering', 'no');
				$res->status(200);
			}
			$bufferLevel = ob_get_level();
			ob_start();
			try {
				include $filePath;
			} catch (Throwable $e) {
				if ($e->getMessage() !== 'jump_exit') {
					error_log($e->getMessage());
				}
			} finally {
				while (ob_get_level() > $bufferLevel) {
					ob_end_clean();
				}
				if ($adapter instanceof SwooleResponseAdapter) {
					$adapter->endStream();
				} else {
					$res->end();
				}
				$this->writeAccessLog(200, 0);
			}
			return;
		}

		ob_start();
		try {
			include $filePath;
		} catch (Throwable $e) {
			ob_end_clean();
			// Swoole 协程内 exit() 抛出 ExitException；检查是否为框架重定向
			if ($e instanceof \Swoole\ExitException) {
				$adapter = Container::getInstance()->get('Response');
				if ($adapter instanceof SwooleResponseAdapter && $adapter->statusCode() > 0 && $adapter->statusCode() !== 200) {
					$status = $adapter->statusCode();
					$body = $status === 304 ? '' : (string)($adapter->body() ?? '');
					$adapter->commit($body);
					$this->writeAccessLog($status, strlen($body));
					return;
				}
				if ($adapter instanceof SwooleResponseAdapter) {
					$adapter->commit();
				} else {
					$res->end();
				}
				$this->writeAccessLog(200, 0);
				return;
			}
			// stop() 以 jump_exit 中断控制器；响应体已由 Candy Respond 写入适配器。
			if ($e->getMessage() === 'jump_exit') {
				$adapter = Container::getInstance()->get('Response');
				if ($adapter instanceof SwooleResponseAdapter) {
					$status = $adapter->statusCode();
					$body = $status === 304 ? '' : (string)($adapter->body() ?? '');
					$adapter->commit($body);
					$this->writeAccessLog($status, strlen($body));
					return;
				}
			}
			$res->status(500);
			$body = 'Server Error: ' . $e->getMessage();
			$res->end($body);
			$this->writeAccessLog(500, strlen($body));
			return;
		}
		$content = (string) ob_get_clean();

		$adapter = Container::getInstance()->get('Response');
		if ($adapter instanceof SwooleResponseAdapter && $adapter->body() !== null) {
			$body = $adapter->statusCode() === 304 ? '' : (string)$adapter->body();
			$adapter->commit($body);
			$this->writeAccessLog($adapter->statusCode(), strlen($body));
			return;
		}

		// PHP 控制器响应（尤其认证/重定向）不能走 ETag 协商；否则浏览器会把
		// 旧的 200/404 缓存为 304，绕过本次请求的登录状态判断。ETag 仅用于静态文件。
		if ($adapter instanceof SwooleResponseAdapter) {
			$adapter->header('Cache-Control', 'no-store, no-cache, must-revalidate');
			$adapter->header('Pragma', 'no-cache');
			$adapter->header('Content-Type', 'text/html; charset=utf-8');
			$adapter->commit($content);
		} else {
			$res->header('Cache-Control', 'no-store, no-cache, must-revalidate');
			$res->header('Pragma', 'no-cache');
			$res->header('Content-Type', 'text/html; charset=utf-8');
			$res->end($content);
		}
		$this->writeAccessLog(200, strlen($content));
	}

	/** @var array<string,string> 扩展名→MIME 映射（mime_content_type 不可靠时回退） */
	private const MIME_MAP = [
		'css' => 'text/css',
		'js' => 'application/javascript',
		'json' => 'application/json',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'ico' => 'image/x-icon',
		'svg' => 'image/svg+xml',
		'woff' => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf' => 'font/ttf',
		'eot' => 'application/vnd.ms-fontobject',
		'pdf' => 'application/pdf',
		'zip' => 'application/zip',
		'mp4' => 'video/mp4',
		'xml' => 'application/xml',
		'txt' => 'text/plain',
	];

	protected function sendStaticFile(
		SwooleResponse $res,
		SwooleRequest $req,
		string $filePath
	): void {
		$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
		$mime = self::MIME_MAP[$ext] ?? '';
		if ($mime === '') {
			$mime = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : 'application/octet-stream';
		}
		$res->header('Content-Type', $mime);

		$lastModified = filemtime($filePath) ?: 0;
		$res->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');

		$ims = (string) ($req->header['if-modified-since'] ?? '');
		if ($ims !== '' && $lastModified <= (strtotime($ims) ?: 0)) {
			$res->status(304);
			$res->end();
			$this->writeAccessLog(304, 0);
			return;
		}

		$size = (int) filesize($filePath);
		$res->sendfile($filePath);
		$this->writeAccessLog(200, $size);
	}

	/**
	 * @param array<string,mixed> $siteConfig
	 */
	protected function applyAdditionHeaders(SwooleResponse $res, array $siteConfig): void
	{
		if (empty($siteConfig['additionHeader'])) {
			return;
		}
		$headers = $siteConfig['additionHeader'];
		if (is_string($headers)) {
			$headers = [$headers];
		}
		if (!is_array($headers)) {
			return;
		}
		foreach ($headers as $item) {
			if (is_string($item) && str_contains($item, ':')) {
				[$k, $v] = array_map('trim', explode(':', $item, 2));
				$res->header($k, $v);
				continue;
			}
			if (is_array($item) && isset($item[0], $item[1])) {
				$res->header((string) $item[0], (string) $item[1]);
			}
		}
	}

	protected function sendError(
		SwooleResponse $res,
		int $statusCode,
		string $message,
		?string $custom404 = null
	): void {
		$res->status($statusCode);
		if ($statusCode === 404 && $custom404 && is_file($custom404)) {
			$res->sendfile($custom404);
			return;
		}
		$res->header('Content-Type', 'text/html; charset=utf-8');
		$res->end(sprintf('<h1>%d %s</h1>', $statusCode, $message));
	}

	protected function writeAccessLog(int $status, int $size): void
	{
		$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
		foreach (self::STATIC_LOG_SKIP as $ext) {
			if ($uri !== '' && str_contains(strtolower($uri), $ext)) {
				return;
			}
		}
		$logDir = function_exists('C') ? (string) C('LOGPATH') : '';
		if ($logDir === '') {
			return;
		}
		if (!is_dir($logDir)) {
			@mkdir($logDir, 0755, true);
		}
		$line = sprintf(
			'%s - - [%s] "%s %s %s" %d %d "-" "%s"' . "\n",
			(string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
			date('d/M/Y:H:i:s O'),
			(string) ($_SERVER['REQUEST_METHOD'] ?? '-'),
			$uri !== '' ? $uri : '/',
			(string) ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1'),
			$status,
			max(0, $size),
			(string) ($_SERVER['HTTP_USER_AGENT'] ?? '-')
		);
		$path = rtrim($logDir, '/\\') . '/access.log';
		$fp = @fopen($path, 'ab');
		if ($fp === false) {
			return;
		}
		if (flock($fp, LOCK_EX)) {
			fwrite($fp, $line);
			flock($fp, LOCK_UN);
		}
		fclose($fp);
	}

	public function start(): void
	{
		$this->swooleServer->start();
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public function setConfig(array $config): void
	{
		// 证书类选项必须跟着一起下发：Swoole 的 Port 每次 set() 都可能重建 SSL 上下文，
		// 只下发 worker_num 之类的运行参数会把证书丢掉。
		$this->swooleServer->set(array_merge($this->baseOptions, $config));
	}
}
