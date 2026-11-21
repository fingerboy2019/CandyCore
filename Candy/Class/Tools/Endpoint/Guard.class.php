<?php
/***
 * 框架端点授权守卫（纯令牌，支持 cookie 记忆）
 *
 * 业务说明：裸框架没有「管理员 / 登录用户」概念，端点授权**只认令牌**。
 * 令牌不放在 URL 里（避免出现在日志、Referer、浏览器历史、截图里），
 * 而是通过页面输入一次，存进 **cookie**，之后同源请求自动带上。
 *
 * 令牌来源（`Registry::token()`）：
 *   ① `Candy/Class/Tools/Inc/Sys.inc.php`（或项目 `Source/Inc/Sys.inc.php`）的
 *      `security.token` / `security.tokens[端点]`，默认 `candy-sys`
 *   ② 旧位置 `Source/Inc/Endpoint.inc.php` 的 token/tokens（兼容）
 *   ③ 都没有 → `Registry::runtimeToken()`（只读部署兜底）
 *
 * 携带方式（按顺序尝试）：
 *   ① `?token=xxx`（脚本 / 无头调用，临时用）
 *   ② `Authorization: Bearer xxx`
 *   ③ **cookie `candy_endpoint_token`**（浏览器页面用，见 `Guard::COOKIE`）
 *
 * 校验失败：`403` + JSON，走框架 `headers()` / `stop()`（常驻下不杀 worker）。
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 12:40:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Guard
{
    /** 令牌 cookie 名 */
    public const COOKIE = 'candy_endpoint_token';

    /**
     * 断言请求带合法令牌（不通过就终止请求）
     *
     * @param string $endpoint 端点路径
     * @return array<string, mixed>
     */
    public static function token(string $endpoint = ''): array
    {
        $expected = Registry::token($endpoint);
        if ($expected === '') {
            self::deny(403, '端点令牌未初始化：请在 Source/Inc/Sys.inc.php 配置 security.token（默认 candy-sys）');
        }

        $via = self::verify($expected);
        if ($via !== '') {
            return ['via' => $via];
        }

        // 带了令牌但不对 → 计一次失败；同一 IP 错满阈值自动封禁（防字典暴力猜测）
        if (self::supplied() !== '') {
            self::recordFailure();
        }

        // 浏览器直接点链接：引导回令牌输入页，而不是甩一串 403 JSON 让人卡住
        if (self::browser()) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/sys/url');
            // 带了令牌但不对 → err=1，输入页会提示重试
            $err = self::supplied() !== '' ? '&err=1' : '';
            $back = '/sys/auth?return=' . rawurlencode($uri) . $err;
            if (function_exists('headers')) {
                headers('Location: ' . $back, 302);
            }
            if (function_exists('stop')) {
                stop();
            }
            exit;
        }

        self::deny(403, '缺少或错误的令牌：请在 /sys/url 页面填入令牌（会存 cookie），或用 ?token=… / Authorization: Bearer …'
            . ' 调用；令牌见 /sys/config → Sys → security.token（默认 candy-sys）');
        return [];
    }

    /**
     * 静默校验（不终止请求，供页面显示「已认证 / 未认证」）
     *
     * @param string $endpoint 端点路径
     * @return bool
     */
    public static function check(string $endpoint = ''): bool
    {
        $expected = Registry::token($endpoint);
        if ($expected === '') {
            return false;
        }
        return self::verify($expected) !== '';
    }

    /**
     * 取请求里携带的令牌原文（排障用，页面不要展示）
     *
     * @return string
     */
    public static function supplied(): string
    {
        $token = (string) ($_GET['token'] ?? '');
        if ($token !== '') {
            return $token;
        }

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $auth = (string) ($_SERVER[$key] ?? '');
            if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
                $bearer = trim(substr($auth, 7));
                if ($bearer !== '') {
                    return $bearer;
                }
            }
        }

        return (string) ($_COOKIE[self::COOKIE] ?? '');
    }

    /**
     * 校验并按来源返回标记
     *
     * @param string $expected 期望令牌
     * @return string via：query / bearer / cookie，失败为空串
     */
    protected static function verify(string $expected): string
    {
        $expected = trim($expected);
        $query = trim((string) ($_GET['token'] ?? ''));
        if ($query !== '' && hash_equals($expected, $query)) {
            return 'query';
        }

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $auth = (string) ($_SERVER[$key] ?? '');
            if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
                $bearer = trim(substr($auth, 7));
                if ($bearer !== '' && hash_equals($expected, $bearer)) {
                    return 'bearer';
                }
            }
        }

        $cookie = trim((string) ($_COOKIE[self::COOKIE] ?? ''));
        if ($cookie !== '' && hash_equals($expected, $cookie)) {
            return 'cookie';
        }

        return '';
    }

    /**
     * 是不是浏览器直接访问（Accept 含 text/html）
     *
     * @return bool
     */
    protected static function browser(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    /**
     * 当前请求携带的 cookie 键（诊断用，不含值）
     *
     * @return array<int, string>
     */
    public static function cookieKeys(): array
    {
        return array_keys((array) ($_COOKIE ?? []));
    }

    /**
     * 诊断信息（**不输出令牌明文**，只给长度与是否匹配）
     *
     * @param string $endpoint 端点路径
     * @return array<string, mixed>
     */
    public static function diagnose(string $endpoint = 'sys/crontab'): array
    {
        $expected = Registry::token($endpoint);
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        $via = $expected === '' ? '' : self::verify($expected);

        return [
            'cookie_name'     => self::COOKIE,
            'cookie_present'  => $cookie !== '',
            'cookie_len'      => strlen($cookie),
            'expected_len'    => strlen($expected),
            'match'           => $via !== '',
            'via'             => $via,
            'request_host'    => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'request_uri'     => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'cookie_keys'     => self::cookieKeys(),
            'accept'          => (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
        ];
    }

    /**
     * sys 下 IP 防护配置（框架默认 + 项目覆盖，键范围以框架默认为准）
     *
     * @return array<string,mixed>
     */
    public static function ipConfig(): array
    {
        $defaults = [
            'enable'   => true,
            'mode'     => 'blacklist',
            'max_fail' => 3,
            'window'   => 600,
            'ban_time'   => 7200,
            'trust_local' => false,
            'allow'      => [],
        ];
        // 配置在 Sys.inc.php 的 security.ip_guard 分组下（sys 系统配置容器）
        $one = [];
        if (function_exists('loadConfig')) {
            $sys = loadConfig('Sys');
            $one = (is_array($sys) && is_array($sys['security']['ip_guard'] ?? null))
                ? $sys['security']['ip_guard'] : [];
            // 兼容旧部署：以前放在 Security.inc.php / Endpoint.inc.php 里
            if ($one === []) {
                $old = loadConfig('Security');
                $one = (is_array($old) && is_array($old['ip_guard'] ?? null)) ? $old['ip_guard'] : [];
            }
            if ($one === []) {
                $old = loadConfig('Endpoint');
                $one = (is_array($old) && is_array($old['ip_guard'] ?? null)) ? $old['ip_guard'] : [];
            }
        }
        $out = array_replace($defaults, array_intersect_key($one, $defaults));
        $out['mode'] = strtolower((string) $out['mode']) === 'whitelist' ? 'whitelist' : 'blacklist';
        foreach (['max_fail', 'window', 'ban_time'] as $k) {
            $out[$k] = max(1, (int) $out[$k]);
        }
        return $out;
    }

    /**
     * sys/* 的 IP 防护（**任何模式都生效**，不受 DEBUG 影响）
     *
     * 项目路径不经过这里 —— 那条仍由 Bootstrap::security() 按 isDebug() 决定。
     *
     * @param string $endpoint 端点路径（仅用于提示）
     * @return void
     */
    public static function ipGuard(string $endpoint = 'sys'): void
    {
        $cfg = self::ipConfig();
        if (empty($cfg['enable'])) {
            return;
        }
        $ip = (string) \Candy\Extend\Network\Client::getClientIp();
        if ($ip === '') {
            return;
        }

        // ① 静态豁免：配置里的 allow 列表（两种模式都直接放行）
        if (self::isAllowed($ip, $cfg)) {
            return;
        }

        // ② 白名单即豁免名单：黑名单模式下也优先放行，避免把运维自己关在门外
        if (\Candy\Core\Security::find($ip, 'w', 'sys')) {
            return;
        }

        if ($cfg['mode'] === 'whitelist') {
            self::block('白名单模式：' . $ip . ' 不在允许列表。'
                . '（管理员可执行 php candy ip add ' . $ip . ' --type=w 放行）');
            return;
        }

        if (\Candy\Core\Security::find($ip, 'b', 'sys')) {
            self::block('您的 IP（' . $ip . '）已被暂时禁止访问。' . "\n"
                . '原因：连续多次输入错误的访问令牌。' . "\n"
                . '解除方式：在服务器执行 php candy ip del ' . $ip . ' --scope=sys'
                . "\n" . '（等待封禁到期也会自动解除，默认 2 小时）');
        }
    }

    /**
     * IP 是否在静态豁免范围内
     *
     * @param string $ip  客户端 IP
     * @param array  $cfg ip_guard 配置
     * @return bool
     */
    protected static function isAllowed(string $ip, array $cfg): bool
    {
        foreach ((array) ($cfg['allow'] ?? []) as $one) {
            if (is_string($one) && $one !== '' && $one === $ip) {
                return true;
            }
        }
        if (!empty($cfg['trust_local']) && ($ip === '127.0.0.1' || $ip === '::1')) {
            return true;
        }
        return false;
    }

    /**
     * 拦截：浏览器给框架自带的「网络防护盾」页面，API 请求给 JSON
     *
     * @param string $message 提示文案
     * @return void
     */
    protected static function block(string $message): void
    {
        if (function_exists('headers')) {
            headers('', 403);
        }
        // API/JSON 调用方：走自家 deny，HTTP 状态码才是 403（框架异常响应会给 200 + code:9999）
        if (self::wantsJson()) {
            self::deny(403, $message);
            return;
        }
        // 浏览器：框架自带的「网络防护盾」页面（firewall 类型自带 403）
        if (function_exists('showExceptionMsg')) {
            showExceptionMsg('firewall', $message, '', '', true);
            return;
        }
        self::deny(403, $message);
    }

    /**
     * 当前请求是否期望 JSON 响应
     *
     * 与框架 Exception::apiFormat() 的判定口径保持一致，避免"该给页面却给了 JSON"。
     *
     * @return bool
     */
    protected static function wantsJson(): bool
    {
        if (function_exists('C') && C('API') !== null) {
            return true;
        }
        $path = (string) ($_SERVER['PATH_INFO'] ?? ($_SERVER['REQUEST_URI'] ?? ''));
        if (preg_match('/\.(json|xml)(\?|$)/i', $path) === 1) {
            return true;
        }
        foreach (['view', 'format'] as $key) {
            $v = strtolower((string) ($_GET[$key] ?? ''));
            if ($v === 'json' || $v === 'xml') {
                return true;
            }
        }
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'json');
    }

    /**
     * 记录一次令牌校验失败，达到阈值即封禁该 IP
     *
     * @param string $ip 空 = 当前客户端
     * @return int 当前窗口内的失败次数（未启用/白名单模式返回 0）
     */
    public static function recordFailure(string $ip = ''): int
    {
        $cfg = self::ipConfig();
        if (empty($cfg['enable']) || $cfg['mode'] !== 'blacklist') {
            return 0;
        }
        if ($ip === '') {
            $ip = (string) \Candy\Extend\Network\Client::getClientIp();
        }
        if ($ip === '') {
            return 0;
        }
        // 豁免对象不计数：否则运维把自己加进白名单后仍会因为连续误输被"记在黑账上"
        if (self::isAllowed($ip, $cfg) || \Candy\Core\Security::find($ip, 'w', 'sys')) {
            return 0;
        }

        try {
            $cache = \Candy\Core\Cache::store();
            $key = 'sys.ipfail:' . md5($ip);
            $times = (int) $cache->get($key, 0) + 1;
            $cache->set($key, $times, (int) $cfg['window']);
            if ($times < (int) $cfg['max_fail']) {
                return $times;
            }
            $cache->delete($key);
        } catch (\Throwable $e) {
            return 0;
        }

        if (function_exists('addIPList')) {
            addIPList('b', $ip, time() + (int) $cfg['ban_time'], 'sys');
        }
        return $times;
    }

    /**
     * 拒绝访问
     *
     * @param int    $code    HTTP 状态码
     * @param string $message 说明
     * @return void
     */
    protected static function deny(int $code, string $message): void
    {
        if (function_exists('headers')) {
            headers('', $code);
        }
        $body = (string) json_encode(
            ['ok' => false, 'code' => $code, 'message' => $message],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (function_exists('stop')) {
            stop($body);
        }
        exit($body);
    }
}
