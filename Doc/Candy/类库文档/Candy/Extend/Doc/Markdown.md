# Markdown 类

## 类简介

Markdown类是Candy框架中的Markdown解析器，提供完整的Markdown语法解析功能，支持标准Markdown以及扩展语法，包括表格、代码高亮、数学公式、Mermaid图表、Emoji表情等高级特性。同时支持自定义容器、折叠块、任务列表等增强功能。

主要功能特性：
- 支持标准Markdown语法（标题、列表、引用、代码块等）
- 支持表格、脚注、链接引用等扩展语法
- 支持表格表头居中显示，数据行保持指定对齐方式
- 支持表格单元格合并（纵向合并使用--，横向合并使用>>>>和<<<<）
- 支持表格单元格分割（右上左下切分使用%%，左上右下切分使用//）
- 支持丰富的Emoji表情符号替换
- 支持数学公式（LaTeX格式）
- 支持Mermaid图表渲染
- 支持视频嵌入（YouTube、Bilibili）
- 支持自定义容器（tip、warning、danger等）
- 支持折叠块语法
- 支持任务列表
- 自动生成目录和面包屑导航
- 代码块支持Mac风格容器和复制功能
- 可通过配置选项灵活启用/禁用各种功能

## 命名空间

```php
namespace Candy\Extend\Doc;
```

## 类定义

```php
final class Markdown {
    // 配置选项
    private static array $config = [
        'basePath' => '',
        'useCahce' => false,
        'enableEmoji' => true,
        'enableChart' => true,
        'enableVideo' => true,
        'enableMath' => true,
        'enableTables' => true,
        'enableTabs' => true,
        'tocDepth' => 3,
        'escapeHtml' => true,
    ];
    
    // Emoji映射表
    private static array $emojiMap = [
        ':smile:' => '😄', ':laughing:' => '🤣', ':blush:' => '😊', ':smiley:' => '😃',
        ':grin:' => '😀', ':wink:' => '😉', ':smirk:' => '😏', ':innocent:' => '😇',
        ':joy:' => '😂', ':rofl:' => '🤣', ':sad:' => '😞', ':relieved:' => '😌',
        // 更多Emoji映射...
    ];
}
```

## 主要方法

### setBasePath()

```php
/**
 * 设置基础路径
 * 
 * 用于解析相对路径的基础路径，确保以斜杠结尾。
 * 
 * @param string $basePath 基础路径
 */
public static function setBasePath(string $basePath): void
```

### getBasePath()

```php
/**
 * 获取基础路径
 * 
 * 返回当前设置的基础路径。
 * 
 * @return string 基础路径
 */
public static function getBasePath(): string
```

### setConfig()

```php
/**
 * 设置配置选项
 * 
 * 用于配置Markdown解析器的各种功能选项，如启用/禁用Emoji、表格、数学公式等。
 * 
 * @param array $config 配置选项数组
 * @return void
 * @example
 * \```php
 * // 设置配置选项
 * Engine::setConfig([
 *     'enableEmoji' => true,
 *     'enableTables' => true,
 *     'tocDepth' => 3,
 *     'escapeHtml' => true
 * ]);
 * \```
 */
public static function setConfig(array $config): void
```

### parse()

```php
/**
 * 将Markdown文本转换为HTML
 * 
 * 核心解析方法，将Markdown格式的文本转换为对应的HTML输出。
 * 支持标准Markdown语法和扩展语法，包括表格、代码高亮、数学公式等。
 * 
 * @param string $text Markdown格式的文本内容
 * @return string 生成的HTML字符串
 * @example
 * \```php
 * // 解析Markdown文本
 * $html = Engine::parse('# Hello World');
 * \```
 */
public static function parse(string $text): string
```



### parseFile()

```php
/**
 * 解析Markdown文件并转换为HTML
 * 
 * 读取指定路径的Markdown文件内容，并调用parse方法将其转换为HTML。
 * 如果文件不存在，将调用showTplMsg函数显示错误信息。
 * 
 * @param string $filePath Markdown文件的完整路径
 * @param array $config 配置参数数组，可设置基础路径等
 * @return array 包含两个元素的数组：
 *              - 第一个元素是标记数组，记录解析过程中使用的特殊功能
 *              - 第二个元素是生成的HTML字符串
 * @example
 * \```php
 * // 解析Markdown文件
 * [$mark, $html] = Engine::parseFile('/path/to/document.md');
 * 
 * // 解析Markdown文件并指定基础路径
 * [$mark, $html] = Engine::parseFile('/path/to/document.md', ['basePath' => '/docs/']);
 * \```
 */
public static function parseFile(string $filePath, array $config = []): array
```

