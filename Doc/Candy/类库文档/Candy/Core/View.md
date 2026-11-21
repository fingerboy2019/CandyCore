# View 类

## 类简介

View类是Candy框架的模板引擎核心类，负责处理模板的编译、渲染和显示，支持变量分配、模板继承、标签解析等功能。
提供完整的模板引擎解决方案，包括缓存机制、变量调节器、标签编译、多模板目录结构、千人千面个性化需求等特性，是框架中负责视图渲染的核心组件。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * View类 - 模板引擎核心类
 * 
 * 该类负责处理模板的编译、渲染和显示，支持变量分配、模板继承、标签解析等功能。
 * 提供完整的模板引擎解决方案，包括缓存机制、变量调节器、标签编译等特性。
 */
class View {
    // 类成员
}
```

## 主要方法

### __construct()

```php
/**
 * 构造方法，用于初始化模板引擎
 * 
 * 初始化模板引擎的各种配置参数，包括控制器、操作、分隔符、目录路径等。
 * 根据API模式设置不同的初始化流程，同时初始化缓存实例。
 * 配置内容包括模板分隔符、目录路径、模板后缀、变量数组等。
 * 
 * @access public
 * @return void
 */
public function __construct()
```

### __call()

```php
/**
 * 魔术方法，调用其他功能注册来的方法
 * 
 * 用于动态调用视图相关的钩子函数，如果方法不存在则显示错误信息。
 * 
 * @param string $name 方法名称
 * @param array $arguments 方法参数
 * @access public
 * @return void
 */
public function __call($name, $arguments)
```

### display()

```php
/**
 * 显示模板内容或返回API数据
 *
 * 根据系统运行模式决定显示Web视图或返回API格式数据
 * - Web模式：渲染模板并发送HTML响应
 * - API模式：格式化数据并准备返回
 *
 * @param string|null $name 模板名称
 * @param string $cacheId 缓存ID
 * @param bool $single 是否单独处理
 * @param string|null $templateDir 自定义模板目录
 * @access public
 * @return void
 */
public function display(?string $name=null, string $cacheId = '', bool $single = false, ?string $templateDir = null): void
```

### fetch()

```php
/**
 * 执行渲染模板文件
 *
 * 渲染模板内容并返回渲染后的字符串，不直接输出。
 *
 * @param string|null $name 模板名称
 * @param string $cacheId 缓存ID
 * @param bool $single 是否单独处理
 * @param string|null $templateDir 模板目录
 * @access public
 * @return string 渲染后的模板内容
 */
public function fetch(?string $name=null, string $cacheId = '', bool $single = false, ?string $templateDir = null): string
```

### assign()

```php
/**
 * 模板变量分配
 *
 * 将变量分配到模板中，支持单个变量或数组批量分配。
 * 同时处理API数据和函数回调。
 *
 * @param string|array $args 键名或数组
 * @param mixed $value 键值
 * @param bool $apidata 是否处理API数据
 * @param string $call_func 回调函数名
 * @access public
 * @return void
 */
public function assign($args, $value = null, bool $apidata = true, string $call_func = ''): void
```

### compileFile()

```php
/**
 * 模板编译方法
 *
 * 编译模板文件，处理模板内容并返回编译后的结果。
 *
 * @param string|null $template_name 模板名称
 * @param string $cacheId 缓存ID
 * @param bool $single 是否单独处理
 * @param string|null $templateDir 模板目录
 * @param string $compile_dir 编译目录
 * @access public
 * @return string 编译后的模板内容
 */
public function compileFile(?string $template_name, string $cacheId = '', bool $single = false, ?string $templateDir = null, string $compile_dir = ''): string
```

### templateExist()

```php
/**
 * 检查模板文件是否存在
 *
 * 验证指定的模板文件是否存在，并处理千人千面、404页面等特殊情况
 *
 * @param string $name 模板名称
 * @param bool $single 是否单独处理
 * @access public
 * @return bool 是否存在
 */
public function templateExist(string $name, bool $single = false): bool
```

### setVal()

```php
/**
 * 设置对象变量
 *
 * 直接设置View对象的属性值
 *
 * @param string $key 键名
 * @param mixed $value 键值
 * @access public
 * @return void
 */
