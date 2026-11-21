<?php
/**
 * 转发隧道客户端
 *
 * 连接 PortForward 转发服务器（或任意 TCP 服务），在业务代码里经隧道读写数据。
 * 同步阻塞式（stream_socket_client），适合 CLI 脚本 / 短连接场景；
 * Workerman 进程内建议用短连接或自行基于 AsyncTcpConnection。
 *
 * 使用示例：
 * ```php
 * $client = new \Proxy\Client('127.0.0.1:18090');
 * $client->write("PING\r\n");
 * $resp = $client->readLine();
 * $client->close();
 * ```
 *
 * @package Proxy
 */
namespace Proxy;

class Client
{
    /** 连接资源 */
    protected $conn = null;

    /** 连接超时（秒） */
    protected int $timeout;

    /**
     * 构造函数并连接
     *
     * @param string $address 目标地址 host:port
     * @param int $timeout 连接/读写超时秒数，默认 10
     * @throws \RuntimeException 连接失败
     */
    public function __construct(string $address, int $timeout = 10)
    {
        $this->timeout = $timeout;
        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client(
            "tcp://{$address}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$conn) {
            throw new \RuntimeException("Proxy\\Client connect failed: ({$errno}) {$errstr}");
        }
        stream_set_timeout($conn, $timeout);
        $this->conn = $conn;
    }

    /**
     * 发送数据
     *
     * @param string $data 数据
     * @return int 写入字节数
     * @throws \RuntimeException 写入失败
     */
    public function write(string $data): int
    {
        if (!is_resource($this->conn)) {
            throw new \RuntimeException('Proxy\\Client not connected');
        }
        $len = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = @fwrite($this->conn, substr($data, $sent));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Proxy\\Client write failed (connection lost)');
            }
            $sent += $n;
        }
        return $sent;
    }

    /**
     * 读取指定字节数（可跨多次到达，超时返回已读部分）
     *
     * @param int $length 要读取的字节数
     * @return string 已读取数据（可能少于请求长度）
     */
    public function read(int $length = 8192): string
    {
        if (!is_resource($this->conn)) {
            return '';
        }
        $data = @fread($this->conn, $length);
        return $data === false ? '' : $data;
    }

    /**
     * 读取一行（\n 结束）
     *
     * @param int $maxLen 最大行长度，默认 4096
     * @return string 行内容（不含换行）；超时/EOF 返回已读部分
     */
    public function readLine(int $maxLen = 4096): string
    {
        $line = '';
        while (strlen($line) < $maxLen) {
            $chunk = @fgets($this->conn, $maxLen - strlen($line) + 1);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $line .= $chunk;
            if (str_ends_with($line, "\n")) {
                break;
            }
        }
        return rtrim($line, "\r\n");
    }

    /**
     * 读取直到目标关闭（返回全部已读数据）
     *
     * @param int $chunkSize 每次读取块大小
     * @return string
     */
    public function readAll(int $chunkSize = 8192): string
    {
        $data = '';
        while (is_resource($this->conn) && !feof($this->conn)) {
            $chunk = @fread($this->conn, $chunkSize);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            if (strlen($data) > 16 * 1024 * 1024) {
                break;  // 防内存膨胀
            }
        }
        return $data;
    }

    /**
     * 关闭连接
     *
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->conn)) {
            @fclose($this->conn);
        }
        $this->conn = null;
    }

    /**
     * 析构自动关闭
     */
    public function __destruct()
    {
        $this->close();
    }
}
