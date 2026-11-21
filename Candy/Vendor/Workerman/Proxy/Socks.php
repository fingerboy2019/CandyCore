<?php
/**
 * Socks5服务器类
 * 
 * 该类基于Workerman实现了一个完整的Socks5代理服务器，支持TCP连接和UDP关联。
 * 支持多种认证方法，包括无认证和用户名密码认证。
 * 
 * @package Proxy
 */

namespace Proxy;

use \Workerman\{Worker,Timer};
use \Workerman\Connection\{AsyncTcpConnection,AsyncUdpConnection};
use \Candy\Core\Log;

/**
 * Socks5 协议阶段常量（命名空间级，避免污染全局）
 */
const STAGE_INIT = 0;
const STAGE_AUTH = 1;
const STAGE_ADDR = 2;
const STAGE_UDP_ASSOC = 3;
const STAGE_DNS = 4;
const STAGE_CONNECTING = 5;
const STAGE_STREAM = 6;
const STAGE_DESTROYED = -1;

const CMD_CONNECT = 1;
const CMD_BIND = 2;
const CMD_UDP_ASSOCIATE = 3;

const ERR_GENERAL = 1;
const ERR_NOT_ALLOW = 2;
const ERR_NETWORK = 3;
const ERR_HOST = 4;
const ERR_REFUSE = 5;
const ERR_TTL_EXPIRED = 6;
const ERR_UNKNOW_COMMAND = 7;
const ERR_UNKNOW_ADDR_TYPE = 8;
const ERR_UNKNOW = 9;

const ADDRTYPE_IPV4 = 1;
const ADDRTYPE_IPV6 = 4;
const ADDRTYPE_HOST = 3;

const METHOD_NO_AUTH = 0;
const METHOD_GSSAPI = 1;
const METHOD_USER_PASS = 2;

const LOG_DEBUG = 0;
const LOG_INFO = 1;
const LOG_ERR = 3;

class Socks extends Worker
{
    /**
     * 配置数组
     * @var array
     */
    public array $config = [];

    /**
     * UDP 中继 Worker（动态属性声明，PHP 8.2 兼容）
     *
     * @var Worker|null
     */
    protected $udpWorker = null;

    /**
     * 构造函数
     * 
     * 初始化Socks5服务器实例，设置监听地址、名称和进程数
     * 
     * @param array $config 配置数组
     * @param string $config['address'] 监听地址和端口，默认: '0.0.0.0:1234'
     * @param string $config['name'] 服务器名称，默认: 'Socks5Server'
     * @param int $config['count'] 进程数，默认: 1（UDP 关联需单进程保证状态一致）
     * @param array $config['auth'] 认证配置，默认: [METHOD_NO_AUTH => true]（⚠️ 开放代理，请显式配置认证）
     * @param int $config['udp_port'] UDP 关联端口，0=动态绑定，默认 0
     * @param string $config['wanIP'] UDP 关联对外通告 IP，默认 '0.0.0.0'
     * @return void
     */
    public function __construct($config = [])
    {
        // 构造必须保存配置（原实现丢失 $config，导致 auth/udp_port/wanIP 全部 undefined）
        $this->config = array_merge([
            'auth' => [METHOD_NO_AUTH => true],
            'udp_port' => 0,
            'wanIP' => '0.0.0.0',
        ], $config);
        if (!is_array($this->config['auth']) || $this->config['auth'] === []) {
            $this->config['auth'] = [METHOD_NO_AUTH => true];
        }

        $address = $config['address'] ?? '0.0.0.0:1234';
        parent::__construct("tcp://$address");
        $this->name = $config['name'] ?? 'Socks5Server';
        // UDP 关联（CMD_UDP_ASSOCIATE）依赖连接状态，多进程下回包可能落不同 worker；默认单进程
        $this->count = $config['count'] ?? 1;

        // UDP 中继 worker 必须在构造时创建（runAll 统一注册/fork）；
        // 原实现放在 run()（worker fork 后动态 new Worker），导致 worker 启动即崩
        $udpPort = (int) ($this->config['udp_port'] ?? 0);
        $udpListen = $this->config['udp_listen'] ?? ($udpPort > 0 ? "udp://0.0.0.0:{$udpPort}" : 'udp://0.0.0.0:1080');
        $this->udpWorker = new Worker($udpListen);
        $this->udpWorker->incId = 0;
        $this->udpWorker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->udpWorker->onMessage = [$this, 'udpWorkerOnMessage'];
    }

