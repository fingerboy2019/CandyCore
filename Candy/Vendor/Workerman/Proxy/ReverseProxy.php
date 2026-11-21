<?php
/**
 * HTTP 反向代理服务器
 *
 * 接收 HTTP 请求，按 Host 头（或配置的通配域名）路由到对应后端并透传。
 * 半包缓冲、请求行/头部校验、手动双向转发。
 *
 * 配置示例：
 * ```php
 * $proxy = new \Proxy\ReverseProxy([
 *     'address' => '0.0.0.0:18092',
 *     'routes' => [
 *         'app.example.com'   => 'tcp://10.0.0.5:8008',   // Candy 应用
 *         'api.example.com'   => 'tcp://10.0.0.6:8009',
 *         '*.ai.example.com'  => 'tcp://10.0.0.7:11434',  // 通配子域
 *         'default'           => 'tcp://10.0.0.5:8008',   // 未匹配时兜底
 *     ],
 * ]);
 * Worker::runAll();
 * ```
 *
 * @package Proxy
 */
namespace Proxy;

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Connection\TcpConnection;

class ReverseProxy extends Worker
{
    /**
     * 路由表：host => tcp://backend，'default' 兜底
     *
     * @var array
     */
    protected array $routes = [];

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     * @param string $config['address'] 监听地址和端口，默认 '0.0.0.0:18092'
     * @param string $config['name'] 服务器名称，默认 'ReverseProxy'
     * @param int $config['count'] 进程数，默认 4
     * @param array $config['routes'] 路由表（必填）
     */
    public function __construct($config = [])
    {
        if (empty($config['routes'])) {
            throw new \InvalidArgumentException('ReverseProxy: routes is required');
        }
        $this->routes = $config['routes'];

        $address = $config['address'] ?? '0.0.0.0:18092';
        parent::__construct("tcp://$address");
        $this->name = $config['name'] ?? 'ReverseProxy';
        $this->count = $config['count'] ?? 4;

        $this->onMessage = [$this, 'onMessage'];
        $this->onClose = [$this, 'onClose'];
    }

    /**
     * 运行服务器（需配合 Worker::runAll()）
     *
     * @return void
     */
    public function run(): void
    {
        parent::run();
    }

    /**
     * 消息处理：缓冲请求 → 解析 Host → 路由到后端 → 透传
     *
     * @param mixed $connection 客户端连接
     * @param string $data 数据
     * @return void
     */
    public function onMessage($connection, $data)
    {
        // 隧道已建立：直接转发
        if (!empty($connection->proxied)) {
            $connection->proxied->send($data);
            return;
        }

        $connection->buffer = ($connection->buffer ?? '') . $data;
        $buffered = $connection->buffer;

        // 等完整头部（\r\n\r\n）
        $headEnd = strpos($buffered, "\r\n\r\n");
        if ($headEnd === false) {
            if (strlen($buffered) > 8192) {
                $connection->close();
            }
            return;  // 半包等待
        }

        // 请求行
        $lineEnd = strpos($buffered, "\r\n");
        if ($lineEnd === false) {
            $connection->close();
            return;
        }
        $parts = explode(' ', substr($buffered, 0, $lineEnd));
        if (count($parts) < 3) {
            $this->badRequest($connection);
            return;
        }

        // Host 头（含端口剥离）
        $host = '';
        if (preg_match('/^Host:\s*([^\s\r\n]+)/mi', $buffered, $m)) {
            $host = strtolower(explode(':', $m[1], 2)[0]);
        }
        if ($host === '') {
            $this->badRequest($connection);
            return;
        }

        // 路由匹配：精确 → 通配 *. 子域 → default
        $backend = $this->routes[$host] ?? null;
        if ($backend === null) {
            $parts2 = explode('.', $host);
            while (count($parts2) > 1) {
                $wild = '*.' . implode('.', array_slice($parts2, 1));
                if (isset($this->routes[$wild])) {
                    $backend = $this->routes[$wild];
                    break;
                }
                array_shift($parts2);
            }
        }
        if ($backend === null) {
            $backend = $this->routes['default'] ?? null;
        }
        if ($backend === null) {
            $this->badRequest($connection);
            return;
        }

        // 建立到后端的连接并透传整个请求
        $remote = new AsyncTcpConnection($backend);
        $connection->proxied = $remote;
        $remote->proxied = $connection;

        $remote->onConnect = function ($remote) use ($connection) {
            $remote->send($connection->buffer);
            $connection->buffer = '';
            $remote->onMessage = function ($src, $data) use ($connection) {
                $connection->send($data);
            };
            $remote->onClose = function () use ($connection) {
                $connection->close();
            };
        };
        $remote->onError = function () use ($connection) {
            $this->badRequest($connection);
        };
        $remote->connect();
    }

    /**
     * 返回 400 并关闭
     *
     * @param mixed $connection 客户端连接
     * @return void
     */
    protected function badRequest($connection): void
    {
        $connection->send("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");
        $connection->close();
    }

    /**
     * 客户端关闭：关闭后端连接
     *
     * @param mixed $connection 客户端连接
     * @return void
     */
    public function onClose($connection)
    {
        $remote = $connection->proxied ?? null;
        if ($remote !== null) {
            $remote->close();
        }
    }
}
