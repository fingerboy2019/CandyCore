<?php
/***
 * 框架端点：命令行控制台（/sys/cli）
 *
 *   /sys/cli                 页面（命令输入 + 快捷按钮 + 输出区）
 *   /sys/cli?do=exec&cmd=…   执行一条 candy 命令，返回 JSON
 *   /sys/cli?do=cmds         命令清单 JSON
 *
 * 鉴权与其它 sys 端点一致：token（?token= / Bearer / cookie candy_endpoint_token）。
 * 危险命令另需 confirm=命令名，且仅 DEBUG 模式可用。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-22 08:00:00 $
 *
 * @package Candy\Extend\Tools\Endpoint
 */
namespace Candy\Extend\Tools\Endpoint;

use \Candy\Extend\Tools\Cli\Console;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Cli
{
    /**
     * 入口
     *
     * @return string HTML 或 JSON
     */
    public static function handle(): string
    {
        $do    = strtolower(trim((string) ($_GET['do'] ?? 'page')));
        $deny  = self::auth($do);
        if ($deny !== '') {
            return $deny;
        }

        if ($do === 'cmds') {
            return self::json(0, 'ok', Console::catalog());
        }

        if ($do === 'exec') {
            $cmd     = (string) ($_GET['cmd'] ?? '');
            $args    = (string) ($_GET['args'] ?? '');
            $confirm = (string) ($_GET['confirm'] ?? '');
            $res     = Console::run($cmd, $args, $confirm);

            return self::json(
                $res['ok'] ? 0 : (int) ($res['code'] >= 400 ? $res['code'] : 1),
                $res['ok'] ? 'ok' : 'failed',
                $res
            );
        }

        return self::page(Console::catalog());
    }

    /**
     * 鉴权
     *
     * @param string $do 动作
     * @return string 空串=通过；否则为拒绝响应
     */
    private static function auth(string $do): string
    {
        $expect = Registry::token('sys/cli');
        if ($expect === '') {
            return $do === 'exec' || $do === 'cmds'
                ? self::json(403, 'sys/cli 需要 token：请先在 Source/Inc/Endpoint.inc.php 或 Sys.inc.php 配置 token')
                : self::denied('未配置 token：请先配置 Source/Inc/Sys.inc.php 的 security.token');
        }

        $got = self::token();
        if ($got !== '' && hash_equals($expect, $got)) {
            return '';
        }

        return $do === 'exec' || $do === 'cmds'
            ? self::json(403, 'token 不正确')
            : self::denied('需要令牌：请输入后继续（会存到 cookie candy_endpoint_token）');
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
     * 令牌输入页（未认证时）
     *
     * @param string $msg 提示
     * @return string
     */
    private static function denied(string $msg): string
    {
        $msg = self::esc($msg);
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>需要令牌 · 命令控制台</title>
<style>body{margin:0;font:14px/1.7 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}
.box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:26px;max-width:420px;width:90%}
h1{font-size:17px;margin:0 0 6px}p{color:#94a3b8;font-size:13px;margin:0 0 16px}
input{width:100%;background:#0f172a;border:1px solid #334155;color:#e2e8f0;border-radius:6px;padding:10px 12px;font-size:14px;outline:none}
input:focus{border-color:#3b82f6}button{margin-top:12px;width:100%;background:#2563eb;border:0;color:#fff;border-radius:6px;padding:10px;font-size:14px;font-weight:600;cursor:pointer}
.hint{margin-top:12px;color:#64748b;font-size:12px}</style></head>
<body><div class="box">
<h1>需要令牌</h1><p>{$msg}</p>
<input id="tk" type="password" placeholder="粘贴令牌（如 candy-sys）" autocomplete="off">
<button onclick="save()">保存并进入</button>
<div class="hint">令牌会写入 cookie <code>candy_endpoint_token</code>（path=/，一天）。也可先去 <a href="/sys/url" style="color:#93c5fd">/sys/url</a> 保存。</div>
</div>
<script>function save(){var v=document.getElementById('tk').value.trim();if(!v){return;}document.cookie='candy_endpoint_token='+encodeURIComponent(v)+';path=/;max-age=86400;SameSite=Lax';location.reload();}</script>
</body></html>
HTML;
    }

    /**
     * 页面
     *
     * @param array $catalog 命令清单
     * @return string
     */
    private static function page(array $catalog): string
    {
        $safe   = (array) ($catalog['safe'] ?? []);
        $danger = (array) ($catalog['danger'] ?? []);
        $debug  = isDebug() ? '1' : '0';

        $quick = ['doctor', 'version', 'migrate:status', 'seed:status', 'queue:status', 'schedule:list'];
        $btns  = '';
        foreach ($quick as $q) {
            if (in_array($q, $safe, true)) {
                $btns .= '<button type="button" class="q" data-cmd="' . self::esc($q) . '">' . self::esc($q) . '</button>';
            }
        }

        $dl = '';
        foreach (array_merge($safe, $danger) as $c) {
            $dl .= '<option value="' . self::esc($c) . '">';
        }

        $dangerJs = json_encode(array_values($danger), JSON_UNESCAPED_SLASHES);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>命令控制台 · Candy</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:14px/1.65 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'PingFang SC',sans-serif;background:#0f172a;color:#e2e8f0}
.wrap{max-width:1080px;margin:0 auto;padding:22px 18px 48px}
h1{font-size:18px;margin:0 0 4px;font-weight:600}
.sub{color:#94a3b8;font-size:13px;margin-bottom:18px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px;margin-bottom:14px}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
input[type=text]{background:#0f172a;border:1px solid #334155;color:#e2e8f0;border-radius:6px;padding:9px 11px;font-size:14px;outline:none}
input[type=text]:focus{border-color:#3b82f6}
#cmd{width:240px}#args{flex:1;min-width:220px}
button{cursor:pointer;border:1px solid #334155;background:#334155;color:#e2e8f0;border-radius:6px;padding:8px 14px;font-size:13px}
button:hover{background:#475569}
button.primary{background:#2563eb;border-color:#2563eb;color:#fff;font-weight:600}
button.primary:hover{background:#1d4ed8}
button.q{background:#0f172a;border-color:#334155;color:#93c5fd;padding:5px 10px;font-size:12px}
.qbar{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
pre{background:#0b1220;border:1px solid #1e293b;border-radius:8px;padding:14px;margin:0;overflow:auto;max-height:520px;white-space:pre-wrap;word-break:break-word;font:12.5px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#cbd5e1}
.meta{display:flex;gap:14px;color:#94a3b8;font-size:12px;margin:10px 0 8px}
.hint{color:#94a3b8;font-size:12px;margin-top:8px}
</style>
</head>
<body>
<div class="wrap">
  <h1>命令控制台</h1>
  <div class="sub">执行 <code>php candy &lt;命令&gt;</code>；只读命令随时可用，危险命令需输入命令名确认（仅 DEBUG 模式）。当前 DEBUG=<b>{$debug}</b></div>

  <div class="card">
    <div class="row">
      <input id="cmd" type="text" list="cmds" placeholder="命令，如 doctor" autocomplete="off">
      <datalist id="cmds">{$dl}</datalist>
      <input id="args" type="text" placeholder="参数（可空），如 Admin Article">
      <button class="primary" id="run">执行</button>
      <button id="clearout">清屏</button>
    </div>
    <div class="qbar">{$btns}</div>
    <div class="hint">提示：危险命令会要求输入命令名本身作为确认；输出已去 ANSI 色码并截断到 64KB。</div>
  </div>

  <div class="card">
    <div class="meta"><span id="state">就绪</span><span id="cost"></span><span id="code"></span></div>
    <pre id="out">等待执行…</pre>
  </div>
</div>
<script>
var DANGER = {$dangerJs};
var $cmd=document.getElementById('cmd'),$args=document.getElementById('args'),
    $run=document.getElementById('run'),$out=document.getElementById('out'),
    $state=document.getElementById('state'),$cost=document.getElementById('cost'),$code=document.getElementById('code');

document.querySelectorAll('button.q').forEach(function(b){
  b.addEventListener('click',function(){ $cmd.value=b.dataset.cmd; $args.value=''; $run.click(); });
});
document.getElementById('clearout').addEventListener('click',function(){ $out.textContent=''; });
$args.addEventListener('keydown',function(e){ if(e.key==='Enter'){ $run.click(); } });
$cmd.addEventListener('keydown',function(e){ if(e.key==='Enter'){ $run.click(); } });

$run.addEventListener('click',async function(){
  var cmd=($cmd.value||'').trim();
  if(!cmd){ $out.textContent='请输入命令名'; return; }
  var confirm='';
  if(DANGER.indexOf(cmd)!==-1){
    confirm=window.prompt('危险命令「'+cmd+'」\n请输入命令名确认：','');
    if(confirm!==cmd){ $out.textContent='已取消（确认词不匹配）'; return; }
  }
  $state.textContent='执行中…'; $cost.textContent=''; $code.textContent=''; $out.textContent='';
  try{
    var url='/sys/cli?do=exec&cmd='+encodeURIComponent(cmd)
          +'&args='+encodeURIComponent(($args.value||'').trim())
          +'&confirm='+encodeURIComponent(confirm);
    var r=await fetch(url,{credentials:'same-origin'});
    var j=await r.json();
    var d=j.data||{};
    $state.textContent = (j.code===0?'完成':'失败');
    $cost.textContent = d.time?('耗时 '+d.time+' ms'):'';
    $code.textContent = 'exit='+(d.code===undefined?'-':d.code);
    $out.textContent = (d.output||j.message||'(无输出)');
  }catch(e){
    $state.textContent='请求失败'; $out.textContent=String(e);
  }
});
</script>
</body>
</html>
HTML;
    }

    /**
     * HTML 转义
     *
     * @param string $s 文本
     * @return string
     */
    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * JSON 输出
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
