<?php
/**
 * Candy框架 响应输出基础类
 * 
 * 提供HTTP响应管理、内容输出、头部设置、状态码控制等核心功能
 * 支持不同响应类型（HTML、JSON等）的灵活输出
 * 包含缓存控制、Cookie管理、Session管理等特性
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @abstract 抽象类，不能直接实例化，需通过静态方法create()创建具体响应类型实例
 */

declare (strict_types = 1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 响应基类，提供HTTP响应的基础功能和接口
 * 
 * 该类是一个抽象类，定义了响应系统的核心功能和接口，
 * 具体的响应类型（如HTML、JSON等）需要继承此类并实现相应的功能。
 */
abstract class Respond
{
    /**
     * 原始数据，存储待输出的原始内容
     * 
     * @var mixed 可以是任意类型的数据，最终会被处理为字符串输出
     */
    protected $data;

    /**
     * 当前内容类型(contentType)
     * 
     * @var string 默认值为'text/html'
     */
    protected $contentType = 'text/html';

    /**
     * 字符集编码
     * 
     * @var string 默认值为'utf-8'
     */
    protected $charset = 'utf-8';

    /**
     * HTTP状态码
     * 
     * @var integer 默认值为200(成功)
     */
    protected $code = 200;

    /**
     * 是否允许请求缓存标志
     * 
     * @var bool 默认允许缓存(true)
     */
    protected $allowCache = true;

    /**
     * 输出选项参数数组
     * 
     * @var array 用于存储各种输出相关的配置选项
     */
    protected $options = [];

    /**
     * HTTP响应头信息数组
     * 
     * @var array 存储待发送的HTTP响应头
     */
    protected $header = [];

    /**
     * 最终输出内容
     * 
     * @var string|null 默认为null，表示尚未生成最终内容
     */
    protected $content = null;

    /**
     * Cookie对象
     * 
     * @var \Candy\Extend\Cookie Cookie操作对象实例
     */
    protected $cookie;

    /**
     * Session对象
     * 
     * @var \Candy\Extend\Session Session操作对象实例
     */
    protected $session;

    /**
     * 正文输出前的缓冲字符串
     * 
     * @var string 静态属性，存储所有响应共享的前置输出内容
     */
    public static $buff = '';

    /**
     * @var list<string> 响应级过滤器（send 前执行，覆盖 HTML / JSON / 文件等所有输出）
     *
     * 与 View::registerOutputfilter 的区别：
     * - registerOutputfilter → 只作用于模板渲染结果，.json 接口不经过 makeContent()
     * - Respond::filter()    → 作用于 send() 的最终 $data，覆盖所有输出类型
     */
    private static array $responseFilters = [];
	
    /**
     * 添加内容到前置缓冲区
     * 
     * 用于在主响应内容输出前添加额外内容
     * 
     * @param mixed $data 要添加到缓冲区的数据，将被转换为字符串
     * @return void
     */
    public static function buff(string $data = ''): void
    {
        if (Request::inCoroutine()) {
            C('RESPOND_BUFF', (string)C('RESPOND_BUFF', '', '') . $data);
            return;
        }
        self::$buff .= $data;
    }
	
    /**
     * 是否跳过底部调试信息栏（错误页、halt 等终止场景）
     *
     * @var bool
     */
    protected bool $skipDebugBar = false;

    /**
     * 初始化响应对象
     * 
     * 设置响应数据、状态码和内容类型
     * 
     * @param mixed $data 输出数据
     * @param int $code HTTP状态码，默认为200
     * @return void
     */
    protected function init(string $data = '', int $code = 200): void
    {
        $this->data($data);
        $this->code = $code;
        $this->contentType($this->contentType, $this->charset);
    }

    /**
     * 创建响应对象实例的静态工厂方法
     * 
     * 根据指定的响应类型动态加载对应的响应处理类
     * 支持直接指定类名或使用预定义的响应类型（如html、json等）
     * 自动添加前置缓冲区内容到输出数据
     * 
     * @param mixed $data 要输出的数据内容
     * @param string $type 响应类型或完整的类名，默认为'html'
     * @param int $code HTTP状态码，默认为200
     * @return Respond 返回响应对象实例
     * @throws \ReflectionException 当类反射创建失败时抛出
     */
    public static function create(?string $data = '', string $type = 'html', int $code = 200): Respond
    {
        $class = false !== strpos($type, '\\') ? $type : '\\Candy\\Core\\Impl\\Respond\\' . ucfirst(strtolower($type));
		
		if(class_exists($class) === false){
			$class =  '\\Candy\\Core\\Impl\\Respond\\Api';
		}
		
		// 前置输出：协程模式使用 C() 请求桶，避免静态属性串到其他请求。
		if (Request::inCoroutine()) {
			$data = (string)C('RESPOND_BUFF', '', '') . $data;
			C('RESPOND_BUFF', null);
		} else {
			$data = self::$buff . $data;
			self::$buff = '';
		}
        return Container::getInstance()->invokeClass($class, [$data, $code]);
    }

    /**
     * 设置Session对象
     * @param \Candy\Extend\Session $session Session对象
     * @return $this
     */
    public function setSession(\Candy\Extend\Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    /**
     * 将响应数据发送到客户端浏览器
     * 
     * 负责处理完整的响应流程：获取处理后的内容、发送HTTP状态码、
     * 设置响应头信息、输出内容到客户端
     * 在支持的环境中自动调用fastcgi_finish_request提高页面响应速度
     * 
     * @return void
     * @throws \InvalidArgumentException
     */
    /**
     * 注册响应级过滤器（2026-11-13）
     *
     * 在 Respond::send() 把内容发给客户端之前，按注册顺序依次执行：
     * ```php
     * Respond::filter('okrInjectTrace');   // function okrInjectTrace(string $data): string
     * ```
     * 覆盖 HTML 页面、.json 接口、文件下载等**所有**输出；
     * 与模板级 `View::registerOutputfilter` 互补（后者只管 HTML）。
     *
     * @param string $funcname 过滤函数名，签名 fn(string $data): string
     * @return void
     */
    public static function filter(string $funcname, ?array $types = null, int $priority = 10): void
    {
        // 2026-11-13：转发到 HookPipeline；$types 传 ['html'] 可让钩子自动跳过 JSON 输出
        \Candy\Core\HookPipeline::add('response', $funcname, $priority, $types);
    }

    /**
     * 清空响应级过滤器
     * @return void
     */
    public static function clearFilters(): void
    {
        \Candy\Core\HookPipeline::clear('response');
    }

    public function send(): void
    {
		$server = I('server.', [], '');
        // 处理输出数据
        $data = $this->getContent();

        // 响应级过滤器（2026-11-13）：统一加工最终输出，覆盖 HTML / JSON / 其他
        // 放在 ETag 计算之前，保证弱校验值基于过滤后的内容
        // 响应级钩子（2026-11-13 统一走 HookPipeline）
        // content_type 决定 types 白名单是否放行：声明 ['html'] 的钩子在 JSON 输出时自动跳过，
        // 避免给 JSON 追加 HTML 导致前端 JSON.parse 失败
        // content_type 直接取 API 模式的值：C('API') 为 null 时是普通 HTML 页面，
        // 非 null 时其值即输出格式（'json' / 'xml' ...），不靠后缀或 Content-Type 猜，
        // 因此 types 白名单可以精确到具体格式（如 ['html'] / ['json'] / ['xml']）
        $__apiFormat  = C('API');
        $__respType   = is_null($__apiFormat) ? 'html' : (string) $__apiFormat;
        $__respIsJson = ($__respType === 'json');
        $data = \Candy\Core\HookPipeline::run('response', $data, [
            'content_type' => $__respType,
            'is_json'      => $__respIsJson,
            'is_api'       => !is_null($__apiFormat),
            'api_format'   => $__apiFormat,
        ]);

        // 动态 JSON / 非 GET：禁止 ETag 304。304 无响应体，XHR 在无可靠磁盘缓存时拿到空串，
        // Admin layTable / $.getJSON 会一直停在 loading（计划任务页即此现象）
        if ($this->shouldNegotiateEtag()) {
            $etag = \substr(\md5($data), 8, 16);
            $etag = \substr($etag, 0, 7) . '-' . \substr($etag, 7, 5) . '-' . \substr($etag, 12);
            $etag = 'W/"' . $etag . '"';
            $this->eTag($etag);
            if (isset($server['HTTP_IF_NONE_MATCH']) && $etag == trim($server['HTTP_IF_NONE_MATCH'])) {
                $this->code = 304;
				if (!Request::inCoroutine()) {
					$_SERVER['REDIRECT_STATUS'] = 304;
				}
            }
        } elseif ($this->isJsonLikeResponse()) {
            // 明确禁止中间层/浏览器把动态 JSON 当静态缓存
            $this->header['Cache-Control'] = 'no-store, no-cache, must-revalidate';
            $this->header['Pragma'] = 'no-cache';
        }

        if(isWebServer()){
            //ACCESS记录
			$log = ($server['REMOTE_ADDR'] ?? '-') . ' - ['. date('d/M/Y:H:i:s O') .'] "'. ($server['REQUEST_METHOD'] ?? 'GET') .' '. ($server['PATH_INFO'] ?? '/') .' '. ($server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') .'" {STATUS} {SIZE} "-" "'. ($server['HTTP_USER_AGENT'] ?? '') ."\"\n";
            $extra = ['.css','.jpg','.jpeg','.gif','.png','.ico','.mp4','.txt','.js','.eot','.svg','.ttf','.woff','.woff2'];
            $rep = ['{STATUS}', '{SIZE}'];
            for($i=0;$i<count($extra);$i++){
                if(stristr((string)($server['REQUEST_URI'] ?? ''), $extra[$i])){
                    $log = '';
                    break;
                }
            }
            
            //记录log
            if(!empty($log))
                \file_put_contents(C('LOGPATH').'/access.log', str_replace($rep, [$this->code, strlen($data)], $log), FILE_APPEND);

            $response = \Candy\Core\Container::getInstance()->get('Response');
            // 逐个 header() 设置而不是 withHeaders()：后者的实现是
            // array_merge_recursive($this->headers, $headers)，同名头会被合并成数组，
            // 最终输出两行 Content-Type（如 application/json 重复两次）。
            // header() 是替换语义（$this->headers[$name] = $value），才能正确覆盖。
            foreach ($this->header as $__hk => $__hv) {
                if ($__hv === null) {
                    continue;
                }
                $response->header((string) $__hk, (string) $__hv);
            }
            $response->withStatus($this->code);

            if($this->code != 304){
                $response->withBody($data);
            }
        } else {
            if (!headers_sent() && !empty($this->header)) {
                // 发送状态码
                http_response_code($this->code);
                // 发送头部信息
                foreach ($this->header as $name => $val) {
                    header($name . (!is_null($val) ? ':' . $val : ''));
                }
            }

            if($this->code == 304) $data = '';
            
            $this->sendData($data);

            if (function_exists('fastcgi_finish_request') && isWebServer() === false) {
                // 提高页面响应
                fastcgi_finish_request();
            }
        }

		// terminate 阶段（2026-11-13）：响应已发送给客户端之后执行
		// 语义等同 ThinkPHP 的 middleware terminate：访问日志、慢请求告警、异步任务派发
		// 必须 try/catch：响应已经发出，此阶段的异常无法再改变结果，只能吞掉
		try {
			\Candy\Core\Security::runMiddlewares('Terminate');
		} catch (\Throwable) {
			// terminate 内的异常不影响已发送的响应
		}

		runApplicationDestructor();
    }

    /**
     * 是否应对当前响应做 ETag / 304 协商
     *
     * 仅适合可缓存的静态或幂等 HTML GET；动态 JSON API 必须关闭
     *
     * @return bool
     */
    protected function shouldNegotiateEtag(): bool
    {
		$method = strtoupper((string)I('server.REQUEST_METHOD', 'GET', ''));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        if ($this->isJsonLikeResponse()) {
            return false;
        }
        return true;
    }

    /**
     * 是否为 JSON 类动态接口响应（.json 后缀或 Content-Type）
     *
     * @return bool
     */
    protected function isJsonLikeResponse(): bool
    {
		$server = I('server.', [], '');
        $path = (string) ($server['PATH_INFO'] ?? '');
		if ($path === '' && isset($server['REQUEST_URI'])) {
			$path = (string) parse_url((string) $server['REQUEST_URI'], PHP_URL_PATH);
        }
        if ($path !== '' && str_ends_with(strtolower($path), '.json')) {
            return true;
        }

        $type = strtolower((string) ($this->header['Content-Type'] ?? $this->contentType ?? ''));
        return str_contains($type, 'application/json') || str_contains($type, '+json');
    }

    /**
     * 处理响应数据的核心方法
     * 
     * 当数据为空时，尝试从当前控制器或Action对象获取内容
     * 支持自动关联容器中的控制器实例
     * 
     * @param mixed $data 要处理的数据
     * @return mixed 返回处理后的数据，可能是原始数据或从控制器获取的内容
     */
    protected function output(string $data): string
    {
		if(empty($data)){
			$objName = C('APPNAME') . C('CONTROL');
			$objWorker = Container::getInstance()->get($objName) ?? Container::getInstance()->get('Action');
			if(is_null($objWorker) === false)
				return $objWorker->content;
		}
		
        return $data;
    }

    /**
     * 输出数据到客户端
     * 
     * 将处理好的响应数据输出到客户端，并在调试模式下显示调试信息
     * 
     * @param string $data 要输出的数据内容
     * @return void
     */
    protected function sendData(string $data): void
    {
        echo $data;
    }

    /**
     * 设置输出选项参数
     * 
     * 合并新的选项参数到已有的选项中
     * 
     * @param array $options 输出选项参数数组
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function options(array $options = []): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * 设置响应数据
     * 
     * 设置要输出的原始数据，数据将在后续处理中被转换为最终输出内容
     * 
     * @param mixed $data 输出数据，可以是任意类型
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function data(string $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 设置是否允许请求缓存
     * 
     * 控制响应是否可以被浏览器或代理服务器缓存
     * 
     * @param bool $cache true表示允许缓存，false表示禁止缓存
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function allowCache(bool $cache): self
    {
        $this->allowCache = $cache;
        return $this;
    }

    /**
     * 获取是否允许请求缓存的状态
     * 
     * @return bool 返回当前缓存设置状态
     */
    public function isAllowCache(): bool
    {
        return $this->allowCache;
    }

    /**
     * 设置Cookie
     * 
     * 通过Cookie对象设置HTTP Cookie
     * 
     * @param string $name cookie名称
     * @param string $value cookie值
     * @param mixed $option 可选参数，可包含过期时间、路径、域名等设置
     * @return $this 返回当前响应对象，支持链式调用
     * @throws \Exception 当Cookie对象未初始化时可能抛出异常
     */
    public function cookie(string $name, string $value, $option = null): self
    {
        $this->cookie->set($name, $value, $option);
        return $this;
    }

    /**
     * 设置HTTP响应头
     * 
     * 合并新的响应头到已有的响应头中
     * 
     * @param array $header 响应头数组，格式为[name => value]
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function header(array $header = []): self
    {
        $this->header = array_merge($this->header, $header);
        return $this;
    }

    /**
     * 设置页面最终输出内容
     * 
     * 直接设置响应的内容字符串，跳过默认的数据处理流程
     * 会进行严格的类型检查，确保内容可转换为字符串
     * 
     * @param mixed $content 要设置的内容，可以是字符串、数字或实现__toString方法的对象
     * @return $this 返回当前响应对象实例，支持链式调用
     * @throws \InvalidArgumentException 当内容无法转换为字符串时抛出异常
     */
    public function content(string $content): self
    {
        if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable([$content,'__toString',])) {
            throw new \InvalidArgumentException(sprintf('variable type error： %s', gettype($content)));
        }

        $this->content = (string) $content;
        return $this;
    }

    /**
     * 设置HTTP状态码
     * 
     * 设置响应的HTTP状态码，如200(成功)、404(未找到)、500(服务器错误)等
     * 
     * @param int $code HTTP状态码
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function code(int $code): self
    {
        $this->code = $code;
        return $this;
    }

    /**
     * 设置Last-Modified响应头
     * 
     * 用于标记资源的最后修改时间，帮助客户端缓存决策
     * 
     * @param string $time 最后修改时间，应符合HTTP日期格式
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function lastModified(string $time): self
    {
        $this->header['Last-Modified'] = $time;
        return $this;
    }

    /**
     * 设置Expires响应头
     * 
     * 指定响应的过期时间，告知客户端何时需要重新请求资源
     * 
     * @param string $time 过期时间，应符合HTTP日期格式
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function expires(string $time): self
    {
        $this->header['Expires'] = $time;
        return $this;
    }

    /**
     * 设置ETag响应头
     * 
     * 提供资源的实体标签，用于缓存验证和条件请求
     * 
     * @param string $eTag 实体标签值
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function eTag(string $eTag): self
    {
        $this->header['ETag'] = $eTag;
        return $this;
    }

    /**
     * 设置Cache-Control响应头
     * 
     * 控制缓存行为，如no-cache(不缓存)、max-age(缓存时长)等
     * 
     * @param string $cache 缓存控制指令
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function cacheControl(string $cache): self
    {
        $this->header['Cache-control'] = $cache;
        return $this;
    }

    /**
     * 设置Content-Type响应头
     * 
     * 指定响应的媒体类型和字符集
     * 
     * @param string $contentType 媒体类型，如'text/html'、'application/json'等
     * @param string $charset 字符编码，默认为'utf-8'
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function contentType(string $contentType, string $charset = 'utf-8'): self
    {
        $this->header['Content-Type'] = $contentType . '; charset=' . $charset;
        return $this;
    }

    /**
     * 获取响应头信息
     * 
     * 获取指定的响应头值或所有响应头
     * 
     * @param string $name 头部名称，留空则返回所有头部
     * @return mixed 如果指定了名称则返回对应的值或null(不存在时)，否则返回所有头部数组
     */
    public function getHeader(string $name = ''): mixed
    {
        if (!empty($name)) {
            return $this->header[$name] ?? null;
        }

        return $this->header;
    }

    /**
     * 获取设置的原始响应数据
     * 
     * 返回未经处理的原始数据对象，不进行任何类型转换或格式化
     * 用于直接访问原始数据，可能需要后续处理
     * 
     * @return mixed 返回设置的原始数据，数据类型由调用方决定
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * 获取最终的输出内容
     * 
     * 如果内容未设置，则会自动调用output方法处理原始数据
     * 确保返回的内容为字符串类型
     * 
     * @return string 返回处理后的字符串格式输出内容
     * @throws \InvalidArgumentException 当处理后的数据无法转换为字符串时抛出异常
     */
    public function getContent(): string
    {
        if (null == $this->content) {
            $content = $this->output($this->data);
            if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable([$content,'__toString',])) {
                throw new \InvalidArgumentException(sprintf('variable type error： %s', gettype($content)));
            }

            $this->content = (string) $content;
        }

        if($this->code == 200 && $this->skipDebugBar === false) {
            $this->content .= $this->showDebugMsg();
        }
        
        return $this->content;
    }

    /**
     * 获取HTTP状态码
     * 
     * @return int 返回当前设置的HTTP状态码
     */
    public function getCode(): int
    {
        return $this->code;
    }
    
    /**
     * Show Debug Msg
     * @return string
     */
    private function showDebugMsg(): string
    {
        if(isDebug() && is_null(C('API')) && is_null(C('XHPROF_ON')))
        {
            $debugState = Debug::snapshot();
            $trace = '<style type="text/css">#candy_debug{display:none;margin:0;padding:0;font-size:12px;font-family:"微软雅黑";line-height:20px;text-align:left;border-top:1px solid #ddd;color:#333;background:#fff;position:fixed;_position:absolute;bottom:0;left:0;width:100%;z-index:999999;box-shadow:-2px 2px 20px #555}#candy_open{display:block;height:28px;line-height:28px;border-top-left-radius:3px;z-index:999998;font-family:"微软雅黑";float:right;text-align:right;overflow:hidden;position:fixed;_position:absolute;bottom:0;right:0;background:#232323;color:#fff;font-size:14px;padding:0 8px;cursor:pointer}.tab{padding-left:15px;height:36px;line-height:36px;border-bottom:1px solid #ddd;background-color:#f5f5f5;color:#444}.button{cursor:pointer;float:right;color:#333;padding:0 5px;margin-right:10px}.info{font-size:14px;margin-left:50px}.nav{display:block;width:12%;max-width:100px;float:left;text-align:center;font-size:14px;text-shadow:1px 1px 0 #f2f2f2;cursor:pointer}.mt5{margin-top:5px}.pl20{padding-left:20px}ol{clear:both;margin:0;padding:0 10px;height:200px;overflow:auto}.even{background: #ddd;}</style><div id="candy_debug"><div id="candy_trace_title" class="tab"><span id="candy_close" class="button" style="font-size: 15px;" title="关闭">X</span><span id="candy_min" class="button" title="最小化">—</span><span class="nav">Runtime</span><span class="nav">Framework</span><span class="nav">Request</span><span class="nav">Server</span><span class="nav">Include</span></div><div id="candy_trace_cont"><ol>';
            if(isset($debugState['includefile']['app'])){
                $trace .= '<div class="mt5">［自动加载］</div><div class="pl20">';
                foreach($debugState['includefile']['app'] as $file){
                    $trace .= '<span>&nbsp;&nbsp;&nbsp;&nbsp;'.$file.'</span>';
                }
                $trace .= '</div>';
            }
            if($debugState['application']){
                $trace .= '<div class="mt5">［项目信息］</div>';
                $debugGet = I('get');
                $trace .= '<div class="pl20">当前项目：'. ($debugGet['g'] ?? 'Home') .'、控制器：'. ($debugGet['m'] ?? 'Index') .'、操作：'. ($debugGet['a'] ?? 'index') .'.</div>';
                foreach($debugState['application'] as $info){
                    $trace .= '<div class="pl20">'.$info.'</div>';
                }
            }
            if($debugState['sqls']){
                $dbEngine = 'Candy\\Extend\\DB\\Driver\\' . \Candy\Extend\DB\DBServer::resolveDriverClass((string) G('DRIVER'));
                if($dbEngine::$execute > 0){
                    Debug::addMsg('[数据库查询总用时<font color=\'red\'>'. $dbEngine::$totalcate .'</font>ms] (共查询数据：'. $dbEngine::$execute .'次)',2);
                    $trace .= '<div class="mt5">［SQL语句］</div>';
                    foreach($debugState['sqls'] as $sql){
                        $trace .= '<div class="pl20">'.$sql.'</div>';
                    }
                }
            }
            $trace .= '</ol><ol style="display:none;">';
            if(isset($debugState['includefile']['framework'])){
                $trace .= '<div class="mt5">［自动加载］</div><div class="pl20">';
                foreach($debugState['includefile']['framework'] as $file){
                    $trace .= '<span>&nbsp;&nbsp;&nbsp;&nbsp;'.$file.'</span>';
                }
                $trace .= '</div>';
            }
            if($debugState['info']){
                $trace .= '<div class="mt5">［配置信息］</div>';
                foreach($debugState['info'] as $info){
                    $trace .= '<div class="pl20">'.$info.'</div>';
                }
            }
            if($debugState['paths']){
                $trace .= '<div class="mt5">［架构资源］</div>';
                foreach($debugState['paths'] as $path){
                    $trace .= '<div class="pl20">'.$path.'</div>';
                }
            }
            $trace .= '<div class="mt5">［其他信息］</div><div class="pl20">框架版本：'.CANDY_VERSION.' <a href="'.CANDY_URL.'" target="_blank" style="color:#888">查看新版</a></div>';
            $trace .= '</ol><ol style="display:none;">';
            $debugGet = I('get');
            unset($debugGet['g'], $debugGet['m'], $debugGet['a']);
            $debugPost = I('post');
            $debugSession = session();
            $debugCookie = cookie();
            $debugServer = I('server');
            $parsList = [];
            $parsList['GET'] = $debugGet;
            $parsList['POST'] = $debugPost;
            $parsList['SESSION'] = $debugSession;
            $parsList['COOKIE'] = $debugCookie;
            $parsList['SERVER'] = $debugServer;
            foreach($parsList as $key=>$val){
                if(isset($val) && count($val) > 0){
                    $trace .= '<div class="mt5">［'. $key .'］</div>';
                    $i = 1;
                    foreach($val as $k=>$v){
                        $trace .= '<div class="pl20'. ($i%2 == 0 ? ' even' : '') .'"><font color="blue">'. $k .'</font> = <font color="red">'. (is_array($v) ? ('[' . \Candy\Extend\Str::arrToStr($v, 0, '', '') . ']') : $v) .'</font></div>';
                        $i++;
                    }
                }
            }
            $trace .= '</ol><ol style="display:none;">';
            $spent = Debug::spent();
            $sessionid = sessionId();
            $trace .= '<div class="mt5">［基本信息］</div><div class="pl20">操作系统：'. php_uname('s') .'</div><div class="pl20">服务器时间：'. date('Y-m-d H:i:s', gmTime()) .'</div><div class="pl20">当前网协：' . \getLocalIP() . '/http' . (isSsl() ? 's' : '') .'</div><div class="pl20">运行时间：<font color="red">'. $spent .'ms</font></div><div class="pl20">使用内存：<font color="red">'. usageMemory() .'</font></div><div class="mt5">［引擎信息］</div>' . (!empty($debugServer['SERVER_SOFTWARE']) ? '<div class="pl20">引擎类型：'.$debugServer['SERVER_SOFTWARE'].'</div>' : '') . '<div class="pl20">PHP版本：'. phpversion() .'</div><div class="pl20">POST大小限制：'. ini_get('post_max_size') .'</div><div class="pl20">上传大小限制：'. ini_get('upload_max_filesize') .'</div><div class="pl20">最大执行时间：'. (ini_get('max_execution_time') ?: '无限制') .'</div><div class="pl20">最大内存占用：'. get_cfg_var('memory_limit') .'</div><div class="mt5">［其他信息］</div><div class="pl20">会话ID：'. $sessionid .'</div>';
            $trace .= '</ol><ol style="display:none;">';
            $i = 1;
            $sizeFile = 0;
            $files = array_merge(requireCache(),includeCache());
            foreach($files as $file=>$status){
                if(file_exists($file))
                    if($status && stripos($file, 'Vendor/Workerman/') === false && stripos($file, 'Class/Console/') === false){
                        $trace .= '<div class="mt5'. ($i%2 == 0 ? ' even' : '') .'">'. $i .'. '. str_replace(CANDYPATH, '', $file) .'</div>';
                        $sizeFile += filesize($file);
                        $i++;
                    }
            }
            $trace .= '<div class="mt5'. ($i%2 == 0 ? ' even' : '') .'">共计加载文件：'. sizeCount($sizeFile) .'</div>';
            $trace .= '</ol></div></div><div id="candy_open" title="查看详细">'. $spent .'ms</div><script type="text/javascript">(function(){var e=document.getElementById("candy_open");var j=document.getElementById("candy_close");var c=document.getElementById("candy_min");var a=document.getElementById("candy_debug");var k=document.getElementById("candy_trace_title").getElementsByTagName("span");var f=document.getElementById("candy_trace_cont").getElementsByTagName("ol");var b=document.cookie.match(/candy_trace_page_show=(\d\|\d)/);var g=(b&&typeof b[1]!="undefined"&&b[1].split("|"))||[0,2];var h=function(){document.cookie="candy_trace_page_show="+g.join("|")};for(var d=0;d<k.length;d++){if(d>1){k[d].onclick=(function(l){return function(){for(var i=0;i<f.length;i++){f[i].style.display="none";k[i+2].style.color="#999"}f[l-2].style.display="block";k[l].style.color="#000";g[1]=l;h()}})(d)}}e.onclick=function(){a.style.display="block";e.style.display="none";g[0]=1;h();k[parseInt(g[1])].click()};j.onclick=function(){a.style.display="none";e.style.display="none"};c.onclick=function(){a.style.display="none";e.style.display="block";g[0]=0;h()};e.style.display="block";if(parseInt(g[0])==1){e.click()}})();</script>';

            return $trace;
        } else {
            //非调试 api模式下
            $this->allowCache(true);
        }

        return '';
    }
}
