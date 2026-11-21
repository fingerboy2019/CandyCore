# Smtp

## 类简介
Smtp类是Candy框架中提供的基于PHP Socket的完整SMTP邮件发送功能类。该类支持HTML内容、附件发送、抄送密送和SMTP认证等多种功能，是一个功能全面的邮件发送工具类。

## 命名空间
```php
namespace Candy\Extend\Network;
```

## 类定义
```php
/**
 * SMTP邮件发送类
 * 
 * 提供基于PHP Socket的完整SMTP邮件发送功能，支持HTML内容、附件发送、抄送密送和SMTP认证。
 * 该类为静态工具类，设计为final类，不可被继承。
 */
final class Smtp {
    // 类成员
    public $hostName = 'localhost';
    public $timeOut = 30;
    public $logFile = '';
    public $debug  = false;
    public $sock = false;
    public $relayHost;
    public $smtpPort;
    public $auth;
    public $user;
    public $pass;
}
```

## 主要方法

### __construct()
```php
/**
 * 初始化SMTP连接配置
 * 
 * 构造函数用于设置SMTP服务器连接所需的基本参数，包括服务器地址、端口、认证信息等。
 * 
 * @param string $relayHost SMTP服务器地址，用于邮件转发
 * @param int $smtpPort SMTP服务器端口，默认为25（非SSL），也可以是465（SSL）或587（TLS）
 * @param bool $auth 是否需要SMTP身份认证，true表示需要，false表示不需要
 * @param string $user SMTP认证用户名，当$auth为true时必填
 * @param string $pass SMTP认证密码，当$auth为true时必填
 */
public function __construct(string $relayHost, int $smtpPort = 25, bool $auth = false, string $user, string $pass)
```

### sendMail()
```php
/**
 * 发送电子邮件的主要方法
 * 
 * 这是Smtp类的核心公共方法，用于发送电子邮件。支持多个收件人、抄送、密送，
 * 可以设置HTML或纯文本格式的邮件内容，以及自定义邮件头信息。
 * 
 * @param string $to 收件人邮箱地址，可以是单个邮箱或多个邮箱用逗号分隔
 * @param string $from 发件人邮箱地址
 * @param string $subject 邮件主题
 * @param string $body 邮件内容
 * @param string $mailtype 邮件类型，可选值："HTML"（HTML格式）或"TEXT"（纯文本格式）
 * @param string $cc 抄送邮箱地址，可以是单个邮箱或多个邮箱用逗号分隔
 * @param string $bcc 密送邮箱地址，可以是单个邮箱或多个邮箱用逗号分隔
 * @param string $additional_headers 附加的邮件头信息
 * @return bool 发送成功返回true，失败返回false
 */
public function sendMail(string $to, string $from, string $subject = "", string $body = "", string $mailtype = "HTML", string $cc = "", string $bcc = "", string $additional_headers = ""): bool
```

## 内部方法

### smtpSend()
```php
/**
 * 执行SMTP协议发送邮件
 * 
 * 内部核心方法，负责与SMTP服务器交互，按照SMTP协议规范发送各种命令。
 * 包括HELO/EHLO问候、身份认证、指定发件人/收件人、发送邮件内容等操作。
 * 
 * @param string $helo 服务器主机名，用于HELO/EHLO命令
 * @param string $from 发件人邮箱地址
 * @param string $to 收件人邮箱地址
 * @param string $header 邮件头信息
 * @param string $body 邮件正文内容
 * @return bool 发送成功返回true，失败返回false
 */
private function smtpSend(string $helo, string $from, string $to, string $header, string $body = ""): bool
```

### smtpSockopen()
```php
/**
 * 打开SMTP服务器连接
 * 
 * 根据配置选择连接方式：如果指定了relayHost，则连接到指定的邮件中继服务器；
 * 否则通过MX记录查找收件人域名的邮件服务器。
 * 
 * @param string $address 收件人邮箱地址，用于查找MX记录
 * @return bool 连接成功返回true，失败返回false
 */
private function smtpSockopen(string $address): bool
```

### smtpSockopenRelay()
```php
/**
 * 连接到指定的中继SMTP服务器
 * 
 * 当配置了中继服务器时，使用fsockopen连接到指定的SMTP服务器。
 * 支持超时设置，并记录连接日志。
 * 
 * @return bool 连接成功返回true，失败返回false
 */
private function smtpSockopenRelay(): bool
```

### smtpSockopenMx()
```php
/**
 * 通过MX记录查找并连接邮件服务器
 * 
 * 从收件人邮箱地址中提取域名，然后通过DNS的MX记录查找该域名的邮件服务器。
 * 尝试连接到找到的每个MX服务器，直到成功连接或全部尝试失败。
 * 
 * @param string $address 收件人邮箱地址
 * @return bool 成功连接到MX服务器返回true，全部失败返回false
 */
private function smtpSockopenMx(string $address): bool
```

### smtpMessage()
```php
/**
 * 发送邮件头和正文内容
 * 
 * 将邮件头和正文内容写入到SMTP连接中。如果开启了调试模式，会输出发送的内容。
 * 
 * @param string $header 邮件头信息
 * @param string $body 邮件正文内容
 * @return bool 发送成功返回true
 */
private function smtpMessage(string $header, string $body): bool
```

