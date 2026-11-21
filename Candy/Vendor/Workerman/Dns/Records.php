<?php
/**
 * DNS 权威记录存储
 *
 * 业务说明：为 DNS 服务器提供权威记录查询（无需实现 getDnsInfoByType 回调）。
 * 从配置数组（Source/Inc/Dns.inc.php 或 CLI --zones 指定文件）加载 zone 记录，
 * 支持 A/AAAA/CNAME/NS/MX/TXT/SOA/PTR 及通配符、CNAME 链、父域 SOA 回退。
 *
 * 配置格式示例（PHP 数组或 JSON 文件）：
 * ```php
 * return [
 *     'example.com' => [
 *         'A'    => ['93.184.216.34'],
 *         'AAAA' => ['2001:db8::1'],
 *         'NS'   => ['ns1.example.com', 'ns2.example.com'],
 *         'MX'   => [['name' => 'mail.example.com', 'pre' => 10]],
 *         'TXT'  => ['v=spf1 include:_spf.example.com ~all'],
 *         'SOA'  => ['mname' => 'ns1.example.com', 'rname' => 'admin.example.com',
 *                    'serial' => 2024010101, 'refresh' => 3600, 'retry' => 1200,
 *                    'expire' => 86400, 'minimum-ttl' => 600],
 *         'ttl'  => 60,
 *     ],
 *     'www.example.com' => ['CNAME' => 'example.com'],
 *     '*.dev.example.com' => ['A' => ['10.0.0.1']],   // 通配符
 *     '10.0.0.1' => ['PTR' => 'host.example.com'],    // PTR 简写（自动匹配 in-addr.arpa）
 * ];
 * ```
 *
 * @package Dns
 */
namespace Dns;

class Records
{
    /** zone 记录（规范化后）：name => ['A'=>[], 'CNAME'=>'', 'SOA'=>[], 'ttl'=>int, ...] */
    private array $zones = [];

    /**
     * 构造并加载 zone 记录
     *
     * @param array $zones 配置数组
     */
    public function __construct(array $zones)
    {
        foreach ($zones as $name => $records) {
            if (!is_array($records)) {
                continue;
            }
            $this->zones[strtolower(trim((string) $name, '.'))] = $records;
        }
    }

    /**
     * 解析一条 DNS 查询，返回可直接编码的答案结构
     *
     * 返回结构（detail 均为数组；CNAME 复合时 detail 为 CNAME 目标字符串 + a/aaaa 数组）：
     * - ['type'=>'A', 'detail'=>['1.2.3.4'], 'ttl'=>60]
     * - ['type'=>'CNAME+A', 'detail'=>'target.example.com', 'a'=>['1.2.3.4'], 'ttl'=>60]
     * - ['type'=>'none', 'detail'=>['type'=>'auto'], 'ttl'=>soa_ttl]  (NXDOMAIN，附带 SOA)
     * - null（不在本权威 zone 内，调用方决定处理）
     *
     * @param string $type 查询类型（A/AAAA/CNAME/NS/MX/TXT/SOA/PTR）
     * @param string $name 查询名（域名或 PTR 的 in-addr.arpa 倒序 IP）
     * @return array|null 答案结构
     */
    public function resolve(string $type, string $name): ?array
    {
        $type = strtoupper($type);
        // 协议层使用 TEXT（RFC 1035 类型名），zone 配置约定用 TXT，统一归一化
        if ($type === 'TEXT') {
            $type = 'TXT';
        }
        $name = strtolower(trim($name, '.'));

        // PTR：4.3.2.1.in-addr.arpa → 反查 IP
        if ($type === 'PTR' && str_ends_with($name, '.in-addr.arpa')) {
            $rev = substr($name, 0, -strlen('.in-addr.arpa'));
            $ip = implode('.', array_reverse(explode('.', $rev)));
            $zone = $this->findZone($ip);
            $ptr = $zone['PTR'] ?? null;
            if ($ptr !== null) {
                return $this->answer($type, is_array($ptr) ? array_values($ptr) : [$ptr], $zone);
            }
            return $this->nxdomain($name);
        }

        // 精确匹配
        $zone = $this->zones[$name] ?? null;
        if ($zone !== null) {
            $answer = $this->answerForZone($type, $name, $zone);
            if ($answer !== null) {
                return $answer;
            }
        }

        // CNAME 链（查询名配置了 CNAME，则解析目标）
        $chain = $this->resolveCnameChain($type, $name, 0);
        if ($chain !== null) {
            return $chain;
        }

        // 通配符（*.example.com 等，取最深层匹配）
        $wildZone = $this->findWildcardZone($name);
        if ($wildZone !== null) {
            [$wildName, $zone] = $wildZone;
            $answer = $this->answerForZone($type, $name, $zone);
            if ($answer !== null) {
                return $answer;
            }
        }

        // 无记录：若域名在某个 zone 内 → NXDOMAIN（带 SOA）；否则不在本权威范围 → null
        return $this->hasZoneFor($name) ? $this->nxdomain($name) : null;
    }

    /**
     * 查询区是否存在（域名或其父域在 zones 中）
     *
     * @param string $name 域名
     * @return bool
     */
    public function hasZoneFor(string $name): bool
    {
        return $this->findZone($name) !== null;
    }

