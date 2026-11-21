<?php
/***
 * 框架内置端点：已注册入口 URL 清单（含令牌保护 + 页面内输入令牌）
 *
 * 业务说明：列出**框架入口 URL**——通过 `Tools\Endpoint\Registry` 注册的端点
 * （框架级 + 应用自定义扩展），**不列业务路由**。数据只取 `Registry::list()`，
 * 不读 `RouteRegistry`、不读任何项目文件，**裸框架可用**。
 *
 * 授权模型（裸框架没有「管理员/登录用户」概念，一律用令牌）：
 *   `public`  免令牌（清单本身 sys/url，只列路径不碰业务数据）
 *   `token`   需要令牌，令牌**不放 URL**：在页面上输入一次 → 存 cookie
 *             （`Guard::COOKIE`）→ 之后同源请求自动带上
 *
 * 用法：
 *   /sys/url               → JSON（默认，不含令牌、不含链接）
 *   /sys/url?view=html     → 可视化页面（浏览器直接打开也会走 HTML）
 *   /sys/url?view=json     → 强制 JSON
 *
 * 脚本/无头调用仍可用 `?token=xxx` 或 `Authorization: Bearer xxx`，但那是调用方自己带，
 * 页面里不会把令牌拼进任何链接。
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 12:40:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Url
{
    /** 需要令牌的端点（目前只有计划任务管理，它能触发任务执行） */
    public const AUTH_TOKEN_REQUIRED = ['sys/crontab', 'sys/service', 'sys/push', 'sys/config', 'sys/ip'];

    /** @deprecated 保留兼容：除 AUTH_TOKEN_REQUIRED 外一律免令牌 */
    public const AUTH_PUBLIC = ['sys/version', 'sys/url', 'sys/health', 'sys/ping'];   // 其余 sys/* 默认公开，需令牌的写进 AUTH_TOKEN_REQUIRED

    /** 页面用来判断「是否已认证」的参照端点 */
    private const PROBE = 'sys/crontab';

    /**
     * 入口
     *
     * @return string JSON 或 HTML
     */
    public static function handle(): string
    {
        $data = self::collect();

        if (self::wantsHtml()) {
            return self::renderHtml($data);
        }

        return (string) json_encode([
            'ok'   => true,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * 汇总数据（JSON 与 HTML 共用同一份）
     *
     * @return array<string, mixed>
     */
    protected static function collect(): array
    {
        $endpoints = Registry::list();
        $rows = self::rows($endpoints);

        $count = ['total' => count($rows)];
        foreach ($rows as $row) {
            $count[$row['auth']] = ($count[$row['auth']] ?? 0) + 1;
        }

        $need = isset($_GET['need']);
        $back = (string) ($_GET['return'] ?? '');
        if ($back === '' || str_starts_with($back, '//')) {
            $back = '/sys/url?view=html';
        }

        return [
            'total'     => count($rows),
            'summary'   => $count,
            'authed'    => Guard::check(self::PROBE),
            'need'      => $need,
            'back'      => $back,
            'cookie'    => Guard::COOKIE,
            'diag'      => isset($_GET['diag']) ? Guard::diagnose(self::PROBE) : null,
            'policies'  => self::policies(),
            'routes'    => $rows,
            'endpoints' => $endpoints,
        ];
    }

    /**
     * 端点 → 展示行（href 不含令牌）
     *
     * @param array<string, array<string, mixed>> $endpoints Registry::list() 结果
     * @return array<int, array<string, mixed>>
     */
    protected static function rows(array $endpoints): array
    {
        $rows = [];
        foreach ($endpoints as $path => $item) {
            $path = trim((string) $path, '/');
            if ($path === '') {
                continue;
            }
            $public = !in_array($path, self::AUTH_TOKEN_REQUIRED, true);

            $rows[] = [
                'uri'       => $path,
                'route'     => (string) ($item['handler'] ?? ''),
                'type'      => (string) ($item['type'] ?? 'optional'),
                'enabled'   => (bool) ($item['enabled'] ?? false),
                'auth'      => $public ? 'public' : 'token',
                'auth_note' => $public
                    ? '免令牌：只列路径，不含业务数据'
                    : '需要令牌（页面输入后存 cookie，不进 URL）',
                'href'      => '/' . $path,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['uri'], $b['uri']));

        return $rows;
    }

    /**
     * 是否输出 HTML 视图
     *
     * @return bool
     */
    protected static function wantsHtml(): bool
    {
        $view = strtolower(trim((string) ($_GET['view'] ?? '')));
        if ($view === 'html') {
            return true;
        }
        if ($view === 'json') {
            return false;
        }
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    /**
     * 授权策略说明
     *
     * @return array<string, string>
     */
    protected static function policies(): array
    {
        return [
            'public' => '免令牌端点（sys/url 自身）：只列注册路径，不含业务数据',
            'token'  => '其余端点需要令牌：页面输入一次存 cookie；脚本可用 ?token=… 或 Authorization: Bearer …',
            'source' => '令牌来源：Sys.inc.php 的 security.token（默认 candy-sys，可在 /sys/config 修改）/ security.tokens[端点] 可覆盖单端点',
        ];
    }

    /**
     * 渲染可视化页面（自包含，不引用任何外部 CDN、不加载项目模板）
     *
     * @param array<string, mixed> $data collect() 结果
     * @return string
     */
    protected static function renderHtml(array $data): string
    {
        $summary = (array) ($data['summary'] ?? []);
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $cookie = $esc($data['cookie'] ?? Guard::COOKIE);
        $info = Registry::tokenInfo();
        $tokShow = $info['is_default']
            ? '<code>' . $esc((string) $info['value']) . '</code>'
            : '<code>' . $esc(substr((string) $info['value'], 0, 4)) . '••••</code>';

        $cards = '';
        $cardMap = [
            'total'  => ['总计', '#0f172a'],
            'public' => ['免令牌', '#059669'],
            'token'  => ['需令牌', '#d97706'],
        ];
        foreach ($cardMap as $key => $meta) {
            $num = $key === 'total' ? (int) ($data['total'] ?? 0) : (int) ($summary[$key] ?? 0);
            $cards .= '<div class="card"><div class="num" style="color:' . $meta[1] . '">' . $num
                . '</div><div class="lbl">' . $esc($meta[0]) . '</div></div>';
        }

        if (!empty($data['authed'])) {
            $banner = '<div class="warn ok"><b>已通过令牌验证</b> —— 受保护端点可直接点开（令牌保存在 cookie 里，不经过 URL）。'
                . '<button type="button" class="btn ghost" id="clearToken">清除令牌</button>'
                . '<div style="margin-top:8px">当前令牌：' . $tokShow
                . ($info['is_default']
                    ? ' <b style="color:#b45309">· 记得修改</b>（/sys/config → Sys → security.token）'
                    : ' <span class="muted">· 已自定义</span>')
                . '</div></div>';
        } else {
            $banner = '<div class="warn"><b>受保护端点需要令牌</b> —— 直接点下面的链接会自动跳到令牌输入页 <code>/sys/auth</code>，验证通过后自动跳回。命令式调用可用 <code>?token=…</code> 或 <code>Authorization: Bearer …</code>。输入框已移到独立页面，本页不再收集令牌。</div>';
        }

        $badge = static function (string $auth): string {
            return $auth === 'public'
                ? '<span class="badge b-pub">免令牌</span>'
                : '<span class="badge b-tok">需令牌</span>';
        };

        $rows = '';
        foreach ((array) ($data['routes'] ?? []) as $r) {
            $href = (string) ($r['href'] ?? ('/' . (string) ($r['uri'] ?? '')));
            $rows .= '<tr>'
                . '<td class="mono uri"><a href="' . $esc($href) . '" target="_blank" rel="noopener"'
                . ' title="新标签页打开">/' . $esc($r['uri'] ?? '') . '</a></td>'
                . '<td class="mono small">' . $esc($r['route'] ?? '') . '</td>'
                . '<td class="c">' . $badge((string) ($r['auth'] ?? 'token')) . '</td>'
                . '<td class="small muted">' . $esc($r['auth_note'] ?? '') . '</td>'
                . '</tr>';
        }

        $diagHtml = '';
        if (!empty($data['diag'])) {
            $d = (array) $data['diag'];
            $rowsD = '';
            foreach ($d as $k => $v) {
                if (is_array($v)) {
                    $v = implode(', ', array_map('strval', $v));
                }
                $v = is_bool($v) ? ($v ? '是' : '否') : (string) $v;
                $rowsD .= '<tr><th>' . $esc($k) . '</th><td class="mono small">' . $esc($v) . '</td></tr>';
            }
            $diagHtml = '<div class="pol"><h2>令牌诊断（?diag=1）</h2>'
                . '<table class="diag"><tbody>' . $rowsD . '</tbody></table></div>';
        }

        $policies = '';
        foreach ((array) ($data['policies'] ?? []) as $key => $text) {
            $label = $key === 'public' || $key === 'token'
                ? $badge($key)
                : '<span class="badge b-route">令牌来源</span>';
            $policies .= '<li>' . $label . ' ' . $esc($text) . '</li>';
        }

        $tpl = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>框架入口 URL · /sys/url</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:28px 32px 60px;background:#f1f5f9;color:#0f172a;
 font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
h1{margin:0 0 4px;font-size:20px;font-weight:650;letter-spacing:.2px}
.sub{color:#64748b;font-size:13px;margin-bottom:16px}
.sub code{background:#e2e8f0;padding:1px 6px;border-radius:4px;font-size:12px}
.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;
 padding:10px 14px;font-size:12.5px;margin-bottom:16px;line-height:1.7}
.warn.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.warn b{font-weight:650}
.warn code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px;font-size:12px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px}
input[type=password]{width:320px;max-width:100%;padding:7px 11px;border:1px solid #fcd34d;border-radius:8px;
 font-size:13px;outline:none;background:#fff;font-family:ui-monospace,Menlo,Consolas,monospace}
input[type=password]:focus{border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.15)}
.btn{padding:7px 14px;border:1px solid #d97706;background:#d97706;color:#fff;border-radius:8px;
 font-size:13px;cursor:pointer;font-weight:600}
.btn:hover{background:#b45309;border-color:#b45309}
.btn.ghost{background:transparent;border-color:#a7f3d0;color:#065f46;margin-left:8px}
.btn.ghost:hover{background:rgba(6,95,70,.08)}
.tip{color:#a16207;font-size:11.5px}
.cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 18px;min-width:104px;
 box-shadow:0 1px 2px rgba(15,23,42,.04)}
.card .num{font-size:22px;font-weight:700;line-height:1.2}
.card .lbl{color:#64748b;font-size:12px;margin-top:2px}
.bar{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
input[type=search]{flex:1 1 260px;max-width:420px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;
 font-size:13px;outline:none;background:#fff}
input[type=search]:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.14)}
.hint{color:#94a3b8;font-size:12px}
.wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:auto;
 box-shadow:0 1px 2px rgba(15,23,42,.04)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{position:sticky;top:0;background:#f8fafc;color:#64748b;font-weight:600;text-align:left;
 padding:10px 12px;border-bottom:1px solid #e2e8f0;white-space:nowrap;font-size:12px}
td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:#f5f8ff}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.mono.uri{white-space:nowrap;font-weight:600}
.mono.uri a{color:#1d4ed8;text-decoration:none;border-bottom:1px dashed rgba(29,78,216,.4)}
.mono.uri a:hover{color:#1e40af;border-bottom-style:solid;border-bottom-color:#1e40af}
.small{font-size:12px}
.muted{color:#64748b}
.c{text-align:center;white-space:nowrap}
.badge{display:inline-block;border-radius:999px;padding:1px 9px;font-size:11px;font-weight:600;white-space:nowrap}
.b-pub{background:#ecfdf5;color:#059669}
.b-tok{background:#fffbeb;color:#d97706}
.b-route{background:#f1f5f9;color:#64748b}
.ok{color:#059669;font-weight:700}
.no{color:#dc2626;font-weight:700}
.pol{margin:20px 0 0;padding:14px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:10px}
.pol h2{margin:0 0 8px;font-size:13px;color:#475569;font-weight:650}
.pol ul{margin:0;padding-left:18px}
.pol li{margin:4px 0;font-size:12px;color:#475569}
</style>
</head>
<body>
<h1>框架入口 URL</h1>
<div class="sub">数据源 <code>Candy\Extend\Tools\Endpoint\Registry</code> · 只列端点注册表，不含业务路由 ·
 生成于 __TIME__ · <a href="?view=json" style="color:#2563eb;text-decoration:none">查看 JSON</a></div>
__BANNER__
__DIAG__
<div class="cards">__CARDS__</div>
<div class="bar">
  <input type="search" id="q" placeholder="按路径 / 处理类 / 授权 / 说明过滤…">
  <span class="hint">共 __TOTAL__ 条</span>
</div>
<div class="wrap">
<table>
<thead><tr>
<th>路径</th><th>处理类::方法</th><th class="c">授权</th><th>说明</th>
</tr></thead>
<tbody id="tb">__ROWS__</tbody>
</table>
</div>
<div class="pol"><h2>授权策略</h2><ul>__POLICIES__</ul></div>
<script>
(function(){
  var C='__COOKIE__',BACK=__BACK__;
  function setTok(v){
    document.cookie=C+'='+encodeURIComponent(v)+';path=/;max-age=86400;SameSite=Lax';
    if(BACK){location.href=BACK;}else{location.reload();}
  }
  var save=document.getElementById('saveToken'),inp=document.getElementById('tokInput');
  if(save&&inp){
    save.addEventListener('click',function(){var v=(inp.value||'').trim();if(!v){inp.focus();return;}setTok(v);});
    inp.addEventListener('keydown',function(e){if(e.key==='Enter'){var v=(inp.value||'').trim();if(v){setTok(v);}}});
  }
  var clr=document.getElementById('clearToken');
  if(clr){clr.addEventListener('click',function(){document.cookie=C+'=;path=/;max-age=0;SameSite=Lax';location.reload();});}
  var q=document.getElementById('q'),tb=document.getElementById('tb');
  if(q&&tb){
    q.addEventListener('input',function(){
      var v=q.value.trim().toLowerCase();
      Array.prototype.forEach.call(tb.rows,function(tr){
        tr.style.display=(!v||tr.textContent.toLowerCase().indexOf(v)>-1)?'':'none';
      });
    });
  }
})();
</script>
</body>
</html>
HTML;

        return str_replace(
            ['__BANNER__', '__DIAG__', '__CARDS__', '__ROWS__', '__POLICIES__', '__TOTAL__', '__TIME__', '__COOKIE__', '__BACK__'],
            [
                $banner,
                $diagHtml,
                $cards,
                $rows,
                $policies,
                (string) (int) ($data['total'] ?? 0),
                date('Y-m-d H:i:s'),
                $cookie,
                (string) json_encode((string) ($data['back'] ?? '/sys/url?view=html'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            $tpl
        );
    }
}
