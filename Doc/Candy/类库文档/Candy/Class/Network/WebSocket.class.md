# WebSocket 类

## 概述

* Candy框架 WebSocket客户端连接类
 * 
 * 提供完整的WebSocket协议实现，支持标准WebSocket连接、数据收发、心跳检测和连接管理。
 * 实现了RFC 6455标准的WebSocket协议，支持文本帧、二进制帧、ping/pong心跳和连接关闭等功能。
 * 
 * 主要特性：
 * - 支持ws://和wss://(SSL)协议
 * - 自动WebSocket握手处理
 * - 数据帧的打包与解包
 * - 心跳检测(ping/pong)
 * - 连接异常处理与关闭
 * - 支持文本和二进制数据传输
 * 
 * 使用示例：
 * ```php
 * // 创建WebSocket连接
 * $ws = new \Candy\Extend\Network\WebSocket('ws://echo.websocket.org');
 * 
 * // 发送文本消息
 * $ws->send('Hello WebSocket!');
 * 
 * // 接收消息
 * $response = $ws->getRecv();
 * echo $resp

## 主要方法


### /**
 * Candy框架 WebSocket客户端连接类
 * 
 * 提供完整的WebSocket协议实现，支持标准WebSocket连接、数据收发、心跳检测和连接管理。
 * 实现了RFC 6455标准的WebSocket协议，支持文本帧、二进制帧、ping/pong心跳和连接关闭等功能。
 * 
 * 主要特性：
 * - 支持ws://和wss://(SSL)协议
 * - 自动WebSocket握手处理
 * - 数据帧的打包与解包
 * - 心跳检测(ping/pong)
 * - 连接异常处理与关闭
 * - 支持文本和二进制数据传输
 * 
 * 使用示例：
 * ```php
 * // 创建WebSocket连接
 * $ws = new \Candy\Extend\Network\WebSocket('ws://echo.websocket.org');
 * 
 * // 发送文本消息
 * $ws->send('Hello WebSocket!');
 * 
 * // 接收消息
 * $response = $ws->getRecv();
 * echo $response;
 * 
 * // 关闭连接
 * $ws->close();
 * ```
 * 
 * @package Candy\Extend\Network
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-04-17 10:46:16
 * @final 此类不可被继承
 */

declare(strict_types=1);
namespace Candy\Extend\Network;
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class WebSocket {
    /**
     * WebSocket协议标识
     */
    const PROTOCOL_WS = 'ws';
    
    /**
     * 安全WebSocket协议标识（使用TLS加密）
     */
    const PROTOCOL_WSS = 'wss';
    
    /**
     * HTTP头部字段分隔符
     */
    const HTTP_HEADER_SEPARATION_MARK = "\r\n";
    
    /**
     * HTTP头部结束标记
     */
    const HTTP_HEADER_END_MARK = "\r\n\r\n";
    
    /**
     * WebSocket握手过程中使用的固定UUID值
     * 
     * 符合RFC 6455标准，用于生成Sec-WebSocket-Accept值
     */
    const UUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    /**
     * 数据包大小，单位：字节
     * 
     * 用于控制socket读取的数据块大小，默认值为32KB (1 << 15)
     */
    const PACKET_SIZE = (1 << 15);
    
    /**
     * 后续帧操作码
     * 
     * 用于标识一个非首帧的延续帧，用于处理分片消息的后续部分
     */
    const OPCODE_CONTINUATION_FRAME = 0;
    
    /**
     * 文本帧操作码
     * 
     * 用于传输UTF-8编码的文本数据
     */
    const OPCODE_TEXT_FRAME = 1;
    
    /**
     * 二进制帧操作码
     * 
     * 用于传输二进制数据
     */
    const OPCODE_BINARY_FRAME = 2;
    
    /**
     * 关闭连接帧操作码
     * 
     * 用于请求关闭WebSocket连接，可携带关闭状态码和原因
     */
    const OPCODE_CLOSE = 8;
    
    /**
     * Ping帧操作码
     * 
     * 用于心跳检测，服务器必须以Pong帧响应
     */
    const OPCODE_PING = 9;
    
    /**
     * Pong帧操作码
     * 
     * 响应Ping帧的心跳响应帧
     */
    const OPCODE_PONG = 10;
    
    /**
     * 帧长度第一级最大值
     * 
     * 根据RFC 6455，当负载长度≤125字节时，直接在第二个字节中编码长度
     */
    const FRAME_LENGTH_LEVEL_1_MAX = 125;
    
    /**
     * 帧长度第二级最大值
     * 
     * 根据RFC 6455，当负载长度在126-65535字节之间时，使用2字节扩展长度格式
     */
    const FRAME_LENGTH_LEVEL_2_MAX = 65535;
    private $protocol;
    private $host;
    private $port;
    private $path;
    private $sock;
    private $secWebSocketKey;
    private $handshakePass = false;
    private $readBuf;
    private $currentReadBufLen;
    private $readBufPos;
    private $closed = false;



    /**
     * WebSocket客户端构造函数
     * 
     * 初始化WebSocket连接，解析URI并建立与服务器的连接
     * 
     * @param string $wsUri WebSocket服务器的URI地址，支持ws://和wss://协议
     * @param float $connectTimeout 连接服务器的超时时间（秒），默认1.0秒
     * @param float $rwTimeout 读写数据的超时时间（秒），默认5.0秒
     * 
     * @example
     * ```php
     * // 创建WebSocket连接
     * $ws = new \Candy\Extend\Network\WebSocket('ws://echo.websocket.org', 2.0, 10.0);
     * ```
     */
    public function __construct($wsUri,float $connectTimeout = 1.0,float $rwTimeout = 5.0) {
        $this->parseUri($wsUri);
        $this->connect($connectTimeout, $rwTimeout);
        $this->handshake();
        $this->initReadBuf();
    }

    /**
      * 解析WebSocket连接URI地址
      * 
      * 解析WebSocket URI，提取协议、主机、端口和路径等信息
      * 
      * @param string $wsUri WebSocket服务器的URI地址，格式为ws:()


### /**
     * 连接WebSocket服务器
     * 
     * 创建并初始化socket连接，根据协议类型选择tcp或ssl连接方式
     * 
     * @param float $timeout 连接服务器的超时时间（秒）
     * @param float $rwTimeout 读写数据的超时时间（秒）
     */
    private function connect(float $timeout,float $rwTimeout):()


### /**
     * 设置socket的读写超时时间
     * 
     * 配置底层socket连接的读写超时参数，支持毫秒级精度
     * 
     * @param float $seconds 超时时间（秒），支持小数表示毫秒
     */
    private function setSockTimeout(float $seconds):()


### /**
     * 向WebSocket服务器发送原始数据
     * 
     * 将数据写入底层socket连接，如果数据长度超过包大小限制，会自动分片发送
     * 
     * @param string $data 要发送的原始数据
     * 
     * @example
     * ```php
     * // 发送原始数据（通常不直接调用，由send等方法内部调用）
     * $ws->writeToSock('原始数据内容');
     * ```
     */
    public function writeToSock(string $data = ''):()


### /**
     * 向socket连接写入指定长度的字节
     * 
     * 确保所有数据都被完整写入socket连接，处理可能的写入不完整情况
     * 
     * @param string $str 要写入的数据内容
     */
    private function writeN(string $str):()


### /**
     * 生成WebSocket握手所需的随机密钥
     * 
     * 创建符合WebSocket协议要求的Sec-WebSocket-Key值，用于握手过程中的身份验证
     * 
     * @return string 返回Base64编码的随机密钥
     */
    protected static function generateWsKey() {
        return base64_encode(md5(uniqid() . mt_rand(1, 8192), true));
    }

    /**
     * 执行WebSocket握手过程
     * 
     * 按照RFC 6455标准执行WebSocket握手，发送握手请求并验证服务器响应
     */
    protected function handshake():()


### /**
     * 打包WebSocket数据帧
     * 
     * 将数据按照WebSocket协议格式打包成帧，支持各种帧类型和长度处理
     * 
     * @param int $opCode 帧类型，支持以下值：
     *                    - WebSocket::OPCODE_TEXT_FRAME: 文本帧
     *                    - WebSocket::OPCODE_BINARY_FRAME: 二进制帧
     *                    - WebSocket::OPCODE_CLOSE: 关闭帧
     *                    - WebSocket::OPCODE_PING: ping帧
     *                    - WebSocket::OPCODE_PONG: pong帧
     * @param string $payload 帧携带的数据内容
     * @param bool $isMask 是否使用掩码，客户端发送的帧必须使用掩码
     * @param int $status 关闭帧的状态码，默认1000（正常关闭）
     * 
     * @return string 返回打包后的WebSocket帧二进制数据
     */
    protected function packFrame(int $opCode,string $payload = '',bool $isMask = true, $status = 1000):()


### /**
     * 向WebSocket服务器发送ping请求进行心跳检测
     * 
     * 发送ping帧并等待pong响应，用于检测连接是否活跃
     * 
     * @return bool 成功接收到pong响应返回true，否则返回false
     * 
     * @example
     * ```php
     * // 心跳检测，如果返回false表示连接可能已断开
     * if (!$ws->ping()) {
     *     echo '连接可能已断开，需要重新连接';
     * }
     * ```
     */
    public function ping():()


### /**
     * 响应WebSocket服务器的ping请求
     * 
     * 发送pong帧回应服务器的ping请求，维持连接活跃状态
     * 
     * @example
     * ```php
     * // 收到ping后主动发送pong响应（通常由recv方法内部自动调用）
     * $ws->pong();
     * ```
     */
    public function pong():()


### /**
     * 主动关闭与WebSocket服务器的连接
     * 
     * 发送关闭帧并等待服务器确认，然后关闭底层socket连接
     * 
     * @return bool 成功接收到服务器关闭确认返回true，否则返回false
     * 
     * @example
     * ```php
     * // 主动关闭WebSocket连接
     * if ($ws->close()) {
     *     echo '连接已成功关闭';
     * }
     * ```
     */
    public function close():()

