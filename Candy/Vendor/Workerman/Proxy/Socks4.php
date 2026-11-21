<?php
/**
 * SOCKS4 / SOCKS4a 代理服务器
 *
 * 支持 CONNECT（IPv4 与 SOCKS4a 域名），无认证（SOCKS4 无认证机制）。
 * 粘包/半包缓冲处理，手动双向转发（proxied + onMessage）。
 *
 * 使用示例：
 * ```php
 * $socks4 = new \Proxy\Socks4(['address' => '0.0.0.0:1081', 'count' => 4]);
 * Worker::runAll();
 * ```
 *
 * @package Proxy
 */
namespace Proxy;

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Connection\TcpConnection;

class Socks4 extends Worker
{
    /** 命令：CONNECT */
    private const CMD_CONNECT = 1;

    /** 响应码 */
    private const REP_GRANTED = 0x5A;
    private const REP_REJECTED = 0x5B;

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     * @param string $config['address'] 监听地址和端口，默认 '0.0.0.0:1081'
     * @param string $config['name'] 服务器名称，默认 'Socks4Server'
     * @param int $config['count'] 进程数，默认 4
     */
    public function __construct($config = [])
    {
        $address = $config['address'] ?? '0.0.0.0:1081';
        parent::__construct("tcp://$address");
        $this->name = $config['name'] ?? 'Socks4Server';
        $this->count = $config['count'] ?? 4;

        $this->onConnect = [$this, 'onConnect'];
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
     * 连接建立：初始化阶段
     *
     * @param mixed $connection 客户端连接
     * @return void
     */
    public function onConnect($connection)
    {
        $connection->stage = 0;
        $connection->buffer = '';
    }

    /**
     * 消息处理：解析握手（含粘包/半包）
     *
     * @param mixed $connection 客户端连接
     * @param string $data 数据
     * @return void
     */
    public function onMessage($connection, $data)
    {
        $connection->buffer .= $data;
        $buffer = $connection->buffer;

        // 隧道已建立：数据直接转发
        if (!empty($connection->proxied)) {
            $connection->proxied->send($data);
            $connection->buffer = '';
            return;
        }

        // 最小握手：VER(1) CMD(1) DSTPORT(2) DSTIP(4) [USERID\0] （>=9 字节）
        if (strlen($buffer) < 8) {
            return;  // 半包等待
        }
        $ver = ord($buffer[0]);
        $cmd = ord($buffer[1]);
        if ($ver !== 4) {
            $this->reject($connection);
            return;
        }
        if ($cmd !== self::CMD_CONNECT) {
            $this->reject($connection);
            return;
        }

        $port = unpack('n', substr($buffer, 2, 2))[1];
        $ip = long2ip(unpack('N', substr($buffer, 4, 4))[1]);

        // 找 USERID 结束的 \x00（SOCKS4a 域名在 USERID 之后）
        $nullPos = strpos($buffer, "\x00", 8);
        if ($nullPos === false) {
            return;  // USERID 未完整，等待
        }
        $useridEnd = $nullPos + 1;  // 包含 \x00

        // SOCKS4a：DSTIP 形如 0.0.0.x（x!=0）时，\x00 后跟域名
        $target = null;
        if (str_starts_with($ip, '0.0.0.') && substr($ip, 7) !== '0') {
            // 域名：USERID\0 之后到下一个 \x00
            $domainEnd = strpos($buffer, "\x00", $useridEnd);
            if ($domainEnd === false) {
                return;  // 域名未完整
            }
            $domain = substr($buffer, $useridEnd, $domainEnd - $useridEnd);
            if ($domain === '') {
                $this->reject($connection);
                return;
            }
            $target = ['host' => $domain, 'port' => $port];
            $consumed = $domainEnd + 1;
        } else {
            $target = ['host' => $ip, 'port' => $port];
            $consumed = $useridEnd;
        }

        $this->connectTarget($connection, $target, $consumed);
    }

    /**
     * 连接目标并建立隧道
     *
     * @param mixed $connection 客户端连接
     * @param array $target 目标 ['host'=>, 'port'=>]
     * @param int $consumed 已消费的握手字节数
     * @return void
     */
    protected function connectTarget($connection, array $target, int $consumed): void
    {
        // 剩余数据（握手后同包到达的隧道数据）
        $remainder = substr($connection->buffer, $consumed);
        $connection->buffer = $remainder;

        $remote = new AsyncTcpConnection("tcp://{$target['host']}:{$target['port']}");
        $connection->proxied = $remote;
        $remote->proxied = $connection;

        $remote->onConnect = function ($remote) use ($connection) {
            // SOCKS4 成功响应：\x00\x5A + 绑定地址/端口（回显目标信息，客户端通常忽略）
            $connection->send("\x00\x5A\x00\x00\x00\x00\x00\x00");
            // 转发握手后同包到达的隧道数据
            if ($connection->buffer !== '') {
                $remote->send($connection->buffer);
                $connection->buffer = '';
            }
            $remote->onMessage = function ($src, $data) use ($connection) {
                $connection->send($data);
            };
            $remote->onClose = function () use ($connection) {
                $connection->close();
            };
        };
        $remote->onError = function () use ($connection) {
            $this->reject($connection);
        };
        $remote->connect();
    }

    /**
     * 拒绝连接（SOCKS4 失败响应并关闭）
     *
     * @param mixed $connection 客户端连接
     * @return void
     */
    protected function reject($connection): void
    {
        $connection->send("\x00" . chr(self::REP_REJECTED) . "\x00\x00\x00\x00\x00\x00");
        $connection->close();
    }

    /**
     * 客户端关闭：关闭目标连接
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