public function setVal(string $key, $value=null): void
```

### assignByRef()

```php
/**
 * 设置模板全局变量
 *
 * 设置可以在模板中直接使用的全局变量
 *
 * @param string $key 键名
 * @param mixed $value 键值
 * @access public
 * @return void
 */
public function assignByRef(string $key, $value=null): void
```

### registerOutputfilter()

```php
/**
 * 注册输出过滤器
 *
 * 注册模板输出前需要执行的过滤函数
 *
 * @param string $funcname 函数名
 * @access public
 * @return void
 */
public function registerOutputfilter(string $funcname): void
```

### assignClear()

```php
/**
 * 清除定义的变量
 *
 * 清除指定的模板变量，可以是单个变量名或变量数组
 *
 * @param string $tplVar 变量名或变量数组
 * @access public
 * @return void
 */
public function assignClear(string $tplVar): void
```

### assignClearAll()

```php
/**
 * 清除所有的变量
 *
 * 清除所有已分配的模板变量
 *
 * @access public
 * @return void
 */
public function assignClearAll(): void
```

### apidataClear()

```php
/**
 * 清除API数据变量
 *
 * 清除指定的API数据变量，可以是单个变量名或变量数组
 *
 * @param string $tplVar 变量名或变量数组
 * @access public
 * @return void
 */
public function apidataClear(string $tplVar): void
```

### apidataClearAll()

```php
/**
 * 清除所有API数据变量
 *
 * 清除所有已设置的API数据变量
 *
 * @access public
 * @return void
 */
public function apidataClearAll(): void
```

### halt()

```php
/**
 * 信息提示
 *
 * 显示错误信息并终止执行
 *
 * @param string|null $msg 错误信息
 * @access public
 * @return void
 */
public function halt(?string $msg): void
```

## 内部方法

以下是View类中的内部方法（私有方法），仅供类内部使用：

### makeContent()

```php
/**
 * 执行显示模板文件
 *
 * 处理模板内容的核心方法，包括路径设置、变量分配、模板编译等。
 *
 * @param string|null $name 模板名称
 * @param string $cacheId 缓存ID
 * @param bool $single 是否单独处理
 * @param string|null $templateDir 模板目录
 * @access private
 * @return void
 */
private function makeContent(?string $name=null, string $cacheId = '', bool $single = false, ?string $templateDir = null): void
```

### templateName()

```php
/**
 * 设置模板名称
 *
 * 根据控制器、操作和分隔符生成模板名称，并处理千人千面个性化需求。
 *
 * @param string|null $name 模板名称
 * @param bool $single 是否单独处理
 * @access private
 * @return void
 */
private function templateName(?string $name = null, bool $single = false): void
```

### templatePath()

```php
/**
 * 获取模板路径
 *
 * 根据模板名称和配置计算并返回模板文件的完整路径
 * 优先检查插件目录，然后是默认目录
 *
 * @access private
 * @return string 模板路径
 */
private function templatePath(): string
```

### cachPath()

```php
/**
 * 设置模板缓存路径
 *
 * 根据模板名称和缓存ID生成缓存文件路径
 * 支持纯静态缓存和动态缓存两种模式
 *
 * @param string $cacheId 缓存ID
 * @access private
 * @return void
 */
private function cachPath(string $cacheId): void
```

### compilePath()

```php
/**
 * 设置模板编译路径
 *
 * 根据模板名称和缓存标识生成编译文件路径
 * 支持千人千面特性
 *
 * @param string $template_name 模板名称
 * @param string $compile_dir 编译目录
 * @param string $cacheId 缓存ID
 * @access private
 * @return void
 */
private function compilePath(string $template_name, string $compile_dir = '', string $cacheId = ''): void
```

### compileContent()

```php
/**
 * 编译模板
 *
 * 编译模板内容，处理模板标签和变量
 *
 * @param string $content 模板内容
 * @param bool $nocache 是否不缓存
 * @access private
 * @return string 编译后的内容
 */
