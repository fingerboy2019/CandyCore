<?php

namespace PHPSocketIO\Engine\Transports;

use PHPSocketIO\Debug;

/**
 * XHR 轮询传输类
 *
 * 该类实现了基于 XMLHttpRequest (XHR) 的轮询传输机制，
 * 支持 CORS (跨域资源共享)，用于现代浏览器环境。
 *
 * @package PHPSocketIO\Engine\Transports
 */
class PollingXHR extends Polling
{
    /**
     * 会话ID
     * @var string|null
     */
    public $sid = null;

    /**
     * 构造函数
     *
     * 初始化 XHR 轮询传输实例，记录调试日志
     */
    public function __construct()
    {
        Debug::debug('PollingXHR __construct');
    }

    /**
     * 析构函数
     *
     * 用于调试，记录对象销毁日志
     */
    public function __destruct()
    {
        Debug::debug('PollingXHR __destruct');
    }

    /**
     * 处理HTTP请求
     *
     * 处理客户端的HTTP请求，包括OPTIONS预检请求用于CORS支持
     *
     * @param object $req HTTP请求对象
     * @return void
     */
    public function onRequest($req)
    {
        if ('OPTIONS' === $req->method) {
            $res = $req->res;
            $headers = $this->headers($req);
            $headers['Access-Control-Allow-Headers'] = 'Content-Type';
            $res->writeHead(200, '', $headers);
            $res->end();
        } else {
            parent::onRequest($req);
        }
    }

    /**
     * 执行写入操作
     *
     * 将数据写入到响应中，根据数据类型设置合适的Content-Type
     *
     * @param string $data 要发送的数据
     * @return void
     */
    public function doWrite($data)
    {
        // explicit UTF-8 is required for pages not served under utf todo
        $content_type = preg_match('/^\d+:/', $data) ? 'text/plain; charset=UTF-8' : 'application/octet-stream';
        $content_length = strlen($data);
        $headers = [
            'Content-Type' => $content_type,
            'Content-Length' => $content_length,
            'X-XSS-Protection' => '0',
        ];
        if (empty($this->res)) {
            echo new \Exception('empty this->res');
            return;
        }
        $this->res->writeHead(200, '', $this->headers($this->req, $headers));
        $this->res->end($data);
    }

    /**
     * 获取响应头
     *
     * 设置CORS相关响应头，并触发headers事件
     *
     * @param object $req HTTP请求对象
     * @param array $headers 初始响应头数组
     * @return array 处理后的响应头数组
     */
    public function headers(object $req, array $headers = []): array
    {
        if (isset($req->headers['origin'])) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Access-Control-Allow-Origin'] = $req->headers['origin'];
        } else {
            $headers['Access-Control-Allow-Origin'] = '*';
        }
        $listeners = $this->listeners('headers');
        foreach ($listeners as $listener) {
            $listener($headers);
        }
        return $headers;
    }
}
