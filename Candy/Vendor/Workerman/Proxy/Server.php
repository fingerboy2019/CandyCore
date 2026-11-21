<?php
/**
 * 代理服务器类
 * 
 * 基于 Workerman 实现的 HTTP/HTTPS 代理服务器：
 * - 支持 HTTP 转发（GET/POST/PUT/DELETE 等）与 HTTPS CONNECT 隧道
 * - 请求行半包缓冲（TCP 分包不再崩）
 * - 可选 Basic 鉴权（Proxy-Authorization），默认无鉴权 ⚠️ 开放代理风险请自行评估
 * 
 * @package Proxy
 */

namespace Proxy;

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

class Server extends Worker
{
    /**
     * Basic 鉴权配置 ['user' => ..., 'pass' => ...]，空则不做鉴权
     *
     * @var array
     */
    protected array $auth = [];

    /**
     * 构造函数
     * 
     * @param array $config 配置数组
     * @param string $config['address'] 监听地址和端口，默认: '0.0.0.0:8080'
     * @param string $config['name'] 服务器名称，默认: 'ProxyServer'
     * @param int $config['count'] 进程数，默认: 6
     * @param array $config['auth'] Basic 鉴权 ['user'=>..,'pass'=>..]，空=无鉴权
     * @return void
     */
    public function __construct($config = [])
    {
        $address = $config['address'] ?? '0.0.0.0:8080';
        parent::__construct("tcp://$address");
        $this->name = $config['name'] ?? 'ProxyServer';
        $this->count = $config['count'] ?? 6;
        $this->auth = $config['auth'] ?? [];
    }

    /**
     * 运行代理服务器实例（需配合 Worker::runAll() 启动主循环）
     *
     * @see Worker::runAll()
     * @return void
     */
    public function run(): void
    {
        $this->onMessage    = [$this, 'onMessage'];
        parent::run();
    }

    /**
     * 消息处理函数
     * 
     * 缓冲请求行半包 → 解析方法/目标 → 建立到目标的异步连接并双向转发
     * 
     * @param mixed $connection 客户端连接对象
     * @param string $buffer 客户端发送的数据
     * @return void
     */
    public function onMessage($connection, $buffer)
    {
        // 粘包缓冲：请求行/头部可能跨包到达
        $connection->buffer = ($connection->buffer ?? '') . $buffer;

        // 隧道已建立（CONNECT 或转发中）：剩余数据直接转发给目标
        if (!empty($connection->proxied)) {
            $connection->proxied->send($buffer);
            $connection->buffer = '';
            return;
        }

        $buffered = $connection->buffer;

        // 等待完整请求行（\r\n 结束）
        $lineEnd = strpos($buffered, "\r\n");
        if ($lineEnd === false) {
            if (strlen($buffered) > 8192) {
                $connection->close();  // 防恶意超长行
            }
            return;  // 半包：等待更多数据
        }

        $requestLine = substr($buffered, 0, $lineEnd);
        // CONNECT 请求的头部（请求行之后）不应转发——那是请求头，隧道数据在 200 响应之后才到达
        $rest = substr($buffered, $lineEnd + 2);

        // 请求行：METHOD SP target SP HTTP/version（防御畸形）
        $parts = explode(' ', $requestLine);
        if (count($parts) < 3) {
            $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n");
            $connection->close();
            return;
        }
        [$method, $target, $httpVersion] = $parts;

        // 解析目标地址
        $urlData = parse_url($target);
        $host = $urlData['host'] ?? '';
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN) && !filter_var($host, FILTER_VALIDATE_IP)) {
            $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n");
            $connection->close();
            return;
        }
        $isConnect = strtoupper($method) === 'CONNECT';
        $port = $urlData['port'] ?? ($isConnect ? 443 : 80);

        // 可选 Basic 鉴权
        if ($this->auth !== [] && !$this->checkAuth($connection, $buffered)) {
            return;  // checkAuth 已发 407
        }

        $remote = new AsyncTcpConnection("tcp://{$host}:{$port}");
        $remote->onConnect = function ($remote) use ($connection, $isConnect) {
            if ($isConnect) {
                // HTTPS 隧道：确认建立后丢弃 CONNECT 请求头（$rest），隧道数据由客户端在 200 后发送
                $connection->send("HTTP/1.1 200 Connection Established\r\n\r\n");
            } else {
                // HTTP 转发：整包（请求行 + 已到达的头部/请求体）透传
                $remote->send($connection->buffer);
            }
            $connection->buffer = '';
            $connection->proxied = $remote;
            $remote->proxied = $connection;
            // 手动双向转发（不用 Workerman pipe：AsyncTcpConnection 上 pipe 的
            // connection->onMessage 替换后数据未触发回调，改由 proxied 字段 + onMessage 转发）
            $remote->onMessage = function ($src, $data) use ($connection) {
                $connection->send($data);
            };
            $remote->onClose = function () use ($connection) {
                $connection->close();
            };
        };
        $remote->onError = function () use ($connection) {
            $connection->send("HTTP/1.1 502 Bad Gateway\r\n\r\n");
            $connection->close();
        };
        $remote->connect();
    }

    /**
     * 校验 Proxy-Authorization Basic 头
     *
     * @param mixed $connection 连接
     * @param string $buffer 已缓冲的请求数据
     * @return bool 通过返回 true；失败已回 407
     */
    protected function checkAuth($connection, string $buffer): bool
    {
        if (preg_match('/Proxy-Authorization:\s*Basic\s+([A-Za-z0-9+\/=]+)/i', $buffer, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false) {
                [$user, $pass] = array_pad(explode(':', $decoded, 2), 2, '');
                if (hash_equals((string) $this->auth['user'], $user) && hash_equals((string) $this->auth['pass'], $pass)) {
                    return true;
                }
            }
        }
        $connection->send("HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm=\"Proxy\"\r\n\r\n");
        $connection->close();
        return false;
    }
}