## 内部方法

以下是Markdown类中的内部方法（私有方法），仅供类内部使用：

### resolvePath()

```php
/**
 * 解析相对路径，转换为绝对路径
 * 
 * 将相对路径转换为基于基础路径的绝对路径，同时保留锚点链接。
 * 支持相对路径、锚点链接、绝对路径等情况。
 * 
 * @param string $path 要解析的路径
 * @return string 解析后的绝对路径或原始路径
 */
private static function resolvePath(string $path): string
```

### initializeData()

```php
/**
 * 初始化解析所需的静态数据
 * 
 * 重置所有用于Markdown解析的静态变量，确保每次解析都是独立的。
 * 设置各种标记和计数器的初始状态。
 */
private static function initializeData(): void
```

### parseHtml()

```php
/**
 * 解析HTML主体内容
 * 
 * 这是HTML解析的核心方法，负责处理整个Markdown文本的转换过程。
 * 解析过程包括多个阶段：预处理、块级元素处理、内联元素处理等。
 * 
 * 解析流程：
 * 1. 初始化数据和状态
 * 2. 预处理文本（处理转义字符等）
 * 3. 收集并处理各种特殊块（列表、表格、代码块等）
 * 4. 处理内联元素（链接、图片、强调等）
 * 5. 生成最终HTML
 * 
 * @param string $text 输入的Markdown文本
 * @return string 转换后的HTML内容
 */
private static function parseHtml(string $text): string
```

### processNestedBlocks()

```php
/**
 * 处理可嵌套的块元素
 * 
 * 该方法按阶段收集和处理各种类型的Markdown块元素，确保正确处理嵌套关系
 * 处理顺序很重要，某些元素需要在其他元素之前或之后处理以避免解析冲突
 * 
 * @param string $text 待处理的Markdown文本
 * @return string 处理后的HTML文本
 */
private static function processNestedBlocks(string $text): string
```

### processInlineElements()

```php
/**
 * 处理内联元素（行级元素）
 * 
 * 该方法负责处理Markdown文本中的内联元素，包括：
 * - 标题和目录生成
 * - 链接和引用
 * - 图片和媒体元素
 * - 文本格式化（加粗、斜体等）
 * 
 * 该方法会遍历文本的每一行，根据配置选项进行处理。
 * 如果启用目录功能，会在处理过程中收集标题信息。
 * 
 * @param string $text 待处理的文本
 * @param bool $withToc 是否生成目录
 * @return string 处理后的HTML文本
 */
private static function processInlineElements(string $text, bool $withToc = false): string
```

### parseInlineElements()

```php
/**
 * 解析内联元素
 * 
 * 该方法处理文本中的内联元素，包括自动链接、图片、链接、内联代码等。
 * 按照特定的顺序进行处理，确保各种内联元素能够正确解析。
 * 
 * @param string $text 待处理的文本
 * @param bool $withImg 是否处理图片，默认为true
 * @param bool $withBr 是否处理换行，默认为true
 * @return string 处理后的文本
 */
private static function parseInlineElements(string $text, bool $withImg = true, $withBr = true): string
```

### parseLinks()

```php
/**
 * 处理链接和脚注
 * 
 * @param string $text 处理前的文本
 * @return string 解析后的文本
 */
private static function parseLinks(string $text) :string 
```

### parseImage()

```php
/**
 * 处理图片文本
 * 
 * @param string $text 处理前的文本
 * @param array $links 链接定义数组，用于解析引用链接
 * @return string 解析后的文本
 */
private static function parseImage(string $text, array $links = []) :string 
```

### parseAutoLinks()

```php
/**
 * 处理自动链接文本
 * 
 * @param string $text 处理前的文本
 * @return string 解析后的文本
 */
private static function parseAutoLinks(string $text) :string 
```

### parseStyle()

```php
/**
 * 处理样式文本
 * 
 * @param string $text 处理前的文本
 * @return string 解析后的文本
 */
private static function parseStyle(string $text) :string 
```

### processCorrelationElements()

```php
/**
 * 处理需要上下文联系的行级元素
 * 
 * 该方法处理表格、引用块、列表等需要结合上下文的行级元素。
 * 这些元素不能单独处理，需要结合前后的行信息来进行正确的解析。
 * 
 * @param string $text 待处理的文本
 * @return string 处理后的HTML内容
 */
private static function parseCorrelationElements(string $text): string
```

### processSplitCell()

