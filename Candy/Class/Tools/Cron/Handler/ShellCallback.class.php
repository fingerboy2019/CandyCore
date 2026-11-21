<?php
/***
 * 框架内置任务处理器：执行 shell 命令（**默认关闭，需显式开启**）
 *
 * ⚠️ 这是「网页上填一条命令，服务器就执行」的能力，风险等级最高，所以：
 *   ① 默认**关闭**，代码里写死关闭，必须改配置才生效（见 Source/Inc/CrontabScheduler.inc.php）
 *   ② 支持命令**前缀白名单**：只允许以白名单前缀开头的命令，其余直接拒绝
 *   ③ 有超时上限，超时先 SIGTERM 再 SIGKILL，不留下挂死的子进程
 *   ④ 输出截断后入库，不把整坨日志塞进 GlobalData
 *
 * 与 `Candy\Extend\Process` 的关系：
 *   真正起进程用 `Process\Runner::run()`，它自身还受 `PROCESS_ENABLE` 开关约束。
 *   两个开关是不同层面的：
 *     - `CrontabScheduler.inc.php → shell.enable`  决定「计划任务里能不能用 shell 类型」
 *     - `System.inc.php → PROCESS_ENABLE`          决定「框架能不能起子进程」
 *   两个都开才能跑。缺哪个，报错里会直接写清楚缺的是哪一个、去哪儿开。
 *
 * params：
 *   command   必填，要执行的命令（经由 /bin/sh -c 执行，支持管道与重定向）
 *   cwd       工作目录（默认继承当前进程，即项目根）
 *   timeout   超时秒数（默认取配置 shell.default_timeout，再被 shell.max_timeout 封顶）
 *   env       额外环境变量（关联数组，继承 + 覆盖）
 *
 * @package Candy\Extend\Tools\Cron\Handler
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-20 11:35:00
 */
namespace Candy\Extend\Tools\Cron\Handler;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ShellCallback
{
    /** handler 标识（任务里写 handler: 'shell_callback'） */
    public const HANDLER_ID = 'shell_callback';

    /** 展示名 */
    public const HANDLER_TITLE = '执行命令（需显式开启）';

    /** 说明 */
    public const HANDLER_DESC = '在服务器上执行 shell 命令；默认关闭，需在配置里开启并建议配置命令前缀白名单';

    /** 输出尾部保留的行数（写进 last_msg 的部分） */
    public const TAIL_LINES = 20;

