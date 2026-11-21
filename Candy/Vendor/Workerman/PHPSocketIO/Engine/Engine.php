<?php

namespace PHPSocketIO\Engine;

use Exception;
use PHPSocketIO\Engine\Transports\WebSocket;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Debug;

/**
 * Engine.IO 服务器类
 * 
 * 该类是Engine.IO协议的核心实现，负责处理客户端连接、握手、传输升级等功能。
 * 继承自Emitter，在客户端连接时会触发'connection'事件。
 * 
 * @package PHPSocketIO\Engine
 * @extends Emitter
 */
class Engine extends Emitter
{
    /**
     * 服务器实例
     * @var mixed
     */
    public $server;
    
    /**
     * Ping超时时间（秒）
     * @var int
     */
    public $pingTimeout = 60;
    
    /**
     * Ping间隔时间（秒）
     * @var int
     */
    public $pingInterval = 25;
    
    /**
     * 升级超时时间（秒）
     * @var int
     */
    public $upgradeTimeout = 5;
    
    /**
     * 支持的传输方式
     * @var array
     */
    public $transports = [];
    
    /**
     * 是否允许升级传输方式
     * @var array
     */
    public $allowUpgrades = [];
    
    /**
     * 请求验证回调
     * @var array
     */
    public $allowRequest = [];
    
    /**
     * 客户端连接列表
     * @var array
     */
    public $clients = [];
    
    /**
     * 允许的来源
     * @var string
     */
    public $origins = '*:*';
    
    /**
     * 允许的传输方式映射
     * @var array
     */
    public static $allowTransports = [
        'polling' => 'polling',
        'websocket' => 'websocket'
    ];

    /**
     * 错误消息列表
     * @var array
     */
    public static $errorMessages = [
        'Transport unknown',
        'Session ID unknown',
        'Bad handshake method',
        'Bad request'
    ];

    /**
     * 未知传输方式错误码
     * @const int
     */
    private const ERROR_UNKNOWN_TRANSPORT = 0;

    /**
     * 未知会话ID错误码
     * @const int
     */
    private const ERROR_UNKNOWN_SID = 1;

    /**
     * 握手方法错误码
     * @const int
     */
    private const ERROR_BAD_HANDSHAKE_METHOD = 2;

    /**
     * 请求错误码
     * @const int
     */
    private const ERROR_BAD_REQUEST = 3;

    /**
     * 构造函数
     * 
     * @param array $opts 配置选项
     */
    public function __construct($opts = [])
    {
        $ops_map = [
            'pingTimeout',
            'pingInterval',
            'upgradeTimeout',
            'transports',
            'allowUpgrades',
            'allowRequest'
        ];

        foreach ($ops_map as $key) {
            if (isset($opts[$key])) {
                $this->$key = $opts[$key];
            }
        }
        Debug::debug('Engine __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Engine __destruct');
    }

    /**
     * 处理HTTP请求
     * 
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @return void
     */
    public function handleRequest(object $req, object $res)
    {
        $this->prepare($req);
        $req->res = $res;
        $this->verify($req, $res, false, [$this, 'dealRequest']);
    }

    /**
     * 处理请求验证结果
     * 
     * @param mixed $err 错误码或null
     * @param bool $success 验证是否成功
     * @param object $req HTTP请求对象
     * @return void
     * @throws Exception
     */
    public function dealRequest($err, bool $success, object $req)
    {
        if (! $success) {
            self::sendErrorMessage($req, $req->res, $err);
            return;
        }

        if (isset($req->_query['sid'])) {
            $this->clients[$req->_query['sid']]->transport->onRequest($req);
        } else {
            $this->handshake($req->_query['transport'], $req);
        }
    }

    /**
     * 发送错误消息
     * 
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @param string $code 错误码
     * @return void
     */
    protected function sendErrorMessage(object $req, object $res, string $code): void
    {
        $headers = ['Content-Type' => 'application/json'];
        if (isset($req->headers['origin'])) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Access-Control-Allow-Origin'] = $req->headers['origin'];
        } else {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        $res->writeHead(403, '', $headers);
        $res->end(
            json_encode(
                [
                    'code' => $code,
                    'message' => self::$errorMessages[$code] ?? $code
                ]
            )
        );
    }

    /**
     * 验证请求
     * 
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @param bool $upgrade 是否为升级请求
     * @param callable $fn 回调函数
     * @return void
     */
    protected function verify(object $req, object $res, bool $upgrade, callable $fn)
    {
        if (! isset($req->_query['transport']) || ! isset(self::$allowTransports[$req->_query['transport']])) {
            return call_user_func($fn, self::ERROR_UNKNOWN_TRANSPORT, false, $req, $res);
        }
        $transport = $req->_query['transport'];
        $sid = $req->_query['sid'] ?? '';
        if ($sid) {
            if (! isset($this->clients[$sid])) {
                return call_user_func($fn, self::ERROR_UNKNOWN_SID, false, $req, $res);
            }
            if (! $upgrade && $this->clients[$sid]->transport->name !== $transport) {
                return call_user_func($fn, self::ERROR_BAD_REQUEST, false, $req, $res);
            }
        } else {
            if ('GET' !== $req->method) {
                return call_user_func($fn, self::ERROR_BAD_HANDSHAKE_METHOD, false, $req, $res);
            }
            return $this->checkRequest($req, $res, $fn);
        }
        call_user_func($fn, null, true, $req, $res);
    }