private function compileContent(string $content, bool $nocache = false): string
```

### compileTag()

```php
/**
 * 标签编译
 *
 * 编译模板标签，根据不同的标签类型调用不同的编译方法
 *
 * @param string $templateTag 标签内容
 * @param bool $nocache 是否不缓存
 * @access private
 * @return string 编译后的标签
 */
private function compileTag(string $templateTag, bool $nocache = false): string
```

### compileVarTag()

```php
/**
 * 变量编译
 *
 * 编译模板中的变量字符串，支持基本变量、数组变量和特殊变量（如request、server等）
 * 兼容foreach循环中的变量访问
 *
 * @param string $tagStr 变量字符串（如$var、$var.key等）
 * @return string 编译后的PHP变量表达式
 * @access private
 * @example $var -> \$this->viewVars['var']
 * @example $var.key -> \$this->viewVars['var']['key']
 * @example $request.name -> \$_REQUEST['name']
 */
private function compileVarTag($tagStr): string
```

### compileVariableTag()

```php
/**
 * 变量标签编译
 *
 * 编译模板中的变量输出标签，支持变量修饰符
 *
 * @param string $templateTag 变量标签字符串
 * @return string 编译后的PHP代码
 * @access private
 * @example <{$var}> -> <?php echo \$this->viewVars['var']; ?>
 * @example <{$var.key}> -> <?php echo \$this->viewVars['var']['key']; ?>
 */
private function compileVariableTag(string $templateTag): string
```

### compileTokenTag()

```php
/**
 * token标签编译
 *
 * 编译模板中的token标签，用于生成安全令牌
 *
 * @param string $tagCommand 标签命令
 * @param bool $nocache 是否不缓存
 * @access private
 * @return string 编译后的PHP代码
 * @example <{_token_}> -> <?php takeToken(false); ?>
 * @example <{__token__}> -> <?php takeToken(); ?>
 * @example <{_csrf_}> -> <?php takeToken(false, "csrf"); ?>
 */
private function compileTokenTag(string $tagCommand,bool $nocache): string
```

### compileIfTag()

```php
/**
 * if标签编译
 *
 * 编译模板中的if、else、elseif和/if标签
 *
 * @param string $tagCommand 标签命令(if/else/elseif//if)
 * @param string $templateTag 标签内容
 * @return string 编译后的PHP代码
 * @access private
 * @example <{if $var > 0}>条件内容<{/if}>
 * @example <{if $var > 0}>条件1<{elseif $var < 0}>条件2<{else}>条件3<{/if}>
 */
private function compileIfTag(string $tagCommand,string $templateTag): string
```

### compileForeachTag()

```php
/**
 * foreach标签编译
 *
 * 编译模板中的foreach、foreachelse和/foreach标签
 *
 * @param string $tagCommand 标签命令(foreach/foreachelse//foreach)
 * @param array $parseVar 解析后的标签参数数组
 * @return string 编译后的PHP代码
 * @access private
 * @example <{foreach from="$list" item="item"}>...<{/foreach}>
 * @example <{foreach from="$list" item="item" key="key"}>...<{/foreach}>
 * @example <{foreach from="$list" item="item" name="loop"}>...<{foreachelse}>...<{/foreach}>
 */
private function compileForeachTag(string $tagCommand, array $parseVar): string
```

### compileLoopTag()

```php
/**
 * loop和volist标签编译
 *
 * 编译模板中的loop、/loop、volist和/volist标签（内部复用 foreach 编译）
 *
 * @param string $tagCommand 标签命令(loop/volist//loop//volist)
 * @param array $templateTag 解析后的标签参数数组
 * @return string 编译后的PHP代码
 * @access private
 * @example <{loop from="$list" item="item"}>...<{/loop}>
 * @example <{volist from="$list" item="item"}>...<{/volist}>
 * @example <{volist name="providers" id="p"}>...<{/volist}>
 */