    /**
     * 执行
     *
     * @param array<string, mixed> $params 参数
     * @return array{code:int, message:string, duration_ms:float, exit_code:int}
     */
    public function run(array $params = []): array
    {
        $cfg = self::config();

        // ① 开关：默认关闭（配置缺失 / 读不到，一律视为关闭）
        if (empty($cfg['enable'])) {
            return self::fail(
                'shell 任务未启用。开启方式：打开 Source/Inc/CrontabScheduler.inc.php，'
                . "把 'shell' => ['enable' => false] 改成 true（建议同时配置 allow_prefix 白名单）"
            );
        }

        // ② 命令
        $command = trim((string) ($params['command'] ?? ''));
        if ($command === '') {
            return self::fail('command 必填');
        }

        // ③ 前缀白名单：配了就必须命中，没配则放行全部（但开关本身已经是一道闸）
        $allow = (array) ($cfg['allow_prefix'] ?? []);
        $allow = array_values(array_filter(array_map('strval', $allow), static fn ($v) => trim($v) !== ''));
        if ($allow !== [] && !self::matchPrefix($command, $allow)) {
            return self::fail('命令不在允许的前缀内，已拒绝执行。允许的前缀：' . implode(' / ', $allow));
        }

        // ④ 超时：请求值 → 默认值 → 上限封顶，且绝不允许 0（0 在 Runner 里表示不限制）
        $maxTimeout = max(1, (int) ($cfg['max_timeout'] ?? 300));
        $default = max(1, (int) ($cfg['default_timeout'] ?? 60));
        $timeout = (int) ($params['timeout'] ?? 0);
        if ($timeout <= 0) {
            $timeout = $default;
        }
        $timeout = min($maxTimeout, $timeout);

        // ⑤ 工作目录
        $cwd = trim((string) ($params['cwd'] ?? ''));
        if ($cwd !== '' && !is_dir($cwd)) {
            return self::fail('工作目录不存在：' . $cwd);
        }

        // ⑥ 环境变量
        $env = $params['env'] ?? null;
        $env = is_array($env) ? $env : null;

        $runnerClass = '\Candy\Extend\Process\Runner';
        $optClass = '\Candy\Extend\Process\RunOptions';
        if (!class_exists($runnerClass) || !class_exists($optClass)) {
            return self::fail('缺少 Candy\Extend\Process\Runner，无法执行命令');
        }

        $start = microtime(true);
        try {
            $opt = new $optClass(
                timeout: $timeout,
                cwd: $cwd === '' ? null : $cwd,
                env: $env,
                maxBytes: 65536,
                graceSeconds: 1.0,
                throwOnTimeout: false,
            );
            // 数组形式的 proc_open 不经过 shell；要支持管道/重定向，必须显式套一层 sh -c
            $result = $runnerClass::run(['/bin/sh', '-c', $command], $opt);
        } catch (\Throwable $e) {
            return self::fail('执行失败：' . $e->getMessage() . self::gateHint());
        }

        $durationMs = round((microtime(true) - $start) * 1000, 1);
        $tail = trim((string) $result->tail(self::TAIL_LINES));

        if ($result->timedOut) {
            return [
                'code'        => 1,
                'message'     => '执行超时（' . $timeout . ' 秒）已被终止' . ($tail !== '' ? '：' . $tail : ''),
                'duration_ms' => $durationMs,
                'exit_code'   => (int) $result->exitCode,
            ];
        }

        if ($result->ok()) {
            return [
                'code'        => 0,
                'message'     => '执行成功（' . $durationMs . 'ms）' . ($tail !== '' ? '：' . $tail : ''),
                'duration_ms' => $durationMs,
                'exit_code'   => 0,
            ];
        }

        return [
            'code'        => 1,
            'message'     => '执行失败，退出码 ' . $result->exitCode . ($tail !== '' ? '：' . $tail : ''),
            'duration_ms' => $durationMs,
            'exit_code'   => (int) $result->exitCode,
        ];
    }

    /**
     * 读配置（框架默认 + 项目覆盖，走 incMergeDefaults 的深合并）
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $shell = [];
        try {
            if (function_exists('loadConfig')) {
                $all = loadConfig('CrontabScheduler');
                if (is_array($all) && isset($all['shell']) && is_array($all['shell'])) {
                    $shell = $all['shell'];
                }
            }
        } catch (\Throwable $e) {
            $shell = [];
        }
        return $shell;
    }

    /**
     * 是否已开启（供页面/诊断判断，不触发执行）
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        $cfg = self::config();
        return !empty($cfg['enable']);
    }

    /**
     * 命令是否命中前缀白名单
     *
     * @param string             $command 命令
     * @param array<int, string> $allow   白名单前缀
     * @return bool
     */
    protected static function matchPrefix(string $command, array $allow): bool
    {
        $command = ltrim($command);
        foreach ($allow as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && strncmp($command, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 统一的失败返回
     *
     * @param string $message 说明
     * @return array{code:int, message:string, duration_ms:float, exit_code:int}
     */
    protected static function fail(string $message): array
    {
        return ['code' => 1, 'message' => $message, 'duration_ms' => 0.0, 'exit_code' => -1];
    }

    /**
     * 进程能力未开启时的补充提示（把「去哪儿开」直接写进报错）
     *
     * @return string
     */
    protected static function gateHint(): string
    {
        $gate = '\Candy\Extend\Process\Gate';
        if (!class_exists($gate)) {
            return '';
        }
        try {
            if ($gate::enabled()) {
                return '';
            }
            return '（当前 PROCESS_ENABLE 未开启：请在 Source/Inc/System.inc.php 去掉 doDefine(\'PROCESS_ENABLE\', true); 的注释）';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
