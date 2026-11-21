<?php
/***
 * 框架内置端点：常驻服务开关（/sys/service）
 *
 * 一个端点两种形态：
 *   浏览器（Accept: text/html）或 ?do=page  → 服务开关页
 *   其余                                   → JSON 接口
 *
 * 动作（?do=）：
 *   page     开关页 HTML
 *   status   服务列表 + 运行状态 JSON
 *   start    开启：name
 *   stop     关闭：name
 *   restart  重启：name
 *   logs     日志尾部：name + lines
 *
 * 所有动作都要求令牌（`Guard::token`）；浏览器未认证会 302 到 `/sys/auth`。
 * 进程能力由 `Candy\Extend\Process\Runner` 提供，需在 `Source/Inc/System.inc.php`
 * 里 `doDefine('PROCESS_ENABLE', true);` 打开，否则会明确报「能力未开启」。
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 13:05:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Tools\Service\GlobalData;
use Candy\Extend\Tools\Service\Manager;

class Service
{
    /**
     * 入口
     *
     * @return string JSON 或 HTML
     */
    public static function handle(): string
    {
        Guard::token('sys/service');

        $do = strtolower(trim((string) ($_GET['do'] ?? '')));
        if ($do === '') {
            $do = self::wantsHtml() ? 'page' : 'status';
        }

        try {
            switch ($do) {
                case 'page':
                    return self::page();
                case 'status':
                    return self::json(['ok' => true, 'data' => self::snapshot()]);
                case 'doctor':
                    return self::json(['ok' => true, 'data' => \Candy\Extend\Tools\Service\Doctor::run()]);
                case 'start':
                    return self::json(array_merge(['ok' => true], Manager::start(self::name())));
                case 'stop':
                    return self::json(array_merge(['ok' => true], Manager::stop(self::name())));
                case 'store':
                    return self::json(['ok' => true, 'data' => \Candy\Extend\Tools\Cron\Store::info()]);
                case 'push':
                    $target = self::param('target', 'all');
                    $msg    = self::param('msg', '');
                    $url    = self::param('url', '');
                    $at     = (int) self::param('at', '0');
                    $res = $at > 0
                        ? GlobalData::pushAt($at, $target, $msg, $url)
                        : GlobalData::pushNow($target, $msg, $url);
                    return self::json(['ok' => $res['ok'], 'message' => $res['message'], 'data' => ['at' => $res['at']]]);
                case 'online':
                    return self::json(['ok' => true, 'data' => [
                        'available' => GlobalData::available(),
                        'online'    => GlobalData::online(),
                        'cron'      => GlobalData::cronList(),
                        'error'     => GlobalData::error(),
                    ]]);
                case 'opcache':
                    // 服务入口脚本改完必须失效旧字节码，否则 Workerman 子进程会一直跑旧代码
                    $files = [];
                    foreach (Manager::all() as $svc) {
                        if (!empty($svc['entry']) && is_file($svc['entry'])) {
                            $files[] = $svc['entry'];
                        }
                    }
                    $invalidated = [];
                    if (function_exists('opcache_invalidate')) {
                        foreach ($files as $f) {
                            if (@opcache_invalidate($f, true)) {
                                $invalidated[] = basename($f);
                            }
                        }
                    }
                    // 目录列表缓存也必须跟着作废：部署后新增/删除类文件要能被感知
                    if (class_exists(\Candy\Extend\Dir::class)) {
                        \Candy\Extend\Dir::flushListFileCache();
                    }
                    return self::json(['ok' => true, 'data' => [
                        'opcache_enabled' => function_exists('opcache_get_status') ? (bool) (@opcache_get_status(false)['opcache_enabled'] ?? false) : false,
                        'invalidated'     => $invalidated,
                        'reset'           => function_exists('opcache_reset') ? (bool) @opcache_reset() : null,
                        'dir_cache_flushed' => true,
                    ]]);
                case 'force':
                    return self::json(array_merge(['ok' => true], Manager::forceStop(self::name())));
                case 'restart':
                    return self::json(array_merge(['ok' => true], Manager::restart(self::name())));
                case 'logclear':
                    return self::json(\Candy\Extend\Tools\Service\Manager::clearLogs(
                        (string) (self::name() ?: '')
                    ));
                case 'logs':
                    $lines = max(10, min(500, (int) self::param('lines', '120')));
                    return self::json(['ok' => true, 'data' => [
                        'name' => self::name(),
                        'log'  => Manager::logFile(self::name()),
                        // 双源：运行日志（真正在跑时发生的事）+ 启动输出（失败原因只在这里）
                        'segments' => Manager::logSegments(self::name(), $lines),
                        'lines' => Manager::logs(self::name(), $lines, 'auto'),
                    ]]);
                default:
                    return self::json(['ok' => false, 'message' => '不支持的动作：' . $do]);
            }
        } catch (\Throwable $e) {
            return self::json(['ok' => false, 'message' => $e->getMessage(), 'type' => get_class($e)]);
        }
    }

    /**
     * 状态快照（含进程能力开关，便于页面提示）
     *
     * @return array<string, mixed>
     */
    protected static function snapshot(): array
    {
        $gate = '\\Candy\\Extend\\Process\\Gate';
        $php = '\\Candy\\Extend\\Process\\PhpBinary';

        $runner = '\\Candy\\Extend\\Process\\Runner';
        $spawnOk = class_exists($runner) ? $runner::available() : false;

        return [
            'services' => Manager::status(),
            'gate'     => class_exists($gate) ? $gate::info() : ['enabled' => null, 'source' => '类不存在'],
            'php'      => class_exists($php) ? $php::info() : null,
            // 能不能起子进程：页面据此在点按钮之前就提示，而不是等点了才报错
            'process'  => [
                'available' => $spawnOk,
                'reason'    => class_exists($runner) ? $runner::unavailableReason() : '进程能力类不存在',
            ],
            // 依赖预检：进页面就先告诉用户缺什么，不要等点了按钮才报错
            'doctor'   => class_exists('\\Candy\\Extend\\Tools\\Service\\Doctor')
                ? \Candy\Extend\Tools\Service\Doctor::run(null, false)   // 状态刷新不探测端口，避免刷错误日志
                : ['ok' => true, 'summary' => [], 'global' => [], 'services' => []],
            'now'      => time(),
        ];
    }

    /**
     * 要操作的服务名
     *
     * @return string
     */
    protected static function name(): string
    {
        return trim((string) self::param('name', ''));
    }

    /**
     * 取参数（先 I('put') 再 $_POST / $_GET）
     *
     * @param string $key     键
     * @param string $default 默认
     * @return string
     */
    protected static function param(string $key, string $default = ''): string
    {
        $bag = [];
        if (function_exists('I')) {
            try {
                $put = I('put');
                if (is_array($put)) {
                    $bag = $put;
                }
            } catch (\Throwable $e) {
                $bag = [];
            }
        }
        foreach ((array) $_POST as $k => $v) {
            $bag[$k] = $v;
        }
        foreach ((array) $_GET as $k => $v) {
            $bag[$k] = $v;
        }
        return isset($bag[$key]) && is_scalar($bag[$key]) ? trim((string) $bag[$key]) : $default;
    }

    /**
     * 是否要 HTML
     *
     * @return bool
     */
    protected static function wantsHtml(): bool
    {
        $view = strtolower(trim((string) ($_GET['view'] ?? '')));
        if ($view === 'json') {
            return false;
        }
        if ($view === 'html') {
            return true;
        }
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    /**
     * 统一 JSON
     *
     * @param array<string, mixed> $data 数据
     * @return string
     */
    protected static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * 失败引导卡的样式（/sys/service 与 /sys/crontab 共用一份，避免两处漂移）
     *
     * @return string
     */
    public static function guideCss(): string
    {
        return <<<'CSS'
.guide{margin:0 0 18px;border:1px solid #fecaca;background:#fef2f2;border-radius:10px;overflow:hidden}
.ghead{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;background:#fee2e2;color:#991b1b;font-size:14px;font-weight:600}
.gclose{border:0;background:transparent;color:#991b1b;cursor:pointer;font-size:12px;font-family:inherit;opacity:.8}
.gclose:hover{opacity:1}
.grow{padding:12px 16px;border-top:1px solid #fecaca}
.grow:first-of-type{border-top:0}
.gt{font-size:12px;color:#b91c1c;font-weight:600;margin-bottom:6px}
.gtext{font-size:13px;color:#7f1d1d;line-height:1.75;white-space:pre-wrap;word-break:break-word}
.gsteps{margin:0;padding-left:20px;font-size:13px;color:#7f1d1d;line-height:1.85}
.gcmd{margin:0;padding:10px 12px;background:#0f172a;color:#e2e8f0;border-radius:6px;font-size:12px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.gcopy{margin-top:8px;padding:5px 12px;font-size:12px;border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:6px;cursor:pointer;font-family:inherit}
.gcopy:hover{background:#f8fafc}
CSS;
    }

    /**
     * 失败引导卡的脚本：暴露 window.okrSvcFail(title, payload, toastFn)
     *
     * 「为什么没成功 / 当前环境 / 怎么处理 / 可复制命令 / 日志 / 处理完怎么办」
     * 全部由这里统一渲染，页面只要给一个 id="guide" 的容器。
     *
     * @return string
     */
    public static function guideJs(): string
    {
        return <<<'JS'
(function(){
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  window.okrSvcHideGuide=function(){var h=document.getElementById('guide');if(h){h.innerHTML='';}};
  window.okrSvcCopy=function(btn){
    var pre=document.getElementById('gcmd');
    if(!pre){return;}
    var t=pre.textContent;
    function done(){if(btn){btn.textContent='已复制';setTimeout(function(){btn.textContent='复制命令';},1500);}}
    if(navigator.clipboard){navigator.clipboard.writeText(t).then(done);}
    else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();
      try{document.execCommand('copy');}catch(e){}a.remove();done();}
  };
  window.okrSvcFail=function(title,j,toastFn){
    var d=j||{}, host=document.getElementById('guide');
    var msg=String(d.message||'操作失败');
    var rich=!!(d.manual||(d.steps&&d.steps.length)||(d.log_tail&&d.log_tail.length)||(d.reason&&d.reason!==msg));
    if(!rich&&msg.length<80){if(typeof toastFn==='function'){toastFn(msg);}if(host){host.innerHTML='';}return false;}
    if(!host){if(typeof toastFn==='function'){toastFn(msg);}return false;}
    var h='<div class="guide"><div class="ghead"><span>'+esc(title)+'</span>'
      +'<button class="gclose" type="button" onclick="okrSvcHideGuide()">关闭</button></div>';
    h+='<div class="grow"><div class="gt">为什么没成功</div><div class="gtext">'+esc(d.reason||msg)+'</div></div>';
    if(d.env&&d.env.php){
      h+='<div class="grow"><div class="gt">当前环境</div><div class="gtext">PHP '+esc(d.env.php)+' · '
        +esc(d.env.sapi||'')+' · ini 文件：'+esc(d.env.ini||'未知')+'</div></div>';
    }
    if(d.steps&&d.steps.length){
      h+='<div class="grow"><div class="gt">怎么处理</div><ol class="gsteps">';
      d.steps.forEach(function(s){h+='<li>'+esc(s)+'</li>';});
      h+='</ol></div>';
    }
    if(d.manual){
      h+='<div class="grow"><div class="gt">可以直接在服务器上执行（复制这条命令）</div>'
        +'<pre class="gcmd" id="gcmd">'+esc(d.manual)+'</pre>'
        +'<button class="gcopy" type="button" onclick="okrSvcCopy(this)">复制命令</button></div>';
    }
    if(d.log_tail&&d.log_tail.length){
      h+='<div class="grow"><div class="gt">最近的日志'+(d.log?'（'+esc(d.log)+'）':'')+'</div>'
        +'<pre class="gcmd">'+esc(d.log_tail.join('\n'))+'</pre></div>';
    }
    h+='<div class="grow"><div class="gt">处理完之后</div><div class="gtext">点上面的「刷新状态」，或重新点一次「开启」。</div></div>';
    host.innerHTML=h+'</div>';
    host.scrollIntoView({block:'nearest'});
    if(typeof toastFn==='function'){toastFn('操作没有成功，请看上方红色提示');}
    return true;
  };
})();
JS;
    }

    /**
     * 开关页
     *
     * @return string
     */
    protected static function page(): string
    {
        $tpl = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>服务管理 · /sys/service</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:26px 30px 70px;background:#f1f5f9;color:#0f172a;
 font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
h1{margin:0 0 4px;font-size:20px;font-weight:650}
.sub{color:#64748b;font-size:12.5px;margin-bottom:16px}
.sub code{background:#e2e8f0;padding:1px 5px;border-radius:4px;font-size:11.5px}
.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;
 padding:10px 14px;font-size:12.5px;margin-bottom:16px;line-height:1.7}
.warn code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px;font-size:11.5px}
.bar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.btn{padding:6px 13px;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:8px;
 font-size:13px;font-weight:600;cursor:pointer}
.btn:hover{background:#1d4ed8}
.btn.ghost{background:#fff;color:#334155;border-color:#cbd5e1}
.btn.ghost:hover{background:#f8fafc}
.btn.red{background:#fff;color:#b91c1c;border-color:#fecaca}
.btn.red:hover{background:#fef2f2}
.btn:disabled{opacity:.5;cursor:not-allowed}
.sp{flex:1}
.hint{color:#94a3b8;font-size:12px}
.list{display:grid;gap:14px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;
 box-shadow:0 1px 2px rgba(15,23,42,.04)}
.head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.name{font-size:15px;font-weight:650}
.desc{color:#64748b;font-size:12.5px;margin-top:2px}
.badge{display:inline-block;border-radius:999px;padding:1px 10px;font-size:11px;font-weight:600}
.b-on{background:#ecfdf5;color:#059669}
.b-off{background:#f1f5f9;color:#64748b}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px}
.meta{color:#94a3b8;font-size:11.5px;margin-top:6px;word-break:break-all}
.acts{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
pre{margin:10px 0 0;padding:10px 12px;background:#0f172a;color:#e2e8f0;border-radius:8px;
 font-size:11.5px;line-height:1.6;max-height:220px;overflow:auto;white-space:pre-wrap;word-break:break-all}
.empty{padding:40px;text-align:center;color:#94a3b8;background:#fff;border:1px dashed #cbd5e1;border-radius:12px}
#toast{position:fixed;right:22px;bottom:22px;padding:10px 16px;border-radius:9px;font-size:13px;
 background:#0f172a;color:#fff;opacity:0;transition:opacity .25s;pointer-events:none;z-index:60}
#toast.show{opacity:.95}
.okline{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;border-radius:10px;padding:9px 14px;font-size:12.5px;margin-bottom:14px}
.chkbox{margin-top:10px;display:grid;gap:8px}
.chk{border-radius:8px;padding:10px 12px;font-size:12.5px;line-height:1.65}
.chk.block{background:#fef2f2;border:1px solid #fecaca}
.chk.warn{background:#fffbeb;border:1px solid #fde68a}
.chk .ct{font-weight:650;color:#991b1b}
.chk.warn .ct{color:#92400e}
.chk .cd{color:#7f1d1d;margin-top:3px;white-space:pre-wrap}
.chk.warn .cd{color:#78350f}
.cfix{margin:7px 0 0;padding:8px 10px;background:#0f172a;color:#e2e8f0;border-radius:6px;font-size:11.5px;
 white-space:pre-wrap;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.ccopy{margin-top:6px;padding:4px 10px;font-size:11.5px;border:1px solid #cbd5e1;background:#fff;color:#475569;
 border-radius:6px;cursor:pointer;font-family:inherit}
.ccopy:hover{background:#f8fafc}
.pt-on{color:#059669;margin-right:10px}
.pt-off{color:#b45309;margin-right:10px}
__GUIDE_CSS__
</style>
</head>
<body>
<h1>服务管理</h1>
<div class="sub">开启 / 关闭 / 重启各个常驻服务；起不来时会列出原因与可复制的处理命令 · 生成于 __TIME__ ·
 <a href="?do=status&view=json" style="color:#2563eb;text-decoration:none">查看 JSON</a></div>
<div id="gateBox"></div>
<div id="guide"></div>
<div class="bar">
  <button class="btn ghost" id="btnRefresh">刷新状态</button>
  <span class="sp"></span>
  <span class="hint" id="hint">加载中…</span>
</div>
<div class="list" id="list"></div>
<div id="toast"></div>
<script>__GUIDE_JS__</script>
<script>
(function(){
  var data={services:[],gate:{}};

  function toast(m){var t=document.getElementById('toast');t.textContent=m;t.className='show';
    clearTimeout(t._h);t._h=setTimeout(function(){t.className=''},2400);}
  /* ── 失败引导卡：实现在 Service::guideJs()，两页共用，避免文案漂移 ── */
  function hideGuide(){okrSvcHideGuide();}
  function failGuide(name,act,j){
    var verb=(act==='start'?'启动':(act==='stop'?'停止':(act==='restart'?'重启':(act==='force'?'清理残留':'操作'))));
    okrSvcFail('没能'+verb+'「'+name+'」服务',j,toast);
  }

  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function fmt(ts){if(!ts)return '-';var d=new Date(ts*1000);
    function p(n){return n<10?'0'+n:''+n;}
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());}

  /* ── 依赖预检：阻断项标红 + 可复制修复命令；有阻断项就禁用「开启 / 重启」 ── */
  function copyText(t,btn){
    function done(){if(btn){var o=btn.getAttribute('data-label')||'复制处理命令';
      btn.textContent='已复制';setTimeout(function(){btn.textContent=o;},1500);}}
    if(navigator.clipboard){navigator.clipboard.writeText(t).then(done);}
    else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();
      try{document.execCommand('copy');}catch(e){}a.remove();done();}
  }
  function bindCopy(root){
    [].forEach.call(root.querySelectorAll('button.ccopy'),function(b){
      b.onclick=function(){copyText(b.getAttribute('data-fix')||'',b);};
    });
  }
  function chkRow(c){
    var block=(c.level==='blocking');
    var h='<div class="chk '+(block?'block':'warn')+'">'
      +'<div class="ct">'+(block?'阻断':'警告')+' · '+esc(c.title)+'</div>'
      +'<div class="cd">'+esc(c.detail)+'</div>';
    if(c.fix){
      h+='<pre class="cfix">'+esc(c.fix)+'</pre>'
        +'<button class="ccopy" type="button" data-fix="'+esc(c.fix)+'">复制处理命令</button>';
    }
    return h+'</div>';
  }
  function doctorTop(){
    var box=document.getElementById('gateBox');
    var d=data.doctor||{}, g=(d.global||[]).slice();
    var blocks=g.filter(function(c){return c.level==='blocking';});
    var warns=g.filter(function(c){return c.level==='warning';});
    if(!blocks.length&&!warns.length){
      box.innerHTML='<div class="okline">✔ 依赖预检通过'
        +((d.summary&&d.summary.checked_at)?'（'+esc(d.summary.checked_at)+'）':'')+'</div>';
      return;
    }
    var out='';
    blocks.forEach(function(c){out+=chkRow(c);});
    warns.forEach(function(c){out+=chkRow(c);});
    box.innerHTML=out;
    bindCopy(box);
  }

  function render(){
    var box=document.getElementById('list');
    var list=data.services||[];
    var dv=(data.doctor||{}).services||{};
    if(!list.length){
      box.innerHTML='<div class="empty">没有可管理的服务。框架内置的「计划任务调度」「推送网关」会自动出现；要加项目自定义服务，在 <span class="mono">Source/Inc/Service.inc.php</span> 的 <span class="mono">services</span> 里登记。</div>';
      document.getElementById('hint').textContent='共 0 个服务';
      return;
    }
    var html='';
    list.forEach(function(s){
      var d=dv[s.name]||{checks:[],ports:[],can_start:!s.running,can_restart:!s.running};
      if(s.probe_limited && s.probe_hint){
        html+='<div class="card" style="border-color:#fde68a;background:#fffbeb">'
          +'<div style="font-size:13px;color:#92400e;line-height:1.7">'
          +'<b>⚠ 探测受限</b><br>'+esc(s.probe_hint)+'</div></div>';
      }
      var ports=(d.ports||[]).map(function(p){
        return '<span class="'+(p.listening?'pt-on':'pt-off')+'">'+esc(p.label)+' '+p.port
          +(p.listening?' 已监听':' 未监听')+'</span>';
      }).join('');
      var checks=(d.checks||[]).map(chkRow).join('');
      var startOff=!d.can_start, restartOff=!d.can_restart;
      var stopOff=!s.running;
      var startWhy=(s.running?'已在运行':'预检未通过，先看上方红色阻断项');
      html+='<div class="card">'
        +'<div class="head"><span class="name">'+esc(s.label)+'</span>'
        +'<span class="badge '+(s.running?'b-on':'b-off')+'">'+(s.running?'运行中':'已停止')+'</span>'
        +(s.running?'<span class="mono">pid '+s.pid+'</span>':'')
        +(s.running&&s.started_at?'<span class="meta">启动于 '+fmt(s.started_at)+'</span>':'')
        +(s.exists?'':'<span class="badge b-off">入口缺失</span>')
        +'</div>'
        +(s.desc?'<div class="desc">'+esc(s.desc)+'</div>':'')
        +(ports?'<div class="meta">'+ports+'</div>':'')
        +(s.running&&s.cmd?'<div class="meta mono" style="opacity:.75">'+esc(s.cmd)+'</div>':'')
        +((s.running&&!s.cmd&&!s.started_at)
          ? '<div class="meta">网页读不到进程详情（open_basedir 挡住了 /proc）—— 用 <span class="mono">php candy doctor</span> 可看到真实命令行与启动时间</div>'
          : '')
        +'<div class="meta mono">'+esc(s.entry)+'</div>'
        +(s.log_time?'<div class="meta">日志更新 '+fmt(s.log_time)+'</div>':'')
        +(checks?'<div class="chkbox">'+checks+'</div>':'')
        +((startOff&&restartOff&&stopOff)
          ? '<div class="cd" style="margin-top:8px">本环境的网页进程不能起子进程，所以「开启 / 重启 / 关闭」都不可用；'
            + '服务本身在跑（pid '+s.pid+'），需要操作请用上面「怎么处理」里的命令行。</div>'
          : '')
        +'<div class="acts">'
          +'<button class="btn'+(s.running?' ghost':'')+'" data-act="start" data-name="'+esc(s.name)+'"'
            +(startOff?' disabled title="'+esc(startWhy)+'"':'')+'>开启服务</button>'
          +'<button class="btn red" data-act="stop" data-name="'+esc(s.name)+'"'
            +(stopOff?' disabled':'')+'>关闭服务</button>'
          +'<button class="btn ghost" data-act="restart" data-name="'+esc(s.name)+'"'
            +(restartOff?' disabled title="预检未通过（见上方阻断项）"':'')+'>重启</button>'
          +'<button class="btn ghost" data-act="config" data-name="'+esc(s.name)+'"'
            +(s.running?' disabled title="服务运行中：先停止再改配置（改完要重启才生效）"':'')+'>编辑配置</button>'
          +'<button class="btn ghost" data-act="logs" data-name="'+esc(s.name)+'">看日志</button>'
        +'</div>'
        +'<pre id="log-'+esc(s.name)+'" style="display:none"></pre>'
        +'</div>';
    });
    box.innerHTML=html;
    bindCopy(box);
    var n=(data.doctor&&data.doctor.summary)||{};
    document.getElementById('hint').textContent='共 '+list.length+' 个服务 · 阻断 '+(n.blocking||0)+' 项 · 警告 '+(n.warning||0)+' 项';
  }

  function load(silent){
    fetch('?do=status&view=json',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.ok){toast(j.message||'加载失败');return;}
        data=j.data||{services:[],gate:{}};
        doctorTop();render();
        // 页面不能一直显示打开那一刻的状态：给出更新时间，并每 10 秒静默刷新
        var hs=document.getElementById('hint');
        if(hs){hs.setAttribute('data-updated',new Date().toTimeString().slice(0,8));}
      }).catch(function(e){toast('加载失败：'+e.message);});
  }

  function post(doName,params){
    var body=new URLSearchParams();
    Object.keys(params||{}).forEach(function(k){body.set(k,params[k]);});
    return fetch('?do='+doName+'&view=json',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
      body:body.toString()}).then(function(r){return r.json();});
  }

  document.getElementById('btnRefresh').addEventListener('click',function(){hideGuide();load();});

  document.getElementById('list').addEventListener('click',function(e){
    var btn=e.target.closest('button[data-act]');
    if(!btn||btn.disabled){return;}
    var act=btn.getAttribute('data-act'),name=btn.getAttribute('data-name');
    if(act==='config'){
      location.href='/sys/config?service='+encodeURIComponent(name);
      return;
    }
    if(act==='logs'){
      var pre=document.getElementById('log-'+name);
      if(!pre){return;}
      if(pre.style.display!=='none'){pre.style.display='none';return;}
      post('logs',{name:name,lines:120}).then(function(j){
        if(!j.ok){toast(j.message||'读取失败');return;}
        var segs=(j.data&&j.data.segments)||[];
        if(segs.length){
          var buf=[];
          segs.forEach(function(sg){
            buf.push('===== '+sg.label+'（'+(sg.exists?sg.path:'文件不存在')+'）=====');
            buf.push(sg.lines&&sg.lines.length?sg.lines.join('\n'):'(空)');
            buf.push('');
          });
          pre.textContent=buf.join('\n');
        }else{
          var lines=(j.data&&j.data.lines)||[];
          pre.textContent=lines.length?lines.join('\n'):'(日志为空)';
        }
        pre.style.display='';
      });
      return;
    }
    btn.disabled=true;
    post(act,{name:name}).then(function(j){
      if(j&&j.ok){hideGuide();toast(j.message||'完成');}
      else{failGuide(name,act,j);}
      load();
    }).catch(function(err){toast('操作失败：'+err.message);})
      .finally(function(){btn.disabled=false;});
  });

  load();
  // 每 10 秒静默刷新一次：别人在命令行起停过、进程意外退出，页面都能自己跟上
  setInterval(function(){load(true);},10000);
})();
</script>
</body>
</html>
HTML;

        return str_replace(
            ['__TIME__', '__GUIDE_CSS__', '__GUIDE_JS__'],
            [date('Y-m-d H:i:s'), self::guideCss(), self::guideJs()],
            $tpl
        );
    }
}
