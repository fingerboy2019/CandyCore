<?php
/**
 * Http 请求处理类
 * 
 * 功能描述：提供基于PHP curl的全面HTTP请求功能，支持多种HTTP方法、文件上传下载、WebDAV操作和流式请求处理。
 * 
 * 主要功能：
 * - 支持GET、POST、PUT、DELETE等多种HTTP方法的请求
 * - 文件下载与上传功能
 * - WebDAV协议支持（文件列表获取等）
 * - FormData表单数据提交，支持文件上传
 * - 流式请求处理，适用于大型数据传输
 * - 自定义请求头、Cookie、超时等高级选项
 * - 代理支持与SSL证书配置
 * - 自动User-Agent模拟，支持爬虫和普通浏览器模式
 * 
 * @package Candy\Class\Network
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-09-18 04:10:03 $
 * @final 此类不可被继承
 */

declare(strict_types=1);
namespace Candy\Extend\Network;
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Http {
    use Trait\HttpCurlOptions;
	/**
     * 发送HTTP GET请求
     * 
     * 用于向指定URL发送GET请求，支持查询参数传递和自定义请求选项。内部调用request方法处理实际请求逻辑。
     * GET请求适合获取数据，请求参数会附加到URL后面。
     * 
     * @param string $location 请求的URL地址
     * @param mixed $query GET查询参数，可以是数组或已编码的查询字符串
     * @param array $options 请求选项，支持headers、cookie、timeout等自定义配置
     * @return array 请求结果数组，包含http_code、content、header等响应信息
     */
    public static function get(string $location,mixed $query = null,array $options = []): array
    {
        $options['query'] = $query;
        return self::request('GET', $location, $options);
    }

    /**
     * 发送HTTP POST请求
     * 
     * 用于向指定URL发送POST请求，支持各种数据格式的提交和自定义请求选项。内部调用request方法处理实际请求逻辑。
     * POST请求适合提交表单数据、上传文件或发送需要隐藏的数据，请求参数会放在请求体中。
     * 
     * @param string $location 请求的URL地址
     * @param mixed $data POST请求数据，可以是关联数组或已编码的请求体字符串
     * @param array $options 请求选项，支持headers、cookie、timeout等自定义配置
     * @return array 请求结果数组，包含http_code、content、header等响应信息
     */
    public static function post(string $location, $data = '',array $options = []): array
    {
        $options['data'] = $data;
        return self::request('POST', $location, $options);
    }

    /**
     * 以原始字节回调方式执行流式 HTTP 请求。
     *
     * 回调收到的是 curl 当前收到的原始字节，网络层不会输出、改写或截断数据；
     * SSE、NDJSON 等上层协议必须自行负责分片拼接和终态校验。回调返回 false 或
     * 抛出异常时请求立即停止并返回失败元数据。
     *
     * @param string $method HTTP 方法
     * @param string $location 请求地址
     * @param array<string,mixed> $options 请求选项
     * @param callable|null $callback 原始字节回调
     * @return array{http_code:int,error:string,callback_stopped:bool,header:array,content:string} 请求元数据
     */
    public static function stream(
        string $method,
        string $location,
        array $options = [],
        ?callable $callback = null
    ): array {
        if (!function_exists('curl_init')) {
            return [
                'http_code' => 0,
                'error' => 'curl_not_available',
                'callback_stopped' => false,
                'header' => [],
                'content' => '',
            ];
        }
        if (!self::isHttpUrl($location)) {
            return [
                'http_code' => 0,
                'error' => 'invalid_url',
                'callback_stopped' => false,
                'header' => [],
                'content' => '',
            ];
        }

        $ch = curl_init();
        if ($ch === false) {
            return [
                'http_code' => 0,
                'error' => 'curl_init_failed',
                'callback_stopped' => false,
                'header' => [],
                'content' => '',
            ];
        }

        if (empty($options['query']) === false) {
            $location .= (stripos($location, '?') !== false ? '&' : '?')
                . http_build_query($options['query']);
        }
        curl_setopt($ch, CURLOPT_URL, $location);
        self::applyCurlOptions($ch, $method, $location, $options);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

        $callbackError = '';
        $callbackStopped = false;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $data) use (
            $callback,
            &$callbackError,
            &$callbackStopped
        ): int {
            unset($ch);
            if ($callback === null) {
                return strlen($data);
            }
            try {
                if ($callback($data) === false) {
                    $callbackStopped = true;

                    return 0;
                }
            } catch (\Throwable $exception) {
                $callbackError = $exception->getMessage();

                return 0;
            }

            return strlen($data);
        });

        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        $curlError = curl_error($ch);

        $error = $callbackError !== ''
            ? 'stream_callback_failed: ' . $callbackError
            : ($callbackStopped ? 'stream_callback_stopped' : $curlError);
        if ($result === false && $error === '') {
            $error = 'curl_stream_failed';
        }

        return [
            'http_code' => (int)($info['http_code'] ?? 0),
            'error' => $error,
            'callback_stopped' => $callbackStopped,
            'header' => [],
            'content' => '',
        ];
    }
	
    /**
     * 发送HTTP DELETE请求
     * 
     * 用于向指定URL发送DELETE请求，通常用于删除服务器上的资源。内部调用request方法处理实际请求逻辑。
     * DELETE请求返回布尔值，表示请求是否成功（HTTP状态码为204时认为成功）。
     * 
     * @param string $location 请求的URL地址
     * @param mixed $data DELETE请求数据，可选的数据负载，某些API可能需要通过请求体传递删除参数
     * @param array $options 请求选项，支持headers、cookie、timeout等自定义配置
     * @return bool 请求成功返回true，失败返回false
     */
    public static function delete(string $location, $data = '',array $options = []): bool
    {
        $options['data'] = $data;
		$res = self::request('DELETE', $location, $options);
		if($res['http_code'] === 204) return true;
		return false;
    }
	
    /**
     * 发送HTTP PUT请求
     * 
     * 用于向指定URL发送PUT请求，通常用于更新服务器上的资源或上传文件。内部调用request方法处理实际请求逻辑。
     * PUT请求适合完全替换资源内容或执行上传操作。
     * 
     * @param string $location 请求的URL地址
     * @param mixed $data PUT请求数据，可以是关联数组或已编码的请求体字符串
     * @param array $options 请求选项，支持headers、cookie、timeout等自定义配置
     * @return array 请求结果数组，包含http_code、content、header等响应信息
     */
    public static function put(string $location, $data = '',array $options = []): array
    {
        $options['data'] = $data;
        return self::request('PUT', $location, $options);
    }
	
    /**
     * 下载远程文件到本地
     * 
     * 用于从指定URL下载文件并保存到本地路径，支持自定义下载参数和进度回调。内部通过设置curl的FILE选项实现文件写入。
     * 如果不指定保存路径，则会根据URL自动确定文件名并保存到当前目录。
     * 
     * @param string $location 远程文件下载URL地址
     * @param string $savePath 本地保存完整路径
     * @param array $options 下载选项，支持timeout、progress_callback等自定义配置
     * @return string|bool 下载成功返回保存路径，失败返回false
     */
    public static function down(string $location,string $savePath,array $options = [])
    {
		isset($options['timeout']) OR $options['timeout'] = 3600;
		$response = self::request('GET', $location, $options);
		if ($response['http_code'] == 200) {
			if (is_dir(dirname($savePath)) === false) {
				\Candy\Extend\Dir::create(dirname($savePath));
			}
            \file_put_contents($savePath, $response['content']);
			return $savePath;
        }
        return false;
    }
	
    /**
     * 上传文件到远程服务器
     * 
     * 用于将本地文件上传到远程服务器，通过PUT请求发送文件内容。该方法会读取本地文件内容并发送到指定URL。
     * 内部设置了合理的超时时间（默认3600秒）以适应大文件上传场景。
     * 
     * @param string $location 远程上传目标URL地址
     * @param string $localPath 本地文件路径
     * @param array $options CURL请求参数，支持timeout、headers等自定义配置
     * @return bool 上传成功返回true，失败返回false
     */
    public static function upload(string $location,string $localPath,array $options = [])
    {
		$data = \file_get_contents($localPath);
		if($data){
			$options['data'] = $data;
			isset($options['timeout']) OR $options['timeout'] = 3600;
			$res = self::request('PUT', $location, $options);
			if($res['http_code'] === 201) return true;
		}
		
		return false;
    }
	
    /**
     * 获取WebDAV文件列表
     * 
     * 用于获取WebDAV服务器上指定路径的文件和目录列表。该方法发送PROPFIND请求到WebDAV服务器，
     * 解析XML格式的响应，提取文件和目录信息，并返回结构化数组。
     * 
     * @param string $location 请求的WebDAV文件路径
     * @param array $options CURL请求参数，需包含webdav配置(host,user,password)和可选的namespaces参数
     * @return array|bool 返回包含文件和目录信息的数组，失败时返回false
     */
    public static function list(string $location, array $options = [])
    {
		if (empty($options['webdav']['host'])) {
			return false;
		}
		$host = rtrim((string) $options['webdav']['host'], '/');
		$url = $host . '/' . ltrim($location, '/');
		$headers = $options['headers'] ?? [];
		$headers[] = 'Depth: ' . (string) (int) ($options['depth'] ?? 1);
		$options['headers'] = $headers;
		$response = self::request('PROPFIND', $url, $options);
		if ((int) ($response['http_code'] ?? 0) !== 207) {
			return false;
		}
		$files = self::parseWebdavList((string) ($response['content'] ?? ''), $host);
		return $files === [] ? false : $files;
    }

	/**
	 * 解析 WebDAV PROPFIND 多状态 XML 为目录列表
	 *
	 * @param string $xml 响应体
	 * @param string $host 站点根 URL（无尾斜杠）
	 * @return array<int,array{name:string,href:string,is_dir:bool}>
	 */
	public static function parseWebdavList(string $xml, string $host): array
	{
		if ($xml === '') {
			return [];
		}
		$doc = @simplexml_load_string($xml);
		if ($doc === false) {
			return [];
		}
		$namespaces = $doc->getNamespaces(true);
		$davNs = $namespaces['d'] ?? $namespaces['D'] ?? ($namespaces[''] ?? 'DAV:');
		$files = [];
		foreach ($doc->children($davNs) as $node) {
			if ($node->getName() !== 'response') {
				continue;
			}
			$href = trim((string) ($node->href ?? $node->children($davNs)->href ?? ''));
			if ($href === '') {
				continue;
			}
			$isDir = false;
			foreach ($node->propstat ?? [] as $propstat) {
				$prop = $propstat->prop ?? $propstat->children($davNs)->prop ?? null;
				if ($prop === null) {
					continue;
				}
				$rt = $prop->resourcetype ?? $prop->children($davNs)->resourcetype ?? null;
				if ($rt === null) {
					continue;
				}
				foreach ($rt->children($davNs) as $child) {
					if ($child->getName() === 'collection') {
						$isDir = true;
						break 2;
					}
				}
			}
			$files[] = [
				'name' => $href,
				'href' => $host . $href,
				'is_dir' => $isDir,
			];
		}
		return $files;
    }
	
    /**
     * 提交表单数据
     * 
     * 用于模拟HTML表单提交，支持常规表单数据和文件上传。内部自动处理表单数据的编码和Content-Type设置。
     * 适合需要以表单方式提交数据的场景，如模拟用户登录、表单提交等操作。
     * 
     * @param string $url 表单提交目标URL地址
     * @param array $data 表单数据数组，包含常规字段
     * @param array $file 提交文件信息，格式为[field,name,content]
     * @param array $header 请求头部信息，默认自动设置Content-type
     * @param string $method 模拟请求的方式，支持GET、POST、PUT
     * @return array 请求结果数组，包含http_code、content、header等响应信息
     */
    public static function submit(string $url,array $data = [],array $file = [],array $header = [],string $method = 'POST'): array
    {
        [$boundary, $content] = self::buildFormData($data, $file);
        $header[] = empty($file) ? 'Content-type:application/x-www-form-urlencoded' : "Content-type:multipart/form-data;boundary={$boundary}";
        return self::request($method, $url, ['data' => $content, 'headers' => $header]);
    }

    
    /**
     * 带重试的 HTTP 请求（Laravel Http::retry 等价）
     *
     * 不改变原 request() 语义，只是包装一层循环。
     * 默认按「传输失败或 5xx」重试；可用 $when 自定义判定。
     *
     * ```php
     * // 最多 3 次，首次退避 100ms，之后指数翻倍（100/200/400）
     * $res = Http::retry('GET', 'https://api.example.com/x');
     *
     * // 只要 429 也重试
     * $res = Http::retry('POST', $url, ['data' => $data], 5, 200, function (array $r) {
     *     return ($r['http_code'] ?? 0) === 429;
     * });
     * ```
     *
     * @param string        $method   请求方法
     * @param string        $location URL
     * @param array         $options  同 request()
     * @param int           $times    最多尝试次数（含首次），>=1
     * @param int           $delayMs  首次退避毫秒，之后指数翻倍
     * @param callable|null $when     自定义重试判定 (array $result, int $attempt): bool
     * @return mixed 同 request()
     */
    public static function retry(
        string $method,
        string $location,
        array $options = [],
        int $times = 3,
        int $delayMs = 100,
        ?callable $when = null
    ): mixed {
        $times   = max(1, $times);
        $delayMs = max(0, $delayMs);

        // 默认判定：传输失败（http_code=0 或有 error）或 5xx 才重试
        $shouldRetry = $when ?? static function (mixed $result): bool {
            if (!is_array($result)) {
                return true;
            }
            $code = (int) ($result['http_code'] ?? 0);

            return $code === 0 || $code >= 500;
        };

        $result   = null;
        $delay    = $delayMs;

        for ($attempt = 1; $attempt <= $times; $attempt++) {
            $result = self::request($method, $location, $options);

            if ($attempt >= $times) {
                break;
            }

            try {
                $retry = (bool) $shouldRetry($result, $attempt);
            } catch (\Throwable) {
                $retry = false;
            }

            if (!$retry) {
                break;
            }

            if ($delay > 0) {
                usleep($delay * 1000);
                $delay *= 2;   // 指数退避
            }
        }

        return $result;
    }

