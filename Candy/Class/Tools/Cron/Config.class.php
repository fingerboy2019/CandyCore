<?php
/***
 * 框架内置的计划任务配置（把内置 handler 注进 `Workerman\Crontab\Config`）
 *
 * 目的：让「装上框架就能跑计划任务」成立，不依赖项目层的任何东西。
 * candy SaaS 版的 `Workerman\Crontab\Config::loadProjectConfig()` 只从
 * `Application/Admin/Services/Jobs/` 扫 handler，裸框架里那个目录不存在 →
 * handler 列表为空 → 任务一律 `handler invalid`。这里补上框架自带的两个 handler。
 *
 * 合并优先级（后者覆盖前者）：
 *   ① 框架内置（本类 builtinHandlers）
 *   ② 项目 `Source/Inc/CrontabScheduler.inc.php` 的 handlers（可选，用于覆盖/定制）
 *   ③ 项目 Jobs 目录扫描（`Config` 自己做的，保持向后兼容）
 *
 * 调用时机：凡是会用到 `Workerman\Crontab\Manager` 的地方都先 `ensure()` 一次
 * （Task 适配层、scheduler 服务入口）。`ensure()` 幂等，可以重复调。
 *
 * @package Candy\Extend\Tools\Cron
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 14:25:00
 */
namespace Candy\Extend\Tools\Cron;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Config
{
    /** 是否已应用（幂等） */
    private static bool $applied = false;

    /**
     * 若 Workerman\Crontab 存在，则注入框架内置 handler
     *
     * @return bool 是否可用（类是存在的）
     */
    public static function ensure(): bool
    {
        $cfgClass = '\Workerman\Crontab\Config';
        if (!class_exists($cfgClass)) {
            return false;
        }

        if (self::$applied) {
            return true;
        }

        try {
            $current = $cfgClass::get();
            $merged = array_replace_recursive(
                (array) $current,
                ['handlers' => self::builtinHandlers()]
            );
            // handlers 用覆盖而不是递归合并，避免同名 handler 的 class/method 被拆散
            $merged['handlers'] = array_merge(
                self::builtinHandlers(),
                (array) ($current['handlers'] ?? [])
            );
            $cfgClass::set($merged);
            self::$applied = true;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 框架内置 handler 表
     *
     * @return array<string, array<string, mixed>>
     */
    public static function builtinHandlers(): array
    {
        return [
            'http_callback' => [
                'title'  => 'HTTP 回调',
                'class'  => '\Candy\Extend\Tools\Cron\Handler\HttpCallback',
                'method' => 'run',
                'desc'   => '定时请求 URL（GET/POST），可校验期望状态码',
            ],
            'push' => [
                'title'  => 'WebSocket 推送',
                'class'  => '\Candy\Extend\Tools\Cron\Handler\Push',
                'method' => 'run',
                'desc'   => '把消息交给 WebSocket 服务推送；离线进队列补发',
            ],
            'shell_callback' => [
                'title'  => '执行命令',
                'class'  => '\Candy\Extend\Tools\Cron\Handler\ShellCallback',
                'method' => 'run',
                'desc'   => '在服务器上执行 shell 命令；默认关闭，需在 CrontabScheduler.inc.php 开启',
            ],
        ];
    }

    /**
     * 取合并后的配置（供页面展示 / 排障）
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        self::ensure();
        $cfgClass = '\Workerman\Crontab\Config';
        if (!class_exists($cfgClass)) {
            return ['available' => false, 'handlers' => self::builtinHandlers()];
        }
        try {
            $cfg = (array) $cfgClass::get();
            return [
                'available'         => true,
                'handlers'          => $cfg['handlers'] ?? [],
                'globalDataGateway' => $cfg['globalDataGateway'] ?? '',
                'prefix'            => $cfg['prefix'] ?? '',
                'record'            => $cfg['record'] ?? [],
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage(), 'handlers' => self::builtinHandlers()];
        }
    }

    /**
     * 重置（测试用）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$applied = false;
    }
}
