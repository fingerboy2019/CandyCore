<?php
/**
 * Workerman 入站 HTTP 适配层
 *
 * 仅用于 Workerman/CLI 入站适配：模拟 PHP header/session/cookie，并提供 input/decode/encode。
 * 禁止与出站客户端 Candy\Extend\Network\Http、上游 Workerman\Protocols\Http、Workerman\Http\Client 混用。
 *
 * @package Workerman\Http
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-08-18 20:25:00 $
 */
declare(strict_types=1);
namespace Workerman\Http;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Workerman\Timer;
use Workerman\Connection\TcpConnection;

/**
 * Http — Workerman 环境下的 入站 HTTP 适配（header/session/cookie）
 *
 * 主要特性：
 * - HTTP 请求解析（GET/POST/PUT/DELETE 等）与响应编码
 * - Cookie / Session 管理
 * - 供 Adapter + reload.func 重写 header/session_* 等系统函数
 */
class Http
{
    /**
     * HTTP响应状态行
     * 
     * 存储当前HTTP响应的状态行，格式为'HTTP/1.1 状态码 状态描述'
     * 默认为空，在reset()方法中初始化为'HTTP/1.1 200 OK'
     * 可通过responseCode()方法修改
     * 
     * @var string
     */
    public static string $status = '';

    /**
     * HTTP响应头数组
     * 
     * 存储所有待发送的HTTP响应头信息，键为响应头名称，值为完整的响应头字符串
     * 可通过header()方法添加或修改
     * 
     * @var array
     */
    public static array $headers = [];

    /**
     * HTTP Cookie数组
     * 
     * 存储所有待发送的HTTP Cookie信息，每个元素为完整的Set-Cookie头字符串
     * 可通过setCookie()方法添加
     * 
     * @var array
     */
    public static array $cookies = [];

    /**
     * Session存储路径
     * 
     * 存储会话文件的保存路径，默认会从PHP配置读取
     * 
     * @var string
     */
    public static string $sessionSavePath = '';

    /**
     * Session名称
     * 
     * 存储会话Cookie的名称，默认从PHP配置读取
     * 
     * @var string
     */
    public static string $sessionName = '';

    /**
     * Session垃圾回收最大生命周期
     * 
     * 定义会话文件多久后会被视为垃圾并清理（单位：秒）
     * 默认为1440秒（24分钟）
     * 
     * @var int
     */
    public static int $sessionGcMaxLifeTime = 1440;

    /**
     * Session Cookie生命周期
     * 
     * 定义会话Cookie的有效期（单位：秒）
     * 默认从PHP配置读取
     * 
     * @var int
     */
    public static int $sessionCookieLifetime;

    /**
     * Session Cookie路径
     * 
     * 定义会话Cookie的有效路径
     * 默认从PHP配置读取
     * 
     * @var string
     */
    public static string $sessionCookiePath;

    /**
     * Session Cookie域名
     * 
     * 定义会话Cookie的有效域名
     * 默认从PHP配置读取
     * 
     * @var string
     */
    public static string $sessionCookieDomain;

    /**
     * Session Cookie安全标识
     * 
     * 定义会话Cookie是否仅通过HTTPS连接发送
     * 默认从PHP配置读取
     * 
     * @var bool
     */
    public static bool $sessionCookieSecure;

    /**
     * Session Cookie HttpOnly标识
     * 
     * 定义会话Cookie是否只能通过HTTP协议访问，无法通过JavaScript获取
     * 默认从PHP配置读取
     * 
     * @var bool
     */
    public static bool $sessionCookieHttponly;

    /**
     * Session垃圾回收执行间隔
     * 
     * 定义多久执行一次会话垃圾回收（单位：秒）
     * 默认为600秒（10分钟）
     * 
     * @var int
     */
    public static int $sessionGcInterval = 600;

    /**
     * Session是否已启动
     * 
     * 标识当前会话是否已经开始，避免重复启动
     * 
     * @var bool
     */
    protected static bool $sessionStarted = false;

    /**
     * Session文件路径
     * 
     * 存储当前会话文件的完整路径
     * 
     * @var string
     */
    protected static string $sessionFile = '';

    /**
     * 会话启动前由 session_id() 指定的 ID。
     *
     * @var string
     */
    protected static string $sessionIdOverride = '';

