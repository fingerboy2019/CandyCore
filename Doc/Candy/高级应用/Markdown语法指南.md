---
title: Markdown语法指南
description: Candy 文档站 Markdown 扩展语法完整说明与示例展示
print: true
download: true
---

# Markdown语法指南{#h1-id .h1-class style='color: red' data-custom='value'}

## 1. 标准语法

标准语法包括原始Markdown、CommonMark规范和GitHub Flavored Markdown (GFM)中定义的核心特性，具有广泛的兼容性。

### 1.1 基础语法

#### 1.1.1 标题

```markdown{#id}
# 一级标题
## 二级标题
### 三级标题
#### 四级标题
##### 五级标题
###### 六级标题
```

#### 1.1.2 段落与换行

```markdown
这是一个段落。

这是另一个段落，  中间有空行分隔。

这是一行文本  
这是同一段落的下一行，行尾有两个空格。  
```
这是一个段落。

这是另一个段落，  中间有空行分隔。

这是一行文本  
这是同一段落的下一行，行尾有两个空格。  

#### 1.1.3 强调

```markdown
**粗体文本**
*斜体文本*
***粗斜体文本***
__下划线文本__
~~删除线文本~~
==高亮标记==  
!!剧透!!
2^3^ = 8
H~2~O是水的化学式
@@ctrl + p@@  
后面的字>>小一号<<
```
**粗体文本**  
*斜体文本*  
***粗斜体文本***  
__下划线文本__  
~~删除线文本~~  
==高亮标记==  
!!剧透!!  
2^3^ = 8  
H~2~O是水的化学式  
@@ctrl + p@@  
后面的字>>小一号<<

### 1.2 列表

#### 1.2.1 无序列表

```markdown
- 列表项1
- 列表项2
- 列表项3

或

* 列表项1
* 列表项2
* 列表项3

或

+ 列表项1
+ 列表项2
+ 列表项3
```

- 列表项1
- 列表项2
- 列表项3

或

* 列表项1
* 列表项2
* 列表项3

或

+ 列表项1
+ 列表项2
+ 列表项3

#### 1.2.2 有序列表

```markdown
1. 列表项1
2. 列表项2
3. 列表项3

或

1. 列表项1
1. 列表项2
1. 列表项3
```
1. 列表项1
2. 列表项2
3. 列表项3

或

1. 列表项1
1. 列表项2
1. 列表项3

#### 1.2.3 嵌套列表

```markdown
- 一级列表项1
  - 二级列表项1
    - 三级列表项1
  - 二级列表项2
- 一级列表项2
```
- 一级列表项1
  - 二级列表项1
    - 三级列表项1
  - 二级列表项2
- 一级列表项2

#### 1.2.4 任务列表

```markdown
- [x] 已完成任务
- [ ] 未完成任务
- [x] 另一个已完成任务
```
- [x] 已完成任务
- [ ] 未完成任务
- [x] 另一个已完成任务

#### 1.2.5 列表属性

为列表添加属性，支持`{{attrs}} {{attrs}}`语法，第一个属性块用于所有的`<li>` 第二个属性块将直接应用于`<ul>`或`<ol>`标签。

##### 1.2.5.1 基本语法

在列表第一行添加`{{attrs}} {{attrs}}`语法：

