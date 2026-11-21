<?php
/**
 * DNS 服务器类
 *
 * 基于 Workerman 的权威 DNS 服务器：
 * - 记录来源优先使用用户实现的 getDnsInfoByType()（Source/Func/global.func.php，向后兼容）；
 *   未实现时自动回退到内置权威记录存储 Dns\Records（Source/Inc/Dns.inc.php 或 CLI --zones 指定）。
 * - 支持 A/CNAME/NS/PTR/AAAA/SOA/MX/TXT 查询、EDNS0 客户端子网（GeoDNS）。
 * - 修复：input() 判长（原实现把 txid 当长度前缀导致所有查询包被丢弃）、
 *   畸形包防御（原 onMessage 一个异常即触发 Worker::stopAll 停掉整个服务）、
 *   CNAME 复合记录压缩指针动态化。
 *
 * 启动方式（推荐 CLI）：
 * ```bash
 * php candy dns start --host=0.0.0.0 --port=53 --zones=Source/Inc/Dns.inc.php
 * ```
 *
 * @package Dns
 */
namespace Dns;

use Workerman\Worker;
use Workerman\Connection\ConnectionInterface;
use Dns\Ipv6;
use Dns\Protocols\Dns;

class DnsServer extends Worker
{
    /** 权威记录存储（getDnsInfoByType 未实现时兜底） */
    private ?Records $records = null;

    /** 用户自定义查询回调（可空） */
    private $customResolver = null;

    /** 响应缓存：key => ['bin'=>编码后的二进制响应, 'expires'=>过期时间戳] */
    private array $responseCache = [];

    /** 响应缓存容量上限（超出后清理过期 + 淘汰最旧） */
    private int $cacheMaxEntries = 100000;

    /**
     * 构造参数：是否启用二进制响应缓存（高并发重复查询直接回包）
     *
     * @var bool
     */
    private bool $useCache = true;

    /**
     * 构造函数
     *
     * @param string $address 服务器监听地址，默认 '0.0.0.0:53'
     * @param array|string|null $zones 权威记录配置数组或配置文件路径（null 时读取默认配置）
     */
    public function __construct($address = '0.0.0.0:53', $zones = null)
    {
        if (function_exists('getDnsInfoByType')) {
            $this->customResolver = 'getDnsInfoByType';
        }

        // 加载内置权威记录（zones 参数 > 默认配置文件）
        $zoneData = $this->loadZones($zones);
        if ($zoneData !== null) {
            $this->records = new Records($zoneData);
        }

        if (!class_exists('\Protocols\Dns')) {
            class_alias('Dns\Protocols\Dns', '\Protocols\Dns');
        }
        parent::__construct("Dns://$address");
        $this->transport = 'udp';
        $this->name = 'DnsServer';
        // 注意：不能覆盖 Worker::run()（实例方法，worker 进程启动回调），
        // onMessage 在构造时绑定；启动请调用静态 Worker::runAll()
        $this->onMessage = [$this, 'onMessage'];
    }

