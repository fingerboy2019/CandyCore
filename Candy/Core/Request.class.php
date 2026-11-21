<?php
/**
 * Candy 框架请求抽象
 *
 * 集中管理请求数据（$_GET / $_POST / $_SERVER / $_COOKIE / $_FILES），
 * 业务代码统一通过本类或 request() 辅助函数访问，不再直接操作超全局变量。
 *
 * 后端适配：
 * - 默认：读取 PHP 超全局（PHP-FPM / CLI）
 * - Workerman：由 Http::decode / Request::extractArgs 写入超全局后本类读取
 * - Swoole（协程）：替换数据源为 Swoole\Http\Request + 协程上下文
 * - 测试：注入 mock 数据
 *
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-08-18 20:05:00 $
 */
declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Request
{
	/** @var array<string, mixed> GET 参数 */
	protected array $get = [];

	/** @var array<string, mixed> POST 参数 */
	protected array $post = [];

	/** @var array<string, mixed> 请求标头（小写键名） */
	protected array $headers = [];

	/** @var array<string, mixed> SERVER 数据 */
	protected array $server = [];

	/** @var array<string, string> Cookie */
	protected array $cookie = [];

	/** @var array<string, array> 上传文件 */
	protected array $files = [];

	/** @var string 请求方法 */
	protected string $method = 'GET';

	/** @var string 请求 URI */
	protected string $uri = '/';

	/** @var string|null 客户端 IP */
	protected ?string $ip = null;

	/** @var array<string, mixed> 合并参数（$_REQUEST 等价） */
	protected array $params = [];

	/** @var string|null 原始请求体；测试注入时优先使用 */
	protected ?string $rawBody = null;

	/** @var array<string, mixed>|null 原始请求体解析结果（惰性；null = 未解析） */
	protected ?array $bodyParams = null;

	/** @var self|null 当前请求实例（非协程共用） */
	private static ?self $instance = null;

	/**
	 * @param array $source 数据源，可注入 mock。空数组时从超全局自动读取。
	 */
	public function __construct(array $source = [])
	{
		if ($source !== []) {
			$this->initFromArray($source);
		} else {
			$this->initFromSuperGlobals();
		}
	}

	/**
	 * 获取当前请求实例
	 *
	 * Swoole 协程下应绑定到 Coroutine::getContext()，此处由 setInstance 接管。
	 *
	 * @return self
	 */
	public static function current(): self
	{
		// 协程模式优先
		if (self::inCoroutine()) {
			$ctx = CoroutineContext::getContext();
			if (isset($ctx['_candy_request'])) {
				return $ctx['_candy_request'];
			}
			$req = new self();
			$ctx['_candy_request'] = $req;
			return $req;
		}

		// 单例模式（CLI / Workerman / PHP-FPM）
		return self::$instance ??= new self();
	}

	/**
	 * 设置当前请求实例（Swoole onRequest / 测试注入）
	 *
	 * @param self $request
	 * @return void
	 */
	public static function setInstance(self $request): void
	{
		if (self::inCoroutine()) {
			CoroutineContext::getContext()['_candy_request'] = $request;
		} else {
			self::$instance = $request;
		}
	}

	/** 清理当前请求实例，供长驻协程请求结束时调用。 */
	public static function clearInstance(): void
	{
		if (self::inCoroutine()) {
			unset(CoroutineContext::getContext()['_candy_request']);
			return;
		}
		self::$instance = null;
	}

	/**
	 * 从超全局初始化
	 *
	 * @return void
	 */
	protected function initFromSuperGlobals(): void
	{
		$this->get    = $_GET;
		$this->post   = $_POST;
		$this->server = $_SERVER;
		$this->cookie = $_COOKIE;
		$this->files  = $_FILES;
		$this->buildParams();
		$this->buildHeaders();
		$this->method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
		$this->uri    = (string) ($this->server['REQUEST_URI'] ?? '/');
		$this->ip     = $this->server['REMOTE_ADDR'] ?? null;
	}

	/**
	 * 从数组初始化（注入 / mock）
	 *
	 * @param array{get?:array,post?:array,server?:array,cookie?:array,files?:array,raw?:string,method?:string,uri?:string,ip?:string,headers?:array} $source
	 * @return void
	 */
	protected function initFromArray(array $source): void
	{
		$this->get    = (array) ($source['get'] ?? []);
		$this->post   = (array) ($source['post'] ?? []);
		$this->server = (array) ($source['server'] ?? []);
		$this->cookie = (array) ($source['cookie'] ?? []);
		$this->files  = (array) ($source['files'] ?? []);
		$this->rawBody = array_key_exists('raw', $source) ? (string) $source['raw'] : null;
		$this->headers = array_change_key_case((array) ($source['headers'] ?? []), CASE_LOWER);
		$this->buildParams();
		$this->method = strtoupper((string) ($source['method'] ?? $this->server['REQUEST_METHOD'] ?? 'GET'));
		$this->uri    = (string) ($source['uri'] ?? $this->server['REQUEST_URI'] ?? '/');
		$this->ip     = $source['ip'] ?? $this->server['REMOTE_ADDR'] ?? null;
	}

	/**
	 * 构建合并参数
	 *
	 * @return void
	 */
	protected function buildParams(): void
	{
		$this->params = array_merge($this->get, $this->post);
	}

	/**
	 * 从 SERVER 数据构建标头映射
	 *
	 * @return void
	 */
	protected function buildHeaders(): void
	{
		$headers = [];
		foreach ($this->server as $key => $value) {
			if (str_starts_with($key, 'HTTP_')) {
				$name = strtolower(str_replace('_', '-', substr($key, 5)));
				$headers[$name] = $value;
			}
		}
		// CONTENT_TYPE / CONTENT_LENGTH 不以 HTTP_ 开头
		if (isset($this->server['CONTENT_TYPE'])) {
			$headers['content-type'] = $this->server['CONTENT_TYPE'];
		}
		if (isset($this->server['CONTENT_LENGTH'])) {
			$headers['content-length'] = $this->server['CONTENT_LENGTH'];
		}
		$this->headers = $headers;
	}

	// ------------------------------------------------------------------ //
	//  访问器
	// ------------------------------------------------------------------ //

	/**
	 * 获取全部 GET 参数
	 *
	 * @return array<string, mixed>
	 */
	public function getData(): array { return $this->get; }

	/**
	 * 向当前请求 GET 数据合并路由等派生参数
	 *
	 * @param array<string,mixed> $data 需要写入的 GET 参数
	 * @return void
	 */
	public function mergeGet(array $data): void
	{
		$this->get = array_merge($this->get, $data);
		$this->buildParams();
		$this->syncLegacyGlobals();
	}

	/**
	 * 替换当前请求全部 GET 数据
	 *
	 * URL 查询串重解析时使用，避免旧参数残留在 R() 合并参数中。
	 *
	 * @param array<string,mixed> $data 新 GET 数据
	 * @return void
	 */
	public function replaceGet(array $data): void
	{
		$this->get = $data;
		$this->buildParams();
		$this->syncLegacyGlobals();
	}

	/**
	 * 获取全部 POST 参数
	 *
	 * @return array<string, mixed>
	 */
	public function postData(): array { return $this->post; }

	/**
	 * 向当前请求 POST 数据合并解码后的中间件参数。
	 *
	 * @param array<string,mixed> $data 需要合并的参数
	 * @return void
	 */
	public function mergePost(array $data): void
	{
		$this->post = array_merge($this->post, $data);
		$this->buildParams();
		$this->syncLegacyGlobals();
	}

	/**
	 * 替换当前请求的全部 POST 数据
	 *
	 * 适用于防火墙等需要整体拒绝或重建 POST 载荷的场景；替换后同步刷新
	 * R() 的合并参数快照，避免后续读取继续取得过滤前的数据。
	 *
	 * @param array<string,mixed> $data 新 POST 数据
	 * @return void
	 */
	public function replacePost(array $data): void
	{
		$this->post = $data;
		$this->buildParams();
		$this->syncLegacyGlobals();
	}

	/**
	 * 向当前请求参数合并认证等派生数据。
	 *
	 * @param array<string,mixed> $data 需要合并的派生参数
	 * @return void
	 */
	public function mergeInput(array $data): void
	{
		$this->params = array_merge($this->params, $data);
		$this->syncLegacyGlobals();
	}

	/**
	 * 获取全部 SERVER 参数
	 *
	 * @return array<string, mixed>
	 */
	public function serverData(): array { return $this->server; }

	/**
	 * 获取全部 Cookie
	 *
	 * @return array<string, string>
	 */
	public function cookieData(): array { return $this->cookie; }

	/**
	 * 获取全部上传文件
	 *
	 * @return array<string, array>
	 */
	public function filesData(): array { return $this->files; }

	/**
	 * 向当前请求文件快照合并适配器解析出的上传字段。
	 *
	 * @param array<string,array> $data 上传文件字段
	 * @return void
	 */
	public function mergeFiles(array $data): void
	{
		$this->files = array_merge($this->files, $data);
		$this->syncLegacyGlobals();
	}

	/**
	 * 在非协程运行时同步旧代码依赖的 PHP 超全局快照。
	 *
	 * 协程请求禁止写入进程级超全局，业务代码统一从当前 Request 快照读取。
	 *
	 * @return void
	 */
	protected function syncLegacyGlobals(): void
	{
		if (self::inCoroutine()) {
			return;
		}
		$_GET = $this->get;
		$_POST = $this->post;
		$_REQUEST = $this->params;
		$_SERVER = $this->server;
		$_FILES = $this->files;
	}

	/**
	 * 获取全部请求参数（GET + POST 合并）
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array { return $this->params; }

	/**
	 * 获取单个 GET 参数
	 *
	 * @param string $key 参数名
	 * @param mixed $default 默认值
	 * @return mixed
	 */
	public function get(string $key, mixed $default = null): mixed
	{
		return $this->get[$key] ?? $default;
	}

	/**
	 * 获取单个 POST 参数
	 *
	 * @param string $key 参数名
	 * @param mixed $default 默认值
	 * @return mixed
	 */
	public function post(string $key, mixed $default = null): mixed
	{
		return $this->post[$key] ?? $default;
	}

	/**
	 * 获取单个 SERVER 参数
	 *
	 * @param string $key 参数名（如 REQUEST_URI、HTTP_HOST）
	 * @param mixed $default 默认值
	 * @return mixed
	 */
	public function server(string $key, mixed $default = null): mixed
	{
		return $this->server[$key] ?? $default;
	}

	/**
	 * 写入当前请求 SERVER 快照
	 *
	 * 仅用于 Markdown 等历史入口的受控路径改写；同步 URI、方法和 IP 派生字段。
	 *
	 * @param string $key SERVER 键名
	 * @param mixed $value 新值
	 * @return void
	 */
	public function setServer(string $key, mixed $value): void
	{
		$this->server[$key] = $value;
		if ($key === 'REQUEST_URI') {
			$this->uri = (string)$value;
		} elseif ($key === 'REQUEST_METHOD') {
			$this->method = strtoupper((string)$value);
		} elseif ($key === 'REMOTE_ADDR') {
			$this->ip = (string)$value;
		}
		$this->syncLegacyGlobals();
	}

	/**
	 * 替换当前请求全部 SERVER 数据
	 *
	 * 路由上下文完成 PATH 重写后使用，确保 R() 与兼容 $_SERVER 一致。
	 *
	 * @param array<string,mixed> $data 新 SERVER 数据
	 * @return void
	 */
	public function replaceServer(array $data): void
	{
		$this->server = $data;
		$this->buildHeaders();
		$this->method = strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
		$this->uri = (string)($this->server['REQUEST_URI'] ?? '/');
		$this->ip = $this->server['REMOTE_ADDR'] ?? null;
		$this->syncLegacyGlobals();
	}

	/**
	 * 获取单个 Cookie 值
	 *
	 * @param string $key Cookie 名
	 * @param mixed $default 默认值
	 * @return string|null
	 */
	public function cookie(?string $key = null, mixed $default = null): mixed
	{
		if ($key === null || $key === '') {
			return $this->cookie;
		}
		return $this->cookie[$key] ?? $default;
	}

	/**
	 * 写入当前请求 Cookie 快照
	 *
	 * 用于登录、刷新令牌等同一请求内需要立即读取刚设置 Cookie 的场景；
	 * 实际浏览器响应仍由 cookie()/setcookie() 负责发送。
	 *
	 * @param string $key Cookie 名
	 * @param string $value Cookie 值
	 * @return void
	 */
	public function setCookie(string $key, string $value): void
	{
		$this->cookie[$key] = $value;
	}

	/**
	 * 删除当前请求 Cookie 快照
	 *
	 * @param string $key Cookie 名
	 * @return void
	 */
	public function removeCookie(string $key): void
	{
		unset($this->cookie[$key]);
	}

	/**
	 * 获取单个上传文件
	 *
	 * @param string $key 文件字段名
	 * @return array|null 文件信息数组，不存在时返回 null
	 */
	public function file(string $key): ?array
	{
		return $this->files[$key] ?? null;
	}

	/**
	 * 获取任意请求参数（优先 GET，同名时 POST 覆盖）
	 *
	 * @param string $key 参数名
	 * @param mixed $default 默认值
	 * @return mixed
	 */
	public function input(string $key, mixed $default = null): mixed
	{
		return $this->params[$key] ?? $default;
	}

	/**
	 * 获取请求头
	 *
	 * @param string|null $name 标头名（不区分大小写）；null 时返回全部
	 * @param mixed $default 默认值
	 * @return mixed
	 */
	public function header(?string $name = null, mixed $default = null): mixed
	{
		if ($name === null) {
			return $this->headers;
		}
		return $this->headers[strtolower($name)] ?? $default;
	}

	/**
	 * 获取请求方法
	 *
	 * @return string GET/POST/PUT/DELETE 等
	 */
	public function method(): string { return $this->method; }

	/**
	 * 获取请求 URI
	 *
	 * @return string
	 */
	public function uri(): string { return $this->uri; }

	/**
	 * 获取客户端 IP
	 *
	 * @return string|null
	 */
	public function ip(): ?string { return $this->ip; }

	/**
	 * 获取原始请求体。
	 * Swoole 通过当前协程的入站适配器读取，其他 SAPI 保持 PHP 输入流语义。
	 */
	public function rawBody(): string
	{
		if ($this->rawBody !== null) {
			return $this->rawBody;
		}
		// 常驻运行时（Swoole 协程 / Workerman）里 php://input 是空的：
		//   Swoole 由协程上下文提供，Workerman 的 body 已被协议解析器消费，
		// 只能从容器绑定的请求适配器取（Workerman\Protocols\Http\Request::rawBody）。
		// FPM 仍保持 php://input 语义。
		if (self::inCoroutine() || isWebServer()) {
			$adapter = Container::getInstance()->get('Request');
			if ($adapter !== null && $adapter !== $this && !($adapter instanceof self)
				&& method_exists($adapter, 'rawBody')
			) {
				return $this->rawBody = (string)$adapter->rawBody();
			}
			if (self::inCoroutine()) {
				return $this->rawBody = '';
			}
		}
		return $this->rawBody = (string)file_get_contents('php://input');
	}

	/**
	 * 解析原始请求体为参数数组（惰性 + 单请求缓存）
	 *
	 * 业务说明：常驻运行时（Swoole / Workerman）$_POST 不参与读取，application/json
	 * 请求体只能从 rawBody 得到；FPM 下 $_POST 也不包含 JSON。统一由本方法兜底，
	 * 让 I('post.*') 在三种运行时下行为一致。
	 *
	 * @return array<string, mixed>
	 */
	public function bodyParams(): array
	{
		if ($this->bodyParams !== null) {
			return $this->bodyParams;
		}
		$raw = $this->rawBody();
		if ($raw === '') {
			return $this->bodyParams = [];
		}
		$ct = (string) ($this->server['CONTENT_TYPE'] ?? ($this->server['HTTP_CONTENT_TYPE'] ?? ($this->headers['content-type'] ?? '')));
		$head = ltrim($raw);
		if (stripos($ct, 'application/json') !== false || str_starts_with($head, '{') || str_starts_with($head, '[')) {
			$data = json_decode($raw, true);
			return $this->bodyParams = is_array($data) ? $data : [];
		}
		$out = [];
		parse_str($raw, $out);
		return $this->bodyParams = $out;
	}

	/** 获取当前请求关联的会话对象；未启用时返回 null。 */
	public function session(): mixed
	{
		$adapter = Container::getInstance()->get('Request');
		return $adapter !== null && method_exists($adapter, 'session') ? $adapter->session() : null;
	}

	/** 获取当前请求关联的响应适配器。 */
	public function response(): mixed
	{
		return Container::getInstance()->get('Response');
	}

	/**
	 * 是否 GET 请求
	 *
	 * @return bool
	 */
	public function isGet(): bool { return $this->method === 'GET'; }

	/**
	 * 是否 POST 请求
	 *
	 * @return bool
	 */
	public function isPost(): bool { return $this->method === 'POST'; }

	/**
	 * 是否 AJAX 请求
	 *
	 * 通过 X-Requested-With 标头判断。
	 *
	 * @return bool
	 */
	public function isAjax(): bool
	{
		return $this->header('x-requested-with') === 'xmlhttprequest';
	}

	// ------------------------------------------------------------------ //
	//  协程检测
	// ------------------------------------------------------------------ //

	/**
	 * 当前是否在 Swoole 协程中
	 *
	 * @return bool
	 */
	public static function inCoroutine(): bool
	{
		return isSwooleCoroutine();
	}

	/**
	 * 从当前超全局重新初始化 Request 实例
	 *
	 * 在路由完成后调用，确保 request()->get('m') 等能拿到路由写入的值。
	 *
	 * @return void
	 */
	public static function refresh(): void
	{
		if (self::inCoroutine()) {
			return;
		}
		self::setInstance(new self());
	}
}
