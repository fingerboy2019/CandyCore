<?php
declare(strict_types=1);
/***
 * 框架级端点：IP 黑白名单管理（独立页面 /sys/ip）
 *
 * 作用：
 *   · 查看 / 添加 / 删除 / 清空 IP 黑白名单
 *   · 切换 sys 端的黑名单 / 白名单模式（写 Source/Inc/Sys.inc.php 的 security.ip_guard 覆盖）
 *
 * 背景：/sys/* 的令牌被暴力猜测时，连续错 N 次会自动把来源 IP 加入 sys 黑名单；
 *      本页是它的管理面。项目页面的访问控制（Bootstrap::security）也共用这份能力，
 *      但两个 scope 的名单文件是分开的。
 *
 * 动作（?do=）：
 *   page   页面
 *   list   名单 + 当前模式
 *   add    添加（ip / end / minutes / type / scope）
 *   del    删除（ip / end / type / scope）
 *   reset  清空（type / scope）
 *   mode   切换 blacklist|whitelist
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 11:00:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Ip
{
    /**
     * 入口
     *
     * @return string
     */
    public static function handle(): string
    {
        Guard::token('sys/ip');

        $do = strtolower(trim((string) ($_GET['do'] ?? '')));
        if ($do === '') {
            $do = self::wantsHtml() ? 'page' : 'list';
        }

        try {
            switch ($do) {
                case 'page':
                    return self::page();
                case 'list':
                    return self::json(self::ipList());
                case 'add':
                    return self::json(self::ipAdd());
                case 'del':
                    return self::json(self::ipDel());
                case 'reset':
                    return self::json(self::ipReset());
                case 'mode':
                    return self::json(self::ipMode());
                default:
                    return self::json(['ok' => false, 'message' => '不支持的动作：' . $do]);
            }
        } catch (\Throwable $e) {
            return self::json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 名单 + 当前策略
     *
     * @return array<string, mixed>
     */
    protected static function ipList(): array
    {
        $scope = self::scope();
        $out = ['mode' => 'blacklist', 'scope' => $scope, 'black' => [], 'white' => []];
        if (class_exists(Guard::class)) {
            $cfg = Guard::ipConfig();
            $out['mode']     = (string) ($cfg['mode'] ?? 'blacklist');
            $out['enable']   = !empty($cfg['enable']);
            $out['max_fail'] = (int) ($cfg['max_fail'] ?? 0);
            $out['window']   = (int) ($cfg['window'] ?? 0);
            $out['ban_time'] = (int) ($cfg['ban_time'] ?? 0);
        }
        foreach (['black' => 'b', 'white' => 'w'] as $key => $letter) {
            $rows = [];
            foreach ((array) getIPList($letter, $scope) as $row) {
                $rows[] = [
                    'start' => (string) $row[0],
                    'end'   => (string) $row[1],
                    'exp'   => (int) ($row[2] ?? 0),
                    'scope' => (string) ($row[3] ?? 'project'),
                ];
            }
            $out[$key] = $rows;
        }
        $out['files'] = [
            'sys'     => (string) C('LOGPATH') . 'ip-blackList.sys.log',
            'project' => (string) C('LOGPATH') . 'ip-blackList.log',
        ];
        return ['ok' => true, 'data' => $out];
    }

    /**
     * 添加
     *
     * @return array<string, mixed>
     */
    protected static function ipAdd(): array
    {
        $type = strtolower(self::param('type', 'black')) === 'white' ? 'w' : 'b';
        $ip   = trim(self::param('ip', ''));
        $end  = trim(self::param('end', ''));
        $mins = max(0, (int) self::param('minutes', '0'));

        if ($ip === '' || ip2long($ip) === false) {
            return ['ok' => false, 'message' => '请输入合法的 IP'];
        }
        if ($end !== '' && ip2long($end) === false) {
            return ['ok' => false, 'message' => '结束 IP 不合法'];
        }
        $target = $end !== '' ? [$ip, $end] : $ip;
        $ok = addIPList($type, $target, $mins > 0 ? time() + $mins * 60 : 0, self::scope());
        return $ok
            ? ['ok' => true, 'message' => '已加入' . ($type === 'w' ? '白名单' : '黑名单')
                . '（' . self::scope() . '，' . ($mins > 0 ? $mins . ' 分钟' : '永久') . '）']
            : ['ok' => false, 'message' => '写入失败：请检查 Runtime/Logs 是否可写'];
    }

    /**
     * 删除
     *
     * @return array<string, mixed>
     */
    protected static function ipDel(): array
    {
        $type = strtolower(self::param('type', 'black')) === 'white' ? 'w' : 'b';
        $ip   = trim(self::param('ip', ''));
        $end  = trim(self::param('end', ''));
        if ($ip === '' || ip2long($ip) === false) {
            return ['ok' => false, 'message' => 'IP 不合法'];
        }
        $ok = delIPList($type, $end !== '' ? [$ip, $end] : $ip, 0, self::scope());
        return ['ok' => $ok, 'message' => $ok ? '已删除' : '删除失败'];
    }

    /**
     * 清空
     *
     * @return array<string, mixed>
     */
    protected static function ipReset(): array
    {
        $type = strtolower(self::param('type', 'all'));
        $letter = $type === 'white' ? 'w' : ($type === 'black' ? 'b' : '');
        cleanIPList($letter, true, self::scope());
        return ['ok' => true, 'message' => '已清空' . ($type === 'white' ? '白名单' : ($type === 'black' ? '黑名单' : '黑白名单'))];
    }

    /**
     * 切换模式（写项目覆盖配置）
     *
     * @return array<string, mixed>
     */
    protected static function ipMode(): array
    {
        $mode = strtolower(self::param('mode', 'blacklist')) === 'whitelist' ? 'whitelist' : 'blacklist';
        $dir = (string) C('INCPATH');
        if ($dir === '' || !is_dir($dir)) {
            return ['ok' => false, 'message' => '项目配置目录不存在'];
        }
        $file = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . 'Sys.inc.php';
        $cfg = is_file($file) ? (include $file) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        // 写进 sys 配置容器的 security.ip_guard.mode，保留其它分组
        $cfg['security'] = is_array($cfg['security'] ?? null) ? $cfg['security'] : [];
        $cfg['security']['ip_guard'] = is_array($cfg['security']['ip_guard'] ?? null) ? $cfg['security']['ip_guard'] : [];
        $cfg['security']['ip_guard']['mode'] = $mode;

        if (is_file($file)) {
            @copy($file, $file . '.bak-' . date('Ymd-His'));
        }
        $code = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        $tmp = $file . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, $code) === false) {
            return ['ok' => false, 'message' => '写入失败'];
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => '替换失败'];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        // 切白名单时必须先放行自己，否则这一刻起连"切回黑名单"的请求都会被 403
        $self = (string) \Candy\Extend\Network\Client::getClientIp();
        $selfAdded = false;
        if ($mode === 'whitelist' && $self !== '' && ip2long($self) !== false
            && !\Candy\Core\Security::find($self, 'w', 'sys')) {
            $selfAdded = addIPList('w', $self, 0, 'sys');
        }

        return [
            'ok' => true,
            'self' => $self,
            'self_added' => $selfAdded,
            'message' => '已切换为' . ($mode === 'whitelist' ? '白名单' : '黑名单') . '模式'
                . ($selfAdded ? '，并把当前 IP ' . $self . ' 加入了白名单' : ''),
        ];
    }

    /**
     * 作用域
     *
     * @return string
     */
    protected static function scope(): string
    {
        return strtolower(self::param('scope', 'sys')) === 'project' ? 'project' : 'sys';
    }

    /**
     * 参数
     *
     * @param string $key     键
     * @param string $default 默认值
     * @return string
     */
    protected static function param(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    /**
     * 是否要 HTML
     *
     * @return bool
     */
    protected static function wantsHtml(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    /**
     * JSON 响应
     *
     * @param array<string, mixed> $data 数据
     * @return string
     */
    protected static function json(array $data): string
    {
        header('Content-Type: application/json; charset=utf-8');
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * 页面
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
<title>IP 访问控制 · Candy</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Helvetica Neue","PingFang SC","Microsoft YaHei",sans-serif;
 background:#f1f5f9;color:#0f172a;font-size:14px}
.wrap{max-width:1100px;margin:0 auto;padding:24px 20px 60px}
h1{font-size:20px;margin:0 0 6px}
.sub{color:#64748b;font-size:12.5px;margin-bottom:18px;line-height:1.7}
.sub a{color:#2563eb;text-decoration:none}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04);padding:16px 18px;margin-bottom:16px}
.row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}
.meta{color:#94a3b8;font-size:12px}
select,input{padding:5px 9px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;background:#fff}
.btn{padding:6px 14px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:13px;font-weight:600;cursor:pointer}
.btn.ghost{background:#fff;color:#334155;border-color:#cbd5e1}
.btn.red{background:#fff;color:#b91c1c;border-color:#fecaca}
.btn.sm{padding:2px 10px;font-size:12px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
th{text-align:left;color:#64748b;font-weight:600;font-size:12px;padding:6px 8px;border-bottom:1px solid #e2e8f0}
td{padding:7px 8px;border-bottom:1px solid #f1f5f9}
.tag{display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:600}
.tag.sys{background:#eff6ff;color:#2563eb}
.tag.proj{background:#f1f5f9;color:#64748b}
.tag.b{background:#fef2f2;color:#b91c1c}
.tag.w{background:#ecfdf5;color:#047857}
.empty{color:#94a3b8;padding:14px 0}
#toast{position:fixed;right:22px;bottom:22px;padding:10px 16px;border-radius:9px;font-size:13px;background:#0f172a;color:#fff;
 opacity:0;transition:opacity .25s;pointer-events:none;z-index:60}
#toast.show{opacity:.95}
.warn{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;color:#92400e;font-size:12.5px;line-height:1.7;margin-top:12px}
h2{font-size:14px;margin:0 0 4px}
</style>
</head>
<body>
<div class="wrap">
<h1>IP 访问控制</h1>
<div class="sub">
  <b>sys</b> 只作用于 <span class="mono">/sys/*</span> 端点（令牌暴力猜测自动封禁写这里）；<b>project</b> 作用于整个站点的页面访问控制。
  两个作用域的名单分开存，互不影响。生成于 __TIME__ ·
  <a href="/sys/service">服务管理</a> · <a href="/sys/config">配置</a> · <a href="/sys/url">端点清单</a>
</div>

<div class="card">
  <div class="row">
    <h2 style="margin:0">策略</h2>
    <span class="meta" id="policyInfo"></span>
    <span style="flex:1"></span>
    作用域：
    <select id="scope">
      <option value="sys">sys（/sys/* 端点）</option>
      <option value="project">project（站点页面）</option>
    </select>
    模式：
    <select id="mode">
      <option value="blacklist">黑名单（命中即拒）</option>
      <option value="whitelist">白名单（不在名单即拒）</option>
    </select>
  </div>
  <div class="warn" id="wlWarn" style="display:none">
    白名单模式下，只有名单内的 IP 能访问对应作用域。切换时会自动把<b>你当前的 IP</b> 加入白名单；
    若被锁在外面，用命令行救：<span class="mono">php candy ip add &lt;你的IP&gt; --type=w</span>
  </div>
</div>

<div class="card">
  <div class="row">
    <h2 style="margin:0">名单</h2>
    <span class="meta" id="countInfo"></span>
    <span style="flex:1"></span>
    <button class="btn red sm" id="resetBlack" type="button">清空黑名单</button>
    <button class="btn red sm" id="resetWhite" type="button">清空白名单</button>
  </div>
  <div id="tables"></div>
</div>

<div class="card">
  <div class="row">
    <h2 style="margin:0">添加</h2>
    <span style="flex:1"></span>
    类型：
    <select id="addType">
      <option value="black">黑名单</option>
      <option value="white">白名单</option>
    </select>
    IP：<input id="addIp" placeholder="1.2.3.4" style="width:150px">
    ~ <input id="addEnd" placeholder="（可选）结束 IP" style="width:150px">
    <select id="addMinutes">
      <option value="0">永久</option>
      <option value="30">30 分钟</option>
      <option value="120">2 小时</option>
      <option value="1440">24 小时</option>
      <option value="10080">7 天</option>
    </select>
    <button class="btn" id="addBtn" type="button">加入名单</button>
  </div>
  <div class="meta" style="margin-top:8px" id="fileInfo"></div>
</div>
</div>
<div id="toast"></div>
<script>
(function(){
  var el=function(id){return document.getElementById(id);};
  var tables=el('tables'), scopeSel=el('scope'), modeSel=el('mode');

  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function toast(t){var x=el('toast');x.textContent=t;x.className='show';
    clearTimeout(x._h);x._h=setTimeout(function(){x.className='';},2400);}
  function q(url){return fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();});}
  function post(act,p){var b=new URLSearchParams();Object.keys(p||{}).forEach(function(k){b.set(k,p[k]);});
    return fetch('?do='+act+'&view=json',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
      body:b.toString()}).then(function(r){return r.json();});}
  function fmtExp(ts){if(!ts){return '永久';}var d=new Date(ts*1000),p=function(n){return (n<10?'0':'')+n;};
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());}

  function table(title,rows,type){
    if(!rows||!rows.length){return '<h2 style="margin-top:14px">'+title+'</h2><div class="empty">（空）</div>';}
    var h='<h2 style="margin-top:14px">'+title+' <span class="meta">'+rows.length+' 条</span></h2>'
      +'<table><thead><tr><th>IP</th><th>到期</th><th>作用域</th><th></th></tr></thead><tbody>';
    rows.forEach(function(r){
      h+='<tr><td class="mono">'+esc(r.start===r.end?r.start:(r.start+' ~ '+r.end))+'</td>'
        +'<td>'+esc(fmtExp(r.exp))+'</td>'
        +'<td><span class="tag '+(r.scope==='sys'?'sys':'proj')+'">'+esc(r.scope)+'</span></td>'
        +'<td style="text-align:right"><button class="btn ghost sm" type="button" data-del="1" data-type="'+type
        +'" data-start="'+esc(r.start)+'" data-end="'+esc(r.end)+'">删除</button></td></tr>';
    });
    return h+'</tbody></table>';
  }

  function render(d){
    modeSel.value=d.mode||'blacklist';
    el('policyInfo').textContent='（enable='+(d.enable?'on':'off')+'，'+d.max_fail+' 次错令牌封 '
      +Math.round((d.ban_time||0)/60)+' 分钟，统计窗口 '+Math.round((d.window||0)/60)+' 分钟）';
    el('wlWarn').style.display=(d.mode==='whitelist')?'':'none';
    el('countInfo').textContent='（scope='+d.scope+'）';
    tables.innerHTML=table('黑名单',d.black,'black')+table('白名单',d.white,'white');
    el('fileInfo').textContent='黑名单文件：'+(d.files&&d.files[d.scope]?d.files[d.scope]:'');
  }

  function load(){
    q('?do=list&view=json&scope='+encodeURIComponent(scopeSel.value)).then(function(j){
      if(!j.ok){tables.textContent=j.message||'加载失败';return;}
      render(j.data);
    }).catch(function(e){tables.textContent='加载失败：'+e.message;});
  }

  scopeSel.onchange=load;
  modeSel.onchange=function(){
    post('mode',{mode:modeSel.value}).then(function(j){toast(j.message||'');load();});
  };
  el('addBtn').onclick=function(){
    post('add',{type:el('addType').value,scope:scopeSel.value,ip:el('addIp').value.trim(),
      end:el('addEnd').value.trim(),minutes:el('addMinutes').value}).then(function(j){
      toast(j.message||'');
      if(j.ok){el('addIp').value='';el('addEnd').value='';load();}
    });
  };
  el('resetBlack').onclick=function(){
    if(!confirm('确定清空当前作用域的黑名单？')){return;}
    post('reset',{type:'black',scope:scopeSel.value}).then(function(j){toast(j.message||'');load();});
  };
  el('resetWhite').onclick=function(){
    if(!confirm('确定清空当前作用域的白名单？')){return;}
    post('reset',{type:'white',scope:scopeSel.value}).then(function(j){toast(j.message||'');load();});
  };
  tables.addEventListener('click',function(e){
    var b=e.target.closest?e.target.closest('button[data-del]'):null;
    if(!b){return;}
    post('del',{type:b.getAttribute('data-type'),scope:scopeSel.value,
      ip:b.getAttribute('data-start'),end:b.getAttribute('data-end')}).then(function(j){toast(j.message||'');load();});
  });

  load();
})();
</script>
</body>
</html>
HTML;
        return str_replace('__TIME__', date('Y-m-d H:i:s'), $tpl);
    }
}
