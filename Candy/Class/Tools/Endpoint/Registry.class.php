<?php
/***
 * 框架内置端点：注册表
 *
 * 业务说明：把「核心类/扩展类的方法」注册成固定路径的 HTTP 端点，
 * 用 Route::rule 挂进路由表，由 RouteContextInit::dispatchRegisteredHttpRoute 统一派发。
 *
 * ┌─ 三类端点，注册策略不同 ─────────────────────────────────────────────┐
 * │ ① 框架级（$frameworkEndpoints）                                      │
 * │    框架自身运行所必需的功能性端点。**恒定注册，不受任何配置影响**；    │
 * │    配置文件里既不能开启也不能关闭，项目无权干预。                      │
 * │                                                                      │
 * │ ② 内置可选（$defaultEndpoints）                                      │
 * │    框架预置的运维能力（健康检查、存活探测等）。**默认不开启**，         │
 * │    需在 Endpoint.inc.php 的 enable 白名单里显式列出才会注册。          │
 * │                                                                      │
 * │ ③ 应用扩展（$config['endpoints']）                                   │
 * │    项目自己注册的端点，同样须进 enable 白名单。                        │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * 注册时机：必须早于 UrlParser::parseUrl()（它内部调 RouteContextInit::init() 并派发）。
 * 因此挂在 Bootstrap::route() 的 listen('route_start') 之前一行。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-13 17:30:00 $
 *
 * @package Candy\Extend\Tools\Endpoint
 */
namespace Candy\Extend\Tools\Endpoint;

