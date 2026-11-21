<?php
/***
 * DEBUG 模式安全提醒
 *
 * 站点以 DEBUG 模式运行时，在 HTML 页面顶部注入提示条：
 *   「当前站点运行在 DEBUG 模式，对外访问会暴露调试信息」
 *   两个动作：关闭 DEBUG（写 Sys.inc.php）/ 静默一天（cookie）
 *
 * 设计要点：
 *   ① 只注入 HTML（Respond::filter 的 types=['html']），JSON/API 不受影响
 *   ② 仅「非内网访问」提醒：本地开发天天 DEBUG，不应该每次刷新都打扰；
 *      换环境（公网 / 新机器）才是真正危险的场景
 *   ③ 静默 cookie 由端点 /sys/debug?do=ack 写入，一天上限由服务端控制
 *   ④ 这是「人因提醒」，不是安全边界 —— cookie 可被伪造，真正的安全是生产关 DEBUG
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-21 21:05:00 $
 *
 * @package Candy\Extend\Security
 */
namespace Candy\Extend\Security;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class DebugNotice
{
    /**
     * 静默 cookie 名
     */
    public const COOKIE = 'candy_debug_ack';

    /**
     * 是否需要在当前请求注入提醒
     *
     * @var bool|null
     */
    private static ?bool $need = null;

    /**
     * 注册响应过滤器（由 Bootstrap::route() 在分发前调用）
     *
     * 注意：HookPipeline 对字符串回调只认「全局函数」（function_exists 判定），
     * 类方法字符串会被静默跳过，所以入口是 Candy/Func/security.func.php 里的
     * candyDebugNoticeInject()。
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::need() === false) {
            return;
        }
        // 只处理 HTML 页面：types=['html'] 时 JSON/XML 输出会被 HookPipeline 自动跳过
        \Candy\Core\Respond::filter('candyDebugNoticeInject', ['html'], 100);
        self::log();
    }

    /**
     * 响应过滤器：在 </body> 前插入提示条
     *
     * @param string $html 原始响应
     * @param array $context 响应上下文
     * @return string
     */
    public static function inject(string $html, array $context = []): string
    {
        if (stripos($html, '</body>') === false) {
            return $html;   // 片段/非完整页面，不注入
        }
        if (strpos($html, 'candy-debug-notice') !== false) {
            return $html;   // 已注入过（防重复）
        }
        $pos = strripos($html, '</body>');
        return substr($html, 0, $pos) . self::markup() . substr($html, $pos);
    }

    /**
     * 静默时长的秒数（来自配置）
     *
     * @return int
     */
    public static function silentSeconds(): int
    {
        $hours = (int) (self::config()['silent_hours'] ?? 24);
        if ($hours < 1) {
            $hours = 24;
        }
        return $hours * 3600;
    }

    /**
     * 是否需要提醒（三条同时满足）
     *
     * @return bool
     */
    private static function need(): bool
    {
        if (self::$need !== null) {
            return self::$need;
        }
        $cfg = self::config();
        $ok  = true;

        if (isDebug() === false) {
            $ok = false;
        } elseif (empty($cfg['enable'])) {
            $ok = false;
        } elseif (self::acknowledged()) {
            $ok = false;
        } elseif (!empty($cfg['skip_intranet']) && self::isIntranet()) {
            $ok = false;
        }

        self::$need = $ok;
        return $ok;
    }

    /**
     * 是否已被静默（cookie 未过期）
     *
     * @return bool
     */
    private static function acknowledged(): bool
    {
        $v = (string) ($_COOKIE[self::COOKIE] ?? '');
        return $v !== '' && (int) $v > time();
    }

    /**
     * 当前请求是否来自内网 / 本机
     *
     * @return bool
     */
    private static function isIntranet(): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return false;
        }
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        return \Candy\Extend\Ip::isPrivate($ip);
    }

    /**
     * 读配置（框架默认 + 项目覆盖）
     *
     * @return array
     */
    private static function config(): array
    {
        $defaults = [
            'enable'        => true,
            'silent_hours'  => 24,
            'skip_intranet' => true,
            'block'         => false,
            'log'           => true,
        ];

        $base = [];
        $fw   = CANDYPATH . 'Candy/Class/Tools/Inc/Sys.inc.php';
        if (is_file($fw)) {
            $base = (array) include $fw;
        }

        $cfg = $defaults;
        if (isset($base['debug_notice']) && is_array($base['debug_notice'])) {
            $cfg = array_merge($cfg, $base['debug_notice']);
        }

        $pj = C('INCPATH') . 'Sys.inc.php';
        if (is_file($pj)) {
            $over = (array) include $pj;
            if (isset($over['debug_notice']) && is_array($over['debug_notice'])) {
                $cfg = array_merge($cfg, $over['debug_notice']);
            }
        }

        return $cfg;
    }

    /**
     * 记一条访问日志（便于事后追溯谁在 DEBUG 下访问过）
     *
     * @return void
     */
    private static function log(): void
    {
        $cfg = self::config();
        if (empty($cfg['log'])) {
            return;
        }
        $dir = C('RUNTIME') . 'Logs';
        if (is_dir($dir) === false) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] ip=%s ua=%s uri=%s\n",
            date('Y-m-d H:i:s'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 120),
            substr((string) ($_SERVER['REQUEST_URI'] ?? '-'), 0, 200)
        );
        @file_put_contents($dir . '/security-debug-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 提示条 HTML + 极简 JS
     *
     * @return string
     */
    private static function markup(): string
    {
        $hours = (int) (self::config()['silent_hours'] ?? 24);
        return <<<HTML
<div id="candy-debug-notice" style="position:fixed;top:0;left:0;right:0;z-index:2147483600;background:#7f1d1d;color:#fff;font:14px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:8px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.25)">
  <span style="flex:1">⚠ 当前站点运行在 <b>DEBUG 模式</b>，对外访问会暴露调试信息（报错细节、SQL、文件路径）。</span>
  <button type="button" onclick="CandyDebugOff()" style="cursor:pointer;border:0;border-radius:4px;padding:5px 12px;background:#fff;color:#7f1d1d;font-weight:600">关闭 DEBUG</button>
  <button type="button" onclick="CandyDebugAck()" style="cursor:pointer;border:1px solid rgba(255,255,255,.6);border-radius:4px;padding:5px 12px;background:transparent;color:#fff">静默 {$hours} 小时</button>
  <button type="button" onclick="document.getElementById('candy-debug-notice').remove()" title="仅收起本页提示" style="cursor:pointer;border:0;background:transparent;color:rgba(255,255,255,.7);font-size:18px;line-height:1">×</button>
</div>
<script>
function CandyDebugAck(){
  fetch('/sys/debug?do=ack',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
    if(j.code===0){var el=document.getElementById('candy-debug-notice');if(el){el.remove();}}
    else{console.warn('[candy] '+j.message);}
  }).catch(function(e){console.warn('[candy] '+e);});
}
function CandyDebugOff(){
  fetch('/sys/debug?do=off',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
    if(j.code===0){location.reload();}
    else{alert(j.message||'关闭失败');}
  }).catch(function(e){alert('关闭失败：'+e);});
}
</script>
HTML;
    }
}