    /**
     * 运行Socks5服务器实例
     * 
     * 设置连接、消息和关闭事件的回调函数，并启动UDP Worker实例
     * 
     * @see Worker::run()
     * @return void
     */
    public function run(): void
    {
        $this->onConnect    = [$this, 'onConnect'];
        $this->onMessage    = [$this, 'onMessage'];
        $this->onClose      = [$this, 'onClose'];

        parent::run();
    }

    /**
     * 客户端连接事件处理
     * 
     * 初始化客户端连接的阶段和认证类型
     * 
     * @param mixed $connection 客户端连接对象
     * @return void
     */
    public function onConnect($connection)
    {
        $connection->stage = STAGE_INIT;
        $connection->auth_type = NULL;
    }
    
    /**
     * 客户端关闭事件处理
     * 
     * 记录客户端关闭日志
     * 
     * @param mixed $connection 客户端连接对象
     * @return void
     */
    public function onClose($connection)
    {
        Log::input('client closed.', LOG_INFO);
    }

    /**
     * 消息处理函数
     * 
     * 根据客户端连接的当前阶段处理不同类型的请求
     * 
     * @param mixed $connection 客户端连接对象
     * @param string $buffer 客户端发送的数据
     * @return void
     */
    public function onMessage($connection, $buffer) {
        try {
        // TCP 粘包/半包：累积到连接缓冲，按阶段解析完整包
        $connection->buffer = ($connection->buffer ?? '') . $buffer;
        $buffer = $connection->buffer;
        switch ($connection->stage) {
                // 初始化环节
            case STAGE_INIT:
                $request = [];
                // 当前偏移量
                $offset = 0;

                // 检测buffer长度
                if (strlen($buffer) < 2) {
                    // 半包：保留缓冲，等待更多数据
                    return;
                }

                // Socks5 版本
                $request['ver'] = ord($buffer[$offset]);
                $offset += 1;

                // 认证方法数量
                $request['method_count'] = ord($buffer[$offset]);
                $offset += 1;

                if (strlen($buffer) < 2 + $request['method_count']) {
                    // 半包：保留缓冲，等待更多数据
                    return;
                }

                // 客户端支持的认证方法
                $request['methods'] = [];
                for ($i = 1; $i <= $request['method_count']; $i++) {
                    $request['methods'][] = ord($buffer[$offset]);
                    $offset++;
                }

                foreach ($this->config['auth'] as $k => $v) {
                    if (in_array($k, $request['methods'])) {
                        Log::input("auth client via method $k", LOG_INFO);
                        Log::input("send:" . bin2hex("\x05" . chr($k)), LOG_DEBUG);
                        $connection->send("\x05" . chr($k));
                        if ($k == 0) {
                            $connection->stage = STAGE_ADDR;
                        } else {
                            $connection->stage = STAGE_AUTH;
                        }
                        $connection->auth_type = $k;
                        // 消费本阶段握手包（2 + 方法数），同包到达的后续数据（地址包等）递归处理
                        $connection->buffer = substr($buffer, 2 + $request['method_count']);
                        if ($connection->buffer !== '') {
                            $this->onMessage($connection, '');
                        }
                        return;
                    }
                }
                if ($connection->stage != STAGE_AUTH) {
                    Log::input("client has no matched auth methods", LOG_ERR);
                    Log::input("send:" . bin2hex("\x05\xff"), LOG_DEBUG);
                    $connection->send("\x05\xff");
                    $connection->stage = STAGE_DESTROYED;
                    $connection->close();
                }
                return;
                // 认证环节
            case STAGE_AUTH:

                $request = [];
                // 当前偏移量
                $offset = 0;

                if (strlen($buffer) < 5) {
                    // 半包：保留缓冲，等待更多数据
                    return;
                }

                // var_dump($connection->auth_type);
                switch ($connection->auth_type) {
                    case METHOD_USER_PASS:
                        //  子协议 协商 版本
                        $request['sub_ver'] = ord($buffer[$offset]);
                        $offset += 1;

                        // 用户名
                        $request['user_len'] = ord($buffer[$offset]);
                        $offset += 1;

                        if (strlen($buffer) < 2 + $request['user_len'] + 2) {
                            // 半包：保留缓冲，等待更多数据
                            return;
                        }

                        $request['user'] = substr($buffer, $offset, $request['user_len']);
                        $offset += $request['user_len'];

                        // 密码
                        $request['pass_len'] = ord($buffer[$offset]);
                        $offset += 1;


                        if (strlen($buffer) < 2 + $request['user_len'] + 1 + $request['pass_len']) {
                            // 半包：保留缓冲，等待更多数据
                            return;
                        }

                        $request['pass'] = substr($buffer, $offset, $request['pass_len']);
                        $offset += $request['pass_len'];

                        if ($this->config["auth"][METHOD_USER_PASS]($request)) {
                            Log::input("auth ok", LOG_INFO);
                            $connection->send("\x01\x00");
                            $connection->stage = STAGE_ADDR;
                            // 消费 auth 包（2 + user_len + 1 + pass_len），同包后续数据递归处理
                            $connection->buffer = substr($buffer, 2 + $request['user_len'] + 1 + $request['pass_len']);
                            if ($connection->buffer !== '') {
                                $this->onMessage($connection, '');
                            }
                        } else {
                            Log::input("auth failed", LOG_INFO);
                            $connection->send("\x01\x01");
                            $connection->stage = STAGE_DESTROYED;
                            $connection->close();
                        }
                        break;
                    default:
                        Log::input("unsupport auth type", LOG_ERR);
                        $connection->send("\x01\x01");
                        $connection->stage = STAGE_DESTROYED;
                        $connection->close();
                        break;
                }
                return;
            case STAGE_ADDR:
                $request = [];
                // 当前偏移量
                $offset = 0;

                if (strlen($buffer) < 4) {
                    // 半包：保留缓冲，等待更多数据
                    return;
                }

                // Socks 版本
                $request['ver'] = ord($buffer[$offset]);
                $offset += 1;

                // 命令
                $request['command'] = ord($buffer[$offset]);
                $offset += 1;

                // RSV
                $request['rsv'] = ord($buffer[$offset]);
                $offset += 1;

                // AddressType
                $request['addr_type'] = ord($buffer[$offset]);
                $offset += 1;

                // DestAddr
                switch ($request['addr_type']) {
                    case ADDRTYPE_IPV4:

                        if (strlen($buffer) < 4 + 4) {
                            // 半包：保留缓冲，等待更多数据
                            return;
                        }

                        $tmp =  substr($buffer, $offset, 4);
                        $ip = 0;
                        for ($i = 0; $i < 4; $i++) {
                            // var_dump(ord($tmp[$i]));
                            $ip += ord($tmp[$i]) * pow(256, 3 - $i);
                        }
                        $request['dest_addr'] = long2ip($ip);
                        $offset += 4;
                        break;

                    case ADDRTYPE_HOST:
                        $request['host_len'] = ord($buffer[$offset]);
                        $offset += 1;

                        if (strlen($buffer) < 4 + 1 + $request['host_len']) {
                            // 半包：保留缓冲，等待更多数据
                            return;
                        }

                        $request['dest_addr'] = substr($buffer, $offset, $request['host_len']);
                        $offset += $request['host_len'];
                        break;

                    case ADDRTYPE_IPV6:
                    default:
                        Log::input("unsupport ipv6. [ADDRTYPE_IPV6].", LOG_ERR);
                        $connection->stage = STAGE_DESTROYED;

                        $response = [];
                        $response['ver'] = 5;
                        $response['rep'] = ERR_UNKNOW_ADDR_TYPE;
                        $response['rsv'] = 0;
                        $response['addr_type'] = ADDRTYPE_IPV4;
                        $response['bind_addr'] = '0.0.0.0';
                        $response['bind_port'] = 0;

                        $connection->close($this->packResponse($response));
                        return;
                        break;
                }

                // DestPort

                if (strlen($buffer) < $offset + 2) {
                    // 半包：保留缓冲，等待更多数据
                    return;
                }
                $portData = unpack("n", substr($buffer, $offset, 2));
                $request['dest_port'] = $portData[1];
                $offset += 2;

                // var_dump($request);
                switch ($request['command']) {
                    case CMD_CONNECT:
                        Log::input('tcp://' . $request['dest_addr'] . ':' . $request['dest_port'], LOG_DEBUG);
                        if ($request['addr_type'] == ADDRTYPE_HOST) {
                            if (!filter_var($request['dest_addr'], FILTER_VALIDATE_IP)) {
                                Log::input('resolve DNS ' . $request['dest_addr'], LOG_DEBUG);
                                $connection->stage = STAGE_DNS;
                                $addr = dns_get_record($request['dest_addr'], DNS_A);
                                $addr = $addr ? array_pop($addr) : null;
                                Log::input('DNS resolved ' . $request['dest_addr'] . ' => ' . $addr['ip'], LOG_DEBUG);
                            } else {
                                $addr['ip'] = $request['dest_addr'];
                            }
                        } else {
                            $addr['ip'] = $request['dest_addr'];
                        }
                        if ($addr) {
                            $connection->stage = STAGE_CONNECTING;
                            // 注意：不清空 buffer——地址包后可能同包携带隧道数据，留给 onConnect 转发
                            $remote_connection = new AsyncTcpConnection('tcp://' . $addr['ip'] . ':' . $request['dest_port']);
                            $remote_connection->onConnect = function ($remote_connection) use ($connection, $request) {
                                $connection->stage = STAGE_STREAM;
                                $response = [];
                                $response['ver'] = 5;
                                $response['rep'] = 0;
                                $response['rsv'] = 0;
                                $response['addr_type'] = $request['addr_type'];
                                $response['bind_addr'] = '0.0.0.0';
                                $response['bind_port'] = 18512;

                                $connection->send($this->packResponse($response));
                                // 转发连接建立前已到达的隧道数据（地址包同包 / CONNECTING 阶段积压）
                                if (!empty($connection->buffer)) {
                                    $remote_connection->send($connection->buffer);
                                    $connection->buffer = '';
                                }
                                $connection->pipe($remote_connection);
                                $remote_connection->pipe($connection);
                                Log::input('tcp://' . $request['dest_addr'] . ':' . $request['dest_port'] . ' [OK]', LOG_DEBUG);
                            };
                            $remote_connection->connect();
                            return;
                        } else {
                            Log::input('DNS resolve failed.', LOG_DEBUG);
                            $connection->stage = STAGE_DESTROYED;

                            $response = [];
                            $response['ver'] = 5;
                            $response['rep'] = ERR_HOST;
                            $response['rsv'] = 0;
                            $response['addr_type'] = ADDRTYPE_IPV4;
                            $response['bind_addr'] = '0.0.0.0';
                            $response['bind_port'] = 0;

                            $connection->close($this->packResponse($response));
                        }
                        break;
                    case CMD_UDP_ASSOCIATE:
                        $connection->stage = STAGE_UDP_ASSOC;
                        if ((int) ($this->config['udp_port'] ?? 0) == 0) {
                            $connection->udpWorker = new Worker('udp://0.0.0.0:0');
                            $connection->udpWorker->incId = 0;
                            $connection->udpWorker->onMessage = function ($udp_connection, $data) use ($connection) {
                                $this->udpWorkerOnMessage($udp_connection, $data, $connection->udpWorker);
                            };
                            $connection->udpWorker->listen();
                            $listenInfo = stream_socket_get_name($connection->udpWorker->getMainSocket(), false);
                            [$bind_addr, $bind_port] = explode(":", $listenInfo);
                        } else {
                            $bind_port = (int) $this->config['udp_port'];
                        }
                        $bind_addr = $this->config['wanIP'] ?? '0.0.0.0';

                        $connection->buffer = '';  // UDP 关联后客户端改用 UDP 中继，TCP 缓冲可清
                        $response['ver'] = 5;
                        $response['rep'] = 0;
                        $response['rsv'] = 0;
                        $response['addr_type'] = ADDRTYPE_IPV4;
                        $response['bind_addr'] = $bind_addr;
                        $response['bind_port'] = $bind_port;

                        Log::input('send:' . bin2hex($this->packResponse($response)), LOG_DEBUG);
                        $connection->send($this->packResponse($response));
                        break;
                    default:
                        Log::input("connect init failed. unknow command.", LOG_ERR);
                        $connection->stage = STAGE_DESTROYED;

                        $response = [];
                        $response['ver'] = 5;
                        $response['rep'] = ERR_UNKNOW_COMMAND;
                        $response['rsv'] = 0;
                        $response['addr_type'] = ADDRTYPE_IPV4;
                        $response['bind_addr'] = '0.0.0.0';
                        $response['bind_port'] = 0;

                        $connection->close($this->packResponse($response));
                        return;
                        break;
                }
                // 连接建立期间（DNS/异步连接）：数据已累积在 buffer，onConnect 建立后转发
            case STAGE_DNS:
            case STAGE_CONNECTING:
                return;
        }
        } catch (\Throwable $e) {
            error_log('[Socks] onMessage: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $connection->close();
        }
    }

    /**
     * Worker启动事件处理
     * 
     * 初始化UDP连接数组，并设置定时清理过期连接的任务
     * 
     * @param mixed $worker Worker实例
     * @return void
     */
    public function onWorkerStart($worker) {
        $worker->udpConnections = [];
        Timer::add(1, function () use ($worker) {
            foreach ($worker->udpConnections as $id => $remote_connection) {
                if ($remote_connection->deadTime < time()) {
                    $remote_connection->close();
                    $remote_connection->udp_connection->close();
                    unset($worker->udpConnections[$id]);
                }
            }
        });
    }

    /**
     * UDP Worker消息处理函数
     * 
     * 处理UDP代理请求，建立远程UDP连接并转发数据
     * 
     * @param mixed $udp_connection UDP连接对象
     * @param string $data UDP数据
     * @param mixed $worker Worker实例
     * @return void
     */
    public function udpWorkerOnMessage($udp_connection, $data, &$worker)
    {

        Log::input('send:' . bin2hex($data), LOG_DEBUG);
        $request = [];
        $offset = 0;

        $request['rsv'] = substr($data, $offset, 2);
        $offset += 2;

        $request['frag'] = ord($data[$offset]);
        $offset += 1;

        $request['addr_type'] = ord($data[$offset]);
        $offset += 1;

        switch ($request['addr_type']) {
            case ADDRTYPE_IPV4:
                $tmp =  substr($data, $offset, 4);
                $ip = 0;
                for ($i = 0; $i < 4; $i++) {
                    $ip += ord($tmp[$i]) * pow(256, 3 - $i);
                }
                $request['dest_addr'] = long2ip($ip);
                $offset += 4;
                break;

            case ADDRTYPE_HOST:
                $request['host_len'] = ord($data[$offset]);
                $offset += 1;

                $request['dest_addr'] = substr($data, $offset, $request['host_len']);
                $offset += $request['host_len'];
                break;

            case ADDRTYPE_IPV6:
                if (strlen($data) < 22) {
                    $error = true;
                    break;
                }
                $error = true;  // IPv6 目标暂不支持
            default:
                $error = true;
        }

        if (!empty($error)) {
            return;  // 不支持的地址类型/畸形包，丢弃
        }

        $portData = unpack("n", substr($data, $offset, 2));
        $request['dest_port'] = $portData[1] ?? 0;
        $offset += 2;

        if ($request['addr_type'] == ADDRTYPE_HOST) {
            Log::input('解析DNS', LOG_DEBUG);
            $addr = dns_get_record($request['dest_addr'], DNS_A);
            $addr = $addr ? array_pop($addr) : null;
            if ($addr === null) {
                return;  // DNS 解析失败，丢弃
            }
            Log::input('DNS 解析完成' . $addr['ip'], LOG_DEBUG);
        } else {
            $addr['ip'] = $request['dest_addr'];
        }

        if (empty($addr['ip'])) {
            return;
        }

        $remote_connection = new AsyncUdpConnection('udp://' . $addr['ip'] . ':' . $request['dest_port']);
        $remote_connection->id = $worker->incId++;
        $remote_connection->udp_connection = $udp_connection;
        $remote_connection->onConnect = function ($remote_connection) use ($data, $offset) {
            $remote_connection->send(substr($data, $offset));
        };
        $remote_connection->onMessage = function ($remote_connection, $recv) use ($data, $offset, $udp_connection, $worker) {
            $udp_connection->close(substr($data, 0, $offset) . $recv);
            $remote_connection->close();
            unset($worker->udpConnections[$remote_connection->id]);
        };
        $remote_connection->deadTime = time() + 3;
        $remote_connection->connect();
        $worker->udpConnections[$remote_connection->id] = $remote_connection;
    }

    /**
     * 打包响应数据
     * 
     * 将响应数组格式化为Socks5协议的二进制数据
     * 
     * @param array $response 响应数组
     * @param int $response['ver'] Socks版本
     * @param int $response['rep'] 响应代码
     * @param int $response['rsv'] 保留字段
     * @param int $response['addr_type'] 地址类型
     * @param string $response['bind_addr'] 绑定地址
     * @param int $response['bind_port'] 绑定端口
     * @return string 打包后的二进制响应数据
     */
    public function packResponse($response)
    {
        $data = "";
        $data .= chr($response['ver']);
        $data .= chr($response['rep']);
        $data .= chr($response['rsv']);
        $data .= chr($response['addr_type']);

        switch ($response['addr_type']) {
            case ADDRTYPE_IPV4:
                $tmp = explode('.', $response['bind_addr']);
                foreach ($tmp as $block) {
                    $data .= chr($block);
                }
                break;
            case ADDRTYPE_HOST:
                $host_len = strlen($response['bind_addr']);
                $data .= chr($host_len);
                $data .= $response['bind_addr'];
                break;
        }

        $data .= pack("n", $response['bind_port']);
        return $data;
    }
}
