# Markdown 类

## 概述

* Candy Markdown解析类
 * 
 * 提供完整的Markdown语法解析功能，支持标准Markdown以及扩展语法，
 * 包括表格、代码高亮、数学公式、Mermaid图表、Emoji表情等高级特性。
 * 同时支持自定义容器、折叠块、任务列表等增强功能。
 * 
 * 主要功能特性：
 * - 支持标准Markdown语法（标题、列表、引用、代码块等）
 * - 支持表格、脚注、链接引用等扩展语法
 * - 支持表格表头居中显示，数据行保持指定对齐方式（左对齐、居中对齐、右对齐）
 * - 支持表格单元格合并：
 *   - 纵向合并：使用--标记连续合并的单元格
 *   - 横向合并：使用>>>>和<<<<标记连续合并的单元格
 * - 支持表格单元格分割：
 *   - 右上左下切分：%%（如：value1%%value2）
 *   - 左上右下切分：//（如：value1//value2）
 * - 支持Emoji表情符号替换
 * - 支持数学公式（LaTeX）
 * - 支持Mermaid图表
 * - 支持视频嵌入（YouTube、Bilibili）


## 主要方法


### 优化报告

- **[Markdown 解析类库优化报告](Markdown优化报告.md)**（架构分析、Trait 拆分、测试建议）
- **Trait 子模块**：`backend/Candy/Class/Doc/Trait/`（State / Placeholder / Util / Inline / Table / BlockCollector / Pipeline / Meta / Output）

### parse()


### parseFile()


### /**
     * 设置Markdown解析器的配置选项
     *
     * 允许自定义Markdown解析器的各种行为，包括启用/禁用特定功能
     * 和设置各种参数。
     *
     * @param array $config 配置选项数组，支持的选项包括：
     *                      - basePath: 基础路径，用于解析相对路径的链接和图片
     *                      - docName: 文档名称
     *                      - enableEmoji: 是否启用Emoji表情替换，默认true
     *                      - enableMermaid: 是否启用Mermaid图表支持，默认true
     *                      - enableVideo: 是否启用视频嵌入支持，默认true
     *                      - enableMath: 是否启用数学公式支持，默认true
     *                      - enableTables: 是否启用表格支持，默认true
     *                      - tocDepth: 目录生成的最大标题级别，默认4
     *                      - escapeHtml: 是否转义HTML特殊字符，防止XSS攻击，默认true
     * @return void
     */
    private static function setConfig(array $config):()


### /**
     * 获取Markdown解析器的配置选项
     *
     * 如果不指定键名，则返回所有配置选项；如果指定键名，则返回对应的值。
     *
     * @param string|null $key 配置选项的键名，默认为null（返回所有配置）
     * @return mixed 配置值或配置数组，如果指定的键名不存在则返回false
     */
    private static function getConfig(?string $key = null) {
        if ($key === null) {
            return self:()


### /**
     * 安全处理HTML特殊字符
     *
     * 对字符串中的HTML特殊字符进行转义，防止XSS攻击。
     * 是htmlspecialchars函数的封装，使用ENT_QUOTES参数确保单双引号都被转义。
     *
     * @param string $content 要处理的内容
     * @return string 转义后的安全内容
     */
    private static function escapeHtml(string $content):()


### /**
     * 解析相对路径，转换为绝对路径
     * 
     * 将相对路径转换为基于基础路径的绝对路径，同时保留锚点链接。
     * 支持以下情况：
     * 1. 相对路径（如 "file.md"、"../folder/file.md"、"./file.md"）
     * 2. 锚点链接（如 "#section"）
     * 3. 绝对路径（如 "http://example.com"、"/absolute/path"）
     * 
     * @param string $path 要解析的路径
     * @param string $image 是否是图片
     * @return string 解析后的绝对路径或原始路径（如果是锚点链接或绝对路径）
     */
    private static function resolvePath(string $path, $image = false):()


### /**
     * 解析Markdown文档中的元数据
     * 
     * 解析Markdown文档顶部的YAML元数据，返回解析后的元数据数组。
     * 元数据通常位于文档顶部，使用---包裹。
     * 
     * @param string $text Markdown格式的文本
     * @return array 解析后的元数据数组
     */
    private static function parseMetaData(string &$text):()


### /**
     * 显示密码输入提示
     * 
     * 当文档需要密码时，显示一个密码输入表单，让用户输入密码。
     * 
     * @param string $password 文档的密码
     * @param string $docKey 文档的唯一标识
     * @return string 密码输入提示的HTML
     */
    private static function showPasswordPrompt(string $docKey):()


### /**
     * 解析YAML格式的元数据
     * 
     * 将YAML格式的元数据转换为PHP数组。
     * 
     * @param string $yaml YAML格式的元数据
     * @return array 解析后的元数据数组
     */
    private static function parseYaml(string $yaml):()


### /**
     * 生成完整的HTML页面
     *
     * 将核心内容HTML与面包屑导航、目录和脚注组合成完整的HTML页面。
     * 包括以下功能：
     * 1. 为文档添加面包屑导航，基于当前URL路径生成
     * 2. 生成目录HTML，支持多级标题嵌套
     * 3. 添加脚注列表，包含所有使用的脚注
     *
     * @param string $html 核心内容的HTML字符串
     * @return string 完整的HTML页面内容
     */
    private static function generateFullHtml(string $html,bool $full):()

