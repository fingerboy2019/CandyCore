<?php
namespace Mail;

use \Workerman\Worker;
use \Workerman\Connection\TcpConnection;
use \Workerman\Protocols\ProtocolInterface;

/**
 * 邮件服务器类
 * 
 * 该类实现了 POP3 和 IMAP 协议的服务器功能，
 * 用于接收客户端的邮件操作请求，支持用户认证、邮件存储、邮件检索等功能。
 * 
 * @package Mail
 */
class Server
{
    /**
     * POP3 服务
     * @var Worker
     */
    protected $_pop3Worker = null;

    /**
     * IMAP 服务
     * @var Worker
     */
    protected $_imapWorker = null;

    /**
     * 用户认证回调
     * @var callable
     */
    protected $_onAuth = null;

    /**
     * 邮件存储接口
     * @var mixed
     */
    protected $_storage = null;

    /**
     * 配置选项
     * @var array
     */
    protected $_config = [
        'pop3_port' => 110,
        'pop3_ssl_port' => 995,
        'imap_port' => 143,
        'imap_ssl_port' => 993,
        'ssl_cert' => '',
        'ssl_key' => '',
        'max_connections' => 1000,
        'max_requests' => 10000,
        'reuse_port' => false, 'count' => 4
    ];

    /**
     * 构造函数
     * 
     * @param array $config 配置选项
     */
    public function __construct(array $config = [])
    {
        $this->_config = array_merge($this->_config, $config);
        $this->_initWorkers();
    }

    /**
     * 初始化工作进程
     * 
     * @return void
     */
    protected function _initWorkers()
    {
        // 创建POP3服务
        $this->_pop3Worker = new Worker('tcp://0.0.0.0:' . $this->_config['pop3_port']);
        $this->_pop3Worker->name = 'MailServer-POP3';
        $this->_pop3Worker->count = (int) ($this->_config['count'] ?? 4);
        $this->_pop3Worker->maxConnections = $this->_config['max_connections'];
        $this->_pop3Worker->maxRequests = $this->_config['max_requests'];
        $this->_pop3Worker->reusePort = $this->_config['reuse_port'];
        $this->_pop3Worker->onConnect = [$this, 'onPop3Connect'];
        $this->_pop3Worker->onMessage = [$this, 'onPop3Message'];
        $this->_pop3Worker->onClose = [$this, 'onPop3Close'];
        $this->_pop3Worker->onError = [$this, 'onPop3Error'];

        // 创建POP3 SSL服务
        if (!empty($this->_config['ssl_cert']) && !empty($this->_config['ssl_key'])) {
            $context = [
                'ssl' => [
                    'local_cert' => $this->_config['ssl_cert'],
                    'local_pk' => $this->_config['ssl_key'],
                    'verify_peer' => false
                ]
            ];
            $this->_pop3Worker = new Worker('ssl://0.0.0.0:' . $this->_config['pop3_ssl_port'], $context);
            $this->_pop3Worker->name = 'MailServer-POP3-SSL';
            $this->_pop3Worker->count = (int) ($this->_config['count'] ?? 4);
            $this->_pop3Worker->maxConnections = $this->_config['max_connections'];
            $this->_pop3Worker->maxRequests = $this->_config['max_requests'];
            $this->_pop3Worker->reusePort = $this->_config['reuse_port'];
            $this->_pop3Worker->onConnect = [$this, 'onPop3Connect'];
            $this->_pop3Worker->onMessage = [$this, 'onPop3Message'];
            $this->_pop3Worker->onClose = [$this, 'onPop3Close'];
            $this->_pop3Worker->onError = [$this, 'onPop3Error'];
        }

        // 创建IMAP服务
        $this->_imapWorker = new Worker('tcp://0.0.0.0:' . $this->_config['imap_port']);
        $this->_imapWorker->name = 'MailServer-IMAP';
        $this->_imapWorker->count = (int) ($this->_config['count'] ?? 4);
        $this->_imapWorker->maxConnections = $this->_config['max_connections'];
        $this->_imapWorker->maxRequests = $this->_config['max_requests'];
        $this->_imapWorker->reusePort = $this->_config['reuse_port'];
        $this->_imapWorker->onConnect = [$this, 'onImapConnect'];
        $this->_imapWorker->onMessage = [$this, 'onImapMessage'];
        $this->_imapWorker->onClose = [$this, 'onImapClose'];
        $this->_imapWorker->onError = [$this, 'onImapError'];

        // 创建IMAP SSL服务
        if (!empty($this->_config['ssl_cert']) && !empty($this->_config['ssl_key'])) {
            $context = [
                'ssl' => [
                    'local_cert' => $this->_config['ssl_cert'],
                    'local_pk' => $this->_config['ssl_key'],
                    'verify_peer' => false
                ]
            ];
            $this->_imapWorker = new Worker('ssl://0.0.0.0:' . $this->_config['imap_ssl_port'], $context);
            $this->_imapWorker->name = 'MailServer-IMAP-SSL';
            $this->_imapWorker->count = (int) ($this->_config['count'] ?? 4);
            $this->_imapWorker->maxConnections = $this->_config['max_connections'];
            $this->_imapWorker->maxRequests = $this->_config['max_requests'];
            $this->_imapWorker->reusePort = $this->_config['reuse_port'];
            $this->_imapWorker->onConnect = [$this, 'onImapConnect'];
            $this->_imapWorker->onMessage = [$this, 'onImapMessage'];
            $this->_imapWorker->onClose = [$this, 'onImapClose'];
            $this->_imapWorker->onError = [$this, 'onImapError'];
        }
    }

