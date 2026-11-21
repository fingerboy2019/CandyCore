<?php
/**
 * FTP 服务器（基于 Workerman）
 *
 * 提供标准 FTP 协议服务：认证、目录浏览（LIST/NLST）、上传下载（STOR/RETR）、
 * 目录管理（MKD/RMD/CWD/CDUP）、删除/重命名（DELE/RNFR/RNTO）、断点续传（REST）、
 * 被动模式（PASV/EPSV）与主动模式（PORT）。
 *
 * 相对早期版本的主要修复：
 * - TCP 行缓冲：onMessage 按 \r\n 切分命令，解决粘包/拆包
 * - 补全核心命令（原实现只有 10 个命令，无法传输任何文件）
 * - 虚拟路径 -> 真实路径映射并约束在 root_dir 内，杜绝 CWD /etc 越权
 * - PASV/EPSV 动态端口监听：Worker 对象持有引用保活，端口冲突自动重试
 * - PORT 主动模式：AsyncTcpConnection 异步连接客户端
 * - 密码 password_hash 存储 + 基于 IP 的失败锁定（文件持久化，跨进程一致）
 * - 统一 USER 响应，避免用户枚举
 *
 * 使用示例：
 * ```php
 * $server = new Ftp\Server('0.0.0.0', 21, '/data/ftp', ['admin' => 'secret'], [50000, 60000]);
 * $server->run();
 * ```
 *
 * @package Ftp
 */
namespace Ftp;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

class Server
{
    /** 数据块大小（传输用） */
    private const CHUNK_SIZE = 65536;

    /** 数据连接发送缓冲上限（超过则等 onBufferDrain 继续） */
    private const SEND_BUFFER_LIMIT = 8388608;

    /** 被动端口分配尝试次数 */
    private const PASV_ATTEMPTS = 30;

    private $worker;

    /** 授权用户：用户名 => password_hash 后的密码 */
    private $users = [];

    /** 真实根目录 */
    private $root_dir;

    /** 被动端口范围 [lo, hi] */
    private $passive_port_range = [50000, 60000];

    /** 已监听的被动数据端口 => Worker（静态数组持有引用，保活） */
    private $passive_workers = [];

    /** 进程内认证失败计数缓存 [ip => ['count'=>n,'locked_until'=>ts]] */
    private $auth_attempts = [];

    /** 认证计数持久化文件 */
    private $auth_file;

    /** 最大失败次数 */
    private $max_attempts = 5;

    /** 锁定时间（秒） */
    private $lockout_time = 300;