/**
     * 底层HTTP请求实现方法
     * 
     * 所有HTTP请求的核心实现方法，处理各种HTTP方法（GET、POST、PUT、DELETE等）的底层逻辑。
     * 负责初始化curl会话、设置请求选项、执行请求并处理响应结果。支持各种高级功能如cookie管理、
     * 代理设置、SSL验证、超时控制等。
     * 
     * @param string $method HTTP请求方法，支持GET、POST、PUT、DELETE等标准方法
     * @param string $location 请求的URL地址
     * @param array $options 高级请求选项，支持以下键值：
     *        - timeout: 传输超时（秒），默认 15
     *        - connect_timeout: 连接超时（秒），未指定时与 timeout 相同
     *        - headers: 请求头数组
     *        - cookie: Cookie信息
     *        - cookie_file: Cookie文件路径
     *        - query: GET查询参数
     *        - data: 请求数据
     *        - referer: HTTP引用来源
     *        - proxy: 代理服务器设置
     *        - proxypass: 代理认证信息
     *        - webdav: WebDAV认证信息
     *        - ssl: 客户端证书数组 {key,cert}（禁止关闭服务端校验）
     *        - ssl_verify: 兼容字段，始终强制校验服务端证书
     *        - http_version: HTTP协议版本（CURL_HTTP_VERSION_1_1、CURL_HTTP_VERSION_2_0等）
     *        - max_redirs: 最大重定向次数，默认为10
     *        - follow_location: 兼容字段，始终禁止自动跟随重定向
     *        - encoding: 内容编码（空字符串表示支持所有编码，如'gzip'、'identity'等）
     *        - port: 指定连接端口
     *        - low_speed_limit: 最低传输速度（字节/秒）
     *        - low_speed_time: 低速持续时间（秒），低于最低速度持续此时间则取消请求
     *        - range: 请求范围，用于断点续传（如'0-999'表示下载前1000字节）
     *        - http_auth: HTTP认证方式（CURLAUTH_BASIC、CURLAUTH_DIGEST等）
     *        - dns_cache_timeout: DNS缓存超时时间（秒）
     *        - forbid_reuse: 禁止连接复用（true/false）
     *        - fresh_connect: 强制建立新连接（true/false）
     *        - ca_info: CA证书文件路径
     *        - stderr: 错误输出文件路径
     *        - fail_on_error: HTTP错误时失败（true/false）
     *        - ssl_version: SSL版本（CURL_SSLVERSION_TLSv1_2、CURL_SSLVERSION_TLSv1_3等）
     *        - safe_upload: 安全上传模式（true/false）
     *        - ip_resolve: IP解析方式（CURL_IPRESOLVE_V4、CURL_IPRESOLVE_V6、CURL_IPRESOLVE_WHATEVER）
     *        - upload: 启用上传模式（true/false）
     *        - infile_size: 上传文件大小（字节）
     *        - file_time: 获取文件修改时间（true/false）
     *        - no_progress: 禁用进度条（true/false）
     *        - progress_function: 进度回调函数
     *        - proxy_tunnel: HTTP代理隧道（true/false）
     *        - interface: 网络接口（如'eth0'、'192.168.1.2'等）
     *        - max_connects: 最大连接数
     *        - tcp_nodelay: 禁用Nagle算法（true/false）
     *        - buffer_size: 缓冲区大小（字节）
     *        - resume_from: 断点续传起始位置（字节）
     *        - time_condition: 时间条件（CURL_TIMECOND_IFMODSINCE、CURL_TIMECOND_IFUNMODSINCE、CURL_TIMECOND_LASTMOD）
     *        - time_value: 时间值（Unix时间戳）
     *        - ca_path: CA证书目录路径
     * @param callable|null $callback 流式输出回调函数，为null时使用普通请求模式
     * @return mixed 请求结果数组（普通模式）或布尔值（流式模式）。
     *         普通模式返回数组包含以下键值：
     *         - http_code: HTTP状态码
     *         - content: 响应内容
     *         - header: 响应头信息数组
     *         - header_size: 响应头大小
     *         - size_download: 下载数据大小
     *         - time_total: 请求总耗时
     *         流式模式下返回布尔值表示请求是否成功
     */
    public static function request(string $method, string $location, array $options = [], ?callable $callback = null): mixed
    {
        if (!function_exists('curl_init')) {
            Debug::showMessage('curl_init没有配置，无法使用CURL！');
            return false;
        }

        if (!self::isHttpUrl($location)) {
            return [
                'http_code' => 0,
                'header' => [],
                'content' => '',
                'error' => 'invalid_url',
            ];
        }

        $ch = curl_init();
        if ($ch === false) {
            return [
                'http_code' => 0,
                'header' => [],
                'content' => '',
                'error' => 'curl_init_failed',
            ];
        }

        // GET 参数设置
        if (empty($options['query']) === false) {
            $location .= (stripos($location, '?') !== false ? '&' : '?') . http_build_query($options['query']);
        }

        // 基本CURL配置
        curl_setopt($ch, CURLOPT_URL, $location);
        self::applyCurlOptions($ch, $method, $location, $options);

        // 区分普通请求和流式请求
        if ($callback !== null) {
            // 流式请求模式
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use ($callback) {
                $chunk = $callback($data);
                echo $chunk;
                ob_flush();
                flush();
                return strlen($chunk);
            });

            $result = curl_exec($ch);
            $success = $result !== false;
            if (!$success) {
                Debug::showMessage(curl_error($ch));
            }
            return $success;
        } else {
            // 普通请求模式
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $content = curl_exec($ch);

            // 传输失败（连接拒绝/超时/DNS 失败）：立即返回错误结构，
            // 不能继续走 substr($content) —— false 会抛 TypeError
            if ($content === false) {
                $error = curl_error($ch);
                $info  = curl_getinfo($ch);
                if ($error !== '') {
                    Debug::showMessage($error);
                }

                return [
                    'http_code' => (int) ($info['http_code'] ?? 0),
                    'header'    => [],
                    'content'   => '',
                    'error'     => $error !== '' ? $error : 'curl_exec_failed',
                ];
            }

            // 返回的数据
            $data = curl_getinfo($ch);

            // 检查代理情况
            if (empty($options['proxy']) === false && $data['http_code'] == '0') {
                Debug::showMessage('代理访问失败');
            }

            // 处理header
            $header = substr($content, 0, $data['header_size']);
            $content = str_replace($header, '', $content);
            $data['header'] = explode("\r\n", $header);
            $data['content'] = $content;

            return $data;
        }
    }

    /**
     * 将数组或字符串构建为查询字符串
     * 
     * 内部辅助方法，用于将关联数组或已有的查询字符串处理成标准的URL查询格式。
     * 处理POST数据时会检查并正确处理文件上传内容，支持CURLFile对象。
     * 如果设置了不编译数据($build=false)且包含文件，则返回数组形式的原始数据。
     * 
     * @param mixed $data 要处理的数据，可以是关联数组或已编码的查询字符串
     * @param bool $build 是否将数据编译为字符串，默认为true
     * @return mixed 如果build为true且数据为数组则返回字符串，否则返回原始数据
     */
    private static function buildQueryData(mixed $data,bool $build = true): mixed
    {
        if (!is_array($data)) return $data;
        foreach ($data as $key => $value) {
            if (is_string($value) && stripos($value, '@') === 0 && class_exists('CURLFile')) {
                if (file_exists($filename = realpath(ltrim($value, '@')))) {
                    [$build, $data[$key]] = [false, new \CURLFile($filename)];
                }
            } elseif ($value instanceof \CURLFile) $build = false;
        }
        return $build ? http_build_query($data) : $data;
    }
    
    /**
     * 构建multipart/form-data表单数据
     * 
     * 内部辅助方法，用于构建适合文件上传的multipart/form-data格式的请求体。
     * 支持添加常规表单字段和文件字段，自动生成正确的boundary分隔符。
     * 
     * @param array $data 常规表单字段数组
     * @param array $file 文件上传字段数组，格式为[field,name,content]
     * @return array 返回包含header和content的数组，用于设置请求头和请求体
     */
    private static function buildFormData(array $data = [], $file = []): array
    {
        [$line, $boundary] = [[], \Candy\Extend\Str::random(18)];
		$postdata = '';
		if(empty($file)) {
            if(isset($data['data'])){
                $post = $data['data'];
                if (is_array($post)) {
                    $postdata = http_build_query($post);
                }
            }
		}else{
			foreach ($data as $key => $value) {
				$line[] = "--{$boundary}";
				$line[] = "Content-Disposition: form-data; name=\"{$key}\"";
				$line[] = "";
				$line[] = $value;
			}
			if (is_array($file) && isset($file['field']) && isset($file['name'])) {
				$line[] = "--{$boundary}";
				$line[] = "Content-Disposition: form-data; name=\"{$file['field']}\"; filename=\"{$file['name']}\"";
				$line[] = "";
				$line[] = $file['content'];
			}
			$line[] = "--{$boundary}--";
			$postdata = join("\r\n", $line);
		}
        return [$boundary, $postdata];
    }
    
    /**
     * 获取随机的User-Agent字符串
     * 
     * 内部辅助方法，用于随机返回一个常见的浏览器User-Agent字符串。
     * 这有助于模拟不同浏览器环境的请求，避免被服务器识别为机器人。
     * 
     * @param string $method HTTP请求方法
     * @param string $location 请求地址
     * @return string 随机生成的浏览器User-Agent字符串
     */
	private static function getUserAgent($method, $location): string
	{
		$server = I('server.', [], '');
		if (!empty($server['HTTP_USER_AGENT'])) {
			return (string)$server['HTTP_USER_AGENT'];
        }else{
			//代理列表
			$agents = [
				'spider'=>[
					'pc'=>[
						'baidu'=>'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
						'google'=>'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
						'bing'=>'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'
					],
					'mobile'=>[
						'baidu'=>'Mozilla/5.0 (Linux;u;Android 4.2.2;zh-cn;) AppleWebKit/534.46 (KHTML,like Gecko) Version/5.1 Mobile Safari/10600.6.3 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
						'google'=>'Mozilla/5.0 (iPhone; CPU iPhone OS 8_3 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) Version/8.0 Mobile/12F70 Safari/600.1.4 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
						'bing'=>'Mozilla/5.0 (iPhone; CPU iPhone OS 7_0 like Mac OS X) AppleWebKit/537.51.1 (KHTML, like Gecko) Version/7.0 Mobile/11A465 Safari/9537.53 (compatible; bingbot/2.0; http://www.bing.com/bingbot.htm)'
					]
				],
				'normal'=>[
					'pc'=>[
						'safari5.1–MAC' => 'Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10_6_8; en-us) AppleWebKit/534.50 (KHTML, like Gecko) Version/5.1 Safari/534.50',
                        'safari5.1–Windows' => 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-us) AppleWebKit/534.50 (KHTML, like Gecko) Version/5.1 Safari/534.50',
                        'IE9.0' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0;',
                        'IE8.0' => 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0; Trident/4.0)',
                        'IE7.0' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.0)',
                        'IE6.0' => ' Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
                        'Firefox4.0.1–MAC' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.6; rv,2.0.1) Gecko/20100101 Firefox/4.0.1',
                        'Firefox4.0.1–Windows' => 'Mozilla/5.0 (Windows NT 6.1; rv,2.0.1) Gecko/20100101 Firefox/4.0.1',
                        'Opera11.11–MAC' => 'Opera/9.80 (Macintosh; Intel Mac OS X 10.6.8; U; en) Presto/2.8.131 Version/11.11',
                        'Opera11.11–Windows' => 'Opera/9.80 (Windows NT 6.1; U; en) Presto/2.8.131 Version/11.11',
                        'Chrome17.0–MAC' => ' Mozilla/5.0 (Macintosh; Intel Mac OS X 10_7_0) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.56 Safari/535.11',
                        'Chrome17.0–Windows' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36',
                        '傲游' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Maxthon 2.0)',
                        '腾讯TT' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; TencentTraveler 4.0)',
                        '世界之窗2.x' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1)',
                        '世界之窗3.x' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; The World)',
                        '搜狗浏览器1.x' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4.0; SE 2.X MetaSr 1.0; SE 2.X MetaSr 1.0; .NET CLR 2.0.50727; SE 2.X MetaSr 1.0)',
                        '360浏览器' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; 360SE)',
                        'Avant' => 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Avant Browser)',
                        'GreenBrowser' => ' Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1)',
					],
					'mobile'=>[
						'safariiOS4.33–iPhone' => 'Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5',
                        'safariiOS4.33–iPodTouch' => 'Mozilla/5.0 (iPod; U; CPU iPhone OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5',
                        'safariiOS4.33–iPad' => 'Mozilla/5.0 (iPad; U; CPU OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5',
                        'Android-N1' => 'Mozilla/5.0 (Linux; U; Android 2.3.7; en-us; Nexus One Build/FRF91) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1',
                        'QQ浏览器' => 'MQQBrowser/26 Mozilla/5.0 (Linux; U; Android 2.3.7; zh-cn; MB200 Build/GRJ22; CyanogenMod-7) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1',
                        'Opera' => 'Opera/9.80 (Android 2.3.4; Linux; Opera Mobi/build-1107180945; U; en-GB) Presto/2.8.149 Version/11.10',
                        'MotoXoom' => 'Mozilla/5.0 (Linux; U; Android 3.0; en-us; Xoom Build/HRI39) AppleWebKit/534.13 (KHTML, like Gecko) Version/4.0 Safari/534.13',
                        'BlackBerry' => 'Mozilla/5.0 (BlackBerry; U; BlackBerry 9800; en) AppleWebKit/534.1+ (KHTML, like Gecko) Version/6.0.0.337 Mobile Safari/534.1+',
                        'HPTouchpad' => 'Mozilla/5.0 (hp-tablet; Linux; hpwOS/3.0.0; U; en-US) AppleWebKit/534.6 (KHTML, like Gecko) wOSBrowser/233.70 Safari/534.6 TouchPad/1.0',
                        'NokiaN97' => 'Mozilla/5.0 (SymbianOS/9.4; Series60/5.0 NokiaN97-1/20.0.019; Profile/MIDP-2.1 Configuration/CLDC-1.1) AppleWebKit/525 (KHTML, like Gecko) BrowserNG/7.1.18124',
                        'Mango' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows Phone OS 7.5; Trident/5.0; IEMobile/9.0; HTC; Titan)',
                        'UC无' => 'UCWEB7.0.2.37/28/999',
                        'UC标准' => 'NOKIA5700/ UCWEB7.0.2.37/28/999',
                        'UCOpenwave' => 'Openwave/ UCWEB7.0.2.37/28/999',
                        'UCOpera' => 'Mozilla/4.0 (compatible; MSIE 6.0; ) Opera/UCWEB7.0.2.37/28/999',
                        '小米' => 'Mozilla/5.0 (Linux; Android 7.1.1; MI 6 Build/NMF26X; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/57.0.2987.132 MQQBrowser/6.2 TBS/043807 Mobile Safari/537.36 MicroMessenger/6.6.1.1220(0x26060135) NetType/WIFI Language/zh_CN',
                        '锤子OD103型号' => 'Mozilla/5.0 (Linux; Android 7.1.1; OD103 Build/NMF26F; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/53.0.2785.49 Mobile MQQBrowser/6.2 TBS/043632 Safari/537.36 MicroMessenger/6.6.1.1220(0x26060135) NetType/4G Language/zh_CN',
                        '锤子SM919型号' => 'Mozilla/5.0 (Linux; Android 6.0.1; SM919 Build/MXB48T; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/53.0.2785.49 Mobile MQQBrowser/6.2 TBS/043632 Safari/537.36 MicroMessenger/6.6.1.1220(0x26060135) NetType/WIFI Language/zh_CN',
                        'VIVOX6S' => 'Mozilla/5.0 (Linux; Android 5.1.1; vivo X6S A Build/LMY47V; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/53.0.2785.49 Mobile MQQBrowser/6.2 TBS/043632 Safari/537.36 MicroMessenger/6.6.1.1220(0x26060135) NetType/WIFI Language/zh_CN',
                        'HUAWEITAG-AL00' => 'Mozilla/5.0 (Linux; Android 5.1; HUAWEI TAG-AL00 Build/HUAWEITAG-AL00; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/53.0.2785.49 Mobile MQQBrowser/6.2 TBS/043622 Safari/537.36 MicroMessenger/6.6.1.1220(0x26060135) NetType/4G Language/zh_CN',
                        '小程序' => 'Mozilla/5.0 (Linux; Android 7.1.1; MI 6 Build/NMF26X; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/57.0.2987.132 MQQBrowser/6.2 TBS/043807 Mobile Safari/537.36 MicroMessenger/6.6.1.1220(0x26060135) NetType/4G Language/zh_CN MicroMessenger/6.6.1.1220(0x26060135) NetType/4G Language/zh_CN miniProgram',
                        'iphoneOS9' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 9_3_2 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Mobile/13F69 MicroMessenger/6.6.1 NetType/4G Language/zh_CN',
                        'iphoneOS112' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 11_2_2 like Mac OS X) AppleWebKit/604.4.7 (KHTML, like Gecko) Mobile/15C202 MicroMessenger/6.6.1 NetType/4G Language/zh_CN',
                        'iphoneOS111' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 11_1_1 like Mac OS X) AppleWebKit/604.3.5 (KHTML, like Gecko) Mobile/15B150 MicroMessenger/6.6.1 NetType/WIFI Language/zh_CN',
                        'iphoneX' => 'Mozilla/5.0 (iphone x Build/MXB48T; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/53.0.2785.49 Mobile MQQBrowser/6.2 TBS/043632 Safari/537.36 MicroMessenger/6.6.1.1220(0x26060135) NetType/WIFI Language/zh_CN',
                    ]
				]
			];
			
			$type = 'normal';
			$ment = 'pc';
			
			//GET模式中不是三大蜘蛛站用蜘蛛代理
			if($method == 'GET' && stripos($location, 'baidu') === false && stripos($location, 'google') === false && stripos($location, 'bing') === false){
                $type = 'spider';
            }
            
			if(isMobile()){
                $ment = 'mobile';
            }
            
			return $agents[$type][$ment][array_rand($agents[$type][$ment])];
		}
    }
	
	/**
     * 生成随机的IP地址字符串
     * 
     * 内部辅助方法，用于生成一个随机的IPv4地址字符串。
     * 这在需要模拟不同IP来源请求的场景下很有用，可以帮助绕过一些基于IP的限制。
     * 
     * @return string 随机生成的IPv4地址字符串
     */
    private static function getRandip()
	{
		$arr_1 = array("218", "218", "66", "66", "218", "218", "60", "60", "202", "204", "66", "66", "66", "59", "61", "60", "222", "221", "66", "59", "60", "60", "66", "218", "218", "62", "63", "64", "66", "66", "122", "211");
		$randarr = mt_rand(0, count($arr_1) - 1);
		$ip1id = $arr_1["{$randarr}"];
		$ip2id = round(rand(600000, 2550000) / 10000);
		$ip3id = round(rand(600000, 2550000) / 10000);
		$ip4id = round(rand(600000, 2550000) / 10000);
		return $ip1id . "." . $ip2id . "." . $ip3id . "." . $ip4id;
	}

	/**
     * 是否可用 Workerman 异步 HTTP 客户端
     *
     * 仅判断类是否已加载；实际发起前仍需 Workerman 事件循环
     *
     * @return bool
     */
    public static function hasAsyncHttpClient(): bool
    {
        return class_exists(\Workerman\Http\Client::class);
    }

	/**
     * 阻塞并发批量请求（curl_multi）
     *
     * FPM/CLI 下同时发起多路 HTTP，当前进程会阻塞直到全部完成
     * 非事件循环异步，勿与 async() 混淆
     *
     * @param array $requests 请求数据数组，每个元素包含 url、method、options
     * @param callable|null $callback 全部完成后的回调
     * @return array 请求结果数组
     */
    public static function parallel(array $requests, ?callable $callback = null): array
    {
        if (!function_exists('curl_init')) {
            Debug::showMessage('curl_init 未启用，无法使用 Http::parallel()');
            return [];
        }

        $normalized = self::normalizeBatchRequests($requests);
        if ($normalized === []) {
            if ($callback) {
                $callback([]);
            }
            return [];
        }

        $mh = curl_multi_init();
        $handles = [];
        $requestInfo = [];
        $paramIndex = 0;
        $paramName = '_mpi_' . rand(10, 1000000);

        foreach ($normalized as $item) {
            $urlWithId = $item['url'] . (stripos($item['url'], '?') !== false ? '&' : '?')
                . $paramName . '=' . $paramIndex;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlWithId);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            self::applyCurlOptions($ch, $item['method'], $item['url'], $item['options']);

            curl_multi_add_handle($mh, $ch);
            $handles[$paramIndex] = $ch;
            $requestInfo[$paramIndex] = $item;
            $paramIndex++;
        }

        $running = null;
        do {
            $execrun = curl_multi_exec($mh, $running);
        } while ($execrun == CURLM_CALL_MULTI_PERFORM);

        while ($running > 0 && $execrun == CURLM_OK) {
            if (curl_multi_select($mh, 1) == -1) {
                usleep(1000);
            }
            do {
                $execrun = curl_multi_exec($mh, $running);
            } while ($execrun == CURLM_CALL_MULTI_PERFORM);
        }

        $results = [];
        foreach ($handles as $index => $ch) {
            $content = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);

            $header = substr($content, 0, $info['header_size']);
            $body = str_replace($header, '', $content);
            $info['header'] = explode("\r\n", $header);
            $info['content'] = $body;
            $info['error'] = $error;

            if (isset($requestInfo[$index])) {
                $item = $requestInfo[$index];
                $results[$item['key']] = self::packBatchResult(
                    $item['url'],
                    $item['method'],
                    $info,
                    $item['options']
                );
            }

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        if ($callback) {
            $callback($results);
        }

        return $results;
    }

	/**
     * 事件循环内异步批量请求（Workerman\Http\Client）
     *
     * 需 class_exists(Workerman\Http\Client)；FPM 阻塞并发请用 parallel()
     *
     * @param array $requests 请求数据数组
     * @param callable|null $callback 有回调时立即返回，结果在回调中交付
     * @return array 无回调时在协程内等待并返回结果；有回调时返回空数组
     */
    public static function async(array $requests, ?callable $callback = null): array
    {
        if (!self::hasAsyncHttpClient()) {
            Debug::showMessage(
                '未加载 Workerman\\Http\\Client，无法使用 Http::async()；'
                . 'FPM/CLI 阻塞并发请使用 Http::parallel()'
            );
            return [];
        }

        try {
            return self::dispatchAsyncWorkerman($requests, $callback);
        } catch (\Throwable $e) {
            Debug::showMessage(
                'Http::async() 需在 Workerman 事件循环中运行（' . $e->getMessage() . '）；'
                . 'FPM/CLI 阻塞并发请使用 Http::parallel()'
            );
            return [];
        }
    }

	/**
     * 规范化批量请求项
     *
     * @param array $requests 原始请求列表
     * @return array<int,array{key:int|string,url:string,method:string,options:array}>
     */
    private static function normalizeBatchRequests(array $requests): array
    {
        $normalized = [];
        foreach ($requests as $key => $request) {
            $url = $request['url'] ?? '';
            $method = $request['method'] ?? 'GET';
            $options = $request['options'] ?? [];
            if (!self::isHttpUrl((string)$url)) {
                continue;
            }
            if (!empty($options['query'])) {
                $url .= (stripos($url, '?') !== false ? '&' : '?') . http_build_query($options['query']);
            }
            $normalized[] = [
                'key' => $key,
                'url' => $url,
                'method' => $method,
                'options' => $options,
            ];
        }
        return $normalized;
    }

	/**
     * 组装单条批量结果
     *
     * @param string $url 请求 URL
     * @param string $method HTTP 方法
     * @param array $response 响应信息（curl_getinfo 或归一化结构）
     * @param array $options 原始选项
     * @return array{url:string,method:string,response:array,options:array}
     */
    private static function packBatchResult(string $url, string $method, array $response, array $options): array
    {
        return [
            'url' => $url,
            'method' => $method,
            'response' => $response,
            'options' => $options,
        ];
    }

	/**
     * 使用 Workerman 客户端分发异步请求
     *
     * @param array $requests 请求列表
     * @param callable|null $callback 完成回调
     * @return array
     */
    private static function dispatchAsyncWorkerman(array $requests, ?callable $callback = null): array
    {
        $normalized = self::normalizeBatchRequests($requests);
        if ($normalized === []) {
            if ($callback) {
                $callback([]);
            }
            return [];
        }

        if ($callback === null) {
            return self::awaitWorkermanBatch($normalized);
        }

        $client = new \Workerman\Http\Client();
        $pending = count($normalized);
        $results = [];

        foreach ($normalized as $item) {
            $wmOptions = self::buildWorkermanRequestOptions($item['method'], $item['url'], $item['options']);
            $key = $item['key'];
            $wmOptions['success'] = function ($response) use (&$results, &$pending, $callback, $item, $key): void {
                $results[$key] = self::packBatchResult(
                    $item['url'],
                    $item['method'],
                    self::workermanResponseToInfo($response),
                    $item['options']
                );
                if (--$pending === 0) {
                    $callback($results);
                }
            };
            $wmOptions['error'] = function (\Throwable $e) use (&$results, &$pending, $callback, $item, $key): void {
                $results[$key] = self::packBatchResult(
                    $item['url'],
                    $item['method'],
                    self::workermanExceptionToInfo($e),
                    $item['options']
                );
                if (--$pending === 0) {
                    $callback($results);
                }
            };
            $client->request($item['url'], $wmOptions);
        }

        return [];
    }

	/**
     * 协程内等待 Workerman 批量请求完成
     *
     * @param array<int,array{key:int|string,url:string,method:string,options:array}> $normalized
     * @return array
     */
    private static function awaitWorkermanBatch(array $normalized): array
    {
        if (!class_exists(\Workerman\Http\ParallelClient::class)) {
            throw new \RuntimeException('Workerman\\Http\\ParallelClient 不可用');
        }

        $parallel = new \Workerman\Http\ParallelClient();
        $batch = [];
        foreach ($normalized as $item) {
            $batch[$item['key']] = [
                $item['url'],
                self::buildWorkermanRequestOptions($item['method'], $item['url'], $item['options']),
            ];
        }
        $parallel->batch($batch);
        $awaited = $parallel->await();

        $results = [];
        foreach ($normalized as $item) {
            $key = $item['key'];
            $entry = $awaited[$key] ?? null;
            if (!is_array($entry) || count($entry) < 2) {
                $info = self::workermanExceptionToInfo(new \RuntimeException('missing response'));
            } elseif ($entry[0] === true) {
                $info = self::workermanResponseToInfo($entry[1]);
            } else {
                $info = self::workermanExceptionToInfo($entry[1] instanceof \Throwable ? $entry[1] : new \RuntimeException((string) $entry[1]));
            }
            $results[$key] = self::packBatchResult($item['url'], $item['method'], $info, $item['options']);
        }

        return $results;
    }

	/**
     * 将 Candy Http 选项映射为 Workerman Client 选项
     *
     * @param string $method HTTP 方法
     * @param string $url 请求 URL
     * @param array<string,mixed> $options Candy 选项
     * @return array<string,mixed>
     */
    private static function buildWorkermanRequestOptions(string $method, string $url, array $options): array
    {
        $wm = ['method' => strtoupper($method)];

        if (isset($options['data'])) {
            $wm['data'] = self::buildQueryData($options['data']);
        }

        $headers = [];
        if (!empty($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $header) {
                if (!is_string($header) || !str_contains($header, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $header, 2);
                $headers[trim($name)] = trim($value);
            }
        } else {
            $headers['User-Agent'] = self::getUserAgent($method, $url);
        }
        $wm['headers'] = $headers;

        $wm['context'] = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];

        return $wm;
    }

    /**
     * 判断通用 HTTP 客户端地址是否为 HTTP(S) 且不携带 URL 凭据。
     *
     * @param string $url 待请求地址
     * @return bool 是否允许交给传输层
     */
    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true)
            && (string)($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

	/**
     * Workerman Response 转为与 curl 一致的结果字段
     *
     * @param mixed $response Workerman 响应对象
     * @return array{http_code:int,content:string,header:array,error:string}
     */
    private static function workermanResponseToInfo(mixed $response): array
    {
        if ($response instanceof \Workerman\Http\Response) {
            $headerLines = [];
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $headerLines[] = $name . ': ' . $value;
                }
            }
            return [
                'http_code' => $response->getStatusCode(),
                'content' => (string) $response->getBody(),
                'header' => $headerLines,
                'error' => '',
            ];
        }

        return [
            'http_code' => 0,
            'content' => '',
            'header' => [],
            'error' => 'invalid workerman response',
        ];
    }

	/**
     * Workerman 异常转为与 curl 一致的结果字段
     *
     * @param \Throwable $e 异常
     * @return array{http_code:int,content:string,header:array,error:string}
     */
    private static function workermanExceptionToInfo(\Throwable $e): array
    {
        return [
            'http_code' => 0,
            'content' => '',
            'header' => [],
            'error' => $e->getMessage(),
        ];
    }

    /**
     * 单个异步请求（Workerman 事件循环）
     *
     * FPM 阻塞并发请用 parallel() 或同步 request()
     * 
     * @param string $method HTTP请求方法
     * @param string $url 请求URL
     * @param array $options 请求选项
     * @param callable|null $callback 回调函数
     * @return array 请求结果数组
     */
    public static function requestAsync(string $method, string $url, array $options = [], ?callable $callback = null): array
    {
        $requests = [
            [
                'url' => $url,
                'method' => $method,
                'options' => $options
            ]
        ];
        $results = self::async($requests, $callback);
        return reset($results) ?: [];
    }

    /**
     * 异步GET请求
     * 
     * 发送异步GET请求，内部调用requestAsync()方法
     * 
     * @param string $url 请求URL
     * @param mixed $query GET参数
     * @param array $options 请求选项
     * @param callable|null $callback 回调函数
     * @return array 请求结果数组
     */
    public static function getAsync(string $url, $query = '', array $options = [], ?callable $callback = null): array
    {
        $options['query'] = $query;
        return self::requestAsync('GET', $url, $options, $callback);
    }

    /**
     * 异步POST请求
     * 
     * 发送异步POST请求，内部调用requestAsync()方法
     * 
     * @param string $url 请求URL
     * @param mixed $data POST数据
     * @param array $options 请求选项
     * @param callable|null $callback 回调函数
     * @return array 请求结果数组
     */
    public static function postAsync(string $url, $data = '', array $options = [], ?callable $callback = null): array
    {
        $options['data'] = $data;
        return self::requestAsync('POST', $url, $options, $callback);
    }

    /**
     * 异步PUT请求
     * 
     * 发送异步PUT请求，内部调用requestAsync()方法
     * 
     * @param string $url 请求URL
     * @param mixed $data PUT数据
     * @param array $options 请求选项
     * @param callable|null $callback 回调函数
     * @return array 请求结果数组
     */
    public static function putAsync(string $url, $data = '', array $options = [], ?callable $callback = null): array
    {
        $options['data'] = $data;
        return self::requestAsync('PUT', $url, $options, $callback);
    }

    /**
     * 异步DELETE请求
     * 
     * 发送异步DELETE请求，内部调用requestAsync()方法
     * 
     * @param string $url 请求URL
     * @param mixed $data DELETE数据
     * @param array $options 请求选项
     * @param callable|null $callback 回调函数
     * @return array 请求结果数组
     */
    public static function deleteAsync(string $url, $data = '', array $options = [], ?callable $callback = null): array
    {
        $options['data'] = $data;
        return self::requestAsync('DELETE', $url, $options, $callback);
    }
}
