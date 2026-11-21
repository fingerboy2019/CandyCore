<?php

namespace PHPSocketIO\Engine\Transports;

use Exception;
use PHPSocketIO\Debug;

/**
 * JSONP 轮询传输类
 *
 * 该类实现了基于 JSONP 的轮询传输机制，用于处理跨域请求
 * 或在不支持 CORS 的环境中使用。
 *
 * @package PHPSocketIO\Engine\Transports
 */
class PollingJsonp extends Polling
{
    /**
     * JSONP 回调头部
     * @var string|null
     */
    public $head = null;
    
    /**
     * JSONP 回调尾部
     * @var string
     */
    public $foot = ');';

    /**
     * 构造函数
     *
     * 初始化 JSONP 回调头部
     *
     * @param array $req 请求信息数组
     */
    public function __construct($req)
    {
        $this->head = '___eio[' . (isset($req['_query']['j']) ? preg_replace('/[^0-9]/', '', $req['_query']['j']) : '') . '](';
        Debug::debug('PollingJsonp __construct');
    }

    /**
     * 析构函数
     *
     * 用于调试，记录对象销毁日志
     */
    public function __destruct()
    {
        Debug::debug('PollingJsonp __destruct');
    }

    /**
     * 处理接收到的数据
     *
     * 解析 JSONP 格式的数据，提取实际内容并传递给父类处理
     *
     * @param string $data 接收到的 JSONP 格式数据
     * @return void
     */
    public function onData($data)
    {
        $parsed_data = null;
        parse_str($data, $parsed_data);
        $data = $parsed_data['d'];
        call_user_func([get_parent_class($this), 'onData'], preg_replace('/\\n/', '\n', $data));
    }

    /**
     * 执行写入操作
     *
     * 将数据包装成 JSONP 格式并发送给客户端
     *
     * @param string $data 要发送的数据
     * @return void
     */
    public function doWrite($data): void
    {
        $js = json_encode($data);

        $data = $this->head . $js . $this->foot;

        // explicit UTF-8 is required for pages not served under utf
        $headers = [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Content-Length' => strlen($data),
            'X-XSS-Protection' => '0'
        ];
        if (empty($this->res)) {
            echo new Exception('empty $this->res');
            return;
        }
        $this->res->writeHead(200, '', $this->headers($headers));
        $this->res->end($data);
    }

    /**
     * 获取响应头
     *
     * 触发 headers 事件并返回响应头数组
     *
     * @param array $headers 初始响应头数组
     * @return array 处理后的响应头数组
     */
    public function headers(array $headers = []): array
    {
        $listeners = $this->listeners('headers');
        foreach ($listeners as $listener) {
            $listener($headers);
        }
        return $headers;
    }
}
