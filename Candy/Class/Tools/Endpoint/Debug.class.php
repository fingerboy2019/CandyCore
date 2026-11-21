<?php
/***
 * 框架端点：DEBUG 模式提醒的两个动作
 *
 *   GET /sys/debug?do=ack  → 静默（写 candy_debug_ack cookie，时长按 Sys.debug_notice.silent_hours）
 *   GET /sys/debug?do=off  → 关闭 DEBUG（写 Sys.inc.php 的 runtime.debug=false，需 token）
 *   GET /sys/debug?do=status → 当前状态（调试用）
 *
 * 说明：ack 免 token —— 它只是"本浏览器不再提示"，不改变服务器行为；
 *      off 需要 token —— 它改的是站点配置，属于管理动作。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-21 21:05:00 $
 *
 * @package Candy\Extend\Tools\Endpoint
 */
namespace Candy\Extend\Tools\Endpoint;

use \Candy\Extend\Security\DebugNotice;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Debug
{
    /**
     * 入口
     *
     * @return string JSON
     */
    public static function handle(): string
    {
        $do = strtolower(trim((string) ($_GET['do'] ?? 'status')));

        if ($do === 'ack') {
            return self::ack();
        }
        if ($do === 'off') {
            return self::off();
        }
        return self::status();
    }

    /**
     * 静默：写 cookie（一天上限由服务端控制）
     *
     * @return string JSON
     */
    private static function ack(): string
    {
        $seconds = DebugNotice::silentSeconds();
        $until   = time() + $seconds;

        cookie(DebugNotice::COOKIE, (string) $until, $seconds, '/', '', [
            'samesite' => 'Lax',
        ]);

        return self::json(0, 'ok', [
            'silent_seconds' => $seconds,
            'silent_until'   => date('Y-m-d H:i:s', $until),
        ]);
    }

    /**
     * 关闭 DEBUG：写项目 Sys.inc.php 的 runtime.debug=false（需 token）
     *
     * @return string JSON
     */
    private static function off(): string
    {
        // off 改配置，按 sys 端点规则要求 token
        $expect = Registry::token('sys/debug');
        if ($expect !== '' && hash_equals($expect, self::token()) === false) {
            return self::json(403, 'token 不正确：请带 ?token=…，或先到 /sys/url 保存令牌');
        }

        $r = saveInc('Sys', ['runtime' => ['debug' => false]]);
        if (empty($r['ok'])) {
            return self::json(500, '写入失败：' . (string) ($r['message'] ?? '未知原因'));
        }

        // 清字节码缓存，让下一次请求立刻读到新值
        $pj = C('INCPATH') . 'Sys.inc.php';
        if (is_file($pj) && function_exists('opcache_invalidate')) {
            @opcache_invalidate($pj, true);
        }

        return self::json(0, 'DEBUG 已关闭，刷新后生效', [
            'file' => 'Source/Inc/Sys.inc.php',
        ]);
    }

    /**
     * 状态（调试用）
     *
     * @return string JSON
     */
    private static function status(): string
    {
        return self::json(0, 'ok', [
            'debug'          => isDebug(),
            'silent_seconds' => DebugNotice::silentSeconds(),
            'acked'          => (string) ($_COOKIE[DebugNotice::COOKIE] ?? ''),
        ]);
    }

    /**
     * 取请求令牌（?token= → Authorization: Bearer → cookie）
     *
     * @return string
     */
    private static function token(): string
    {
        $got = trim((string) ($_GET['token'] ?? ''));
        if ($got !== '') {
            return $got;
        }
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return trim((string) ($_COOKIE['candy_endpoint_token'] ?? ''));
    }

    /**
     * 输出 JSON
     *
     * @param int $code 业务码
     * @param string $message 提示
     * @param array $data 数据
     * @return string
     */
    private static function json(int $code, string $message, array $data = []): string
    {
        return (string) json_encode([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