use Candy\Core\Route;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Registry
{
    /**
     * ① 框架级端点：**恒定注册，不可配置**
     *
     * 框架自身运行所必需的功能性操作入口。项目配置无法关闭、也无法移除。
     * 加新条目时必须确认它确实是「框架运行必需」，而不是「运维可选」——
     * 后者应放 $defaultEndpoints。
     *
     * @var array<string, array{0:string,1:string}>
     */
    protected static array $frameworkEndpoints = [
        'sys/version' => [Version::class, 'info'],
        'sys/url'     => [Url::class, 'handle'],
        'sys/health'  => [Health::class, 'check'],
        'sys/ping'    => [Ping::class, 'check'],
        'sys/crontab' => [Crontab::class, 'handle'],
        'sys/auth'    => [Auth::class, 'handle'],
        'sys/service' => [Service::class, 'handle'],
        'sys/config'  => [Config::class, 'handle'],
        'sys/ip'      => [Ip::class, 'handle'],
        'sys/push'    => [Push::class, 'handle'],
    ];

    /**
     * ② 内置可选端点：**默认不开启**，由 Endpoint.inc.php 的 enable 白名单控制
     *
     * ⚠️ 路径必须**两段**（如 sys/health）。RouteRegistry::parseRoute('ping')
     * 返回 group='ping'、rule=''，而 dispatchRegisteredHttpRoute 里 `if ($rule === '') return;`
     * 会直接跳过，所以单段路径永远派发不到。
     *
     * @var array<string, array{0:string,1:string}>
     */
    protected static array $defaultEndpoints = [
    ];

    /** 本次请求是否已注册（防重复） */
    protected static bool $booted = false;

    /**
     * 注册端点
     *
     * 框架级 → 无条件注册；内置可选 + 应用扩展 → 按 enable 白名单注册。
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // ── ① 框架级：恒定注册，不看任何配置 ──
        foreach (self::$frameworkEndpoints as $path => $handler) {
            $callback = self::normalizeCallback($handler);
            $path = trim((string) $path, '/');
            if ($callback !== '' && $path !== '') {
                Route::rule($path, $callback);
            }
        }

        // ── ② 内置可选 + ③ 应用扩展：走 enable 白名单 ──
        $config = self::config();
        if ($config === []) {
            return;   // 没配置 = 可选端点全部不开启（框架级不受影响）
        }

        $endpoints = array_merge(
            self::$defaultEndpoints,
            (array) ($config['endpoints'] ?? [])
        );

        $enabled = (array) ($config['enable'] ?? []);
        if ($enabled === []) {
            return;
        }

        foreach ($endpoints as $path => $handler) {
            if (!in_array($path, $enabled, true)) {
                continue;
            }

            $callback = self::normalizeCallback($handler);
            if ($callback === '') {
                continue;
            }

            $path = trim((string) $path, '/');
            if ($path === '') {
                continue;
            }

            Route::rule($path, $callback);
        }
    }

    /**
     * 端点鉴权（各端点 check() 方法第一行调用）
     *
     * 配置项：
     *   'token'      => 'xxx'                  // 全局令牌
     *   'tokens'     => ['sys/health' => 'yyy'] // 按端点覆盖（优先）
     * 两者都为空 = 不校验。
     *
     * ⚠️ 框架级端点（如 sys/version）**不走鉴权**，见各端点自身是否调用本方法。
     *
     * @param string $name 端点路径（如 sys/health）
     * @return void
     */
    public static function authorize(string $name = ''): void
    {
        // 只认显式配置的令牌：health/ping 这类端点没配 token 就必须放行，
        // 不能因为 runtimeToken() 自动生成了一枚就全站都要 token。
        $config = self::config();
        $tokens = (array) ($config['tokens'] ?? []);
        $token = (string) ($tokens[$name] ?? ($config['token'] ?? ''));

        if ($token === '') {
            return;
        }

        $got = (string) ($_GET['token'] ?? '');
        if ($got !== '' && hash_equals($token, $got)) {
            return;
        }

        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
            $bearer = trim(substr($auth, 7));
            if ($bearer !== '' && hash_equals($token, $bearer)) {
                return;
            }
        }

        headers('', 403);
        stop('forbidden');
    }

    /**
     * 取某端点生效的令牌（按端点覆盖优先，其次全局）
     *
     * @param string $name 端点路径
     * @return string 空字符串 = 未配置令牌
     */
    /**
     * 令牌信息（页面展示用）
     *
     * @return array{value:string,source:string,is_default:bool,default:string}
     *         source: sys（Sys.inc.php）/ endpoint（旧位置）/ runtime（兜底文件）
     */
    public static function tokenInfo(): array
    {
        $default = 'candy-sys';

        $sys = function_exists('loadConfig') ? loadConfig('Sys') : [];
        $security = (is_array($sys) && is_array($sys['security'] ?? null)) ? $sys['security'] : [];
        $sysToken = (string) ($security['token'] ?? '');
        if ($sysToken !== '') {
            return ['value' => $sysToken, 'source' => 'sys', 'is_default' => $sysToken === $default, 'default' => $default];
        }

        $config = self::config();
        $token = (string) ($config['token'] ?? '');
        if ($token !== '') {
            return ['value' => $token, 'source' => 'endpoint', 'is_default' => $token === $default, 'default' => $default];
        }

        return ['value' => self::runtimeToken(), 'source' => 'runtime', 'is_default' => false, 'default' => $default];
    }

    public static function token(string $name = ''): string
    {
        // ① 新版位置：Sys.inc.php 的 security.token / security.tokens
        $sys = function_exists('loadConfig') ? loadConfig('Sys') : [];
        $security = (is_array($sys) && is_array($sys['security'] ?? null)) ? $sys['security'] : [];
        $sysTokens = (array) ($security['tokens'] ?? []);
        $sysToken = (string) ($sysTokens[$name] ?? ($security['token'] ?? ''));
        if ($sysToken !== '') {
            return $sysToken;
        }

        // ② 兼容旧位置：Endpoint.inc.php 的 token / tokens（不写回，只读）
        $config = self::config();
        $tokens = (array) ($config['tokens'] ?? []);
        $token = (string) ($tokens[$name] ?? ($config['token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        // ③ 都没有：退回 Runtime 文件（裸框架只读部署的兜底；正常不会走到）
        return self::runtimeToken();
    }

    /**
     * 运行时令牌：**只读部署时的最后兜底**
     *
     * 正常路径是 `Sys.inc.php` 的 `security.token`（默认 `candy-sys`）。
     * 只有当 Sys 与 Endpoint 都没配、且项目目录不可写无法覆盖时，才生成/读取
     * `Runtime/.endpoint-token`（0600），保证裸框架仍能跑。
     *
     * @return string
     */
    public static function runtimeToken(): string
    {
        static $cached = null;
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $root = defined('CANDYROOT') ? CANDYROOT : dirname(__DIR__, 4) . '/';
        $file = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . '.endpoint-token';

        $raw = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if ($raw !== '' && preg_match('/^[A-Za-z0-9]{16,}$/', $raw) === 1) {
            return $cached = $raw;
        }

        $fresh = bin2hex(random_bytes(24));
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($file, $fresh, LOCK_EX) !== false) {
            @chmod($file, 0600);
        }

        return $cached = $fresh;
    }

    /**
     * 把处理器统一成 'Class::method' 字符串
     *
     * ⚠️ dispatchRegisteredHttpRoute 只认字符串（is_string 判断），
     * 写成数组 / 闭包会被静默跳过，因此这里必须归一化。
     *
     * @param mixed $handler
     * @return string
     */
    protected static function normalizeCallback(mixed $handler): string
    {
        if (is_string($handler)) {
            return str_contains($handler, '::') ? $handler : str_replace('@', '::', $handler);
        }
        if (is_array($handler) && count($handler) === 2) {
            return (string) $handler[0] . '::' . (string) $handler[1];
        }
        return '';
    }

    /**
     * 读配置（Source/Inc/Endpoint.inc.php）
     *
     * 用 CANDYROOT 常量而不是 C()：常量在框架引导时即确定，比 C() 更早可用。
     *
     * @return array
     */
    public static function config(): array
    {
        $root = defined('CANDYROOT') ? CANDYROOT : dirname(__DIR__, 3) . '/';
        $file = $root . 'Source/Inc/Endpoint.inc.php';
        if (!is_file($file)) {
            return [];
        }
        $cfg = include $file;
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * 列出全部已知端点，供排查「这个路径谁处理」
     *
     * 返回每项的类型：framework（框架级，恒开）/ optional（可选，看 enable）
     *
     * @return array<string, array{handler:string, enabled:bool, type:string}>
     */
    public static function list(): array
    {
        $config = self::config();
        $enabled = (array) ($config['enable'] ?? []);
        $out = [];

        foreach (self::$frameworkEndpoints as $path => $handler) {
            $out[$path] = [
                'handler' => self::normalizeCallback($handler),
                'enabled' => true,
                'type'    => 'framework',
            ];
        }

        $optional = array_merge(self::$defaultEndpoints, (array) ($config['endpoints'] ?? []));
        foreach ($optional as $path => $handler) {
            $out[$path] = [
                'handler' => self::normalizeCallback($handler),
                'enabled' => in_array($path, $enabled, true),
                'type'    => 'optional',
            ];
        }

        return $out;
    }
}