    /**
     * POP3连接回调
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    public function onPop3Connect(TcpConnection $connection)
    {
        $connection->state = 'AUTH';
        $connection->user = null;
        $connection->buffer = '';
        $connection->mailbox = [];
        
        $connection->send('+OK POP3 server ready <' . time() . '.' . mt_rand() . '@' . gethostname() . '>\r\n');
    }

    /**
     * POP3消息回调
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $data 数据
     * @return void
     */
    public function onPop3Message(TcpConnection $connection, $data)
    {
        $connection->buffer .= $data;
        
        while (strpos($connection->buffer, "\r\n") !== false) {
            $pos = strpos($connection->buffer, "\r\n");
            $command = substr($connection->buffer, 0, $pos);
            $connection->buffer = substr($connection->buffer, $pos + 2);
            
            $this->_processPop3Command($connection, $command);
        }
    }

    /**
     * 处理POP3命令
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $command 命令
     * @return void
     */
    protected function _processPop3Command(TcpConnection $connection, $command)
    {
        $parts = preg_split('/\s+/', trim($command));
        $cmd = strtoupper($parts[0]);
        $args = array_slice($parts, 1);
        
        match ($connection->state) {
            'AUTH' => $this->_pop3Auth($connection, $cmd, $args),
            'TRANSACTION' => $this->_pop3Transaction($connection, $cmd, $args),
            default => $this->_pop3UnknownState($connection),
        };
    }

    /**
     * POP3认证状态处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $cmd 命令
     * @param array $args 参数
     * @return void
     */
    protected function _pop3Auth(TcpConnection $connection, string $cmd, array $args): void
    {
        match ($cmd) {
            'USER' => $this->_pop3User($connection, $args),
            'PASS' => $this->_pop3Pass($connection, $args),
            'QUIT' => $this->_pop3Quit($connection),
            default => $this->_pop3UnknownCommand($connection),
        };
    }

    /**
     * POP3 USER命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param array $args 参数
     * @return void
     */
    protected function _pop3User(TcpConnection $connection, array $args): void
    {
        if (empty($args[0])) {
            $connection->send('-ERR USER command requires a parameter\r\n');
            return;
        }
        $connection->user = $args[0];
        $connection->send('+OK User accepted\r\n');
    }

    /**
     * POP3 PASS命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param array $args 参数
     * @return void
     */
    protected function _pop3Pass(TcpConnection $connection, array $args): void
    {
        if (empty($args[0])) {
            $connection->send('-ERR PASS command requires a parameter\r\n');
            return;
        }
        if (!$connection->user) {
            $connection->send('-ERR USER command required before PASS\r\n');
            return;
        }
        
        if ($this->_authenticate($connection->user, $args[0])) {
            $connection->state = 'TRANSACTION';
            $connection->mailbox = $this->_getMailbox($connection->user);
            $connection->send('+OK Authentication successful\r\n');
        } else {
            $connection->send('-ERR Authentication failed\r\n');
        }
    }

