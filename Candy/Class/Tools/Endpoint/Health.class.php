<?php
/***
 * 框架内置端点：健康检查
 *
 * 业务说明：供监控 / 负载均衡探测服务可用性。
 * 由 Registry 注册到路径 health，经 RouteContextInit::dispatchRegisteredHttpRoute 派发
 * （该派发器会自动 Respond::create()->send() + stop()，因此这里只需返回字符串）。
 *
 * 返回 JSON 字符串即按 json 输出；返回非 JSON 即按 html 输出。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-13 17:30:00 $
 *
 * @package Candy\Extend\Tools\Endpoint
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Health
{
    /**
     * 执行健康检查
     *
     * 配置见 Source/Inc/Health.inc.php；未配置或 checks 为空时只回报存活。
     *
     * @return string JSON
     */
    public static function check(): string
    {
        // 入口鉴权：配置了 token 时要求 ?token=xxx 或 Authorization: Bearer xxx
        Registry::authorize('sys/health');

        $cfg = self::config();
        $want = (array) ($cfg['checks'] ?? ['db', 'disk']);

        $checks = [];
        $healthy = true;

        if (in_array('db', $want, true)) {
            // 先判断数据库是否已配置：未初始化 / 未填 DB.default 时，D() 会走
            // showExceptionMsg('shutdown') 直接终止请求（不是抛异常），后续 try/catch 拦不住。
            // 因此这里必须先判配置，不能靠 catch 兜底。
            $state = self::dbState();
            if ($state['ok'] === false) {
                $checks['db'] = [
                    'ok'      => false,
                    'skipped' => true,
                    'error'   => $state['reason'],
                ];
                $healthy = false;
            } else {
                $t = microtime(true);
                try {
                    // 用最轻量的查询验证连接可用
                    D()->query('SELECT 1');
                    $checks['db'] = ['ok' => true, 'ms' => round((microtime(true) - $t) * 1000, 2)];
                } catch (\Throwable $e) {
                    $checks['db'] = [
                        'ok'    => false,
                        'ms'    => round((microtime(true) - $t) * 1000, 2),
                        'error' => self::short($e->getMessage()),
                    ];
                    $healthy = false;
                }
            }
        }

        if (in_array('disk', $want, true)) {
            $minGb = (float) ($cfg['disk_min_gb'] ?? 1);
            // 用常量而不是 C()：CANDYROOT 在框架引导阶段就是确定值，C() 此时可能还未装载
            $free = @disk_free_space(defined('CANDYROOT') ? CANDYROOT : dirname(__DIR__, 4) . '/');
            if ($free === false) {
                $checks['disk'] = ['ok' => false, 'free_gb' => null];
                $healthy = false;
            } else {
                $freeGb = round($free / 1073741824, 2);
                $checks['disk'] = ['ok' => $minGb <= 0 || $freeGb >= $minGb, 'free_gb' => $freeGb];
                $healthy = $healthy && $checks['disk']['ok'];
            }
        }

        if (in_array('memory', $want, true)) {
            $maxMb = (int) ($cfg['memory_max_mb'] ?? 0);
            $usedMb = round(memory_get_usage(true) / 1048576, 2);
            $checks['memory'] = ['ok' => $maxMb <= 0 || $usedMb <= $maxMb, 'used_mb' => $usedMb];
            $healthy = $healthy && $checks['memory']['ok'];
        }

        return (string) json_encode([
            'status' => $healthy ? 'ok' : 'fail',
            // 端点是在「应用识别之前」派发的，此时 C('APP')/C('APPNAME') 均为 null，
            // 所以不输出 app 字段（取不到就是取不到，留空串反而误导）。改报运行环境。
            'sapi'   => PHP_SAPI,
            'time'   => date('c'),
            'checks' => $checks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 数据库是否已配置
     *
     * 未初始化（没有 Config.inc.php）或 DB.default 的 HOST / DBNAME 为空时，
     * 视为「未配置」——此时不应调用 D()，否则会直接终止请求。
     *
     * @return array{ok:bool,reason:string}
     */
    protected static function dbState(): array
    {
        // 不能读 C('DB')：sys/* 端点是在应用配置装载之前派发的，此时 C('DB') 恒为空，
        // 会把「已配置的站点」误判成未配置。所以直接读配置文件判断有没有填。
        $file = C('INCPATH') . 'Config.inc.php';
        if (!is_file($file)) {
            return ['ok' => false, 'reason' => '未初始化：缺少 Source/Inc/Config.inc.php'];
        }

        $cfg = include $file;
        if (is_array($cfg) === false) {
            return ['ok' => false, 'reason' => '配置格式异常：Source/Inc/Config.inc.php 未返回数组'];
        }

        $conf   = (isset($cfg['DB']['default']) && is_array($cfg['DB']['default'])) ? $cfg['DB']['default'] : [];
        $host   = trim((string) ($conf['HOST'] ?? ''));
        $dbname = trim((string) ($conf['DBNAME'] ?? ''));

        // SQLite 之类文件型数据库没有 HOST，只要求库名（文件路径）非空
        $fileDb = str_contains(strtolower((string) ($cfg['DRIVER'] ?? '')), 'sqlite');

        if ($dbname === '' || ($fileDb === false && $host === '')) {
            return ['ok' => false, 'reason' => '未配置数据库：Source/Inc/Config.inc.php → DB.default 的 HOST / DBNAME 为空'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * 截断长错误信息
     *
     * mbstring 不是框架的必装扩展，缺省时退回 substr，避免在 catch 里二次致命。
     *
     * @param string $text 原文
     * @param int $len 保留长度
     * @return string
     */
    protected static function short(string $text, int $len = 200): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len);
    }

    /**
     * 读配置（Source/Inc/Health.inc.php）
     *
     * @return array
     */
    protected static function config(): array
    {
        $file = C('SOURCEPATH') . 'Inc/Health.inc.php';
        if (!is_file($file)) {
            return [];
        }
        $cfg = include $file;
        return is_array($cfg) ? $cfg : [];
    }
}
