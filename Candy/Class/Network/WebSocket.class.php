<?php
/**
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
      * @param string $wsUri WebSocket服务器的URI地址，格式为ws://host:port/path或wss://host:port/path
      */
     private function parseUri(string $wsUri): void
    {
        $uriData = parse_url($wsUri);
        if (!$uriData) {
            Debug::showMessage('不正确的ws uri格式');
        }
        if ($uriData['scheme'] != self::PROTOCOL_WS && $uriData['scheme'] != self::PROTOCOL_WSS) {
            Debug::showMessage('ws的uri必须是以ws://或wss://开头');
        }
        
        $this->protocol = $uriData['scheme'];
        $this->host = $uriData['host'];
        if ($uriData['port']) {
            $this->port = (int)$uriData['port'];
        } else {
            if ($this->protocol == self::PROTOCOL_WSS) {
                $this->port = 443;
            } else {
                $this->port = 80;
            }
        }
        $this->path = !empty($uriData['path']) ?: '/';
        if (!empty($uriData['query'])) {
            $this->path .= '?' . $uriData['query'];
        }
        if (!empty($uriData['fragment'])) {
            $this->path .= '#' . $uriData['fragment'];
        }
    }

    /**
     * 连接WebSocket服务器
     * 
     * 创建并初始化socket连接，根据协议类型选择tcp或ssl连接方式
     * 
     * @param float $timeout 连接服务器的超时时间（秒）
     * @param float $rwTimeout 读写数据的超时时间（秒）
     */
    private function connect(float $timeout,float $rwTimeout): void
    {
        $errno = $errstr = null;
        $this->sock = stream_socket_client(($this->protocol == self::PROTOCOL_WSS ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port, $errno, $errstr, $timeout);
        if (!$this->sock) {
            if ($errstr) {
                Debug::showMessage('连接ws服务器失败：' . $errstr);
            }
            Debug::showMessage('连接ws服务器失败: 未知错误。');
        }
        $this->setSockTimeout($rwTimeout);
    }

    /**
     * 设置socket的读写超时时间
     * 
     * 配置底层socket连接的读写超时参数，支持毫秒级精度
     * 
     * @param float $seconds 超时时间（秒），支持小数表示毫秒
     */
    private function setSockTimeout(float $seconds): void
    {
        if (strpos((string)$seconds, '.') !== false) {
            $original = (string)$seconds;
            $seconds = (int)$seconds;
            $microseconds = bcmul($original, '1000000', 0) - ($seconds * 1000000);
        } else {
            $microseconds = 0;
        }
        stream_set_timeout($this->sock, (int)$seconds, $microseconds);
    }

    /**
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
    public function writeToSock(string $data = ''): void
    {
        if(is_null($data) || $data === '') {}else{
            if ($this->closed) {
                Debug::showMessage('连接已关闭, 不允许再发送消息');
            }
            $dataLen = strlen($data);
            if ($dataLen > self::PACKET_SIZE) {
                $dataPieces = str_split($data, self::PACKET_SIZE);
                foreach ($dataPieces as $piece) {
                    $this->writeN($piece);
                }
            } else {
                $this->writeN($data);
            }
        }
    }

    /**
     * 向socket连接写入指定长度的字节
     * 
     * 确保所有数据都被完整写入socket连接，处理可能的写入不完整情况
     * 
     * @param string $str 要写入的数据内容
     */
    private function writeN(string $str): void
    {
        if ($this->closed) {
            Debug::showMessage('连接已关闭, 不允许再发送消息');
        }
        $len = strlen($str);
        $writeLen = 0;
        do {
            if ($writeLen > 0) {
                $str = substr($str, $writeLen);
            }
            $n = fwrite($this->sock, $str);
            if ($n === false) {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out']) {
                    Debug::showMessage('向服务器发送数据超时');
                }
                Debug::showMessage('无法发送数据，socket连接已断开？');
            }
            $writeLen += $n;
        } while ($writeLen < $len);
    }

    /**
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
    protected function handshake(): void
    {
        $this->secWebSocketKey = self::generateWsKey();
        $headers = [
            'GET ' . $this->path . ' HTTP/1.1',
            'Host: ' . $this->host . ':' . $this->port,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $this->secWebSocketKey,
            'Sec-WebSocket-Version: 13',
        ];
        $htmlHeader = implode(self::HTTP_HEADER_SEPARATION_MARK, $headers) . self::HTTP_HEADER_END_MARK;
        $this->writeToSock($htmlHeader);
        $response = '';
        $end = false;
        do {
            $str = fread($this->sock, 8192);
            if (strlen($str) == 0) {
                break;
            }
            $response .= $str;
            $end = strpos($response, self::HTTP_HEADER_END_MARK);
        } while ($end === false);
        if ($end === false) {
            Debug::showMessage('握手失败：握手响应不是标准的http响应');
        }
        $resHeader = substr($response, 0, $end);
        $headers = explode(self::HTTP_HEADER_SEPARATION_MARK, $resHeader);
        if (strpos($headers[0], '101') === false) {
            Debug::showMessage('握手失败：服务器返回http状态码不是101');
        }
        for ($i = 1; $i < count($headers); $i++) {
            [$key, $val] = explode(':', $headers[$i]);
            if (strtolower(trim($key)) == 'sec-websocket-accept') {
                $accept = base64_encode(sha1($this->secWebSocketKey . self::UUID, true));
                if (trim($val) != $accept) {
                    Debug::showMessage('无握手失败： sec-websocket-accept值校验失败？');
                }
                $this->handshakePass = true;
                break;
            }
        }
        if (!$this->handshakePass) {
            Debug::showMessage('握手失败：缺少sec-websocket-accept http头');
        }
    }

    /**
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
    protected function packFrame(int $opCode,string $payload = '',bool $isMask = true, $status = 1000): mixed
    {
        $firstByte = 0x80 | $opCode;
        if ($isMask) {
            $secondByte = 0x80;
        } else {
            $secondByte = 0x00;
        }
        $payloadLen = strlen($payload);
        if ($opCode == self::OPCODE_CLOSE) {
            // 协议规定关闭帧必须使用掩码
            $isMask = true;
            $payload = pack('CC', (($status >> 8) & 0xff), $status & 0xff) . $payload;
            $payloadLen += 2;
        }
        if ($payloadLen <= self::FRAME_LENGTH_LEVEL_1_MAX) {
            $secondByte |= $payloadLen;
            $frame = pack('CC', $firstByte, $secondByte);
        } elseif ($payloadLen <= self::FRAME_LENGTH_LEVEL_2_MAX) {
            $secondByte |= 126;
            $frame = pack('CCn', $firstByte, $secondByte, $payloadLen);
        } else {
            $secondByte |= 127;
            $frame = pack('CCJ', $firstByte, $secondByte, $payloadLen);
        }
        if ($isMask) {
            $maskBytes = [mt_rand(1, 255), mt_rand(1, 255), mt_rand(1, 255), mt_rand(1, 255)];
            $frame .= pack('CCCC', $maskBytes[0], $maskBytes[1], $maskBytes[2], $maskBytes[3]);
            if ($payloadLen > 0) {
                for ($i = 0; $i < $payloadLen; $i++) {
                    $payload[$i] = chr(ord($payload[$i]) ^ $maskBytes[$i % 4]);
                }
            }
        }
        $frame .= $payload;
        return $frame;
    }
    
    /**
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
    public function ping(): bool
    {
        try {
            $frame = $this->packFrame(self::OPCODE_PING, '', true);
            $this->writeToSock($frame);
            do {
                $pong = $this->recv();
                if ($pong['opcode'] == self::OPCODE_PONG) {
                    return true;
                }
            } while ($pong['opcode'] != self::OPCODE_PONG);
        } catch (\Exception $e) {
            Debug::showMessage($e->getMessage());
        }
        return false;
    }

    /**
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
    public function pong(): void
    {
        $frame = $this->packFrame(self::OPCODE_PONG, '', true);
        $this->writeToSock($frame);
    }

    /**
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
    public function close(): bool
    {
        $frame = $this->packFrame(self::OPCODE_CLOSE, '', true, 1000);
        try {
            $this->writeToSock($frame);
            // 主动关闭需要再接收一次对端返回的确认消息
            $wsData = $this->recv();
            if ($wsData['opcode'] == self::OPCODE_CLOSE) {
                return true;
            }
        } catch (\Throwable $e) {
        } finally {
            $this->closed = true;
            stream_socket_shutdown($this->sock, STREAM_SHUT_RDWR);
        }
        return false;
    }

    /**
     * 异常关闭WebSocket连接
     * 
     * 当ping失败或服务器响应异常时，直接关闭底层socket资源，不进行正常的关闭握手过程
     * 
     * @example
     * ```php
     * // 在异常情况下强制关闭连接
     * try {
     *     // 某些操作
     * } catch (\Exception $e) {
     *     $ws->abnormalClose();
     * }
     * ```
     */
    public function abnormalClose(): void
    {
        if (!$this->closed && $this->sock) {
            $this->closed = true;
            try {
                stream_socket_shutdown($this->sock, STREAM_SHUT_RDWR);
            } catch (\Throwable $e) {}
        }
    }

    /**
     * 响应WebSocket服务器的关闭消息
     * 
     * 当收到服务器的关闭帧时，发送关闭确认帧并关闭连接
     */
    protected function replyClosure(): void
    {
        $frame = $this->packFrame(self::OPCODE_CLOSE, '', true, 1000);
        $this->writeToSock($frame);
        $this->closed = true;
        stream_socket_shutdown($this->sock, STREAM_SHUT_RDWR);
    }

    /**
     * 向WebSocket服务器发送数据
     * 
     * 根据WebSocket协议规范，将数据打包成标准帧格式并发送
     * 
     * @param string $data 要发送的数据内容
     * @param int $opCode 操作码，默认为文本帧(OPCODE_TEXT_FRAME)
     * @param bool $isMask 是否使用掩码，默认为true（客户端必须使用掩码）
     * @return bool 发送成功返回true，失败返回false
     * 
     * @example
     * ```php
     * // 发送文本消息
     * $ws->send('Hello WebSocket!');
     * 
     * // 发送二进制数据
     * $binaryData = pack('N', 12345); // 打包一个32位整数
     * $ws->send($binaryData, WebSocket::OPCODE_BINARY_FRAME);
     * ```
     */
    public function send(string $data, int $opCode = self::OPCODE_TEXT_FRAME, bool $isMask = true): bool
    {
        if ($opCode != self::OPCODE_TEXT_FRAME && $opCode != self::OPCODE_BINARY_FRAME) {
            Debug::showMessage('不支持的帧数据类型');
        }
        $frame = $this->packFrame($opCode, $data, $isMask);
        $this->writeToSock($frame);
        return true;
    }

    /**
     * 初始化数据接收缓冲区
     * 
     * 设置接收缓冲区的初始状态，包括清空缓冲区和重置指针位置
     */
    private function initReadBuf(): void
    {
        $this->readBuf = '';
        $this->currentReadBufLen = 0;
        $this->readBufPos = 0;
    }

    /**
     * 从读取缓冲区获取指定长度的字符串
     * 
     * 从当前缓冲区位置读取指定长度的数据，如果数据不足则自动从socket读取更多数据
     * 
     * @param int $len 要读取的字符串长度
     * 
     * @return string 返回从缓冲区读取的字符串
     */
    private function fetchStrFromReadBuf(int $len = 1): mixed
    {
        $target = $this->readBufPos + $len;
        while ($target > $this->currentReadBufLen) {
            if ($this->closed) {
                Debug::showMessage('连接已关闭, 不允许再收取消息');
            }
            $read = fread($this->sock, self::PACKET_SIZE);
            if (!$read) {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out']) {
                    Debug::showMessage('读取服务器数据超时');
                }
                Debug::showMessage('无法读取服务器数据，错误未知');
            }
            $this->readBuf .= $read;
            $this->currentReadBufLen += strlen($read);
        }
        $str = substr($this->readBuf, $this->readBufPos, $len);
        $this->readBufPos += $len;
        return $str;
    }

    /**
     * 获取读取缓冲区当前位置字符的ASCII码
     * 
     * 从缓冲区当前位置读取一个字符并返回其ASCII值
     * 
     * @return int 返回字符的ASCII码值
     */
    private function fetchCharFromReadBuf(): int
    {
        $str = $this->fetchStrFromReadBuf(1);
        return ord($str[0]);
    }

    /**
     * 丢弃读取缓冲区中已处理的数据
     * 
     * 从缓冲区开头移除指定长度的已处理数据，优化内存使用
     * 
     * @param int $len 要丢弃的数据长度
     */
    private function discardReadBuf(int $len): void
    {
        // 未处理的数据不会被丢弃
        if ($len > $this->readBufPos) {
            $len = $this->readBufPos;
        }
        if ($len > 0) {
            $this->readBuf = substr($this->readBuf, $len);
            $this->readBufPos -= $len;
            $this->currentReadBufLen -= $len;
        }
    }

    /**
     * 从WebSocket服务器接收数据帧
     * 
     * 接收并解析WebSocket服务器发送的数据帧，自动处理ping/pong和关闭帧
     * 
     * @return array 返回数据帧信息数组，包含以下键值：
     *               - opcode: 帧类型
     *               - fin: 是否为最终帧
     *               - status: 关闭帧的状态码（仅关闭帧有效）
     *               - payload: 帧携带的有效数据
     * 
     * @example
     * ```php
     * // 接收数据帧
     * $frame = $ws->recv();
     * if ($frame['opcode'] == WebSocket::OPCODE_TEXT_FRAME) {
     *     echo '收到文本消息: ' . $frame['payload'];
     * }
     * ```
     */
    public function recv(): array
    {
        $dataFrame = $this->readFrame();
        match ($dataFrame['opcode']) {
            self::OPCODE_PING => $this->pong(),
            self::OPCODE_PONG => null,
            self::OPCODE_TEXT_FRAME, self::OPCODE_BINARY_FRAME, self::OPCODE_CLOSE 
                => (function() use (&$dataFrame) {
                    if ($dataFrame['fin'] == 0) {
                        do {
                            $continueFrame = $this->readFrame();
                            $dataFrame['payload'] .= $continueFrame['payload'];
                        } while ($continueFrame['fin'] == 0);
                    }
                    if ($dataFrame['opcode'] == self::OPCODE_CLOSE) {
                        $this->replyClosure();
                    }
                })(),
            default => Debug::showMessage('无法识别的frame数据'),
        };
        return $dataFrame;
    }

    /**
     * 从WebSocket连接读取一个完整的数据帧
     * 
     * 按照RFC 6455标准解析WebSocket数据帧，处理各种帧类型和掩码
     * 
     * @return array 返回数据帧解析结果数组，包含以下键值：
     *               - opcode: 帧类型
     *               - fin: 是否为最终帧（1表示完整帧，0表示后续还有数据）
     *               - status: 关闭帧的状态码（仅关闭帧有效）
     *               - payload: 帧携带的有效数据
     */
    protected function readFrame(): array
    {
        $firstByte = $this->fetchCharFromReadBuf();
        $fin = ($firstByte >> 7);
        $opcode = $firstByte & 0x0F;
        $secondByte = $this->fetchCharFromReadBuf();
        $isMasked = ($secondByte >> 7);
        $dataLen = $secondByte & 0x7F;
        if ($dataLen == 126) {
            // 2字节无符号整形
            $dataLen = ($this->fetchCharFromReadBuf() << 8) + $this->fetchCharFromReadBuf();
        } elseif ($dataLen == 127) {
            // 8字节无符号整形
            $dataLen = $this->fetchStrFromReadBuf(8);
            $res = unpack('Jlen', $dataLen);
            if (isset($res['len'])) {
                $dataLen = $res['len'];
            } else {
                $dataLen = (ord($dataLen[0]) << 56)
                    + (ord($dataLen[1]) << 48)
                    + (ord($dataLen[2]) << 40)
                    + (ord($dataLen[3]) << 32)
                    + (ord($dataLen[4]) << 24)
                    + (ord($dataLen[5]) << 16)
                    + (ord($dataLen[6]) << 8)
                    + ord($dataLen[7]);
            }
        }
        $data = '';
        $status = 0;
        if ($dataLen > 0) {
            if ($isMasked) {
                // 4字节掩码
                $maskChars = $this->fetchStrFromReadBuf(4);
                $maskSet = [ord($maskChars[0]), ord($maskChars[1]), ord($maskChars[2]), ord($maskChars[3])];
                $data = $this->fetchStrFromReadBuf($dataLen);
                for ($i = 0; $i < $dataLen; $i++) {
                    $data[$i] = chr(ord($data[$i]) ^ $maskSet[$i % 4]);
                }
            } else {
                $data = $this->fetchStrFromReadBuf($dataLen);
            }
            if ($opcode == self::OPCODE_CLOSE) {
                $status = (ord($data[0]) << 8) + ord($data[1]);
                $data = substr($data, 2);
            }
        }
        $this->discardReadBuf($this->readBufPos);
        return ['opcode'=>$opcode, 'fin'=>$fin,'status'=>$status, 'payload'=>$data];
    }

    /**
     * 获取WebSocket服务器返回的数据内容
     * 
     * 调用recv方法接收数据帧，并直接返回数据帧中的payload内容，简化数据接收操作
     * 
     * @return string 返回接收到的数据内容
     * 
     * @example
     * ```php
     * // 直接获取接收到的数据内容
     * $response = $ws->getRecv();
     * echo '收到响应: ' . $response;
     * ```
     */
    public function getRecv(): string
    {
        return $this->recv()['payload'];
    }

    /**
    * __destruct
    */
    public function __destruct()
    {
        $this->abnormalClose();
    }
}

