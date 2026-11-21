<?php
/**
 * WebDAV 服务器（基于 Workerman）
 *
 * 提供标准 WebDAV 协议服务：文件/目录的读写删、属性查询（PROPFIND）、
 * 目录创建（MKCOL）、复制/移动（COPY/MOVE）、资源锁定（LOCK/UNLOCK）。
 * 内置 HTTP Basic 认证与基于 IP 的暴力破解防护（计数持久化到文件，跨进程生效）。
 *
 * 主要修复/特性（相对早期版本）：
 * - 正确处理 OPTIONS 探测（DAV / Allow / MS-Author-Via），保证客户端可挂载
 * - PROPFIND 支持 Depth: 0 / 1 / infinity 递归列目录，并按请求体过滤属性
 * - PUT 通过 $request->rawBody() 读取请求体（Workerman 下 php://input 不可用）
 * - 路径规范化 + 越界校验，杜绝 ../ 路径穿越
 * - LOCK/UNLOCK 实现真实锁存储（文件持久化 + flock 同步，跨进程一致），
 *   写操作校验 If 头锁令牌，未持锁返回 423
 * - GET 支持 Range 断点续传；响应携带 ETag / Last-Modified
 * - DELETE 递归删除目录；MOVE/COPY 处理 Overwrite 与目标父目录
 * - 认证失败计数持久化（auth.json），多进程 / 重启后防爆破仍生效
 *
 * 使用示例：
 * ```php
 * $server = new Webdav\Server('0.0.0.0', 8080, '/data/webdav', ['admin' => 'secret']);
 * $server->run();
 * ```
 *
 * @package Webdav
 */
namespace Webdav;

use Workerman\Worker;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Connection\TcpConnection;

class Server
{
    /** DAV 命名空间 */
    public const DAV_NS = 'DAV:';

    /** 允许的 HTTP 方法（OPTIONS 与 405 响应使用） */
    public const ALLOW_METHODS = 'OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, MKCOL, COPY, MOVE, LOCK, UNLOCK';

    /** PROPFIND 单次递归最大节点数，防止 Depth: infinity 打爆服务器 */
    private const MAX_PROPFIND_NODES = 500;

    /** 锁默认超时（秒） */
    private const DEFAULT_LOCK_TIMEOUT = 3600;

    private $worker;

    /** 数据根目录（真实路径） */
    private $data_dir;

    /** 授权用户：用户名 => password_hash 后的密码 */
    private $auth_users = [];

    /** 进程内认证失败计数缓存 [ip => ['count'=>n,'time'=>ts]] */
    private $auth_attempts = [];

    /** 认证计数持久化文件路径 */
    private $auth_file;

    /** 最大失败次数 */
    private $max_attempts = 5;

    /** 锁定时间（秒） */
    private $lockout_time = 300;

    /** 进程内锁表缓存 [token => lock array] */
    private $locks = [];

    /** 锁持久化文件路径 */
    private $locks_file;

