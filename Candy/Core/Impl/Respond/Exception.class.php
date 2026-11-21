<?php
/***
 * Candy框架 Exception错误处理
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2019-08-01 23:42:51 $   
 */
 
declare(strict_types=1);
namespace Candy\Core\Impl\Respond;

use \Candy\Core\Request;
use \Candy\Core\Respond;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Exception extends Respond
{
    /**
     *   construct
     * @param mixed $type
     * @param mixed $msg
     * @return mixed
     */
    public function __construct(string $type, array $msg)
    {
        [$msg, $detailed, $etype, $die] = $msg;
        $code = 200;
        if (in_array($type, ['halt', 'shutdown'], true)) {
            $this->skipDebugBar = true;
        }
        $apiFormat = $this->apiFormat();
        if ($apiFormat !== null) {
            $content = $this->apiError($msg, $detailed, $apiFormat);
        } else {
            $content = match($type){
                'error' => $this->error($msg),
                'firewall' => (function() use ($msg, $die, &$code) {
                    $code = 403;
                    return $this->firewall($msg, $die);
                })(),
                'halt' => $this->halt($msg, $detailed),
                'report' => $this->report($msg, $detailed),
                'shutdown' => $this->shutdown($msg, $detailed, $etype),
                default => $this->shutdown($msg, $detailed, $etype),
            };
        }
        
        $this->init($content, $code);
        $this->send();

        //是否退出
        if(in_array($type, ['halt', 'shutdown'])){
            stop();
        }
    }

    /**
     * 获取当前 API 响应格式
     *
     * 路由尚未完成时 C('API') 可能还没有写入，因此同时识别 URL 后缀。
     */
    private function apiFormat(): ?string
    {
        $format = strtolower(trim((string) C('API')));
        if ($format !== '') {
            return $format;
        }

		$server = I('server.', [], '');
		$path = (string) ($server['PATH_INFO'] ?? '');
		if ($path === '' && isset($server['REQUEST_URI'])) {
			$path = (string) parse_url((string) $server['REQUEST_URI'], PHP_URL_PATH);
        }
        if (preg_match('/\.([a-z0-9_-]+)$/i', $path, $matches) === 1) {
            $suffix = strtolower($matches[1]);
            if (in_array($suffix, ['json', 'xml'], true)) {
                return $suffix;
            }
        }

        // 框架端点用 ?view=json / ?format=json 这种查询参数，没有路径后缀
        $query = I('get.', [], '');
        if (is_array($query)) {
            $qf = strtolower(trim((string) ($query['view'] ?? ($query['format'] ?? ''))));
            if (in_array($qf, ['json', 'xml'], true)) {
                return $qf;
            }
        }

        // 带着 XMLHttpRequest 头的，一律按 JSON 回，别把 HTML 错误页丢给前端
        $xrw = strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') {
            return 'json';
        }

        $accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && str_contains($accept, 'application/json')) {
            return 'json';
        }

        return null;
    }

    /**
     * 生成统一的 API 错误响应，复用当前格式及自定义格式化钩子
     */
    private function apiError(mixed $msg, mixed $detailed, string $format): string
    {
        $raw = is_string($detailed) && $detailed !== ''
            ? $detailed
            : (is_scalar($msg) ? (string)$msg : '系统服务异常');
        $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = trim(preg_replace('/\s+/u', ' ', convertEncoding($raw)) ?? '');

        // 把「文件 / 行号 / 说明 / 堆栈」从详情里拆出来，别再压成一坨给前端
        $file = '';
        if (preg_match('#(/[^\s<>]+\.php)#u', $raw, $fm)) {
            $file = $fm[1];
        }
        $line = 0;
        if (preg_match('/行号\D{0,6}(\d{1,7})/u', $raw, $lm) || preg_match('/line\D{0,3}(\d{1,7})/i', $raw, $lm)) {
            $line = (int) $lm[1];
        }
        $core = $raw !== '' ? $raw : '系统服务异常';
        if (preg_match('/说明(.+?)(调用堆栈|$)/u', $raw, $mm)) {
            $core = trim($mm[1]);
        }
        if ($core === '' || mb_strlen($core, 'UTF-8') > 400) {
            $core = mb_substr($core !== '' ? $core : '系统服务异常', 0, 400, 'UTF-8');
        }
        $trace = [];
        if (preg_match_all('/#\d+\s+[^#]+/u', $raw, $tm)) {
            foreach (array_slice($tm[0], 0, 20) as $one) {
                $trace[] = trim($one);
            }
        }
        $hint = strip_tags($this->hintFor($raw));
        $hint = trim(preg_replace('/\s+/u', ' ', $hint) ?? '');

        $data = [
            'code' => 9999,
            'message' => $core,
            'sessionid' => \session_id(),
        ];
        if ($file !== '') {
            $data['file'] = $file;
        }
        if ($line > 0) {
            $data['line'] = $line;
        }
        if ($hint !== '') {
            $data['hint'] = $hint;
        }
        if ($trace !== []) {
            $data['trace'] = $trace;
        }
        $this->contentType = match ($format) {
            'json' => 'application/json',
            'xml' => 'application/xml',
            default => 'application/' . preg_replace('/[^a-z0-9.+_-]/i', '', $format),
        };

        return formatApiResponseData($data, $format);
    }

    /**
     * Error
     * @param mixed $msg
     * @return string
     */
	private function error(string $msg = '操作失败'): string
	{
		$server = I('server.', [], '');
		$content = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
		if(empty($server['HTTP_REFERER'])){
			$content .= '<meta http-equiv="refresh" content="5;url='. ($server['HTTP_REFERER'] ?? '') .'" />';
        }
        $content .= '<title>'. $msg .'</title><style>html,body,div,h1,*{margin:0;padding:0}body{background-color:#fefefe;color:#333}.box{width:580px;margin:0 auto}h1{font-size:20px;text-align:center;background:url(data:image/png;base64,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) no-repeat top center;padding-top:160px;margin-top:30%;font-weight:normal}</style></head><body><div class="box"><h1>'. $msg .'</h1></div></body></html>';
        return $content;
    }

    /**
     * Firewall
     * @param mixed $msg
     * @param mixed $die
     * @return string
     */
    private function firewall(string $msg = '操作失败',bool $die = false): string
    {
        $content = '<html><head><meta charset="utf-8"><title>网络防护盾1.0/403</title></head><style>*{background:#FFF;color:#b4bccc;margin:auto;font-size:16px;font-family:"微软雅黑"}.box{width:500px;max-width:92vw;margin:100px auto 0;padding:0 16px;box-sizing:border-box}#dun{width:200px;height:220px;margin:auto;background:#FFF;position:relative}#dun:after{content:"";width:98px;height:220px;background:#fff;position:absolute;left:0;transform:skew(20deg) rotate(20deg);box-shadow:-3px 2px 5px #CCC}#dun:before{content:"";width:97px;height:220px;background:#f9f9f9;position:absolute;right:0;transform:skew(-20deg) rotate(-20deg);box-shadow:3px 2px 5px #CCC}#dun a{width:200px;line-height:220px;font-size:80px;color:#b4bccc;font-weight:bold;text-shadow:0 2px 0 #999;position:absolute;z-index:9;background:0;text-align:center;opacity:.3;filter:alpha(opacity=30)}.content-divide{width:100%;margin-top:50px;border-bottom:1px solid #edf2fc}.txtc{padding-top:20px}.txth{line-height:1.9;white-space:normal;word-break:break-word;overflow-wrap:anywhere}textarea{width:100%;line-height:1.9;border:0;box-sizing:border-box;font-family:inherit;resize:vertical;min-height:80px;background:transparent;color:inherit}</style><body><div class="box"><div id="dun"><a>盾</a></div><div class="content-divide"></div>';
        if($die){
            $safe = htmlspecialchars((string) $msg, ENT_QUOTES, 'UTF-8');
            $content .= '<div class="txtc txth" style="text-align:center;">'
                . nl2br($safe, false) . '</div>';
        }else{
            $content .= '<div class="txtc txth">抱歉：网址出现违规字符导致页面无法访问...</div><div class="txth">原因：网址有错误，请检查地址是否完整或存在多余字符...</div><textarea class="txth">'. $msg .'</textarea>';
        }
        $content .= '</div></body></html>';
        return $content;
    }

    /**
     * Halt
     * @param mixed $msg
     * @param mixed $detailed
     * @return string
     */
    private function halt(string $msg, string $detailed): string
    {
        return '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /><title>'. $detailed .'</title><style>*{padding:0;margin:0;}#full{background:#fff;font-family:]\'微软雅黑\';color:#333;font-size:16px;}#msg{text-align:center;width:750px;margin:100px auto 0;overflow:hidden;word-break:keep-all;word-wrap:break-word;}#title{font-size:100px;}#body{font-size:40px;}#footer{font-size:14px;text-align:right;color:#999;}#footer a{color:#000;text-decoration:none;}</style></head><body id="full"><div id="msg"><div id="title"> (>﹏<) </div><div id="body">'.$msg.'</div><div id="footer"><a href="'.CANDY_URL.'" target="_blank">CANDYPHP</a><sup>'.CANDY_VERSION.'</sup></div></div></body></html>';
    }

    /**
     * Report
     * @param mixed $msg
     * @param mixed $code
     * @return string
     */
    private function report(array $msg,int $code): string
    {
        if(G('always200') === null){
            http_response_code($code);
        }

        if(!empty($msg)) [$file, $line] = $msg;
        $str = '{"code":'.$code.',"status":"error","message":"{$ErrorInfo}","data":{}}';
        $errorDetail = '';
        if(!empty($file)){
            $errorDetail.=' @ Debug#the error in [ '.$file.' ] on [ '.$line.' ].';
            $log = '[error] '.$errorDetail.' @ Debug#the error in [ '.$file.' ] on [ '.$line.' ].'.' <'. strval((intval(microtime(true)*1000) - intval(gmTime() * 1000))/1000) ."s>\r\n";
            writeLog($log);
        }
        $errorDetail=str_replace('\\','/',$errorDetail);
        $errorDetail = substr(substr(json_encode(['*'=>$errorDetail],320),6),0,-2);
        $str = str_replace('{$ErrorInfo}', $errorDetail, $str);
        $str = str_replace('{$code}', (string)$code, $str);
        $this->contentType = 'application/json';
        return $str;
    }

    /**
     * Shutdown
     * @param mixed $msg
     * @param mixed $detailed
     * @param mixed $type
     * @return string
     */
    private function shutdown(string $msg, string $detailed, string $type = ''): string
    {
        $typeLabel = $type !== '' ? $type : 'PHP';
        $typeEsc = htmlspecialchars($typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $msgEsc = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $detailHtml = $detailed !== '' ? $this->renderDetailBlock($detailed) : '';
        $extraHtml = $this->diagnose($detailed, $msg);
        $candyUrl = htmlspecialchars((string)CANDY_URL, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $version = htmlspecialchars((string)CANDY_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$typeEsc} · 系统错误</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{min-height:100%}
body{min-height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",helvetica,arial,sans-serif;background:#eef2f7;color:#334155;line-height:1.6;padding:0 24px}
.page{max-width:1280px;margin:64px auto 120px;display:flex;flex-direction:column;background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 2px 16px rgba(15,23,42,.05)}
@media (max-width:640px){body{padding:0 14px}.page{margin:40px auto 80px;border-radius:8px}}
.page-head{padding:28px 32px 20px;border-bottom:1px solid #e2e8f0;background:#fff}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;letter-spacing:.02em;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;margin-bottom:10px}
.page-head h1{font-size:22px;font-weight:600;color:#0f172a}
.page-summary{padding:18px 32px;font-size:15px;color:#475569;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.page-detail{padding:24px 32px 40px;font-size:13px;color:#334155}
.page-detail .candy-err-table{width:100%;max-width:100%;border-collapse:collapse;margin:0}
.page-detail .candy-err-table th,.page-detail .candy-err-table td{padding:12px 14px;text-align:left;border-bottom:1px solid #f1f5f9;vertical-align:top}
.page-detail .candy-err-table th{width:80px;color:#64748b;font-weight:500;white-space:nowrap}
.page-detail .candy-err-table tr:last-child th,.page-detail .candy-err-table tr:last-child td{border-bottom:none}
.page-detail .candy-err-path,.page-detail code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;background:#f1f5f9;color:#0f172a;padding:2px 7px;border-radius:4px;word-break:break-all}
.page-detail pre.detail-pre{margin:0;padding:14px 16px;background:#0f172a;color:#e2e8f0;border-radius:8px;font-size:12px;line-height:1.55;overflow-x:auto;white-space:pre-wrap;word-break:break-word}
.page-detail .detail-html{line-height:1.7}
.page-detail .detail-html ul,.page-detail .detail-html ol{padding-left:1.4em;margin:.5em 0}
.page-detail .detail-html li{margin:.35em 0}
.page-detail .detail-html pre{margin:8px 0 0;padding:10px 12px;background:#f6f8fa;border:1px solid #e2e8f0;border-radius:6px;overflow-x:auto;font-size:12px}
.candy-err-trace{margin-top:20px;padding-top:18px;border-top:1px dashed #e2e8f0}
.candy-trace-title{font-size:13px;font-weight:600;color:#475569;margin:0 0 10px}
.candy-trace-steps{list-style:none;padding:0;margin:0}
.candy-trace-item{padding:10px 12px;margin:0 0 6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;line-height:1.5;word-break:break-word}
.candy-trace-no{display:inline-block;min-width:28px;color:#94a3b8;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-weight:600}
.candy-trace-call{color:#0f172a;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.candy-trace-throw{color:#b91c1c;font-weight:600}
.candy-trace-at{color:#94a3b8;margin:0 2px}
.candy-diag{margin-top:20px;padding-top:18px;border-top:1px dashed #e2e8f0}
.candy-diag-t{font-size:13px;font-weight:600;color:#475569;margin:0 0 10px}
.candy-diag-hint{padding:12px 14px;margin:0 0 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;line-height:1.7;color:#92400e}
.candy-diag-hint b{color:#78350f}
.candy-src{background:#0f172a;color:#cbd5e1;border-radius:8px;padding:12px 0;overflow-x:auto;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;line-height:1.7}
.candy-src .ln{display:block;padding:0 14px;white-space:pre}
.candy-src .ln .no{display:inline-block;min-width:44px;color:#475569;user-select:none}
.candy-src .ln.hit{background:rgba(239,68,68,.16)}
.candy-src .ln.hit .no{color:#fca5a5}
.candy-src .ln.hit .cd{color:#fecaca}
.candy-copy{margin-top:10px;padding:6px 14px;font-size:12px;border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:6px;cursor:pointer;font-family:inherit}
.candy-copy:hover{background:#f8fafc;border-color:#94a3b8}
.page-foot{padding:20px 32px 28px;border-top:1px solid #e2e8f0;text-align:right;font-size:12px;color:#94a3b8;background:#fff}
.page-foot a{color:#64748b;text-decoration:none;font-weight:600}
.page-foot a:hover{color:#0f172a}
</style>
</head>
<body>
<div class="page">
<header class="page-head"><span class="badge">{$typeEsc}</span><h1>系统运行异常</h1></header>
<section class="page-summary">{$msgEsc}</section>
<section class="page-detail">{$detailHtml}{$extraHtml}</section>
<footer class="page-foot">Powered by <a href="{$candyUrl}" target="_blank" rel="noopener">CANDYPHP</a> {$version}</footer>
</div>
</body>
</html>
HTML;
    }

    /**
     * 渲染错误详情块：HTML 原样输出，纯文本包在 pre 中
     */
    private function renderDetailBlock(string $detailed): string
    {
        if ($detailed === '') {
            return '';
        }

        if ($detailed !== strip_tags($detailed)) {
            return '<div class="detail-html">' . $detailed . '</div>';
        }

        return '<pre class="detail-pre">' . htmlspecialchars($detailed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }

    /**
     * 诊断区：把「哪一行代码 / 大概什么原因 / 怎么查」直接摆在错误页上
     *
     * 只在 DEBUG 打开时输出源码上下文，避免生产泄露代码。
     *
     * @param string $detailed 详情（可能是 HTML 或纯文本）
     * @param string $msg      原始消息
     * @return string
     */
    private function diagnose(string $detailed, string $msg): string
    {
        $plain = trim(html_entity_decode(strip_tags((string) $detailed), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $hint = $this->hintFor($plain !== '' ? $plain : (string) $msg);
        $source = $this->codeContext((string) $detailed);

        if ($hint === '' && $source === '') {
            return '';
        }

        $html = '<div class="candy-diag">';
        if ($hint !== '') {
            $html .= '<div class="candy-diag-t">可能的原因与下一步</div>'
                . '<div class="candy-diag-hint">' . $hint . '</div>';
        }
        if ($source !== '') {
            $html .= '<div class="candy-diag-t">出错位置的代码</div>' . $source;
        }
        $html .= '<button type="button" class="candy-copy" onclick="(function(b){'
            . 'var t=document.querySelector(\'.page-head\').innerText+\'\n\'+'
            . 'document.querySelector(\'.page-summary\').innerText+\'\n\'+'
            . 'document.querySelector(\'.page-detail\').innerText;'
            . 'if(navigator.clipboard){navigator.clipboard.writeText(t).then(function(){'
            . 'b.textContent=\'已复制\';setTimeout(function(){b.textContent=\'复制错误信息\';},1500);});}'
            . 'else{var a=document.createElement(\'textarea\');a.value=t;document.body.appendChild(a);'
            . 'a.select();document.execCommand(\'copy\');a.remove();b.textContent=\'已复制\';}})'
            . '(this)">复制错误信息</button>';
        $html .= '</div>';
        return $html;
    }

    /**
     * 按错误特征给排查建议
     *
     * @param string $text 错误文本
     * @return string
     */
    private function hintFor(string $text): string
    {
        $t = strtolower($text);
        $rules = [
            ['syntax error', 'PHP 语法错误。常见三种：<b>数组少逗号或多个逗号</b>、<b>括号没配对</b>、<b>docblock 里嵌了 <code>*/</code> 导致注释提前闭合</b>。直接对着下面的代码看那一行。'],
            ['unmatched', '括号 / 方括号 / 花括号没配对。看下面代码那一段，数一下 <code>[ ]</code> 和 <code>( )</code>。<b>docblock 里写 <code>*/</code> 也会造成这个报错</b>。'],
            ['unexpected token', '语法错误，通常是<b>上一行少了个逗号或分号</b>，或者<b>多了一个逗号</b>。错误行号往往指向真正问题的下一行。'],
            ['undefined method', '调用了不存在的方法。三件事要查：① 方法名拼写（组件版本升级后 API 可能改名，<code>grep "function 方法名"</code> 确认）；② 是不是该用 <code>static</code> 调用却写成了实例调用；③ 类是不是用错了（同名类很常见）。'],
            ['undefined function', '调用了不存在的函数。检查：① 函数名是否拼错；② 该函数在的项目文件有没有被 require；③ CLI 与 FPM 环境不同（例如 <code>mb_*</code> 在 CLI 可能缺失）。'],
            ['undefined variable', '变量未定义。多半是拼写差异或作用域问题（<code>use</code> 少了变量）。'],
            ['undefined array key', '数组键不存在。用 <code>??</code> 兜底，或先 <code>isset</code> 判断。'],
            ['class "', '类不存在。检查：① 命名空间与文件路径是否匹配；② 自动加载前缀有没有注册；③ 文件名是否 <code>Xxx.class.php</code> 且里面只有一个类。'],
            ['call to a member function', '对 null 调用了方法。往上追一层：那个对象为什么是 null。'],
            ['failed to open stream', '文件读写失败。查路径是否存在、目录权限（<code>www</code> 用户是否有写权限）。'],
            ['permission denied', '权限不足。该项目一般要求 <code>Runtime/</code> 属主为 <code>www:www</code>、权限 <code>775</code>。'],
            ['too many connections', '数据库连接数打满。检查是否有连接未释放，或调大 <code>max_connections</code>。'],
            ['sqlstate', '数据库错误。看 SQLSTATE 代码：42P01 表不存在、23505 唯一键冲突、23502 非空列缺值、42703 列不存在。'],
            ['allowed memory size', '内存超限。先查是不是大数组/无限递归；确实需要就调 <code>memory_limit</code>。'],
            ['maximum execution time', '执行超时。先查死循环或慢查询；长任务应改成队列/常驻进程。'],
        ];
        foreach ($rules as [$needle, $tip]) {
            if (str_contains($t, $needle)) {
                return $tip;
            }
        }
        return '';
    }

    /**
     * 提取出错文件与行号，读前后几行源码（仅 DEBUG 打开时）
     *
     * @param string $detailed 详情（含「文件」「行号」）
     * @return string
     */
    private function codeContext(string $detailed): string
    {
        $debug = (defined('DEBUG') && DEBUG) || (function_exists('C') && C('DEBUG'));
        if (!$debug) {
            return '';
        }
        $text = html_entity_decode(strip_tags((string) $detailed), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/(\/[^\s"\'<>]+\.php)/u', $text, $m)) {
            return '';
        }
        $file = $m[1];
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }
        $line = 0;
        if (preg_match('/行号\D{0,6}(\d{1,7})/u', $text, $lm) || preg_match('/\bline\D{0,3}(\d{1,7})/i', $text, $lm)) {
            $line = (int) $lm[1];
        }
        if ($line <= 0) {
            return '';
        }
        $rows = @file($file);
        if (!is_array($rows)) {
            return '';
        }
        $start = max(1, $line - 5);
        $end = min(count($rows), $line + 5);
        $html = '<div class="candy-src">';
        for ($i = $start; $i <= $end; $i++) {
            $code = rtrim((string) ($rows[$i - 1] ?? ''), "\r\n");
            $code = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $hit = $i === $line ? ' hit' : '';
            $html .= '<span class="ln' . $hit . '"><span class="no">' . $i . '</span><span class="cd">'
                . ($code === '' ? ' ' : $code) . '</span></span>';
        }
        $html .= '</div>';
        return $html;
    }

}
