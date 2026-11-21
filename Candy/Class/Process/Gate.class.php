<?php
/**
 * 进程执行开关
 *
 * 默认**关闭**。关闭时 Runner / Forker / Caller::call 一律直接抛异常，
 * 不产生任何进程，也不读任何进程表 —— 现有站点零成本、零行为变化。
 *
 * 「不使用不加载」由 PHP 的懒加载天然保证：本类与包内其它类只有在业务代码
 * 第一次引用时才会被 autoload。**没有任何引导流程会主动 require 本包。**
 *
 * 开启方式（优先级从高到低）：
 *  1. 环境变量  CANDY_PROCESS=1
 *  2. 常量      PROCESS_ENABLE（在 Source/Inc/System.inc.php 里
 *               `doDefine('PROCESS_ENABLE', true);`）
 *  3. 配置      C('PROCESS_ENABLE')
 *  4. 运行时全局 G('processEnable')
 *  5. 都没有 → **false**
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 12:00:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

use Candy\Extend\Process\Exception\ProcessUnavailableException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Gate
{
    /**
     * 解析结果缓存（每次请求只解析一次）
     *
     * @var bool|null
     */
    private static ?bool $enabled = null;

    /**
     * 上一次解析到的来源，便于 env / 排障显示「为什么是关的」
     *
     * @var string
     */
    private static string $source = 'default';

    /**
     * 开关是否打开
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        // 1. 环境变量（最高优先级，便于压测/临时开）
        $env = getenv('CANDY_PROCESS');
        if (is_string($env) && $env !== '') {
            $on = in_array(strtolower($env), ['1', 'true', 'on', 'yes'], true);
            self::$source = 'env:CANDY_PROCESS';
            return self::$enabled = $on;
        }

        // 2. 常量（项目层 Source/Inc/System.inc.php 里 doDefine 出来）
        if (defined('PROCESS_ENABLE')) {
            self::$source = 'const:PROCESS_ENABLE';
            return self::$enabled = (bool) constant('PROCESS_ENABLE');
        }

        // 3. 框架配置
        $cfg = function_exists('C') ? C('PROCESS_ENABLE') : null;
        if ($cfg !== null) {
            self::$source = 'config:PROCESS_ENABLE';
            return self::$enabled = (bool) $cfg;
        }

        // 4. 运行时全局
        $glob = function_exists('G') ? G('processEnable') : null;
        if ($glob !== null) {
            self::$source = 'global:processEnable';
            return self::$enabled = (bool) $glob;
        }

        // 5. 默认关闭
        self::$source = 'default(off)';
        return self::$enabled = false;
    }

    /**
     * 开关来源
     *
     * @return string
     */
    public static function source(): string
    {
        if (self::$enabled === null) {
            self::enabled();
        }
        return self::$source;
    }

    /**
     * 断言开关已打开，否则抛出可执行的提示
     *
     * @param string $mode 能力标识：sync / spawn / fork / call
     * @return void
     * @throws ProcessUnavailableException
     */
    public static function assert(string $mode): void
    {
        if (self::enabled()) {
            return;
        }
        throw (new ProcessUnavailableException(
            '进程执行能力未开启（Candy\Extend\Process 默认关闭）。'
            . '如需使用请在 Source/Inc/System.inc.php 中加一行：doDefine(\'PROCESS_ENABLE\', true); '
            . '或设置环境变量 CANDY_PROCESS=1。关闭状态下不会启动任何进程，对现有站点零影响。'
        ))->setMode($mode);
    }

    /**
     * 清空缓存（改配置 / 跑测试时用）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$enabled = null;
        self::$source = 'default';
    }

    /**
     * 自省信息，供 `php candy env` 输出
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return [
            'enabled' => self::enabled(),
            'source'  => self::source(),
        ];
    }
}