```markdown
- 列表项1 {{#list-id .list-class style="color: red;"}}
- 列表项2
```
- 列表项1 {{#list-id .list-class style="color: red;"}}
- 列表项2

其中`attrs`支持：
- ID：`#list-id`
- Class：`.list-class`
- Style：`style="color: red;"`
- 自定义属性：`data-*="value"`

##### 1.2.5.2 用法示例

```markdown
# 无序列表带属性
- 列表项1 {#ul-id .ul-class style="color: red;"}
- 列表项2

# 有序列表带属性
1. 列表项1 {#ol-id .ol-class}
2. 列表项2

# 嵌套列表带属性
- 外层列表项 {#outer-ul .outer-class}
  - 内层列表项 {#inner-ul .inner-class}
  - 内层列表项2

# 任务列表带属性
- [x] 已完成任务 {#task-list .task-class}
- [ ] 未完成任务
```

#### 1.2.6 高级列表功能

Candy 框架的 Markdown 解析器支持以下高级列表功能：

##### 1.2.6.1 混合类型列表

在同一层级可以混合使用不同类型的列表（无序列表和有序列表）：

```markdown
- 无序列表项1
2. 有序列表项2
- 无序列表项3
3. 有序列表项4
```

- 无序列表项1
2. 有序列表项2
- 无序列表项3
3. 有序列表项4

##### 1.2.6.2 增强的任务列表

任务列表支持自动样式添加和 emoji 显示：

```markdown
- [x] 已完成任务
- [ ] 未完成任务

# 带样式的任务列表
- [x] 已完成任务 {#task-list .custom-task}
- [ ] 未完成任务
```

- [x] 已完成任务
- [ ] 未完成任务

当启用 emoji 功能时，任务列表会使用 ✅/🕙 图标代替复选框。

##### 1.2.6.3 复杂嵌套列表

支持多层级嵌套列表，自动处理缩进和列表类型切换：

```markdown
- 一级无序列表项
  1. 二级有序列表项
     - 三级无序列表项
     - 三级无序列表项
  2. 二级有序列表项
     1. 三级有序列表项
     2. 三级有序列表项
- 一级无序列表项
```

- 一级无序列表项
  1. 二级有序列表项
     - 三级无序列表项
     - 三级无序列表项
  2. 二级有序列表项
     1. 三级有序列表项
     2. 三级有序列表项
- 一级无序列表项

##### 1.2.6.4 列表项内联元素支持

列表项中支持所有内联元素（链接、强调、代码等）：

```markdown
- [链接文本](https://example.com) 在列表项中
- *斜体文本* 和 **粗体文本**
- `行内代码` 示例
- 2^3^ = 8 和 H~2~O
```

- [链接文本](https://example.com) 在列表项中
- *斜体文本* 和 **粗体文本**
- `行内代码` 示例
- 2^3^ = 8 和 H~2~O

### 1.3 引用

```html
\> 一级引用
\> > 二级引用
\> > > 三级引用

引用内的列表：
\> - 列表项1
\> - 列表项2
```
> 一级引用
> > 二级引用
> > > 三级引用

引用内的列表：
> - 列表项1
> - 列表项2

#### 1.3.1 引用块属性支持

为引用块添加属性，支持`{attrs}`语法，属性将直接应用于`<blockquote>`标签。

##### 1.3.1.1 基本语法

在引用内容后添加`{attrs}`语法：

```html
\> 引用内容 {#quote-id .quote-class style="border-left: 4px solid #333; padding: 10px;"}
```

其中`attrs`支持：
- ID：`#quote-id`
- Class：`.quote-class`
- Style：`style="border-left: 4px solid #333; padding: 10px;"`
- 自定义属性：`data-*="value"`

##### 1.3.1.2 用法示例

```html
# 基本引用带属性
\> 这是一个带ID和Class的引用 {#basic-quote .info-quote}

# 带样式的引用
\> 这是一个带自定义样式的引用 {style="border-left: 4px solid #007bff; background-color: #f8f9fa; padding: 15px; border-radius: 4px;"}

# 带自定义属性的引用
\> 这是一个带自定义数据属性的引用 {data-type="example" data-priority="high"}

# 嵌套引用带属性
\> 一级引用 {#level1 .primary}
\> > 二级引用 {#level2 .secondary}
\> > > 三级引用 {#level3 .tertiary}
```

### 1.4 代码

#### 1.4.1 行内代码

```markdown
`单行代码`
```
`单行代码`

#### 1.4.2 代码块

```markdown
+```
多行代码块
无语法高亮
+```
```

``` 
多行代码块
无语法高亮
```

#### 1.4.3 语法高亮代码块

```markdown
+```php
function hello() {
    echo "Hello World";
}
+```
```

```php
function hello() {
    echo "Hello World";
}
```

```markdown
+```javascript
console.log("Hello World");
+```
```

```javascript
console.log("Hello World");
```

```html
+```html
<div class="container">
    <p>HTML代码</p>
</div>
+```
```

#### 1.4.4 内联HTML

```markdown
这是一个 <span style="color: red;">红色</span> 文本。
```
这是一个 <span style="color: red;">红色</span> 文本。

#### 1.4.5 四个空格或者Tab键缩进解析为代码

    function hello() {
        echo "Hello World";
    }

### 1.5 链接

#### 1.5.1 行内链接

```markdown
[链接文本](https://example.com)
[链接文本](https://example.com "链接标题")
```
[链接文本](https://example.com)  
[链接文本](https://example.com "链接标题")

#### 1.5.2 引用链接

```markdown
[链接文本][ref]
[另一个链接][ref2]

\[ref]: https://example.com "链接标题"
\[ref2]: https://example.org
```
[链接文本][ref]  
[另一个链接][ref2]

[ref]: https://example.com "链接标题"
[ref2]: https://example.org

#### 1.5.3 自动链接

```markdown
<https://example.com>
<user@example.com>
<tel:13388888888>
```

<https://example.com>  
<user@example.com>  
<tel:13388888888>

#### 1.5.4 相对路径链接

Candy框架的Markdown解析器支持相对路径链接，这允许你在文档中链接到同一项目中的其他文件或资源。

```markdown
[当前目录文件](document.md)
[当前目录文件带标题](document.md "文件标题")
[子目录文件](subdir/document.md)
[父目录文件](../parent/document.md)
[多级目录文件](../../grandparent/document.md)
```
[当前目录文件](document.md)  
[当前目录文件带标题](document.md "文件标题")  
[子目录文件](subdir/document.md)  
[父目录文件](../parent/document.md)  
[多级目录文件](../../grandparent/document.md)

相对路径会自动解析为绝对路径，确保链接在任何环境下都能正确指向目标文件。

#### 1.5.5 锚点链接

锚点链接允许你链接到文档内部的特定部分。

```markdown
[跳转到章节](#1-7-链接)
[带标题的锚点链接](#1-7-链接 "链接章节")
```
[跳转到章节](#1-7-链接)  
[带标题的锚点链接](#1-7-链接 "链接章节")

你也可以在相对路径链接中使用锚点：

```markdown
[链接到其他文档的特定部分](document.md#章节标题)
[链接到父目录文档的特定部分](../parent/document.md#章节标题)
```
[链接到其他文档的特定部分](document.md#章节标题)  
[链接到父目录文档的特定部分](../parent/document.md#章节标题)

### 1.6 图片

#### 1.6.1 当前路径图片

```markdown
![图片描述](title.png)
![图片描述](title.png "图片标题")

引用图片：
![图片描述][imgref]

\[imgref]: title.png "图片标题"
```

![图片描述](title.png)  
![图片描述](title.png "图片标题")

引用图片：  
![图片描述][imgref]

[imgref]: title.png "图片标题"

#### 1.6.2 相对路径图片

Candy框架的Markdown解析器支持相对路径图片，这允许你在文档中引用同一项目中的图片资源。

```markdown
![当前目录图片](title.png)
![当前目录图片带标题](title.png "图片标题")
![子目录图片](subdir/title.png)
![父目录图片](../parent/title.png)
![多级目录图片](../../grandparent/title.png)

引用相对路径图片：
![图片描述][relimgref]

\[relimgref]: subdir/image.png "图片标题"

```

![当前目录图片](title.png)  
![当前目录图片带标题](title.png "图片标题")  

相对路径图片会自动解析为绝对路径，确保图片在任何环境下都能正确显示。

#### 1.6.3 图片尺寸控制

Candy框架的Markdown解析器支持两种图片尺寸控制语法：标准语法和Obsidian语法，可以精确控制图片的宽度和高度。

#### 1.6.3.1 标准语法

使用`=widthxheight`格式在图片alt文本中指定尺寸：

```markdown
# 同时指定宽度和高度
![测试图片 =200x300](/image.jpg)

# 仅指定宽度（高度自适应）
![测试图片 =200](/image.jpg)

# 仅指定高度（宽度自适应）
![测试图片 =x300](/image.jpg)

# 带标题的图片
![测试图片 =200x300](/image.jpg "图片标题")
```

#### 1.6.3.2 Obsidian语法

使用`|widthxheight`格式在图片alt文本中指定尺寸：

```markdown
# 同时指定宽度和高度
![测试图片|200x300](/image.jpg)

# 仅指定宽度（高度自适应）
![测试图片|200](/image.jpg)

# 仅指定高度（宽度自适应）
![测试图片|x300](/image.jpg)

# 带标题的图片
![测试图片|200x300](/image.jpg "图片标题")
```

两种语法都支持：
- 同时指定宽度和高度：`=200x300`或`|200x300`
- 仅指定宽度：`=200`或`|200`
- 仅指定高度：`=x300`或`|x300`
- 与图片标题结合使用

#### 1.6.4 外部图片引入

你可以使用 shields.io 生成大量的动态徽章，比如项目的 Stars，NPM 版本号，下载量等。

![Version](https://img.shields.io/npm/v/vuepress-theme-hope.svg?style=flat-square&logo=npm)
![Downloads](https://img.shields.io/npm/dm/vuepress-theme-hope.svg?style=flat-square&logo=npm)
![Dependencies](https://img.shields.io/librariesio/release/npm/vuepress-theme-hope?style=flat-square)

当然你可以单纯利用它生成静态的徽章。你可以通过修改参数来定制文字、样式、颜色与大小。

![Theme: vuepress-theme-hope](https://img.shields.io/badge/主题-vuepress--theme--hope-green.svg?style=for-the-badge&logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjEyMDAiIGNsYXNzPSJpY29uIiB2aWV3Qm94PSIwIDAgMzI4MC45NDQgMjgwMCI+CiAgPHBhdGggZD0iTTE2NDUuMzMyIDYwMS4wMDRoMzc1LjY3NUwxMDgxLjgyIDIyMzguNDc4IDE0Mi42MzYgNjAxLjAwNGg3MTguNDc3bDIyMC43MDggMzc5LjcwNCAyMTYuMDEzLTM3OS43MDR6IiBmaWxsPSIjNDFiODgzIiAvPgogIDxwYXRoIGQ9Ik0xNDIuNjM2IDYwMS4wMDRsOTM5LjE4NSAxNjM3LjQ3NCA5MzkuMTg2LTE2MzcuNDc0aC0zNzUuNjc1bC01NjMuNTEgOTgyLjQ4NC01NjguMjA4LTk4Mi40ODR6IiBmaWxsPSIjNDFiODgzIiAvPgogIDxwYXRoIGQ9Ik01MTMuMTg4IDYwMS4wMDRsNTY4LjIwNyA5ODcuMjMgNTYzLjUxMS05ODcuMjNoLTM0Ny40OThsLTIxNi4wMTMgMzc5LjcwNC0yMjAuNzA4LTM3OS43MDR6TTE2MDcuNzkyIDEzMTEuODNsNTk0LjY3OCAyLjI5MyAxODcuMzUzLTMxNi4zMjUtNTk4LjY2MiAyLjI5MnpNMjE5OC41MDYgMTkwOS41N0MyODY3LjQzNiA3MzIuNyAyOTM5LjUwMiA2MDUuNDI2IDI5MzcuODc0IDYwMy43OGMtLjcxNS0uNzIzIDQ1LjMwMy0xLjMxNCAxMDIuMjYyLTEuMzE0czEwMy41NjIuNDI4IDEwMy41NjIuOTUxYzAgLjUyMy0yMDguNTcgMzY3Ljk3OC00NjMuNDkxIDgxNi41NjdMMjIxNi43MTUgMjIzNS42bC0xMDIuMS41OTYtMTAyLjEwMi41OTZ6IiBmaWxsPSIjMzU0OTVlIiAvPgogIDxwYXRoIGQ9Ik0xNjgwLjU2MyAyMjMzLjMyOGMwLTEuMzQgMTY4LjIwOC0yOTguMTQ1IDQ0MC4zNzUtNzc3LjA0OGE0MTM1NjQ1Ljc3NSA0MTM1NjQ1Ljc3NSAwIDAwMzM3LjYxOS01OTQuMTlsMTQ2LjEzLTI1Ny4yNSAxNzAuNzQ2LS4wNCAxNzAuNzQ3LS4wNC01LjUzNiA5Ljc0MWMtMy4wNDQgNS4zNTgtNDMuNzI3IDc3LjMwMi05MC40MDcgMTU5Ljg3NS04NS4zNTYgMTUwLjk5Mi0zMzcuNTYyIDU5NS4xNjMtNjU2LjYwMiAxMTU2LjM3M2wtMTcyIDMwMi41NTktMTcwLjUzNi41ODhjLTkzLjc5NS4zMjItMTcwLjUzNi4wNjktMTcwLjUzNi0uNTY3eiIgZmlsbD0iIzQxYjg4MyIgLz4KICA8cGF0aCBkPSJNMTQyOS43ODMgMTYyNS4zNTFsNTk0LjY3OSAyLjI5MiAxODcuMzUzLTMxNi4zMjQtNTk4LjY2MiAyLjI5MnoiIGZpbGw9IiMzNTQ5NWUiIC8+CiAgPHBhdGggZD0iTTE1MjQuMjA3IDE0NjQuOTAzbDYwOC4yODUgNi44NzcgMTczLjc0Ni0zMjAuOTA5aC02MTkuMDcyeiIgZmlsbD0iIzQxYjg4MyIgLz4KPC9zdmc+)
![Author: Mr.Hope](https://img.shields.io/badge/作者:Mr.Hope-关注-blue.svg?style=social)

#### 1.6.5 abbr提示

```markdown
?[提示语句](显示文字)

```

?[提示语句](显示文字)

### 1.7 水平分割线

```markdown
\---

\***

\___
```
---

***

___

#### 1.7.1 水平线属性支持

为水平线添加属性，支持`{attrs}`语法，属性将直接应用于`<hr>`标签。

##### 1.7.1.1 基本语法

在水平线语法后添加`{attrs}`：

```markdown
\--- {#hr-id .hr-class style="height: 2px; background: #333; margin: 20px 0;"}
```
--- {#hr-id .hr-class style="color: red;height: 2px; background: #333; margin: 20px 0;"}

其中`attrs`支持：
- ID：`#hr-id`
- Class：`.hr-class`
- Style：`style="height: 2px; background: #333;"`
- 自定义属性：`data-*="value"`

##### 1.7.1.2 用法示例

```markdown
# 基本水平线带属性
\--- {#basic-hr .solid-hr}

# 带样式的水平线
\*** {style="height: 3px; background: linear-gradient(to right, #333, #666);"}

# 带自定义属性的水平线
\___ {data-type="section-divider" data-color="gray"}

# 复杂样式的水平线
\--- {id="fancy-hr" class="dashed-hr" style="height: 1px; border: none; border-top: 2px dashed #007bff; margin: 30px 0;"}
```

### 1.8 转义字符

当你需要在Markdown文档中显示特殊字符本身时，可以使用反斜杠（\）进行转义。

```markdown
\\# 这不是一个标题
\\* 这不是斜体文本 *
\\[链接文本\\](https://example.com) 这不是一个链接
\\` 这不是行内代码 `
```
\# 这不是一个标题  
\* 这不是斜体文本 *  
\[链接文本\](https://example.com) 这不是一个链接  
\` 这不是行内代码 `

#### 常见需要转义的特殊字符

| 字符 | 转义写法 | 用途 |
|------|----------|------|
| # | \\# | 标题标记 |
| * | \\* | 强调标记 |
| _ | \\_ | 下划线标记 |
| ` | \\` | 代码标记 |
| ^ | \\^ | 上角标标记 |
| ~ | \\~ | 下角标标记 |
| [ | \\[ | 链接和图片的起始标记 |
| ] | \\] | 链接和图片的结束标记 |
| ( | \\( | 链接URL的起始标记 |
| ) | \\) | 链接URL的结束标记 |
| > | \\> | 引用标记 |
| - | \\- | 无序列表标记 |
| + | \\+ | 无序列表标记 |
| . | \\. | 有序列表标记 |
| ! | \\! | 图片的起始标记 |
| \ | \\‎\\ | 转义字符本身 |

#### 转义字符的注意事项

1. 转义字符仅在特定上下文中起作用，例如在段落文本中，而在代码块中则会被忽略。

2. 当特殊字符作为普通文本出现时，不一定需要转义，只有当它们可能被解释为Markdown语法时才需要。

3. 对于一些不常用的特殊字符，Markdown解析器通常会直接显示，无需转义。

## 2. 扩展语法

扩展语法是对标准语法的扩展，提供了更多高级功能，但可能需要特定的Markdown解析器支持。这些语法在不同平台上的兼容性可能有所差异。

### 2.1 表格

#### 2.1.1 基本表格

```markdown
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |
```

| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |

#### 2.1.2 对齐表格

**注意：** 表头始终居中显示，对齐设置仅作用于数据行。

```markdown
| 左对齐 | 居中对齐 | 右对齐 |
|:------+|:--------:|-------:|
| 内容1 | 内容2 | 内容3 |
| 内容4 | 内容5 | 内容6 |
```

Table: {width: 1000px; columns: 200px, 150px, 200px;}
| 左对齐 | 居中对齐 | 右对齐 |
|:----+|:----:|-----:|
| 内容1 | 内容2 | 内容3 |
| 内容4 | 内容5 | 内容6 |

#### 2.1.3 表格标题

##### 2.1.3.1 表格标题在顶部

使用`Table: `前缀定义表格标题，支持多种格式。

**基本语法格式：**
```
Table: 表格标题（可省略）{表格样式}（可省略） 位置（bottom 可省略 默认top）
```

**注意：**
- `top`可以省略不写，因为是默认值
- 只有需要底部标题时才需要写`bottom`

**基本格式示例：**
```markdown
Table: 这是一个表格标题
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |
```

Table: 这是一个表格标题
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |

**带样式的表格：**
```markdown
Table: 我的表格标题 {width: 80%, class: custom-table}
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |
```

Table: 我的表格标题 {width: 80%, class: custom-table}
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |

**顶部标题（显式指定）：**
```markdown
Table: 显式顶部标题 top
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |
```

Table: 显式顶部标题 top
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |

**顶部标题（默认，省略top）：**
```markdown
Table: 默认顶部标题
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |
```

Table: 默认顶部标题
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |

**完整格式示例：**
```markdown
Table: 完整格式表格 {width: 70%, class: styled-table, id: main-table} top
| 列1 | 列2 | 列3 |
|-------|-------|-------|
| A1 | B1 | C1 |
| A2 | B2 | C2 |
```

Table: 完整格式表格 {width: 70%, class: styled-table, id: main-table} top
| 列1 | 列2 | 列3 |
|-------|-------|-------|
| A1 | B1 | C1 |
| A2 | B2 | C2 |

**简化格式：**
```markdown
Table: {width: 60%}
| 列1 | 列2 |
|------|------|
| 内容1 | 内容2 |
```

Table: {width: 60%}
| 列1 | 列2 |
|------|------|
| 内容1 | 内容2 |

##### 2.1.3.2 底部标题

**基本格式示例：**
```markdown
Table: 底部标题示例 bottom
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |
```

Table: 底部标题示例 bottom
| 表头1 | 表头2 | 表头3 |
|-------|-------|-------|
| 单元格1 | 单元格2 | 单元格3 |
| 单元格4 | 单元格5 | 单元格6 |

**带样式的底部标题：**
```markdown
Table: 城市人口表 {width: 80%; class: population-table} bottom
| 城市 | 人口 |
|------|------|
| 北京 | 2154万 |
| 上海 | 2428万 |
```

Table: 城市人口表 {width: 80%; class: population-table} bottom
| 城市 | 人口 |
|------|------|
| 北京 | 2154万 |
| 上海 | 2428万 |

**完整格式的底部标题：**
```markdown
Table: 水果颜色表 {width: 60%; id: fruit-table} bottom
| 水果 | 颜色 |
|------|------|
| 苹果 | 红色 |
| 香蕉 | 黄色 |
```

Table: 水果颜色表 {width: 60%; id: fruit-table} bottom
| 水果 | 颜色 |
|------|------|
| 苹果 | 红色 |
| 香蕉 | 黄色 |

**仅使用属性的底部标题：**
```markdown
Table: {width: 70%; class: country-table; data-source: "official"} bottom
| 国家 | 首都 |
|------|------|
| 中国 | 北京 |
| 美国 | 华盛顿 |
```

Table: {width: 70%; class: country-table; data-source: "official"} bottom
| 国家 | 首都 |
|------|------|
| 中国 | 北京 |
| 美国 | 华盛顿 |

#### 2.1.4 表格属性和样式

在新格式中，表格属性和样式可以直接在`{attrs}`部分中定义。

**支持的属性类型：**
- ID：`#table-id`
- Class：`.table-class`
- Style：`style="width: 80%; border: 1px solid #ccc;"`
- 自定义属性：`data-*="value"`
- 布局属性：`width: 80%, columns: 150px,150px,150px`

**常用示例：**
```markdown
# 带完整属性的表格
Table: 数据统计表 {#stats-table .data-table style="width: 90%; margin: 20px auto" data-year="2024"}
| 月份 | 收入 | 支出 |
|------|------|------|
| 1月 | 10000 | 8000 |
| 2月 | 12000 | 8500 |

# 纯属性表格（无标题）
Table: {#minimal-table .simple style="width: 60%; border-collapse: collapse"}
| 姓名 | 部门 |
|------|------|
| 张三 | 技术部 |
| 李四 | 市场部 |

# 复杂样式表格
Table: 复杂样式表格 {#advanced-table .styled-table data-theme="modern" data-status="active"} top
| 列1 | 列2 | 列3 |
|------|------|------|
| 数据1 | 数据2 | 数据3 |
| 数据4 | 数据5 | 数据6 |
```

Table: 数据统计表 {#stats-table .data-table style="width: 90%; margin: 20px auto" data-year="2024"}
| 月份 | 收入 | 支出 |
|------|------|------|
| 1月 | 10000 | 8000 |
| 2月 | 12000 | 8500 |

Table: {#minimal-table .simple style="width: 60%; border-collapse: collapse"}
| 姓名 | 部门 |
|------|------|
| 张三 | 技术部 |
| 李四 | 市场部 |

Table: 复杂样式表格 {#advanced-table .styled-table data-theme="modern" data-status="active"} top
| 列1 | 列2 | 列3 |
|------|------|------|
| 数据1 | 数据2 | 数据3 |
| 数据4 | 数据5 | 数据6 |

**属性使用注意事项：**
- 属性语法必须以`{`开头，以`}`结尾
- ID属性必须唯一，不能重复
- Class属性可以多个，使用空格分隔
- 样式值建议使用引号（单引号或双引号）包裹
- 自定义属性名必须符合HTML规范，推荐使用data-前缀与表格标题结合
- 属性可以使用，也可以单独使用
- 支持与表格的合并、分割等高级功能结合使用


#### 2.1.5 合并和分割单元格

支持横向合并（合并左侧单元格）和纵向合并（合并上方单元格）：

- 使用`>>>>`表示合并右侧单元格`<<<<`表示合并左侧单元格（增加colspan）
- 使用`--`表示合并上方单元格（增加rowspan）
- 使用`%%`表示左上右下分割
- 使用`//`表示右上左下分割

```html
Table: 课程表 {columns: 150px, 150px, 150px, 150px, 150px, 150px}
| 课节%%星期 | 周一 | 周二 | 周三 | 周四 | 周五 |
|-------|:----:|:----:|:----:|:----:|:----:|
| 第一节 | 语文 | 数学 | 英语//语文 | 体育 | 音乐 |
| 第二节 | 语文 | -- | 英语//数学 | 体育 | 音乐 |
| 阳光大课间|>>>>|>>>>|>>>>|>>>>|>>>>|
| 第三节 | 语文 | 数学 | 英语 | 体育 | 音乐 |
|<<<<|<<<<|<<<<|<<<<|<<<<|午休|
| 第四节 | 语文 | 数学 | 英语 | 体育 | 音乐 |
| 第五节 | -- | 数学 | 英语 | 个性化 | 音乐 |
| 第六节 | -- | 数学 | 英语 | -- | 音乐 |
| 第七节 | 语文 | 数学 | 英语 | -- | 音乐 |
```

Table: 课程表 {columns: 150px, 150px, 150px, 150px, 150px, 150px;}
| 课节%%星期 | 周一 | 周二 | 周三 | 周四 | 周五 |
|-------|:----:|:----:|:----:|:----:|:----:|
| 第一节 | 语文 | 数学 | 英语//语文 | 体育 | 音乐 |
| 第二节 | 语文 | -- | 英语//数学 | 体育 | 音乐 |
| 阳光大课间|>>>>|>>>>|>>>>|>>>>|>>>>|
| 第三节 | 语文 | 数学 | 英语 | 体育 | 音乐 |
|<<<<|<<<<|<<<<|<<<<|<<<<|午休|
| 第四节 | 语文 | 数学 | 英语 | 体育 | 音乐 |
| 第五节 | -- | 数学 | 英语 | 个性化 | 音乐 |
| 第六节 | -- | 数学 | 英语 | -- | 音乐 |
| 第七节 | 语文 | 数学 | 英语 | -- | 音乐 |

### 2.2 脚注

```markdown
这是一个带脚注的文本[^1]。

[^1]: 这是脚注的内容。

另一个脚注[^note]

[^note]: 脚注可以包含**格式**和[链接](https://example.com)
```
这是一个带脚注的文本[^1]

[^1]: 这是脚注的内容。

另一个脚注[^note]

[^note]: 脚注可以包含**格式**和[链接](https://example.com)。

### 2.3 定义列表

```markdown
术语1
\: 定义1

术语2
\: 定义2
\: 另一个定义2

```
术语1
: 定义1

术语2
: 定义2
: 另一个定义2

### 2.4 数学公式

```markdown
行内公式：$E = mc^2$

块级公式：
$$
\int_0^1 x^2 dx = \frac{1}{3}
$$

复杂公式：
$$
\frac{1}{\sigma\sqrt{2\pi}} e^{-\frac{(x-\mu)^2}{2\sigma^2}}
$$
```
#### 2.4.1 行内公式

$E = mc^2$

#### 2.4.2 块级公式

$$
\int_0^1 x^2 dx = \frac{1}{3}
$$

#### 2.4.3 复杂公式

$$
\frac{1}{\sigma\sqrt{2\pi}} e^{-\frac{(x-\mu)^2}{2\sigma^2}}
$$

### 2.5 视频嵌入

```markdown
!\[video](https://www.youtube.com/watch?v=VIDEO_ID)
!\[video](https://www.bilibili.com/video/BV1xx411c7mK)
```

### 2.6 自定义容器

#### 2.6.1 提示容器

```markdown
\::: tip
这是一个提示信息
可以包含多行内容
\:::
```

::: tip
这是一个提示信息
可以包含多行内容
:::

添加隔行数据

#### 2.6.2 警告容器

```markdown
\::: warning
这是一个警告信息
需要特别注意
\:::
```
::: warning
这是一个警告信息
需要特别注意
:::

#### 2.6.3 危险容器

```markdown
\::: danger
这是一个危险信息
必须高度重视
\:::
```
::: danger
这是一个危险信息
必须高度重视
:::


#### 2.6.4 重要信息

```markdown
\::: info
这是一个重要信息
\:::
```
::: info
这是一个重要信息
:::

#### 2.6.5 自定义提示

```markdown
\::: info 自定义提示
\:::
```

::: info 自定义提示
:::

### 2.7 Bootstrap5 相关扩展

[Bootstrap5使用指南](./Bootstrap5使用指南)

### 2.8 折叠块

```markdown
\[点击展开查看详细内容]
\: 这是折叠的内容
\: 可以包含多行文本
\: 每行前面需要加冒号

\[另一个折叠块]
\: 折叠内容1
\: 折叠内容2
```
[点击展开查看详细内容]
: 这是折叠的内容
: 可以包含多行文本
: 每行前面需要加冒号

[另一个折叠块]
: 折叠内容1
: 折叠内容2

### 2.9 Emoji表情

```markdown
\:smile: => :smile:
\:heart: => :heart:
\:thumbsup: => :thumbsup:
\:warning: => :warning:
\:danger: => :danger:
\:fire: => :fire:
\:star: => :star:
\:cake: => :cake:
\:pizza: => :pizza:
\:rocket: => :rocket:
```

[Emoji代码表](./Emoji代码表)

### 2.10 图表

[图表使用指南](./图表/图表使用指南)

### 2.11 自动生成功能

#### 2.11.1 面包屑导航

（自动生成，无需手动编写）

#### 2.11.2 目录

默认根据文档标题自动生成浮动目录（页面右上角）。可通过 Front Matter 中的 `toc`、`toc_depth` 控制是否显示及深度，详见 [2.15 页面元数据（Front Matter）](#215-页面元数据front-matter)。

#### 2.11.3 全文搜索

文档站左侧栏搜索框支持**标题 + 正文**全文检索（需通过 `/` 或 `/Doc/` 访问文档站，单独 `Markdown::parse()` 无此 UI）。

| 能力 | 说明 |
|------|------|
| 即时过滤 | 输入时同步过滤侧栏导航标题 |
| 全文检索 | 防抖约 300ms 后检索 `Doc/Candy/` 下全部 `.md` |
| 结果列表 | 侧栏下方展示命中条数、标题与摘要片段（关键词高亮） |
| 无刷新跳转 | 点击结果 AJAX 加载正文，URL 附带 `?q=` 供正文高亮 |
| 索引缓存 | 生产环境缓存至 `Runtime/Doc/search-index.json`；`DEBUG=1` 每次重建 |

**说明：** 带 `password` 的文档不会进入搜索索引；新建或修改文档后，下次搜索会根据文件 `mtime` 自动更新索引。

#### 2.11.4 打印与下载按钮

当页面 Front Matter 设置 `print: true` 或 `download: true` 时，文档站会在**右下角操作区**（主题切换按钮上方）显示固定按钮：

| 按钮 | 元数据 | 行为 |
|------|--------|------|
| 🖨 打印 | `print: true` | 调用浏览器 `window.print()`；打印时自动隐藏侧栏 |
| ⬇ 下载 | `download: true` | 下载当前页面对应的原始 `.md` 源文件 |

下载链接为 `当前页面 URL?download=1`，需经文档站路由并校验文件位于 `Doc/Candy/` 内。受密码保护的文档须先通过验证才能下载。

详见 [打印与下载（`print` / `download`）](#打印与下载print--download)。

### 2.12 元素属性支持

Candy框架支持使用`{attrs}`语法为Markdown元素添加自定义属性，包括ID、class、style和其他HTML属性。这种语法可以应用于标题、图片、链接等多种Markdown元素。

#### 2.12.1 通用属性语法

使用`{attrs}`格式在Markdown元素后添加属性：

```markdown
{#id .class style="key: value" data-attr="value"}
```

- `#id`：设置元素的ID
- `.class`：添加元素的class（可多个）
- `style="key: value"`：设置内联样式
- `data-attr="value"`：添加自定义数据属性

#### 2.12.2 标题属性支持

为标题添加属性：

```markdown
# 一级标题 {#h1-id .h1-class style="color: red" data-custom="value"}
## 二级标题 {#h2-id .h2-class style="font-size: 24px"}
### 三级标题 {#h3-id .h3-class}
```

#### 2.12.3 图片属性支持

为图片添加属性，支持与各种图片尺寸语法结合使用：

```markdown
# 标准图片
![图片描述](image.jpg) {#img-id .img-class style="border: 1px solid #ccc"}

# 带尺寸的图片
![图片描述 =200x300](image.jpg) {#img-size .img-thumbnail}
![图片描述|200x300](image.jpg) {style="border-radius: 8px"}

# 仅宽度或高度
![图片描述 =200](image.jpg) {.img-width}
![图片描述 =x300](image.jpg) {.img-height}

# 引用式图片
![图片描述][ref] {#img-ref .img-reference}

[ref]: image.jpg "图片标题"
```

#### 2.12.4 链接属性支持

为链接添加属性，支持内联链接和引用链接：

```markdown
# 内联链接
[链接文本](https://example.com) {#link-id .link-class style="color: blue"}
[带标题的链接](https://example.com "链接标题") {.link-with-title}

# 引用链接
[引用链接][ref] {#ref-link .link-reference}
[空引用键][] {#empty-ref}

[ref]: https://example.com "引用链接标题"
[空引用键]: https://example.org
```

#### 2.12.5 代码块属性支持

为代码块添加属性，属性将应用于包含代码块的`<div class="code-block">`元素上：

```markdown
# 基本代码块属性
+```php {#code-example .highlight}
echo "Hello World";
+```

# 带样式的代码块
+```javascript {style="border: 2px solid red; background: #f0f0f0"}
console.log("Styled code block");
+```

# 多种属性组合
+```python {#python-demo .demo .python-code style="font-size: 14px" data-lang="python"}
print("Multiple attributes")
+```

# 无语言指定的代码块
+``` {#plain-text .simple}
Plain text content
+```
```

#### 2.12.6 段落属性支持

为段落添加属性，支持单行段落和多行段落：

```markdown
# 单行段落
这是一个普通段落 {#para-id .para-class style="text-align: center; color: blue"}

# 多行段落
这是一个多行段落的第一行  
这是第二行，保留换行  
这是第三行，最后添加属性 {#multi-line-para .multi-class style="line-height: 1.6"}

# 包含内联元素的段落
这是一个包含**粗体**和*斜体*的段落 {.styled-para}

# 仅添加class
这是一个普通段落 {.simple-para}

# 仅添加ID
这是一个普通段落 {#unique-para}
```

注意事项：
- 多行段落的属性必须放在最后一行末尾
- 段落内的换行使用两个空格+换行实现
- 支持与各种内联元素（粗体、斜体、链接等）结合使用
- 所有通用属性语法都适用于段落


### 2.13 文件引入

#### 2.13.1 基本语法

框架支持文件引入功能，可以在Markdown文档中引入其他文件的内容。

```markdown
<!-- @include: filename -->
```

#### 2.13.2 带行范围的引入

可以指定引入文件的特定行范围：

```markdown
<!-- @include: filename{start-end} -->
```

#### 2.13.3 用法示例

1. 引入整个文件：
   ```markdown
   <!-- @include: include-source.md -->
   ```

2. 引入文件的特定行范围：
   ```markdown
   <!-- @include: include-source.md{1-5} -->
   ```

#### 2.13.4 功能说明

- 引入的文件内容会被自动解析为Markdown
- 支持相对路径和绝对路径
- 行范围指定从1开始计数
- 如果文件不存在或不可读，会保留原始的引入标签

#### 2.13.5 注意事项

- 引入的文件必须是Markdown格式或纯文本格式
- 避免循环引入，这会导致解析错误
- 确保引入的文件路径正确，相对于当前文档的位置

### 2.14 幻灯片功能

Candy框架支持将Markdown文档转换为交互式幻灯片，基于Reveal.js实现，支持水平和垂直分页、主题切换和过渡效果。

#### 2.14.1 基本语法

使用`@slidestart`和`@slideend`标签包裹幻灯片内容：

```markdown
@slidestart {theme="black" transition="slide"}

# 幻灯片标题

这是第一张幻灯片的内容

<--->

## 第二张幻灯片

水平分页使用 `<--->` 分隔

<-->

### 垂直子幻灯片

垂直分页使用 `<-->` 分隔

@slideend
```

#### 2.14.2 支持的属性

在`@slidestart`标签中可以配置以下属性：

| 属性名 | 说明 | 默认值 | 可选值 |
|-------|------|-------|-------|
| theme | 幻灯片主题 | black | black, white, league,  beige, sky, night,  serif, simple, solarized,  blood, moon |
| transition | 过渡效果 | slide | none, fade, slide,  convex, concave, zoom |
| width | 幻灯片宽度 | 960 | 数字 |
| height | 幻灯片高度 | 700 | 数字 |
| margin | 外边距 | 0.1 | 0-1之间的小数 |
| minScale | 最小缩放比例 | 0.2 | 数字 |
| maxScale | 最大缩放比例 | 1.5 | 数字 |

#### 2.14.3 分页标记

- **水平分页**：使用 `<--->` 分隔不同的水平幻灯片
- **垂直分页**：使用 `<-->` 分隔同一水平幻灯片下的垂直子幻灯片

#### 2.14.4 完整示例

@slidestart {theme="white" transition="zoom" width="600" height="300"}

## 幻灯片 1

一个有文字和 [链接](https://mister-hope.com) 的段落

<--->

## 幻灯片 2

- 项目 1
- 项目 2

<--->

## 幻灯片 3.1

```js
const a = 1;
```

<-->

## 幻灯片 3.2

$$J(\theta_0,\theta_1) = \sum_{i=0}$$

@slideend


### 2.14 选项卡功能

选项卡功能允许你在文档中创建可切换的内容面板，提高信息组织和阅读体验。

#### 2.14.1 基本语法

使用 `::: tabs` 容器包裹多个 `@tab` 定义，每个 `@tab` 表示一个选项卡：

```markdown
\::: tabs
@tab 选项卡标题1
选项卡内容1

可以包含多行内容

@tab 选项卡标题2
选项卡内容2

支持Markdown格式

- 列表
- 格式化文本

@tab 选项卡标题3
选项卡内容3
\:::
```

::: tabs
@tab 选项卡标题1
测试术语嵌入
:这是一个测试术语，用于展示选项卡功能。

可以包含多行内容

@tab 选项卡标题2
选项卡内容2

支持Markdown格式

- 列表
- 格式化文本

@tab 选项卡标题3
选项卡内容3
:::

#### 2.14.2 默认激活选项卡

使用 `@tab:active` 标记默认激活的选项卡：

```markdown
\::: tabs
@tab 选项卡标题1
选项卡内容1

@tab:active 选项卡标题2
这是默认激活的选项卡内容

@tab 选项卡标题3
选项卡内容3
\:::

```

::: tabs
@tab 选项卡标题1
$$J(\theta_0,\theta_1) = \sum_{i=0}$$

@tab:active 选项卡标题2

```js
const a = 1;
```

@tab 选项卡标题3
选项卡内容3
:::


### 2.15 页面元数据（Front Matter）

Candy 文档站支持在 Markdown 文件**最顶部**使用 YAML Front Matter 声明页面级元数据。解析器会在渲染正文前读取这些字段，用于控制 SEO、布局、资源引入与社交分享等。

Front Matter 使用 `---` 包裹，**必须位于文件第一行**，与正文之间不留空行以外的其他内容：

```markdown
---
title: 页面标题
description: 页面描述
toc: true
toc_depth: 3
---

# 正文从这里开始
```

#### 2.15.1 语法规则

| 规则 | 说明 |
|------|------|
| 位置 | 文件最顶部，以 `---` 开始和结束 |
| 格式 | 扁平 `键: 值` 行，每行一个字段 |
| 布尔值 | 支持 `true` / `false`，也支持 `1` / `0`、`yes` / `no` |
| 数值 | 自动识别为数字，如 `toc_depth: 3` |
| 多路径 | `css`、`js` 可用逗号分隔多个 URL |
| 剥离 | 解析后 Front Matter 不会出现在正文 HTML 中 |

**当前限制：** 解析器为轻量实现，暂不支持嵌套对象（如 `custom:` 下的多级键值）。规划中的嵌套自定义字段尚未落地。

#### 2.15.2 完整示例

```markdown
---
title: Candy框架开发手册
keywords: Candy框架, PHP框架, 开发手册
description: Candy框架是一个轻量级的PHP开发框架，提供快速开发Web应用的能力。
author: 刘森
date: 2024-01-01
update: 2024-06-01
tags: PHP, 框架, 文档
category: 技术文档
toc: true
toc_depth: 3
sidebar: true
theme: dark
style: default
lang: zh-CN
css: /Doc/Static/css/custom.css
js: /Doc/Static/js/custom.js
logo: /Doc/Static/logo.png
favicon: /Doc/Static/favicon.ico
og_title: Candy框架开发手册
og_description: Candy框架是一个轻量级的PHP开发框架，提供快速开发Web应用的能力。
og_image: /Doc/Static/og_image.png
og_url: https://example.com/Doc/
twitter_card: summary_large_image
print: true
download: true
cache: true
cache_time: 3600
password: your-secret
---
```

#### 2.15.3 已支持字段一览

| 分类 | 字段 | 类型 | 默认值 | 作用 |
|------|------|------|--------|------|
| SEO | `title` | 字符串 | 文件名 | 浏览器 `<title>` |
| SEO | `keywords` | 字符串 | — | `<meta name="keywords">` |
| SEO | `description` | 字符串 | — | `<meta name="description">` |
| 文档信息 | `author` | 字符串 | — | 正文顶部作者信息条 |
| 文档信息 | `date` | 字符串 | — | 创建日期（展示用） |
| 文档信息 | `update` | 字符串 | — | 更新日期（展示用） |
| 文档信息 | `tags` | 字符串 | — | 标签（逗号分隔，展示在信息条） |
| 文档信息 | `category` | 字符串 | — | 分类（展示在信息条） |
| 布局 | `toc` | 布尔 | `true` | 是否显示浮动目录 |
| 布局 | `toc_depth` | 整数 1–6 | `3` | 目录收录的最大标题级别 |
| 布局 | `sidebar` | 布尔 | `true` | 是否显示左侧导航栏 |
| 布局 | `lang` | 字符串 | `zh-CN` | `<html lang="...">` |
| 布局 | `theme` | 字符串 | 系统偏好 | 初始深浅色：`light` / `dark` |
| 样式 | `style` | 字符串 | `default` | 加载 `/Doc/Static/css/{style}.css` |
| 样式 | `css` | 字符串/列表 | — | 额外引入 CSS（可逗号分隔） |
| 样式 | `js` | 字符串/列表 | — | 额外引入 JS（可逗号分隔） |
| 样式 | `logo` | URL | — | 侧栏标题旁 Logo 图片 |
| 样式 | `favicon` | URL | — | 页面 favicon |
| 社交 | `og_title` | 字符串 | 回退 `title` | Open Graph 标题 |
| 社交 | `og_description` | 字符串 | 回退 `description` | Open Graph 描述 |
| 社交 | `og_image` | URL | — | 分享预览图 |
| 社交 | `og_url` | URL | — | 规范链接 |
| 社交 | `twitter_card` | 字符串 | — | Twitter Card 类型 |
| 功能 | `print` | 布尔 | `false` | 为 `true` 时显示打印按钮 |
| 功能 | `download` | 布尔 | `false` | 为 `true` 时允许下载原始 `.md` |
| 性能 | `cache` | 布尔 | `false` | 是否缓存解析结果（DEBUG 下强制关闭） |
| 性能 | `cache_time` | 整数 | `0` | 解析缓存 TTL（秒），`0` 为不过期 |
| 权限 | `password` | 字符串 | — | 文档访问密码 |
| 模板 | `tpl` | 路径 | 默认模板 | 自定义 PHP 模板文件（相对 CANDYROOT） |

#### 2.15.4 字段详解

##### SEO 与页面标题

- **`title`**：未设置时，文档站使用「文件名 - Candy框架文档」作为标题；首页 `README.md` 使用「Candy框架文档」。
- **`keywords`** / **`description`**：写入标准 meta 标签，并作为 Open Graph 的缺省回退值。
- 所有输出值均经 HTML 转义，避免 XSS。

##### 作者与日期

`author`、`date`、`update`、`category`、`tags` 会在正文顶部渲染为 `.doc-meta` 信息条，格式示例：

> 作者：刘森 · 创建：2024-01-01 · 更新：2024-06-01 · 分类：技术文档 · 标签：PHP、框架

`tags` 支持逗号分隔：`tags: PHP, 框架, 文档`

仅填写存在的字段，字段之间以 ` · ` 分隔。

##### 目录控制（`toc` / `toc_depth`）

| 设置 | 效果 |
|------|------|
| `toc: true`（默认） | 根据标题生成右上角浮动目录 |
| `toc: false` | 不输出目录 HTML（标题锚点 ID 仍保留，可跳转） |
| `toc_depth: 1` | 仅收录一级标题 `#` |
| `toc_depth: 3`（默认） | 收录一至三级标题 |

`toc_depth` 同时影响标题是否进入目录数组；超出深度的标题不会出现在目录列表中。

##### 侧栏与语言（`sidebar` / `lang`）

- **`sidebar: false`**：隐藏左侧导航与侧栏折叠按钮，正文区域全宽（`body.no-sidebar`）。
- **`lang`**：设置页面 `lang` 属性，如 `en-US`、`zh-CN`、`ja-JP`，利于浏览器与搜索引擎识别页面语言。

##### 样式与资源（`style` / `css` / `js` / `logo` / `favicon`）

- **`style`**：选择文档站内置主题 CSS，对应文件 `/Doc/Static/css/{style}.css`，默认 `default`。可选值取决于 `Doc/Static/css/` 目录下已有的样式文件（如 `default`、`knowledge`）。
- **`css`** / **`js`**：在页面 `<head>` 追加 `<link>` / `<script>`，路径建议使用以 `/` 开头的站点绝对路径。多个文件写法：

```markdown
css: /Doc/Static/a.css, /Doc/Static/b.css
```

- **`logo`**：显示在侧栏站点标题左侧，建议高度约 28px。
- **`favicon`**：写入 `<link rel="icon" href="...">`。

与正文内资源引入的关系见 [2.15.5](#2155-与正文资源引入的关系)。

##### 社交分享（Open Graph / Twitter）

| 字段 | 说明 |
|------|------|
| `og_title` | 缺省使用 `title` |
| `og_description` | 缺省使用 `description` |
| `og_image` | 分享卡片预览图 URL |
| `og_url` | 页面规范 URL |
| `twitter_card` | 如 `summary`、`summary_large_image` |

##### 文档密码（`password`）

设置后，访问者需先提交密码才能查看正文：

1. 首次访问显示密码表单；
2. 密码正确后写入 Session，同一会话内可继续浏览；
3. 密码校验通过前，侧栏 Prev/Next 导航不显示。

密码以明文比对（轻量场景），`doc_key` 由文档内容 MD5 生成，**正文变更后需重新输入密码**。

##### 自定义模板（`tpl`）

覆盖默认 `Candy/Class/Doc/Site/layout.tpl.php`，值为相对于 `CANDYROOT` 的 PHP 模板路径。博客模式同样支持通过元数据或配置切换模板。

##### 初始主题（`theme`）

| 值 | 效果 |
|----|------|
| `light` | 首次访问默认浅色 |
| `dark` | 首次访问默认深色 |

**Front Matter 写法：**

```markdown
---
title: 深色主题示例
theme: dark
style: knowledge
---
```

优先级：**用户 `localStorage` 主题偏好** > **Front Matter `theme`** > **系统 `prefers-color-scheme`**。用户点击右下角 🌙/☀️ 切换后会写入 `localStorage`，覆盖文档默认值。与 `style`（配色 CSS 文件）的区别见 [2.15.6](#2156-style-与-theme-的区别)。

##### 打印与下载（`print` / `download`）

在文件头开启后，文档站会在页面右下角（深色模式切换按钮**上方**）显示操作按钮组 `.doc-action-buttons`。

**Front Matter 写法：**

```markdown
---
title: 可打印与可下载的示例页
print: true
download: true
---
```

| 字段 | 默认值 | 效果 |
|------|--------|------|
| `print` | `false` | 为 `true` 时显示 🖨 按钮，调用 `window.print()` |
| `download` | `false` | 为 `true` 时显示 ⬇ 按钮，下载原始 Markdown |

**打印行为：**

- 点击打印按钮触发浏览器打印对话框；
- `@media print` 下自动隐藏侧栏、浮动目录、主题切换与操作按钮；
- 通过独立 `print.css`（`media="print"`，在主题 CSS 之后加载）覆盖各主题的 `margin-left: 260px`，正文全宽并保留约 `12mm` 页边距；
- 代码块、表格等块级元素尽量不分页断裂。

**下载行为：**

- 按钮链接指向 `当前页面 URL?download=1`（保留 `/` 根模式或 `/Doc/` 兼容模式路径）；
- 服务端校验：文件须为 `Doc/Candy/` 内的 `.md`、元数据 `download: true`、若设 `password` 须已通过 Session 验证；
- 响应头：`Content-Type: text/markdown`，`Content-Disposition: attachment`，文件名为原始 `.md` 名；
- 下载内容为**未渲染的源文件**（含 Front Matter），不是 HTML。

**组合与限制：**

| 场景 | 说明 |
|------|------|
| `password` + `download` | 须先输入密码，通过后打印/下载按钮才会出现 |
| 仅 `parse()` 片段 | 不渲染按钮，仅文档站整页访问有效 |
| `print` / `download` 与 `sidebar: false` | 可同时使用；打印时侧栏本即隐藏 |

**快捷访问下载（无需按钮）：** 在已设置 `download: true` 的页面上，也可直接在地址栏访问 `?download=1` 触发下载（须满足密码等校验）。

##### 解析缓存（`cache` / `cache_time`）

| 设置 | 效果 |
|------|------|
| `cache: true` | 缓存该文档的 HTML 解析结果 |
| `cache_time: 3600` | 缓存 3600 秒后过期（依赖缓存驱动 TTL） |

`DEBUG=1` 时无论 meta 如何设置均不缓存。文件 `mtime` 变化时缓存键自动失效。

**Front Matter 写法：**

```markdown
---
title: 缓存示例
cache: true
cache_time: 3600
---
```

| 设置 | 效果 |
|------|------|
| `cache: true` | 将该文档解析后的 HTML 写入框架缓存驱动 |
| `cache_time: 3600` | 缓存 TTL 3600 秒；`0` 表示不过期（由驱动决定） |
| `cache: false`（默认） | 每次请求重新解析 |

适用场景：内容稳定、扩展语法较多（Mermaid、Chart 等）的长文档，可明显降低 CPU 开销。开发阶段请保持 `DEBUG=1` 或关闭 `cache`。

#### 2.15.5 与正文资源引入的关系

除 Front Matter 外，正文也支持 HTML 注释形式引入资源：

```markdown
<!-- @link /Doc/Static/custom.css -->
<!-- @script /Doc/Static/custom.js -->
```

| 方式 | 适用场景 |
|------|----------|
| Front Matter `css` / `js` | 整页级资源，写在文件头，便于统一管理 |
| 正文 `@link` / `@script` | 仅当前文档特定章节需要的资源 |

两种方式最终都注入页面 `<head>`，可叠加使用。

#### 2.15.6 `style` 与 `theme` 的区别

| 名称 | 含义 |
|------|------|
| `style` | 切换文档站配色 CSS 文件（`default.css`、`knowledge.css` 等） |
| `theme` | 控制页面初始浅色/深色（`light` / `dark`），与右下角手动切换配合使用 |

二者可同时使用：例如 `style: knowledge` + `theme: dark`。

#### 2.15.7 文档站能力速查（P1 已实现）

以下能力通过 Front Matter 或文档站 UI 提供，**均已实现**，可在文档站（`/` 根模式或 `/Doc/` 兼容模式）中直接使用：

| 能力 | 配置 / 入口 | 说明 |
|------|-------------|------|
| 全文搜索 | 侧栏搜索框 | 标题 + 正文检索、结果高亮、无刷新跳转；见 [2.11.3](#2113-全文搜索) |
| 打印 | `print: true` | 右下角 🖨 按钮，`window.print()`，打印时隐藏侧栏 |
| 下载源文 | `download: true` | 右下角 ⬇ 按钮或 `?download=1`，下载原始 `.md` |
| 解析缓存 | `cache` / `cache_time` | 缓存 HTML 解析结果；`DEBUG=1` 时无效 |
| 初始主题 | `theme: light` / `dark` | 首次访问默认深浅色，可被用户手动切换覆盖 |
| 文档信息条 | `author` / `date` / `update` / `tags` / `category` | 正文顶部 `.doc-meta` 展示 |
| 标签分类 | `tags` / `category` | 逗号分隔标签；分类为单行文本 |
| 相对链接 | 正文 `[](相对路径.md)` | 自动按当前页 URL 目录解析，兼容 `/` 与 `/Doc/` |

**推荐组合示例（长文档 / 手册页）：**

```markdown
---
title: 部署手册
author: 运维组
update: 2026-07-01
tags: 部署, 生产环境
category: 运维
toc: true
toc_depth: 2
theme: light
print: true
download: true
cache: true
cache_time: 86400
---
```

#### 2.15.8 规划中字段（尚未实现）

以下字段在 [元数据规划](../../待完成/元数据待完成.md) 中已列出，**当前版本解析后不会生效**，供后续版本参考：

| 分类 | 字段 |
|------|------|
| 功能开关 | `comments`、`edit` |
| 权限 | `role`、`expire` |
| 国际化 | `locale` |
| 统计/评论 | `google_analytics`、`baidu_tongji`、`disqus`、`giscus` |
| 自定义 | `custom`（嵌套键值） |

#### 2.15.9 注意事项

1. Front Matter 必须顶格写 `---`，行前不要有空格或 BOM。
2. 键名区分大小写，请使用小写加下划线（如 `og_title`）。
3. 未识别的键会被解析进元数据数组但模板不消费，不影响正文渲染。
4. 修改 Front Matter 后若页面未更新，可清理 `backend/Runtime/` 缓存（保留 `init.lock`）；开启 `cache: true` 的页面还需等待 TTL 或清理框架缓存。
5. 密码页、打印/下载按钮、全文搜索等效果仅在通过文档站（`/` 或 `/Doc/`）访问时体现；单独调用 `Markdown::parse()` 仅返回正文 HTML 片段。
6. `download: true` 只控制是否允许下载，不会自动把源文件暴露到静态目录；须走 `?download=1` 路由校验。


## 3. 最佳实践

1. **代码块**：使用语法高亮提升可读性
2. **表格**：使用对齐增强数据可读性
3. **列表**：使用适当的嵌套层级
4. **引用**：合理使用引用区分内容
5. **Emoji**：适度使用增强视觉效果
6. **元素属性**：
   - 为重要标题添加唯一ID以便锚点链接
   - 使用class属性统一管理样式，减少内联style
   - 为图片添加描述性ID和class以便样式控制
   - 为链接添加自定义属性以便追踪和交互
7. **页面元数据**：
   - 独立文档页在文件头写 Front Matter，集中管理 SEO 与布局
   - 长文档可设 `toc_depth: 2` 避免目录过长；演示页可设 `sidebar: false` 全宽展示
   - `og_image` 建议 1200×630 左右，使用绝对 URL 或站点根路径
   - 手册、规范类文档可开启 `print: true` + `download: true`，便于导出与离线传阅
   - 稳定长页可设 `cache: true` 与合理 `cache_time`，减轻 Mermaid/图表页解析压力
   - 使用 `tags` / `category` 便于读者在正文顶部快速识别文档归类

## 4. 注意事项

- 代码块前后必须有空行，否则可能导致解析错误
- 表格的表头分隔线不能省略
- 引用的嵌套层级不要超过三级
- 数学公式使用LaTeX语法，确保格式正确
- Mermaid图表需要正确的语法结构
- 元素属性：
  - 属性语法必须以`{`开头，以`}`结尾，确保格式正确
  - ID属性必须唯一，不能重复
  - class属性可以多个，使用空格分隔
  - 样式值需要使用引号（单引号或双引号）包裹
  - 自定义属性名必须符合HTML规范，推荐使用data-前缀
  - 部分Markdown编辑器可能不支持属性预览，以渲染结果为准
- Front Matter：
  - 仅支持扁平键值，不要使用 YAML 嵌套块
  - `password` 变更或正文修改后，访问者可能需要重新验证
  - `theme`、`tags`、`category`、`cache`、`print`、`download` 等字段已生效；`comments`、`edit`、`custom` 嵌套等仍在规划中
  - 表格单元格内链接含 `/` 时不会被误判为斜线分割符；有意使用 `//`、`%%` 切分单元格时勿写 `https://` 同级内容
  - 相对链接 `../` 会按当前页 URL 目录解析，与侧栏导航使用相同的 `/` 或 `/Doc/` 前缀规则

## 5. 兼容性说明

所有语法均兼容标准Markdown规范，并在其基础上进行了扩展。对于不支持扩展语法的Markdown解析器，会降级显示为基本文本格式。

本指南涵盖了Candy框架Markdown解析类支持的所有语法，可根据实际需求选择合适的语法进行文档编写。
