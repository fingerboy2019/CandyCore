<?php
/***
 * Candy 内置 Workerman WebServer
 *
 * 业务说明：虚拟主机 + 文档根 + PATHINFO 回落 index.php；对接 Container / 超全局 / WorkerRuntime
 * 注意：不使用 Coroutine::create 并发改超全局，避免 Fiber 下串请求（并发靠多 worker）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-09-18 04:41:03 $
 *
 * @package WebServer
 */

namespace WebServer;

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Connection\TcpConnection;
use Candy\Core\Container;
use Candy\Core\Trait\View\CompileCache;
use Throwable;

/**
 * Candy 定制 HTTP Worker
 */
class Server extends Worker
{
	/** @var array<string,string> Workerman SNI 域名到合并 PEM 文件的映射 */
	protected array $sslSniCertificates = [];
	/** @var array<string, array{root:string,custom404?:string,additionHeader?:array}> 虚拟主机 */
	protected array $serverRoot = [];

	/** @var list<string> HTTP→HTTPS 强制跳转域名 */
	public static array $urlCompelList = [];

	/** @var callable|null 用户 onWorkerStart */
	protected $_onWorkerStart = null;

	/** @var callable|null 用户 onConnect */
	protected $_onConnect = null;

	/** @var callable|null 用户 onClose */
	protected $_onClose = null;

	/** 是否使用 Workerman 协程调度 HTTP 请求。 */
	protected bool $coroutine = false;

	/**
	 * 单个 worker 累计处理请求数上限（__MAX_REQUEST__ 2026-11-13）
	 *
	 * 0 表示不限制，保持原有行为（默认值，零侵入）。
	 * 大于 0 时：当前 worker 处理满该数量的请求后正常退出，
	 * 由 master 的 monitorWorkersForLinux() 自动 fork 新进程补位，
	 * 用于规避常驻模式下内存缓慢增长（对标 webman 的 max_request）。
	 *
	 * 配置方式：Source/Inc/Server.inc.php 的站点项加 'max_request' => 1000
	 *
	 * @var int
	 */
	public int $maxRequest = 0;

	/**
	 * 当前 worker 进程已处理的请求数（reset 仅在进程重建时自然归零）
	 *
	 * @var int
	 */
	protected int $workerRequestCount = 0;

	/** 静态资源扩展名（不写 access.log） */
	private const STATIC_LOG_SKIP = [
		'.css', '.jpg', '.jpeg', '.gif', '.png', '.ico', '.mp4', '.txt', '.js',
		'.eot', '.svg', '.ttf', '.woff', '.woff2', '.webp', '.map',
	];

	/** 泛域名匹配最大回退次数 */
	private const VHOST_MAX_DEPTH = 8;

	/**
	 * 添加虚拟主机
	 *
	 * @param string $domain 域名（支持 *.example.com）
	 * @param string|array $config 文档根路径或配置数组（须含 root）
	 */
	public function addRoot(string $domain, string|array $config): void
	{
		if (is_string($config)) {
			$config = ['root' => $config];
		}
		if (empty($config['root']) || !is_string($config['root'])) {
			throw new \InvalidArgumentException('WebServer addRoot 需要 root 路径');
		}
		$config['root'] = rtrim($config['root'], "/\\");
		$this->serverRoot[$domain] = $config;
	}

	/** 为同一 SSL 监听器追加已合并的域名证书。 */
	public function addSslCertificate(string $domain, array $ssl): void
	{
		$cert = (string)($ssl['sni_cert'] ?? $ssl['merged_cert'] ?? '');
		if ($cert === '') {
			// 单证书场景只使用 local_cert/local_pk 作为默认证书，不需要 SNI 映射。
			return;
		}
		if (!is_file($cert) || !is_readable($cert)) {
			throw new \InvalidArgumentException('SNI 合并证书不可读：' . $domain);
		}
		$this->sslSniCertificates[$domain] = $cert;
		stream_context_set_option($this->socketContext, 'ssl', 'SNI_enabled', true);
		stream_context_set_option($this->socketContext, 'ssl', 'SNI_server_certs', $this->sslSniCertificates);
	}

