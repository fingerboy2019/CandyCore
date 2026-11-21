<?php
declare(strict_types=1);
/***
 * 框架级端点：框架服务配置
 *
 * 三层模型：
 *   框架默认  Candy/Class/Tools/Inc/<Name>.inc.php      （只读，随框架升级）
 *   项目覆盖  Source/Inc/<Name>.inc.php                 （本页保存的位置）
 *   生效值    incMergeDefaults(默认, 覆盖)              （默认参数为范围，超出丢弃）
 *
 * 动作（?do=）：
 *   page     配置页 HTML
 *   list     可配置项清单
 *   get      某个配置的「默认 / 覆盖 / 生效 / 被丢弃的键」
 *   save     保存覆盖（写 Source/Inc，先校验再原子替换）
 *   reset    删除覆盖，回到框架默认
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 10:20:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Config
{
    /**
     * 已知配置的展示信息：[服务名, 说明]
     */
    protected const META = [
        'Process'          => ['process', '进程组 worker 清单（内置 globaldata / scheduler 的覆盖与额外 worker）'],
        'Api'              => ['process', 'GlobalData 网关地址（须与 globaldata 监听一致）'],
        'CrontabScheduler' => ['process', '计划任务调度器：轮询参数、执行记录、HTTP 回调限制'],
        'Push'             => ['push', '推送网关：监听地址、离线策略、限流、审计'],
        'Server'           => ['', '常驻 Web 服务：监听端口、域名、worker 数、max_request / reuse_port / gzip（是否运行由 /sys/service 的开关决定）'],
        'Sys'              => ['', 'sys 系统配置：security.ip_guard（令牌错误封禁阈值 / 窗口 / 时长 / 黑白名单模式），可继续扩展其它分组'],
    ];

    /**
     * 入口
     *
     * @return string
     */
    public static function handle(): string
    {
        Guard::token('sys/config');

        $do = strtolower(trim((string) ($_GET['do'] ?? '')));
        if ($do === '') {
            $do = self::wantsHtml() ? 'page' : 'list';
        }

        try {
            switch ($do) {
                case 'page':
                    return self::page();
                case 'list':
                    return self::json(['ok' => true, 'data' => self::files()]);
                case 'get':
                    return self::json(self::detail(self::name()));
                case 'save':
                    return self::json(self::save(self::name(), (string) self::param('code', '')));
                case 'reset':
                    return self::json(self::reset(self::name()));
                case 'form':
                    return self::json(['ok' => true, 'data' => loadInc(self::name())]);
                case 'saveform':
                    return self::json(self::saveForm());
                case 'formpage':
                    return self::formPage();
                default:
                    return self::json(['ok' => false, 'message' => '不支持的动作：' . $do]);
            }
        } catch (\Throwable $e) {
            return self::json(['ok' => false, 'message' => $e->getMessage(), 'type' => get_class($e)]);
        }
    }

    /**
     * 框架默认目录
     *
     * @return string
     */
    public static function incDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Inc' . DIRECTORY_SEPARATOR;
    }

    /**
     * 可配置项清单（只列框架目录里真实存在的默认文件）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function files(): array
    {
        $dir = self::incDir();
        $out = [];
        foreach ((array) glob($dir . '*.inc.php') as $path) {
            $base = basename($path);
            $name = substr($base, 0, -strlen('.inc.php'));
            $meta = self::META[$name] ?? ['', ''];
            $project = self::projectPath($name);
            $out[] = [
                'name'       => $name,
                'service'    => $meta[0],
                'desc'       => $meta[1],
                'framework'  => $path,
                'project'    => $project,
                'overridden' => $project !== '',
                'service_running' => self::serviceRunning($meta[0]),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['service'], (string) $b['service']));
        return $out;
    }

    /**
     * 某个配置的三层详情
     *
     * @param string $name 配置名
     * @return array<string, mixed>
     */
    public static function detail(string $name, bool $fresh = false): array
    {
        self::assertName($name);

        $fwPath = self::incDir() . $name . '.inc.php';
        $pjPath = self::projectPath($name);

        $frameworkCode = is_file($fwPath) ? (string) file_get_contents($fwPath) : '';
        $projectCode = $pjPath !== '' ? (string) file_get_contents($pjPath) : '';

        $GLOBALS['__incDroppedKeys'] = [];
        if ($fresh) {
            // 刚写完文件时 loadConfig 里还是请求内的旧缓存，这里直接读盘合并
            $base = is_file($fwPath) ? include $fwPath : [];
            $over = ($pjPath !== '' && is_file($pjPath)) ? include $pjPath : [];
            $base = is_array($base) ? $base : [];
            $over = is_array($over) ? $over : [];
            $effective = (($base !== [] && $over !== []) && function_exists('incMergeDefaults'))
                ? incMergeDefaults($base, $over)
                : ($over !== [] ? $over : $base);
        } else {
            $effective = loadConfig($name);
        }
        $dropped = function_exists('incDroppedKeys') ? incDroppedKeys() : [];

        $meta = self::META[$name] ?? ['', ''];

        return [
            'ok'        => true,
            'name'      => $name,
            'service'   => $meta[0],
            'desc'      => $meta[1],
            'framework_path' => $fwPath,
            'project_path'   => $pjPath,
            'framework_code' => $frameworkCode,
            'project_code'   => $projectCode,
            'has_project'    => $pjPath !== '',
            'effective'      => $effective,
            'dropped'        => $dropped,
            'service_running' => self::serviceRunning($meta[0]),
        ];
    }

    /**
     * 保存项目覆盖
     *
     * 源码以 **base64** 传输：框架的 XSS 过滤会把请求值里的 `<` 转成 `&lt;`，
     * 直接传 `<?php ...` 会被转义成 `&lt;?php ...`，所以这里先解码。
     *
     * @param string $name 配置名
     * @param string $code base64 的 PHP 源码（兼容直接传源码）
     * @return array<string, mixed>
     */
    public static function save(string $name, string $code): array
    {
        self::assertName($name);

        $content = '';
        $decoded = base64_decode(str_replace(' ', '+', $code), true);
        if (is_string($decoded) && stripos(ltrim($decoded), '<?php') === 0) {
            $content = $decoded;
        } elseif (stripos(ltrim($code), '<?php') === 0) {
            $content = $code;   // 少数情况下值没被转义，直接用
        } else {
            return ['ok' => false, 'message' => '内容无法解析：需要 base64 编码的 PHP 源码', 'code' => 'bad_payload'];
        }

        if (trim($content) === '') {
            return ['ok' => false, 'message' => '内容为空'];
        }
        if (stripos(ltrim($content), '<?php') !== 0) {
            return ['ok' => false, 'message' => '内容必须以 <?php 开头'];
        }

        // ① 语法校验（TOKEN_PARSE 会抛 ParseError）
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'PHP 语法错误：' . $e->getMessage(), 'code' => 'syntax'];
        }

        // ② 语义校验：必须 return 一个数组
        $tmp = self::tmpFile();
        if (@file_put_contents($tmp, $content) === false) {
            return ['ok' => false, 'message' => '临时文件写入失败：' . $tmp];
        }
        $returns = null;
        try {
            $returns = include $tmp;
        } catch (\Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'message' => '执行配置时出错：' . $e->getMessage(), 'code' => 'runtime'];
        }
        @unlink($tmp);

        if (!is_array($returns)) {
            return ['ok' => false, 'message' => '配置文件必须 return 一个数组', 'code' => 'not_array'];
        }

        // ③ 目标文件（项目覆盖），先备份再原子替换
        $dir = (string) C('INCPATH');
        if ($dir === '' || !is_dir($dir)) {
            return ['ok' => false, 'message' => '项目配置目录不存在：' . $dir];
        }
        $target = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name . '.inc.php';
        self::backup($name, $target);

        $tmpTarget = $target . '.tmp-' . getmypid();
        if (@file_put_contents($tmpTarget, $content) === false) {
            return ['ok' => false, 'message' => '写入失败：' . $tmpTarget];
        }
        @chmod($tmpTarget, 0664);
        if (!@rename($tmpTarget, $target)) {
            @unlink($tmpTarget);
            return ['ok' => false, 'message' => '替换失败（可检查目录权限）'];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }

        $detail = self::detail($name, true);
        return [
            'ok'       => true,
            'message'  => '已保存到 ' . $target,
            'dropped'  => $detail['dropped'],
            'effective' => $detail['effective'],
            'need_restart' => $detail['service_running'] ? ($detail['service'] ?? '') : '',
        ];
    }

    /**
     * 删除项目覆盖（回到框架默认）
     *
     * @param string $name 配置名
     * @return array<string, mixed>
     */
    public static function reset(string $name): array
    {
        self::assertName($name);
        $target = self::projectPath($name);
        if ($target === '') {
            return ['ok' => true, 'message' => '本来就没有覆盖文件，已是框架默认'];
        }
        self::backup($name, $target);
        if (!@unlink($target)) {
            return ['ok' => false, 'message' => '删除失败：' . $target];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
        $detail = self::detail($name, true);
        return [
            'ok'        => true,
            'message'   => '已删除覆盖文件，回到框架默认',
            'effective' => $detail['effective'],
            'need_restart' => $detail['service_running'] ? ($detail['service'] ?? '') : '',
        ];
    }

    /**
     * 项目覆盖文件路径（不存在返回 ''）
     *
     * @param string $name 配置名
     * @return string
     */
    protected static function projectPath(string $name): string
    {
        $dir = function_exists('C') ? (string) C('INCPATH') : '';
        if ($dir === '') {
            return '';
        }
        $path = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name . '.inc.php';
        return is_file($path) ? $path : '';
    }

    /**
     * 校验配置名在框架默认目录里真实存在（顺带防路径穿越）
     *
     * @param string $name 配置名
     * @return void
     */
    protected static function assertName(string $name): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new \RuntimeException('非法的配置名：' . $name);
        }
        if (!is_file(self::incDir() . $name . '.inc.php')) {
            throw new \RuntimeException('不是框架服务配置：' . $name);
        }
    }

    /**
     * 备份旧文件到 Runtime/config-backup/
     *
     * @param string $name   配置名
     * @param string $target 目标文件
     * @return void
     */
    protected static function backup(string $name, string $target): void
    {
        if (!is_file($target)) {
            return;
        }
        $root = defined('CANDYROOT') ? CANDYROOT : (string) C('CANDYROOT');
        $dir = rtrim((string) $root, "/\\") . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'config-backup' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @copy($target, $dir . $name . '-' . date('Ymd-His') . '.php');
    }

    /**
     * 临时文件
     *
     * @return string
     */
    protected static function tmpFile(): string
    {
        $root = defined('CANDYROOT') ? CANDYROOT : (string) C('CANDYROOT');
        $dir = rtrim((string) $root, "/\\") . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'Tmps' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . 'config-' . getmypid() . '-' . mt_rand(1000, 9999) . '.php';
    }

    /**
     * 服务是否在跑（决定「改完要不要重启」）
     *
     * @param string $service 服务名
     * @return bool
     */
    protected static function serviceRunning(string $service): bool
    {
        if ($service === '') {
            return false;
        }
        $mgr = '\\Candy\\Extend\\Tools\\Service\\Manager';
        if (!class_exists($mgr)) {
            return false;
        }
        $pid = (int) $mgr::pid($service);
        if ($pid <= 0) {
            return false;
        }
        $runner = '\\Candy\\Extend\\Process\\Runner';
        return class_exists($runner) && $runner::alive($pid)->running;
    }

    /**
     * 参数
     *
     * @param string $key     键
     * @param string $default 默认
     * @return string
     */
    protected static function param(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    /**
     * 配置名
     *
     * @return string
     */
    protected static function name(): string
    {
        return trim((string) self::param('name', ''));
    }

    /**
     * 是否要 HTML
     *
     * @return bool
     */
    protected static function wantsHtml(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'text/html');
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
     * 配置页
     *
     * @return string
     */
    /**
     * 保存表单提交（只写入与框架默认不同的项）
     *
     * 值以 base64 的 JSON 传输：框架的 XSS 过滤会把 `<` 转义，
     * 而配置值里可能有任意文本。
     *
     * @return array<string, mixed>
     */
    public static function saveForm(): array
    {
        $raw = (string) self::param('values', '');
        if ($raw === '') {
            return ['ok' => false, 'message' => '缺少 values 参数'];
        }
        $decoded = base64_decode(str_replace(' ', '+', $raw), true);
        $values  = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($values)) {
            return ['ok' => false, 'message' => 'values 需要 base64 编码的 JSON 对象'];
        }
        return saveInc(self::name(), $values);
    }

    /**
     * 表单编辑页（由 loadInc 的 schema 生成控件）
     *
     * 单独成页，不干扰现有源码编辑器：三种标量控件 + 关联数组递归展开，
     * 索引数组只读展示并引导到源码编辑器。
     *
     * @return string
     */
    public static function formPage(): string
    {
        $name = self::name();
        $nameJson = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>配置表单 · Candy</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;background:#f1f5f9;color:#0f172a;font-size:14px}
.wrap{max-width:900px;margin:0 auto;padding:24px 20px 80px}
h1{font-size:19px;margin:0 0 6px}
.sub{color:#64748b;font-size:12.5px;line-height:1.7;margin-bottom:16px}
.sub a{color:#2563eb;text-decoration:none}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px}
.sec{margin:16px 0 6px;font-size:12px;font-weight:700;color:#475569;text-transform:none;border-bottom:1px dashed #e2e8f0;padding-bottom:4px}
.row{display:grid;grid-template-columns:240px 1fr 90px;gap:12px;align-items:center;padding:7px 0}
.row.list{grid-template-columns:1fr;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:9px;padding:10px 12px;margin:6px 0}
.row label{font-size:13px;color:#334155;word-break:break-all}
.row label .p{display:block;color:#94a3b8;font-size:11px;font-family:ui-monospace,Menlo,Consolas,monospace}
input[type=text],input[type=number],select{width:100%;padding:6px 9px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;background:#fff}
.radios{display:flex;gap:14px;align-items:center;font-size:13px;color:#334155}
.radios label{display:inline-flex;gap:5px;align-items:center;cursor:pointer}
.src{font-size:11px;color:#94a3b8;text-align:right}
.src.ov{color:#2563eb;font-weight:600}
.list .ttl{font-weight:650;font-size:13px}
.list .tip{color:#64748b;font-size:12px;margin-top:5px;line-height:1.6}
.list .tip a{color:#2563eb;text-decoration:none}
.acts{position:sticky;bottom:0;background:#f1f5f9;padding:12px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}
.btn{padding:7px 16px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
.btn.ghost{background:#fff;color:#334155;border-color:#cbd5e1}
.btn:disabled{opacity:.5;cursor:not-allowed}
.hint{color:#94a3b8;font-size:12px}
.warn{margin-top:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;color:#92400e;font-size:12.5px;line-height:1.7}
.ok{margin-top:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;color:#166534;font-size:12.5px;line-height:1.7}
#toast{position:fixed;right:22px;bottom:22px;padding:10px 16px;border-radius:9px;font-size:13px;background:#0f172a;color:#fff;opacity:0;transition:opacity .25s;pointer-events:none;z-index:60}
#toast.show{opacity:.95}
@media(max-width:700px){.row{grid-template-columns:1fr}.src{text-align:left}}
</style>
</head>
<body>
<div class="wrap">
  <h1>配置表单</h1>
  <div class="sub">
    控件由<b>框架默认配置</b>生成 —— 只有默认里存在的键才会出现在表单，提交范围天然受限。<br>
    保存只写入<b>与默认不同</b>的项到 <span class="mono">Source/Inc/</span>，索引数组请到源码编辑器改。
    · <a href="?do=page">← 返回配置列表</a>
  </div>
  <div class="card" id="form"><div class="hint">加载中…</div></div>
  <div id="msg"></div>
  <div class="acts">
    <button class="btn" id="btnSave" disabled>保存</button>
    <button class="btn ghost" id="btnReload">重新读取</button>
    <span class="hint" id="tip"></span>
  </div>
</div>
<div id="toast"></div>
<script>
(function(){
  var CUR = __NAME__;
  var NODES = [];
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function b64(s){try{return btoa(unescape(encodeURIComponent(s)));}catch(e){return btoa(s);} }
  function toast(m){var t=document.getElementById('toast');t.textContent=m;t.className='show';
    clearTimeout(t._h);t._h=setTimeout(function(){t.className='';},2400);}
  function fid(p){return 'f'+p.replace(/[^A-Za-z0-9_]/g,'_');}
  function last(p){var a=p.split('.');return a[a.length-1];}

  function load(){
    document.getElementById('form').innerHTML='<div class="hint">加载中…</div>';
    fetch('?do=form&name='+encodeURIComponent(CUR)).then(function(r){return r.json();}).then(function(j){
      if(!j.ok){document.getElementById('form').innerHTML='<div class="warn">'+esc(j.message||'加载失败')+'</div>';return;}
      NODES=j.data.nodes||[];
      render(j.data);
      document.getElementById('btnSave').disabled=false;
    }).catch(function(e){
      document.getElementById('form').innerHTML='<div class="warn">请求失败：'+esc(e.message)+'</div>';
    });
  }

  function render(data){
    if(!NODES.length){document.getElementById('form').innerHTML='<div class="hint">该配置没有可编辑的键</div>';return;}
    var h='';
    NODES.forEach(function(n){
      if(n.kind==='map'){
        h+='<div class="sec" style="margin-left:'+(n.depth*14)+'px">'+esc(last(n.path))+'</div>';
        return;
      }
      if(n.kind==='list'){
        h+='<div class="row list"><div class="ttl">'+esc(n.path)+'　列表 · '+n.count+' 项</div>'
          +'<div class="tip">'+(n.keys.length?('每项字段：'+esc(n.keys.join(' / '))+'　'):'')
          +'这类结构不在表单里编辑，请到源码编辑器修改。<br>'
          +'<a href="?do=page&name='+encodeURIComponent(CUR)+'">打开配置编辑器 →</a></div></div>';
        return;
      }
      var id=fid(n.path);
      var ov=n.source==='override';
      var ctl='';
      if(n.kind==='bool'){
        var args='name="'+id+'"';
        ctl='<div class="radios">'
          +'<label><input type="radio" '+args+' value="__inherit__"'+(ov?'':' checked')+'>跟随默认</label>'
          +'<label><input type="radio" '+args+' value="1"'+(ov&&n.value===true?' checked':'')+'>开</label>'
          +'<label><input type="radio" '+args+' value="0"'+(ov&&n.value===false?' checked':'')+'>关</label>'
          +'</div>';
      } else if(n.kind==='number'){
        ctl='<input type="number" id="'+id+'" value="'+esc(n.value)+'"'
          +(n.min!=null?(' min="'+esc(n.min)+'"'):'')+(n.max!=null?(' max="'+esc(n.max)+'"'):'')+'>';
      } else if(n.kind==='select'){
        var opts='<option value="__inherit__">跟随默认</option>';
        Object.keys(n.options||{}).forEach(function(k){
          opts+='<option value="'+esc(k)+'"'+(ov&&String(n.value)===String(k)?' selected':'')+'>'+esc(n.options[k])+'</option>';
        });
        ctl='<select id="'+id+'">'+opts+'</select>';
      } else {
        ctl='<input type="text" id="'+id+'" value="'+esc(n.value)+'">';
      }
      h+='<div class="row" style="margin-left:'+(n.depth*14)+'px"><label title="'+esc(n.path)+'">'
        +esc(last(n.path))+'<span class="p">'+esc(n.path)+'</span></label>'
        +ctl+'<span class="src'+(ov?' ov':'')+'">'+(ov?'已覆盖':'默认')+'</span></div>';
    });
    document.getElementById('form').innerHTML=h;
    document.getElementById('tip').textContent='共 '+NODES.length+' 个节点';
  }

  function collect(){
    var out={};
    NODES.forEach(function(n){
      if(n.kind==='map'||n.kind==='list'){return;}
      var id=fid(n.path);
      if(n.kind==='bool'){
        var el=document.querySelector('input[name="'+id+'"]:checked');
        out[n.path]= el? el.value : '__inherit__';
        return;
      }
      var e=document.getElementById(id);
      if(!e){return;}
      out[n.path]=e.value;
    });
    return out;
  }

  document.getElementById('btnReload').onclick=load;
  document.getElementById('btnSave').onclick=function(){
    var btn=this;
    btn.disabled=true;
    var body=new URLSearchParams();
    body.set('values', b64(JSON.stringify(collect())));
    fetch('?do=saveform&name='+encodeURIComponent(CUR),{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:body.toString()
    }).then(function(r){return r.json();}).then(function(j){
      var box=document.getElementById('msg');
      if(j.ok){
        toast('已保存');
        box.innerHTML='<div class="ok">'+esc(j.message||'已保存')+'</div>';
        load();
      }else{
        toast(j.message||'保存失败');
        box.innerHTML='<div class="warn">'+esc(j.message||'保存失败')+'</div>';
      }
      btn.disabled=false;
    }).catch(function(e){
      toast('请求失败：'+e.message);
      btn.disabled=false;
    });
  };

  load();
})();
</script>
</body>
</html>
HTML;

        return str_replace('__NAME__', $nameJson, $html);
    }

    protected static function page(): string
    {
        $tpl = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>框架服务配置 · Candy</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Helvetica Neue","PingFang SC","Microsoft YaHei",sans-serif;
 background:#f1f5f9;color:#0f172a;font-size:14px}
.wrap{max-width:1280px;margin:0 auto;padding:24px 20px 60px}
h1{font-size:20px;margin:0 0 6px}
.sub{color:#64748b;font-size:12.5px;margin-bottom:18px;line-height:1.7}
.sub a{color:#2563eb;text-decoration:none}
.layout{display:grid;grid-template-columns:280px 1fr;gap:16px;align-items:start}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.side{padding:8px}
.item{padding:10px 12px;border-radius:8px;cursor:pointer;margin-bottom:4px}
.item:hover{background:#f8fafc}
.item.on{background:#eff6ff;box-shadow:inset 0 0 0 1px #bfdbfe}
.item .t{font-weight:650;font-size:13.5px}
.item .d{color:#64748b;font-size:11.5px;margin-top:2px;line-height:1.5}
.badge{display:inline-block;border-radius:999px;padding:1px 8px;font-size:10.5px;font-weight:600;margin-left:6px;vertical-align:1px}
.b-ov{background:#eff6ff;color:#2563eb}
.b-def{background:#f1f5f9;color:#64748b}
.main{padding:16px 18px}
.head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.name{font-size:15px;font-weight:650}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px}
.meta{color:#94a3b8;font-size:11.5px;margin-top:4px;word-break:break-all}
.tabs{display:flex;gap:6px;margin:12px 0 8px}
.tab{padding:5px 12px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:12.5px;cursor:pointer}
.tab.on{background:#2563eb;border-color:#2563eb;color:#fff;font-weight:600}
textarea{width:100%;min-height:340px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;
 font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.65;resize:vertical;background:#0f172a;color:#e2e8f0}
pre.out{margin:0;padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;
 font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.65;max-height:420px;overflow:auto;white-space:pre-wrap;word-break:break-all}
.acts{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center}
.btn{padding:6px 14px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:13px;font-weight:600;cursor:pointer}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn.ghost{background:#fff;color:#334155;border-color:#cbd5e1}
.btn.red{background:#fff;color:#b91c1c;border-color:#fecaca}
.hint{color:#94a3b8;font-size:12px}
.warn{margin-top:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;color:#92400e;font-size:12.5px;line-height:1.7}
.warn b{color:#b45309}
.warn pre{margin:6px 0 0;background:#0f172a;color:#e2e8f0;border-radius:6px;padding:8px 10px;font-size:11.5px;white-space:pre-wrap}
#toast{position:fixed;right:22px;bottom:22px;padding:10px 16px;border-radius:9px;font-size:13px;background:#0f172a;color:#fff;
 opacity:0;transition:opacity .25s;pointer-events:none;z-index:60}
#toast.show{opacity:.95}
.empty{padding:40px;text-align:center;color:#94a3b8}
@media(max-width:860px){.layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<h1>框架服务配置</h1>
<div class="sub">
  改这里的值是给<b>框架服务</b>用的：保存在 <span class="mono">Source/Inc/</span> 作为覆盖，框架默认在
  <span class="mono">Candy/Class/Tools/Inc/</span>。合并时<b>以框架默认的键为范围，超出的键会被丢弃</b>。
  · 生成于 __TIME__ · <a href="/sys/service">服务管理</a> · <a href="/sys/url">端点清单</a>
</div>
<div class="layout">
  <div class="card side" id="list"></div>
  <div class="card main">
    <div id="editor"><div class="empty">左侧选择一个配置</div></div>
  </div>
</div>
</div>
<div id="toast"></div>
<script>
(function(){
  var cur=null, data=null, tab='project';
  function b64(s){
    try{return btoa(unescape(encodeURIComponent(s)));}catch(e){
      return btoa(s);}
  }
  function toast(m){var t=document.getElementById('toast');t.textContent=m;t.className='show';
    clearTimeout(t._h);t._h=setTimeout(function(){t.className='';},2400);}
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function get(q,p){var qs=p?('&'+new URLSearchParams(p).toString()):'';
    return fetch(q+qs,{credentials:'same-origin',
    headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();});}
  function post(doName,p){var b=new URLSearchParams();Object.keys(p||{}).forEach(function(k){b.set(k,p[k]);});
    return fetch('?do='+doName+'&view=json',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
      body:b.toString()}).then(function(r){return r.json();});}

  function loadList(){
    return get('?do=list&view=json').then(function(j){
      if(!j.ok){toast(j.message||'加载失败');return;}
      var h='';
      (j.data||[]).forEach(function(f){
        h+='<div class="item'+(cur===f.name?' on':'')+'" data-name="'+esc(f.name)+'" data-service="'+esc(f.service||'')+'">'
          +'<div class="t">'+esc(f.name)
          +(f.overridden?'<span class="badge b-ov">已覆盖</span>':'<span class="badge b-def">默认</span>')
          +'</div>'
          +'<div class="d">'+esc(f.desc||'')+(f.service?' · '+esc(f.service):'')+'</div>'
          +'</div>';
      });
      document.getElementById('list').innerHTML=h||'<div class="empty">没有框架服务配置</div>';
      [].forEach.call(document.querySelectorAll('.item'),function(el){
        el.onclick=function(){open(el.getAttribute('data-name'));};
      });
    });
  }

  function open(name){
    cur=name; tab='project';
    get('?do=get&view=json',{name:name}).then(function(j){
      if(!j.ok){toast(j.message||'加载失败');return;}
      data=j; render();
      loadList();
    });
  }

  function droppedHtml(){
    var d=(data&&data.dropped)||{};
    var keys=Object.keys(d);
    if(!keys.length){return '';}
    var lines=keys.map(function(k){return k+' = '+JSON.stringify(d[k]);}).join('\n');
    return '<div class="warn"><b>注意：有 '+keys.length+' 个键不被框架默认接受，保存后会被忽略</b>'
      +'<pre>'+esc(lines)+'</pre></div>';
  }

  function render(){
    if(!data){return;}
    var running=data.service_running;
    var body='';
    var code = tab==='project' ? (data.project_code||'') : (data.framework_code||'');
    if(tab==='effective'){
      body='<pre class="out">'+esc(JSON.stringify(data.effective,null,2))+'</pre>';
    }else{
      body='<textarea id="code" spellcheck="false"'
        +(tab==='framework'?' readonly':'')+'>'+esc(code)+'</textarea>';
    }
    var h='<div class="head"><span class="name">'+esc(data.name)+'</span>'
      +(data.has_project?'<span class="badge b-ov">已覆盖</span>':'<span class="badge b-def">用框架默认</span>')
      +(running?'<span class="badge b-ov">服务运行中</span>':'')
      +'<a class="btn ghost" style="margin-left:auto;text-decoration:none;padding:5px 12px"'
      +' href="?do=formpage&name='+encodeURIComponent(data.name)+'">表单编辑</a>'
      +'</div>'
      +'<div class="meta">'+esc(data.desc||'')+'</div>'
      +'<div class="meta mono">默认：'+esc(data.framework_path||'')+'</div>'
      +'<div class="meta mono">覆盖：'+esc(data.project_path||'(未创建，保存后生成)')+'</div>'
      +'<div class="tabs">'
        +'<div class="tab'+(tab==='project'?' on':'')+'" data-tab="project">项目覆盖</div>'
        +'<div class="tab'+(tab==='framework'?' on':'')+'" data-tab="framework">框架默认</div>'
        +'<div class="tab'+(tab==='effective'?' on':'')+'" data-tab="effective">最终生效</div>'
      +'</div>'
      +body
      +droppedHtml()
      +(running?'<div class="warn">这个配置属于正在运行的服务，<b>保存后需要重启「'+esc(data.service)+'」才生效</b>。</div>':'')
      +'<div class="acts">'
        +(tab==='framework'
          ? '<span class="hint">框架默认是只读的；要改请切到「项目覆盖」，保存后会生成 Source/Inc 覆盖文件。</span>'
          : '<button class="btn" id="btnSave"'+(tab==='framework'?' disabled':'')+'>保存覆盖</button>'
            +'<button class="btn red" id="btnReset">恢复默认</button>')
        +'<span class="hint" id="hint"></span>'
      +'</div>';
    document.getElementById('editor').innerHTML=h;

    [].forEach.call(document.querySelectorAll('.tab'),function(t){
      t.onclick=function(){ tab=t.getAttribute('data-tab'); render(); };
    });
    var save=document.getElementById('btnSave');
    if(save){ save.onclick=function(){
      var code=document.getElementById('code').value;
      save.disabled=true;
      post('save',{name:cur,code:b64(code)}).then(function(j){
        if(j.ok){
          toast('已保存');
          if(j.need_restart){toast('已保存；记得重启「'+j.need_restart+'」生效');}
          open(cur);
        }else{ toast(j.message||'保存失败'); save.disabled=false; }
      }).catch(function(e){toast('保存失败：'+e.message);save.disabled=false;});
    };}
    var rst=document.getElementById('btnReset');
    if(rst){ rst.onclick=function(){
      if(!data.has_project){toast('本来就没有覆盖');return;}
      rst.disabled=true;
      post('reset',{name:cur}).then(function(j){
        if(j.ok){toast('已恢复框架默认');open(cur);}else{toast(j.message||'失败');rst.disabled=false;}
      });
    };}
  }

  loadList().then(function(){
    // /sys/config?service=process 时直接选中该服务的第一个配置
    var want=new URLSearchParams(location.search).get('service');
    var first=want?document.querySelector('.item[data-service="'+want+'"]'):null;
    first=first||document.querySelector('.item');
    if(first){open(first.getAttribute('data-name'));}
  });
})();
</script>
</body>
</html>
HTML;
        return str_replace('__TIME__', date('Y-m-d H:i:s'), $tpl);
    }
}
