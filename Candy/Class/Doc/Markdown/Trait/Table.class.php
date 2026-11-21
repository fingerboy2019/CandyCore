<?php
/***
 * Markdown Table
 *
 * 业务说明：表格单元格算法与表格块收集
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Table
{
    /**
     * 查找表格单元格斜线分割符位置
     *
     * `%%` 为右上/左下分割；`//` 为左上/右下分割
     * 匹配 `//` 时排除 URL 协议 `://`，避免链接或 https 地址误触发切分
     *
     * @param string $cellContent 单元格原始 Markdown 文本（须在行内解析之前检测）
     * @return array{delimiter:string,position:int}|null
     * @access private
     * @static
     */
    private static function findTableCellSplitPosition(string $cellContent): ?array
    {
        $percentPos = strpos($cellContent, '%%');
        if ($percentPos !== false) {
            return ['delimiter' => '%%', 'position' => $percentPos];
        }

        $offset = 0;
        while (($pos = strpos($cellContent, '//', $offset)) !== false) {
            if ($pos > 0 && $cellContent[$pos - 1] === ':') {
                $offset = $pos + 2;
                continue;
            }

            return ['delimiter' => '//', 'position' => $pos];
        }

        return null;
    }

    /**
     * 处理表格单元格斜线分割与行内元素
     *
     * 须在原始 Markdown 上检测分割符，再对各区段做行内解析
     * 数据行禁止先 parseInlineElements 再调用本方法，否则 href 中的 // 会被误判
     *
     * @param string $cellContent 单元格原始 Markdown 文本
     * @return string 单元格 HTML
     * @access private
     * @static
     */
    private static function processSplitCell(string $cellContent): string
    {
        $split = self::findTableCellSplitPosition($cellContent);
        if ($split !== null) {
            $delimiter = $split['delimiter'];
            $position = $split['position'];
            $left = trim(substr($cellContent, 0, $position));
            $right = trim(substr($cellContent, $position + strlen($delimiter)));

            if ($delimiter === '%%') {
                return '<div class="split-cell split-top-right-bottom-left">
                    <div class="split-top">' . self::parseInlineElements($right) . '</div>
                    <div class="split-bottom">' . self::parseInlineElements($left) . '</div>
                </div>';
            }

            return '<div class="split-cell split-top-left-bottom-right">
                    <div class="split-top">' . self::parseInlineElements($left) . '</div>
                    <div class="split-bottom">' . self::parseInlineElements($right) . '</div>
                </div>';
        }

        return self::parseInlineElements($cellContent);
    }

    private static function processIndentation(string $line): array
    {
        // 使用ltrim()函数只移除左侧空白字符，保留右侧空格
        $trimmedLine = ltrim($line);
        
        // 通过原始行长度减去去除左侧空格后的长度，得到行首空格数
        // 这种方式能准确计算包括制表符在内的所有缩进字符
        $indent = strlen($line) - strlen($trimmedLine);
        
        // 按2个空格为一个缩进层级计算，符合Markdown标准的缩进习惯
        // 缩进级别映射关系：
        //   0-1个空格 → 级别0（一级列表）
        //   2-3个空格 → 级别1（二级列表）
        //   4-5个空格 → 级别2（三级列表）
        //   6-7个空格 → 级别3（四级列表）
        //   ...以此类推
        // 使用floor()函数确保非严格的偶数缩进也能正确归类
        // max(0, ...)确保缩进级别不为负数
        $indentLevel = max(0, (int)floor($indent / 2));
        
        // 返回处理结果：去除了缩进后的文本行和对应的缩进级别
        return [$trimmedLine, $indentLevel];
    }

    private static function processMergedCells(array $cells): array
    {
        $result = [];
        $mergeCount = 0;
        $mergeStart = false;
        
        foreach ($cells as $index => $cell) {
            // 跳过空单元格（处理行首尾的|分割产生的空元素）
            if (empty($cell)) {
                continue;
            }
            if ($cell === '>>>>') {
                // 向右格合并（当前单元格被合并到前一个单元格中）
                if (!empty($result)) {
                    $lastIndex = count($result) - 1;
                    $result[$lastIndex]['colspan']++;
                }
            } elseif ($cell === '<<<<') {
                // 向左格合并 - 开始收集连续的合并标记
                $mergeCount++;
                $mergeStart = true;
            } elseif ($cell === '--') {
                // 处理纵向合并标记前的横向合并
                if ($mergeStart) {
                    // 创建合并单元格
                    $result[] = [
                        'content' => '',
                        'colspan' => $mergeCount,
                        'rowspan' => 1
                    ];
                    $mergeCount = 0;
                    $mergeStart = false;
                }
                
                // 纵向合并标记 - 表示当前单元格为空，需要与上方单元格合并
                // 实际合并由parseTable中的columnMergeStates处理
                $result[] = [
                    'content' => '',
                    'colspan' => 1,
                    'rowspan' => 0, // 0表示这是一个需要被合并的空单元格
                    'is_vertical_merge' => true
                ];
            } else {
                // 普通单元格
                if ($mergeStart) {
                    // 如果之前有连续的向左合并标记，将当前单元格合并
                    $result[] = [
                        'content' => $cell,
                        'colspan' => $mergeCount + 1, // 合并标记数量 + 1个内容单元格
                        'rowspan' => 1
                    ];
                    $mergeCount = 0;
                    $mergeStart = false;
                } else {
                    // 普通单元格
                    $result[] = [
                        'content' => $cell,
                        'colspan' => 1,
                        'rowspan' => 1
                    ];
                }
            }
        }
        
        // 处理最后可能的连续合并标记
        if ($mergeStart) {
            $result[] = [
                'content' => '',
                'colspan' => $mergeCount,
                'rowspan' => 1
            ];
        }
        
        return $result;
    }

    private static function collectTableBlocks(string $text): string
    {
        // 检查表格功能是否启用，如果未启用则直接返回原文本
        if(self::$config['enableTables'] === false) return $text;
        
        // 初始化表格块存储数组
        $table = [];
        
        // 使用正则表达式匹配Markdown表格
        // 匹配模式：Table: 标题 {属性} 位置\n表头行\n分隔符行\n数据行...
        $text = preg_replace_callback(
            '/(?:Table:\s*(\S*)?\s*\{([^}]+)?\}\s*(top|bottom)?\n)?(\|.*\|)\n(\|[^\n]*[-:][^\n]*\|)\n(\|.*\|(?:\n\|.*\|)*)?/m',
            function ($matches) use (&$table) {
                // 提取表格标题和标题位置设置
                $tableTitle = $matches[1];
                $captionPosition = empty($matches[3]) ? 'top' : $matches[3];

                // 检查是否有表格数据行
                if (isset($matches[6])) {
                    // 解析表格标题和属性配置
                    [$tableAttrs] = self::parseAttributes($matches[2] ?? null);
                    // 生成完整的table标签
                    $tableContent = '<table';
                    if (!empty($tableAttrs)) {
                        $tableContent .= ' ' . $tableAttrs;
                    }
                    $tableContent .= '>';

                    // 解析表格结构：表头行和分隔符行
                    // 表头行定义表格的列数和列标题，分隔符行定义列的对齐方式
                    $headerRow = trim($matches[4], '| ');
                    $headers = array_map('trim', explode('|', $headerRow));

                    $separatorRow = trim($matches[5], '| ');
                    $separatorCells = explode('|', $separatorRow);
                    
                    // 解析对齐方式：支持多种分隔符形式的左、中、右对齐
                    $alignments = []; // 存储每列的对齐方式
                    foreach ($separatorCells as $cell) {
                        $cell = trim($cell);
                        // 增强的对齐语法支持
                        // 支持:-:, :==:, :--:, :===:, :--+:, :===+:等多种分隔符形式的居中对齐
                        if (preg_match('/^:[-=+]+:$/', $cell)) {
                            $alignments[] = 'center';
                        // 支持:-+, :==+, :--+, :===+等多种分隔符形式的左对齐
                        } elseif (preg_match('/^:[-=+]+$/', $cell)) {
                            $alignments[] = 'left';
                        // 支持-+:, ==+:等多种分隔符形式的右对齐
                        } elseif (preg_match('/^[-=+]+:$/', $cell)) {
                            $alignments[] = 'right';
                        } else {
                            // 默认居中对齐，确保表格的美观性
                            $alignments[] = 'center';
                        }
                    }

                    // 处理表头行中的合并单元格
                    // 支持表头单元格的colspan和rowspan合并，提高表格的灵活性
                    $processedHeaders = self::processMergedCells($headers);

                    // 初始化列合并状态跟踪数组
                    // 用于在渲染过程中跳过被合并的单元格，确保表格结构的正确性
                    $columnCount = count($processedHeaders);
                    $columnMergeStates = []; // 存储每列的合并状态
                    for ($j = 0; $j < $columnCount; $j++) {
                        $columnMergeStates[$j] = 0; // 0表示当前行不需要跳过此列
                    }

                    // 生成表格标题（caption元素）
                    // 根据captionPosition参数决定标题显示在表格顶部还是底部
                    if (!empty($tableTitle) && $captionPosition === 'top') {
                        // 顶部标题：使用caption-side: top样式，标题在表格内容上方显示
                        $tableContent .= '<caption style="caption-side: top; text-align: center; padding: 8px; font-style: italic; color: #666;">' . self::escapeHtml($tableTitle) . '</caption>';
                    }

                    // 生成表格头部（thead元素）
                    // 表头包含表格的列标题，通常用于描述每列的数据类型
                    $tableContent .= '<thead><tr>';
                    foreach ($processedHeaders as $index => $headerInfo) {
                        $header = $headerInfo['content']; // 表头文本内容
                        $colspan = $headerInfo['colspan']; // 水平合并列数
                        $rowspan = $headerInfo['rowspan']; // 垂直合并行数
                        $align = $alignments[$index] ?? 'left'; // 对齐方式，默认为左对齐
                        
                        // 构建th元素的属性字符串
                        $attr = [];
                        if ($colspan > 1) $attr[] = "colspan=\"{$colspan}\""; // 水平合并
                        if ($rowspan > 1) {
                            $attr[] = "rowspan=\"{$rowspan}\""; // 垂直合并
                            // 更新列合并状态跟踪数组，标记后续行需要跳过的列数
                            for ($j = 0; $j < $colspan; $j++) {
                                if (isset($columnMergeStates[$index + $j])) {
                                    $columnMergeStates[$index + $j] = $rowspan - 1;
                                }
                            }
                        }
                        $attrStr = count($attr) > 0 ? ' ' . implode(' ', $attr) : '';
                        
                        // 应用列宽和表头样式
                        // 表头通常使用居中对齐，提供更好的视觉效果
                        $columnWidth = $columnWidths[$index] ?? ''; // 获取列宽度配置
                        $style = "text-align: center; height: 60px; vertical-align: middle; position: relative;";
                        if (!empty($columnWidth)) {
                            $style .= "; width: {$columnWidth}"; // 应用列宽样式
                        }
                        
                        // 处理表头内容，支持分割单元格功能
                        $tableContent .= "<th{$attrStr} style=\"{$style}\">" . self::processSplitCell($header) . "</th>";
                    }
                    $tableContent .= '</tr></thead><tbody>'; // 完成表头，开始表格正文

                    // 解析表格内容数据行
                    // 将表格数据部分按行分割，每行包含多个单元格数据
                    $dataRows = explode("\n", $matches[6]);
                    $rowCount = count($dataRows);
                    
                    // 遍历每一行数据，逐行渲染表格内容
                    for ($i = 0; $i < $rowCount; $i++) {
                        $row = trim($dataRows[$i], '| '); // 去除行首尾的管道符和空格
                        $cells = array_map('trim', explode('|', $row)); // 分割单元格
                        
                        // 处理当前行中的合并单元格逻辑
                        // 支持水平合并（colspan）和特殊标记的垂直合并
                        $processedCells = self::processMergedCells($cells);
                        
                        $tableContent .= '<tr>'; // 开始新行
                        $currentColumnIndex = 0; // 当前处理的列索引
                        
                        // 遍历当前行的所有单元格
                        foreach ($processedCells as $cellIndex => $cellInfo) {
                            // 处理列合并状态：跳过被垂直合并的列
                            while ($currentColumnIndex < count($columnMergeStates) && $columnMergeStates[$currentColumnIndex] > 0) {
                                // 减少合并计数器，当计数器为0时该列恢复正常
                                $columnMergeStates[$currentColumnIndex]--;
                                $currentColumnIndex++;
                            }
                            
                            // 处理纵向合并标记：跳过特殊的"--"合并标记单元格
                            if (isset($cellInfo['is_vertical_merge']) && $cellInfo['is_vertical_merge']) {
                                // 纵向合并标记不生成实际的td元素，只移动列索引
                                $currentColumnIndex++;
                                continue;
                            }
                            
                            $colspan = $cellInfo['colspan']; // 水平合并列数
                            $rowspan = 1; // 默认垂直合并行数为1
                            
                            // 确定单元格的对齐方式
                            // 优先使用当前列的对齐配置，其次使用默认对齐方式
                            $align = isset($alignments[$currentColumnIndex]) ? $alignments[$currentColumnIndex] : 
                                    (!empty($alignments) ? end($alignments) : 'center');
                            
                            // 检测连续纵向合并：检查当前列下方是否有连续的"--"标记
                            if ($i < $rowCount - 1) {
                                $currentRowIndex = $i + 2; // 数据行在原始表格中的索引（+2因为包含表头和分隔符行）
                                $mergeCount = 0; // 合并计数器
                                
                                // 向下扫描连续的行，检查是否有纵向合并标记
                                for ($j = $i + 1; $j < $rowCount; $j++) {
                                    $nextRow = trim($dataRows[$j], '| ');
                                    $nextCells = array_map('trim', explode('|', $nextRow));
                                    
                                    // 检查当前列在下一行是否为纵向合并标记"--"
                                    if (isset($nextCells[$currentColumnIndex]) && $nextCells[$currentColumnIndex] === '--') {
                                        $mergeCount++;
                                    } else {
                                        break; // 遇到非合并标记，停止扫描
                                    }
                                }
                                
                                // 如果发现连续合并标记，更新rowspan属性
                                if ($mergeCount > 0) {
                                    $rowspan = $mergeCount + 1; // +1包含当前行
                                }
                            }
                            
                            // 构建td元素的属性
                            $attr = [];
                            if ($colspan > 1) $attr[] = "colspan=\"{$colspan}\""; // 水平合并属性
                            if ($rowspan > 1) {
                                $attr[] = "rowspan=\"{$rowspan}\""; // 垂直合并属性
                                // 更新列合并状态数组，标记后续行需要跳过的列数
                                for ($j = 0; $j < $colspan; $j++) {
                                    if (isset($columnMergeStates[$currentColumnIndex + $j])) {
                                        $columnMergeStates[$currentColumnIndex + $j] = $rowspan - 1;
                                    }
                                }
                            }
                            $attrStr = count($attr) > 0 ? ' ' . implode(' ', $attr) : '';
                            
                            // 应用列宽和对齐样式
                            // 确保每个单元格都有统一的视觉表现
                            $columnWidth = $columnWidths[$currentColumnIndex] ?? '';
                            $style = "text-align: {$align}; height: 60px; vertical-align: middle; position: relative;";
                            if (!empty($columnWidth)) {
                                $style .= "; width: {$columnWidth}";
                            }
                            
                            // 在原始 Markdown 上处理斜线分割，再解析链接等行内元素
                            $cellContent = self::processSplitCell($cellInfo['content']);
                            
                            // 生成完整的td元素
                            $tableContent .= "<td{$attrStr} style=\"{$style}\">{$cellContent}</td>";
                            
                            // 更新列索引，跳过已处理的列
                            $currentColumnIndex += $colspan;
                        }
                        $tableContent .= '</tr>'; // 结束当前行
                    }
                    
                    // 生成表格底部标题（caption元素）
                    // 当captionPosition设置为'bottom'时，标题显示在表格内容下方
                    if (!empty($tableTitle) && $captionPosition === 'bottom') {
                        // 底部标题：使用caption-side: bottom样式，标题在表格内容下方显示
                        $tableContent .= '<caption style="caption-side: bottom; text-align: center; padding: 8px; font-style: italic; color: #666;">' . self::escapeHtml($tableTitle) . '</caption>';
                    }
                    
                    // 完成表格结构：关闭tbody和table标签
                    $tableContent .= '</tbody></table>';
                } else {
                    // 处理空表格情况：生成带有提示信息的占位表格
                    // 当表格只有表头和分隔符行但没有数据行时显示
                    $tableContent = '<table class="empty-table"><tbody><tr><td style="text-align: center; color: #999; padding: 20px;">表格内容为空</td></tr></tbody></table>';
                }

                // 生成表格块的唯一标识符
                // 使用递增的索引确保每个表格块都有唯一的占位符
                $blockKey = '<!--block-table-' . self::$index++ . '-->';
                
                // 将生成的表格HTML存储到全局块数组中
                // 后续将用占位符替换回实际的HTML内容
                $table[$blockKey] = $tableContent;
                
                // 返回占位符，原始的表格markdown文本被替换为占位符
                return $blockKey;
            },
            $text
        );

        // 将所有处理的表格块合并到全局块存储数组中
        // 确保表格内容能够在最终渲染时被正确替换
        if(count($table) > 0) self::$allBlocks = array_merge(self::$allBlocks, $table);

        // 返回处理后的文本，表格markdown已被占位符替换
        return $text;
    }
}