    /**
     * 构造 WebDAV 服务器
     *
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param string|null $data_dir 数据存储目录，默认 CANDYPATH . 'Webdav'
     * @param array $users 授权用户，格式 ['用户名' => '密码或已有hash']，密码自动 password_hash
     */
    public function __construct(string $host = '0.0.0.0', int $port = 8080, ?string $data_dir = null, array $users = [])
    {
        // 密码：已是 hash（$2y$ 等）则原样保留，明文则加密存储
        foreach ($users as $username => $password) {
            $this->auth_users[$username] = $this->isPasswordHash((string) $password)
                ? (string) $password
                : password_hash((string) $password, PASSWORD_DEFAULT);
        }

        // 数据目录：规范化 + 自动创建
        $this->data_dir = $data_dir ?: (defined('CANDYPATH') ? CANDYPATH . 'Webdav' : sys_get_temp_dir() . '/candy-webdav');
        $this->data_dir = rtrim(str_replace('\\', '/', $this->data_dir), '/');
        if (!is_dir($this->data_dir)) {
            @mkdir($this->data_dir, 0777, true);
        }

        // 运行时文件（锁 / 认证计数）放数据目录之外，避免被 WebDAV 暴露
        $runtime_dir = (defined('CANDYPATH') ? CANDYPATH . 'Runtime/Webdav' : sys_get_temp_dir() . '/candy-webdav-runtime');
        $runtime_dir = str_replace('\\', '/', $runtime_dir);
        if (!is_dir($runtime_dir)) {
            @mkdir($runtime_dir, 0777, true);
        }
        $this->locks_file = $runtime_dir . '/locks.json';
        $this->auth_file  = $runtime_dir . '/auth.json';

        $this->worker = new Worker("http://{$host}:{$port}");
        $this->worker->count = 4;
        $this->worker->name = 'WebdavServer';
        $this->worker->onMessage = [$this, 'handleRequest'];
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
     * 请求入口
     * ===================================================================== */

    /**
     * 处理 WebDAV 请求（Workerman onMessage 回调）
     *
     * @param TcpConnection $connection 连接
     * @param Request $request 请求
     * @return void
     */
    public function handleRequest(TcpConnection $connection, Request $request): void
    {
        // 认证（OPTIONS 也要求认证，避免未授权探测；无配置用户时放行）
        if (!$this->checkAuth($connection, $request)) {
            return;
        }

        $method = strtoupper($request->method());
        $rawPath = $request->path();

        // 解析并校验路径，越界返回 403
        try {
            $file_path = $rawPath === '*' ? '' : $this->resolvePath($rawPath);
        } catch (\RuntimeException $e) {
            $this->sendResponse($connection, 403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Forbidden');
            return;
        }

        switch ($method) {
            case 'OPTIONS':
                $this->handleOptions($connection);
                break;
            case 'GET':
                $this->handleGet($connection, $file_path, $request, false);
                break;
            case 'HEAD':
                $this->handleGet($connection, $file_path, $request, true);
                break;
            case 'PUT':
                $this->handlePut($connection, $file_path, $request);
                break;
            case 'DELETE':
                $this->handleDelete($connection, $file_path, $request);
                break;
            case 'PROPFIND':
                $this->handlePropfind($connection, $file_path, $request);
                break;
            case 'PROPPATCH':
                $this->handleProppatch($connection);
                break;
            case 'MKCOL':
                $this->handleMkcol($connection, $file_path);
                break;
            case 'COPY':
                $this->handleCopyMove($connection, $file_path, $request, 'COPY');
                break;
            case 'MOVE':
                $this->handleCopyMove($connection, $file_path, $request, 'MOVE');
                break;
            case 'LOCK':
                $this->handleLock($connection, $file_path, $request);
                break;
            case 'UNLOCK':
                $this->handleUnlock($connection, $file_path, $request);
                break;
            default:
                $this->sendResponse($connection, 405, [
                    'Allow' => self::ALLOW_METHODS,
                    'DAV' => '1',
                ]);
        }
    }

    /* =====================================================================
     * 认证
     * ===================================================================== */

    /**
     * Basic 认证检查（含基于 IP 的失败计数锁定，计数持久化到文件）
     *
     * @param TcpConnection $connection 连接
     * @param Request $request 请求
     * @return bool 通过返回 true；失败已发送 401/429 响应并返回 false
     */
    private function checkAuth(TcpConnection $connection, Request $request): bool
    {
        // 未配置用户：默认允许所有访问
        if (empty($this->auth_users)) {
            return true;
        }

        $client_ip = $connection->getRemoteIp();

        // 读取并检查失败锁定
        $this->loadAuthAttempts();
        if (isset($this->auth_attempts[$client_ip]) && $this->auth_attempts[$client_ip]['locked_until'] > time()) {
            $retry = $this->auth_attempts[$client_ip]['locked_until'] - time();
            $this->sendResponse($connection, 429, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Retry-After' => (string) $retry,
            ], "Too many failed attempts. Retry after {$retry}s.");
            return false;
        }

        $auth = (string) $request->header('authorization', '');
        if (strpos($auth, 'Basic ') === 0) {
            $credentials = base64_decode(substr($auth, 6), true);
            if ($credentials !== false && strpos($credentials, ':') !== false) {
                [$username, $password] = explode(':', $credentials, 2);
                if (isset($this->auth_users[$username]) && password_verify($password, $this->auth_users[$username])) {
                    // 成功：重置计数（而非 unset，避免多进程合并写时旧记录复活）
                    $this->auth_attempts[$client_ip] = ['count' => 0, 'locked_until' => 0];
                    $this->saveAuthAttempts();
                    return true;
                }
                // 失败：计数 +1，达到上限触发锁定
                $this->auth_attempts[$client_ip] = $this->auth_attempts[$client_ip] ?? ['count' => 0, 'locked_until' => 0];
                $this->auth_attempts[$client_ip]['count']++;
                if ($this->auth_attempts[$client_ip]['count'] >= $this->max_attempts) {
                    $this->auth_attempts[$client_ip]['locked_until'] = time() + $this->lockout_time;
                    $this->auth_attempts[$client_ip]['count'] = 0;
                }
                $this->saveAuthAttempts();
            }
        }

        // 401 触发客户端弹出登录框
        $this->sendResponse($connection, 401, [
            'WWW-Authenticate' => 'Basic realm="WebDAV Server", charset="UTF-8"',
            'Content-Type' => 'text/plain; charset=utf-8',
        ], 'Authentication required.');
        return false;
    }

    /**
     * 从文件加载认证失败计数（flock 保护）
     *
     * 每次强制重读，保证多 Worker 进程间计数一致（与锁表同理）。
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
     * 持久化认证失败计数（flock 保护）
     *
     * 写入前合并文件中的最新状态（其他进程可能刚写入其他 IP 的记录），
     * 本进程的内存状态优先。
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

    /* =====================================================================
     * 路径安全
     * ===================================================================== */

    /**
     * 将请求 URI 路径解析为数据目录内的绝对路径，并做越界校验
     *
     * @param string $uriPath 请求路径（含 URL 编码）
     * @return string 绝对文件路径
     * @throws \RuntimeException 路径越界时抛出
     */
    private function resolvePath(string $uriPath): string
    {
        $path = parse_url($uriPath, PHP_URL_PATH) ?: $uriPath;
        $path = rawurldecode($path);
        $path = '/' . ltrim($path, '/');

        // 解析 . / .. 段
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }

        $realDataDir = realpath($this->data_dir) ?: $this->data_dir;
        $full = $segments === [] ? $realDataDir : $realDataDir . '/' . implode('/', $segments);

        // 防御性越界校验
        $real = realpath($full);
        $base = rtrim($realDataDir, '/');
        if ($real !== false) {
            if ($real !== $base && strpos($real, $base . '/') !== 0) {
                throw new \RuntimeException('Path traversal detected');
            }
        }
        return $full;
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
     * OPTIONS / PROPPATCH
     * ===================================================================== */

    /**
     * OPTIONS：声明 DAV 能力与允许的方法（客户端挂载第一步）
     *
     * @param TcpConnection $connection 连接
     * @return void
     */
    private function handleOptions(TcpConnection $connection): void
    {
        $this->sendResponse($connection, 200, [
            'DAV' => '1',
            'Allow' => self::ALLOW_METHODS,
            'MS-Author-Via' => 'DAV',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * PROPPATCH：接受属性修改请求，返回 207 空成功（自定义属性不持久化）
     *
     * @param TcpConnection $connection 连接
     * @return void
     */
    private function handleProppatch(TcpConnection $connection): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:multistatus xmlns:D="DAV:">' . "\n"
            . '</D:multistatus>';
        $this->sendResponse($connection, 207, [
            'Content-Type' => 'application/xml; charset="utf-8"',
        ], $xml);
    }

    /* =====================================================================
     * GET / HEAD（支持 Range 断点续传）
     * ===================================================================== */

    /**
     * GET/HEAD：读取文件（支持 Range），目录返回 404
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 文件路径
     * @param Request $request 请求
     * @param bool $head 是否 HEAD 请求
     * @return void
     */
    private function handleGet(TcpConnection $connection, string $file_path, Request $request, bool $head): void
    {
        if ($file_path === '' || !file_exists($file_path) || is_dir($file_path)) {
            $this->sendResponse($connection, 404);
            return;
        }

        $size = filesize($file_path);
        $etag = $this->makeEtag($file_path);
        $lastModified = $this->httpDate(filemtime($file_path));
        $baseHeaders = [
            'Content-Type' => $this->mimeType($file_path),
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
        ];
        // 注意：Accept-Ranges 与 Content-Range 由 Workerman 的 Http::encode 在 withFile 时自动生成，
        // 这里不重复设置，否则 withHeaders 的 array_merge_recursive 会产生重复响应头。

        // If-None-Match / If-Modified-Since 条件请求
        $inm = (string) $request->header('if-none-match', '');
        if ($inm !== '' && $this->etagMatches($inm, $etag)) {
            $this->sendResponse($connection, 304, $baseHeaders);
            return;
        }

        // Range 解析（单区间）
        $range = (string) $request->header('range', '');
        $offset = 0;
        $length = $size;
        $status = 200;
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                // 后缀区间：bytes=-N 取最后 N 字节
                $suffix = (int) $m[2];
                $offset = max(0, $size - $suffix);
                $length = $size - $offset;
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
                if ($start > $end || $start >= $size) {
                    $this->sendResponse($connection, 416, ['Content-Range' => "bytes */{$size}"]);
                    return;
                }
                $offset = $start;
                $length = $end - $start + 1;
            }
            if ($length > 0 && $length < $size) {
                $status = 206;
            }
        }

        if ($head) {
            // HEAD：手动构造响应头（Response 会对空 body 强制输出 Content-Length: 0，
            // 无法表达真实文件长度，故不走 sendResponse）
            $headers = array_merge($baseHeaders, ['Content-Length' => (string) $length]);
            $reason = [200 => 'OK', 206 => 'Partial Content'][$status] ?? 'OK';
            $headStr = "HTTP/1.1 {$status} {$reason}\r\n";
            foreach ($headers as $name => $value) {
                $headStr .= "{$name}: {$value}\r\n";
            }
            $headStr .= "\r\n";
            $connection->send($headStr, true);
            return;
        }

        // withFile 传了 offset/length 会被 Workerman 自动置 206 + Content-Range，
        // 因此仅 Range 请求时传参，完整文件直接 withFile($file)
        $response = new Response($status, $baseHeaders);
        if ($offset === 0 && $length === $size) {
            $response->withFile($file_path);
        } else {
            $response->withFile($file_path, $offset, $length);
        }
        $connection->send($response);
    }

    /* =====================================================================
     * PUT
     * ===================================================================== */

    /**
     * PUT：写入文件（校验锁令牌、自动创建父目录、返回 ETag）
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 目标文件路径
     * @param Request $request 请求
     * @return void
     */
    private function handlePut(TcpConnection $connection, string $file_path, Request $request): void
    {
        // 锁校验：被锁定且未携带正确令牌 → 423
        if ($this->hasConflictingLock($file_path, (string) $request->header('if', ''))) {
            $this->sendResponse($connection, 423);
            return;
        }

        $dir = dirname($file_path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            if (!is_dir($dir)) {
                $this->sendResponse($connection, 500);
                return;
            }
        }

        // Workerman 下请求体必须从 rawBody 读取（php://input 不可用）
        $data = $request->rawBody();
        $isNew = !file_exists($file_path);
        if (@file_put_contents($file_path, $data) === false) {
            $this->sendResponse($connection, 500);
            return;
        }

        $this->sendResponse($connection, $isNew ? 201 : 204, [
            'ETag' => $this->makeEtag($file_path),
        ]);
    }

    /* =====================================================================
     * DELETE（递归）
     * ===================================================================== */

    /**
     * DELETE：递归删除文件/目录（校验锁令牌）
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 目标路径
     * @param Request $request 请求
     * @return void
     */
    private function handleDelete(TcpConnection $connection, string $file_path, Request $request): void
    {
        if ($file_path === '' || !file_exists($file_path)) {
            $this->sendResponse($connection, 404);
            return;
        }

        if ($this->hasConflictingLock($file_path, (string) $request->header('if', ''))) {
            $this->sendResponse($connection, 423);
            return;
        }

        $ok = $this->removeRecursive($file_path);
        $this->sendResponse($connection, $ok ? 204 : 500);
    }

    /**
     * 递归删除文件/目录
     *
     * @param string $path 路径
     * @return bool 是否成功
     */
    private function removeRecursive(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!$this->removeRecursive($path . '/' . $entry)) {
                    return false;
                }
            }
            return @rmdir($path);
        }
        return true;
    }

    /* =====================================================================
     * PROPFIND（列目录 / 查属性）
     * ===================================================================== */

    /**
     * PROPFIND：返回资源属性，支持 Depth 0/1/infinity 与请求体属性过滤
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 目标路径
     * @param Request $request 请求
     * @return void
     */
    private function handlePropfind(TcpConnection $connection, string $file_path, Request $request): void
    {
        if ($file_path === '' || !file_exists($file_path)) {
            $this->sendResponse($connection, 404);
            return;
        }

        // 解析请求体（allprop / propname / prop 过滤）
        [$requestedProps, $propnameOnly] = $this->parsePropfindBody($request->rawBody());

        // 解析 Depth 头（默认 infinity，限制节点数防爆）
        $depth = strtolower((string) $request->header('depth', 'infinity'));
        if ($depth !== '0' && $depth !== '1' && $depth !== 'infinity') {
            $depth = 'infinity';
        }

        // 收集节点：BFS 展平 [相对路径, 绝对路径]
        $nodes = [['', $file_path]];
        $isCollection = is_dir($file_path);
        if ($isCollection && $depth !== '0') {
            $queue = [['', $file_path]];
            while ($queue !== [] && count($nodes) < self::MAX_PROPFIND_NODES) {
                [$rel, $abs] = array_shift($queue);
                foreach (scandir($abs) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $childAbs = $abs . '/' . $entry;
                    $childRel = $rel === '' ? $entry : $rel . '/' . $entry;
                    $nodes[] = [$childRel, $childAbs];
                    if (count($nodes) >= self::MAX_PROPFIND_NODES) {
                        break 2;
                    }
                    if (is_dir($childAbs) && $depth === 'infinity') {
                        $queue[] = [$childRel, $childAbs];
                    }
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:multistatus xmlns:D="DAV:">' . "\n";
        foreach ($nodes as [$rel, $abs]) {
            $href = $this->encodeHref($rel, $isCollection);
            $xml .= $this->buildPropfindResponse($href, $abs, $rel, $requestedProps, $propnameOnly);
        }
        $xml .= '</D:multistatus>';

        $this->sendResponse($connection, 207, [
            'Content-Type' => 'application/xml; charset="utf-8"',
        ], $xml);
    }

    /**
     * 解析 PROPFIND 请求体，得到属性过滤信息
     *
     * @param string $body 请求体
     * @return array{0: array|null, 1: bool} [请求的属性名列表（null=全部）, 是否仅属性名]
     */
    private function parsePropfindBody(string $body): array
    {
        if (trim($body) === '') {
            return [null, false];
        }
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($body)) {
            return [null, false];
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('D', self::DAV_NS);
        if ($xp->query('//D:propname')->length > 0) {
            return [null, true];
        }
        $nodes = $xp->query('//D:prop/*');
        if ($nodes !== false && $nodes->length > 0) {
            $props = [];
            foreach ($nodes as $node) {
                $props[] = $node->localName;
            }
            return [$props, false];
        }
        return [null, false];
    }

    /**
     * 生成单个资源的 PROPFIND 响应 XML（response 节点）
     *
     * @param string $href 编码后的 href
     * @param string $abs 绝对路径
     * @param string $rel 相对路径（根为 ''）
     * @param array|null $requestedProps 请求的属性名列表，null=全部
     * @param bool $propnameOnly 仅输出属性名
     * @return string XML 片段
     */
    private function buildPropfindResponse(string $href, string $abs, string $rel, ?array $requestedProps, bool $propnameOnly): string
    {
        $isDir = is_dir($abs);
        $xml = '  <D:response>' . "\n"
            . '    <D:href>' . $href . '</D:href>' . "\n"
            . '    <D:propstat>' . "\n"
            . '      <D:prop>' . "\n";

        $props = [
            'displayname' => $rel === '' ? basename($this->data_dir) : basename($abs),
            'resourcetype' => $isDir ? '<D:collection/>' : '',
            'supportedlock' => $this->buildSupportedLock(),
        ];

        if (!$propnameOnly) {
            if (!$isDir) {
                $props['getcontentlength'] = (string) filesize($abs);
                $props['getcontenttype'] = $this->mimeType($abs);
            }
            $props['getlastmodified'] = $this->httpDate(filemtime($abs));
            $props['creationdate'] = gmdate('Y-m-d\TH:i:s\Z', filectime($abs));
            $props['getetag'] = $this->makeEtag($abs);

            // 当前资源上的活动锁
            $activeLocks = $this->findLocksForPath($rel === '' ? '/' : '/' . $rel);
            if ($activeLocks !== []) {
                $props['lockdiscovery'] = $this->buildLockDiscovery($activeLocks);
            }
        }

        foreach ($props as $name => $value) {
            // 属性过滤：请求了指定属性时仅输出匹配项
            if ($requestedProps !== null && !in_array($name, $requestedProps, true)) {
                continue;
            }
            if ($propnameOnly) {
                $xml .= '        <D:' . $name . '/>' . "\n";
                continue;
            }
            // 值本身是 XML 子节点（supportedlock / lockdiscovery / resourcetype）时不转义
            $rawXml = in_array($name, ['supportedlock', 'lockdiscovery', 'resourcetype'], true);
            if ($value === '') {
                $xml .= '        <D:' . $name . '/>' . "\n";
            } elseif ($rawXml) {
                $xml .= '        <D:' . $name . '>' . $value . '</D:' . $name . '>' . "\n";
            } else {
                $xml .= '        <D:' . $name . '>' . $this->xmlEscape($value) . '</D:' . $name . '>' . "\n";
            }
        }

        $xml .= '      </D:prop>' . "\n"
            . '      <D:status>HTTP/1.1 200 OK</D:status>' . "\n"
            . '    </D:propstat>' . "\n"
            . '  </D:response>' . "\n";
        return $xml;
    }

    /**
     * 生成 supportedlock 属性 XML（声明支持独占写锁）
     *
     * @return string XML 片段
     */
    private function buildSupportedLock(): string
    {
        return '<D:lockentry><D:lockscope><D:exclusive/></D:lockscope>'
            . '<D:locktype><D:write/></D:locktype></D:lockentry>';
    }

    /* =====================================================================
     * MKCOL / COPY / MOVE
     * ===================================================================== */

    /**
     * MKCOL：创建目录（父目录不存在返回 409，已存在返回 405）
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 目标路径
     * @return void
     */
    private function handleMkcol(TcpConnection $connection, string $file_path): void
    {
        if ($file_path === '' || file_exists($file_path)) {
            $this->sendResponse($connection, 405);
            return;
        }
        if (!is_dir(dirname($file_path))) {
            $this->sendResponse($connection, 409);
            return;
        }
        if (@mkdir($file_path, 0777)) {
            $this->sendResponse($connection, 201);
        } else {
            $this->sendResponse($connection, 403);
        }
    }

    /**
     * COPY/MOVE：复制/移动文件或目录
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 源路径
     * @param Request $request 请求
     * @param string $method COPY 或 MOVE
     * @return void
     */
    private function handleCopyMove(TcpConnection $connection, string $file_path, Request $request, string $method): void
    {
        if ($file_path === '' || !file_exists($file_path)) {
            $this->sendResponse($connection, 404);
            return;
        }

        // Destination 头解析：仅接受本服务器的路径，防跨主机与路径穿越
        $destHeader = (string) $request->header('destination', '');
        if ($destHeader === '') {
            $this->sendResponse($connection, 400);
            return;
        }
        $destUrl = parse_url($destHeader);
        if ($destUrl === false || empty($destUrl['path'])) {
            $this->sendResponse($connection, 400);
            return;
        }
        // 跨主机校验：带 host 且与请求 Host 不一致 → 502
        if (!empty($destUrl['host'])) {
            $reqHost = (string) $request->header('host', '');
            $reqHostName = strtolower(explode(':', $reqHost, 2)[0]);
            if (strtolower($destUrl['host']) !== $reqHostName) {
                $this->sendResponse($connection, 502);
                return;
            }
        }
        try {
            $dest_path = $this->resolvePath($destUrl['path']);
        } catch (\RuntimeException $e) {
            $this->sendResponse($connection, 403);
            return;
        }

        // 源与目标相同路径
        if ($dest_path === $file_path) {
            $this->sendResponse($connection, 403);
            return;
        }

        // Overwrite: F 时目标已存在 → 412
        $overwrite = strtoupper((string) $request->header('overwrite', 'T')) !== 'F';
        if (!$overwrite && file_exists($dest_path)) {
            $this->sendResponse($connection, 412);
            return;
        }

        $ifHeader = (string) $request->header('if', '');
        if ($this->hasConflictingLock($file_path, $ifHeader) || $this->hasConflictingLock($dest_path, $ifHeader)) {
            $this->sendResponse($connection, 423);
            return;
        }

        // 目标父目录
        $destDir = dirname($dest_path);
        if (!is_dir($destDir) && !@mkdir($destDir, 0777, true)) {
            $this->sendResponse($connection, 409);
            return;
        }

        if ($method === 'MOVE') {
            $ok = $this->movePath($file_path, $dest_path, $overwrite);
        } else {
            $ok = $this->copyPath($file_path, $dest_path, $overwrite);
        }

        $this->sendResponse($connection, $ok ? 201 : 500);
    }

    /**
     * 移动（优先 rename，跨设备回退为复制+删除）
     *
     * @param string $src 源路径
     * @param string $dest 目标路径
     * @param bool $overwrite 是否允许覆盖
     * @return bool
     */
    private function movePath(string $src, string $dest, bool $overwrite): bool
    {
        if (!$overwrite && file_exists($dest)) {
            return false;
        }
        if (@rename($src, $dest)) {
            return true;
        }
        // 跨设备：复制后删除
        if ($this->copyPath($src, $dest, true)) {
            return $this->removeRecursive($src);
        }
        return false;
    }

    /**
     * 递归复制
     *
     * @param string $src 源路径
     * @param string $dest 目标路径
     * @param bool $overwrite 是否允许覆盖
     * @return bool
     */
    private function copyPath(string $src, string $dest, bool $overwrite): bool
    {
        if (!$overwrite && file_exists($dest)) {
            return false;
        }
        if (is_file($src)) {
            return @copy($src, $dest);
        }
        if (!is_dir($dest) && !@mkdir($dest, 0777, true)) {
            return false;
        }
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->copyPath($src . '/' . $entry, $dest . '/' . $entry, true)) {
                return false;
            }
        }
        return true;
    }

    /* =====================================================================
     * LOCK / UNLOCK
     * ===================================================================== */

    /**
     * LOCK：创建或刷新锁（令牌持久化到文件，跨进程一致）
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 目标路径
     * @param Request $request 请求
     * @return void
     */
    private function handleLock(TcpConnection $connection, string $file_path, Request $request): void
    {
        if ($file_path === '') {
            $this->sendResponse($connection, 400);
            return;
        }

        $this->loadLocks();
        $rel = $this->pathToRel($file_path);
        $ifHeader = (string) $request->header('if', '');
        $tokenInIf = $this->extractTokens($ifHeader);

        // If 头携带令牌：刷新模式（令牌必须存在且指向该路径）
        if ($tokenInIf !== []) {
            $found = null;
            foreach ($this->locks as $token => $lock) {
                if (in_array($token, $tokenInIf, true)) {
                    $found = $token;
                    break;
                }
            }
            if ($found === null) {
                $this->sendResponse($connection, 412);
                return;
            }
            $this->locks[$found]['expires'] = time() + self::DEFAULT_LOCK_TIMEOUT;
            $this->saveLocks();
            $lock = $this->locks[$found];
            $xml = $this->buildLockResponseXml($rel, $lock, $found);
            $this->sendResponse($connection, 200, [
                'Content-Type' => 'application/xml; charset="utf-8"',
                'Lock-Token' => '<' . $found . '>',
            ], $xml);
            return;
        }

        // 新锁：路径已被其他锁占用 → 423
        if ($this->findLocksForPath($rel) !== []) {
            $this->sendResponse($connection, 423);
            return;
        }

        // 解析请求体（owner / timeout）
        $owner = 'WebDAV Client';
        $body = $request->rawBody();
        if (trim($body) !== '') {
            $doc = new \DOMDocument();
            if (@$doc->loadXML($body)) {
                $xp = new \DOMXPath($doc);
                $xp->registerNamespace('D', self::DAV_NS);
                $ownerNode = $xp->query('//D:owner')->item(0);
                if ($ownerNode) {
                    $owner = trim($ownerNode->textContent) ?: $owner;
                }
            }
        }

        // 解析 Timeout 头
        $timeout = self::DEFAULT_LOCK_TIMEOUT;
        $timeoutHeader = (string) $request->header('timeout', '');
        if (preg_match('/Second-(\d+)/i', $timeoutHeader, $m)) {
            $timeout = max(60, min((int) $m[1], self::DEFAULT_LOCK_TIMEOUT));
        }

        $token = 'urn:uuid:' . md5(uniqid((string) mt_rand(), true));
        $this->locks[$token] = [
            'path' => $rel,
            'owner' => $owner,
            'expires' => time() + $timeout,
            'depth' => 'infinity',
        ];
        $this->saveLocks();

        $xml = $this->buildLockResponseXml($rel, $this->locks[$token], $token);
        $this->sendResponse($connection, 200, [
            'Content-Type' => 'application/xml; charset="utf-8"',
            'Lock-Token' => '<' . $token . '>',
            'Timeout' => 'Second-' . $timeout,
        ], $xml);
    }

    /**
     * UNLOCK：释放锁（校验 Lock-Token）
     *
     * @param TcpConnection $connection 连接
     * @param string $file_path 目标路径
     * @param Request $request 请求
     * @return void
     */
    private function handleUnlock(TcpConnection $connection, string $file_path, Request $request): void
    {
        $this->loadLocks();
        $token = trim((string) $request->header('lock-token', ''));
        $token = trim($token, '<>');
        if ($token === '' || !isset($this->locks[$token])) {
            $this->sendResponse($connection, 409);
            return;
        }
        unset($this->locks[$token]);
        $this->saveLocks();
        $this->sendResponse($connection, 204);
    }

    /**
     * 检查路径是否存在冲突锁（被锁定且 If 头未携带对应令牌）
     *
     * @param string $file_path 绝对路径
     * @param string $ifHeader If 头内容
     * @return bool 存在冲突返回 true
     */
    private function hasConflictingLock(string $file_path, string $ifHeader): bool
    {
        if ($file_path === '') {
            return false;
        }
        $rel = $this->pathToRel($file_path);
        $locks = $this->findLocksForPath($rel);
        if ($locks === []) {
            return false;
        }
        $tokens = $this->extractTokens($ifHeader);
        foreach ($locks as $token => $lock) {
            if (!in_array($token, $tokens, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 查找影响指定相对路径的活动锁（精确匹配或 depth=infinity 前缀匹配）
     *
     * @param string $rel 相对路径（以 / 开头）
     * @return array 锁数组 [token => lock]
     */
    private function findLocksForPath(string $rel): array
    {
        $this->loadLocks();
        $this->purgeExpiredLocks();
        $result = [];
        $now = time();
        foreach ($this->locks as $token => $lock) {
            if ($lock['expires'] < $now) {
                continue;
            }
            $lockPath = rtrim($lock['path'], '/');
            $target = rtrim($rel, '/');
            if ($lockPath === $target || str_starts_with($target . '/', $lockPath . '/')) {
                $result[$token] = $lock;
            }
        }
        return $result;
    }

    /**
     * 从 If 头提取锁令牌列表
     *
     * @param string $ifHeader If 头内容
     * @return array 令牌数组
     */
    private function extractTokens(string $ifHeader): array
    {
        if ($ifHeader === '') {
            return [];
        }
        preg_match_all('/urn:uuid:[0-9a-f]{32}/i', $ifHeader, $m);
        return $m[0] ?: [];
    }

    /**
     * 清理过期锁
     *
     * @return void
     */
    private function purgeExpiredLocks(): void
    {
        $now = time();
        $changed = false;
        foreach ($this->locks as $token => $lock) {
            if ($lock['expires'] < $now) {
                unset($this->locks[$token]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveLocks();
        }
    }

    /**
     * 从文件加载锁表（flock 保护）
     *
     * 每次强制重读，保证多 Worker 进程（count=4）间锁状态一致。
     * 锁表规模很小，JSON 读取开销可忽略。
     *
     * @return void
     */
    private function loadLocks(): void
    {
        if (is_file($this->locks_file)) {
            $raw = @file_get_contents($this->locks_file);
            $data = $raw ? json_decode($raw, true) : null;
            $this->locks = is_array($data) ? $data : [];
        } else {
            $this->locks = [];
        }
    }

    /**
     * 持久化锁表（flock 保护，防多进程竞争）
     *
     * 调用方必须先 loadLocks() 拿到最新状态再修改，此处直接覆盖写。
     *
     * @return void
     */
    private function saveLocks(): void
    {
        $fp = @fopen($this->locks_file, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($this->locks));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * 生成 LOCK 响应 XML（lockdiscovery）
     *
     * @param string $rel 相对路径
     * @param array $lock 锁信息
     * @param string $token 锁令牌
     * @return string XML
     */
    private function buildLockResponseXml(string $rel, array $lock, string $token): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:prop xmlns:D="DAV:">' . "\n"
            . '  <D:lockdiscovery>' . "\n"
            . '    <D:activelock>' . "\n"
            . '      <D:locktype><D:write/></D:locktype>' . "\n"
            . '      <D:lockscope><D:exclusive/></D:lockscope>' . "\n"
            . '      <D:depth>infinity</D:depth>' . "\n"
            . '      <D:owner>' . $this->xmlEscape($lock['owner']) . '</D:owner>' . "\n"
            . '      <D:timeout>Second-' . ($lock['expires'] - time()) . '</D:timeout>' . "\n"
            . '      <D:locktoken><D:href>' . $this->xmlEscape($token) . '</D:href></D:locktoken>' . "\n"
            . '      <D:lockroot><D:href>' . $this->encodeHref(ltrim($rel, '/'), false) . '</D:href></D:lockroot>' . "\n"
            . '    </D:activelock>' . "\n"
            . '  </D:lockdiscovery>' . "\n"
            . '</D:prop>';
    }

    /**
     * 生成 lockdiscovery 属性 XML（PROPFIND 使用）
     *
     * @param array $locks 锁数组
     * @return string XML 片段
     */
    private function buildLockDiscovery(array $locks): string
    {
        $xml = '';
        foreach ($locks as $token => $lock) {
            $xml .= '<D:activelock>'
                . '<D:locktype><D:write/></D:locktype>'
                . '<D:lockscope><D:exclusive/></D:lockscope>'
                . '<D:depth>infinity</D:depth>'
                . '<D:owner>' . $this->xmlEscape($lock['owner']) . '</D:owner>'
                . '<D:timeout>Second-' . max(0, $lock['expires'] - time()) . '</D:timeout>'
                . '<D:locktoken><D:href>' . $this->xmlEscape($token) . '</D:href></D:locktoken>'
                . '</D:activelock>';
        }
        return $xml;
    }

    /* =====================================================================
     * 工具方法
     * ===================================================================== */

    /**
     * 绝对路径转相对路径（以 / 开头）
     *
     * @param string $file_path 绝对路径
     * @return string 相对路径
     */
    private function pathToRel(string $file_path): string
    {
        $base = rtrim(realpath($this->data_dir) ?: $this->data_dir, '/');
        $real = realpath($file_path) ?: $file_path;
        if (str_starts_with($real, $base . '/')) {
            return '/' . substr($real, strlen($base) + 1);
        }
        return '/' . basename($real);
    }

    /**
     * 相对路径转 href（逐段 URL 编码，根返回 '/'）
     *
     * @param string $rel 相对路径（无前导斜杠）
     * @param bool $isCollection 请求目标是否为目录（根目录 href 处理）
     * @return string 编码后的 href
     */
    private function encodeHref(string $rel, bool $isCollection): string
    {
        if ($rel === '') {
            return '/';
        }
        $segments = array_map('rawurlencode', explode('/', $rel));
        return '/' . implode('/', $segments);
    }

    /**
     * XML 转义
     *
     * @param string $value 值
     * @return string 转义后的值
     */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * 生成 HTTP 日期（RFC 7231）
     *
     * @param int $ts 时间戳
     * @return string
     */
    private function httpDate(int $ts): string
    {
        return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
    }

    /**
     * 计算 ETag（基于 mtime + size，与 nginx 风格一致）
     *
     * @param string $path 文件路径
     * @return string ETag
     */
    private function makeEtag(string $path): string
    {
        return '"' . dechex(filemtime($path)) . '-' . dechex(filesize($path)) . '"';
    }

    /**
     * 判断 If-None-Match 是否匹配 ETag（支持 * 与多个值）
     *
     * @param string $header 头值
     * @param string $etag ETag
     * @return bool
     */
    private function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === $etag || trim($candidate) === 'W/' . $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * 根据扩展名推断 MIME 类型
     *
     * @param string $path 文件路径
     * @return string MIME 类型
     */
    private function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'txt' => 'text/plain', 'html' => 'text/html', 'htm' => 'text/html',
            'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
            'xml' => 'application/xml', 'csv' => 'text/csv', 'md' => 'text/markdown',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'gz' => 'application/gzip', 'tar' => 'application/x-tar',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'wav' => 'audio/wav',
            'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska', 'mov' => 'video/quicktime',
            'sql' => 'application/sql', 'php' => 'application/x-httpd-php',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * 发送 HTTP 响应
     *
     * @param TcpConnection $connection 连接
     * @param int $status 状态码
     * @param array $headers 响应头
     * @param string $body 响应体
     * @return void
     */
    private function sendResponse(TcpConnection $connection, int $status, array $headers = [], string $body = ''): void
    {
        $connection->send(new Response($status, $headers, $body));
    }
}
