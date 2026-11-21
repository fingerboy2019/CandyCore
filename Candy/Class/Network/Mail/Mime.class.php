<?php
/**
 * MIME 邮件解析器
 *
 * 为什么需要单独一个：框架自带的 `Candy\Class\Network\Mail::parseMailContent()` 只解析头部 +
 * Content-Type 含 text/html 时 strip_tags，**不处理 multipart / base64 / quoted-printable /
 * 字符集转换 / 附件**。真实邮件（尤其外贸往来的国际邮件）几乎全是 multipart + base64，
 * 直接用它拿到的正文会是乱码或空。
 *
 * 本类只做「原始 MIME 文本 → 结构化数组」，不碰网络、不碰数据库，可独立测试。
 *
 * 用法：
 *   $mail = Mime::parse($rawEml);
 *   echo $mail['subject'], $mail['text'];
 *   foreach ($mail['attachments'] as $att) { ... }
 *
 * @package Candy\Extend\Network\Mail
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 16:00:00
 */
declare(strict_types=1);

namespace Candy\Extend\Network\Mail;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Mime
{
    /** 常见 charset 别名 → mbstring 认识的名字 */
    private const CHARSET_MAP = [
        'gb2312' => 'GB18030',   // GB2312 是 GB18030 子集，用它避免生僻字丢字
        'gbk'    => 'GB18030',
        'gb18030'=> 'GB18030',
        'big5'   => 'BIG-5',
        'utf8'   => 'UTF-8',
        'utf-8'  => 'UTF-8',
        'iso-8859-1' => 'ISO-8859-1',
        'latin1' => 'ISO-8859-1',
        'windows-1252' => 'Windows-1252',
        'ks_c_5601-1987' => 'UHC',
        'euc-kr' => 'UHC',
        'shift_jis' => 'SJIS-win',
        'iso-2022-jp' => 'ISO-2022-JP',
    ];

    /** 递归深度上限，防止畸形邮件把栈打穿 */
    private const MAX_DEPTH = 12;

    /**
     * 解析一封原始 MIME 邮件
     *
     * @param string $raw 原始邮件全文（含头与正文）
     * @return array{
     *   headers:array<string,string>, subject:string, from:string, from_addr:string,
     *   to:string, cc:string, date:string, message_id:string, in_reply_to:string,
     *   references:array<int,string>, text:string, html:string,
     *   attachments:array<int,array{filename:string,mime:string,content:string,size:int,inline:bool,cid:string}>,
     *   charset:string, is_multipart:bool
     * }
     */
    public static function parse(string $raw): array
    {
        $out = [
            'headers'     => [],
            'subject'     => '',
            'from'        => '',
            'from_addr'   => '',
            'to'          => '',
            'cc'          => '',
            'date'        => '',
            'message_id'  => '',
            'in_reply_to' => '',
            'references'  => [],
            'text'        => '',
            'html'        => '',
            'attachments' => [],
            'charset'     => 'UTF-8',
            'is_multipart'=> false,
        ];

        if ($raw === '') {
            return $out;
        }

        // 统一换行，但保留正文里的原始 \r\n（后面按需还原）
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        [$headersPart, $bodyPart] = self::splitHeaderBody($raw);
        $headers = self::parseHeaders($headersPart);
        $out['headers'] = $headers;

        $out['subject']     = self::decodeHeader((string) ($headers['subject'] ?? ''));
        $out['from']        = self::decodeHeader((string) ($headers['from'] ?? ''));
        $out['from_addr']   = self::extractAddress($out['from']);
        $out['to']          = self::decodeHeader((string) ($headers['to'] ?? ''));
        $out['cc']          = self::decodeHeader((string) ($headers['cc'] ?? ''));
        $out['date']        = trim((string) ($headers['date'] ?? ''));
        $out['message_id']  = trim((string) ($headers['message-id'] ?? ''), " \t<>");
        $out['in_reply_to'] = trim((string) ($headers['in-reply-to'] ?? ''), " \t<>");
        $refs = trim((string) ($headers['references'] ?? ''));
        if ($refs !== '') {
            $out['references'] = array_values(array_filter(
                preg_split('/\s+/', $refs) ?: [],
                static fn($v) => trim((string) $v, '<>') !== ''
            ));
            $out['references'] = array_map(static fn($v) => trim((string) $v, '<>'), $out['references']);
        }

        // 递归解析 body 结构
        $walk = self::walk($headers, $bodyPart, 0);
        $out['text']         = $walk['text'];
        $out['html']         = $walk['html'];
        $out['attachments']  = $walk['attachments'];
        $out['charset']      = $walk['charset'];
        $out['is_multipart'] = $walk['is_multipart'];

        // 只有 html 没有 text 时，降级出一份纯文本（便于分类/检索）
        if ($out['text'] === '' && $out['html'] !== '') {
            $out['text'] = self::htmlToText($out['html']);
        }

        return $out;
    }

