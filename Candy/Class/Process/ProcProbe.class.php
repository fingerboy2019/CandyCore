<?php
namespace APP\Control\Home;

/**
 * 临时探针：验证 Candy\Extend\Process 四形态执行器 + Gate 开关（用完即删）
 */
class ProcProbe
{
    #[Annotation(title: '进程探针', auth: false, menu: false)]
    function index()
    {
        $out = ['classes' => [], 'caps' => [], 'tests' => []];
        $root = 'Candy\\Extend\\Process\\';
        $names = [
            'PhpBinary', 'ProcessInfo', 'Gate', 'Runner', 'RunOptions', 'RunResult',
            'SpawnOptions', 'SpawnResult', 'Forker', 'ForkOptions', 'ForkResult',
            'Caller', 'CallOptions', 'CallResult',
            'Exception\\ProcessException', 'Exception\\ProcessTimeoutException',
            'Exception\\ProcessUnavailableException',
        ];
        foreach ($names as $n) {
            $out['classes'][$n] = class_exists($root . $n);
        }

        $g = 'Candy\\Extend\\Process\\Gate';
        $r = 'Candy\\Extend\\Process\\Runner';
        $f = 'Candy\\Extend\\Process\\Forker';
        $c = 'Candy\\Extend\\Process\\Caller';
        $pb = 'Candy\\Extend\\Process\\PhpBinary';

        $out['caps']['php'] = $pb::info();
        $out['caps']['proc_open_available'] = $r::available();
        $out['caps']['proc_open_reason'] = $r::unavailableReason();
        $out['caps']['forker_can'] = $f::can();
        $out['caps']['forker_reason'] = $f::unavailableReason();
        $out['caps']['forker_safe_context'] = $f::isSafeContext();
        $out['caps']['sapi'] = PHP_SAPI;
        $out['caps']['open_basedir'] = (string) ini_get('open_basedir');

        // ---- 开关：默认必须是关闭 ----
        $g::reset();
        $out['tests']['gate_default'] = ['enabled' => $g::enabled(), 'source' => $g::source()];
        try {
            $r::run(['true']);
            $out['tests']['gate_block'] = ['blocked' => false];
        } catch (\Throwable $e) {
            $out['tests']['gate_block'] = [
                'blocked' => true,
                'type' => get_class($e),
                'mode' => method_exists($e, 'getMode') ? $e->getMode() : '',
                'msg' => mb_substr($e->getMessage(), 0, 70),
            ];
        }

        // ---- 打开开关后跑功能测试 ----
        putenv('CANDY_PROCESS=1');
        $g::reset();
        $out['tests']['gate_after_env'] = ['enabled' => $g::enabled(), 'source' => $g::source()];

        // 测试1：同步执行 + 大量 stderr（验管道不死锁）
        try {
            $res = $r::run(
                [$pb::resolve(), '-r', 'fwrite(STDERR, str_repeat("x", 200000)); echo "OK";'],
                new \Candy\Extend\Process\RunOptions(timeout: 20)
            );
            $out['tests']['sync_bigstderr'] = [
                'ok' => $res->ok(),
                'stdout' => $res->stdout,
                'stderr_len' => strlen($res->stderr),
                'duration' => round($res->duration, 3),
            ];
        } catch (\Throwable $e) {
            $out['tests']['sync_bigstderr'] = ['error' => $e->getMessage()];
        }

        // 测试2：超时强杀
        try {
            $res = $r::run(['sleep', '10'], new \Candy\Extend\Process\RunOptions(timeout: 2, throwOnTimeout: false));
            $out['tests']['sync_timeout'] = [
                'timedOut' => $res->timedOut,
                'duration' => round($res->duration, 2),
            ];
        } catch (\Throwable $e) {
            $out['tests']['sync_timeout'] = ['error' => $e->getMessage()];
        }

        // 测试3：后台 + 探活
        try {
            $s = $r::spawn(['sleep', '5'], new \Candy\Extend\Process\SpawnOptions(marker: 'procprobe'));
            $info = $r::alive($s->pid);
            $out['tests']['spawn'] = [
                'pid' => $s->pid,
                'alive' => $info->running,
                'log_exists' => is_file($s->logPath),
            ];
            if ($s->pid > 0) {
                $r::kill($s->pid);
                usleep(200000);
                $out['tests']['spawn']['after_kill_alive'] = $r::alive($s->pid)->running;
            }
        } catch (\Throwable $e) {
            $out['tests']['spawn'] = ['error' => $e->getMessage()];
        }

        // 测试4：同进程 call（不受开关限制）
        try {
            $cr = $c::invoke(function () {
                echo 'captured-';
                return 7;
            });
            $out['tests']['call_invoke'] = ['ok' => $cr->ok, 'output' => $cr->output, 'exit' => $cr->exitCode];
        } catch (\Throwable $e) {
            $out['tests']['call_invoke'] = ['error' => $e->getMessage()];
        }

        // 测试5：白名单
        try {
            $c::allow(['env']);
            $c::call('forbidden-cmd');
            $out['tests']['whitelist'] = ['blocked' => false];
        } catch (\Throwable $e) {
            $out['tests']['whitelist'] = ['blocked' => true];
        }

        // 测试6：fork 降级
        try {
            $fr = $f::run(function () {
                return 'forked';
            });
            $out['tests']['fork'] = ['degraded' => $fr->degraded, 'mode' => $fr->mode, 'return' => $fr->return];
        } catch (\Throwable $e) {
            $out['tests']['fork'] = ['error' => $e->getMessage()];
        }

        $this->assign('probe', $out);
        $this->display();
    }
}
