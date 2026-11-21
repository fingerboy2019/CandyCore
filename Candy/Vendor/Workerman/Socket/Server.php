<?php
namespace Socket;

use \Workerman\Worker;
use \GatewayWorker\Register;
use \GatewayWorker\Gateway as Gway;


/**
 * Socket 服务器类
 * 
 * 该类负责初始化和启动各种 Workerman 进程，包括 Business、Register、Gateway 和 Http 进程，
 * 用于构建基于 Workerman 的 Socket 服务器。
 * 
 * @package Socket
 */
class Server
{
    /**
     * 构造函数
     * 
     * @param array $config 配置数组，包含各进程的配置信息
     *                      - bussiness: Business 进程配置
     *                      - register: Register 进程配置
     *                      - gateway: Gateway 进程配置
     *                      - http: Http 进程配置
     *                      - internal: 内部 Gateway 进程配置
     */
    public function __construct(array $config = [])
    {
        // 创建log目录
        \Candy\Extend\Dir::create(C('LOGPATH'));
                        
        // 自定义记录文件地址
        Worker::$logFile = C('LOGPATH').'/workerman.log';
        Worker::$pidFile = C('LOGPATH').'/workerman.pid';
        Worker::$stdoutFile = C('LOGPATH').'/workerman.log';

        // BusinessWorker 进程，处理业务逻辑
        $bussinessConfig = isset($config['bussiness']) ? $config['bussiness'] : [];
        $bussinessWorker = new Business($bussinessConfig);

        // Register 进程，必须使用 text 协议
        $registerConfig = isset($config['register']) ? $config['register'] : [];
        $registerAddress = $registerConfig['address'] ??= '0.0.0.0:1238';
        $register = new Register('text://'.$registerAddress);

        // Gateway 进程，使用 Text 协议，可以用 telnet 测试
        $gatewayConfig = isset($config['gateway']) ? $config['gateway'] : [];
        $gateway = new Gateway($gatewayConfig);

        // Http 进程，处理 Http 请求
        $httpConfig = isset($config['http']) ? $config['http'] : [];
        $http = new Http($gatewayConfig);

        // 内部 Gateway 进程，用于内部通信
        $internalConfig = isset($config['internal'])? $config['internal'] : [];
        $internalAddress = $internalConfig['address'] ??= '127.0.0.1:7273';
        $internal_gateway = new Gway("Text://". $internalAddress);
        $internal_gateway->name= $internalConfig['name'] ??= 'internalGateway';
        $internal_gateway->startPort = $internalConfig['startPort'] ??= 3300;
        $internal_gateway->registerAddress = $internalConfig['register'] ??= '127.0.0.1:1238';
        
        // 运行所有 Worker 进程
        Worker::runAll();
    }
}