	/**
	 * @param string $socket_name 如 http://0.0.0.0:8008
	 * @param array $context_option SSL 等上下文
	 */
	public function __construct(string $socket_name, array $context_option = [])
	{
		[, $address] = explode(':', $socket_name, 2);
		$this->coroutine = (bool)($context_option['coroutine'] ?? false);
		// coroutine 是 Candy 的 Worker 选项，不属于 PHP stream context，不能传给父类。
		unset($context_option['coroutine']);
		parent::__construct('http:' . $address, $context_option);
		$this->name = 'WebServer';
		if ($this->coroutine) {
			if (PHP_VERSION_ID < 80100 || !class_exists(\Fiber::class)) {
				throw new \RuntimeException('Workerman WebServer Fiber 模式需要 PHP 8.1+');
			}
			$this->eventLoop = \Workerman\Events\Fiber::class;
		}
	}

	/**
	 * 启动 Worker（包装回调并绑定 Candy reload/start 连接治理）
	 */
	public function run(): void
	{
		$this->_onConnect = $this->onConnect;
		$this->onConnect = [$this, 'onConnect'];
		$this->_onWorkerStart = $this->onWorkerStart;
		$this->onWorkerStart = [$this, 'onWorkerStart'];
		$this->_onClose = $this->onClose;
		$this->onClose = [$this, 'onClose'];
		$this->onMessage = [$this, 'onMessage'];
		\Candy\Core\Route::bindWorkerReload($this);
		parent::run();
	}

	/**
	 * 连接建立：转发用户 onConnect
	 */
	public function onConnect(TcpConnection $connection): void
	{
		if ($this->_onConnect) {
			try {
				($this->_onConnect)($connection);
			} catch (Throwable $e) {
				self::log($e);
				exit(250);
			}
		}
	}

	/**
	 * Worker 启动：转发用户 onWorkerStart（WorkerRuntime 已由 bindWorkerReload 前置）
	 */
	public function onWorkerStart(): void
	{
		if ($this->coroutine) {
			// 提前加载 Fiber 上下文实现，避免首个请求中自动加载器递归进入配置读取。
			$fiberContext = defined('CANDYPATH')
				? CANDYPATH . 'Candy/Vendor/Workerman/Coroutine/Context/Fiber.php'
				: '';
			if ($fiberContext !== '' && is_file($fiberContext)) {
				require_once $fiberContext;
			}
			\Workerman\Coroutine::init();
		}
		if ($this->_onWorkerStart) {
			try {
				($this->_onWorkerStart)($this);
			} catch (Throwable $e) {
				self::log($e);
				exit(250);
			}
		}
	}

	/**
	 * 连接关闭：转发用户 onClose
	 */
	public function onClose(TcpConnection $connection): void
	{
		if ($this->_onClose) {
			try {
				($this->_onClose)($connection);
			} catch (Throwable $e) {
				self::log($e);
				exit(250);
			}
		}
	}

	/**
	 * HTTP 消息入口（同步处理，避免 Fiber 协程并发污染超全局）
	 */
	public function onMessage(TcpConnection $connection, Request $request): void
	{
		if ($this->coroutine) {
			\Workerman\Coroutine::create(function () use ($connection, $request): void {
				$this->handleMessage($connection, $request);
			});
			return;
		}
		$this->handleMessage($connection, $request);
	}

	/** 执行单个 HTTP 请求；协程模式下每次调用拥有独立上下文。 */
	protected function handleMessage(TcpConnection $connection, Request $request): void
	{
		$container = Container::getInstance();
		$response = new Response(200);
		if (!$this->coroutine) {
			$container->bind('Request', $request);
			$container->bind('Response', $response);
		}

		try {
			// 长驻进程：清除上一请求残留的路由/视图相关 C()，否则 PathSegmentParser
			// 会因 C('ACTION') 非 null 而覆盖 PATH_INFO 解析出的 a/m（如 Article/read→index）
			$this->resetRequestScopedConfig();
			if ($this->coroutine) {
				$this->bootstrapCoroutineRequest($connection, $request, $response);
			} else {
				$this->bootstrapSuperglobals($connection, $request, $response);
			}
			$this->dispatch($connection, $request, $response);
		} catch (Throwable $e) {
			$body = 'Server Error: ' . $e->getMessage();
			$this->finish(
				$connection,
				$response->withStatus(500)->withBody($body),
				500,
				strlen($body),
				false
			);
		} finally {
			$this->clearRequestScope();
			// 响应已发送、作用域已清理，此时才可安全退出进程
			$this->maybeRestartWorker();
		}
	}

