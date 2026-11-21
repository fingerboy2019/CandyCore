<?php
/***
 * 生成器字段映射 Trait
 *
 * 将表字段转为 Layui 列表列、搜索表单、Model 筛选与 normalizePayload 片段
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 15:42:06 $
 *
 * @package Candy\Core\Trait\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Generator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait FieldMapper
{
    /**
     * 生成列表中默认跳过的字段
     *
     * @return array<int, string>
     */
    protected static function generatorSkipListFields(): array
    {
        return ['userpass', 'usersalt', 'userlockpass', 'password', 'repassword', 'content', 'body', 'remark'];
    }

    /**
     * 列表搜索表单中默认跳过的字段
     *
     * @return array<int, string>
     */
    protected static function generatorSkipSearchFields(): array
    {
        return array_merge(self::generatorSkipListFields(), [
            'cover', 'summary', 'slug', 'views',
            'createtime', 'updatetime', 'create_time', 'update_time',
            'published_at', 'deleted_at', 'deletetime', 'delete_time',
        ]);
    }

    /**
     * 写入时默认跳过的字段
     *
     * @return array<int, string>
     */
    protected static function generatorSkipWriteFields(): array
    {
        return ['createtime', 'updatetime', 'create_time', 'update_time', 'deleted_at', 'deletetime', 'delete_time'];
    }

    /**
     * 表单中默认跳过的字段
     *
     * 不含 content/body（列表可隐藏，表单需编辑）；时间字段由系统自动写入
     *
     * @return array<int, string>
     * @access protected
     * @static
     */
    protected static function generatorSkipFormFields(): array
    {
        return array_merge(self::generatorSkipWriteFields(), [
            'userpass', 'usersalt', 'userlockpass', 'password', 'repassword',
        ]);
    }

    /**
     * 检测软删除时间字段
     *
     * @param array<int, array<string, mixed>> $columns
     * @return string|null
     */
    protected static function detectSoftDeleteField(array $columns): ?string
    {
        $candidates = ['deleted_at', 'deletetime', 'delete_time', 'deleteTime'];
        $fields = array_map(static fn(array $col): string => (string)($col['field'] ?? ''), $columns);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 是否为上传类字段（封面、头像、附件等）
     *
     * 优先看设计器 control；否则按字段名启发式匹配。
     *
     * @param string $field 字段名
     * @param string $sqlType SQL 类型
     * @param array<string, mixed>|null $optionMeta 设计器元数据
     * @return bool
     */
    protected static function isUploadField(string $field, string $sqlType, ?array $optionMeta = null): bool
    {
        $control = is_array($optionMeta) ? strtolower(trim((string) ($optionMeta['control'] ?? ''))) : '';
        if (in_array($control, ['upload_image', 'upload_file'], true)) {
            return true;
        }
        if (!preg_match('/varchar|char|text/i', $sqlType)) {
            return false;
        }

        return (bool) preg_match(
            '/^(cover|avatar|icon|image|img|thumb|thumbnail|logo|pic|photo|file|attachment|attach|banner|upload_image|upload_file|upload)(_|$)/i',
            $field
        ) || (bool) preg_match('/(cover|avatar|icon|image|img|logo|thumb|banner|attachment)$/i', $field)
            || (bool) preg_match('/^upload_(image|file|img)/i', $field);
    }

    /**
     * 解析上传控件类型（图片 / 文件）
     *
     * @param string $field 字段名
     * @param array<string, mixed>|null $optionMeta
     * @return string upload_image|upload_file
     * @access protected
     * @static
     */
    protected static function resolveUploadControl(string $field, ?array $optionMeta = null): string
    {
        $control = is_array($optionMeta) ? strtolower(trim((string) ($optionMeta['control'] ?? ''))) : '';
        if ($control === 'upload_file') {
            return 'upload_file';
        }
        if ($control === 'upload_image') {
            return 'upload_image';
        }
        if (preg_match('/file|attach|attachment|pdf|zip/i', $field) && !preg_match('/image|img|cover|avatar|logo|thumb|banner|photo/i', $field)) {
            return 'upload_file';
        }

        return 'upload_image';
    }

    /**
     * 是否为富文本字段（长文本 + 语义字段名）
     *
     * @param string $field 字段名
     * @param string $sqlType SQL 类型
     * @return bool
     */
    protected static function isRichTextField(string $field, string $sqlType): bool
    {
        if (!preg_match('/\b(text|mediumtext|longtext)\b/i', $sqlType)) {
            return false;
        }

        return in_array(strtolower($field), ['content', 'body', 'detail', 'html', 'richtext', 'description'], true)
            || (bool)preg_match('/^(content|body|detail|html|richtext)/i', $field);
    }

    /**
     * 解析 laydate 类型（date / datetime / time）
     *
     * 优先设计器 control；否则按 SQL 类型推断。
     * 返回 null 表示非时间字段。
     * 生成 input 须带 data-date-input，供 team.js 调用 laydate.render。
     *
     * @param string $sqlType SQL 类型
     * @param array<string, mixed>|null $optionMeta 设计器字段元数据（可含 control）
     * @return string|null date|datetime|time
     * @access protected
     * @static
     */
    protected static function resolveLaydateType(string $sqlType, ?array $optionMeta = null): ?string
    {
        $control = is_array($optionMeta) ? strtolower(trim((string) ($optionMeta['control'] ?? ''))) : '';
        if (in_array($control, ['date', 'datetime', 'time'], true)) {
            return $control;
        }

        $type = strtolower(trim($sqlType));
        if ($type === '') {
            return null;
        }
        if (preg_match('/datetime|timestamp/', $type)) {
            return 'datetime';
        }
        // 纯 date（避免匹配 datetime 已在上方处理）
        if (preg_match('/^date\b/', $type) || $type === 'date') {
            return 'date';
        }
        // 纯 time（非 datetime）
        if (preg_match('/^time\b/', $type) || $type === 'time') {
            return 'time';
        }

        return null;
    }

    /**
     * 解析字段展示标签
     *
     * 优先 label（设计器覆盖），其次库 comment，最后回退字段名
     *
     * @param array<string, mixed> $column 字段元数据
     * @return string
     * @access protected
     * @static
     */
    protected static function resolveColumnLabel(array $column): string
    {
        $field = (string) ($column['field'] ?? '');
        foreach (['label', 'comment'] as $key) {
            $value = trim((string) ($column[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $field;
    }

    /**
     * 将 columnLabels 覆盖写入列元数据
     *
     * @param array<int, array<string, mixed>> $columns
     * @param mixed $labels field => 中文标签
     * @return array<int, array<string, mixed>>
     * @access protected
     * @static
     */
    protected static function applyColumnLabelOverrides(array $columns, mixed $labels): array
    {
        if (!is_array($labels) || $labels === []) {
            return $columns;
        }

        $map = [];
        foreach ($labels as $name => $label) {
            $key = strtolower(trim((string) $name));
            $text = trim((string) $label);
            if ($key !== '' && $text !== '') {
                $map[$key] = $text;
            }
        }
        if ($map === []) {
            return $columns;
        }

        foreach ($columns as &$column) {
            $field = strtolower((string) ($column['field'] ?? ''));
            if ($field === '' || !isset($map[$field])) {
                continue;
            }
            $column['label'] = $map[$field];
            if (trim((string) ($column['comment'] ?? '')) === '') {
                $column['comment'] = $map[$field];
            }
        }
        unset($column);

        return $columns;
    }

    /**
     * 构建 Layui 表格列 JS 片段
     *
     * 若 pageLayout.list.columns 有值，严格按设计器列顺序与宽度生成；
     * 否则回退为库表字段顺序（最多 8 列）。
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @param array<string, array<string, mixed>> $fieldOptions 设计器选项源
     * @param array<string, mixed> $pageLayout 设计器布局（list.columns / widths / sortable）
     * @return string
     */
    protected static function buildListColsJs(
        array $columns,
        string $pk,
        array $fieldOptions = [],
        array $pageLayout = []
    ): string {
        $skip = array_merge(self::generatorSkipListFields(), array_filter([self::detectSoftDeleteField($columns)]));
        $byField = [];
        foreach ($columns as $column) {
            $field = (string) ($column['field'] ?? '');
            if ($field !== '') {
                $byField[$field] = $column;
            }
        }

        $listLayout = is_array($pageLayout['list'] ?? null) ? $pageLayout['list'] : [];
        $designCols = [];
        if (is_array($listLayout['columns'] ?? null)) {
            foreach ($listLayout['columns'] as $name) {
                $n = strtolower(trim((string) $name));
                if ($n !== '' && !in_array($n, $designCols, true)) {
                    $designCols[] = $n;
                }
            }
        }
        $widths = is_array($listLayout['widths'] ?? null) ? $listLayout['widths'] : [];
        $sortable = is_array($listLayout['sortable'] ?? null) ? $listLayout['sortable'] : [];

        $ordered = [];
        if ($designCols !== []) {
            foreach ($designCols as $name) {
                if (!isset($byField[$name])) {
                    continue;
                }
                if (in_array($name, $skip, true) && $name !== $pk) {
                    continue;
                }
                $ordered[] = $byField[$name];
            }
        } else {
            foreach ($columns as $column) {
                $field = (string) ($column['field'] ?? '');
                if ($field === '' || in_array($field, $skip, true)) {
                    continue;
                }
                $ordered[] = $column;
                if (count($ordered) >= 8) {
                    break;
                }
            }
        }

        $lines = ["{type: 'checkbox', fixed: 'left'},"];
        foreach ($ordered as $column) {
            $field = (string) ($column['field'] ?? '');
            $title = addslashes(self::resolveColumnLabel($column));
            $width = isset($widths[$field]) ? (int) $widths[$field] : ($field === $pk ? 80 : 120);
            if ($width <= 0) {
                $width = 120;
            }
            $sortAttr = !empty($sortable[$field]) ? ', sort: true' : '';
            $opt = $fieldOptions[$field] ?? null;
            if (is_array($opt) && ($opt['optionSource'] ?? '') !== '') {
                if ($field === 'status') {
                    $lines[] = "{field: '{$field}', title: '{$title}', minWidth: {$width}, align: 'center', templet: '#statusTpl'{$sortAttr}},";
                } else {
                    $lines[] = "{field: '{$field}_text', title: '{$title}', minWidth: {$width}{$sortAttr}},";
                }
            } else {
                $lines[] = "{field: '{$field}', title: '{$title}', minWidth: {$width}{$sortAttr}},";
            }
        }
        $lines[] = "{title: '操作', toolbar: '#barTpl', minWidth: 160, fixed: 'right'}";

        return implode("\n        ", $lines);
    }

    /**
     * 构建表单字段 HTML 片段
     *
     * 支持 pageLayout.form：按设计顺序输出，并按 layout.col 拼 12 栏栅格；
     * 无布局时保持原「整行堆叠」行为。
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @param array<string, array<string, mixed>> $fieldOptions 设计器选项源
     * @param array<string, mixed> $pageLayout 设计器布局
     * @return string
     */
    protected static function buildFormFieldsHtml(
        array $columns,
        string $pk,
        array $fieldOptions = [],
        array $pageLayout = []
    ): string {
        // 表单不展示密码与系统时间字段；create_time/update_time 由 Model 自动写入
        $skip = array_merge(
            self::generatorSkipFormFields(),
            [$pk],
            array_filter([self::detectSoftDeleteField($columns)])
        );
        $byField = [];
        foreach ($columns as $column) {
            $field = (string) ($column['field'] ?? '');
            if ($field !== '') {
                $byField[$field] = $column;
            }
        }

        $formLayout = is_array($pageLayout['form'] ?? null) ? $pageLayout['form'] : [];
        $order = [];
        if (is_array($formLayout['order'] ?? null)) {
            foreach ($formLayout['order'] as $name) {
                $n = strtolower(trim((string) $name));
                if ($n !== '' && !in_array($n, $order, true)) {
                    $order[] = $n;
                }
            }
        }
        $cols = is_array($formLayout['cols'] ?? null) ? $formLayout['cols'] : [];
        $hasDesignLayout = $order !== [] || $cols !== [];

        $orderedColumns = [];
        if ($order !== []) {
            foreach ($order as $name) {
                if (!isset($byField[$name]) || in_array($name, $skip, true)) {
                    continue;
                }
                $orderedColumns[] = $byField[$name];
            }
        } else {
            foreach ($columns as $column) {
                $field = (string) ($column['field'] ?? '');
                if ($field === '' || in_array($field, $skip, true)) {
                    continue;
                }
                $orderedColumns[] = $column;
                if (!$hasDesignLayout && count($orderedColumns) >= 12) {
                    break;
                }
            }
        }

        $items = [];
        $verifyDefs = [];
        foreach ($orderedColumns as $column) {
            $field = (string) ($column['field'] ?? '');
            $label = self::resolveColumnLabel($column);
            $type = strtolower((string) ($column['type'] ?? ''));
            $meta = $fieldOptions[$field] ?? null;
            $html = self::buildSingleFormField($field, $label, $type, is_array($meta) ? $meta : null);
            $html = self::decorateFormFieldUi($html, $field, is_array($meta) ? $meta : null, $verifyDefs);
            $col = 12;
            if ($hasDesignLayout) {
                $col = (int) ($cols[$field] ?? 12);
                if (!in_array($col, [3, 4, 6, 8, 12], true)) {
                    $col = 12;
                }
            }
            $items[] = ['col' => $col, 'html' => $html];
        }

        $body = self::wrapFormFieldsInGrid($items);
        $script = self::buildFormVerifyScriptHtml($verifyDefs);
        if ($script !== '') {
            $body = ($body === '' ? '' : $body . "\n") . $script;
        }

        return $body;
    }

    /**
     * 将表单域按 12 栏栅格打包成 layui-row / layui-col
     *
     * 全部为 col=12 时不包栅格，保持与旧生成物一致的整行堆叠。
     *
     * @param list<array{col:int,html:string}> $items
     * @return string
     * @access protected
     * @static
     */
    protected static function wrapFormFieldsInGrid(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $needGrid = false;
        foreach ($items as $item) {
            if ((int) ($item['col'] ?? 12) !== 12) {
                $needGrid = true;
                break;
            }
        }
        if (!$needGrid) {
            return implode("\n", array_map(static fn(array $it): string => (string) $it['html'], $items));
        }

        $rows = [];
        $current = [];
        $used = 0;
        foreach ($items as $item) {
            $col = (int) ($item['col'] ?? 12);
            if (!in_array($col, [3, 4, 6, 8, 12], true)) {
                $col = 12;
            }
            if ($used > 0 && $used + $col > 12) {
                $rows[] = $current;
                $current = [];
                $used = 0;
            }
            $current[] = ['col' => $col, 'html' => (string) $item['html']];
            $used += $col;
            if ($used >= 12) {
                $rows[] = $current;
                $current = [];
                $used = 0;
            }
        }
        if ($current !== []) {
            $rows[] = $current;
        }

        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $inner = rtrim((string) $cell['html']);
                $cells[] = "            <div class=\"layui-col-xs12 layui-col-md{$cell['col']}\">\n{$inner}\n            </div>";
            }
            $out[] = "        <div class=\"layui-row layui-col-space10\">\n" . implode("\n", $cells) . "\n        </div>";
        }

        return implode("\n", $out);
    }

    /**
     * 为表单域注入必填 / placeholder / hint / lay-verify
     *
     * @param string $html 原始表单项 HTML
     * @param string $field 字段名
     * @param array<string, mixed>|null $meta fieldOptions 元数据
     * @param array<string, array<string, mixed>> $verifyDefs 收集自定义 verify（按引用写入）
     * @return string
     * @access protected
     * @static
     */
    protected static function decorateFormFieldUi(
        string $html,
        string $field,
        ?array $meta,
        array &$verifyDefs
    ): string {
        if ($html === '' || !is_array($meta)) {
            return $html;
        }

        $required = !empty($meta['required']);
        $placeholder = trim((string) ($meta['placeholder'] ?? ''));
        $hint = trim((string) ($meta['hint'] ?? ''));
        $rules = is_array($meta['rules'] ?? null) ? $meta['rules'] : [];
        $ruleType = strtolower(trim((string) ($rules['type'] ?? 'none')));

        $verifyParts = [];
        if ($required) {
            $verifyParts[] = 'required';
        }

        $safeField = preg_replace('/[^a-z0-9_]/', '', strtolower($field)) ?: 'f';
        if ($ruleType === 'phone') {
            $verifyParts[] = 'phone';
        } elseif ($ruleType === 'email') {
            $verifyParts[] = 'email';
        } elseif ($ruleType === 'zh') {
            $key = 'pd_zh_' . $safeField;
            $verifyParts[] = $key;
            $verifyDefs[$key] = ['kind' => 'zh', 'message' => trim((string) ($rules['message'] ?? '')) ?: '请输入中文'];
        } elseif ($ruleType === 'en') {
            $key = 'pd_en_' . $safeField;
            $verifyParts[] = $key;
            $verifyDefs[$key] = ['kind' => 'en', 'message' => trim((string) ($rules['message'] ?? '')) ?: '请输入英文字母'];
        } elseif ($ruleType === 'length') {
            $key = 'pd_len_' . $safeField;
            $verifyParts[] = $key;
            $verifyDefs[$key] = [
                'kind' => 'length',
                'min' => (int) ($rules['min'] ?? 0),
                'max' => (int) ($rules['max'] ?? 0),
                'message' => trim((string) ($rules['message'] ?? '')),
            ];
        } elseif ($ruleType === 'custom') {
            $pattern = trim((string) ($rules['pattern'] ?? ''));
            if ($pattern !== '') {
                $key = 'pd_re_' . $safeField;
                $verifyParts[] = $key;
                $verifyDefs[$key] = [
                    'kind' => 'custom',
                    'pattern' => $pattern,
                    'message' => trim((string) ($rules['message'] ?? '')) ?: '格式不正确',
                ];
            }
        }

        $verifyAttr = $verifyParts !== [] ? implode('|', $verifyParts) : '';

        if ($required) {
            $html = preg_replace(
                '/class="layui-form-label"/',
                'class="layui-form-label label-required"',
                $html,
                1
            ) ?? $html;
        }

        if ($verifyAttr !== '') {
            $verifyEsc = htmlspecialchars($verifyAttr, ENT_QUOTES, 'UTF-8');
            $html = preg_replace(
                '/<(input|textarea|select)(\s)/i',
                '<$1 lay-verify="' . $verifyEsc . '"$2',
                $html,
                1
            ) ?? $html;
            if ($required) {
                $html = preg_replace(
                    '/<(input|textarea|select)(\s)/i',
                    '<$1 required$2',
                    $html,
                    1
                ) ?? $html;
            }
        }

        if ($placeholder !== '' && !preg_match('/\splaceholder=/i', $html)) {
            $phEsc = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
            $html = preg_replace(
                '/<(input|textarea)(\s)/i',
                '<$1 placeholder="' . $phEsc . '"$2',
                $html,
                1
            ) ?? $html;
        }

        if ($hint !== '') {
            $hintEsc = htmlspecialchars($hint, ENT_QUOTES, 'UTF-8');
            $hintHtml = "\n                <p class=\"help-block color-desc\">{$hintEsc}</p>";
            // 插入到第一个 layui-input-block 的闭合前
            $pos = strpos($html, 'layui-input-block');
            if ($pos !== false) {
                $close = strpos($html, '</div>', $pos);
                if ($close !== false) {
                    $html = substr($html, 0, $close) . $hintHtml . "\n            " . substr($html, $close);
                }
            }
        }

        return $html;
    }

    /**
     * 生成表单自定义 layui.form.verify 脚本
     *
     * @param array<string, array<string, mixed>> $verifyDefs
     * @return string
     * @access protected
     * @static
     */
    protected static function buildFormVerifyScriptHtml(array $verifyDefs): string
    {
        if ($verifyDefs === []) {
            return '';
        }

        $lines = [];
        foreach ($verifyDefs as $key => $def) {
            $key = preg_replace('/[^a-z0-9_]/', '', (string) $key) ?: '';
            if ($key === '' || !is_array($def)) {
                continue;
            }
            $kind = (string) ($def['kind'] ?? '');
            $msg = addslashes((string) ($def['message'] ?? ''));
            if ($kind === 'zh') {
                $lines[] = "  {$key}: function(value){ if(value===''||value==null) return; if(!/^[\\u4e00-\\u9fa5]+$/.test(value)) return '{$msg}'; }";
            } elseif ($kind === 'en') {
                $lines[] = "  {$key}: function(value){ if(value===''||value==null) return; if(!/^[A-Za-z]+$/.test(value)) return '{$msg}'; }";
            } elseif ($kind === 'length') {
                $min = (int) ($def['min'] ?? 0);
                $max = (int) ($def['max'] ?? 0);
                $defaultMsg = ($min > 0 && $max > 0)
                    ? "长度须在 {$min}-{$max} 之间"
                    : ($min > 0 ? "长度不能少于 {$min}" : "长度不能超过 {$max}");
                $m = $msg !== '' ? $msg : addslashes($defaultMsg);
                $checks = [];
                if ($min > 0) {
                    $checks[] = "if(value.length < {$min}) return '{$m}';";
                }
                if ($max > 0) {
                    $checks[] = "if(value.length > {$max}) return '{$m}';";
                }
                $body = implode(' ', $checks);
                $lines[] = "  {$key}: function(value){ if(value===''||value==null) return; {$body} }";
            } elseif ($kind === 'custom') {
                $pattern = (string) ($def['pattern'] ?? '');
                // 转义正则字面量中的反斜杠与引号，供 new RegExp
                $patternJs = addcslashes($pattern, "\\'");
                $lines[] = "  {$key}: function(value){ if(value===''||value==null) return; try { if(!(new RegExp('{$patternJs}')).test(value)) return '{$msg}'; } catch(e) { return '校验规则无效'; } }";
            }
        }

        if ($lines === []) {
            return '';
        }

        return "        <script>\n(function(){ if (typeof layui === 'undefined' || !layui.form) return;\nlayui.form.verify({\n"
            . implode(",\n", $lines)
            . "\n});\n})();\n        </script>";
    }

    /**
     * 单个表单域 HTML
     *
     * @param string $field 字段名
     * @param string $label 标签
     * @param string $sqlType SQL 类型
     * @param array<string, mixed>|null $optionMeta 选项源元数据
     * @return string
     */
    protected static function buildSingleFormField(string $field, string $label, string $sqlType, ?array $optionMeta = null): string
    {
        $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $fieldEsc = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
        $control = is_array($optionMeta) ? strtolower(trim((string) ($optionMeta['control'] ?? ''))) : '';

        // 设计器控件优先：选项类（含未配选项源时的默认 static）
        if (in_array($control, ['select', 'radio', 'checkbox', 'switch'], true)) {
            $meta = is_array($optionMeta) ? $optionMeta : [];
            if (($meta['optionSource'] ?? '') === '') {
                $meta['optionSource'] = 'static';
                if (($meta['options'] ?? []) === [] && $control !== 'switch') {
                    $meta['options'] = [
                        ['value' => '1', 'label' => '选项1'],
                        ['value' => '2', 'label' => '选项2'],
                    ];
                }
            }
            $meta['control'] = $control;

            return self::buildOptionFormFieldHtml($fieldEsc, $labelEsc, $meta);
        }

        // 有选项源时走选项渲染（兼容仅 fieldOptions、无 control 的旧数据）
        if (is_array($optionMeta) && ($optionMeta['optionSource'] ?? '') !== '') {
            return self::buildOptionFormFieldHtml($fieldEsc, $labelEsc, $optionMeta);
        }

        // 图片 / 文件上传
        if (self::isUploadField($field, $sqlType, $optionMeta) || in_array($control, ['upload_image', 'upload_file'], true)) {
            return self::buildUploadFormFieldHtml($fieldEsc, $labelEsc, $field, $optionMeta);
        }

        if ($control === 'richtext' || self::isRichTextField($field, $sqlType)) {
            return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <textarea name="{$fieldEsc}" class="layui-textarea editor"><{\$vo.{$field}|default:''|htmlspecialchars_decode|raw}></textarea>
            </div>
        </div>
HTML;
        }

        if ($control === 'textarea') {
            return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <textarea name="{$fieldEsc}" class="layui-textarea" rows="3"><{\$vo.{$field}|default:''}></textarea>
            </div>
        </div>
HTML;
        }

        // 日期/时间：挂 data-date-input，由 team.js → laydate.render 提供选择器
        $laydateType = self::resolveLaydateType($sqlType, $optionMeta);
        if ($laydateType !== null) {
            $placeholder = $laydateType === 'datetime'
                ? 'YYYY-MM-DD HH:mm:ss'
                : ($laydateType === 'time' ? 'HH:mm:ss' : 'YYYY-MM-DD');
            return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <input type="text" name="{$fieldEsc}" data-date-input="{$laydateType}" value="<{\$vo.{$field}|default:''}>" class="layui-input" placeholder="{$placeholder}" autocomplete="off">
            </div>
        </div>
HTML;
        }

        if (str_contains($sqlType, 'text') && !self::isRichTextField($field, $sqlType)) {
            return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <textarea name="{$fieldEsc}" class="layui-textarea" rows="3"><{\$vo.{$field}|default:''}></textarea>
            </div>
        </div>
HTML;
        }

        if (preg_match('/tinyint\(1\)/', $sqlType) || $field === 'status') {
            return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <input type="radio" name="{$fieldEsc}" value="1" title="<{\$lang.enabled|default:'启用'}>" <{if \$vo.{$field}|default:1 == 1}>checked<{/if}>>
                <input type="radio" name="{$fieldEsc}" value="0" title="<{\$lang.disabled|default:'禁用'}>" <{if \$vo.{$field}|default:1 == 0}>checked<{/if}>>
            </div>
        </div>
HTML;
        }

        $inputType = ($control === 'number' || str_contains($sqlType, 'int') || str_contains($sqlType, 'decimal')) ? 'number' : 'text';
        return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <input type="{$inputType}" name="{$fieldEsc}" value="<{\$vo.{$field}|default:''}>" class="layui-input">
            </div>
        </div>
HTML;
    }

    /**
     * 上传类表单域 HTML
     *
     * 图片：data-upload-image + team.js uploadOneImage
     * 文件：文本框 + data-file=btn 选择上传
     *
     * @param string $fieldEsc 转义字段名
     * @param string $labelEsc 转义标签
     * @param string $field 原始字段名
     * @param array<string, mixed>|null $optionMeta
     * @return string
     * @access protected
     * @static
     */
    protected static function buildUploadFormFieldHtml(
        string $fieldEsc,
        string $labelEsc,
        string $field,
        ?array $optionMeta = null
    ): string {
        $kind = self::resolveUploadControl($field, $optionMeta);
        if ($kind === 'upload_image') {
            return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <input type="hidden" name="{$fieldEsc}" value="<{\$vo.{$field}|default:''}>" data-upload-image="1" data-type="png,jpg,jpeg,gif,webp" data-dir="image">
            </div>
        </div>
HTML;
        }

        return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                <input name="{$fieldEsc}" value="<{\$vo.{$field}|default:''}>" class="layui-input" placeholder="URL 或点击上传" autocomplete="off">
                <a class="layui-btn layui-btn-sm layui-btn-normal margin-top-5" data-file="btn" data-type="png,jpg,jpeg,gif,webp,svg,pdf,zip,doc,docx,xls,xlsx" data-field="{$fieldEsc}" href="javascript:;">
                    <i class="layui-icon layui-icon-upload"></i> <{\$lang.upload|default:'上传'}>
                </a>
            </div>
        </div>
HTML;
    }

    /**
     * 构建搜索表单 HTML 片段
     *
     * 若 pageLayout.search.order 有值，严格按设计器搜索字段顺序生成；
     * 否则沿用库字段启发式（选项/日期/状态 + 最多 2 个 varchar）。
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @param array<string, array<string, mixed>> $fieldOptions 设计器选项源
     * @param array<string, mixed> $pageLayout 设计器布局
     * @return string
     */
    protected static function buildSearchFieldsHtml(
        array $columns,
        string $pk,
        array $fieldOptions = [],
        array $pageLayout = []
    ): string {
        $skip = array_merge(self::generatorSkipSearchFields(), [$pk]);
        $html = [];
        $varcharCount = 0;
        $softDeleteField = self::detectSoftDeleteField($columns);
        $byField = [];
        foreach ($columns as $column) {
            $field = (string) ($column['field'] ?? '');
            if ($field !== '') {
                $byField[$field] = $column;
            }
        }

        if ($softDeleteField !== null) {
            $html[] = <<<HTML
        <div class="layui-form-item layui-inline">
            <label class="layui-form-label"><{\$lang.dataScope|default:'数据范围'}></label>
            <div class="layui-input-inline">
                <select class="layui-select" name="type">
                    <option value=""><{\$lang.normalList|default:'正常列表'}></option>
                    <option value="recycle" <{if \$get.type|default:'' == 'recycle'}>selected<{/if}>><{\$lang.recycleBin|default:'回收站'}></option>
                </select>
            </div>
        </div>
HTML;
        }

        $searchOrder = [];
        $searchLayout = is_array($pageLayout['search'] ?? null) ? $pageLayout['search'] : [];
        if (is_array($searchLayout['order'] ?? null)) {
            foreach ($searchLayout['order'] as $name) {
                $n = strtolower(trim((string) $name));
                if ($n !== '' && !in_array($n, $searchOrder, true)) {
                    $searchOrder[] = $n;
                }
            }
        }

        $iterate = [];
        if ($searchOrder !== []) {
            foreach ($searchOrder as $name) {
                if (isset($byField[$name]) && !in_array($name, $skip, true)) {
                    $iterate[] = $byField[$name];
                } elseif (!isset($byField[$name]) && !in_array($name, $skip, true)) {
                    // 设计器自定义搜索字段（无对应表列）：按文本框输出
                    $iterate[] = ['field' => $name, 'type' => 'varchar', 'label' => $name, 'comment' => $name];
                }
            }
        } else {
            $iterate = $columns;
        }
        $useDesignOrder = $searchOrder !== [];

        foreach ($iterate as $column) {
            $field = (string) ($column['field'] ?? '');
            if ($field === '' || in_array($field, $skip, true)) {
                continue;
            }
            $type = strtolower((string) ($column['type'] ?? ''));
            $label = self::resolveColumnLabel($column);
            $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $fieldEsc = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
            $opt = $fieldOptions[$field] ?? null;

            if (is_array($opt) && ($opt['optionSource'] ?? '') !== '') {
                $html[] = self::buildOptionSearchFieldHtml($fieldEsc, $labelEsc, $opt);
                continue;
            }

            // 日期/时间搜索：同样挂 data-date-input，便于 laydate 选择
            $laydateType = self::resolveLaydateType($type, is_array($opt) ? $opt : null);
            if ($laydateType !== null) {
                $placeholder = $laydateType === 'datetime'
                    ? 'YYYY-MM-DD HH:mm:ss'
                    : ($laydateType === 'time' ? 'HH:mm:ss' : 'YYYY-MM-DD');
                $html[] = <<<HTML
        <div class="layui-form-item layui-inline">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-inline">
                <input name="{$fieldEsc}" data-date-input="{$laydateType}" value="<{\$get.{$field}|default:''}>" placeholder="{$placeholder}" class="layui-input" autocomplete="off">
            </div>
        </div>
HTML;
                continue;
            }

            if (preg_match('/tinyint\(1\)/', $type) || $field === 'status') {
                $html[] = <<<HTML
        <div class="layui-form-item layui-inline">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-inline">
                <select class="layui-select" name="{$fieldEsc}">
                    <option value=""><{\$lang.allStatus|default:'全部'}></option>
                    <option value="1" <{if \$get.{$field}|default:'' == '1'}>selected<{/if}>><{\$lang.enabled|default:'启用'}></option>
                    <option value="0" <{if \$get.{$field}|default:'' == '0'}>selected<{/if}>><{\$lang.disabled|default:'禁用'}></option>
                </select>
            </div>
        </div>
HTML;
                continue;
            }

            if ($useDesignOrder) {
                // 设计器指定的搜索项：一律输出输入框（含数字等）
                $html[] = <<<HTML
        <div class="layui-form-item layui-inline">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-inline">
                <input name="{$fieldEsc}" value="<{\$get.{$field}|default:''}>" placeholder="<{\$lang.enter{$fieldEsc}|default:'请输入{$labelEsc}'}>" class="layui-input">
            </div>
        </div>
HTML;
                continue;
            }

            if (!preg_match('/varchar|char/', $type) || $varcharCount >= 2) {
                continue;
            }
            $varcharCount++;
            $html[] = <<<HTML
        <div class="layui-form-item layui-inline">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-inline">
                <input name="{$fieldEsc}" value="<{\$get.{$field}|default:''}>" placeholder="<{\$lang.enter{$fieldEsc}|default:'请输入{$labelEsc}'}>" class="layui-input">
            </div>
        </div>
HTML;
        }

        return self::finalizeSearchFieldsHtml($html, $softDeleteField !== null, $searchOrder !== []);
    }

    /**
     * 搜索字段 HTML 收尾：无业务项时补关键词
     *
     * @param list<string> $html 已生成片段
     * @param bool $hasSoftDelete 是否已有回收站筛选项
     * @param bool $hasDesignOrder 是否来自设计器顺序
     * @return string
     * @access private
     * @static
     */
    private static function finalizeSearchFieldsHtml(array $html, bool $hasSoftDelete, bool $hasDesignOrder): string
    {
        $bizCount = count($html) - ($hasSoftDelete ? 1 : 0);
        if ($bizCount <= 0 && !$hasDesignOrder) {
            $html[] = <<<HTML
        <div class="layui-form-item layui-inline">
            <label class="layui-form-label"><{\$lang.keyword|default:'关键词'}></label>
            <div class="layui-input-inline">
                <input name="keyword" value="<{\$get.keyword|default:''}>" placeholder="<{\$lang.enterKeyword|default:'请输入关键词'}>" class="layui-input">
            </div>
        </div>
HTML;
        }

        return implode("\n", $html);
    }

    /**
     * 构建 Model 列表筛选 PHP 片段
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @return string
     */
    protected static function buildListFilterPhp(array $columns, string $pk): string
    {
        $skip = array_merge(self::generatorSkipSearchFields(), [$pk]);
        $lines = [];
        $softDeleteField = self::detectSoftDeleteField($columns);

        if ($softDeleteField !== null) {
            $lines[] = "        if ((\$params['type'] ?? '') === 'recycle') {\n            \$this->onlyTrashed(true);\n        }";
        }

        foreach ($columns as $column) {
            $field = (string)($column['field'] ?? '');
            if ($field === '' || in_array($field, $skip, true)) {
                continue;
            }
            $type = strtolower((string)($column['type'] ?? ''));
            if (preg_match('/tinyint\(1\)/', $type) || $field === 'status') {
                $lines[] = "        if ((\$params['{$field}'] ?? '') !== '' && \$params['{$field}'] !== null) {\n            \$this->where('{$field}', (int)\$params['{$field}']);\n        }";
                continue;
            }
            if (preg_match('/datetime|timestamp|^date\b|^time\b/', $type)) {
                $lines[] = "        if ((\$params['{$field}'] ?? '') !== '') {\n            \$this->where('{$field}', trim((string)\$params['{$field}']));\n        }";
                continue;
            }
            if (preg_match('/varchar|char/', $type)) {
                $lines[] = "        if ((\$params['{$field}'] ?? '') !== '') {\n            \$this->where('{$field} LIKE ?', ['%' . trim((string)\$params['{$field}']) . '%']);\n        }";
            }
        }

        if ($lines === []) {
            $lines[] = "        if ((\$params['keyword'] ?? '') !== '') {\n            \$kw = '%' . trim((string)\$params['keyword']) . '%';\n            \$this->where('{$pk} LIKE ?', [\$kw]);\n        }";
        }

        return implode("\n", $lines);
    }

    /**
     * 构建 getList 默认参数字符串
     *
     * 生成搜索字段默认值片段；末尾带逗号，以便 stub 中继续拼接 page/limit
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @return string
     */
    protected static function buildListParamsDefaultsPhp(array $columns, string $pk): string
    {
        $defaults = [];
        $skip = array_merge(self::generatorSkipSearchFields(), [$pk]);
        $softDeleteField = self::detectSoftDeleteField($columns);

        if ($softDeleteField !== null) {
            $defaults[] = "'type' => ''";
        }

        $varcharCount = 0;
        foreach ($columns as $column) {
            $field = (string)($column['field'] ?? '');
            if ($field === '' || in_array($field, $skip, true)) {
                continue;
            }
            $type = strtolower((string)($column['type'] ?? ''));
            if (preg_match('/tinyint\(1\)/', $type) || $field === 'status') {
                $defaults[] = "'{$field}' => ''";
                continue;
            }
            if (preg_match('/varchar|char/', $type) && $varcharCount < 2) {
                $defaults[] = "'{$field}' => ''";
                $varcharCount++;
            }
        }

        if ($defaults === []) {
            $defaults[] = "'keyword' => ''";
        }

        // 末尾保留逗号，与 stub 中紧随其后的 'page' => 1 拼接，避免缺逗号语法错误
        return implode(",\n            ", $defaults) . ',';
    }

    /**
     * 构建排序字段白名单常量
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @return string
     */
    protected static function buildSortFieldsConst(array $columns, string $pk): string
    {
        $fields = [$pk];
        $skip = self::generatorSkipListFields();
        foreach ($columns as $column) {
            $field = (string)($column['field'] ?? '');
            if ($field === '' || $field === $pk || in_array($field, $skip, true)) {
                continue;
            }
            if (self::detectSoftDeleteField($columns) === $field) {
                continue;
            }
            $fields[] = $field;
            if (count($fields) >= 8) {
                break;
            }
        }

        $quoted = array_map(static fn(string $f): string => "'" . addslashes($f) . "'", array_values(array_unique($fields)));

        return implode(', ', $quoted);
    }

    /**
     * 构建软删除属性声明
     *
     * @param array<int, array<string, mixed>> $columns
     * @return string
     */
    protected static function buildSoftDeletePropertyPhp(array $columns): string
    {
        $field = self::detectSoftDeleteField($columns);
        if ($field === null) {
            return '';
        }

        return "\n    /**\n     * 软删除时间字段\n     *\n     * @var string\n     */\n    protected \$deleteTime = '{$field}';\n";
    }

    /**
     * 检测创建时间字段名
     *
     * @param array<int, array<string, mixed>> $columns
     * @return string|null
     * @access protected
     * @static
     */
    protected static function detectCreateTimeField(array $columns): ?string
    {
        $candidates = ['create_time', 'createtime', 'createTime', 'AddTime'];
        $fields = array_map(static fn(array $col): string => (string) ($col['field'] ?? ''), $columns);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
            foreach ($fields as $field) {
                if (strcasecmp($field, $candidate) === 0) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * 检测更新时间字段名
     *
     * @param array<int, array<string, mixed>> $columns
     * @return string|null
     * @access protected
     * @static
     */
    protected static function detectUpdateTimeField(array $columns): ?string
    {
        $candidates = ['update_time', 'updatetime', 'updateTime', 'UpTime'];
        $fields = array_map(static fn(array $col): string => (string) ($col['field'] ?? ''), $columns);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
            foreach ($fields as $field) {
                if (strcasecmp($field, $candidate) === 0) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * 构建自动时间戳属性声明
     *
     * 表含 create_time / update_time 时启用框架 autoWriteTimestamp（datetime）
     * 表单不展示这些字段，写入由 insert/update 自动填充
     *
     * @param array<int, array<string, mixed>> $columns
     * @return string
     * @access protected
     * @static
     */
    protected static function buildAutoTimestampPropertyPhp(array $columns): string
    {
        $create = self::detectCreateTimeField($columns);
        $update = self::detectUpdateTimeField($columns);
        if ($create === null && $update === null) {
            return '';
        }

        $lines = ["\n"];
        if ($create !== null) {
            $lines[] = "    /**\n     * 创建时间字段（insert 时自动写入）\n     *\n     * @var string\n     */\n    protected \$createTime = '{$create}';\n";
        }
        if ($update !== null) {
            $lines[] = "    /**\n     * 更新时间字段（insert/update 时自动写入）\n     *\n     * @var string\n     */\n    protected \$updateTime = '{$update}';\n";
        }
        $lines[] = "    /**\n     * 自动写入时间戳格式（datetime）\n     *\n     * @var string\n     */\n    protected \$autoWriteTimestamp = 'datetime';\n";

        return implode("\n", $lines);
    }

    /**
     * 构建回收站恢复方法（存在软删除字段时）
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @return string
     */
    protected static function buildRestoreRowMethodPhp(array $columns, string $pk): string
    {
        $field = self::detectSoftDeleteField($columns);
        if ($field === null) {
            return '';
        }

        return <<<PHP

    /**
     * 从回收站恢复记录
     *
     * 将软删除字段置空
     *
     * @param array $data 含 {$pk} 或 ids
     * @return array {code, message}
     */
    public function restoreRow(array \$data): array
    {
        \$ids = \$data['ids'] ?? (\$data['{$pk}'] ?? null);
        if (empty(\$ids)) {
            return ['code' => 1, 'message' => L('idMissing')];
        }
        if (!is_array(\$ids)) {
            \$ids = [\$ids];
        }
        \$this->resetQueryState();
        \$this->withTrashed(true);
        if (\$this->where('{$pk}', 'in', \$ids)->update(['{$field}' => null])) {
            return ['code' => 0, 'message' => L('restoreSuccess')];
        }
        return ['code' => 1, 'message' => \$this->getMsg() ?: L('restoreFailed')];
    }
PHP;
    }

    /**
     * 构建 normalizePayload 方法体
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $pk 主键字段
     * @param array<string, array<string, mixed>> $fieldOptions 设计器选项源（含 control）
     * @return string
     */
    protected static function buildNormalizePayloadPhp(array $columns, string $pk, array $fieldOptions = []): string
    {
        $skip = array_merge([$pk], self::generatorSkipWriteFields(), array_filter([self::detectSoftDeleteField($columns)]));
        $lines = ["        \$payload = [];"];

        foreach ($columns as $column) {
            $field = (string)($column['field'] ?? '');
            if ($field === '' || in_array($field, $skip, true)) {
                continue;
            }
            $type = strtolower((string)($column['type'] ?? ''));
            $opt = $fieldOptions[$field] ?? null;
            $isSwitch = is_array($opt) && (($opt['control'] ?? '') === 'switch');
            $isCheckbox = is_array($opt) && (($opt['control'] ?? '') === 'checkbox');

            // 开关：未勾选时 POST 无该键，须落 0（不可用 status 的默认 1）
            if ($isSwitch) {
                $lines[] = "        \$payload['{$field}'] = (int)(\$data['{$field}'] ?? 0) === 1 ? 1 : 0;";
                continue;
            }
            // 多选：表单 name[] 提交为数组，存库拼成逗号分隔
            if ($isCheckbox) {
                $lines[] = "        \$__cb = \$data['{$field}'] ?? [];\n"
                    . "        if (!is_array(\$__cb)) {\n"
                    . "            \$__cb = \$__cb === '' || \$__cb === null ? [] : [\$__cb];\n"
                    . "        }\n"
                    . "        \$payload['{$field}'] = implode(',', array_map('strval', \$__cb));";
                continue;
            }
            if (preg_match('/tinyint\(1\)/', $type) || $field === 'status') {
                $lines[] = "        \$payload['{$field}'] = (int)(\$data['{$field}'] ?? 1) === 0 ? 0 : 1;";
                continue;
            }
            if (preg_match('/\b(int|bigint|smallint|mediumint|tinyint)\b/', $type) && !preg_match('/tinyint\(1\)/', $type)) {
                $lines[] = "        \$payload['{$field}'] = (int)(\$data['{$field}'] ?? 0);";
                continue;
            }
            if (preg_match('/decimal|float|double/', $type)) {
                $lines[] = "        \$payload['{$field}'] = (float)(\$data['{$field}'] ?? 0);";
                continue;
            }
            if (preg_match('/datetime|timestamp|date/', $type)) {
                $lines[] = "        \$val = trim((string)(\$data['{$field}'] ?? ''));\n        \$payload['{$field}'] = \$val === '' ? null : \$val;";
                continue;
            }
            if (self::isUploadField($field, $type)) {
                $lines[] = "        \$payload['{$field}'] = trim((string)(\$data['{$field}'] ?? ''));";
                continue;
            }
            if (self::isRichTextField($field, $type)) {
                // 富文本存真实 HTML；若曾被实体转义（&lt;p&gt;），入库前解码一次
                $lines[] = "        \$html = (string)(\$data['{$field}'] ?? '');\n"
                    . "        if (\$html !== '' && (str_contains(\$html, '&lt;') || str_contains(\$html, '&gt;') || str_contains(\$html, '&amp;'))) {\n"
                    . "            \$html = htmlspecialchars_decode(\$html, ENT_QUOTES | ENT_HTML5);\n"
                    . "        }\n"
                    . "        \$payload['{$field}'] = \$html;";
                continue;
            }
            if (str_contains($type, 'text')) {
                $lines[] = "        \$payload['{$field}'] = trim((string)(\$data['{$field}'] ?? ''));";
                continue;
            }

            $lines[] = "        \$payload['{$field}'] = trim((string)(\$data['{$field}'] ?? ''));";
        }

        $lines[] = "        return ['code' => 0, 'message' => '', 'data' => \$payload];";

        return implode("\n", $lines);
    }

    /**
     * 解析开关 title（开|关），优先静态选项中 value=1/0 的 label
     *
     * @param array<string, mixed> $meta
     * @return array{0:string,1:string} [onTitle, offTitle]
     * @access protected
     * @static
     */
    protected static function resolveSwitchTitles(array $meta): array
    {
        $on = '启用';
        $off = '停用';
        $opts = is_array($meta['options'] ?? null) ? $meta['options'] : [];
        foreach ($opts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = (string) ($row['value'] ?? '');
            $lab = trim((string) ($row['label'] ?? ''));
            if ($lab === '') {
                continue;
            }
            if ($v === '1' || $v === 'true' || strtolower($v) === 'on') {
                $on = $lab;
            }
            if ($v === '0' || $v === 'false' || strtolower($v) === 'off') {
                $off = $lab;
            }
        }

        return [$on, $off];
    }

    /**
     * 选项字段表单 HTML（静态 / 字典 / 关联表）
     *
     * @param string $fieldEsc 转义后字段名
     * @param string $labelEsc 转义后标签
     * @param array<string, mixed> $meta 选项源
     * @return string
     * @access protected
     * @static
     */
    protected static function buildOptionFormFieldHtml(string $fieldEsc, string $labelEsc, array $meta): string
    {
        $source = (string) ($meta['optionSource'] ?? '');
        $control = (string) ($meta['control'] ?? 'select');
        $inner = '';
        $cbSkin = strtolower(trim((string) ($meta['checkboxSkin'] ?? 'primary')));
        if (!in_array($cbSkin, ['default', 'primary'], true)) {
            $cbSkin = 'primary';
        }
        $cbSkinAttr = $cbSkin === 'primary' ? ' lay-skin="primary"' : '';

        // 开关：layui switch，存 1/0；未勾选不提交，Model 侧按 0 处理
        if ($control === 'switch') {
            [$onTitle, $offTitle] = self::resolveSwitchTitles($meta);
            $onEsc = htmlspecialchars($onTitle, ENT_QUOTES, 'UTF-8');
            $offEsc = htmlspecialchars($offTitle, ENT_QUOTES, 'UTF-8');
            // 字典默认用语言包启用/停用，静态选项用上面解析的 label
            if ($source === 'dict' && ($meta['options'] ?? []) === []) {
                $titleAttr = "<{\$lang.enabled|default:'启用'}>|<{\$lang.disabled|default:'停用'}>";
            } else {
                $titleAttr = "{$onEsc}|{$offEsc}";
            }
            $inner = <<<HTML
                <input type="checkbox" name="{$fieldEsc}" value="1" lay-skin="switch" title="{$titleAttr}" <{if (string)(\$vo.{$fieldEsc}|default:'0') === '1'}>checked<{/if}>>
HTML;
        } elseif ($source === 'dict') {
            $code = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) ($meta['dict'] ?? 'common_status'))) ?: 'common_status';
            $var = 'dictItems_' . $code;
            if ($control === 'select') {
                $inner = <<<HTML
                <select class="layui-select" name="{$fieldEsc}">
                    <{foreach \${$var} as \$item}>
                    <option value="<{\$item.value}>" <{if \$vo.{$fieldEsc}|default:'' == \$item.value}>selected<{/if}>><{\$item.label_display}></option>
                    <{/foreach}>
                </select>
HTML;
            } elseif ($control === 'checkbox') {
                $inner = <<<HTML
                <{foreach \${$var} as \$item}>
                <input type="checkbox" name="{$fieldEsc}[]" value="<{\$item.value}>" title="<{\$item.label_display}>"{$cbSkinAttr} <{if in_array((string)\$item.value, explode(',', (string)(\$vo.{$fieldEsc}|default:'')), true)}>checked<{/if}>>
                <{/foreach}>
HTML;
            } else {
                $inner = <<<HTML
                <{foreach \${$var} as \$item}>
                <input type="radio" name="{$fieldEsc}" value="<{\$item.value}>" title="<{\$item.label_display}>" <{if \$vo.{$fieldEsc}|default:'' == \$item.value}>checked<{/if}>>
                <{/foreach}>
HTML;
            }
        } elseif ($source === 'table') {
            $optVar = $fieldEsc . '_options';
            $vf = htmlspecialchars((string) (($meta['ref']['valueField'] ?? 'id')), ENT_QUOTES, 'UTF-8');
            $lf = htmlspecialchars((string) (($meta['ref']['labelField'] ?? 'name')), ENT_QUOTES, 'UTF-8');
            if ($control === 'radio') {
                $inner = <<<HTML
                <{foreach \${$optVar} as \$item}>
                <input type="radio" name="{$fieldEsc}" value="<{\$item.{$vf}}>" title="<{\$item.{$lf}}>" <{if \$vo.{$fieldEsc}|default:'' == \$item.{$vf}}>checked<{/if}>>
                <{/foreach}>
HTML;
            } elseif ($control === 'checkbox') {
                $inner = <<<HTML
                <{foreach \${$optVar} as \$item}>
                <input type="checkbox" name="{$fieldEsc}[]" value="<{\$item.{$vf}}>" title="<{\$item.{$lf}}>"{$cbSkinAttr}>
                <{/foreach}>
HTML;
            } else {
                $inner = <<<HTML
                <select class="layui-select" name="{$fieldEsc}">
                    <option value=""><{\$lang.pleaseSelect|default:'请选择'}></option>
                    <{foreach \${$optVar} as \$item}>
                    <option value="<{\$item.{$vf}}>" <{if \$vo.{$fieldEsc}|default:'' == \$item.{$vf}}>selected<{/if}>><{\$item.{$lf}}></option>
                    <{/foreach}>
                </select>
HTML;
            }
        } else {
            // static
            $opts = is_array($meta['options'] ?? null) ? $meta['options'] : [];
            $parts = [];
            foreach ($opts as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $v = htmlspecialchars((string) ($row['value'] ?? ''), ENT_QUOTES, 'UTF-8');
                $lab = htmlspecialchars((string) ($row['label'] ?? $v), ENT_QUOTES, 'UTF-8');
                if ($v === '') {
                    continue;
                }
                if ($control === 'select') {
                    $parts[] = "<option value=\"{$v}\" <{if \$vo.{$fieldEsc}|default:'' == '{$v}'}>selected<{/if}>{$lab}</option>";
                } elseif ($control === 'checkbox') {
                    $parts[] = "<input type=\"checkbox\" name=\"{$fieldEsc}[]\" value=\"{$v}\" title=\"{$lab}\"{$cbSkinAttr}>";
                } else {
                    $parts[] = "<input type=\"radio\" name=\"{$fieldEsc}\" value=\"{$v}\" title=\"{$lab}\" <{if \$vo.{$fieldEsc}|default:'' == '{$v}'}>checked<{/if}>>";
                }
            }
            if ($control === 'select') {
                $inner = "<select class=\"layui-select\" name=\"{$fieldEsc}\">\n                    " . implode("\n                    ", $parts) . "\n                </select>";
            } else {
                $inner = implode("\n                ", $parts);
            }
        }

        return <<<HTML
        <div class="layui-form-item">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-block">
                {$inner}
            </div>
        </div>
HTML;
    }

    /**
     * 选项字段搜索 HTML
     *
     * @param string $fieldEsc
     * @param string $labelEsc
     * @param array<string, mixed> $meta
     * @return string
     * @access protected
     * @static
     */
    protected static function buildOptionSearchFieldHtml(string $fieldEsc, string $labelEsc, array $meta): string
    {
        $source = (string) ($meta['optionSource'] ?? '');
        $inner = '';
        if ($source === 'dict') {
            $code = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) ($meta['dict'] ?? 'common_status'))) ?: 'common_status';
            $var = 'dictItems_' . $code;
            $inner = <<<HTML
                <select class="layui-select" name="{$fieldEsc}">
                    <option value=""><{\$lang.allStatus|default:'全部'}></option>
                    <{foreach \${$var} as \$item}>
                    <option value="<{\$item.value}>" <{if \$get.{$fieldEsc}|default:'' == \$item.value}>selected<{/if}>><{\$item.label_display}></option>
                    <{/foreach}>
                </select>
HTML;
        } elseif ($source === 'table') {
            $optVar = $fieldEsc . '_options';
            $vf = htmlspecialchars((string) (($meta['ref']['valueField'] ?? 'id')), ENT_QUOTES, 'UTF-8');
            $lf = htmlspecialchars((string) (($meta['ref']['labelField'] ?? 'name')), ENT_QUOTES, 'UTF-8');
            $inner = <<<HTML
                <select class="layui-select" name="{$fieldEsc}">
                    <option value=""><{\$lang.allStatus|default:'全部'}></option>
                    <{foreach \${$optVar} as \$item}>
                    <option value="<{\$item.{$vf}}>" <{if \$get.{$fieldEsc}|default:'' == \$item.{$vf}}>selected<{/if}>><{\$item.{$lf}}></option>
                    <{/foreach}>
                </select>
HTML;
        } else {
            $opts = is_array($meta['options'] ?? null) ? $meta['options'] : [];
            $parts = ['<option value=""><{$lang.allStatus|default:\'全部\'}></option>'];
            foreach ($opts as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $v = htmlspecialchars((string) ($row['value'] ?? ''), ENT_QUOTES, 'UTF-8');
                $lab = htmlspecialchars((string) ($row['label'] ?? $v), ENT_QUOTES, 'UTF-8');
                if ($v === '') {
                    continue;
                }
                $parts[] = "<option value=\"{$v}\" <{if \$get.{$fieldEsc}|default:'' == '{$v}'}>selected<{/if}>{$lab}</option>";
            }
            $inner = "<select class=\"layui-select\" name=\"{$fieldEsc}\">\n                    " . implode("\n                    ", $parts) . "\n                </select>";
        }

        return <<<HTML
        <div class="layui-form-item layui-inline">
            <label class="layui-form-label">{$labelEsc}</label>
            <div class="layui-input-inline">
                {$inner}
            </div>
        </div>
HTML;
    }

    /**
     * Control list()：为选项字段附加 {field}_text
     *
     * @param array<string, array<string, mixed>> $fieldOptions
     * @return string
     * @access protected
     * @static
     */
    protected static function buildOptionDisplayPhp(array $fieldOptions): string
    {
        if ($fieldOptions === []) {
            return "        \$data = \$result['data'] ?? [];";
        }
        $lines = ["        \$data = \$result['data'] ?? [];"];
        foreach ($fieldOptions as $field => $meta) {
            $field = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $field)) ?: '';
            if ($field === '') {
                continue;
            }
            $source = (string) ($meta['optionSource'] ?? '');
            if ($source === 'dict') {
                $code = addslashes((string) ($meta['dict'] ?? 'common_status'));
                $lines[] = "        \$data = dictRowsDisplay(\$data, '{$field}', '{$code}');";
            } elseif ($source === 'static') {
                $map = [];
                foreach (($meta['options'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $v = (string) ($row['value'] ?? '');
                    if ($v === '') {
                        continue;
                    }
                    $map[$v] = (string) ($row['label'] ?? $v);
                }
                $mapExport = var_export($map, true);
                $lines[] = "        \$__map_{$field} = {$mapExport};";
                $lines[] = "        foreach (\$data as &\$__row) {";
                $lines[] = "            \$__raw = (string)(\$__row['{$field}'] ?? '');";
                $lines[] = "            if (str_contains(\$__raw, ',')) {";
                $lines[] = "                \$__parts = [];";
                $lines[] = "                foreach (explode(',', \$__raw) as \$__p) { \$__p = trim(\$__p); if (\$__p !== '') { \$__parts[] = \$__map_{$field}[\$__p] ?? \$__p; } }";
                $lines[] = "                \$__row['{$field}_text'] = implode('、', \$__parts);";
                $lines[] = "            } else {";
                $lines[] = "                \$__row['{$field}_text'] = \$__map_{$field}[\$__raw] ?? \$__raw;";
                $lines[] = "            }";
                $lines[] = "        }";
                $lines[] = "        unset(\$__row);";
            } elseif ($source === 'table') {
                $table = addslashes(strtolower((string) (($meta['ref']['table'] ?? ''))));
                $vf = addslashes(strtolower((string) (($meta['ref']['valueField'] ?? 'id'))));
                $lf = addslashes(strtolower((string) (($meta['ref']['labelField'] ?? 'name'))));
                if ($table === '') {
                    continue;
                }
                $lines[] = "        \$__ids_{$field} = [];";
                $lines[] = "        foreach (\$data as \$__row) {";
                $lines[] = "            \$__raw = trim((string)(\$__row['{$field}'] ?? ''));";
                $lines[] = "            if (\$__raw === '') { continue; }";
                $lines[] = "            foreach (explode(',', \$__raw) as \$__p) { \$__p = trim(\$__p); if (\$__p !== '') { \$__ids_{$field}[] = \$__p; } }";
                $lines[] = "        }";
                $lines[] = "        \$__ids_{$field} = array_values(array_unique(\$__ids_{$field}));";
                $lines[] = "        \$__labelMap_{$field} = [];";
                $lines[] = "        if (\$__ids_{$field} !== []) {";
                $lines[] = "            \$__rows = D('{$table}')->where('{$vf}', 'in', \$__ids_{$field})->field('{$vf},{$lf}')->select() ?: [];";
                $lines[] = "            foreach (\$__rows as \$__r) { \$__labelMap_{$field}[(string)(\$__r['{$vf}'] ?? '')] = (string)(\$__r['{$lf}'] ?? ''); }";
                $lines[] = "        }";
                $lines[] = "        foreach (\$data as &\$__row) {";
                $lines[] = "            \$__raw = trim((string)(\$__row['{$field}'] ?? ''));";
                $lines[] = "            if (\$__raw === '') { \$__row['{$field}_text'] = ''; continue; }";
                $lines[] = "            \$__parts = [];";
                $lines[] = "            foreach (explode(',', \$__raw) as \$__p) { \$__p = trim(\$__p); if (\$__p !== '') { \$__parts[] = \$__labelMap_{$field}[\$__p] ?? \$__p; } }";
                $lines[] = "            \$__row['{$field}_text'] = implode('、', \$__parts);";
                $lines[] = "        }";
                $lines[] = "        unset(\$__row);";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Control add/edit/index：分配字典与关联表选项
     *
     * @param array<string, array<string, mixed>> $fieldOptions
     * @return string
     * @access protected
     * @static
     */
    protected static function buildAssignOptionsPhp(array $fieldOptions): string
    {
        if ($fieldOptions === []) {
            return '';
        }
        $lines = [];
        $dicts = [];
        foreach ($fieldOptions as $field => $meta) {
            $source = (string) ($meta['optionSource'] ?? '');
            if ($source === 'dict') {
                $code = (string) ($meta['dict'] ?? '');
                if ($code !== '') {
                    $dicts[$code] = true;
                }
            } elseif ($source === 'table') {
                $fieldSafe = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $field)) ?: '';
                $table = addslashes(strtolower((string) (($meta['ref']['table'] ?? ''))));
                $vf = addslashes(strtolower((string) (($meta['ref']['valueField'] ?? 'id'))));
                $lf = addslashes(strtolower((string) (($meta['ref']['labelField'] ?? 'name'))));
                if ($fieldSafe === '' || $table === '') {
                    continue;
                }
                $lines[] = "        \$this->assign('{$fieldSafe}_options', D('{$table}')->field('{$vf},{$lf}')->order('{$vf} asc')->limit(500)->select() ?: []);";
            }
        }
        foreach (array_keys($dicts) as $code) {
            $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($code)) ?: 'common_status';
            $codeEsc = addslashes($code);
            $lines[] = "        \$this->assignDict('{$codeEsc}', 'dictItems_{$safe}');";
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * 列表 status 徽章模板（有 status 选项源时）
     *
     * @param array<string, array<string, mixed>> $fieldOptions
     * @return string
     * @access protected
     * @static
     */
    protected static function buildListStatusTplHtml(array $fieldOptions): string
    {
        if (!isset($fieldOptions['status']) || ($fieldOptions['status']['optionSource'] ?? '') === '') {
            return '';
        }

        return <<<HTML
<script type="text/html" id="statusTpl">
    {{# var on = (d.status == 1 || d.status == '1'); }}
    <span class="layui-badge {{on ? 'layui-bg-green' : 'layui-bg-gray'}}">{{d.status_text || d.status}}</span>
</script>

HTML;
    }
}
