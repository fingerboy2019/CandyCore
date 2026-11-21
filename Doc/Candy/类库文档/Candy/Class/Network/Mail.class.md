# Mail 类

## 概述

* 邮件接收类
 * 
 * 功能描述：提供基于POP3和IMAP协议的邮件接收功能，支持连接邮箱服务器、获取邮件列表、读取邮件内容等操作。
 * 该类为工具类，位于Candy\Class\Network命名空间。
 * 
 * 主要功能：
 * - 支持POP3和IMAP两种邮件接收协议
 * - 支持SSL/TLS加密连接
 * - 支持邮件列表获取和邮件内容读取
 * - 支持邮件删除和标记操作
 * - 提供完整的错误处理和日志记录
 * - 支持自定义超时设置
 * 
 * @package Candy\Class\Network
 * @author 系统生成
 * @date 2026-02-11

## 主要方法


### /**
 * 邮件接收类
 * 
 * 功能描述：提供基于POP3和IMAP协议的邮件接收功能，支持连接邮箱服务器、获取邮件列表、读取邮件内容等操作。
 * 该类为工具类，位于Candy\Class\Network命名空间。
 * 
 * 主要功能：
 * - 支持POP3和IMAP两种邮件接收协议
 * - 支持SSL/TLS加密连接
 * - 支持邮件列表获取和邮件内容读取
 * - 支持邮件删除和标记操作
 * - 提供完整的错误处理和日志记录
 * - 支持自定义超时设置
 * 
 * @package Candy\Class\Network
 * @author 系统生成
 * @date 2026-02-11
 */

declare(strict_types=1);
namespace Candy\Extend\Network;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Mail {
    /**
     * 服务器地址
     * @var string
     */
    private $host;
    
    /**
     * 服务器端口
     * @var int
     */
    private $port;
    
    /**
     * 协议类型 (pop3|imap)
     * @var string
     */
    private $protocol;
    
    /**
     * 是否使用SSL
     * @var bool
     */
    private $ssl;
    
    /**
     * 用户名
     * @var string
     */
    private $username;
    
    /**
     * 密码
     * @var string
     */
    private $password;
    
    /**
     * 连接超时时间
     * @var int
     */
    private $timeout;
    
    /**
     * 连接资源
     * @var resource
     */
    private $connection;
    
    /**
     * 调试模式
     * @var bool
     */
    public $debug = false;
    
    /**
     * 日志文件
     * @var string
     */
    public $logFile = '';
    
    /**
     * 错误信息
     * @var string
     */
    private $error;
    
    /**
     * 初始化邮件接收配置
     * 
     * 构造函数用于设置邮件服务器连接所需的基本参数，包括服务器地址、端口、协议类型、认证信息等。
     * 
     * @param string $host 邮件服务器地址
     * @param int $port 邮件服务器端口，默认为0（自动根据协议和SSL设置）
     * @param string $protocol 协议类型，可选值：pop3|imap
     * @param bool $ssl 是否使用SSL连接
     * @param string $username 邮箱用户名
     * @param string $password 邮箱密码
     * @param int $timeout 连接超时时间，默认为30秒
     */
    public function __construct(string $host, int $port = 0, string $protocol = 'pop3', bool $ssl = true, string $username = '', string $password = '', int $timeout = 30) {
        $this->host = $host;
        $this->protocol = strtolower($protocol);
        $this->ssl = $ssl;
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
        
        // 自动设置端口
        if ($port === 0) {
            if ($this->protocol === 'pop3') {
                $this->port = $this->ssl ? 995 :()


### /**
     * 连接邮件服务器
     * 
     * 根据配置的协议类型和连接参数，建立与邮件服务器的连接。
     * 
     * @return bool 连接成功返回true，失败返回false
     */
    public function connect():()


### /**
     * 邮件服务器认证
     * 
     * 使用提供的用户名和密码进行邮件服务器认证。
     * 
     * @return bool 认证成功返回true，失败返回false
     */
    public function login():()


### /**
     * 获取邮件列表
     * 
     * 获取邮箱中的邮件列表，返回邮件的基本信息。
     * 
     * @param int $limit 限制返回的邮件数量，默认为0（返回所有）
     * @return array 邮件列表数组，每个元素包含邮件的基本信息
     */
    public function getMailList(int $limit = 0):()


### /**
     * 获取邮件内容
     * 
     * 根据邮件ID获取邮件的详细内容。
     * 
     * @param int $mailId 邮件ID
     * @return array 邮件内容数组，包含邮件头和正文
     */
    public function getMail(int $mailId):()


### /**
     * 删除邮件
     * 
     * 根据邮件ID删除邮件。
     * 
     * @param int $mailId 邮件ID
     * @return bool 删除成功返回true，失败返回false
     */
    public function deleteMail(int $mailId):()


### /**
     * 关闭连接
     * 
     * 关闭与邮件服务器的连接，并在POP3协议下发送QUIT命令。
     * 
     * @return bool 关闭成功返回true，失败返回false
     */
    public function close():()


### /**
     * 发送命令到服务器
     * 
     * 向邮件服务器发送命令。
     * 
     * @param string $command 要发送的命令
     * @return bool 发送成功返回true
     */
    private function sendCommand(string $command):()


### /**
     * 读取服务器响应
     * 
     * 读取邮件服务器的响应，支持多行响应。
     * 
     * @param bool $multiLine 是否读取多行响应
     * @return string|array 单行响应返回字符串，多行响应返回数组
     */
    private function readResponse(bool $multiLine = false) {
        if (!$this->connection) {
            return $multiLine ? [] :()


### /**
     * 解析邮件内容
     * 
     * 解析原始邮件内容，提取邮件头和正文。
     * 
     * @param string $content 原始邮件内容
     * @return array 解析后的邮件数组
     */
    private function parseMailContent(string $content):()