    /**
     * POP3 QUIT命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    protected function _pop3Quit(TcpConnection $connection): void
    {
        $connection->send('+OK POP3 server signing off\r\n');
        $connection->close();
    }

    /**
     * POP3未知命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    protected function _pop3UnknownCommand(TcpConnection $connection): void
    {
        $connection->send('-ERR Unknown command\r\n');
    }

    /**
     * POP3未知状态处理
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    protected function _pop3UnknownState(TcpConnection $connection): void
    {
        $connection->send('-ERR Unknown state\r\n');
    }

    /**
     * POP3事务状态处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $cmd 命令
     * @param array $args 参数
     * @return void
     */
    protected function _pop3Transaction(TcpConnection $connection, string $cmd, array $args): void
    {
        match ($cmd) {
            'STAT' => $this->_pop3Stat($connection),
            'LIST' => $this->_pop3List($connection),
            'RETR' => $this->_pop3Retr($connection, $args),
            'DELE' => $this->_pop3Dele($connection, $args),
            'RSET' => $this->_pop3Rset($connection),
            'QUIT' => $this->_pop3Quit($connection),
            default => $this->_pop3UnknownCommand($connection),
        };
    }

    /**
     * POP3 STAT命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    protected function _pop3Stat(TcpConnection $connection): void
    {
        $count = count($connection->mailbox);
        $size = array_sum(array_column($connection->mailbox, 'size'));
        $connection->send("+OK $count $size\r\n");
    }

    /**
     * POP3 LIST命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    protected function _pop3List(TcpConnection $connection): void
    {
        $connection->send('+OK Mailbox scan listing follows\r\n');
        foreach ($connection->mailbox as $index => $mail) {
            $connection->send("" . ($index + 1) . " " . $mail['size'] . "\r\n");
        }
        $connection->send('.\r\n');
    }

    /**
     * POP3 RETR命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param array $args 参数
     * @return void
     */
    protected function _pop3Retr(TcpConnection $connection, array $args): void
    {
        $index = (int)$args[0] - 1;
        if (isset($connection->mailbox[$index])) {
            $mail = $connection->mailbox[$index];
            $connection->send('+OK ' . $mail['size'] . ' octets\r\n');
            $connection->send($mail['content'] . '\r\n');
            $connection->send('.\r\n');
        } else {
            $connection->send('-ERR No such message\r\n');
        }
    }

    /**
     * POP3 DELE命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param array $args 参数
     * @return void
     */
    protected function _pop3Dele(TcpConnection $connection, array $args): void
    {
        $index = (int)$args[0] - 1;
        if (isset($connection->mailbox[$index])) {
            unset($connection->mailbox[$index]);
            $connection->mailbox = array_values($connection->mailbox);
            $this->_saveMailbox($connection->user, $connection->mailbox);
            $connection->send('+OK Message deleted\r\n');
        } else {
            $connection->send('-ERR No such message\r\n');
        }
    }

    /**
     * POP3 RSET命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    protected function _pop3Rset(TcpConnection $connection): void
    {
        $connection->mailbox = $this->_getMailbox($connection->user);
        $connection->send('+OK Mailbox reset\r\n');
    }

    /**
     * POP3关闭回调
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    public function onPop3Close(TcpConnection $connection)
    {
    }

    /**
     * POP3错误回调
     * 
     * @param TcpConnection $connection 连接对象
     * @param int $code 错误码
     * @param string $msg 错误信息
     * @return void
     */
    public function onPop3Error(TcpConnection $connection, $code, $msg)
    {
    }

    /**
     * IMAP连接回调
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    public function onImapConnect(TcpConnection $connection)
    {
        $connection->state = 'AUTH';
        $connection->user = null;
        $connection->buffer = '';
        $connection->mailbox = [];
        $connection->tag = 1;
        
        $connection->send('* OK [CAPABILITY IMAP4rev1 LITERAL+ SASL-IR LOGIN-REFERRALS ID ENABLE IDLE AUTH=PLAIN] IMAP server ready <' . time() . '.' . mt_rand() . '@' . gethostname() . '>\r\n');
    }

    /**
     * IMAP消息回调
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $data 数据
     * @return void
     */
    public function onImapMessage(TcpConnection $connection, $data)
    {
        $connection->buffer .= $data;
        
        while (strpos($connection->buffer, "\r\n") !== false) {
            $pos = strpos($connection->buffer, "\r\n");
            $command = substr($connection->buffer, 0, $pos);
            $connection->buffer = substr($connection->buffer, $pos + 2);
            
            $this->_processImapCommand($connection, $command);
        }
    }

    /**
     * 处理IMAP命令
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $command 命令
     * @return void
     */
    protected function _processImapCommand(TcpConnection $connection, $command)
    {
        $parts = preg_split('/\s+/', trim($command), 3);
        $tag = $parts[0];
        $cmd = strtoupper($parts[1]);
        $args = isset($parts[2]) ? $parts[2] : '';
        
        match ($connection->state) {
            'AUTH' => $this->_imapAuth($connection, $cmd, $args, $tag),
            'SELECTED' => $this->_imapSelected($connection, $cmd, $args, $tag),
            default => $this->_imapUnknownState($connection, $tag),
        };
    }