	/**
	 * 清理请求级 C() 与视图容器残留
	 *
	 * PathSegmentParser 在 C('CONTROL'/'ACTION') 非 null 时会用旧值覆盖 PATH 解析结果；
	 * S404 / TEMPLATE_DIR 等同样会串请求。View / ViewRenderer 若留在 Container
	 * 会跨请求复用模板变量与目录。须在每请求路由前清空。
	 *
	 * @return void
	 */
	protected function resetRequestScopedConfig(): void
	{
		// 上一请求若因不可恢复错误未进入 finally，在新请求覆盖超全局前兜底清理。
		$this->clearRequestScope();
		// SSE 连接只属于上一请求；清除绑定，避免普通请求误写入已关闭连接。
		Container::getInstance()->bind('Connection', null);
		// APP 由 DomainRouteMiddleware 按 Host 写入；它只能在当前请求有效。
		// 不清理会使 CMS 的 Home 绑定泄漏到下一次 /Admin/ 等显式应用请求。
		foreach (['APP', 'CONTROL', 'ACTION', 'S404', 'TEMPLATE_DIR', 'API', 'ISADDON', 'APP_PATH_LOCKED', 'APP_FS_REL', 'APP_FS_ROOT', 'APP_CONTROL_FILE', 'APP_CONTROL_VIA'] as $key) {
			\C($key, null);
		}
		CompileCache::clearRequestFileCache();
		Container::getInstance()->flushActionInstances();
		if (!$this->coroutine) {
			\Candy\Core\Respond::$buff = '';
		}
	}

	/**
	 * 填充超全局并应用 Worker 级 gzip / max-age
	 */
	protected function bootstrapSuperglobals(
		TcpConnection $connection,
		Request $request,
		Response $response
	): void {
		$microtime = microtime(true);
		if ($connection->protocol === 'websocket') {
			$_SERVER['REQUEST_SCHEME'] = 'WS';
		}

		$args = $request->extractArgs();
		$_POST = $args['_POST'] ?? [];
		$_GET = $args['_GET'] ?? [];
		$_REQUEST = $args['_REQUEST'] ?? [];
		$_SERVER = $args['_SERVER'] ?? [];

		$_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
		$_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
		$_SERVER['REQUEST_TIME'] = (int) $microtime;
		$_SERVER['REQUEST_TIME_FLOAT'] = $microtime;
		$_SERVER['REDIRECT_STATUS'] = 200;
$_SERVER['REDIRECT_REQUEST_UUID'] = \createUuidV4();

		$_SESSION = $request->session()->all();
		$_COOKIE = $request->cookie();
		$_FILES = $request->file();

		$acceptEncoding = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
		if ($acceptEncoding !== '' && str_contains($acceptEncoding, 'gzip') && isset($this->gzip)) {
			$response->gzip = $this->gzip;
		}
		if (isset($this->max_age)) {
			$response->max_age = $this->max_age;
		}
	}