    /**
     * 取最近父 zone 的 SOA（用于 NXDOMAIN / SOA 查询）
     *
     * @param string $name 域名
     * @return array|null SOA 记录（含 ttl）
     */
    public function soa(string $name): ?array
    {
        $zone = $this->findZone(strtolower(trim($name, '.')));
        if ($zone === null || empty($zone['SOA'])) {
            return null;
        }
        $soa = is_array($zone['SOA']) ? $zone['SOA'] : [];
        $ttl = (int) ($zone['ttl'] ?? $soa['minimum-ttl'] ?? 60);
        return ['type' => 'SOA', 'detail' => $soa, 'ttl' => $ttl];
    }

    /**
     * 获取 zone 的 TTL
     *
     * @param string $name 域名
     * @return int
     */
    public function zoneTtl(string $name): int
    {
        $zone = $this->findZone(strtolower(trim($name, '.')));
        return (int) ($zone['ttl'] ?? 60);
    }

    /* =====================================================================
     * 内部实现
     * ===================================================================== */

    /**
     * 从 zone 记录组装某类型答案
     *
     * @param string $type 查询类型
     * @param string $name 查询名（已规范化）
     * @param array $zone zone 记录
     * @return array|null 答案结构
     */
    private function answerForZone(string $type, string $name, array $zone): ?array
    {
        $ttl = (int) ($zone['ttl'] ?? 60);

        // CNAME 独占：查询名配置了 CNAME 时返回 CNAME（resolve 里走 CNAME 链）
        if (isset($zone['CNAME'])) {
            return null;
        }
        // SOA 查询：直接返回本 zone SOA（即使类型不是 SOA 也可能被显式查询）
        if ($type === 'SOA') {
            if (empty($zone['SOA'])) {
                return null;
            }
            return $this->answer('SOA', $zone['SOA'], $zone);
        }
        if (empty($zone[$type])) {
            return null;
        }
        $detail = $zone[$type];
        if (!is_array($detail)) {
            $detail = [$detail];
        }
        return $this->answer($type, array_values($detail), $zone);
    }

    /**
     * 组装普通答案结构
     *
     * @param string $type 类型
     * @param array $detail 记录值
     * @param array $zone zone 记录
     * @return array
     */
    private function answer(string $type, array $detail, array $zone): array
    {
        return ['type' => $type, 'detail' => $detail, 'ttl' => (int) ($zone['ttl'] ?? 60)];
    }

    /**
     * NXDOMAIN 答案（附 SOA）
     *
     * @param string $name 查询名
     * @return array
     */
    private function nxdomain(string $name): array
    {
        $soa = $this->soa($name);
        return [
            'type' => 'none',
            'detail' => ['type' => 'auto', 'name' => $name],
            'ttl' => $soa['ttl'] ?? 60,
        ];
    }

    /**
     * 解析 CNAME 链：查询名 → CNAME 目标 → 目标 A/AAAA（最多 8 跳防环）
     *
     * @param string $type 查询类型
     * @param string $name 起始查询名
     * @param int $depth 当前跳数
     * @return array|null 复合答案或 null
     */
    private function resolveCnameChain(string $type, string $name, int $depth): ?array
    {
        if ($depth > 8) {
            return null;
        }
        $zone = $this->zones[$name] ?? null;
        if ($zone === null || empty($zone['CNAME'])) {
            return null;
        }
        $target = strtolower(trim((string) $zone['CNAME'], '.'));
        $ttl = (int) ($zone['ttl'] ?? 60);

        // CNAME 目标也在本权威区且配置了对应记录 → 复合返回
        $targetZone = $this->zones[$target] ?? null;
        if ($targetZone !== null && !empty($targetZone[$type])) {
            $targetDetail = $targetZone[$type];
            if (!is_array($targetDetail)) {
                $targetDetail = [$targetDetail];
            }
            if ($type === 'A') {
                return ['type' => 'CNAME+A', 'detail' => $target, 'a' => array_values($targetDetail), 'ttl' => $ttl];
            }
            if ($type === 'AAAA') {
                return ['type' => 'CNAME+AAAA', 'detail' => $target, 'aaaa' => array_values($targetDetail), 'ttl' => $ttl];
            }
        }
        // 目标继续是 CNAME → 递归
        if (isset($targetZone['CNAME'])) {
            $next = $this->resolveCnameChain($type, $target, $depth + 1);
            if ($next !== null) {
                return $next;
            }
        }
        // 仅返回 CNAME（目标不在本权威区，由客户端递归查询）
        return ['type' => 'CNAME', 'detail' => [$target], 'ttl' => $ttl];
    }

    /**
     * 查找精确或父域 zone
     *
     * @param string $name 域名（已规范化）
     * @return array|null zone 记录
     */
    private function findZone(string $name): ?array
    {
        $parts = explode('.', $name);
        while ($parts !== []) {
            $candidate = implode('.', $parts);
            if (isset($this->zones[$candidate])) {
                return $this->zones[$candidate];
            }
            array_shift($parts);
        }
        return null;
    }

    /**
     * 查找通配符 zone（*.example.com），返回 [通配名, zone 记录] 或 null
     *
     * @param string $name 域名（已规范化）
     * @return array|null
     */
    private function findWildcardZone(string $name): ?array
    {
        $parts = explode('.', $name);
        while (count($parts) > 1) {
            $wild = '*.' . implode('.', array_slice($parts, 1));
            if (isset($this->zones[$wild])) {
                return [$wild, $this->zones[$wild]];
            }
            array_shift($parts);
        }
        return null;
    }
}
