<?php
/***
 * 框架内置端点：令牌输入页（独立页面，只干一件事）
 *
 * 使用流程：
 *   点击受保护的端点（如 /sys/crontab）
 *     → Guard 发现没令牌、且是浏览器访问
 *     → 302 跳到 /sys/auth?return=<原地址>
 *     → 输入令牌，JS 写 cookie 后自动跳回 <原地址>
 *     → 令牌错 → 再次跳到本页并带 err=1，提示重试
 *   已经验证过的话，直接 302 回目标页，不显示表单。
 *
 * 本页是公开的（不调 Guard）；它只保存令牌、不返回任何业务数据。
 * 与 `/sys/url` 的分工：`/sys/url` 是清单，本页是「凭证入口」。
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 12:45:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Auth
{
    /** 判断「是否已认证」用的参照端点 */
    private const PROBE = 'sys/crontab';

    /**
     * 入口
     *
     * @return string HTML 或空串（302 时已被 stop 中断）
     */
    public static function handle(): string
    {
        $back = self::target();
        $err = isset($_GET['err']);

        // 已认证：直接回目标页
        if (Guard::check(self::PROBE)) {
            if (function_exists('headers')) {
                headers('Location: ' . $back, 302);
            }
            if (function_exists('stop')) {
                stop();
            }
            exit;
        }

        return self::render($back, $err);
    }

    /**
     * 规整回跳地址：只允许站内绝对路径，防开放重定向
     *
     * @return string
     */
    protected static function target(): string
    {
        $back = trim((string) ($_GET['return'] ?? ''));
        if ($back === '' || $back[0] !== '/') {
            return '/sys/url?view=html';
        }
        if (str_starts_with($back, '//') || str_contains($back, "\n") || str_contains($back, "\r")) {
            return '/sys/url?view=html';
        }
        return $back;
    }

    /**
     * 渲染输入页
     *
     * @param string $back 回跳地址
     * @param bool   $err  是否是「刚输错过」
     * @return string
     */
    protected static function render(string $back, bool $err): string
    {
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // 令牌提示：未自定义时直接把默认值显示出来，并提醒尽快改掉
        $info = Registry::tokenInfo();
        if ($info['is_default']) {
            $tokInfo = '默认令牌：<code>' . $esc((string) $info['value']) . '</code>'
                . ' · <b style="color:#b45309">记得修改</b>（/sys/config → Sys → security.token）';
        } else {
            $tokInfo = '令牌已自定义 · 修改：/sys/config → Sys → security.token';
        }

        $notice = $err
            ? '<div class="err">令牌不正确，请重新输入。</div>'
            : '';

        $tpl = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>需要令牌 · 框架端点</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
 background:#f1f5f9;color:#0f172a;padding:24px;
 font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.card{width:100%;max-width:460px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;
 padding:28px 26px 24px;box-shadow:0 8px 28px rgba(15,23,42,.08)}
h1{margin:0 0 6px;font-size:18px;font-weight:650}
.sub{color:#64748b;font-size:12.5px;margin-bottom:16px}
.sub code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:11.5px}
.target{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 11px;
 font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;
 color:#1d4ed8;word-break:break-all;margin-bottom:16px}
input[type=password]{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:9px;
 font-size:13px;outline:none;background:#fff;
 font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.4px}
input[type=password]:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
button{width:100%;margin-top:12px;padding:10px 14px;border:1px solid #2563eb;background:#2563eb;
 color:#fff;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer}
button:hover{background:#1d4ed8;border-color:#1d4ed8}
.err{margin-bottom:14px;padding:9px 12px;background:#fef2f2;border:1px solid #fecaca;
 border-radius:8px;color:#b91c1c;font-size:12.5px}
.tip{margin-top:14px;padding-top:12px;border-top:1px dashed #e2e8f0;color:#64748b;font-size:11.5px;line-height:1.8}
.tip code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-family:ui-monospace,Menlo,Consolas,monospace}
</style>
</head>
<body>
<div class="card">
  <h1>需要令牌</h1>
  <div class="sub">这个端点需要访问令牌。输入一次后保存在 cookie 里，<b>不会出现在 URL 或浏览历史中</b>；验证通过会自动跳回原页面。</div>
  __NOTICE__
  <div class="target">__BACK__</div>
  <input type="password" id="tok" placeholder="粘贴端点令牌" autocomplete="off" spellcheck="false" autofocus>
  <button type="button" id="go">验证并跳回</button>
  <div class="tip">
    __TOKINFO__<br>
    前后空格会自动忽略。
  </div>
</div>
<script>
(function(){
  var C='__COOKIE__',BACK=__BACKJS__;
  var inp=document.getElementById('tok'),btn=document.getElementById('go');
  function go(){
    var v=(inp.value||'').trim();
    if(!v){inp.focus();return;}
    document.cookie=C+'='+encodeURIComponent(v)+';path=/;max-age=86400;SameSite=Lax';
    location.href=BACK;
  }
  btn.addEventListener('click',go);
  inp.addEventListener('keydown',function(e){if(e.key==='Enter'){go();}});
})();
</script>
</body>
</html>
HTML;

        return str_replace(
            ['__NOTICE__', '__BACK__', '__TOKINFO__', '__COOKIE__', '__BACKJS__'],
            [
                $notice,
                $esc($back),
                $tokInfo,
                $esc(Guard::COOKIE),
                (string) json_encode($back, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            $tpl
        );
    }
}
