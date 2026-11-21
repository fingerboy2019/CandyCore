<?php

namespace PHPSocketIO\Engine\Protocols\Http;

/**
 * HTTP 请求类
 * 
 * 该类负责解析和存储HTTP请求的信息，包括请求方法、URL、HTTP版本、头部等。
 * 
 * @package PHPSocketIO\Engine\Protocols\Http
 */
class Request
{
    /**
     * 数据事件回调
     * @var callable|null
     */
    public $onData = null;

    /**
     * 请求结束事件回调
     * @var callable|null
     */
    public $onEnd = null;

    /**
     * 请求关闭事件回调
     * @var callable|null
     */
    public $onClose = null;

    /**
     * HTTP版本
     * @var string|null
     */
    public $httpVersion = null;

    /**
     * 请求头部数组（键名已转为小写）
     * @var array
     */
    public $headers = [];

    /**
     * 原始请求头部数组
     * @var array|null
     */
    public $rawHeaders = null;

    /**
     * 请求方法（GET, POST, PUT, DELETE等）
     * @var string|null
     */
    public $method = null;

    /**
     * 请求URL
     * @var string|null
     */
    public $url = null;

    /**
     * 连接对象
     * @var mixed
     */
    public $connection = null;

    /**
     * 查询参数
     * @var array|null
     */
    public $_query = null;

    /**
     * 响应对象
     * @var Response|null
     */
    public $res = null;

    /**
     * 清理回调
     * @var callable|null
     */
    public $cleanup = null;

    /**
     * 构造函数
     * 
     * @param mixed $connection 连接对象
     * @param string $raw_head 原始请求头部
     */
    public function __construct($connection, $raw_head)
    {
        $this->connection = $connection;
        $this->parseHead($raw_head);
    }

    /**
     * 解析请求头部
     * 
     * @param string $raw_head 原始请求头部
     * @return void
     */
    public function parseHead($raw_head)
    {
        $header_data = explode("\r\n", $raw_head);
        [$this->method, $this->url, $protocol] = explode(' ', $header_data[0]);
        [$null, $this->httpVersion] = explode('/', $protocol);
        unset($header_data[0]);
        foreach ($header_data as $content) {
            if (empty($content)) {
                continue;
            }
            $this->rawHeaders[] = $content;
            [$key, $value] = explode(':', $content, 2);
            $this->headers[strtolower($key)] = trim($value);
        }
    }

    /**
     * 销毁请求对象，释放资源
     * 
     * @return void
     */
    public function destroy()
    {
        $this->onData = $this->onEnd = $this->onClose = null;
        $this->connection = null;
    }
}