	/** 初始化 Workerman Fiber 请求快照，不触碰 PHP 超全局。 */
	protected function bootstrapCoroutineRequest(TcpConnection $connection, Request $request, Response $response): void
	{
		$args = $request->extractArgs();
		$server = (array)($args['_SERVER'] ?? []);
		$server['REMOTE_ADDR'] = $connection->getRemoteIp();
		$server['REMOTE_PORT'] = $connection->getRemotePort();
		$server['REQUEST_TIME'] = time();
		$server['REQUEST_TIME_FLOAT'] = microtime(true);
		$server['REDIRECT_STATUS'] = 200;
		$server['REDIRECT_REQUEST_UUID'] = \createUuidV4();
		R([
			'get' => (array)($args['_GET'] ?? []),
			'post' => (array)($args['_POST'] ?? []),
			'server' => $server,
			'cookie' => (array)$request->cookie(),
			'files' => (array)$request->file(),
			'raw' => $request->rawBody(),
			'method' => $request->method(),
			'uri' => $request->uri(),
			'ip' => $connection->getRemoteIp(),
		]);
		$ctx = \Candy\Core\CoroutineContext::getContext();
		$ctx['_container_Request'] = $request;
		$ctx['_container_Response'] = $response;
		$acceptEncoding = (string)($server['HTTP_ACCEPT_ENCODING'] ?? '');
		if ($acceptEncoding !== '' && str_contains($acceptEncoding, 'gzip') && isset($this->gzip)) {
			$response->gzip = $this->gzip;
		}
		if (isset($this->max_age)) {
			$response->max_age = $this->max_age;
		}
	}

	/** 读取当前请求 SERVER 快照。 */
	protected function serverValue(string $key, mixed $default = null): mixed
	{
		return $this->coroutine ? R()->server($key, $default) : ($_SERVER[$key] ?? $default);
	}

	/** 写入当前请求 SERVER 快照。 */
	protected function setServerValue(string $key, mixed $value): void
	{
		if ($this->coroutine) {
			R()->setServer($key, $value);
			return;
		}
		$_SERVER[$key] = $value;
	}

	/**
	 * 清除当前 WebServer 请求的 T() 数据桶与 UUID
	 *
	 * @return void
	 */
	/**
	 * 达到 max_request 后让当前 worker 进程退出，由 master 自动重建
	 *
	 * ⚠️ 必须在响应发送完成、请求作用域清理之后调用（handleMessage 的 finally 末尾）。
	 * 提前调用会导致客户端收不到响应内容。
	 *
	 * 退出是 exit(0)，master 的 monitorWorkersForLinux() 收到子进程退出后
	 * 会执行 forkWorkers() 补回一个，且只重建当前这一个 worker，
	 * 不影响其它端口与其它 worker 进程。
	 *
	 * @return void
	 */
	protected function maybeRestartWorker(): void
	{
		if ($this->maxRequest <= 0) {
			return;
		}
		if (++$this->workerRequestCount < $this->maxRequest) {
			return;
		}
		Worker::log('worker[' . \getmypid() . '] reached max_request=' . $this->maxRequest . ', exit for master refork');
		exit(0);
	}

	protected function clearRequestScope(): void
	{
		if ($this->coroutine) {
			$uuid = (string)R()->server('REDIRECT_REQUEST_UUID', '');
			if ($uuid !== '' && function_exists('T')) {
				\T(null, null, null, $uuid);
			}
			\Candy\Core\Request::clearInstance();
			\Workerman\Coroutine\Context\Fiber::destroy();
			return;
		}
		if (isset($_SERVER['REDIRECT_REQUEST_UUID']) && function_exists('T')) {
			\T(null, null);
		}
		unset($_SERVER['REDIRECT_REQUEST_UUID']);
	}

