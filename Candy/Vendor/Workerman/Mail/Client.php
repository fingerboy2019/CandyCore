<?php
namespace Mail;

use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Timer;

/**
 * 邮件客户端类
 * 
 * 该类实现了 POP3 和 IMAP 协议的客户端功能，
 * 用于与邮件服务器进行通信，支持获取邮件列表、读取邮件内容、删除邮件等操作。
 * 
 * @package Mail
 */
class Client
{
    /**
     * 初始状态
     */
    const STATE_INITIAL = 1;

    /**
     * 连接中状态
     */
    const STATE_CONNECTING = 2;

    /**
     * 等待连接确认状态
     */
    const STATE_WAITCONACK = 3;

    /**
     * 连接已建立状态
     */
    const STATE_ESTABLISHED = 4;

    /**
     * 断开连接中状态
     */
    const STATE_DISCONNECTING = 5;

    /**
     * 已断开连接状态
     */
    const STATE_DISCONNECT = 6;

    /**
     * POP3 协议
     */
    const PROTOCOL_POP3 = 'pop3';

    /**
     * IMAP 协议
     */
    const PROTOCOL_IMAP = 'imap';

    /**
     * 连接成功回调
     * @var callable
     */
    public $onConnect = null;

    /**
     * 重连成功回调
     * @var callable
     */
    public $onReconnect = null;

    /**
     * 连接关闭回调
     * @var callable
     */
    public $onClose = null;

    /**
     * 错误回调
     * @var callable
     */
    public $onError = null;

    /**
     * 邮件接收回调
     * @var callable
     */
    public $onMail = null;

    /**
     * 连接状态
     * @var int
     */
    protected $_state = 1;

    /**
     * 远程地址
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 异步TCP连接
     * @var AsyncTcpConnection
     */
    protected $_connection = null;

    /**
     * 协议类型
     * @var string
     */
    protected $_protocol = self::PROTOCOL_POP3;

    /**
     * 用户名
     * @var string
     */
    protected $_username = '';

    /**
     * 密码
     * @var string
     */
    protected $_password = '';

    /**
     * 缓冲区
     * @var string
     */
    protected $_buffer = '';

    /**
     * 命令队列
     * @var array
     */
    protected $_commandQueue = [];

    /**
     * 正在执行的命令
     * @var array
     */
    protected $_currentCommand = null;

    /**
     * 构造函数
     * 
     * @param string $remoteAddress 远程地址，格式为 tcp://host:port 或 ssl://host:port
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $protocol 协议类型，可选值：pop3|imap
     */
    public function __construct($remoteAddress, $username = '', $password = '', $protocol = self::PROTOCOL_POP3)
    {
        $this->_remoteAddress = $remoteAddress;
        $this->_username = $username;
        $this->_password = $password;
        $this->_protocol = $protocol;
    }

    /**
     * 连接到邮件服务器
     * 
     * @return void
     */
    public function connect()
    {
        $this->_state = self::STATE_CONNECTING;
        
        $this->_connection = new AsyncTcpConnection($this->_remoteAddress);
        
        $this->_connection->onConnect = function($connection) {
            $this->_state = self::STATE_WAITCONACK;
            
            // 读取服务器欢迎消息
            $connection->onMessage = function($connection, $data) {
                $this->_buffer .= $data;
                $this->_processBuffer();
            };
            
            $connection->onClose = function($connection) {
                $this->_state = self::STATE_DISCONNECT;
                if ($this->onClose) {
                    call_user_func($this->onClose, $this);
                }
            };
            
            $connection->onError = function($connection, $code, $msg) {
                $this->_state = self::STATE_DISCONNECT;
                if ($this->onError) {
                    call_user_func($this->onError, $this, $code, $msg);
                }
            };
            
            // 如果提供了用户名和密码，自动登录
            if ($this->_username && $this->_password) {
                $this->login($this->_username, $this->_password);
            }
        };
        
        $this->_connection->connect();
    }

