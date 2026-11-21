# WebSocket 类

## 类简介

WebSocket类是Candy框架中实现的WebSocket客户端连接类，提供完整的WebSocket协议实现。该类基于RFC 6455标准，支持WebSocket连接的建立、数据收发、心跳检测和连接管理等核心功能，适用于需要与WebSocket服务器进行通信的各种应用场景。

## 命名空间

```php
namespace Candy\Extend\Network;
```

## 类定义

```php
/**
 * Candy框架 WebSocket客户端连接类
 * 
 * 提供完整的WebSocket协议实现，支持标准WebSocket连接、数据收发、心跳检测和连接管理。
 * 实现了RFC 6455标准的WebSocket协议，支持文本帧、二进制帧、ping/pong心跳和连接关闭等功能。
 * 
 * @package Candy\Extend\Network
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-04-17 10:46:16
 * @final 此类不可被继承
 */
final class WebSocket {
    // 类成员
}
```

## 类常量

### 协议相关常量

```php
// WebSocket协议标识
const PROTOCOL_WS = 'ws';

// 安全WebSocket协议标识（使用TLS加密）
const PROTOCOL_WSS = 'wss';

// HTTP头部字段分隔符
const HTTP_HEADER_SEPARATION_MARK = "\r\n";

// HTTP头部结束标记
const HTTP_HEADER_END_MARK = "\r\n\r\n";

// WebSocket握手过程中使用的固定UUID值
const UUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
```

### 帧操作码常量

```php
// 后续帧操作码
const OPCODE_CONTINUATION_FRAME = 0;

// 文本帧操作码
const OPCODE_TEXT_FRAME = 1;

// 二进制帧操作码
const OPCODE_BINARY_FRAME = 2;

// 关闭连接帧操作码
const OPCODE_CLOSE = 8;

// Ping帧操作码
const OPCODE_PING = 9;

// Pong帧操作码
const OPCODE_PONG = 10;
```

### 数据长度相关常量

```php
// 数据包大小，单位：字节
const PACKET_SIZE = (1 << 15);

// 帧长度第一级最大值
const FRAME_LENGTH_LEVEL_1_MAX = 125;

// 帧长度第二级最大值
const FRAME_LENGTH_LEVEL_2_MAX = 65535;
```

## 主要方法

### __construct()

```php
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
 * \```php
 * // 创建WebSocket连接
 * $ws = new \Candy\Extend\Network\WebSocket('ws://echo.websocket.org', 2.0, 10.0);
 * \```
 */
public function __construct($wsUri, float $connectTimeout = 1.0, float $rwTimeout = 5.0)
```

### send()

```php
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
 * \```php
 * // 发送文本消息
 * $ws->send('Hello WebSocket!');
 * 
 * // 发送二进制数据
 * $binaryData = pack('N', 12345); // 打包一个32位整数
 * $ws->send($binaryData, WebSocket::OPCODE_BINARY_FRAME);
 * \```
 */
public function send(string $data, int $opCode = self::OPCODE_TEXT_FRAME, bool $isMask = true): bool
```

### recv()

```php
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
 * \```php
 * // 接收数据帧
 * $frame = $ws->recv();
 * if ($frame['opcode'] == WebSocket::OPCODE_TEXT_FRAME) {
 *     echo '收到文本消息: ' . $frame['payload'];
 * }
 * \```
 */
public function recv(): array
```

### getRecv()

```php
/**
 * 获取WebSocket服务器返回的数据内容
 * 
 * 调用recv方法接收数据帧，并直接返回数据帧中的payload内容，简化数据接收操作
 * 
 * @return string 返回接收到的数据内容
 * 
 * @example
 * \```php
 * // 直接获取接收到的数据内容
 * $response = $ws->getRecv();
 * echo '收到响应: ' . $response;
 * \```
 */
public function getRecv(): string
```

### ping()

```php
/**
 * 向WebSocket服务器发送ping请求进行心跳检测
 * 
 * 发送ping帧并等待pong响应，用于检测连接是否活跃
 * 
 * @return bool 成功接收到pong响应返回true，否则返回false
 * 
 * @example
 * \```php
 * // 心跳检测，如果返回false表示连接可能已断开
 * if (!$ws->ping()) {
 *     echo '连接可能已断开，需要重新连接';
 * }
 * \```
 */
public function ping(): bool
```

### pong()

```php
/**
 * 响应WebSocket服务器的ping请求
 * 
 * 发送pong帧回应服务器的ping请求，维持连接活跃状态
 * 
 * @example
 * \```php
 * // 收到ping后主动发送pong响应（通常由recv方法内部自动调用）
 * $ws->pong();
 * \```
 */
public function pong(): void
```

### close()

```php
/**
 * 主动关闭与WebSocket服务器的连接
 * 
 * 发送关闭帧并等待服务器确认，然后关闭底层socket连接
 * 
 * @return bool 成功接收到服务器关闭确认返回true，否则返回false
 * 
 * @example
 * \```php
 * // 主动关闭WebSocket连接
 * if ($ws->close()) {
 *     echo '连接已成功关闭';
 * }
 * \```
 */
public function close(): bool
```

### abnormalClose()

