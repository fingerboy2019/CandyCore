<?php
/**
 * CSV文件导出工具类
 * 
 * 该类提供了一套完整的CSV文件导出功能，主要用于将数据列表导出为CSV格式文件。
 * 支持自定义表头设置、数据规则配置和带点语法的数据字段解析。
 * 
 * 主要功能：
 * - 生成CSV文件头部信息，包括文件名称和列标题
 * - 处理并输出CSV文件主体数据
 * - 支持复杂数组结构的数据字段解析（使用点语法）
 * - 自动处理UTF-8到GBK的编码转换，确保中文在CSV文件中正确显示
 * 
 * 使用示例：
 * ```php
 * // 设置CSV文件头部
 * Excel::header('用户数据表.csv', ['ID', '用户名', '邮箱']);
 * // 输出CSV数据内容
 * Excel::body($userList, ['id', 'username', 'contact.email']);
 * ```
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-01-23 18:50:08
 */
 
declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Excel {
    /**
     * 设置并输出CSV文件头部信息
     * 
     * 该方法负责设置CSV文件的HTTP响应头，包括文件MIME类型和下载信息，并将指定的
     * 表头数据写入输出流。方法会自动处理UTF-8到GBK的编码转换，确保中文表头在CSV文件中正确显示。
     * 
     * 工作流程：
     * 1. 设置适当的HTTP响应头，指示浏览器下载文件
     * 2. 打开PHP输出流作为写入目标
     * 3. 将表头数据从UTF-8转换为GBK编码
     * 4. 使用fputcsv函数格式化并写入表头数据
     * 5. 关闭输出流
     * 
     * @param string $name 导出文件的名称，包含文件扩展名(.csv)
     * @param array $headers 表格头部列标题数组，一维数组，每个元素对应一列的标题
     * @return void 无返回值，直接输出CSV头部信息
     */
    public static function header($name, $headers): void
    {
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=" . iconv('utf-8', 'gbk//TRANSLIT', $name));
        $handle = fopen('php://output', 'w');
        foreach ($headers as $key => $value) $headers[$key] = iconv("utf-8", "gbk//TRANSLIT", $value);
        fputcsv($handle, $headers);
        if (is_resource($handle)) fclose($handle);
    }

    /**
     * 处理并输出CSV文件主体数据内容
     * 
     * 该方法负责将数据列表按照指定的规则格式化为CSV行，并写入输出流。
     * 支持使用点语法访问嵌套数组中的字段值，自动处理UTF-8到GBK的编码转换。
     * 
     * 工作流程：
     * 1. 打开PHP输出流作为写入目标
     * 2. 遍历数据列表中的每一条记录
     * 3. 根据数据规则提取对应字段的值（支持点语法）
     * 4. 将提取的数据值写入CSV行
     * 5. 完成所有记录处理后关闭输出流
     * 
     * @param array $list 待导出的数据列表，二维数组，每个元素是一条记录
     * @param array $rules 数据字段提取规则数组，一维数组，每个元素是字段名或点语法路径
     *                     例如：['id', 'username', 'contact.email']
     * @return void 无返回值，直接输出CSV数据内容
     * @see parseKeyDotValue() 用于解析带点语法的字段路径
     */
    public static function body(array $list, $rules): void
    {
        $handle = fopen('php://output', 'w');
        foreach ($list as $data) {
            $rows = [];
            foreach ($rules as $rule) {
                $rows[] = self::parseKeyDotValue($data, $rule);
            }
            fputcsv($handle, $rows);
        }
        if (is_resource($handle)) fclose($handle);
    }

    /**
     * 根据点语法路径从数组中提取字段值
     * 
     * 该方法是Excel类的核心辅助方法，用于支持复杂数组结构的数据字段提取。
     * 支持使用点语法(如 'user.contact.email') 访问嵌套数组中的深层字段值。
     * 同时负责将提取到的数据值从UTF-8转换为GBK编码，确保CSV文件中的中文正确显示。
     * 
     * 处理逻辑：
     * 1. 移除规则字符串开头和结尾的点号，确保格式正确
     * 2. 将规则字符串按点号分割成键名数组
     * 3. 从数据数组的顶层开始，逐层按照键名向下查找
     * 4. 对于不存在的键，使用空字符串作为默认值
     * 5. 将最终找到的值转换为字符串并进行UTF-8到GBK的编码转换
     * 6. 对于非字符串和非数值类型的值，返回空字符串
     * 
     * @param array $data 源数据数组，可能包含多层嵌套结构
     * @param string $rule 字段提取规则，支持点语法路径
     *                     例如：'id', 'username', 'contact.email', 'orders.0.product_name'
     * @return string|mixed 提取到的字段值，已转换为GBK编码的字符串，
     *                      或当值不存在或类型不支持时返回空字符串
     */
    public static function parseKeyDotValue(array $data, $rule): mixed
    {
        [$temp, $attr] = [$data, explode('.', trim($rule, '.'))];
        while ($key = array_shift($attr)) $temp = $temp[$key] ??= $temp;
        return (is_string($temp) || is_numeric($temp)) ? @iconv('utf-8', 'gbk//TRANSLIT', "{$temp}") : '';
    }

    /**
     * 导出Excel文件
     * 
     * 该方法负责将给定的数据列表按照指定的规则格式化为Excel文件并输出。
     * 包含两部分：设置CSV文件头部信息和写入数据主体内容。
     * 
     * 工作流程：
     * 1. 调用header()方法设置CSV文件头部信息，包括文件名和表头
     * 2. 调用body()方法处理并写入数据主体内容，根据规则提取字段值
     * 
     * @param string $name 导出文件的名称，包含文件扩展名(.csv)
     * @param array $headers 表格头部列标题数组，一维数组，每个元素对应一列的标题
     * @param array $list 待导出的数据列表，二维数组，每个元素是一条记录
     * @param array $rules 数据字段提取规则数组，一维数组，每个元素是字段名或点语法路径
     *                     例如：['id', 'username', 'contact.email']
     * @return void 无返回值，直接输出Excel文件内容
     */
    public static function export($name, $headers, $list, $rules): void
    {
        self::header($name, $headers);
        self::body($list, $rules);
    }

    /**
     * 导入Excel 文件
     * 
     * 该方法负责从上传的Excel文件中读取数据并解析为数组格式。
     * 支持CSV、XLSX格式，对于XLS格式提供友好的错误提示。
     * 
     * 工作流程：
     * 1. 根据文件扩展名判断文件类型
     * 2. 对于CSV文件，使用fgetcsv()函数解析
     * 3. 对于XLSX文件，使用PHP原生功能解析XML内容
     * 4. 对于XLS文件，提供明确的错误提示
     * 5. 解析有数据的第一行为表头，将其存储在$headers变量中
     * 6. 从第二行开始，将解析得到的每一行数据添加到结果数组$list中
     * 7. 返回包含所有数据行的数组
     * 
     * @param string $file 待导入的Excel文件路径，包含文件名和扩展名
     * @return array 导入的Excel数据，包含headers（表头）和list（数据列表）两个键的关联数组
     * @throws \Exception 当文件不存在、无法访问或格式不支持时抛出异常
     */
    public static function import(string $file): array
    {
        // 检查文件是否存在
        if (!file_exists($file)) {
            showExceptionMsg('shutdown', "文件不存在: {$file}");
        }
        
        // 获取文件扩展名并转换为小写
        $fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        // 根据文件类型选择不同的解析方法
        return match ($fileExtension) {
            'csv' => self::importCSV($file),
            'xlsx' => self::importXLSX($file),
            'xls' => showExceptionMsg('shutdown', 'XLS格式暂不支持，请转换为CSV或XLSX格式后再导入'),
            default => showExceptionMsg('shutdown', "不支持的文件格式: {$fileExtension}，请使用CSV或XLSX格式"),
        };
    }
    
    /**
     * 导入CSV格式文件
     * 
     * @param string $file CSV文件路径
     * @return array 包含headers和list的数组
     */
    private static function importCSV(string $file): array
    {
        $list = [];
        $headers = [];
        
        if (is_resource($handle = fopen($file, 'r'))) {
            // 尝试检测并处理BOM头
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            while ($data = fgetcsv($handle)) {
                if (empty($headers)) {
                    $headers = $data;
                    continue;
                }
                $list[] = $data;
            }
            if (is_resource($handle)) fclose($handle);
        }
        
        return ['headers' => $headers, 'list' => $list];
    }
    
    /**
     * 导入XLSX格式文件
     * 
     * 使用PHP原生功能解析XLSX文件（基于XML和ZIP的格式）
     * 注意：该实现为基础版本，可能不支持所有XLSX特性
     * 
     * @param string $file XLSX文件路径
     * @return array 包含headers和list的数组
     * @throws \Exception 当无法解析XLSX文件时抛出异常
     */
    private static function importXLSX(string $file): array
    {
        // 检查PHP是否支持ZipArchive扩展
        if (!class_exists('\ZipArchive')) {
            showExceptionMsg('shutdown', "解析XLSX文件需要PHP的ZipArchive扩展");
        }
        
        $list = [];
        $headers = [];
        $zip = new \ZipArchive();
        
        // 打开XLSX文件
        if ($zip->open($file) !== true) {
            $zip->close();
            showExceptionMsg('shutdown', "无法打开XLSX文件: {$file}");
        }
        
        // 读取工作簿关系文件
        $rels = simplexml_load_string($zip->getFromName('_rels/.rels'));
        if (!$rels) {
            $zip->close();
            showExceptionMsg('shutdown', "无法解析XLSX文件结构");
        }
        
        // 查找工作表关系
        $workbookRel = '';
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Type'] === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument') {
                $workbookRel = (string)$rel['Target'];
                break;
            }
        }
        
        if (empty($workbookRel)) {
            $zip->close();
            showExceptionMsg('shutdown', "无法找到工作表");
        }
        
        // 读取工作簿文件
        $workbookXml = $zip->getFromName($workbookRel);
        if (!$workbookXml) {
            $zip->close();
            showExceptionMsg('shutdown', "无法读取工作簿内容");
        }
        
        $workbook = simplexml_load_string($workbookXml);
        if (!$workbook) {
            $zip->close();
            showExceptionMsg('shutdown', "无法解析工作簿内容");
        }
        
        // 查找第一个工作表
        $sheetRel = '';
        $sheetPath = $workbook->sheets->sheet[0]['' . 'r:id'] ?? '';
        
        if (!empty($sheetPath)) {
            // 构建工作表关系文件路径
            $pathParts = explode('/', $workbookRel);
            array_pop($pathParts);
            $workbookDir = implode('/', $pathParts);
            $workbookDir = $workbookDir ? $workbookDir . '/' : '';
            
            $workbookRelsPath = $workbookDir . '_rels/' . basename($workbookRel) . '.rels';
            $sheetRelsXml = $zip->getFromName($workbookRelsPath);
            
            if ($sheetRelsXml) {
                $sheetRels = simplexml_load_string($sheetRelsXml);
                foreach ($sheetRels->Relationship as $rel) {
                    if ((string)$rel['' . 'Id'] === $sheetPath) {
                        $sheetRel = (string)$rel['Target'];
                        break;
                    }
                }
            }
        }
        
        if (empty($sheetRel)) {
            $zip->close();
            showExceptionMsg('shutdown', "无法找到工作表内容");
        }
        
        // 读取工作表内容
        $sheetPath = $workbookDir . $sheetRel;
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        
        if (!$sheetXml) {
            showExceptionMsg('shutdown', "无法读取工作表数据");
        }
        
        // 解析工作表XML
        $sheetXml = str_replace(['xmlns:', 'x:', 'r:', 'a:'], ['', '', '', ''], $sheetXml);
        $sheet = simplexml_load_string($sheetXml);
        
        if (!$sheet) {
            showExceptionMsg('shutdown', "无法解析工作表数据");
        }
        
        // 提取数据
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rowData = [];
            $maxCol = 0;
            
            // 收集所有单元格数据并记录最大列号
            foreach ($row->c as $cell) {
                $cellRef = (string)$cell['r'];
                // 从单元格引用中提取列号（如A1 -> A）
                preg_match('/^([A-Z]+)/', $cellRef, $matches);
                $col = $matches[1] ?? '';
                
                // 将列字母转换为数字（A=0, B=1, ..., Z=25, AA=26...）
                $colNum = 0;
                for ($i = 0; $i < strlen($col); $i++) {
                    $colNum = $colNum * 26 + (ord(strtoupper($col[$i])) - 65);
                }
                
                $maxCol = max($maxCol, $colNum);
                
                // 提取单元格值
                if (isset($cell->v)) {
                    $value = (string)$cell->v;
                    // 如果单元格有公式但只存储了值，直接使用值
                    $rowData[$colNum] = $value;
                } else {
                    $rowData[$colNum] = '';
                }
            }
            
            // 填充缺失的单元格（保证数组连续）
            $fullRow = array_fill(0, $maxCol + 1, '');
            foreach ($rowData as $colNum => $value) {
                $fullRow[$colNum] = $value;
            }
            
            $rows[] = $fullRow;
        }
        
        // 分离表头和数据
        if (!empty($rows)) {
            $headers = array_shift($rows);
            $list = $rows;
        }
        
        return ['headers' => $headers, 'list' => $list];
    }
}
