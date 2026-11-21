<?php
/***
 * 切面编译期织入器
 *
 * 职责：读取源码文本 → 找出带 #[Aspect] 的方法 → 把方法体包一层切面调用 → 返回新源码。
 *
 * 实现要点：
 *   · 用 token_get_all() 定位（不用正则匹配大括号 —— 字符串里的 "}" 会骗过正则）
 *   · 方法体包装成闭包：$__ret = (function() use ($a,$b) { ...原body... })();
 *     这样能捕获所有 return 路径，after 钩子一定执行
 *   · 织入完成后把注解全名化成 \Candy\Core\Attribute\Aspect，用户源码不用写 use
 *   · 织入结果交给调用方做 php -l 自检，失败则回退原文
 *
 * 短名识别全部用「字符串前缀比较」，不用正则 —— 避免 PHP 字符串与正则的双层转义出错。
 *
 * ⚠️ 已知不支持（会跳过，不织入）：
 *   · 抽象方法 / 接口方法（没有方法体）
 *   · __construct / __destruct / __clone 等魔术方法
 *   · static 方法
 *   · 方法体内有 static 变量或 global 声明（包装成闭包会改变语义）
 *   · 返回类型为 never 的方法
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-14 11:48:00 $
 *
 * @package Candy\Core
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class AspectWeaver
{
    /** 注解全名（唯一权威类名） */
    private const FQCN = 'Candy\\Core\\Attribute\\Aspect';

    /** 可接受的注解名写法（短名 + 带/不带前导反斜杠的全名） */
    private const ATTR_NAMES = [
        'Aspect',
        '\\Aspect',
        'Candy\\Core\\Attribute\\Aspect',
        '\\Candy\\Core\\Attribute\\Aspect',
    ];

    /** 不织入的魔术方法 */
    private const SKIP_MAGIC = [
        '__construct', '__destruct', '__clone', '__get', '__set',
        '__call', '__callstatic', '__tostring', '__invoke', '__debuginfo',
    ];

    /**
     * 主入口：给源码织入切面
     *
     * @param string $code 源码
     * @return string 织入后的源码（没有切面时原样返回）
     */
    public static function weave(string $code): string
    {
        // 快速跳过
        if (strpos($code, 'Aspect') === false) {
            return $code;
        }

        $tokens = token_get_all($code);
        $targets = self::findAnnotatedMethods($tokens);
        if ($targets === []) {
            return $code;
        }

        // 从后往前处理，避免前面的改动影响后面的下标
        usort($targets, static fn($a, $b) => $b['open'] <=> $a['open']);

        foreach ($targets as $t) {
            $code = self::wrapOne($tokens, $t, $code);
            $tokens = token_get_all($code);
        }

        // 把 #[Aspect(...)] 全名化：用户源码不用 use，产物里是真实类名
        return self::qualifyAspectAttrs($code);
    }

    /**
     * 判断一段属性文本是不是 Aspect 注解（短名/全名都认）
     *
     * 用字符串前缀比较，不用正则 —— 避免转义踩坑。
     *
     * @param string $txt 例：`Aspect(X::class)` 或 `\Candy\Core\Attribute\Aspect(X::class)`
     * @return bool
     */
    public static function isAspectAttr(string $txt): bool
    {
        $rest = self::stripAttrName(ltrim(trim($txt)));
        return $rest !== null;
    }

    /**
     * 剥掉注解名，返回它后面的部分（应为 `(...)`）
     *
     * @param string $txt 已 trim 的属性文本
     * @return string|null 不是 Aspect 注解返回 null
     */
    private static function stripAttrName(string $txt): ?string
    {
        foreach (self::ATTR_NAMES as $name) {
            $len = strlen($name);
            if (strncasecmp($txt, $name, $len) !== 0) {
                continue;
            }
            $rest = substr($txt, $len);
            // 名字后面必须是左括号（允许空白）
            if (preg_match('/^\s*\(/', $rest) === 1) {
                return $rest;
            }
        }
        return null;
    }

    /**
     * 扫描所有带 #[Aspect] 的方法
     *
     * @param array $tokens token_get_all 的结果
     * @return array<int, array{name:string, open:int, close:int, aspects:array, params:array, returnType:string}>
     */
    private static function findAnnotatedMethods(array $tokens): array
    {
        $n = count($tokens);
        $out = [];

        // 先收集所有 T_ATTRIBUTE（#[）的位置及内容
        $attrs = [];
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_ATTRIBUTE) {
                continue;
            }
            $txt = '';
            $depth = 1;
            $j = $i + 1;
            for (; $j < $n; $j++) {
                $s = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($s === '[') { $depth++; }
                if ($s === ']') {
                    $depth--;
                    if ($depth === 0) { break; }
                }
                $txt .= $s;
            }
            $parsed = self::parseAspectAttr($txt);
            if ($parsed !== []) {
                $attrs[$i] = ['end' => $j, 'aspects' => $parsed];
            }
        }
        if ($attrs === []) {
            return [];
        }

        foreach ($attrs as $startIdx => $attr) {
            // 往后找它修饰的方法
            $k = $attr['end'] + 1;
            for (; $k < $n; $k++) {
                $tk = $tokens[$k];
                if (is_array($tk)) {
                    if (in_array($tk[0], [
                        T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                        T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT,
                    ], true)) {
                        continue;
                    }
                    if ($tk[0] === T_FUNCTION) {
                        break;
                    }
                    $k = $n;
                    break;
                }
                $k = $n;
                break;
            }
            if ($k >= $n) {
                continue;
            }

            // static / abstract 跳过
            $isStatic = false;
            $isAbstract = false;
            for ($b = $startIdx; $b < $k; $b++) {
                if (is_array($tokens[$b])) {
                    if ($tokens[$b][0] === T_STATIC) { $isStatic = true; }
                    if ($tokens[$b][0] === T_ABSTRACT) { $isAbstract = true; }
                }
            }
            if ($isStatic || $isAbstract) {
                continue;
            }

            // 方法名
            $nameIdx = $k + 1;
            while ($nameIdx < $n && (!is_array($tokens[$nameIdx]) || $tokens[$nameIdx][0] !== T_STRING)) {
                $nameIdx++;
            }
            if ($nameIdx >= $n) {
                continue;
            }
            $mName = $tokens[$nameIdx][1];
            if (in_array(strtolower($mName), self::SKIP_MAGIC, true)) {
                continue;
            }

            // 参数变量名
            $params = [];
            $paren = 0;
            for ($p = $nameIdx + 1; $p < $n; $p++) {
                $tk = $tokens[$p];
                if ($tk === '(') { $paren++; continue; }
                if ($tk === ')') { $paren--; if ($paren === 0) { break; } continue; }
                if ($paren > 0 && is_array($tk) && $tk[0] === T_VARIABLE) {
                    $params[] = $tk[1];
                }
            }

            // 返回类型（参数列表结束到方法体 { 之间）
            $returnType = '';
            $paren2 = 0;
            $sawColon = false;
            for ($p = $nameIdx + 1; $p < $n; $p++) {
                $tk2 = $tokens[$p];
                if ($tk2 === '(') { $paren2++; continue; }
                if ($tk2 === ')') { $paren2--; continue; }
                if ($paren2 !== 0) { continue; }
                if ($tk2 === ':') { $sawColon = true; continue; }
                if ($tk2 === '{' || $tk2 === ';') { break; }
                if ($sawColon) {
                    $returnType .= is_array($tk2) ? $tk2[1] : $tk2;
                }
            }
            $returnType = strtolower(trim($returnType));

            // never 永不正常返回，跳过
            if ($returnType === 'never') {
                continue;
            }

            // 方法体 {}
            $open = null;
            $paren = 0;
            for ($p = $nameIdx + 1; $p < $n; $p++) {
                $tk = $tokens[$p];
                if ($tk === '(') { $paren++; continue; }
                if ($tk === ')') { $paren--; continue; }
                if ($paren === 0 && $tk === '{') { $open = $p; break; }
                if ($paren === 0 && $tk === ';') { break; }
            }
            if ($open === null) {
                continue;
            }

            $d = 0;
            $close = null;
            for ($p = $open; $p < $n; $p++) {
                if ($tokens[$p] === '{') { $d++; continue; }
                if ($tokens[$p] === '}') {
                    $d--;
                    if ($d === 0) { $close = $p; break; }
                }
            }
            if ($close === null) {
                continue;
            }

            $out[] = [
                'name'       => $mName,
                'open'       => $open,
                'close'      => $close,
                'aspects'    => $attr['aspects'],
                'params'     => array_values(array_unique($params)),
                'returnType' => $returnType,
            ];
        }

        return $out;
    }

    /**
     * 解析 #[Aspect(X::class)] 或 #[Aspect(X::class, priority: 5)]
     *
     * 支持短名与全名两种注解写法。
     *
     * @param string $txt 方括号内文本（不含 #[]）
     * @return array<int, array{0:string,1:int}> [[切面类名, 优先级], ...]
     */
    private static function parseAspectAttr(string $txt): array
    {
        $rest = self::stripAttrName(ltrim(trim($txt)));
        if ($rest === null) {
            return [];
        }
        // 去掉外层括号
        if (preg_match('/^\s*\(([\s\S]*)\)\s*$/', $rest, $m) !== 1) {
            return [];
        }
        $inner = trim($m[1]);
        if ($inner === '') {
            return [];
        }

        // 拆参数（顶层逗号）
        $parts = [];
        $buf = '';
        $depth = 0;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $c = $inner[$i];
            if ($c === '(' || $c === '[') { $depth++; }
            if ($c === ')' || $c === ']') { $depth--; }
            if ($c === ',' && $depth === 0) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $parts[] = trim($buf);
        }
        if ($parts === []) {
            return [];
        }

        // 第一参：X::class / 'X' / "X"
        $clsRaw = $parts[0];
        $cls = '';
        if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class$/i', $clsRaw, $mm) === 1) {
            $cls = $mm[1];
        } elseif (preg_match('/^[\'"](.+?)[\'"]$/', $clsRaw, $mm) === 1) {
            $cls = $mm[1];
        } else {
            return [];
        }
        $cls = ltrim($cls, '\\');
        if ($cls === '') {
            return [];
        }

        // 第二参：priority
        $priority = 0;
        if (isset($parts[1]) && preg_match('/^(?:priority\s*:\s*)?(-?\d+)$/i', $parts[1], $mm) === 1) {
            $priority = (int) $mm[1];
        }

        return [[$cls, $priority]];
    }

    /**
     * 把一个方法体包一层切面调用
     *
     * @param array  $tokens
     * @param array  $t      方法信息
     * @param string $code   当前源码
     * @return string
     */
    private static function wrapOne(array $tokens, array $t, string $code): string
    {
        $offset = 0;
        $openOff = null;
        $closeOff = null;
        foreach ($tokens as $idx => $tk) {
            $len = strlen(is_array($tk) ? $tk[1] : $tk);
            if ($idx === $t['open']) { $openOff = $offset; }
            if ($idx === $t['close']) { $closeOff = $offset; break; }
            $offset += $len;
        }
        if ($openOff === null || $closeOff === null) {
            return $code;
        }

        $body = substr($code, $openOff + 1, $closeOff - $openOff - 1);

        // 含 static 变量 / global 的方法跳过（闭包会改变语义）
        if (preg_match('/\bstatic\s+\$|\bglobal\s+\$/', $body) === 1) {
            return $code;
        }

        $method = $t['name'];
        $isVoid = (($t['returnType'] ?? '') === 'void');
        $useVars = '';
        if ($t['params'] !== []) {
            $useVars = ' use (' . implode(', ', $t['params']) . ')';
        }

        $aspectArr = [];
        foreach ($t['aspects'] as $a) {
            $aspectArr[] = "['" . addslashes($a[0]) . "', " . (int) $a[1] . "]";
        }
        $aspectLit = '[' . implode(', ', $aspectArr) . ']';
        $m = addslashes($method);

        $newBody = "\n"
            . "        \\Candy\\Core\\Aspect::register(__CLASS__, '" . $m . "', " . $aspectLit . ");\n"
            . "        \$__asp = \\Candy\\Core\\Aspect::begin(__CLASS__, '" . $m . "', func_get_args(), \$this ?? null);\n"
            . "        \$__ret = \\Candy\\Core\\Aspect::call(\$__asp, function ()" . $useVars . " {" . $body . "\n        });\n"
            . ($isVoid ? "        return;\n" : "        return \$__ret;\n")
            . "    ";

        return substr($code, 0, $openOff + 1) . $newBody . substr($code, $closeOff);
    }

    /**
     * 把 #[Aspect(...)] 全名化（已经是全名的原样保留）
     *
     * @param string $code
     * @return string
     */
    private static function qualifyAspectAttrs(string $code): string
    {
        $tokens = token_get_all($code);
        $n = count($tokens);
        $out = '';

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_ATTRIBUTE) {
                $out .= is_array($t) ? $t[1] : $t;
                continue;
            }

            // 找配对 ]
            $txt = '';
            $depth = 1;
            $j = $i + 1;
            for (; $j < $n; $j++) {
                $s = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($s === '[') { $depth++; }
                if ($s === ']') {
                    $depth--;
                    if ($depth === 0) { break; }
                }
                $txt .= $s;
            }

            $trimmed = ltrim(trim($txt));
            $rest = self::stripAttrName($trimmed);

            if ($rest === null) {
                // 不是 Aspect，原样输出整段
                for ($k = $i; $k <= $j && $k < $n; $k++) {
                    $out .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                }
                $i = $j;
                continue;
            }

            // 已经是全名？
            // 统一写成全名 —— stripAttrName 已剥离原注解名（短名/全名都行），
            // $rest 就是 "(" 开头的参数部分
            $out .= $t[1] . '\\' . self::FQCN . $rest . ']';
            
            $i = $j;
        }

        return $out;
    }
}