    /**
     * IMAP认证状态处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $cmd 命令
     * @param string $args 参数
     * @param string $tag 标签
     * @return void
     */
    protected function _imapAuth(TcpConnection $connection, string $cmd, string $args, string $tag): void
    {
        match ($cmd) {
            'LOGIN' => $this->_imapLogin($connection, $args, $tag),
            'LOGOUT' => $this->_imapLogout($connection, $tag),
            default => $this->_imapUnknownCommand($connection, $tag),
        };
    }

    /**
     * IMAP LOGIN命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $args 参数
     * @param string $tag 标签
     * @return void
     */
    protected function _imapLogin(TcpConnection $connection, string $args, string $tag): void
    {
        $loginParts = preg_split('/\s+/', $args, 2);
        $user = $loginParts[0];
        $pass = isset($loginParts[1]) ? $loginParts[1] : '';
        
        if ($this->_authenticate($user, $pass)) {
            $connection->state = 'SELECTED';
            $connection->user = $user;
            $connection->mailbox = $this->_getMailbox($user);
            $connection->send('* CAPABILITY IMAP4rev1 LITERAL+ SASL-IR LOGIN-REFERRALS ID ENABLE IDLE AUTH=PLAIN\r\n');
            $connection->send($tag . ' OK LOGIN completed\r\n');
        } else {
            $connection->send($tag . ' NO LOGIN failed\r\n');
        }
    }

    /**
     * IMAP LOGOUT命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $tag 标签
     * @return void
     */
    protected function _imapLogout(TcpConnection $connection, string $tag): void
    {
        $connection->send('* BYE IMAP server signing off\r\n');
        $connection->send($tag . ' OK LOGOUT completed\r\n');
        $connection->close();
    }

    /**
     * IMAP未知命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $tag 标签
     * @return void
     */
    protected function _imapUnknownCommand(TcpConnection $connection, string $tag): void
    {
        $connection->send($tag . ' BAD Unknown command\r\n');
    }

    /**
     * IMAP未知状态处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $tag 标签
     * @return void
     */
    protected function _imapUnknownState(TcpConnection $connection, string $tag): void
    {
        $connection->send('* BAD Unknown state\r\n');
    }

    /**
     * IMAP已选择状态处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $cmd 命令
     * @param string $args 参数
     * @param string $tag 标签
     * @return void
     */
    protected function _imapSelected(TcpConnection $connection, string $cmd, string $args, string $tag): void
    {
        match ($cmd) {
            'SELECT' => $this->_imapSelect($connection, $args, $tag),
            'SEARCH' => $this->_imapSearch($connection, $tag),
            'FETCH' => $this->_imapFetch($connection, $args, $tag),
            'STORE' => $this->_imapStore($connection, $args, $tag),
            'EXPUNGE' => $this->_imapExpunge($connection, $tag),
            'LOGOUT' => $this->_imapLogout($connection, $tag),
            default => $this->_imapUnknownCommand($connection, $tag),
        };
    }

    /**
     * IMAP SELECT命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $args 参数
     * @param string $tag 标签
     * @return void
     */
    protected function _imapSelect(TcpConnection $connection, string $args, string $tag): void
    {
        $mailbox = trim($args, '"');
        if ($mailbox === 'INBOX') {
            $count = count($connection->mailbox);
            $connection->send('* ' . $count . ' EXISTS\r\n');
            $connection->send('* 0 RECENT\r\n');
            $connection->send('* OK [UNSEEN 1] First unseen message\r\n');
            $connection->send('* OK [UIDVALIDITY ' . time() . '] UIDs valid\r\n');
            $connection->send('* OK [UIDNEXT ' . ($count + 1) . '] Predicted next UID\r\n');
            $connection->send('* FLAGS (\Answered \Flagged \Deleted \Seen \Draft)\r\n');
            $connection->send('* OK [PERMANENTFLAGS (\Answered \Flagged \Deleted \Seen \Draft \*)] Permanent flags\r\n');
            $connection->send($tag . ' OK [READ-WRITE] SELECT completed\r\n');
        } else {
            $connection->send($tag . ' NO Mailbox does not exist\r\n');
        }
    }

    /**
     * IMAP SEARCH命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $tag 标签
     * @return void
     */
    protected function _imapSearch(TcpConnection $connection, string $tag): void
    {
        $count = count($connection->mailbox);
        $connection->send('* SEARCH ');
        for ($i = 1; $i <= $count; $i++) {
            $connection->send($i . ' ');
        }
        $connection->send('\r\n');
        $connection->send($tag . ' OK SEARCH completed\r\n');
    }

