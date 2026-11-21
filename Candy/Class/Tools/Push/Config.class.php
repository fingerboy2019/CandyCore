<?php
/***
 * 推送服务配置（框架默认值 → 项目覆盖）
 *
 * 设计原则：**零配置可用**。项目不写 Source/Inc/Push.inc.php 就全用默认值，
 * 要改才写；默认值集中在这里，不散落在各处。
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:40:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Config
{
    /**
     * 默认配置
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enable'   => true,
            'gateway'  => [
                'listen' => 'websocket://0.0.0.0:4020',
                'count'  => 1,
                // wss：页面是 https 时，前端 app.js 会自动用 wss://，服务端这里不开 SSL 就连不上。
                // cert/key 必须是运行用户（www）可读的路径 —— 宝塔证书是 root:600，
                // 需要先复制到站点内并 chown www（步骤见 DESIGN.md「开启 wss」）。
                'ssl'    => [
                    'enable'      => false,
                    'cert'        => '',
                    'key'         => '',
                    'verify_peer' => false,
                ],
            ],
            'register' => ['listen' => 'text://127.0.0.1:1236'],
            'worker'   => ['count' => 1],
            // persist=true：GlobalData + G() 双写（重启不丢，吃 Redis）
            // persist=false：只走 GlobalData 内存（省 Redis，重启丢）
            'offline'  => ['enable' => true, 'ttl' => 604800, 'max' => 200, 'persist' => true],
            'auth'     => ['required' => true, 'token' => null],
            'message'  => ['max_bytes' => 8192],
            // 应用注册表：app => ['label' => ..., 'key' => 'pk_...', 'offline' => [...]]
            // 为空也能用（首次发送时自动注册），由 /sys/push 页面维护
            'apps'     => [],
            // 限流（0 = 不限制）
            'limits'   => ['conns_per_ip' => 50, 'frames_per_second' => 20],
            // 审计日志（写 Runtime/Logs/push-YYYY-MM-DD.log）
            'audit'    => ['enable' => true, 'days' => 7],
        ];
    }

    /**
     * 读取合并后的配置（首次读取时加载项目覆盖文件）
     *
     * @param array<string, mixed>|null $override 运行时覆盖
     * @return array<string, mixed>
     */
    public static function get(?array $override = null): array
    {
        if (self::$cfg === null) {
            $cfg = self::defaults();
            $path = self::incPath() . 'Push.inc.php';
            if ($path !== '' && is_file($path) && function_exists('loadConfig')) {
                $project = loadConfig('Push');
                if (is_array($project)) {
                    $cfg = array_replace_recursive($cfg, $project);
                }
            }
            self::$cfg = $cfg;
        }
        if ($override === null || $override === []) {
            return self::$cfg;
        }
        return array_replace_recursive(self::$cfg, $override);
    }

    /**
     * 清掉进程内缓存（测试 / 改配置后）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$cfg = null;
    }

    /**
     * 项目配置目录
     *
     * @return string
     */
    protected static function incPath(): string
    {
        if (!function_exists('C')) {
            return '';
        }
        // Push.inc.php 走 incResolve：项目 Source/Inc 优先，其次框架默认骨架
        if (function_exists('incResolve')) {
            $file = incResolve('Push');
            if ($file !== '') {
                return dirname($file) . DIRECTORY_SEPARATOR;
            }
        }
        $path = C('INCPATH');
        return is_string($path) ? $path : '';
    }

    /** @var array<string, mixed>|null 进程内缓存 */
    private static ?array $cfg = null;
}