    /**
     * 登录邮件服务器
     * 
     * @param string $username 用户名
     * @param string $password 密码
     * @param callable $callback 回调函数
     * @return void
     */
    public function login($username, $password, $callback = null)
    {
        if ($this->_state !== self::STATE_WAITCONACK && $this->_state !== self::STATE_ESTABLISHED) {
            if ($callback) {
                call_user_func($callback, $this, false, 'Not connected');
            }
            return;
        }
        
        $this->_username = $username;
        $this->_password = $password;
        
        if ($this->_protocol === self::PROTOCOL_POP3) {
            // POP3 登录
            $this->sendCommand('USER ' . $username, function($client, $success, $data) use ($password, $callback) {
                if (!$success) {
                    if ($callback) {
                        call_user_func($callback, $client, false, $data);
                    }
                    return;
                }
                
                $client->sendCommand('PASS ' . $password, function($client, $success, $data) use ($callback) {
                    if ($success) {
                        $client->_state = self::STATE_ESTABLISHED;
                        if ($client->onConnect) {
                            call_user_func($client->onConnect, $client);
                        }
                    }
                    if ($callback) {
                        call_user_func($callback, $client, $success, $data);
                    }
                });
            });
        } else {
            // IMAP 登录
            $this->sendCommand('LOGIN ' . $username . ' ' . $password, function($client, $success, $data) use ($callback) {
                if ($success) {
                    $client->_state = self::STATE_ESTABLISHED;
                    if ($client->onConnect) {
                        call_user_func($client->onConnect, $client);
                    }
                }
                if ($callback) {
                    call_user_func($callback, $client, $success, $data);
                }
            });
        }
    }

    /**
     * 获取邮件列表
     * 
     * @param int $limit 限制数量
     * @param callable $callback 回调函数
     * @return void
     */
    public function getMailList($limit = 0, $callback = null)
    {
        if ($this->_state !== self::STATE_ESTABLISHED) {
            if ($callback) {
                call_user_func($callback, $this, false, 'Not connected');
            }
            return;
        }
        
        if ($this->_protocol === self::PROTOCOL_POP3) {
            $this->sendCommand('LIST', function($client, $success, $data) use ($limit, $callback) {
                if (!$success) {
                    if ($callback) {
                        call_user_func($callback, $client, false, $data);
                    }
                    return;
                }
                
                $mails = [];
                $lines = explode("\r\n", $data);
                
                for ($i = 1; $i < count($lines) - 1; $i++) {
                    if (preg_match('/^(\d+) (\d+)$/', $lines[$i], $matches)) {
                        $mails[] = [
                            'id' => (int)$matches[1],
                            'size' => (int)$matches[2]
                        ];
                    }
                }
                
                if ($limit > 0 && count($mails) > $limit) {
                    $mails = array_slice($mails, -$limit);
                }
                
                if ($callback) {
                    call_user_func($callback, $client, true, $mails);
                }
            }, true);
        } else {
            $this->sendCommand('SELECT INBOX', function($client, $success, $data) use ($limit, $callback) {
                if (!$success) {
                    if ($callback) {
                        call_user_func($callback, $client, false, $data);
                    }
                    return;
                }
                
                $client->sendCommand('SEARCH ALL', function($client, $success, $data) use ($limit, $callback) {
                    if (!$success) {
                        if ($callback) {
                            call_user_func($callback, $client, false, $data);
                        }
                        return;
                    }
                    
                    $mails = [];
                    if (preg_match('/^\* SEARCH (.+)$/', $data, $matches)) {
                        $mailIds = explode(' ', trim($matches[1]));
                        foreach ($mailIds as $id) {
                            if ($id) {
                                $mails[] = ['id' => (int)$id];
                            }
                        }
                    }
                    
                    if ($limit > 0 && count($mails) > $limit) {
                        $mails = array_slice($mails, -$limit);
                    }
                    
                    if ($callback) {
                        call_user_func($callback, $client, true, $mails);
                    }
                });
            });
        }
    }

    /**
     * 获取邮件内容
     * 
     * @param int $mailId 邮件ID
     * @param callable $callback 回调函数
     * @return void
     */
    public function getMail($mailId, $callback = null)
    {
        if ($this->_state !== self::STATE_ESTABLISHED) {
            if ($callback) {
                call_user_func($callback, $this, false, 'Not connected');
            }
            return;
        }
        
        if ($this->_protocol === self::PROTOCOL_POP3) {
            $this->sendCommand('RETR ' . $mailId, function($client, $success, $data) use ($mailId, $callback) {
                if (!$success) {
                    if ($callback) {
                        call_user_func($callback, $client, false, $data);
                    }
                    return;
                }
                
                $mail = $client->_parseMail($data);
                $mail['id'] = $mailId;
                
                if ($callback) {
                    call_user_func($callback, $client, true, $mail);
                }
                
                if ($client->onMail) {
                    call_user_func($client->onMail, $client, $mail);
                }
            }, true);
        } else {
            $this->sendCommand('FETCH ' . $mailId . ' BODY[]', function($client, $success, $data) use ($mailId, $callback) {
                if (!$success) {
                    if ($callback) {
                        call_user_func($callback, $client, false, $data);
                    }
                    return;
                }
                
                $mail = $client->_parseMail($data);
                $mail['id'] = $mailId;
                
                if ($callback) {
                    call_user_func($callback, $client, true, $mail);
                }
                
                if ($client->onMail) {
                    call_user_func($client->onMail, $client, $mail);
                }
            }, true);
        }
    }