```php
/**
 * 异常关闭WebSocket连接
 * 
 * 当ping失败或服务器响应异常时，直接关闭底层socket资源，不进行正常的关闭握手过程
 * 
 * @example
 * \```php
 * // 在异常情况下强制关闭连接
 * try {
 *     // 某些操作
 * } catch (\Exception $e) {
 *     $ws->abnormalClose();
 * }
 * \```
 */
public function abnormalClose(): void
```

### writeToSock()

```php
/**
 * 向WebSocket服务器发送原始数据
 * 
 * 将数据写入底层socket连接，如果数据长度超过包大小限制，会自动分片发送
 * 
 * @param string $data 要发送的原始数据
 * 
 * @example
 * \```php
 * // 发送原始数据（通常不直接调用，由send等方法内部调用）
 * $ws->writeToSock('原始数据内容');
 * \```
 */
public function writeToSock(string $data = ''): void
```

## 内部方法

以下是WebSocket类中的内部方法（私有或受保护方法），仅供类内部使用：

### parseUri()

```php
/**
 * 解析WebSocket连接URI地址
 * 
 * 解析WebSocket URI，提取协议、主机、端口和路径等信息
 * 
 * @param string $wsUri WebSocket服务器的URI地址，格式为ws://host:port/path或wss://host:port/path
 */
private function parseUri(string $wsUri): void
```

### connect()

```php
/**
 * 连接WebSocket服务器
 * 
 * 创建并初始化socket连接，根据协议类型选择tcp或ssl连接方式
 * 
 * @param float $timeout 连接服务器的超时时间（秒）
 * @param float $rwTimeout 读写数据的超时时间（秒）
 */
private function connect(float $timeout, float $rwTimeout): void
```

### handshake()

```php
/**
 * 执行WebSocket握手过程
 * 
 * 按照RFC 6455标准执行WebSocket握手，发送握手请求并验证服务器响应
 */
protected function handshake(): void
```

### packFrame()

```php
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
protected function packFrame(int $opCode, string $payload = '', bool $isMask = true, $status = 1000): mixed
```

### readFrame()

```php
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
```

### replyClosure()

```php
/**
 * 响应WebSocket服务器的关闭消息
 * 
 * 当收到服务器的关闭帧时，发送关闭确认帧并关闭连接
 */
protected function replyClosure(): void
```

### generateWsKey()

```php
/**
 * 生成WebSocket握手所需的随机密钥
 * 
 * 创建符合WebSocket协议要求的Sec-WebSocket-Key值，用于握手过程中的身份验证
 * 
 * @return string 返回Base64编码的随机密钥
 */
protected static function generateWsKey()
```

### initReadBuf()

```php
/**
 * 初始化数据接收缓冲区
 * 
 * 设置接收缓冲区的初始状态，包括清空缓冲区和重置指针位置
 */
private function initReadBuf(): void
```

## 使用说明

### 创建WebSocket连接

```php
// 基本连接
$ws = new \Candy\Extend\Network\WebSocket('ws://echo.websocket.org');

// 自定义超时设置
$ws = new \Candy\Extend\Network\WebSocket('wss://secure.websocket.org', 3.0, 15.0);
```

### 发送数据

```php
// 发送文本数据
$ws->send('Hello, WebSocket Server!');

// 发送二进制数据
$binaryData = pack('N', 1024) . pack('d', 3.14159); // 打包一个整数和一个双精度浮点数
$ws->send($binaryData, WebSocket::OPCODE_BINARY_FRAME);
```

### 接收数据

```php
// 获取数据内容
$message = $ws->getRecv();
echo '收到消息: ' . $message;

// 接收原始帧数据
$frame = $ws->recv();
if ($frame['opcode'] == WebSocket::OPCODE_TEXT_FRAME) {
    echo '文本帧: ' . $frame['payload'];
} elseif ($frame['opcode'] == WebSocket::OPCODE_BINARY_FRAME) {
    echo '二进制帧，长度: ' . strlen($frame['payload']);
} elseif ($frame['opcode'] == WebSocket::OPCODE_CLOSE) {
    echo '连接关闭，状态码: ' . $frame['status'];
}
```

### 心跳检测

```php
// 定期进行心跳检测
if ($ws->ping()) {
    echo '连接正常';
} else {
    echo '连接可能已断开，需要重连';
    // 重连逻辑
}
```

### 关闭连接

```php
// 正常关闭
if ($ws->close()) {
    echo '连接已正常关闭';
}

// 异常情况下强制关闭
try {
    // 某些操作
} catch (\Exception $e) {
    $ws->abnormalClose();
    echo '异常关闭连接';
}
```

## 注意事项

1. WebSocket连接默认支持ws://(普通)和wss://(加密)两种协议，使用wss://时自动启用SSL加密
2. 客户端发送的数据帧必须使用掩码（默认已设置），这是WebSocket协议的要求
3. 长时间不活动的连接可能被服务器断开，建议定期发送ping消息保持连接活跃
4. 对于大量数据的传输，类会自动进行分片处理，但注意调整超时时间以适应大数据传输
5. 类实现了__destruct方法会自动关闭连接，但最好显式调用close()方法进行正常关闭
6. WebSocket连接是异步的，可能需要循环接收消息以确保所有数据都被处理

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2024-04-17 10:46:16