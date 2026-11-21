<?php
namespace Socket;

use \Workerman\Worker;
use \Workerman\Connection\TcpConnection;
use \Workerman\Protocols\Http\Request;

/**
 * Socket HTTP 服务器类
 * 
 * 该类继承自 Workerman\Worker，负责处理 HTTP 请求，
 * 包括初始化 HTTP 服务、处理请求消息等。
 * 
 * @package Socket
 * @extends Worker
 */
class Http extends Worker
{
    /**
     * 内部通信地址
     * @var string
     */
    protected $internalAddress;

    /**
     * 构造函数
     * 
     * @param array $config 配置数组，包含 HTTP 服务器的配置信息
     *                      - internal: 内部通信地址
     *                      - address: 监听地址，格式为 "0.0.0.0:8088"
     *                      - ssl: SSL配置
     *                      - name: 进程名称
     */
    public function __construct(array $config = [])
    {
        $this->internalAddress = $config['internal'] ??= '127.0.0.1:7273';
        // 创建 HTTP 服务
        $address = $config['address'] ??= '0.0.0.0:8088';
        $context = [];
        if (isset($config['ssl'])) {
            $context = [
                'ssl' => [
                    // 请使用绝对路径
                    'local_cert'                => $config['ssl']['local_cert'], // 也可以是crt文件
                    'local_pk'                  => $config['ssl']['local_pk'],
                    'verify_peer'               => false,
                    'allow_self_signed'         => isset($config['ssl']['allow_self_signed']) ? $config['ssl']['allow_self_signed'] : false, // 如果是自签名证书需要开启此选项
                ]
            ];
        }
        parent::__construct('http://' . $address, $context);
        $this->name = $config['name'] ??= 'WsHttpServer';
    }

    /**
     * 运行 HTTP 服务器实例
     *
     * @see Workerman\Worker::run()
     * @return void
     */
    public function run(): void
    {
        $this->onMessage = [$this, 'onMessage']; // 设置消息处理回调
        parent::run();
    }

    /**
     * 处理 HTTP 请求
     * 
     * @param TcpConnection $connection TCP连接对象
     * @param Request $request HTTP请求对象
     * @return void
     */
    public function onMessage(TcpConnection $connection, Request $request)
    {
        $client = stream_socket_client($this->internalAddress);
        if (!$client) {
            exit("无法连接到内部服务");
        }
        // 推送的数据，包含uid字段，表示是给这个uid推送
        $path = substr($request->path(), 1);
        $param = array_merge($request->post(), $request->get());
        $data['api'] = $path;
        $data['app'] = "agent";
        $data['ver'] = 1;
        $data['data'] = $param;
        fwrite($client, json_encode($data) . "\n");
        // 读取推送结果
        $msg = fread($client, 8192);
        $response = json_decode($msg, true);
        $response = $response['data'];
        // $request为请求对象，这里没有对请求对象执行任何操作直接返回结果给浏览器
        $connection->send($msg);
    }
}