    /**
     * 检查请求来源是否允许
     * 
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @param callable $fn 回调函数
     * @return void
     */
    public function checkRequest(object $req, object $res, callable $fn)
    {
        if ($this->origins === "*:*" || empty($this->origins)) {
            return call_user_func($fn, null, true, $req, $res);
        }
        $origin = null;
        if (isset($req->headers['origin'])) {
            $origin = $req->headers['origin'];
        } elseif (isset($req->headers['referer'])) {
            $origin = $req->headers['referer'];
        }

        // file:// URLs produce a null Origin which can't be authorized via echo-back
        if ('null' === $origin || null === $origin) {
            return call_user_func($fn, null, true, $req, $res);
        }

        if ($origin) {
            $parts = parse_url($origin);
            $defaultPort = 'https:' === $parts['scheme'] ? 443 : 80;
            $parts['port'] = $parts['port'] ?? $defaultPort;
            $allowed_origins = explode(' ', $this->origins);
            foreach ($allowed_origins as $allow_origin) {
                $ok =
                    $allow_origin === $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] ||
                    $allow_origin === $parts['scheme'] . '://' . $parts['host'] ||
                    $allow_origin === $parts['scheme'] . '://' . $parts['host'] . ':*' ||
                    $allow_origin === '*:' . $parts['port'];
                if ($ok) {
                    return call_user_func($fn, null, true, $req, $res);
                }
            }
        }
        call_user_func($fn, null, false, $req, $res);
    }

    /**
     * 准备请求
     * 
     * @param object $req HTTP请求对象
     * @return void
     */
    protected function prepare(object $req)
    {
        if (! isset($req->_query)) {
            $info = parse_url($req->url);
            if (isset($info['query'])) {
                parse_str($info['query'], $req->_query);
            }
        }
    }

    /**
     * 处理客户端握手
     * 
     * @param string $transport 传输方式
     * @param object $req HTTP请求对象
     * @return void
     * @throws Exception
     */
    public function handshake(string $transport, object $req)
    {
        $id = bin2hex(pack('d', microtime(true)) . pack('N', function_exists('random_int') ? random_int(1, 100000000) : rand(1, 100000000)));
        if ($transport == 'websocket') {
            $transport = '\\PHPSocketIO\\Engine\\Transports\\WebSocket';
        } elseif (isset($req->_query['j'])) {
            $transport = '\\PHPSocketIO\\Engine\\Transports\\PollingJsonp';
        } else {
            $transport = '\\PHPSocketIO\\Engine\\Transports\\PollingXHR';
        }

        $transport = new $transport($req);

        $transport->supportsBinary = ! isset($req->_query['b64']);

        $socket = new Socket($id, $this, $transport, $req);

        $transport->onRequest($req);

        $this->clients[$id] = $socket;
        $socket->once('close', [$this, 'onSocketClose']);
        $this->emit('connection', $socket);
    }

    /**
     * 处理客户端断开连接
     * 
     * @param string $id 客户端ID
     * @return void
     */
    public function onSocketClose($id): void
    {
        unset($this->clients[$id]);
    }

    /**
     * 附加到Worker实例
     * 
     * @param mixed $worker Worker实例
     * @return void
     */
    public function attach($worker): void
    {
        $this->server = $worker;
        $worker->onConnect = [$this, 'onConnect'];
    }

    /**
     * 处理新连接
     * 
     * @param object $connection 连接对象
     * @return void
     */
    public function onConnect(object $connection): void
    {
        $connection->onRequest = [$this, 'handleRequest'];
        $connection->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        // 清理资源
        $connection->onClose = function ($connection) {
            if (! empty($connection->httpRequest)) {
                $connection->httpRequest->destroy();
                $connection->httpRequest = null;
            }
            if (! empty($connection->httpResponse)) {
                $connection->httpResponse->destroy();
                $connection->httpResponse = null;
            }
            if (! empty($connection->onRequest)) {
                $connection->onRequest = null;
            }
            if (! empty($connection->onWebSocketConnect)) {
                $connection->onWebSocketConnect = null;
            }
        };
    }

    /**
     * 处理WebSocket连接
     * 
     * @param mixed $connection 连接对象
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @return void
     */
    public function onWebSocketConnect($connection, object $req, object $res): void
    {
        $this->prepare($req);
        $this->verify($req, $res, true, [$this, 'dealWebSocketConnect']);
    }

    /**
     * 处理WebSocket连接验证结果
     * 
     * @param mixed $err 错误码或null
     * @param bool $success 验证是否成功
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @return void
     * @throws Exception
     */
    public function dealWebSocketConnect($err, bool $success, object $req, object $res): void
    {
        if (! $success) {
            self::sendErrorMessage($req, $res, $err);
            return;
        }

        if (isset($req->_query['sid'])) {
            if (! isset($this->clients[$req->_query['sid']])) {
                self::sendErrorMessage($req, $res, 'upgrade attempt for closed client');
                return;
            }
            $client = $this->clients[$req->_query['sid']];
            if ($client->upgrading) {
                self::sendErrorMessage($req, $res, 'transport has already been trying to upgrade');
                return;
            }
            if ($client->upgraded) {
                self::sendErrorMessage($req, $res, 'transport had already been upgraded');
                return;
            }
            $transport = new WebSocket($req);
            $client->maybeUpgrade($transport);
        } else {
            $this->handshake($req->_query['transport'], $req);
        }
    }
}
