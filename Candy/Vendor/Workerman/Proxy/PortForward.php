<?php
/**
 * TCP 端口转发服务器
 *
 * 监听本地端口，把客户端连接双向转发到固定目标地址（内网服务暴露/跳板场景）。
 * 复用手动双向转发（proxied + onMessage），不依赖 Workerman pipe（AsyncTcpConnection 上不可靠）。
 *
 * 使用示例：
 * ```php
 * $forward = new \Proxy\PortForward([
 *     'address' => '0.0.0.0:18090',   // 监听端口
 *     'target'  => 'tcp://10.0.0.132:3306',  // 转发目标
 *     'count'   => 4,
 * ]);
 * Worker::runAll();
 * ```
 *
 * @package Proxy
 */
namespace Proxy;

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

class PortForward extends Worker
{
    /**
     * 目标地址（tcp://host:port）
     *
     * @var string
     */
    protected string $target = '';

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     * @param string $config['address'] 监听地址和端口，默认 '0.0.0.0:18090'
     * @param string $config['target'] 转发目标，必填，如 'tcp://10.0.0.132:3306'
     * @param string $config['name'] 服务器名称，默认 'PortForward'
     * @param int $config['count'] 进程数，默认 4
     * @return void
     */
    public function __construct($config = [])
    {
        if (empty($config['target'])) {
            throw new \InvalidArgumentException('PortForward: target is required (e.g. tcp://10.0.0.132:3306)');
        }
        $this->target = $config['target'];

        $address = $config['address'] ?? '0.0.0.0:18090';
        parent::__construct("tcp://$address");
        $this->name = $config['name'] ?? 'PortForward';
        $this->count = $config['count'] ?? 4;

        $this->onConnect = [$this, 'onConnect'];
        $this->onMessage = [$this, 'onMessage'];
        $this->onClose = [$this, 'onClose'];
    }

    /**
     * 运行转发服务器（需配合 Worker::runAll() 启动主循环）
     *
     * @return void
     */
    public function run(): void
    {
        parent::run();
    }

    /**
     * 客户端连接：异步连接目标，建立后双向转发
     *
     * @param mixed $connection 客户端连接
     * @return void
     */
    public function onConnect($connection)
    {
        $remote = new AsyncTcpConnection($this->target);
        $connection->proxied = $remote;
        $remote->proxied = $connection;

        $remote->onConnect = function ($remote) use ($connection) {
            // 转发客户端在目标连接建立前积压的数据
            if (!empty($connection->buffer)) {
                $remote->send($connection->buffer);
                $connection->buffer = '';
            }
            // 目标 -> 客户端
            $remote->onMessage = function ($src, $data) use ($connection) {
                $connection->send($data);
            };
            $remote->onClose = function () use ($connection) {
                $connection->close();
            };
        };
        $remote->onError = function () use ($connection) {
            $connection->close();
        };
        $remote->connect();
    }

    /**
     * 客户端数据：转发到目标（目标未连接时先缓冲）
     *
     * @param mixed $connection 客户端连接
     * @param string $buffer 数据
     * @return void
     */
    public function onMessage($connection, $buffer)
    {
        $remote = $connection->proxied ?? null;
        if ($remote !== null && $remote->getStatus() === \Workerman\Connection\TcpConnection::STATUS_ESTABLISHED) {
            $remote->send($buffer);
        } else {
            // 目标连接尚未建立：缓冲等待
            $connection->buffer = ($connection->buffer ?? '') . $buffer;
            if (strlen($connection->buffer) > 8 * 1024 * 1024) {
                $connection->close();  // 防缓冲膨胀
            }
        }
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