    /**
     * 拆头部与正文（以第一个空行为界）
     *
     * @return array{0:string,1:string}
     */
    public static function splitHeaderBody(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return [$raw, ''];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /**
     * 解析头部（支持折行续行）
     *
     * @return array<string,string>
     */
    public static function parseHeaders(string $part): array
    {
        $headers = [];
        $current = '';
        foreach (explode("\n", $part) as $line) {
            if ($line === '') {
                continue;
            }
            // 折行：以空白开头，续到上一个头
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== '') {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            if ($name === '') {
                continue;
            }
            $headers[$name] = trim(substr($line, $pos + 1));
            $current = $name;
        }
        return $headers;
    }

    /**
     * 递归遍历 MIME 结构，收集正文与附件
     *
     * @param array<string,string> $headers 当前段头部
     * @param string               $body    当前段正文
     * @param int                  $depth   深度
     * @return array{text:string,html:string,attachments:array<int,array<string,mixed>>,charset:string,is_multipart:bool}
     */
    private static function walk(array $headers, string $body, int $depth): array
    {
        $result = ['text' => '', 'html' => '', 'attachments' => [], 'charset' => 'UTF-8', 'is_multipart' => false];
        if ($depth > self::MAX_DEPTH) {
            return $result;
        }

        $ct = (string) ($headers['content-type'] ?? 'text/plain');
        [$type, $params] = self::parseContentType($ct);
        $charset = self::normalizeCharset((string) ($params['charset'] ?? 'UTF-8'));
        $result['charset'] = $charset;

        $disposition = strtolower((string) ($headers['content-disposition'] ?? ''));
        $filename = '';
        if (isset($params['name'])) {
            $filename = self::decodeHeader((string) $params['name']);
        }
        if (preg_match('/filename\*?=(?:"([^"]*)"|([^;\s]+))/i', (string) $headers['content-disposition'] ?? '', $m)) {
            $raw = $m[1] !== '' ? $m[1] : $m[2];
            $filename = self::decodeHeader(rawurldecode(trim($raw, "\" \t")));
        }
        $isAttachment = str_contains($disposition, 'attachment') || ($filename !== '' && !str_starts_with($type, 'multipart/'));

        // multipart：按 boundary 递归
        if (str_starts_with($type, 'multipart/') && !empty($params['boundary'])) {
            $result['is_multipart'] = true;
            foreach (self::splitMultipart($body, (string) $params['boundary']) as $section) {
                [$sectionHeadersPart, $sectionBody] = self::splitHeaderBody($section);
                $sub = self::parseHeaders($sectionHeadersPart);
                $subRes = self::walk($sub, $sectionBody, $depth + 1);
                if ($subRes['text'] !== '' && $result['text'] === '') {
                    $result['text'] = $subRes['text'];
                }
                if ($subRes['html'] !== '' && $result['html'] === '') {
                    $result['html'] = $subRes['html'];
                }
                $result['attachments'] = array_merge($result['attachments'], $subRes['attachments']);
            }
            return $result;
        }

        // 叶子节点：先解码传输编码
        $decoded = self::decodeTransfer($body, (string) ($headers['content-transfer-encoding'] ?? ''));

        if ($isAttachment) {
            $cid = trim((string) ($headers['content-id'] ?? ''), " \t<>");
            $result['attachments'][] = [
                'filename' => $filename !== '' ? $filename : ('attachment-' . substr(md5($decoded), 0, 8)),
                'mime'     => $type,
                'content'  => $decoded,
                'size'     => strlen($decoded),
                'inline'   => !str_contains($disposition, 'attachment'),
                'cid'      => $cid,
            ];
            return $result;
        }

        // 正文
        if ($type === 'text/html') {
            $result['html'] = self::toUtf8($decoded, $charset);
        } elseif ($type === 'text/plain' || $type === '') {
            $result['text'] = self::toUtf8($decoded, $charset);
        } else {
            // 其它类型（如 application/ics）当附件处理，避免丢数据
            $result['attachments'][] = [
                'filename' => $filename !== '' ? $filename : ('part-' . substr(md5($decoded), 0, 8)),
                'mime'     => $type,
                'content'  => $decoded,
                'size'     => strlen($decoded),
                'inline'   => false,
                'cid'      => '',
            ];
        }

        return $result;
    }

    /**
     * 按 boundary 拆分 multipart
     *
     * @return array<int,string>
     */
    public static function splitMultipart(string $body, string $boundary): array
    {
        $delim = '--' . $boundary;
        $parts = [];
        $chunks = explode($delim, $body);
        foreach ($chunks as $i => $chunk) {
            if ($i === 0) {
                continue;                          // boundary 之前是说明文字
            }
            $chunk = (string) $chunk;
            if (str_starts_with($chunk, '--')) {
                break;                             // 结束边界
            }
            // 去掉紧随 boundary 的那一行
            $chunk = preg_replace('/^[^\n]*\n/', '', $chunk, 1) ?? $chunk;
            $parts[] = rtrim($chunk, "\n");
        }
        return $parts;
    }

    /**
     * 解析 Content-Type 与其参数
     *
     * @return array{0:string,1:array<string,string>}
     */
    public static function parseContentType(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['text/plain', []];
        }
        $segments = explode(';', $value);
        $type = strtolower(trim(array_shift($segments) ?? 'text/plain'));
        $params = [];
        foreach ($segments as $seg) {
            $pos = strpos($seg, '=');
            if ($pos === false) {
                continue;
            }
            $k = strtolower(trim(substr($seg, 0, $pos)));
            $v = trim(substr($seg, $pos + 1));
            if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
                $v = substr($v, 1, -1);
            }
            $params[$k] = $v;
        }
        return [$type, $params];
    }

    /**
     * 解码传输编码
     */
    public static function decodeTransfer(string $body, string $encoding): string
    {
        $enc = strtolower(trim($encoding));
        if ($enc === 'base64') {
            $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '';
            $decoded = base64_decode($clean, true);
            return $decoded === false ? $body : $decoded;
        }
        if ($enc === 'quoted-printable') {
            return quoted_printable_decode($body);
        }
        return $body;
    }

    /**
     * 解码 RFC2047 头部（=?UTF-8?B?...?= / ?Q?）
     */
    public static function decodeHeader(string $value): string
    {
        if ($value === '' || !str_contains($value, '=?')) {
            return $value;
        }
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }
        return (string) preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            static function (array $m): string {
                $charset = self::normalizeCharset($m[1]);
                $text = $m[2] === 'B' || $m[2] === 'b'
                    ? (string) base64_decode($m[3], true)
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                return self::toUtf8($text, $charset);
            },
            $value
        );
    }

    /**
     * 规范化字符集名
     */
    public static function normalizeCharset(string $charset): string
    {
        $c = strtolower(trim($charset, " \t\"'"));
        if ($c === '') {
            return 'UTF-8';
        }
        return self::CHARSET_MAP[$c] ?? strtoupper($charset);
    }

    /**
     * 转 UTF-8（非法字节替换，不抛异常）
     */
    public static function toUtf8(string $text, string $charset): string
    {
        $charset = self::normalizeCharset($charset);
        if ($text === '' || strtoupper($charset) === 'UTF-8') {
            return self::scrubUtf8($text);
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if (is_string($converted)) {
                return self::scrubUtf8($converted);
            }
        }
        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                return self::scrubUtf8($converted);
            }
        }
        return self::scrubUtf8($text);
    }

    /**
     * 清理非法 UTF-8 字节（否则 json_encode 会整体失败）
     */
    public static function scrubUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_convert_encoding')) {
            return (string) @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return (string) preg_replace('/[\x00-\x08\x10-\x1F]/', '', $text);
    }

    /**
     * 从 From 头提取纯邮箱
     */
    public static function extractAddress(string $from): string
    {
        if (preg_match('/<([^<>]+@[^<>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }
        if (preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $from, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    /**
     * HTML 降级为纯文本（保留可读性）
     */
    public static function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6])>#i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