    /**
     * 删除邮件
     * 
     * @param int $mailId 邮件ID
     * @param callable $callback 回调函数
     * @return void
     */
    public function deleteMail($mailId, $callback = null)
    {
        if ($this->_state !== self::STATE_ESTABLISHED) {
            if ($callback) {
                call_user_func($callback, $this, false, 'Not connected');
            }
            return;
        }
        
        if ($this->_protocol === self::PROTOCOL_POP3) {
            $this->sendCommand('DELE ' . $mailId, $callback);
        } else {
            $this->sendCommand('STORE ' . $mailId . ' +FLAGS (\\Deleted)', function($client, $success, $data) use ($callback) {
                if (!$success) {
                    if ($callback) {
                        call_user_func($callback, $client, false, $data);
                    }
                    return;
                }
                
                $client->sendCommand('EXPUNGE', $callback);
            });
        }
    }

    /**
     * 发送命令
     * 
     * @param string $command 命令
     * @param callable $callback 回调函数
     * @param bool $multiLine 是否多行响应
     * @return void
     */
    public function sendCommand($command, $callback = null, $multiLine = false)
    {
        if ($this->_state !== self::STATE_ESTABLISHED && $this->_state !== self::STATE_WAITCONACK) {
            if ($callback) {
                call_user_func($callback, $this, false, 'Not connected');
            }
            return;
        }
        
        $this->_commandQueue[] = [
            'command' => $command,
            'callback' => $callback,
            'multiLine' => $multiLine
        ];
        
        if (!$this->_currentCommand) {
            $this->_executeNextCommand();
        }
    }

    /**
     * 执行下一个命令
     * 
     * @return void
     */
    protected function _executeNextCommand()
    {
        if (empty($this->_commandQueue)) {
            $this->_currentCommand = null;
            return;
        }
        
        $this->_currentCommand = array_shift($this->_commandQueue);
        $command = $this->_currentCommand['command'];
        
        $this->_connection->send($command . "\r\n");
    }

    /**
     * 处理缓冲区数据
     * 
     * @return void
     */
    protected function _processBuffer()
    {
        if ($this->_protocol === self::PROTOCOL_POP3) {
            $this->_processPop3Buffer();
        } else {
            $this->_processImapBuffer();
        }
    }

    /**
     * 处理POP3缓冲区数据
     * 
     * @return void
     */
    protected function _processPop3Buffer()
    {
        while (strpos($this->_buffer, "\r\n") !== false) {
            if (!$this->_currentCommand) {
                // 处理欢迎消息或未请求的响应
                $pos = strpos($this->_buffer, "\r\n");
                $line = substr($this->_buffer, 0, $pos);
                $this->_buffer = substr($this->_buffer, $pos + 2);
                continue;
            }
            
            if ($this->_currentCommand['multiLine']) {
                // 处理多行响应
                if (strpos($this->_buffer, "\r\n.\r\n") !== false) {
                    $pos = strpos($this->_buffer, "\r\n.\r\n");
                    $response = substr($this->_buffer, 0, $pos);
                    $this->_buffer = substr($this->_buffer, $pos + 5);
                    
                    $success = true;
                    if (strpos($response, "-ERR") === 0) {
                        $success = false;
                    }
                    
                    if ($this->_currentCommand['callback']) {
                        call_user_func($this->_currentCommand['callback'], $this, $success, $response);
                    }
                    
                    $this->_executeNextCommand();
                }
            } else {
                // 处理单行响应
                $pos = strpos($this->_buffer, "\r\n");
                $response = substr($this->_buffer, 0, $pos);
                $this->_buffer = substr($this->_buffer, $pos + 2);
                
                $success = strpos($response, "+OK") === 0;
                
                if ($this->_currentCommand['callback']) {
                    call_user_func($this->_currentCommand['callback'], $this, $success, $response);
                }
                
                $this->_executeNextCommand();
            }
        }
    }

