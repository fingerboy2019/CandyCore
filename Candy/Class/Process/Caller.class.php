<?php
/**
 * 同进程命令调用器（形态 C）
 *
 * 与 Runner / Forker 的根本区别：**它不新起任何进程**，
 * 直接在当前的 FPM worker 或常驻 worker 里执行，输出被 ob_start 捕获。
 *
 * 因此它天然继承当前的 PHP 版本、扩展、容器、DB 连接 —— 这正好规避了
 * 「CLI 与 FPM 两套解释器」的老问题；代价是它是**同步阻塞**的，
 * 且受 max_execution_time / fastcgi_read_timeout 约束，**不能跑长任务**。
 *
 * ⚠️ 三个必须知道的点：
 *  1. **不能强制中断**。PHP 的同进程代码没有抢占式超时，只能靠 set_time_limit
 *     让 PHP 自己报致命错误 —— 那是 fatal，catch 不到。要超时强杀请用 Runner::run()。
 *  2. **必须能容住 stop()**。框架的 stop() 在 FPM 下是 exit，会直接杀掉当前 worker。
 *     本类通过捕获 jump_exit 异常把它当正常结束处理；被调命令若自己调用了原生 exit，
 *     仍然会杀掉 worker —— 所以被 call 的命令必须遵守「不用原生 exit」的约定。
 *  3. **命令解析器要显式绑定**。框架 Console 的实现形状不同，本类不去猜，
 *     由 Source/Inc/ 里调用 setResolver() 把「命令名 → 可执行回调」接进来。
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

use Candy\Extend\Process\Exception\ProcessUnavailableException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Caller
{
    /**
     * 命令解析器：fn(string $name, array $args): mixed
     *
     * @var \Closure|null
     */
    private static ?\Closure $resolver = null;

    /**
     * 全局命令白名单
     *
     * @var array<int, string>
     */
    private static array $whitelist = [];

    /**
     * 是否放开白名单
     *
     * @var bool
     */
    private static bool $allowAll = false;

    /**
     * 当前嵌套深度
     *
     * @var int
     */
    private static int $depth = 0;

    /**
     * 绑定命令解析器
     *
     * 示例（在 Source/Inc/ 里）：
     * ```php
     * \Candy\Extend\Process\Caller::setResolver(function (string $name, array $args) {
     *     // 把 $name 与 $args 交给框架 Console 去执行，返回 int 退出码或 null
     * });
     * ```
     *
     * @param callable|null $resolver fn(string $name, array $args): mixed
     * @return void
     */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * 设置全局白名单（不设置则任何命令都不允许调用）
     *
     * @param array<int, string> $names 命令名列表
     * @return void
     */
    public static function allow(array $names): void
    {
        self::$whitelist = array_values(array_map('strval', $names));
    }

    /**
     * 放开白名单（危险：等于允许执行任意已注册命令，务必配合鉴权）
     *
     * @param bool $allow 是否放开
     * @return void
     */
    public static function allowAll(bool $allow = true): void
    {
        self::$allowAll = $allow;
    }

    /**
     * 取当前白名单
     *
     * @return array<int, string>
     */
    public static function whitelist(): array
    {
        return self::$whitelist;
    }

    /**
     * 调用一个已注册命令（同进程执行）
     *
     * @param string           $name 命令名
     * @param array<int|string, mixed> $args 命令参数
     * @param CallOptions|null $opt  调用选项
     * @return CallResult
     * @throws ProcessUnavailableException 白名单不允许 / 未绑定解析器 / 递归过深
     */
    public static function call(string $name, array $args = [], ?CallOptions $opt = null): CallResult
    {
        $opt ??= new CallOptions();
        Gate::assert('call');
        $name = trim($name);
        if ($name === '') {
            throw (new ProcessUnavailableException('命令名不能为空'))->setMode('call');
        }

        $allowed = $opt->whitelist;
        if ($allowed === null) {
            $allowed = self::$allowAll ? null : self::$whitelist;
        }
        if (is_array($allowed) && !in_array($name, $allowed, true)) {
            $hint = $allowed === []
                ? '当前未配置白名单，请先在 Source/Inc/ 里调用 Caller::allow([...])'
                : '可用命令：' . implode(', ', $allowed);
            throw (new ProcessUnavailableException('命令不在白名单内：' . $name . '（' . $hint . '）'))->setMode('call');
        }

        if (self::$resolver === null) {
            throw (new ProcessUnavailableException(
                '未绑定命令解析器：请先在 Source/Inc/ 里调用 Caller::setResolver() '
                . '把框架 Console 的「命令名 → 可执行回调」接进来'
            ))->setMode('call');
        }

        $resolver = self::$resolver;
        return self::invoke(static fn() => $resolver($name, $args), $opt, true);
    }

    /**
     * 在同一进程里执行一个 callable，捕获其输出与异常
     *
     * @param callable         $fn           要执行的回调
     * @param CallOptions|null $opt          调用选项
     * @param bool             $intIsExitCode 是否把返回的 int 解释成退出码
     *                                        （call() 为 true；invoke() 默认 false ——
     *                                        普通闭包 return 7 很可能是结果而非失败码，
     *                                        不加区分会把正常结果误判成失败）
     * @return CallResult
     * @throws ProcessUnavailableException 嵌套过深
     *
     * @note 本方法**不受 Gate 开关限制**：它只是「ob 捕获 + 异常兜底」的纯内存工具，
     *       不起进程、无副作用。开关只拦 call() / run() / spawn() / fork()。
     */
    public static function invoke(callable $fn, ?CallOptions $opt = null, bool $intIsExitCode = false): CallResult
    {
        $opt ??= new CallOptions();

        if (self::$depth >= $opt->maxDepth) {
            throw (new ProcessUnavailableException(
                '同一进程内的命令调用嵌套过深（当前 ' . self::$depth . ' 层，上限 ' . $opt->maxDepth . '），'
                . '疑似命令递归调用自己'
            ))->setMode('call');
        }

        $level = ob_get_level();
        $start = microtime(true);
        $output = '';
        $exitCode = 0;
        $error = null;
        $stopped = false;

        $oldLimit = null;
        if ($opt->timeLimit > 0 && function_exists('set_time_limit')) {
            $oldLimit = (int) ini_get('max_execution_time');
            @set_time_limit($opt->timeLimit);
        }

        self::$depth++;
        ob_start();
        try {
            $ret = $fn();
            if ($intIsExitCode) {
                if (is_int($ret)) {
                    $exitCode = $ret;
                } elseif ($ret === false) {
                    $exitCode = 1;
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof \Exception && $e->getMessage() === 'jump_exit') {
                // 命令主动调用了框架的 stop()：属正常结束，输出照常保留
                $stopped = true;
                $exitCode = 0;
            } else {
                $error = $e;
                $exitCode = 1;
            }
        } finally {
            while (ob_get_level() > $level) {
                $chunk = ob_get_clean();
                if (is_string($chunk) && $chunk !== '') {
                    $output = $chunk . $output;
                }
            }
            self::$depth--;
            if ($oldLimit !== null) {
                @set_time_limit($oldLimit);
            }
        }

        $result = new CallResult(
            $exitCode === 0,
            $output,
            $exitCode,
            $error === null ? null : $error->getMessage(),
            microtime(true) - $start,
            $stopped,
        );

        if ($error !== null && $opt->throwOnError) {
            throw $error;
        }

        return $result;
    }

    /**
     * 当前嵌套深度（排障用）
     *
     * @return int
     */
    public static function depth(): int
    {
        return self::$depth;
    }
}