	/**
	 * 路由分发：虚拟主机 → PHP / 静态 / 404
	 */
	protected function dispatch(
		TcpConnection $connection,
		Request $request,
		Response $response
	): void {
		$urlInfo = parse_url('http://' . $request->host() . $request->uri());
		if ($urlInfo === false) {
			$body = '<h1>400 Bad Request</h1>';
			$this->finish(
				$connection,
				$response->withStatus(400)->withBody($body),
				400,
				strlen($body),
				true
			);
			return;
		}

		$path = $urlInfo['path'] ?? '/';
		$pathInfo = pathinfo($path);
		$extension = isset($pathInfo['extension']) ? strtolower((string) $pathInfo['extension']) : '';
		if ($extension === '') {
			$path = str_ends_with($path, '/') ? $path . 'index.php' : $path . '/index.php';
			$extension = 'php';
		}

		$siteConfig = $this->resolveVirtualHost(
			(string) $this->serverValue('SERVER_NAME', $request->host()),
			$connection,
			$request,
			$response
		);
		if ($siteConfig === null) {
			return; // 已发送 302/502
		}

		$this->applyAdditionHeaders($response, $siteConfig);

		$rootDir = rtrim((string) $siteConfig['root'], '/');
		$file = $rootDir . $path;
		$this->setServerValue('DOCUMENT_ROOT', $rootDir);
		$this->setServerValue('DOCUMENT_URI', $path);

		[$file, $extension] = $this->resolveScriptFile($rootDir, $file, $extension, $path);

		if (!is_file($file)) {
			$this->sendNotFound($connection, $response, $siteConfig);
			return;
		}

		$realFile = realpath($file);
		$realRoot = realpath($rootDir);
		if ($realFile === false || $realRoot === false || !str_starts_with($realFile, $realRoot)) {
			$body = '<h1>400 Bad Request</h1>';
			$this->finish(
				$connection,
				$response->withStatus(400)->withBody($body),
				400,
				strlen($body),
				true
			);
			return;
		}

		$file = $realFile;
		if ($extension === 'php') {
			$this->handlePhp($connection, $request, $response, $rootDir, $file);
			return;
		}

		$this->handleStatic($connection, $request, $response, $file);
	}

	/**
	 * 解析虚拟主机；compel 跳转或未匹配时直接响应并返回 null
	 *
	 * @return array<string,mixed>|null
	 */
	protected function resolveVirtualHost(
		string $serverName,
		TcpConnection $connection,
		Request $request,
		Response $response
	): ?array {
		// HTTP 强制 HTTPS：按原始 Host 匹配 compel 列表（不再误判 *. 形式）
		if ($this->transport !== 'ssl' && in_array($serverName, self::$urlCompelList, true)) {
			$host = (string) $this->serverValue('HTTP_HOST', $request->host());
			$uri = (string) $this->serverValue('REQUEST_URI', $request->uri());
			$response->withStatus(302)->header('Location', 'https://' . $host . $uri);
			$this->finish($connection, $response, 302, 0, true);
			return null;
		}

		$name = $serverName;
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

		if (isset($this->serverRoot[$serverName])) {
			return $this->serverRoot[$serverName];
		}

		// 兜底站点：domain 配成 '*' 表示「不限 Host」（框架默认配置用它，便于先跑起来再接真实域名）
		if (isset($this->serverRoot['*'])) {
			return $this->serverRoot['*'];
		}

		$body = '<h1>502 Bad Gateway</h1>';
		$this->finish(
			$connection,
			$response->withStatus(502)->withBody($body),
			502,
			strlen($body),
			true
		);
		return null;
	}

	/**
	 * 解析最终脚本/静态文件路径（含 PATHINFO、index.html 回落）
	 *
	 * @return array{0:string,1:string} [file, extension]
	 */
	protected function resolveScriptFile(
		string $rootDir,
		string $file,
		string $extension,
		string $path
	): array {
		if ($extension === 'php' && !is_file($file)) {
			$workingDir = dirname($file);
			if (is_dir($workingDir) && is_file($workingDir . '/index.html')) {
				return [$workingDir . '/index.html', 'html'];
			}
			$indexPhp = $rootDir . '/index.php';
			if (is_file($indexPhp)) {
				$this->setServerValue('SCRIPT_FILENAME', $indexPhp);
				$this->setServerValue('SCRIPT_NAME', '/index.php');
				return [$indexPhp, 'php'];
			}
			return [$rootDir . '/index.html', 'html'];
		}

		if ($extension === 'php') {
			$this->setServerValue('SCRIPT_FILENAME', $file);
			$this->setServerValue('SCRIPT_NAME', $path);
			return [$file, 'php'];
		}

		$this->setServerValue('SCRIPT_FILENAME', $rootDir . '/index.php');
		$this->setServerValue('SCRIPT_NAME', '/index.php');

		if (defined('PATHINFO') && PATHINFO && !is_file($file)) {
			return [$rootDir . '/index.php', 'php'];
		}

		return [$file, $extension];
	}

