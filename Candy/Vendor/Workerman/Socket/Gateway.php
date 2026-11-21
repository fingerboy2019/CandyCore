<?php
namespace Socket;

use Workerman\Connection\TcpConnection;
use \GatewayWorker\Gateway as baseGateway;

/**
 * Socket 网关类
 * 
 * 该类继承自 GatewayWorker\Gateway，负责处理 Socket 连接的网关功能，
 * 包括初始化网关配置、处理连接事件等。
 * 
 * @package Socket
 * @extends baseGateway
 */
class Gateway extends baseGateway
{
    /**
     * 允许的请求源，用于跨域验证
     * @var string
     */
    protected $origin;

    /**
     * 构造函数
     * 
     * @param array $config 配置数组，包含网关的配置信息
     *                      - origin: 允许的请求源
     *                      - address: 监听地址，格式为 "websocket://0.0.0.0:8282"
     *                      - ssl: SSL配置
     *                      - name: 进程名称
     *                      - lanIp: 局域网IP
     *                      - startPort: 起始端口
     *                      - register: 注册中心地址
     *                      - pingInterval: 心跳间隔
     *                      - pingNotResponseLimit: 心跳无响应次数上限
     *                      - pingData: 心跳数据
     */
    public function __construct(array $config = [])
    {
        $this->origin = $config['origin'] ??= '';
        // gateway 进程，默认使用 websocket 协议
        $gatewayAddress = $config['address'] ??= 'websocket://0.0.0.0:8282';
        $context = [];
        if (isset($config['ssl'])) {
            $context = [
                // 更多ssl选项请参考手册 http://php.net/manual/zh/context.ssl.php
                'ssl' => [
                    // 请使用绝对路径
                    'local_cert'                => $config['ssl']['local_cert'], // 也可以是crt文件
                    'local_pk'                  => $config['ssl']['local_pk'],
                    'verify_peer'               => false,
                    'allow_self_signed'         => $config['ssl']['allow_self_signed'] ??= false, // 如果是自签名证书需要开启此选项
                ]
            ];
        }
        parent::__construct($gatewayAddress, $context);
        if (empty($context) === false) {
            $this->transport = 'ssl'; // 启用SSL传输
        }
        $this->name = isset($config['name']) ? $config['name'] : 'Gateway';
        $this->count = 4; // 网关进程数量
        $this->lanIp = $config['lanIp'] ??= '127.0.0.1';
        $this->startPort = $config['startPort'] ??= 2900;
        $this->registerAddress = $config['register'] ??= '127.0.0.1:1238';
        $this->pingInterval = $config['pingInterval'] ??= 55; // 心跳间隔，单位秒
        $this->pingNotResponseLimit = $config['pingNotResponseLimit'] ??= 1; // 心跳无响应次数上限
        $this->pingData = $config['pingData'] ??= '{"type":"ping"}'; // 心跳数据
    }

    /**
     * 运行网关实例
     *
     * @see Workerman\Worker::run()
     * @return void
     */
    public function run(): void
    {
        $this->onConnect = [$this, 'onConnect']; // 设置连接事件回调
        parent::run();
    }

    /**
     * 连接事件处理函数
     * 
     * @param TcpConnection $connection TCP连接对象
     * @return void
     */
    public function onConnect(TcpConnection $connection)
    {
        $connection->__onWebSocketConnect = $connection->onWebSocketConnect;
        $connection->onWebSocketConnect = function($connection , $http_header)
        {
            // 可以在这里判断连接来源是否合法，不合法就关掉连接
            // $_SERVER['HTTP_ORIGIN']标识来自哪个站点的页面发起的websocket链接
            if (!empty($this->origin) && $_SERVER['HTTP_ORIGIN'] != $this->origin) {
                $connection->close();
            }
            // onWebSocketConnect 里面$_GET $_SERVER是可用的
            $connection->__onWebSocketConnect;
            //var_dump($_GET, $_SERVER);
        };
    }
}