private function compileLoopTag(string $tagCommand,array $templateTag): string
```

### compileDefineTag()

```php
/**
 * define标签编译
 *
 * 编译模板中的define、defined、notdefine和/defined、/notdefine标签
 *
 * @param string $tagCommand 标签命令(define/defined/notdefine//defined//notdefine)
 * @param array $templateTag 解析后的标签参数数组
 * @return string 编译后的PHP代码
 * @access private
 * @example <{define name="CONST_NAME" value="constant_value"}> -> <?php define('CONST_NAME', 'constant_value'); ?>
 * @example <{defined name="CONST_NAME"}>...<{/defined}>
 * @example <{notdefine name="CONST_NAME"}>...<{/notdefine}>
 */
private function compileDefineTag(string $tagCommand,array $templateTag): string
```

### compileEmptyTag()

```php
/**
 * empty和notempty标签编译
 *
 * 编译模板中的empty和notempty标签，用于条件判断
 *
 * @param string $tagCommand 标签命令(empty/notempty//empty//notempty)
 * @param array $templateTag 解析后的标签参数数组
 * @return string 编译后的PHP代码
 * @access private
 * @example <{empty name="$list"}>列表为空<{/empty}>
 * @example <{notempty name="$list"}>列表不为空<{/notempty}>
 */
private function compileEmptyTag(string $tagCommand,array $templateTag): string
```

### compileIncludeTag()

```php
/**
 * include标签编译
 *
 * 编译模板中的include标签，用于包含其他模板文件
 *
 * @param string $tagCommand 标签命令(include)
 * @param array $parseVar 解析后的变量数组
 * @return string 编译后的PHP代码
 * @access private
 * @example <{include file="header.html"}> -> <?php $this->viewInclude(array('file' => "header.html", 'vars' => array())); ?>
 * @example <{include file="list.html" var1="value1" var2="$var"}> -> 包含带参数的模板
 */
private function compileIncludeTag(string $tagCommand,array $parseVar): string
```

### compileImportTag()

```php
/**
 * import标签编译
 *
 * 编译模板中的import标签，用于导入CSS、JS等静态资源
 *
 * @param string $tagCommand 标签命令(import)
 * @param array $parseVar 解析后的变量数组
 * @return string 编译后的HTML标签
 * @access private
 * @example <{import type="css" file="style.main"}> -> <link rel="stylesheet" type="text/css" href="/Public/style/main.css" />
 * @example <{import type="js" file="lib.jquery"}> -> <script type="text/javascript" src="/Public/lib/jquery.js"></script>
 * @example <{import type="jpg" file="images.banner"}> -> <img src="/Public/images/banner.jpg" />
 */
private function compileImportTag(string $tagCommand,array $parseVar): string
```

### compileParseVar()

```php
/**
 * 解析参数数组
 *
 * 解析标签中的参数数组，将等号前后的内容转换为键值对
 *
 * @param array|string $tagStr 参数数组或字符串
 * @return array|string 解析后的参数数组或原始字符串
 * @access private
 */
private function compileParseVar($tagStr): array|string
```

### viewInclude()

```php
/**
 * 包含模板文件
 *
 * 安全地包含指定的模板文件，并传递变量
 *
 * @param array $parse 包含文件路径和变量的数组，包含file键（模板文件路径）和vars键（变量数组）
 * @access private
 * @return void
 * @throws Exception 当模板路径无效时抛出异常
 */
private function viewInclude(array $parse): void
```

### data2api()

```php
/**
 * 处理API接口数据
 *
 * 管理API接口数据的存储、获取和清除
 * 支持函数回调处理数据
 *
 * @param string|null $key 键名
 * @param mixed $value 键值
 * @param string|null $call_func 回调函数名
 * @access private
 * @return mixed 数据或null
 */
private function data2api(?string $key=null, $value=null, ?string $call_func=null)
```

## 使用说明

### 基本用法

在控制器中使用View类进行模板渲染：

```php
// 分配变量
$this->assign('title', '网站标题');
$this->assign('list', $dataList);

// 显示模板
$this->display(); // 自动使用控制器/操作名对应的模板

// 或者指定模板
$this->display('custom/template');

// 获取渲染后的内容但不输出
$content = $this->fetch();
```

### 变量分配

```php
// 单个变量分配
$this->assign('name', '张三');