    /**
     * 处理IMAP缓冲区数据
     * 
     * @return void
     */
    protected function _processImapBuffer()
    {
        while (strpos($this->_buffer, "\r\n") !== false) {
            if (!$this->_currentCommand) {
                // 处理欢迎消息或未请求的响应
                $pos = strpos($this->_buffer, "\r\n");
                $line = substr($this->_buffer, 0, $pos);
                $this->_buffer = substr($this->_buffer, $pos + 2);
                continue;
            }
            
            if ($this->_currentCommand['multiLine']) {
                // 处理多行响应
                if (strpos($this->_buffer, "\r\n)\r\n") !== false) {
                    $pos = strpos($this->_buffer, "\r\n)\r\n");
                    $response = substr($this->_buffer, 0, $pos);
                    $this->_buffer = substr($this->_buffer, $pos + 5);
                    
                    $success = true;
                    if (strpos($response, "BAD") !== false || strpos($response, "NO") !== false) {
                        $success = false;
                    }
                    
                    if ($this->_currentCommand['callback']) {
                        call_user_func($this->_currentCommand['callback'], $this, $success, $response);
                    }
                    
                    $this->_executeNextCommand();
                }
            } else {
                // 处理单行响应
                $pos = strpos($this->_buffer, "\r\n");
                $response = substr($this->_buffer, 0, $pos);
                $this->_buffer = substr($this->_buffer, $pos + 2);
                
                $success = strpos($response, "OK") !== false;
                
                if ($this->_currentCommand['callback']) {
                    call_user_func($this->_currentCommand['callback'], $this, $success, $response);
                }
                
                $this->_executeNextCommand();
            }
        }
    }

    /**
     * 解析邮件内容
     * 
     * @param string $content 邮件内容
     * @return array 解析后的邮件
     */
    protected function _parseMail($content)
    {
        $mail = [
            'headers' => [],
            'subject' => '',
            'from' => '',
            'to' => '',
            'date' => '',
            'body' => '',
            'html_body' => ''
        ];
        
        // 分离邮件头和正文
        $parts = explode("\r\n\r\n", $content, 2);
        $headersPart = $parts[0];
        $bodyPart = isset($parts[1]) ? $parts[1] : '';
        
        // 解析邮件头
        $headerLines = explode("\r\n", $headersPart);
        $currentHeader = '';
        
        foreach ($headerLines as $line) {
            if (preg_match('/^([^:\s]+):\s*(.*)$/', $line, $matches)) {
                $currentHeader = strtolower($matches[1]);
                $mail['headers'][$currentHeader] = $matches[2];
            } elseif ($currentHeader && preg_match('/^\s+(.*)$/', $line, $matches)) {
                // 处理多行邮件头
                $mail['headers'][$currentHeader] .= " " . $matches[1];
            }
        }
        
        // 提取关键邮件头
        if (isset($mail['headers']['subject'])) {
            $mail['subject'] = $this->_decodeMimeHeader($mail['headers']['subject']);
        }
        
        if (isset($mail['headers']['from'])) {
            $mail['from'] = $this->_decodeMimeHeader($mail['headers']['from']);
        }
        
        if (isset($mail['headers']['to'])) {
            $mail['to'] = $this->_decodeMimeHeader($mail['headers']['to']);
        }
        
        if (isset($mail['headers']['date'])) {
            $mail['date'] = $mail['headers']['date'];
        }
        
        // 解析邮件正文
        if ($bodyPart) {
            // 检查是否为多部分邮件
            if (isset($mail['headers']['content-type']) && strpos($mail['headers']['content-type'], 'multipart/') === 0) {
                // 简单处理多部分邮件
                $mail['body'] = "[多部分邮件内容]";
            } elseif (isset($mail['headers']['content-type']) && strpos($mail['headers']['content-type'], 'text/html') === 0) {
                // HTML邮件
                $mail['html_body'] = $bodyPart;
                $mail['body'] = strip_tags($bodyPart);
            } else {
                // 纯文本邮件
                $mail['body'] = $bodyPart;
            }
        }
        
        return $mail;
    }

    /**
     * 解码MIME编码的邮件头
     * 
     * @param string $header 编码的邮件头
     * @return string 解码后的邮件头
     */
    protected function _decodeMimeHeader($header)
    {
        // 处理MIME编码
        $header = preg_replace_callback('/=\?([^?]+)\?([QB])\?([^?]+)\?=/i', function($matches) {
            $charset = $matches[1];
            $encoding = $matches[2];
            $text = $matches[3];
            
            if (strtolower($encoding) === 'b') {
                $text = base64_decode($text);
            } else {
                $text = quoted_printable_decode($text);
            }
            
            // 转换编码
            if (strtolower($charset) !== 'utf-8') {
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            }
            
            return $text;
        }, $header);
        
        return $header;
    }

    /**
     * 关闭连接
     * 
     * @return void
     */
    public function close()
    {
        if ($this->_connection) {
            $this->_connection->close();
        }
    }

    /**
     * 获取状态
     * 
     * @return int 状态
     */
    public function getState()
    {
        return $this->_state;
    }
}