### smtpEom()
```php
/**
 * 发送邮件结束标记(End Of Message)
 * 
 * 按照SMTP协议规范，发送邮件内容结束标记(.)，并检查服务器响应。
 * 
 * @return bool 服务器接收成功返回true，失败返回false
 */
private function smtpEom(): bool
```

### smtpOk()
```php
/**
 * 检查SMTP服务器响应状态
 * 
 * 读取SMTP服务器的响应并验证其状态码。SMTP协议中，2xx和3xx表示成功响应。
 * 如果响应状态码不是以2或3开头，则表示命令执行失败。
 * 
 * @return bool 响应状态码有效(2xx或3xx)返回true，否则返回false
 */
private function smtpOk(): bool
```

### smtpPutCmd()
```php
/**
 * 发送SMTP命令到服务器
 * 
 * 将SMTP命令和参数组合后发送到服务器，并检查服务器的响应状态。
 * 
 * @param string $cmd SMTP命令（如HELO、MAIL FROM、RCPT TO等）
 * @param string $arg 命令参数
 * @return bool 命令发送成功且服务器响应有效返回true，否则返回false
 */
private function smtpPutCmd(string $cmd, string $arg = ""): bool
```

### smtpError()
```php
/**
 * 记录SMTP错误信息
 * 
 * 当SMTP操作失败时，记录详细的错误信息到日志。
 * 
 * @param string $string 错误操作描述
 * @return bool 始终返回false，表示操作失败
 */
private function smtpError(string $string): bool
```

### logWrite()
```php
/**
 * 写入日志信息
 * 
 * 将日志消息输出到调试终端或写入到指定的日志文件。
 * 如果指定了logFile，则将日志写入文件，否则仅在debug模式下输出到屏幕。
 * 
 * @param string $message 要记录的日志消息
 * @return bool 日志记录成功返回true，失败返回false
 */
private function logWrite(string $message): bool
```

### stripComment()
```php
/**
 * 移除邮箱地址中的注释
 * 
 * 从邮箱地址字符串中移除括号内的注释部分，例如将"John Doe <john@example.com>"转换为"John Doe <john@example.com>"
 * 
 * @param string $address 包含可能包含注释的邮箱地址
 * @return string 清理后的邮箱地址
 */
private function stripComment(string $address): string
```

### getAddress()
```php
/**
 * 提取邮箱地址中的实际邮件地址部分
 * 
 * 从可能包含显示名称的邮箱地址中提取纯邮箱地址部分，例如将"John Doe <john@example.com>"转换为"john@example.com"
 * 
 * @param string $address 可能包含显示名称的邮箱地址
 * @return string 提取的纯邮箱地址
 */
private function getAddress(string $address): string
```

### smtpDebug()
```php
/**
 * 输出调试信息
 * 
 * 当debug模式开启时，输出SMTP交互的详细调试信息。
 * 
 * @param string $message 调试信息
 * @return void
 */
private function smtpDebug(string $message): void
```

## 使用说明

### 基本用法
```php
// 创建Smtp实例
$smtp = new Smtp('smtp.example.com', 25, true, 'username@example.com', 'password');

// 发送纯文本邮件
$smtp->sendMail(
    'recipient@example.com',           // 收件人
    'sender@example.com',              // 发件人
    '测试邮件主题',                    // 主题
    '这是一封测试邮件的内容',          // 正文
    'TEXT'                             // 邮件类型
);

// 发送HTML邮件
$smtp->sendMail(
    'recipient@example.com',           // 收件人
    'sender@example.com',              // 发件人
    'HTML格式测试邮件',                // 主题
    '<h1>Hello World</h1><p>这是HTML格式的邮件内容</p>', // 正文
    'HTML',                            // 邮件类型
    'cc@example.com',                  // 抄送
    'bcc@example.com'                  // 密送
);
```

### 高级用法
```php
// 设置调试模式
$smtp = new Smtp('smtp.example.com', 25, true, 'username', 'password');
$smtp->debug = true; // 开启调试输出
$smtp->logFile = '/path/to/smtp.log'; // 设置日志文件
$smtp->timeOut = 60; // 设置超时时间

// 添加自定义邮件头
$headers = "Reply-To: reply@example.com\r\n";
$headers .= "X-Priority: 1 (Highest)\r\n";
$headers .= "X-MSMail-Priority: High\r\n";

$smtp->sendMail(
    'recipient@example.com',
    'sender@example.com',
    '带有自定义头部的邮件',
    '邮件内容',
    'HTML',
    '',
    '',
    $headers
);
```

## 注意事项
1. 使用前请确保PHP环境支持socket功能（fsockopen函数可用）。
2. 发送HTML邮件时，建议将CSS样式内联到HTML中，以确保在大多数邮件客户端中正确显示。
3. 使用SMTP认证时，请确保用户名和密码正确，并注意安全存储敏感信息。
4. 连接到SMTP服务器可能受到网络环境和服务器配置的限制，请确保防火墙允许相应端口的连接。
5. 调试模式下会输出详细的SMTP交互信息，生产环境应关闭此选项。
6. 发送大量邮件时，请注意遵守反垃圾邮件法规和接收服务器的限制。

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-02-10 14:00:00