// 批量分配
$this->assign([
    'age' => 25,
    'gender' => '男',
    'hobbies' => ['读书', '旅行', '编程']
]);
```

### 模板标签使用

在模板文件中使用标签：

```html
<!-- 变量输出 -->
<{$title}>

<!-- 条件判断 -->
<{if $age > 18}>
    <p>已成年</p>
<{else}>
    <p>未成年</p>
<{/if}>

<!-- 循环遍历 -->
<{foreach from=$hobbies item=hobby}>
    <li><{$hobby}></li>
<{/foreach}>

<!-- 包含模板 -->
<{include file="common/header"}>

<!-- 导入资源 -->
<{import type="css" file="style/common"}>
<{import type="js" file="js/common"}>
```

### 模板标签列表

以下是Candy框架模板引擎支持的所有标签，按功能分类整理：

#### 1. 变量输出标签

- **基本变量输出**
  ```html
  <{$变量名}>
  ```
  功能：输出模板变量的值
  示例：`<{$title}>` 输出标题变量

#### 2. 条件判断标签

- **if 标签**
  ```html
  <{if 条件表达式}>
      <!-- 条件成立时的内容 -->
  <{/if}>
  ```
  功能：条件判断
  示例：`<{if $age > 18}>已成年<{/if}>`

- **if-elseif-else 标签**
  ```html
  <{if 条件1}>
      <!-- 条件1成立时的内容 -->
  <{elseif 条件2}>
      <!-- 条件2成立时的内容 -->
  <{else}>
      <!-- 所有条件不成立时的内容 -->
  <{/if}>
  ```
  功能：多条件判断
  示例：`<{if $var > 0}>正数<{elseif $var < 0}>负数<{else}>零<{/if}>`

- **empty 标签**
  ```html
  <{empty name="$变量"}>
      <!-- 变量为空时的内容 -->
  <{/empty}>
  ```
  功能：判断变量是否为空
  示例：`<{empty name="$list"}>列表为空<{/empty}>`

- **notempty 标签**
  ```html
  <{notempty name="$变量"}>
      <!-- 变量不为空时的内容 -->
  <{/notempty}>
  ```
  功能：判断变量是否不为空
  示例：`<{notempty name="$list"}>列表不为空<{/notempty}>`

#### 3. 循环遍历标签

- **foreach 标签**
  ```html
  <{foreach from="$数据源" item="循环变量" [key="键名变量"] [name="循环名称"]}>
      <!-- 循环内容 -->
  <{foreachelse}>
      <!-- 数据源为空时的内容 -->
  <{/foreach}>
  ```
  功能：遍历数组或对象
  示例：`<{foreach from="$list" item="item" key="key"}>键：<{$key}>, 值：<{$item}><{/foreach}>`

- **loop 标签**
  ```html
  <{loop from="$数据源" item="循环变量"}>
      <!-- 循环内容 -->
  <{/loop}>
  ```
  功能：遍历数组或对象（与foreach类似，内部复用 foreach 编译）
  示例：`<{loop from="$list" item="item"}>值：<{$item}><{/loop}>`

- **volist 标签**
  ```html
  <!-- Candy 写法（与 foreach/loop 一致） -->
  <{volist from="$数据源" item="循环变量" [key="键名"]}>
      <!-- 循环内容 -->
  <{/volist}>

  <!-- ThinkPHP 兼容写法 -->
  <{volist name="数据源" id="循环变量"}>
      <!-- 循环内容 -->
  <{/volist}>
  ```
  功能：遍历数组或对象（与 foreach / loop 等价）
  示例：
  - `<{volist from="$list" item="item"}>值：<{$item}><{/volist}>`
  - `<{volist name="providers" id="p"}><{$p.name}><{/volist}>`
  - `<{volist name="vo.attachments" id="att"}><{$att.url}><{/volist}>`

#### 4. 模板包含与资源导入标签

- **include 标签**
  ```html
  <{include file="模板文件路径" [var1="值1" var2="值2"]}>
  ```
  功能：包含其他模板文件
  示例：`<{include file="header.html" title="页面标题"}>`

- **import 标签**
  ```html
  <{import type="资源类型" file="资源文件路径" [basepath="基础路径"]}>
  ```
  功能：导入CSS、JS等静态资源
  示例：`<{import type="css" file="style/main"}>`

#### 5. 常量与定义标签

- **define 标签**
  ```html
  <{define name="常量名" value="常量值"}>
  ```
  功能：定义常量
  示例：`<{define name="SITE_NAME" value="Candy框架"}>`

- **defined 标签**
  ```html
  <{defined name="常量名"}>
      <!-- 常量已定义时的内容 -->
  <{/defined}>
  ```
  功能：判断常量是否已定义
  示例：`<{defined name="DEBUG_MODE"}>调试模式已开启<{/defined}>`

- **notdefine 标签**
  ```html
  <{notdefine name="常量名"}>
      <!-- 常量未定义时的内容 -->
  <{/notdefine}>
  ```
  功能：判断常量是否未定义
  示例：`<{notdefine name="DEBUG_MODE"}>调试模式未开启<{/notdefine}>`

#### 6. 安全标签

- **token 标签**
  ```html
  <{_token_}>
  ```
  功能：生成安全令牌
  示例：`<{_token_}>` 生成隐藏的token字段

- **__token__ 标签**
  ```html
  <{__token__}>
  ```
  功能：生成安全令牌（带name属性）
  示例：`<{__token__}>` 生成带name属性的token字段

- **csrf 标签**
  ```html
  <{_csrf_}>
  ```
  功能：生成CSRF令牌
  示例：`<{_csrf_}>` 生成隐藏的CSRF令牌字段

- **__csrf__ 标签**
  ```html
  <{__csrf__}>
  ```
  功能：生成CSRF令牌（带name属性）
  示例：`<{__csrf__}>` 生成带name属性的CSRF令牌字段

### 缓存控制

```php
// 启用缓存
G('CSTART', 1);
// 设置缓存时间（秒）
G('CTIME', 3600);