    /**
     * 处理 DNS 查询消息（防御异常，避免单个坏包停掉整个服务）
     *
     * @param TcpConnection $connection 客户端连接对象
     * @param mixed $data DNS 查询数据（JSON 格式）
     * @return void
     */
    public function onMessage(ConnectionInterface $connection, mixed $data): void
    {
        try {
            $query = json_decode((string) $data);
            if (!($query instanceof \stdClass) || empty($query->type) || !isset($query->name)) {
                return; // 畸形查询直接丢弃
            }
            $type = strtoupper((string) $query->type);
            $name = (string) $query->name;
            $rip = $connection->getRemoteIp();

            // EDNS0 客户端子网（GeoDNS）
            $ip = $rip;
            if (isset($query->addR->csubnet->ip)) {
                $ip = (string) $query->addR->csubnet->ip;
            }

            // 缓存命中：替换响应头 ID（偏移 0-1）后直接回包，跳过 resolve + encode
            if ($this->useCache) {
                $cacheKey = $type . '|' . $name . '|' . $ip;
                $cached = $this->responseCache[$cacheKey] ?? null;
                if ($cached !== null && $cached['expires'] > time()) {
                    $bin = $cached['bin'];
                    $reqId = (string) $query->id;
                    if (strlen($reqId) === 4 && ctype_xdigit($reqId)) {
                        $bin[0] = chr(hexdec(substr($reqId, 0, 2)));
                        $bin[1] = chr(hexdec(substr($reqId, 2, 2)));
                    }
                    $connection->send($bin, true); // raw：绕过协议 encode
                    return;
                }
            }

            // 解析答案
            $send = $this->resolveAnswer($type, $name);

            // 组装响应公共字段
            $send['id'] = (string) $query->id;
            $send['query'] = (string) $query->query;
            if (!isset($send['ttl'])) {
                $send['ttl'] = 0;
            }
            $send['info'] = json_encode([
                'domain' => $name,
                'querytype' => $type,
                'answertype' => $send['type'],
                'ip' => $ip,
                'rip' => $rip,
                'ttl' => $send['ttl'],
                'detail' => $send['detail'],
            ]);

            // 预编码为二进制（缓存复用 + raw 直发），ttl>0 时写入缓存
            $json = json_encode($send);
            $bin = \Dns\Protocols\Dns::encode($json);
            if ($this->useCache && $bin !== '' && (int) $send['ttl'] > 0) {
                $cacheKey = $type . '|' . $name . '|' . $ip;
                $this->responseCache[$cacheKey] = [
                    'bin' => $bin,
                    'expires' => time() + (int) $send['ttl'],
                ];
                $this->trimResponseCache();
            }
            $connection->send($bin, true);
        } catch (\Throwable $e) {
            // 丢弃异常查询，不中断服务（Workerman 的 acceptUdpConnection 会 catch 并 stopAll）
        }
    }

    /**
     * 控制响应缓存容量：先清理过期项，仍超限则淘汰最旧一批
     *
     * @return void
     */
    private function trimResponseCache(): void
    {
        $count = count($this->responseCache);
        if ($count <= $this->cacheMaxEntries) {
            return;
        }
        // 清理过期项
        $now = time();
        foreach ($this->responseCache as $key => $entry) {
            if ($entry['expires'] <= $now) {
                unset($this->responseCache[$key]);
            }
        }
        // 仍超限：按过期时间排序，淘汰最旧的 20%
        if (count($this->responseCache) > $this->cacheMaxEntries) {
            uasort($this->responseCache, static fn ($a, $b) => $a['expires'] <=> $b['expires']);
            $this->responseCache = array_slice($this->responseCache, -$this->cacheMaxEntries, null, true);
        }
    }

    /**
     * 解析答案：优先用户回调，回退内置权威记录
     *
     * @param string $type 查询类型
     * @param string $name 查询名
     * @return array 答案结构
     */
    private function resolveAnswer(string $type, string $name): array
    {
        if ($this->customResolver !== null) {
            $result = call_user_func($this->customResolver, $type, $name);
            if (is_array($result) && isset($result['type'])) {
                return $result;
            }
        }
        if ($this->records !== null) {
            $result = $this->records->resolve($type, $name);
            if (is_array($result)) {
                return $result;
            }
            // 域名不在本权威区 → SERVFAIL（避免对无关域名返回 NXDOMAIN 误导 LDNS）
            return ['type' => 'flag', 'flag' => 'SERVFAIL', 'ttl' => 0, 'detail' => []];
        }
        // 无任何记录来源
        return ['type' => 'flag', 'flag' => 'SERVFAIL', 'ttl' => 0, 'detail' => []];
    }

    /**
     * 加载权威记录配置
     *
     * @param array|string|null $zones 配置数组或文件路径
     * @return array|null 配置数组
     */
    private function loadZones($zones): ?array
    {
        if (is_array($zones)) {
            return $zones;
        }
        $path = null;
        if (is_string($zones) && $zones !== '') {
            $path = $zones;
        } elseif (defined('CANDYROOT')) {
            $candidate = CANDYROOT . 'Source/Inc/Dns.inc.php';
            if (is_file($candidate)) {
                $path = $candidate;
            }
        }
        if ($path === null || !is_file($path)) {
            return null;
        }
        // PHP 配置数组
        if (str_ends_with($path, '.php')) {
            $data = @include $path;
            return is_array($data) ? $data : null;
        }
        // JSON 文件
        $raw = @file_get_contents($path);
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }
}