    /**
     * HTTP请求缓存数组
     * 
     * 缓存已解析的HTTP请求，提高性能
     * 以请求数据为键，存储解析后的请求信息
     * 
     * @var array
     */
    protected static array $cache = [];
    /**
     * HTTP状态码常量
     * 
     * 存储标准HTTP状态码及其对应的状态描述文本
     * 用于生成正确的HTTP响应状态行，支持从1xx到5xx的所有标准状态码
     * 
     * 结构：
     * - 键：HTTP状态码（如200、404、500等）
     * - 值：状态描述文本（如'OK'、'Not Found'、'Internal Server Error'等）
     * 
     * @var array
     */
    const CODES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing', // WebDAV; RFC 2518
        103 => 'Early Hints', // RFC 8297

        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information', // since HTTP/1.1
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content', // RFC 7233
        207 => 'Multi-Status', // WebDAV; RFC 4918
        208 => 'Already Reported', // WebDAV; RFC 5842
        226 => 'IM Used', // RFC 3229

        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // Previously "Moved temporarily"
        303 => 'See Other', // since HTTP/1.1
        304 => 'Not Modified', // RFC 7232
        305 => 'Use Proxy', // since HTTP/1.1
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect', // since HTTP/1.1
        308 => 'Permanent Redirect', // RFC 7538