    /**
     * 构造 FTP 服务器
     *
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param string|null $root_dir 数据根目录，默认 CANDYPATH . 'Ftp'
     * @param array $users 授权用户，格式 ['用户名' => '密码或已有hash']
     * @param array $passive_range 被动端口范围 [lo, hi]
     */
    public function __construct(string $host = '0.0.0.0', int $port = 21, ?string $root_dir = null, array $users = [], array $passive_range = [50000, 60000])
    {
        foreach ($users as $username => $password) {
            $this->users[$username] = $this->isPasswordHash((string) $password)
                ? (string) $password
                : password_hash((string) $password, PASSWORD_DEFAULT);
        }
        if (count($passive_range) === 2) {
            $this->passive_port_range = [(int) $passive_range[0], (int) $passive_range[1]];
        }

        $this->root_dir = $root_dir ?: (defined('CANDYPATH') ? CANDYPATH . 'Ftp' : sys_get_temp_dir() . '/candy-ftp');
        $this->root_dir = rtrim(str_replace('\\', '/', $this->root_dir), '/');
        if (!is_dir($this->root_dir)) {
            @mkdir($this->root_dir, 0777, true);
        }

        // 认证计数持久化文件（放 root 之外，避免被 FTP 暴露）
        $runtime_dir = (defined('CANDYPATH') ? CANDYPATH . 'Runtime/Ftp' : sys_get_temp_dir() . '/candy-ftp-runtime');
        $runtime_dir = str_replace('\\', '/', $runtime_dir);
        if (!is_dir($runtime_dir)) {
            @mkdir($runtime_dir, 0777, true);
        }
        $this->auth_file = $runtime_dir . '/auth.json';

        $this->worker = new Worker("tcp://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'FtpServer';
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    /**
     * 启动服务器（Workerman 依据 argv 自动分发 start/stop/restart/reload/status）
     *
     * @return void
     */
    public function run(): void
    {
        Worker::runAll();
    }

    /* =====================================================================
     * 连接生命周期
     * ===================================================================== */

    /**
     * 连接建立：初始化会话状态并发送欢迎语
     *
     * @param TcpConnection $connection 控制连接
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        $connection->ftp = [
            'authenticated' => false,
            'username' => null,
            'cwd' => '/',
            'type' => 'A',
            'data_connection' => null,
            'data_worker_port' => null,
            'port_address' => null,
            'rest_offset' => 0,
            'rnfr' => null,
            'transfer' => null,
            'pending_transfer' => null,
            'buffer' => '',
        ];
        $connection->send("220 Welcome to Candy FTP Server\r\n");
    }

    /**
     * 控制连接收到数据：行缓冲后逐条分发命令
     *
     * @param TcpConnection $connection 控制连接
     * @param string $data 字节流
     * @return void
     */
    public function onMessage(TcpConnection $connection, $data): void
    {
        $state = &$connection->ftp;
        $state['buffer'] .= $data;
        while (($pos = strpos($state['buffer'], "\n")) !== false) {
            $line = substr($state['buffer'], 0, $pos);
            $state['buffer'] = substr($state['buffer'], $pos + 1);
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            $this->dispatch($connection, $line);
        }
    }

    /**
     * 控制连接关闭：清理被动监听与数据连接
     *
     * @param TcpConnection $connection 控制连接
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $this->cleanupDataConnection($connection);
        $transfer = $connection->ftp['transfer'] ?? null;
        if ($transfer && $transfer['handle']) {
            @fclose($transfer['handle']);
        }
    }

    /* =====================================================================
     * 命令分发
     * ===================================================================== */

    /**
     * 解析并分发单条 FTP 命令
     *
     * @param TcpConnection $connection 控制连接
     * @param string $line 命令行
     * @return void
     */
    private function dispatch(TcpConnection $connection, string $line): void
    {
        $spacePos = strpos($line, ' ');
        if ($spacePos === false) {
            $cmd = strtoupper($line);
            $param = '';
        } else {
            $cmd = strtoupper(substr($line, 0, $spacePos));
            $param = ltrim(substr($line, $spacePos + 1));
        }

        // 认证前的命令
        switch ($cmd) {
            case 'USER':
                $this->handleUser($connection, $param);
                return;
            case 'PASS':
                $this->handlePass($connection, $param);
                return;
            case 'QUIT':
                $connection->send("221 Goodbye\r\n");
                $connection->close();
                return;
            case 'NOOP':
                $connection->send("200 OK\r\n");
                return;
            case 'SYST':
                $connection->send("215 UNIX Type: L8\r\n");
                return;
            case 'FEAT':
                $connection->send("211-Features:\r\n EPSV\r\n PASV\r\n SIZE\r\n MDTM\r\n REST STREAM\r\n UTF8\r\n211 End\r\n");
                return;
            case 'OPTS':
                $connection->send("200 OK\r\n");
                return;
        }

        if (!$connection->ftp['authenticated']) {
            $connection->send("530 Please login with USER and PASS.\r\n");
            return;
        }

        switch ($cmd) {
            case 'PWD':
            case 'XPWD':
                $connection->send('257 "' . $this->escapeFtpString($connection->ftp['cwd']) . '" is the current directory' . "\r\n");
                break;
            case 'TYPE':
                $this->handleType($connection, $param);
                break;
            case 'PASV':
                $this->handlePasv($connection);
                break;
            case 'EPSV':
                $this->handleEpsv($connection, $param);
                break;
            case 'PORT':
                $this->handlePort($connection, $param);
                break;
            case 'LIST':
                $this->handleList($connection, $param, false);
                break;
            case 'NLST':
                $this->handleList($connection, $param, true);
                break;
            case 'CWD':
            case 'XCWD':
                $this->handleCwd($connection, $param);
                break;
            case 'CDUP':
            case 'XCUP':
                $this->handleCwd($connection, '..');
                break;
            case 'MKD':
            case 'XMKD':
                $this->handleMkd($connection, $param);
                break;
            case 'RMD':
            case 'XRMD':
                $this->handleRmd($connection, $param);
                break;
            case 'DELE':
                $this->handleDele($connection, $param);
                break;
            case 'RNFR':
                $this->handleRnfr($connection, $param);
                break;
            case 'RNTO':
                $this->handleRnto($connection, $param);
                break;
            case 'SIZE':
                $this->handleSize($connection, $param);
                break;
            case 'MDTM':
                $this->handleMdtm($connection, $param);
                break;
            case 'REST':
                $this->handleRest($connection, $param);
                break;
            case 'RETR':
                $this->handleRetr($connection, $param);
                break;
            case 'STOR':
                $this->handleStor($connection, $param);
                break;
            case 'ABOR':
                $this->cleanupDataConnection($connection);
                $connection->send("226 Abort successful.\r\n");
                break;
            case 'STAT':
                $connection->send("211-FTP server status:\r\n Connected, logged in.\r\n211 End of status\r\n");
                break;
            case 'HELP':
                $connection->send("214-Commands:\r\n USER PASS QUIT NOOP SYST FEAT PWD TYPE PASV EPSV PORT LIST NLST CWD CDUP MKD RMD DELE RNFR RNTO SIZE MDTM REST RETR STOR ABOR\r\n214 End\r\n");
                break;
            case 'SITE':
            case 'STOU':
                $connection->send("502 Command not implemented.\r\n");
                break;
            default:
                $connection->send("502 Command not implemented.\r\n");
        }
    }

    /* =====================================================================
     * 认证
     * ===================================================================== */

    /**
     * USER：统一回 331（不泄露用户是否存在）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $username 用户名
     * @return void
     */
    private function handleUser(TcpConnection $connection, string $username): void
    {
        if ($username === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $connection->ftp['username'] = $username;
        $connection->ftp['authenticated'] = false;
        $connection->send("331 Password required for {$username}\r\n");
    }

    /**
     * PASS：校验密码，支持基于 IP 的失败锁定（计数持久化，多进程一致）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $password 密码
     * @return void
     */
    private function handlePass(TcpConnection $connection, string $password): void
    {
        $username = $connection->ftp['username'];
        if ($username === null) {
            $connection->send("503 Login with USER first.\r\n");
            return;
        }

        $client_ip = $connection->getRemoteIp();
        $this->loadAuthAttempts();
        if (isset($this->auth_attempts[$client_ip]) && $this->auth_attempts[$client_ip]['locked_until'] > time()) {
            $retry = $this->auth_attempts[$client_ip]['locked_until'] - time();
            $connection->send("421 Too many failed attempts. Retry after {$retry}s.\r\n");
            $connection->close();
            return;
        }

        if (isset($this->users[$username]) && password_verify($password, $this->users[$username])) {
            $connection->ftp['authenticated'] = true;
            $connection->ftp['cwd'] = '/';
            // 成功：重置失败计数
            $this->auth_attempts[$client_ip] = ['count' => 0, 'locked_until' => 0];
            $this->saveAuthAttempts();
            $connection->send("230 User {$username} logged in.\r\n");
            return;
        }

        // 失败：计数 +1，达到上限触发锁定
        $this->auth_attempts[$client_ip] = $this->auth_attempts[$client_ip] ?? ['count' => 0, 'locked_until' => 0];
        $this->auth_attempts[$client_ip]['count']++;
        if ($this->auth_attempts[$client_ip]['count'] >= $this->max_attempts) {
            $this->auth_attempts[$client_ip]['locked_until'] = time() + $this->lockout_time;
            $this->auth_attempts[$client_ip]['count'] = 0;
        }
        $this->saveAuthAttempts();
        $connection->send("530 Login incorrect.\r\n");
    }

    /**
     * 从文件加载认证失败计数（每次强制重读，保证多进程一致）
     *
     * @return void
     */
    private function loadAuthAttempts(): void
    {
        if (is_file($this->auth_file)) {
            $raw = @file_get_contents($this->auth_file);
            $data = $raw ? json_decode($raw, true) : null;
            $this->auth_attempts = is_array($data) ? $data : [];
        } else {
            $this->auth_attempts = [];
        }
    }

    /**
     * 持久化认证失败计数（flock 保护 + 合并写，避免覆盖其他进程记录）
     *
     * @return void
     */
    private function saveAuthAttempts(): void
    {
        $fp = @fopen($this->auth_file, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            rewind($fp);
            $existing = $raw ? json_decode($raw, true) : null;
            if (is_array($existing)) {
                $this->auth_attempts = array_merge($existing, $this->auth_attempts);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($this->auth_attempts));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * 判断密码字符串是否为已生成的 password_hash
     *
     * @param string $password 密码
     * @return bool
     */
    private function isPasswordHash(string $password): bool
    {
        return str_starts_with($password, '$2y$') || str_starts_with($password, '$2a$')
            || str_starts_with($password, '$argon2');
    }

    /* =====================================================================
     * 路径处理（虚拟路径 -> 真实路径，约束在根目录内）
     * ===================================================================== */

    /**
     * 结合当前目录解析参数为虚拟路径（/ 开头，已规范化）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 命令参数
     * @return string 规范化后的虚拟路径
     */
    private function resolveVirtual(TcpConnection $connection, string $param): string
    {
        $param = str_replace('\\', '/', trim($param));
        if ($param === '') {
            return $connection->ftp['cwd'];
        }
        $virtual = $param[0] === '/' ? $param : $connection->ftp['cwd'] . '/' . $param;
        return $this->normalizeVirtual($virtual);
    }

    /**
     * 规范化虚拟路径（解析 . / ..，保证 / 开头且不越出根目录）
     *
     * @param string $virtual 虚拟路径
     * @return string 规范化后的虚拟路径
     */
    private function normalizeVirtual(string $virtual): string
    {
        $segments = [];
        foreach (explode('/', $virtual) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        return '/' . implode('/', $segments);
    }

    /**
     * 虚拟路径转真实路径（约束在 root_dir 内）
     *
     * @param string $virtual 规范化虚拟路径
     * @return string 真实路径
     */
    private function toRealPath(string $virtual): string
    {
        $real = $this->root_dir;
        foreach (explode('/', trim($virtual, '/')) as $seg) {
            if ($seg !== '') {
                $real .= '/' . $seg;
            }
        }
        return $real;
    }

    /* =====================================================================
     * 目录与文件命令
     * ===================================================================== */

    /**
     * TYPE：设置传输模式（A=ASCII，I=Binary）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $type 类型
     * @return void
     */
    private function handleType(TcpConnection $connection, string $type): void
    {
        $type = strtoupper(trim($type));
        // 兼容 "A N"、"I 8" 等带次要参数的形式
        $type = strtok($type, ' ');
        if ($type === 'A' || $type === 'I') {
            $connection->ftp['type'] = $type;
            $connection->send("200 Type set to {$type}.\r\n");
        } else {
            $connection->send("504 Type not supported.\r\n");
        }
    }

    /**
     * PWD：已在 dispatch 中内联处理
     */

    /**
     * CWD：切换目录（虚拟路径约束在根目录内）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 目标目录
     * @return void
     */
    private function handleCwd(TcpConnection $connection, string $param): void
    {
        $virtual = $this->resolveVirtual($connection, $param);
        $real = $this->toRealPath($virtual);
        if (is_dir($real)) {
            $connection->ftp['cwd'] = $virtual;
            $connection->send("250 Directory successfully changed.\r\n");
        } else {
            $connection->send("550 Failed to change directory.\r\n");
        }
    }

    /**
     * MKD：创建目录
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 目录名
     * @return void
     */
    private function handleMkd(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $virtual = $this->resolveVirtual($connection, $param);
        $real = $this->toRealPath($virtual);
        if (file_exists($real)) {
            $connection->send("550 Directory already exists.\r\n");
            return;
        }
        if (@mkdir($real, 0777)) {
            $connection->send('257 "' . $this->escapeFtpString($virtual) . '" created' . "\r\n");
        } else {
            $connection->send("550 Create directory failed.\r\n");
        }
    }

    /**
     * RMD：删除空目录
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 目录名
     * @return void
     */
    private function handleRmd(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $real = $this->toRealPath($this->resolveVirtual($connection, $param));
        if (!is_dir($real)) {
            $connection->send("550 Directory not found.\r\n");
            return;
        }
        if (@rmdir($real)) {
            $connection->send("250 Directory removed.\r\n");
        } else {
            $connection->send("550 Directory not empty or failed.\r\n");
        }
    }

    /**
     * DELE：删除文件
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 文件名
     * @return void
     */
    private function handleDele(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $real = $this->toRealPath($this->resolveVirtual($connection, $param));
        if (!is_file($real)) {
            $connection->send("550 File not found.\r\n");
            return;
        }
        if (@unlink($real)) {
            $connection->send("250 File deleted.\r\n");
        } else {
            $connection->send("550 Delete failed.\r\n");
        }
    }

    /**
     * RNFR：暂存重命名源
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 源路径
     * @return void
     */
    private function handleRnfr(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $real = $this->toRealPath($this->resolveVirtual($connection, $param));
        if (!file_exists($real)) {
            $connection->send("550 File or directory not found.\r\n");
            return;
        }
        $connection->ftp['rnfr'] = $real;
        $connection->send("350 Ready for RNTO.\r\n");
    }

    /**
     * RNTO：执行重命名（需先 RNFR）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 目标路径
     * @return void
     */
    private function handleRnto(TcpConnection $connection, string $param): void
    {
        $src = $connection->ftp['rnfr'];
        $connection->ftp['rnfr'] = null;
        if ($src === null || $param === '') {
            $connection->send("503 Bad sequence of commands.\r\n");
            return;
        }
        $dest = $this->toRealPath($this->resolveVirtual($connection, $param));
        if (@rename($src, $dest)) {
            $connection->send("250 Rename successful.\r\n");
        } else {
            $connection->send("550 Rename failed.\r\n");
        }
    }

    /**
     * SIZE：返回文件大小
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 文件名
     * @return void
     */
    private function handleSize(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $real = $this->toRealPath($this->resolveVirtual($connection, $param));
        if (is_file($real)) {
            $connection->send('213 ' . filesize($real) . "\r\n");
        } else {
            $connection->send("550 File not found.\r\n");
        }
    }

    /**
     * MDTM：返回文件修改时间
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 文件名
     * @return void
     */
    private function handleMdtm(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $real = $this->toRealPath($this->resolveVirtual($connection, $param));
        if (is_file($real)) {
            $connection->send('213 ' . gmdate('YmdHis', filemtime($real)) . "\r\n");
        } else {
            $connection->send("550 File not found.\r\n");
        }
    }

    /**
     * REST：设置断点续传偏移（仅对 RETR 生效）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 偏移量
     * @return void
     */
    private function handleRest(TcpConnection $connection, string $param): void
    {
        if (!ctype_digit(trim($param))) {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $connection->ftp['rest_offset'] = (int) trim($param);
        $connection->send('350 Restart position accepted (' . $connection->ftp['rest_offset'] . ").\r\n");
    }

    /* =====================================================================
     * 数据连接（PASV / EPSV / PORT）
     * ===================================================================== */

    /**
     * PASV：开启被动模式监听
     *
     * @param TcpConnection $connection 控制连接
     * @return void
     */
    private function handlePasv(TcpConnection $connection): void
    {
        $this->cleanupDataConnection($connection);
        $port = $this->openPassive($connection);
        if ($port === null) {
            $connection->send("425 Can't open passive connection.\r\n");
            return;
        }
        $ip = $connection->getLocalIp();
        $p1 = intdiv($port, 256);
        $p2 = $port % 256;
        $connection->send('227 Entering Passive Mode (' . str_replace('.', ',', $ip) . ",{$p1},{$p2})\r\n");
    }

    /**
     * EPSV：扩展被动模式（返回 |||port|）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 忽略（标准客户端可能发 "ALL"）
     * @return void
     */
    private function handleEpsv(TcpConnection $connection, string $param): void
    {
        $this->cleanupDataConnection($connection);
        $port = $this->openPassive($connection);
        if ($port === null) {
            $connection->send("425 Can't open passive connection.\r\n");
            return;
        }
        $connection->send('229 Entering Extended Passive Mode (|||' . $port . "|)\r\n");
    }

    /**
     * PORT：主动模式，记录客户端数据地址
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param h1,h2,h3,h4,p1,p2
     * @return void
     */
    private function handlePort(TcpConnection $connection, string $param): void
    {
        if (!preg_match('/^(\d{1,3}),(\d{1,3}),(\d{1,3}),(\d{1,3}),(\d{1,3}),(\d{1,3})$/', trim($param), $m)) {
            $connection->send("501 Syntax error in parameters.\r\n");
            return;
        }
        $ip = "{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}";
        $port = (int) $m[5] * 256 + (int) $m[6];
        $connection->ftp['port_address'] = [$ip, $port];
        $connection->ftp['data_connection'] = null;
        $connection->send("200 PORT command successful.\r\n");
    }

    /**
     * 开启被动数据监听（随机端口，冲突重试；Worker 持有引用保活）
     *
     * @param TcpConnection $control 控制连接
     * @return int|null 端口号
     */
    private function openPassive(TcpConnection $control): ?int
    {
        [$lo, $hi] = $this->passive_port_range;
        for ($i = 0; $i < self::PASV_ATTEMPTS; $i++) {
            $port = mt_rand($lo, $hi);
            if (isset($this->passive_workers[$port])) {
                continue;
            }
            try {
                $w = new Worker("tcp://0.0.0.0:{$port}");
                $w->reusePort = false;
                $w->onConnect = function ($conn) use ($control, $port) {
                    $control->ftp['data_connection'] = $conn;
                    $control->ftp['data_worker_port'] = $port;
                    // 兼容“先发数据命令、后连数据端口”的客户端（如 PHP ftp 扩展）：
                    // 命令已挂起待办时，连接建立后立即执行
                    $pending = $control->ftp['pending_transfer'] ?? null;
                    if ($pending) {
                        $control->ftp['pending_transfer'] = null;
                        $pending($conn);
                    }
                };
                $w->onMessage = function ($conn, $data) use ($control) {
                    $this->onDataMessage($control, $conn, $data);
                };
                $w->onClose = function ($conn) use ($control) {
                    $this->onDataClose($control, $conn);
                };
                $w->listen();
                $this->passive_workers[$port] = $w;
                return $port;
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    /**
     * 确保数据连接就绪（被动已建立则直接用，主动模式异步连接客户端）
     *
     * @param TcpConnection $control 控制连接
     * @param callable $onReady 数据连接就绪回调（参数：数据连接）
     * @return bool 是否已发起（被动已就绪时同步回调）
     */
    private function ensureDataConnection(TcpConnection $control, callable $onReady): bool
    {
        $dataConn = $control->ftp['data_connection'] ?? null;
        if ($dataConn instanceof TcpConnection && $dataConn->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            $onReady($dataConn);
            return true;
        }

        // PORT 主动模式
        $portAddress = $control->ftp['port_address'] ?? null;
        if ($portAddress === null) {
            $control->send("425 Use PORT or PASV first.\r\n");
            return false;
        }
        [$ip, $port] = $portAddress;
        try {
            $conn = new AsyncTcpConnection("tcp://{$ip}:{$port}");
        } catch (\Throwable $e) {
            $control->send("425 Can't open data connection.\r\n");
            return false;
        }
        $conn->onConnect = function ($c) use ($control, $onReady) {
            $control->ftp['data_connection'] = $c;
            $onReady($c);
        };
        $conn->onError = function () use ($control) {
            $control->send("425 Can't open data connection.\r\n");
        };
        $conn->onMessage = function ($c, $data) use ($control) {
            $this->onDataMessage($control, $c, $data);
        };
        $conn->onClose = function ($c) use ($control) {
            $this->onDataClose($control, $c);
        };
        $conn->connect();
        return true;
    }

    /**
     * 数据连接收到数据（STOR 写入）
     *
     * @param TcpConnection $control 控制连接
     * @param TcpConnection $data 数据连接
     * @param string $chunk 数据块
     * @return void
     */
    private function onDataMessage(TcpConnection $control, TcpConnection $data, string $chunk): void
    {
        $transfer = $control->ftp['transfer'] ?? null;
        if (!$transfer || $transfer['mode'] !== 'STOR' || !$transfer['handle']) {
            return;
        }
        if ($transfer['type'] === 'A') {
            $chunk = str_replace("\r\n", "\n", $chunk);
        }
        $written = @fwrite($transfer['handle'], $chunk);
        if ($written === false) {
            return;
        }
        $control->ftp['transfer']['offset'] = $transfer['offset'] + $written;
    }

    /**
     * 数据连接关闭（STOR 收尾 / 异常清理）
     *
     * @param TcpConnection $control 控制连接
     * @param TcpConnection $data 数据连接
     * @return void
     */
    private function onDataClose(TcpConnection $control, TcpConnection $data): void
    {
        $transfer = $control->ftp['transfer'] ?? null;
        if ($transfer && $transfer['mode'] === 'STOR' && $transfer['handle']) {
            @fclose($transfer['handle']);
            $control->ftp['transfer'] = null;
            // 先发送完成响应，再清理数据通道（避免清理中断控制连接发送）
            $control->send("226 Transfer complete.\r\n");
            $this->cleanupDataConnection($control);
            return;
        }
        // 其他情况：仅清理引用（RETR 完成后由服务端主动 close，transfer 已清空）
        $this->cleanupDataConnection($control);
    }

    /**
     * 清理控制连接上的数据连接引用与被动监听
     *
     * @param TcpConnection $control 控制连接
     * @return void
     */
    private function cleanupDataConnection(TcpConnection $control): void
    {
        $port = $control->ftp['data_worker_port'] ?? null;
        if ($port !== null && isset($this->passive_workers[$port])) {
            $worker = $this->passive_workers[$port];
            unset($this->passive_workers[$port]);
            $worker->unlisten(); // 官方 API：pauseAccept + fclose，避免裸 fclose 破坏事件循环
        }
        $dataConn = $control->ftp['data_connection'] ?? null;
        if ($dataConn instanceof TcpConnection && $dataConn->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            $dataConn->close();
        }
        $control->ftp['data_connection'] = null;
        $control->ftp['data_worker_port'] = null;
        $control->ftp['pending_transfer'] = null;
    }

    /* =====================================================================
     * LIST / NLST
     * ===================================================================== */

    /**
     * LIST/NLST：列目录（支持 -la 选项与路径参数）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 参数
     * @param bool $nlst 是否 NLST（仅文件名）
     * @return void
     */
    private function handleList(TcpConnection $connection, string $param, bool $nlst): void
    {
        // 解析参数：去除 -l/-a/-la 等选项，剩余为路径
        $path = '';
        foreach (preg_split('/\s+/', trim($param)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (str_starts_with($token, '-')) {
                continue;
            }
            $path = $token;
            break;
        }

        $virtual = $path === '' ? $connection->ftp['cwd'] : $this->resolveVirtual($connection, $path);
        $real = $this->toRealPath($virtual);

        $content = '';
        if (is_file($real)) {
            $content = $nlst ? basename($real) . "\r\n" : $this->formatListLine($real, basename($real)) . "\r\n";
        } elseif (is_dir($real)) {
            $files = @scandir($real);
            if ($files === false) {
                $connection->send("550 Failed to read directory.\r\n");
                return;
            }
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $child = $real . '/' . $file;
                $content .= $nlst ? $file . "\r\n" : $this->formatListLine($child, $file) . "\r\n";
            }
        } else {
            $connection->send("550 Path not found.\r\n");
            return;
        }

        $this->startTransfer($connection, function ($dataConn) use ($content, $connection) {
            $dataConn->send($content);
            $dataConn->close();
            // 先发完成响应，再清理数据通道
            $connection->send("226 Transfer complete.\r\n");
            $this->cleanupDataConnection($connection);
        });
    }

    /**
     * 生成单行 LIST 记录（unix 风格）
     *
     * @param string $real 真实路径
     * @param string $name 显示名
     * @return string 列表行
     */
    private function formatListLine(string $real, string $name): string
    {
        $stat = @lstat($real);
        if ($stat === false) {
            return '-rw-r--r-- 1 owner group 0 Jan 01 00:00 ' . $name;
        }
        $perms = $this->formatPerms($stat['mode'], is_dir($real));
        $size = $stat['size'];
        $mtime = $stat['mtime'];
        // 6 个月内的显示时间，更早的显示年份
        $date = (time() - $mtime < 15778476) ? date('M d H:i', $mtime) : date('M d  Y', $mtime);
        return sprintf('%s 1 owner group %8d %s %s', $perms, $size, $date, $name);
    }

    /**
     * 格式化权限字符串（如 -rw-r--r--）
     *
     * @param int $mode stat mode
     * @param bool $isDir 是否目录
     * @return string 权限串
     */
    private function formatPerms(int $mode, bool $isDir): string
    {
        $permMap = [
            0x0100 => 'r', 0x0080 => 'w', 0x0040 => 'x',
            0x0020 => 'r', 0x0010 => 'w', 0x0008 => 'x',
            0x0004 => 'r', 0x0002 => 'w', 0x0001 => 'x',
        ];
        $str = $isDir ? 'd' : '-';
        foreach ($permMap as $bit => $char) {
            $str .= ($mode & $bit) ? $char : '-';
        }
        return $str;
    }

    /* =====================================================================
     * RETR / STOR
     * ===================================================================== */

    /**
     * RETR：下载文件（支持 REST 偏移与 ASCII 转换）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 文件名
     * @return void
     */
    private function handleRetr(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $real = $this->toRealPath($this->resolveVirtual($connection, $param));
        if (!is_file($real)) {
            $connection->send("550 File not found.\r\n");
            return;
        }

        $offset = $connection->ftp['rest_offset'];
        $connection->ftp['rest_offset'] = 0;

        // startTransfer 统一处理：数据连接已建立 / 主动模式 / 被动等待 accept 三种时序
        $this->startTransfer($connection, function ($dataConn) use ($real, $offset, $connection) {
            $this->streamFile($connection, $dataConn, $real, $offset);
        });
    }

    /**
     * 流式发送文件到数据连接（分块 + 发送缓冲控制）
     *
     * @param TcpConnection $control 控制连接
     * @param TcpConnection $dataConn 数据连接
     * @param string $real 文件路径
     * @param int $offset 起始偏移
     * @return void
     */
    private function streamFile(TcpConnection $control, TcpConnection $dataConn, string $real, int $offset): void
    {
        $handle = @fopen($real, 'rb');
        if (!$handle) {
            $dataConn->close();
            $this->cleanupDataConnection($control);
            $control->send("451 Local error.\r\n");
            return;
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $type = $control->ftp['type'];
        $dataConn->maxSendBufferSize = self::SEND_BUFFER_LIMIT;

        $finish = function () use ($control, $dataConn, $handle) {
            @fclose($handle);
            $dataConn->close();
            // 先发完成响应，再清理数据通道
            $control->send("226 Transfer complete.\r\n");
            $this->cleanupDataConnection($control);
        };

        $sendNext = function () use ($dataConn, $handle, $type, &$sendNext, $finish) {
            while (true) {
                $chunk = @fread($handle, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    $finish();
                    return;
                }
                if ($type === 'A') {
                    $chunk = str_replace("\n", "\r\n", $chunk);
                }
                // 发送缓冲接近上限时暂停，等 onBufferDrain 继续
                if ($dataConn->getSendBufferQueueSize() + strlen($chunk) > self::SEND_BUFFER_LIMIT) {
                    $dataConn->onBufferDrain = $sendNext;
                    return;
                }
                $dataConn->send($chunk);
            }
        };
        $sendNext();
    }

    /**
     * STOR：上传文件（数据连接按块写入）
     *
     * @param TcpConnection $connection 控制连接
     * @param string $param 文件名
     * @return void
     */
    private function handleStor(TcpConnection $connection, string $param): void
    {
        if ($param === '') {
            $connection->send("501 Syntax error.\r\n");
            return;
        }
        $real = $this->toRealPath($this->resolveVirtual($connection, $param));
        $dir = dirname($real);
        if (!is_dir($dir)) {
            $connection->send("550 Directory not found.\r\n");
            return;
        }
        $handle = @fopen($real, 'wb');
        if (!$handle) {
            $connection->send("550 Can't create file.\r\n");
            return;
        }
        $connection->ftp['transfer'] = [
            'mode' => 'STOR',
            'path' => $real,
            'handle' => $handle,
            'offset' => 0,
            'type' => $connection->ftp['type'],
        ];
        $connection->send("150 Ok to send data.\r\n");

        // 主动模式（PORT）：服务器需主动连接客户端数据端口，客户端才会开始发送
        if (($connection->ftp['port_address'] ?? null) !== null) {
            $this->ensureDataConnection($connection, function ($dataConn) {
                // 连接已建立，客户端随即开始发送数据（onDataMessage 写入）
            });
        }
        // 被动模式：客户端自行连接数据端口，数据连接关闭（onDataClose）时完成收尾并回 226
    }

    /* =====================================================================
     * 通用传输入口
     * ===================================================================== */

    /**
     * 发起一次数据传输（兼容三种时序：
     * 1. 数据连接已建立（FileZilla 等先连后命令）→ 直接发送
     * 2. 主动模式 PORT → 异步连接客户端
     * 3. 被动模式但客户端先发命令（PHP ftp 扩展）→ 挂起待办，accept 后发送）
     *
     * @param TcpConnection $control 控制连接
     * @param callable $onReady 数据连接就绪回调
     * @return void
     */
    private function startTransfer(TcpConnection $control, callable $onReady): void
    {
        $dataConn = $control->ftp['data_connection'] ?? null;
        if ($dataConn instanceof TcpConnection && $dataConn->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            $control->send("150 Opening data connection.\r\n");
            $onReady($dataConn);
            return;
        }
        if (($control->ftp['port_address'] ?? null) !== null) {
            // 主动模式：异步连接客户端，连上后 150 + onReady
            $this->ensureDataConnection($control, function ($dataConn) use ($control, $onReady) {
                $control->send("150 Opening data connection.\r\n");
                $onReady($dataConn);
            });
            return;
        }
        // 被动模式：数据连接尚未建立（客户端先发命令），挂起待办，accept 后执行
        $control->ftp['pending_transfer'] = $onReady;
        $control->send("150 Opening data connection.\r\n");
    }

    /**
     * FTP 字符串转义（PWD/MKD 响应中的引号）
     *
     * @param string $str 字符串
     * @return string
     */
    private function escapeFtpString(string $str): string
    {
        return str_replace('"', '""', $str);
    }
}