	/**
	 * 附加虚拟主机自定义响应头
	 *
	 * @param array<string,mixed> $siteConfig
	 */
	protected function applyAdditionHeaders(Response $response, array $siteConfig): void
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
				[$type, $content] = array_map('trim', explode(':', $item, 2));
				$response->header($type, $content);
				continue;
			}
			if (is_array($item) && isset($item[0], $item[1])) {
				$response->header((string) $item[0], (string) $item[1]);
			}
		}
	}

	/**
	 * 执行 PHP 入口（捕获 echo；SSE 执行入口并保持原始连接写入）
	 */
	protected function handlePhp(
		TcpConnection $connection,
		Request $request,
		Response $response,
		string $rootDir,
		string $file
	): void {
		$cwd = getcwd() ?: $rootDir;
		chdir($rootDir);
		ini_set('display_errors', 'off');

		$accept = strtolower((string) $request->header('accept'));
		if ($accept === 'text/event-stream') {
			Container::getInstance()->bind('Connection', $connection);
			$connection->send(new Response(200, [
				'Content-Type' => 'text/event-stream',
				'Cache-Control' => 'no-cache',
				'Connection' => 'keep-alive',
				'X-Accel-Buffering' => 'no',
			]));
			$bufferLevel = ob_get_level();
			ob_start();
			try {
				$this->setServerValue('REMOTE_ADDR', $connection->getRemoteIp());
				$this->setServerValue('REMOTE_PORT', $connection->getRemotePort());
				include $file;
			} catch (\Exception $e) {
				if ($e->getMessage() !== 'jump_exit') {
					Worker::safeEcho($e);
				}
			} catch (Throwable $e) {
				Worker::safeEcho($e);
			} finally {
				while (ob_get_level() > $bufferLevel) {
					ob_end_clean();
				}
				$connection->close();
				$this->writeAccessLog(200, 0);
				chdir($cwd);
				ini_set('display_errors', 'on');
			}
			return;
		}

		$buffer = '';
		ob_start();
		try {
			$this->setServerValue('REMOTE_ADDR', $connection->getRemoteIp());
			$this->setServerValue('REMOTE_PORT', $connection->getRemotePort());
			include $file;
		} catch (\Exception $e) {
			if ($e->getMessage() !== 'jump_exit') {
				Worker::safeEcho($e);
			}
		} catch (Throwable $e) {
			Worker::safeEcho($e);
		}
		$buffer = (string) ob_get_clean();
		ini_set('display_errors', 'on');
		chdir($cwd);

		// 框架未写入 Response body 时，回填脚本直接输出
		if ($buffer !== '') {
			$current = method_exists($response, 'rawBody') ? (string) $response->rawBody() : '';
			if ($current === '') {
				$response->withBody($buffer);
			}
		}

		$status = (int) $this->serverValue('REDIRECT_STATUS', 200);
		$size = strlen(method_exists($response, 'rawBody') ? (string) $response->rawBody() : $buffer);
		$this->finish($connection, $response, $status > 0 ? $status : 200, $size, true);
	}

	/**
	 * 发送静态文件（304 / withFile）
	 */
	protected function handleStatic(
		TcpConnection $connection,
		Request $request,
		Response $response,
		string $file
	): void {
		$mtime = filemtime($file) ?: 0;

		$ifModifiedSince = (string) $this->serverValue('HTTP_IF_MODIFIED_SINCE',
			$this->serverValue('HTTP_IF_MODIFIED_SINEC', $request->header('if-modified-since') ?? '')
		);
		if ($ifModifiedSince !== '' && $mtime === strtotime($ifModifiedSince)) {
			$response->withStatus(304);
			$this->finish($connection, $response, 304, 0, true);
			return;
		}

		$ifNoneMatch = trim((string) $this->serverValue('HTTP_IF_NONE_MATCH', $request->header('if-none-match') ?? ''));
		if ($ifNoneMatch !== '') {
			$etag = $this->buildWeakEtag($file, $mtime);
			if ($etag === $ifNoneMatch) {
				$response->withStatus(304);
				$this->finish($connection, $response, 304, 0, true);
				return;
			}
			$response->header('ETag', $etag);
		}

		// __RANGE_FIX__ 2026-11-13：原实现直接 withFile($file)，Range 头未被解析，
		// 所有断点/拖动请求都返回 200 全量。这里解析单区间 Range 并传给 withFile，
		// 由 Http::encode 依据 file['offset'] / file['length'] 输出 206 + Content-Range。
		$fileSize = is_file($file) ? (int) filesize($file) : 0;
		$offset = 0;
		$length = 0;
		$rangeHeader = trim((string) $request->header('range'));
		if ($fileSize > 0 && $rangeHeader !== ''
			&& preg_match('/^bytes=(\d*)-(\d*)$/i', $rangeHeader, $m) === 1) {
			$start = $m[1] === '' ? null : (int) $m[1];
			$end   = $m[2] === '' ? null : (int) $m[2];
			if ($start === null && $end !== null) {
				// bytes=-N：取末尾 N 字节
				$start = max(0, $fileSize - $end);
				$end   = $fileSize - 1;
			} elseif ($start !== null && $end === null) {
				// bytes=N-：从 N 到末尾
				$end = $fileSize - 1;
			}
			if ($start !== null && $end !== null && $start <= $end && $start < $fileSize) {
				$end = min($end, $fileSize - 1);
				$offset = $start;
				$length = $end - $start + 1;
			}
		}

		$response->withFile($file, $offset, $length);
		$size = $fileSize;
		$this->finish($connection, $response, $offset > 0 || $length > 0 ? 206 : 200, $size, true);
	}

	/**
	 * 404 页面
	 *
	 * @param array<string,mixed> $siteConfig
	 */
	protected function sendNotFound(
		TcpConnection $connection,
		Response $response,
		array $siteConfig
	): void {
		$custom = $siteConfig['custom404'] ?? null;
		if (is_string($custom) && is_file($custom)) {
			$html = (string) file_get_contents($custom);
		} else {
			$html = '<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>';
		}
		$response->withStatus(404)->withBody($html);
		$this->finish($connection, $response, 404, strlen($html), true);
	}

	/**
	 * 发送响应并可选写 access.log
	 */
	protected function finish(
		TcpConnection $connection,
		Response $response,
		int $status,
		int $size,
		bool $writeLog
	): void {
		$this->setServerValue('REDIRECT_STATUS', $status);
		if ($writeLog) {
			$this->writeAccessLog($status, $size);
		}
		if ($this->shouldKeepAlive()) {
			$connection->send($response);
		} else {
			$connection->close($response);
		}
	}

	/**
	 * HTTP/1.1 默认 keep-alive；显式 close 则关闭
	 */
	protected function shouldKeepAlive(): bool
	{
		$conn = strtolower((string) $this->serverValue('HTTP_CONNECTION', ''));
		if ($conn === 'close') {
			return false;
		}
		if ($conn === 'keep-alive') {
			return true;
		}
		$protocol = strtoupper((string) $this->serverValue('SERVER_PROTOCOL', 'HTTP/1.1'));
		return str_contains($protocol, 'HTTP/1.1') || str_contains($protocol, 'HTTP/2');
	}

	/**
	 * 写入 access.log（flock，静态资源跳过）
	 */
	protected function writeAccessLog(int $status, int $size): void
	{
		$uri = (string) $this->serverValue('REQUEST_URI', '');
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
			(string) $this->serverValue('REMOTE_ADDR', '-'),
			date('d/M/Y:H:i:s O'),
			(string) $this->serverValue('REQUEST_METHOD', '-'),
			$uri !== '' ? $uri : '/',
			(string) $this->serverValue('SERVER_PROTOCOL', 'HTTP/1.1'),
			$status,
			max(0, $size),
			(string) $this->serverValue('HTTP_USER_AGENT', '-')
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

	/**
	 * 弱 ETag（与历史格式兼容）
	 */
	protected function buildWeakEtag(string $file, int $mtime): string
	{
		$etag = substr(md5($file . $mtime), 8, 16);
		$etag = substr($etag, 0, 5) . '-' . substr($etag, 5, 7) . '-' . substr($etag, 12);
		return 'W/"' . $etag . '"';
	}
}
