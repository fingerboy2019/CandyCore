<?php

namespace PHPSocketIO\Engine\Protocols\Http;

use Exception;

/**
 * HTTP 响应类
 * 
 * 该类负责构建和发送HTTP响应，包括状态行、响应头和响应体。
 * 
 * @package PHPSocketIO\Engine\Protocols\Http
 */
class Response
{
    /**
     * HTTP状态码
     * @var int
     */
    public $statusCode = 200;

    /**
     * HTTP状态短语
     * @var string|null
     */
    protected $_statusPhrase = null;

    /**
     * 连接对象
     * @var mixed
     */
    protected $_connection = null;

    /**
     * 响应头数组
     * @var array
     */
    protected $_headers = [];

    /**
     * 是否已发送响应头
     * @var bool
     */
    public $headersSent = false;

    /**
     * 是否可写入
     * @var bool
     */
    public $writable = true;

    /**
     * 响应缓冲
     * @var string
     */
    protected $_buffer = '';

    /**
     * HTTP状态码到状态短语的映射
     * @var array
     */
    public static $codes = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => '(Unused)',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    /**
     * 构造函数
     * 
     * @param mixed $connection 连接对象
     */
    public function __construct($connection)
    {
        $this->_connection = $connection;
    }

    /**
     * 初始化响应头
     * 
     * @return void
     */
    protected function initHeader()
    {
        $this->_headers['Connection'] = 'keep-alive';
        $this->_headers['Content-Type'] = 'Content-Type: text/html;charset=utf-8';
    }

    /**
     * 写入响应头
     * 
     * @param int $status_code HTTP状态码
     * @param string $reason_phrase HTTP状态短语
     * @param array|null $headers 响应头数组
     * @return bool|void 返回false表示响应头已发送，否则返回void
     */
    public function writeHead($status_code, $reason_phrase = '', $headers = null)
    {
        if ($this->headersSent) {
            echo "header has already send\n";
            return false;
        }
        $this->statusCode = $status_code;
        if ($reason_phrase) {
            $this->_statusPhrase = $reason_phrase;
        }
        if ($headers) {
            foreach ($headers as $key => $val) {
                $this->_headers[$key] = $val;
            }
        }
        $this->_buffer = $this->getHeadBuffer();
        $this->headersSent = true;
    }

    /**
     * 获取响应头缓冲
     * 
     * @return string 返回响应头缓冲
     */
    public function getHeadBuffer(): string
    {
        if (! $this->_statusPhrase) {
            $this->_statusPhrase = self::$codes[$this->statusCode] ?? '';
        }
        $head_buffer = "HTTP/1.1 $this->statusCode $this->_statusPhrase\r\n";
        if (! isset($this->_headers['Content-Length']) && ! isset($this->_headers['Transfer-Encoding'])) {
            $head_buffer .= "Transfer-Encoding: chunked\r\n";
        }
        if (! isset($this->_headers['Connection'])) {
            $head_buffer .= "Connection: keep-alive\r\n";
        }
        foreach ($this->_headers as $key => $val) {
            if ($key === 'Set-Cookie' && is_array($val)) {
                foreach ($val as $v) {
                    $head_buffer .= "Set-Cookie: $v\r\n";
                }
                continue;
            }
            $head_buffer .= "$key: $val\r\n";
        }
        return $head_buffer . "\r\n";
    }

    /**
     * 设置响应头
     * 
     * @param string $key 响应头名称
     * @param string|array $val 响应头值
     * @return void
     */
    public function setHeader($key, $val)
    {
        $this->_headers[$key] = $val;
    }

    /**
     * 获取响应头
     * 
     * @param string $name 响应头名称
     * @return string 响应头值
     */
    public function getHeader($name)
    {
        return $this->_headers[$name] ?? '';
    }

    /**
     * 移除响应头
     * 
     * @param string $name 响应头名称
     * @return void
     */
    public function removeHeader($name)
    {
        unset($this->_headers[$name]);
    }

    /**
     * 写入响应体
     * 
     * @param string $chunk 响应体数据块
     * @return void
     */
    public function write($chunk)
    {
        if (! isset($this->_headers['Content-Length'])) {
            $chunk = dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }
        if (! $this->headersSent) {
            $head_buffer = $this->getHeadBuffer();
            $this->_buffer = $head_buffer . $chunk;
            $this->headersSent = true;
        } else {
            $this->_buffer .= $chunk;
        }
    }

    /**
     * 结束响应
     * 
     * @param string|null $data 响应体数据
     * @return bool|mixed 返回发送结果，失败返回false
     */
    public function end($data = null)
    {
        if (! $this->writable) {
            echo new Exception('unwirtable');
            return false;
        }
        if ($data !== null) {
            $this->write($data);
        }

        if (! $this->headersSent) {
            $head_buffer = $this->getHeadBuffer();
            $this->_buffer = $head_buffer;
            $this->headersSent = true;
        }

        if (! isset($this->_headers['Content-Length'])) {
            $ret = $this->_connection->send($this->_buffer . "0\r\n\r\n", true);
            $this->destroy();
            return $ret;
        }
        $ret = $this->_connection->send($this->_buffer, true);
        $this->destroy();
        return $ret;
    }

    /**
     * 销毁响应对象，释放资源
     * 
     * @return void
     */
    public function destroy()
    {
        if (! empty($this->_connection->httpRequest)) {
            $this->_connection->httpRequest->destroy();
        }
        if (! empty($this->_connection)) {
            $this->_connection->httpResponse = $this->_connection->httpRequest = null;
        }
        $this->_connection = null;
        $this->writable = false;
    }
}
