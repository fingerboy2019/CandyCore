# Candy\Extend\Validate\Base

类规则验证器基类。应用层源码短名（如 `Group`），由 `RuntimeClassLoader` 编译为 `{Name}Validate` 写入 `Runtime/Validates/`。

## 用法

```php
// 使用编译后的运行时类名
UserValidate::make($data)->setDb($db)->scene('add')->check();
```

详见：`Doc/Candy/框架使用/自动验证.md`