```php
/**
 * 处理表格单元格的切分
 * 
 * 该方法处理表格单元格中的切分操作，支持两种切分方式：
 * 1. %%分隔符：右上左下切分
 * 2. //分隔符：左上右下切分
 * 
 * @param string $cellContent 单元格内容
 * @return string 切分后的HTML结构
 */
private static function processSplitCell(string $cellContent): string
```

### processMergedCells()

```php
/**
 * 处理合并单元格
 * 
 * 解析表格行中的合并单元格语法，支持colspan和rowspan两种合并方式。
 * 使用>>>>表示向右格合并（增加colspan），使用<<<<表示向左格合并（增加colspan），使用--表示合并上一个单元格（rowspan）。
 * 
 * @param array $cells 表格行中的单元格数组
 * @return array 处理后的单元格数组，每个元素是包含content、colspan、rowspan的数组
 */
private static function processMergedCells(array $cells): array
```

### processIndentation()

```php
/**
 * 处理文本行的缩进级别计算
 * 
 * 该方法用于计算Markdown文本中每一行的缩进级别，主要用于列表项的缩进处理。
 * 按2个空格为一个层级计算缩进级别，这种方式更符合Markdown的缩进习惯。
 * 
 * @param string $line 待处理的文本行
 * @return array 返回包含处理后文本行和缩进级别的数组 [string $trimmedLine, int $indentLevel]
 */
private static function processIndentation(string $line): array
```

### processTitle()

```php
/**
 * 处理标题行并生成目录
 * 
 * 该方法处理Markdown标题（#到######），支持可选的目录生成。
 * 会为每个标题分配唯一的ID，并收集标题信息用于目录生成。
 * 
 * @param string $text 包含标题的文本内容
 * @param bool $withToc 是否生成目录，默认为false
 * @return string 处理后的HTML内容
 */
private static function processTitle(string $text, bool $withToc = false): string
```

## 使用说明

### 基本用法

解析Markdown文本内容：

```php
// 导入命名空间
use Candy\Extend\Doc\Markdown\Engine;

// 解析Markdown文本
$html = Engine::parse('# 标题

这是一段**粗体**文本，这是一段*斜体*文本。

- 列表项1
- 列表项2');

// 输出HTML
echo $html;
```

### 解析Markdown文件

直接从文件中读取并解析Markdown内容：

```php
// 导入命名空间
use Candy\Extend\Doc\Markdown\Engine;

// 解析Markdown文件
[$mark, $html] = Engine::parseFile('/path/to/document.md');

// 输出HTML
echo $html;
```

### 配置解析器

使用setConfig方法配置解析器的功能选项：

```php
// 导入命名空间
use Candy\Extend\Doc\Markdown\Engine;

// 设置配置选项
Engine::setConfig([
    'enableEmoji' => true,
    'enableTables' => true,
    'enableMath' => false,
    'tocDepth' => 2,
    'escapeHtml' => true
]);

// 解析Markdown文本
$html = Engine::parse('# 标题

这是一段包含:smile:表情的文本。');
```

### 使用扩展功能

Markdown类支持多种扩展功能，如表格、代码高亮、数学公式、Mermaid图表等：

```php
// 导入命名空间
use Candy\Extend\Doc\Markdown\Engine;

// 包含扩展语法的Markdown文本
$markdown = '# 扩展功能示例

## 表格
| 姓名 | 年龄 | 职位 |
| :--- | :--- | :--- |
| 张三 | 28 | 开发工程师 |
| 李四 | 32 | 产品经理 |

## 表格合并示例
| 列1 | 列2 | 列3 |
| :--- | :--- | :--- |
| 内容1 | 内容2 | 内容3 |
| -- | 内容4 | 内容5 |
| 内容6 | >>>> | 内容7 |

## 代码块
\```php
function hello() {
    echo "Hello World";
}
\```

## Emoji表情
:smile: :heart: :thumbsup:

## 自定义容器
::: tip
这是一条提示信息
:::

## 任务列表
- [x] 完成任务1
- [ ] 完成任务2
- [ ] 完成任务3';

// 解析扩展语法
$html = Engine::parse($markdown);

// 输出HTML
echo $html;
```

## 注意事项

1. Markdown类是最终类（final），不允许被继承
2. 在解析包含数学公式的内容时，需要确保前端有相关的渲染库支持
3. Mermaid图表功能需要前端引入Mermaid.js库
4. 解析大文件时应注意内存消耗，对于特别大的文件可能需要分段处理
5. 类中使用了一些全局函数（如showTplMsg、C、stop），确保这些函数在使用环境中可用

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2024-08-24 10:28:25