# Candy 响应 Schema

## 概述

Schema 描述 API 返回 `data` 的形状（出参），与 Validate（入参）分离。  
统一外壳仍为 `{code, message, data}`。

走与 Control / Model / Service / Validate **同一套 Runtime 编译**：

| | 路径 / 类名 |
|--|--|
| 源码 | `Application/{App}/Schemas/Group.class.php` → `class Group`（**不写** extends） |
| 运行时 | `Runtime/Schemas/{App}/…` → `class GroupSchema extends \Candy\Extend\Schema\Base` |
| 插件扩展 | `Plugins/{RAPPNAME}/Schemas/Group.class.php`（继承 / 覆写 / 替代） |
| Hook 片段 | 源码 `// hook xxx.php` → `Plugins/*/Hook/` |

## 快速示例

```php
<?php
namespace APP\Schema\Admin;

use Candy\Extend\Schema\{ObjectSchema, ResponseDataType, Schema};

// 短名类：不写 extends、不写 Schema 后缀；二者均由编译追加
class Group
{
    public static function dataType(): ResponseDataType
    {
        return ResponseDataType::Item; // Item | List | Page | None
    }

    public static function openApiSchemas(): array
    {
        return ObjectSchema::make(self::openApiSchemaName())
            ->prop('gid', Schema::integer('权限组 ID'), true)
            ->prop('name', Schema::string('组名'), true)
            ->components();
    }
}
```

使用运行时类名：

```php
$schemas = \APP\Schema\Admin\GroupSchema::openApiSchemas();
$type = \APP\Schema\Admin\GroupSchema::dataType();
```

Apidoc 可通过 `#[ApiContract]` / 约定从 Schema 补全响应 `data`，见 `Doc/Candy/第三方类库/Apidoc使用文档.md` §1.1。

## 生成骨架

```bash
php candy make:schema Admin Group
# → Application/Admin/Schemas/Group.class.php
# → Runtime/Schemas/Admin/groupschema.*.php（类名 GroupSchema）
```

## 与 DB SchemaProvider 的区别

| 名称空间 | 用途 |
|----------|------|
| `Candy\Extend\Schema` / `APP\Schema\{App}` | **API 响应** data 形状（本文档） |
| `Candy\Extend\DB\Schema` | **数据库** 表结构元数据（运维 / Generator） |

二者无关，勿混用。