        400 => 'Bad Request',
        401 => 'Unauthorized', // RFC 7235
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required', // RFC 7235
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed', // RFC 7232
        413 => 'Payload Too Large', // RFC 7231
        414 => 'URI Too Long', // RFC 7231
        415 => 'Unsupported Media Type', // RFC 7231
        416 => 'Range Not Satisfiable', // RFC 7233
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot', // RFC 2324, RFC 7168
        421 => 'Misdirected Request', // RFC 7540
        422 => 'Unprocessable Entity', // WebDAV; RFC 4918
        423 => 'Locked', // WebDAV; RFC 4918
        424 => 'Failed Dependency', // WebDAV; RFC 4918
        425 => 'Too Early', // RFC 8470
        426 => 'Upgrade Required',
        428 => 'Precondition Required', // RFC 6585
        429 => 'Too Many Requests', // RFC 6585
        431 => 'Request Header Fields Too Large', // RFC 6585
        451 => 'Unavailable For Legal Reasons', // RFC 7725
        
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates', // RFC 2295
        507 => 'Insufficient Storage', // WebDAV; RFC 4918
        508 => 'Loop Detected', // WebDAV; RFC 5842
        510 => 'Not Extended', // RFC 2774
        511 => 'Network Authentication Required', // RFC 6585
    ];

    /**
     * 初始化Http类的会话配置
     * 
     * 设置HTTP会话管理所需的各项配置参数，包括会话存储路径、Cookie设置等
     * 这些配置会影响sessionStart()、sessionWriteClose()等会话相关方法的行为
     * 
     * @return void
     */
    public static function init()
    {
        if (!static::$sessionName) {
            static::$sessionName = \ini_get('session.name');
        }

        if (!static::$sessionSavePath) {
            $savePath = ini_get('session.save_path');
            if (preg_match('/^\d+;(.*)$/', $savePath, $match)) {
                $savePath = $match[1];
            }
            if (!$savePath || str_starts_with($savePath, 'tcp://')) {
                $savePath = \sys_get_temp_dir();
            }
            static::$sessionSavePath = $savePath;
        }

        // 初始化统一会话管理器
        try {
            Candy\Extend\SessionManager::init(static::$sessionSavePath);
        } catch (\Throwable) {
        }

        if ($gc_max_life_time = \ini_get('session.gc_maxlifetime')) {
            static::$sessionGcMaxLifeTime = (int)$gc_max_life_time;
        }

        static::$sessionCookieLifetime = (int)\ini_get('session.cookie_lifetime');
        static::$sessionCookiePath = (string)\ini_get('session.cookie_path');
        static::$sessionCookieDomain = (string)\ini_get('session.cookie_domain');
        static::$sessionCookieSecure = (bool)\ini_get('session.cookie_secure');
        static::$sessionCookieHttponly = (bool)\ini_get('session.cookie_httponly');

        if (class_exists(Timer::class)
            && class_exists(\Workerman\Worker::class)
            && \Workerman\Worker::getAllWorkers()
        ) {
            Timer::add(static::$sessionGcInterval, function () {
                static::tryGcSessions();
            });
        }
    }

    /**
     * 重置HTTP响应状态
     * 
     * 清空所有HTTP响应相关的状态，包括响应状态行、响应头和Cookie
     * 每个新的HTTP请求处理前都应该调用此方法重置状态
     * 
     * 重置内容包括：
     * - 响应状态行设置为默认的'HTTP/1.1 200 OK'
     * - 清空所有响应头数组
     * - 清空所有Cookie数组
     * 
     * @return void
     */
    public static function reset()
    {
        static::$status = 'HTTP/1.1 200 OK';
        static::$headers = [
            'Content-Type' => 'Content-Type: text/html;charset=utf-8',
            'Server' => 'Server: workerman'
        ];
        static::$cookies = [];
        static::$sessionFile = '';
        static::$sessionIdOverride = '';
        static::$sessionStarted = false;
    }

    /**
     * 支持的HTTP请求方法常量
     * 
     * 存储该HTTP类支持处理的所有HTTP请求方法
     * 用于请求方法验证和处理不同类型的HTTP请求
     * 
     * 支持的方法包括：
     * - GET：获取资源
     * - POST：提交数据并可能创建新资源
     * - PUT：更新资源
     * - DELETE：删除资源
     * - HEAD：获取资源头部信息，与GET类似但不返回响应体
     * - OPTIONS：获取服务器支持的HTTP方法
     * - PATCH：部分更新资源
     * 
     * @var array
     */
    const AVAILABLE_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'];

    /**
     * 设置HTTP响应头
     * 
     * 添加或修改HTTP响应头，这些头信息会在encode()方法生成响应时包含在内
     * 支持标准HTTP响应头和自定义响应头
     * 也可以直接设置HTTP状态行（以HTTP开头的内容）
     * 
     * 使用示例：
     * ```php
     * Http::header('Content-Type: application/json');
     * Http::header('Cache-Control: no-cache, must-revalidate');
     * Http::header('HTTP/1.1 404 Not Found');
     * ```
     * 
     * @param string $content 完整的响应头内容或HTTP状态行
     * @param bool $replace 是否替换同名的已存在响应头
     * @param int $http_response_code 可选的HTTP状态码，与location头配合使用
     * @return bool 操作是否成功
     */
    public static function header(string $content, bool $replace = true, ?int $http_response_code = null): bool
    {
        if (str_starts_with($content, 'HTTP')) {
            static::$status = $content;
            return true;
        }

        $key = \strstr($content, ':', true);
        if (empty($key)) {
            return false;
        }

        if ('location' === \strtolower($key)) {
            if (!$http_response_code) {
                $http_response_code = 302;
            }
            static::responseCode($http_response_code);
        }

        if (strcasecmp(trim($key), 'Set-Cookie') === 0) {
            static::$cookies[] = $content;
        } elseif ($replace) {
            foreach (static::$headers as $headerKey => $headerValue) {
                $existingName = strstr((string)$headerValue, ':', true);
                if ($existingName !== false && strcasecmp(trim($existingName), trim($key)) === 0) {
                    unset(static::$headers[$headerKey]);
                }
            }
            static::$headers[$key] = $content;
        } else {
            static::$headers[] = $content;
        }

        return true;
    }

    /**
     * 删除已设置的HTTP响应头。
     *
     * 头名称按 HTTP 规范大小写不敏感匹配，并同时处理重复头。
     * @param string $name 要删除的响应头名称
     * @return void
     */
    public static function headerRemove(string $name): void
    {
        foreach (static::$headers as $headerKey => $headerValue) {
            $existingName = strstr((string)$headerValue, ':', true);
            if ($existingName !== false && strcasecmp(trim($existingName), trim($name)) === 0) {
                unset(static::$headers[$headerKey]);
            }
        }
        if (strcasecmp(trim($name), 'Set-Cookie') === 0) {
            static::$cookies = [];
        }
    }

    /**
     * 设置或获取HTTP响应状态码
     * 
     * 用于设置当前HTTP响应的状态码，并自动更新响应状态行
     * 也可以不传入参数来获取当前设置的状态码
     * 
     * 状态码使用CODES常量数组中定义的标准HTTP状态码
     * 如果指定的状态码不存在，将使用默认的200状态码
     * 
     * 使用示例：
     * ```php
     * Http::responseCode(404);
     * $code = Http::responseCode();
     * ```
     * 
     * @param int|null $code 要设置的HTTP状态码，为null时返回当前状态码
     * @return int|void 如果传入参数则无返回值，如果未传入参数则返回当前状态码
     */
    public static function responseCode(int $code): bool|int
    {
        if (isset(static::CODES[$code])) {
            static::$status = "HTTP/1.1 $code " . static::CODES[$code];
            return $code;
        }
        return false;
    }

    /**
     * 设置HTTP Cookie
     * 
     * 添加一个Cookie到响应中，这些Cookie会在encode()方法生成响应时包含在内
     * 支持设置Cookie的各种属性，如过期时间、路径、域名、安全标志等
     * 
     * 使用示例：
     * ```php
     * Http::setCookie('user_id', '123');
     * Http::setCookie('session_id', 'abc123', 86400);
     * Http::setCookie('secure_data', 'xyz', 0, '/', '', true, true);
     * ```
     * 
     * @param string $name Cookie名称
     * @param string $value Cookie值
     * @param int $maxage Cookie最大生存时间（单位：秒），0表示会话结束时过期
     * @param string $path Cookie有效路径
     * @param string $domain Cookie有效域名
     * @param bool $secure 是否仅通过HTTPS连接发送Cookie
     * @param bool $HTTPOnly 是否仅通过HTTP访问Cookie（禁止JavaScript获取）
     * @return bool 操作是否成功
     */
    public static function setCookie(
        string $name,
        string $value = '',
        int    $maxage = 0,
        string $path = '',
        string $domain = '',
        bool   $secure = false,
        bool $HTTPOnly = false
    ): bool
    {
        static::$cookies[] = 'Set-Cookie: ' . $name . '=' . rawurlencode($value)
            . ($domain ? '; Domain=' . $domain : '')
            . (($maxage === 0) ? '' : '; Max-Age=' . $maxage)
            . ($path ? '; Path=' . $path : '')
            . (!$secure ? '' : '; Secure')
            . (!$HTTPOnly ? '' : '; HttpOnly');

        return true;
    }

    /**
     * 创建新的Session ID
     * 
     * 生成一个新的随机Session ID，用于会话标识
     * 不会影响当前的Session ID，仅返回新生成的值
     * 
     * 生成的Session ID是基于随机数和时间戳的字符串，确保足够的唯一性
     * 
     * @param string $prefix Session ID 前缀
     * @return string 新生成的Session ID字符串
     */
    public static function sessionCreateId(string $prefix = ''): string
    {
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9,_-]{1,64}$/D', $prefix)) {
            throw new \ValueError('Invalid session ID prefix');
        }
        \mt_srand();
        return $prefix . bin2hex(\pack('d', \hrtime(true)) . \pack('N', \mt_rand(0, 2147483647)));
    }

    /**
     * 设置或获取当前Session ID
     * 
     * 用于设置新的Session ID或获取当前Session ID
     * 当传入参数时设置Session ID，不传入参数时获取当前Session ID
     * 
     * @param string|null $id 要设置的Session ID，为null时返回当前Session ID
     * @return string 如果未传入参数则返回当前Session ID
     */
    public static function sessionId(?string $id = null): string
    {
        $current = '';
        if (static::$sessionFile) {
            $current = str_replace('ses_', '', basename(static::$sessionFile));
        } elseif (static::$sessionIdOverride !== '') {
            $current = static::$sessionIdOverride;
        }
        if ($id !== null && !static::sessionStarted()) {
            if ($id !== '' && !preg_match('/^[A-Za-z0-9,_-]{1,128}$/D', $id)) {
                throw new \ValueError('Invalid session ID');
            }
            static::$sessionIdOverride = $id;
        }
        if (static::sessionStarted() && static::$sessionFile) {
            return \str_replace('ses_', '', basename(static::$sessionFile));
        }
        return $current;
    }

    /**
     * 设置或获取Session名称
     * 
     * 用于设置新的Session名称或获取当前Session名称
     * Session名称用于设置会话Cookie的名称
     * 
     * @param string|null $name 要设置的Session名称，为null时返回当前Session名称
     * @return string 如果未传入参数则返回当前Session名称
     */
    public static function sessionName(?string $name = null): string
    {
        $session_name = static::$sessionName;
        if ($name && !static::sessionStarted()) {
            static::$sessionName = $name;
        }
        return $session_name;
    }

    /**
     * 设置或获取Session存储路径
     * 
     * 用于设置新的Session文件存储路径或获取当前存储路径
     * 该路径用于保存会话数据文件
     * 
     * @param string|null $path 要设置的Session存储路径，为null时返回当前存储路径
     * @return string 如果传入参数则返回设置后的存储路径，如果未传入参数则返回当前存储路径
     */
    public static function sessionSavePath(?string $path = null): string
    {
        if ($path && \is_dir($path) && \is_writable($path) && !static::sessionStarted()) {
            static::$sessionSavePath = $path;
        }
        return static::$sessionSavePath;
    }

    /**
     * 检查Session是否已启动
     * 
     * 判断当前会话是否已经启动，避免重复启动会话
     * 这是一个辅助方法，通常在调用sessionStart()前使用
     * 
     * @return bool 如果会话已启动返回true，否则返回false
     */
    public static function sessionStarted(): bool
    {
        return static::$sessionStarted;
    }

    /**
     * 启动HTTP会话
     * 
     * 初始化会话环境，创建或恢复会话数据
     * 支持从Cookie中读取Session ID，或自动创建新的Session ID
     * 
     * 处理流程：
     * 1. 检查会话是否已经启动，避免重复启动
     * 2. 从Cookie中获取Session ID（如果存在）
     * 3. 如果没有Session ID或Session文件不存在，则创建新的Session ID
     * 4. 从文件系统加载会话数据（如果存在）
     * 5. 标记会话为已启动状态
     * 
     * @param array $options PHP session_start() 选项
     * @return bool 会话启动是否成功
     */
    public static function sessionStart(array $options = []): bool
    {
        if (static::$sessionStarted) {
            return true;
        }
        static::applySessionOptions($options);
        static::$sessionStarted = true;
        // Generate a SID.
        // candy: 缓存模式下不检查文件是否存在，由 SessionManager 决定是否为新会话
        $sessionExists = static::$sessionIdOverride !== '' || isset($_COOKIE[static::$sessionName]);
        if ($sessionExists) {
            $sid = static::$sessionIdOverride !== ''
                ? static::$sessionIdOverride
                : $_COOKIE[static::$sessionName];
            $sessionFile = static::$sessionSavePath . '/ses_' . $sid;
            // candy: 缓存模式不依赖文件存在判断，交给 SessionManager::read() 处理
            if (!\is_file($sessionFile)) {
                // candy: 文件不存在时尝试从缓存读取，确定是否为新会话
                try {
                    $cached = Candy\Extend\SessionManager::read($sid);
                    if ($cached !== '') {
                        $sessionExists = true; // 缓存中有数据，会话有效
                    }
                } catch (\Throwable) {
                }
            }
        }
        if (!$sessionExists) {
            // Create a unique session_id and the associated file name.
            while (true) {
                $session_id = static::sessionCreateId();
                if (!\is_file($file_name = static::$sessionSavePath . '/ses_' . $session_id)) break;
            }
            static::$sessionFile = $file_name;
            static::$sessionIdOverride = '';
            return static::setcookie(
                static::$sessionName
                , $session_id
                , static::$sessionCookieLifetime
                , static::$sessionCookiePath
                , static::$sessionCookieDomain
                , static::$sessionCookieSecure
                , static::$sessionCookieHttponly
            );
        }
        if (!static::$sessionFile) {
            static::$sessionFile = static::$sessionSavePath . '/ses_' . $sid;
        }
        // Read session from cache or file.
        if (static::$sessionFile) {
            $sessionId = basename(static::$sessionFile);
            $raw = Candy\Extend\SessionManager::read($sessionId);
            if (!$raw) {
                $raw = @file_get_contents(static::$sessionFile);
            }
            if ($raw) {
                $sessionData = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($sessionData)) {
                    $_SESSION = $sessionData;
                }
            }
        }
        if (!empty($options['read_and_close'])) {
            static::sessionWriteClose();
        }
        return true;
    }

    /**
     * 应用 session_start() 支持的 Cookie 选项。
     *
     * @param array $options PHP 会话启动选项
     * @return void
     */
    private static function applySessionOptions(array $options): void
    {
        if (array_key_exists('cookie_lifetime', $options)) {
            static::$sessionCookieLifetime = max(0, (int)$options['cookie_lifetime']);
        }
        if (array_key_exists('cookie_path', $options)) {
            static::$sessionCookiePath = (string)$options['cookie_path'];
        }
        if (array_key_exists('cookie_domain', $options)) {
            static::$sessionCookieDomain = (string)$options['cookie_domain'];
        }
        if (array_key_exists('cookie_secure', $options)) {
            static::$sessionCookieSecure = (bool)$options['cookie_secure'];
        }
        if (array_key_exists('cookie_httponly', $options)) {
            static::$sessionCookieHttponly = (bool)$options['cookie_httponly'];
        }
    }

    /**
     * Save session.
     * @return bool
     */
    public static function sessionWriteClose(): bool
    {
        if (static::$sessionStarted) {
            $session_str = serialize($_SESSION);
            if ($session_str && static::$sessionFile) {
                $sessionId = basename(static::$sessionFile);
                Candy\Extend\SessionManager::write($sessionId, $session_str, static::$sessionGcMaxLifeTime);
                return true;
            }
        }
        return empty($_SESSION);
    }

    /**
     * Update the current session id with a newly generated one.
     * @param $delete_old_session
     * @return bool
     * @link https://www.php.net/manual/en/function.session-regenerate-id.php
     */
    public static function sessionRegenerateId(bool $delete_old_session = false): bool
    {
        $old_session_file = static::$sessionFile;
        // Create a unique session_id and the associated file name.
        while (true) {
            $session_id = static::sessionCreateId();
            if (!\is_file($file_name = static::$sessionSavePath . '/ses_' . $session_id)) break;
        }
        static::$sessionFile = $file_name;

        if ($delete_old_session) {
            \unlink($old_session_file);
        }

        return static::setcookie(
            static::$sessionName
            , $session_id
            , static::$sessionCookieLifetime
            , static::$sessionCookiePath
            , static::$sessionCookieDomain
            , static::$sessionCookieSecure
            , static::$sessionCookieHttponly
        );
    }

    /**
     * 解析上传的文件数据
     * 
     * 从HTTP请求体中解析multipart/form-data格式的文件上传数据
     * 将解析后的数据填充到$_FILES和$_POST超全局变量中
     * 支持多文件上传和表单字段混合的情况
     * 
     * @param string $http_body HTTP请求体内容
     * @param string $http_post_boundary 请求体的分隔符
     * @return void
     */
    protected static function parseUploadFiles(string $http_body, string $http_post_boundary)
    {
        $http_body = \substr($http_body, 0, \strlen($http_body) - (\strlen($http_post_boundary) + 4));
        $boundary_data_array = \explode($http_post_boundary . "\r\n", $http_body);
        if ($boundary_data_array[0] === '') {
            unset($boundary_data_array[0]);
        }
        $key = -1;
        $post_encode_string = '';
        foreach ($boundary_data_array as $boundary_data_buffer) {
            [$boundary_header_buffer, $boundary_value] = \explode("\r\n\r\n", $boundary_data_buffer, 2);
            // Remove \r\n from the end of buffer.
            $boundary_value = \substr($boundary_value, 0, -2);
            $key++;
            foreach (\explode("\r\n", $boundary_header_buffer) as $item) {
                [$header_key, $header_value] = \explode(": ", $item);
                $header_key = \strtolower($header_key);
                switch ($header_key) {
                    case 'content-disposition':
                        // Is file data.
                        if (\preg_match('/name="(.*?)"; filename="(.*?)"/', $header_value, $match)) {
                            // Parse $_FILES.
                            $_FILES[$key] = [
                                'name' => $match[1],
                                'file_name' => $match[2],
                                'file_data' => $boundary_value,
                                'file_size' => \strlen($boundary_value),
                            ];
                        } // Is post field.
                        else {
                            // Parse $_POST.
                            if (\preg_match('/name="(.*?)"$/', $header_value, $match)) {
                                //TODO search a fast solution
                                $post_encode_string .= urlencode($match[1]) . '=' . urlencode($boundary_value) . '&';
                            }
                        }
                    case 'content-type':
                        $_FILES[$key]['file_type'] = \trim($header_value);
                    default:
                        return null;
                }
            }
            if($post_encode_string) {
                \parse_str($post_encode_string, $_POST);
            }
        }
    }

    /**
     * Try GC sessions.
     * @return void
     */
    public static function tryGcSessions()
    {
        $time_now = \time();
        foreach (glob(static::$sessionSavePath . '/ses*') as $file) {
            if (\is_file($file) && $time_now - \filemtime($file) > static::$sessionGcMaxLifeTime) {
                \unlink($file);
            }
        }
    }

    /**
     * 检查HTTP数据包的完整性
     * 
     * 用于验证接收的HTTP数据是否完整，根据HTTP协议规范解析数据包长度
     * 实现了Workerman所需的回调函数接口，用于确定何时可以将接收到的数据作为完整包处理
     * 支持缓存解析结果以提高性能
     * 
     * 处理逻辑：
     * 1. 尝试从缓存中获取已解析的结果
     * 2. 查找HTTP头和HTTP体之间的分隔符(\r\n\r\n)
     * 3. 根据请求方法和Content-Length头确定数据包总长度
     * 4. 对于不支持的请求方法返回400错误
     * 
     * @param string $recv_buffer 接收的HTTP原始数据
     * @param TcpConnection $connection 对应的TCP连接对象
     * @return int 返回0表示数据包不完整，需要继续接收数据；返回>0的整数表示数据包完整，整数即为数据包总长度
     */
    public static function input(string $recv_buffer, TcpConnection $connection): int
    {
        if (isset(static::$cache[$recv_buffer]['input'])) {
            return static::$cache[$recv_buffer]['input'];
        }
        $recv_len = \strlen($recv_buffer);
        $crlf_post = \strpos($recv_buffer, "\r\n\r\n");
        if (!$crlf_post) {
            // Judge whether the package length exceeds the limit.
            if ($recv_len >= $connection->maxPackageSize) {
                $connection->close();
            }
            return 0;
        }
        $head_len = $crlf_post + 4;

        $method = \substr($recv_buffer, 0, \strpos($recv_buffer, ' '));
        if (!\in_array($method, static::AVAILABLE_METHODS)) {
            $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            $connection->consumeRecvBuffer($recv_len);
            return 0;
        }

        if ($method === 'GET' || $method === 'OPTIONS' || $method === 'HEAD') {
            static::$cache[$recv_buffer]['input'] = $head_len;
            return $head_len;
        }

        $match = [];
        if (\preg_match("/\r\nContent-Length: ?(\d+)/i", $recv_buffer, $match)) {
            $content_length = $match[1] ??= 0;
            $total_length = $content_length + $head_len;
            if (!isset($recv_buffer[1024])) {
                static::$cache[$recv_buffer]['input'] = $total_length;
            }
            return $total_length;
        }

        return $method === 'DELETE' ? $head_len : 0;
    }

    /**
     * 解析HTTP请求数据
     * 
     * 将原始HTTP请求数据解析为结构化的请求信息，包括请求方法、URI、头信息、请求体等
     * 支持解析GET、POST、PUT、DELETE等各种HTTP请求方法
     * 自动处理Cookie解析、文件上传、表单数据和JSON数据
     * 
     * 该方法会重置当前环境变量($_GET, $_POST, $_COOKIE, $_SERVER等)并填充解析后的数据
     * 同时会对GET请求进行缓存以提高性能
     * 
     * @param string $recv_buffer 原始HTTP请求数据
     * @param TcpConnection $connection 对应的TCP连接对象，用于获取客户端IP和端口等信息
     * @return array 解析后的请求信息数组，包含以下键：
     *         - get: $_GET数组数据
     *         - post: $_POST数组数据
     *         - cookie: $_COOKIE数组数据
     *         - server: $_SERVER数组数据
     *         - files: $_FILES数组数据（文件上传信息）
     *         - request: $_REQUEST数组数据（合并了GET和POST）
     */
    public static function decode(string $recv_buffer, TcpConnection $connection): array
    {
        static::reset();
        if (isset(static::$cache[$recv_buffer]['decode'])) {
            $cache = static::$cache[$recv_buffer]['decode'];
            $_SERVER = $cache['server'];
            $_POST = $cache['post'];
            $_GET = $cache['get'];
            $_COOKIE = $cache['cookie'];
            $_REQUEST = $cache['request'];
            $GLOBALS['HTTP_RAW_POST_DATA'] = $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '';
            return static::$cache[$recv_buffer]['decode'];
        }
        // Init.
        $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = $_FILES = [];
        // $_SERVER
        $_SERVER = [
            'REQUEST_METHOD' => '',
            'REQUEST_URI' => '',
            'SERVER_PROTOCOL' => '',
            'SERVER_SOFTWARE' => 'workerman',
            'SERVER_NAME' => '',
            'HTTP_HOST' => '',
            'HTTP_USER_AGENT' => '',
            'HTTP_ACCEPT' => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_ACCEPT_ENCODING' => '',
            'HTTP_COOKIE' => '',
            'HTTP_CONNECTION' => '',
            'CONTENT_TYPE' => ''
        ];

        // Parse headers.
        [$http_header, $http_body] = \explode("\r\n\r\n", $recv_buffer, 2);
        $header_data = \explode("\r\n", $http_header);

        [$_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']] = \explode(' ',
            $header_data[0]);

        $http_post_boundary = '';
        unset($header_data[0]);
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            [$key, $value] = \explode(':', $content, 2);
            $key = \str_replace('-', '_', strtoupper($key));
            $value = \trim($value);
            $_SERVER['HTTP_' . $key] = $value;
            switch ($key) {
                case 'HOST':
                    $tmp = \explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                case 'COOKIE':
                    \parse_str(\str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                case 'CONTENT_TYPE':
                    if (!\preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                        if ($pos = \strpos($value, ';')) {
                            $_SERVER['CONTENT_TYPE'] = \substr($value, 0, $pos);
                        } else {
                            $_SERVER['CONTENT_TYPE'] = $value;
                        }
                    } else {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $http_post_boundary = '--' . $match[1];
                    }
                case 'CONTENT_LENGTH':
                    $_SERVER['CONTENT_LENGTH'] = $value;
            }
        }

        // Parse $_POST.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE']) {
            switch ($_SERVER['CONTENT_TYPE']) {
                case 'multipart/form-data':
                    static::parseUploadFiles($http_body, $http_post_boundary);
                    break;
                case 'application/json':
                    $_POST = \json_decode($http_body, true) ?? [];
                    break;
                case 'application/x-www-form-urlencoded':
                    \parse_str($http_body, $_POST);
                    break;
            }
        }

        // Parse other HTTP action parameters
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== "POST") {
            $data = [];
            if ($_SERVER['CONTENT_TYPE'] === "application/x-www-form-urlencoded") {
                \parse_str($http_body, $data);
            } elseif ($_SERVER['CONTENT_TYPE'] === "application/json") {
                $data = \json_decode($http_body, true) ?? [];
            }
            $_REQUEST = \array_merge($_REQUEST, $data);
        }

        // HTTP_RAW_REQUEST_DATA HTTP_RAW_POST_DATA
        $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $GLOBALS['HTTP_RAW_POST_DATA'] = $http_body;

        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) {
            // $GET
            \parse_str($_SERVER['QUERY_STRING'], $_GET);
        } else {
            $_SERVER['QUERY_STRING'] = '';
        }

        if (\is_array($_POST)) {
            // REQUEST
            $_REQUEST = \array_merge($_GET, $_POST, $_REQUEST);
        } else {
            // REQUEST
            $_REQUEST = \array_merge($_GET, $_REQUEST);
        }

        // REMOTE_ADDR REMOTE_PORT
        $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
        $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
        $ret = [
            'get' => $_GET,
            'post' => $_POST,
            'cookie' => $_COOKIE,
            'server' => $_SERVER,
            'files' => $_FILES,
            'request' => $_REQUEST
        ];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            static::$cache[$recv_buffer]['decode'] = $ret;
            if (\count(static::$cache) > 256) {
                unset(static::$cache[key(static::$cache)]);
            }
        }

        return $ret;
    }

    /**
     * 生成HTTP响应数据
     * 
     * 将内容编码为符合HTTP协议的响应数据，包括状态行、响应头、Cookie和响应体
     * 自动处理gzip压缩、内容长度计算和会话保存
     * 
     * 处理流程：
     * 1. 添加HTTP状态行
     * 2. 添加所有待发送的Cookie头
     * 3. 添加所有设置的自定义响应头
     * 4. 如果启用了gzip压缩，则对内容进行压缩并添加相应的Content-Encoding头
     * 5. 计算并添加Content-Length头
     * 6. 自动保存当前会话数据
     * 7. 组装完整的HTTP响应数据
     * 
     * @param string|null $content HTTP响应体内容
     * @param TcpConnection $connection TCP连接对象，用于检查是否启用了gzip压缩
     * @return string 完整的HTTP响应数据
     */
    public static function encode(?string $content, TcpConnection $connection): string
    {
        $content = (string)$content;

        // http-code status line.
        $header = static::$status . "\r\n";

        // Cookie headers
        if (static::$cookies) {
            $header .= \implode("\r\n", static::$cookies) . "\r\n";
        }

        // other headers
        if (static::$headers) {
            $header .= \implode("\r\n", static::$headers) . "\r\n";
        }

        if (!empty($connection->gzip)) {
            $header .= "Content-Encoding: gzip\r\n";
            $content = \gzencode($content, $connection->gzip);
        }
        // header
        $header .= 'Content-Length: ' . \strlen($content) . "\r\n\r\n";

        // save session
        static::sessionWriteClose();

        // the whole http package
        return $header . $content;
    }
    
    /**
     * 获取GET请求参数
     * 
     * 从GET请求参数中获取指定参数值
     * 当不指定参数名时返回所有GET参数
     * 
     * @param string|null $name 要获取的参数名，支持点号分隔的嵌套数组访问，如'user.info.name'
     * @param mixed $default 当参数不存在时的默认返回值
     * @return mixed 如果指定了$name且参数存在，返回对应的参数值；如果参数不存在，返回$default；如果未指定$name，返回所有GET参数
     */
    public static function get($name = null, $default = null)
    {
        if ($name === null) {
            return $_GET;
        }
        
        if (!isset($_GET[$name])) {
            return $default;
        }
        
        return $_GET[$name];
    }
    
    /**
     * 获取POST请求参数
     * 
     * 从POST请求参数中获取指定参数值
     * 当不指定参数名时返回所有POST参数
     * 
     * @param string|null $name 要获取的参数名，支持点号分隔的嵌套数组访问，如'user.info.name'
     * @param mixed $default 当参数不存在时的默认返回值
     * @return mixed 如果指定了$name且参数存在，返回对应的参数值；如果参数不存在，返回$default；如果未指定$name，返回所有POST参数
     */
    public static function post($name = null, $default = null)
    {
        if ($name === null) {
            return $_POST;
        }
        
        if (!isset($_POST[$name])) {
            return $default;
        }
        
        return $_POST[$name];
    }
}