// 显示带缓存的模板
$this->display('index', 'cache_key');
```

### API模式使用

```php
// 在API模式下分配数据
$this->assign('data', $apiData);

// 自动格式化并返回JSON数据
$this->display();
```

## 变量修饰器

变量修饰器用于对模板中的变量进行格式化和处理，支持通过管道符(`|`)连接多个修饰器。

### 使用语法

```html
<{$变量名|修饰器名:参数1:参数2}>
```

### 修饰器分类

#### 1. 日期时间处理

##### date_format - 日期格式化

功能：将时间戳或日期字符串格式化为指定的日期格式

参数：
- `string`：时间戳或日期字符串
- `format`：日期格式（默认：Y-m-d H:i:s）
- `ext`：扩展参数1（可选）
- `ext2`：扩展参数2（可选）

示例：
```html
<{$time|date_format:Y-m-d}>
<{$time|date_format:H:i:s}>
<{$time|date_format:Y-m-d H:i:s}>
```

##### time_format - 相对时间格式化

功能：将时间戳转换为相对时间描述（如：5分钟前、1小时前）

参数：
- `time`：时间戳
- `format`：保留参数（未使用）

示例：
```html
<{$time|time_format}>
```

##### age_format - 年龄计算

功能：根据生日时间戳计算年龄

参数：
- `birthday`：生日时间戳
- `format`：保留参数（未使用）

示例：
```html
<{$birthday|age_format}>
```

#### 2. 字符串处理

##### truncate - 字符串截断

功能：将字符串截断到指定长度，并添加省略号

参数：
- `string`：要截断的字符串
- `length`：截断长度（默认：80）
- `etc`：省略符号（默认：...）
- `break_words`：是否强制截断单词（默认：false）
- `middle`：是否在中间截断（默认：false）

示例：
```html
<{$content|truncate:50}>
<{$content|truncate:100:'...':true}>
```

##### default - 默认值设置

功能：当变量为空时设置默认值

参数：
- `string`：变量值
- `default`：默认值（支持nowtime和nowdate特殊值）

示例：
```html
<{$name|default:'未设置'}>
<{$create_time|default:'nowdate'}>
```

##### round - 数值四舍五入

功能：对数值进行四舍五入处理

参数：
- `string`：数值字符串
- `format`：保留小数位数（默认：0）

示例：
```html
<{$price|round}>
<{$price|round:2}>
```

#### 3. 编码解码

##### base64_encode - Base64编码

功能：对字符串进行Base64编码

参数：
- `string`：要编码的字符串

示例：
```html
<{$data|base64_encode}>
```

##### base64_decode - Base64解码

功能：对Base64编码的字符串进行解码

参数：
- `string`：要解码的Base64字符串

示例：
```html
<{$data|base64_decode}>
```

##### urlencode - URL编码

功能：对字符串进行URL编码

参数：
- `string`：要编码的字符串

示例：
```html
<{$url|urlencode}>
```

##### urldecode - URL解码

功能：对URL编码的字符串进行解码

参数：
- `string`：要解码的URL字符串
- `title`：保留参数（未使用）

示例：
```html
<{$url|urldecode}>
```

##### gbk2utf8 - GBK转UTF-8编码

功能：将GBK编码的字符串转换为UTF-8编码

参数：
- `string`：GBK编码的字符串

示例：
```html
<{$content|gbk2utf8}>
```

#### 4. HTML处理

##### html_format - HTML标签过滤

功能：过滤字符串中的HTML标签

参数：
- `string`：包含HTML标签的字符串

示例：
```html
<{$content|html_format}>
```

##### htmlspecialchars_decode - HTML特殊字符解码

功能：将HTML特殊字符转换为原始字符

参数：
- `string`：包含HTML特殊字符的字符串

示例：
```html
<{$content|htmlspecialchars_decode}>
```

##### br2nl - 换行符转换

功能：移除字符串中的<br>标签

参数：
- `string`：包含<br>标签的字符串

示例：
```html
<{$content|br2nl}>
```

#### 5. 表单相关

##### checked - 复选框选中状态

功能：判断复选框是否应该被选中

参数：
- `string`：当前值
- `arr`：可选中值（数组或逗号分隔的字符串）

示例：
```html
<input type="checkbox" name="status" value="1" <{$status|checked:1}>>
<input type="checkbox" name="tag" value="tag1" <{$tag|checked:'tag1,tag2'}>>
```

#### 6. 链接处理

##### url_format - URL参数过滤

功能：过滤URL中的指定参数

参数：
- `url`：原始URL
- `format`：要过滤的参数名

示例：
```html
<{$url|url_format:page}>
```

##### pic2img - 图片URL转img标签

功能：将图片URL转换为img标签

参数：
- `string`：图片URL（支持逗号分隔的多个URL）
- `title`：图片标题（默认：空）

示例：
```html
<{$image_url|pic2img}>
<{$image_url|pic2img:'图片描述'}>
```

#### 7. 数据关联

##### linkage - 联动数据处理

功能：处理联动数据（如地区联动）

参数：
- `string`：联动数据值（支持逗号分隔）
- `parse_var`：解析参数（格式：type/value）
- `viewVars`：视图变量（内部使用，`assign()` 与编译产物一致）

示例：
```html
<{$province_id|linkage:'province'}>
<{$city_id|linkage:'city/value'}>
```
## 注意事项

1. **模板缓存机制**：模板编译后的文件会被缓存，修改模板后需要清除缓存或禁用缓存进行开发调试。
2. **模板标签格式**：默认使用 `<{$变量名}>` 格式输出变量，不要与其他模板引擎的标签格式混淆。
3. **模板目录结构**：模板文件默认位于控制器对应的目录中，可通过配置文件修改默认模板路径。
4. **千人千面功能**：支持个性化模板，但需要正确配置相关参数。
5. **API模式与Web模式**：系统会根据运行环境自动切换，确保在API模式下只分配必要的数据。
6. **缓存时间设置**：使用 `G('CTIME', 时间)` 设置合理的缓存时间，避免频繁编译影响性能。
7. **输出过滤器**：可以通过 `registerOutputfilter` 注册自定义过滤器对输出内容进行处理。
8. **内存管理**：处理大量模板变量时，注意及时清理不需要的变量以优化内存使用。

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-07-26 11:31:47