    /**
     * IMAP FETCH命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $args 参数
     * @param string $tag 标签
     * @return void
     */
    protected function _imapFetch(TcpConnection $connection, string $args, string $tag): void
    {
        $fetchParts = preg_split('/\s+/', $args);
        $mailId = $fetchParts[0];
        $index = (int)$mailId - 1;
        
        if (isset($connection->mailbox[$index])) {
            $mail = $connection->mailbox[$index];
            $connection->send('* ' . $mailId . ' FETCH (FLAGS (\Seen) BODY[] {1:' . $mail['size'] . '})\r\n');
            $connection->send('(' . strlen($mail['content']) . ' {1}\r\n');
            $connection->send($mail['content'] . '\r\n');
            $connection->send(')\r\n');
            $connection->send($tag . ' OK FETCH completed\r\n');
        } else {
            $connection->send($tag . ' NO No such message\r\n');
        }
    }

    /**
     * IMAP STORE命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $args 参数
     * @param string $tag 标签
     * @return void
     */
    protected function _imapStore(TcpConnection $connection, string $args, string $tag): void
    {
        $storeParts = preg_split('/\s+/', $args);
        $mailId = $storeParts[0];
        $index = (int)$mailId - 1;
        
        if (isset($connection->mailbox[$index])) {
            $connection->send('* ' . $mailId . ' FETCH (FLAGS (\Deleted))\r\n');
            $connection->send($tag . ' OK STORE completed\r\n');
        } else {
            $connection->send($tag . ' NO No such message\r\n');
        }
    }

    /**
     * IMAP EXPUNGE命令处理
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $tag 标签
     * @return void
     */
    protected function _imapExpunge(TcpConnection $connection, string $tag): void
    {
        $connection->send($tag . ' OK EXPUNGE completed\r\n');
    }

    /**
     * IMAP关闭回调
     * 
     * @param TcpConnection $connection 连接对象
     * @return void
     */
    public function onImapClose(TcpConnection $connection)
    {
    }

    /**
     * IMAP错误回调
     * 
     * @param TcpConnection $connection 连接对象
     * @param int $code 错误码
     * @param string $msg 错误信息
     * @return void
     */
    public function onImapError(TcpConnection $connection, $code, $msg)
    {
    }

    /**
     * 用户认证
     * 
     * @param string $user 用户名
     * @param string $pass 密码
     * @return bool 认证成功返回true，失败返回false
     */
    protected function _authenticate($user, $pass)
    {
        if ($this->_onAuth) {
            return call_user_func($this->_onAuth, $user, $pass);
        }
        
        return $user === 'test' && $pass === 'test';
    }

    /**
     * 获取邮箱
     * 
     * @param string $user 用户名
     * @return array 邮箱内容
     */
    protected function _getMailbox($user)
    {
        return [
            [
                'id' => 1,
                'size' => 1024,
                'content' => "From: sender@example.com\r\n" .
                            "To: recipient@example.com\r\n" .
                            "Subject: Test Email\r\n" .
                            "Date: " . date('r') . "\r\n" .
                            "\r\n" .
                            "This is a test email.\r\n"
            ],
            [
                'id' => 2,
                'size' => 2048,
                'content' => "From: sender2@example.com\r\n" .
                            "To: recipient@example.com\r\n" .
                            "Subject: Another Test Email\r\n" .
                            "Date: " . date('r') . "\r\n" .
                            "\r\n" .
                            "This is another test email.\r\n"
            ]
        ];
    }

    /**
     * 保存邮箱
     * 
     * @param string $user 用户名
     * @param array $mailbox 邮箱内容
     * @return bool 保存成功返回true，失败返回false
     */
    protected function _saveMailbox($user, $mailbox)
    {
        return true;
    }

    /**
     * 设置用户认证回调
     * 
     * @param callable $callback 回调函数
     * @return void
     */
    public function onAuth(callable $callback)
    {
        $this->_onAuth = $callback;
    }

    /**
     * 设置存储接口
     * 
     * @param mixed $storage 存储接口
     * @return void
     */
    public function setStorage($storage)
    {
        $this->_storage = $storage;
    }

    /**
     * 运行服务器
     * 
     * @return void
     */
    public function run()
    {
        Worker::runAll();
    }

    /**
     * 停止服务器
     * 
     * @return void
     */
    public function stop()
    {
        Worker::stopAll();
    }
}
