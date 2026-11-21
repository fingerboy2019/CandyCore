# Catlog使用文档

## 定位（Candy）

| 层 | 路径 | 说明 |
|----|------|------|
| 入口 | `Catlog\Xhprof` | start / stop / showResult |
| 包内挂载 | `Catlog\Bootstrap` | 开关 + Hook 合并 + 配置应用 + 报告鉴权 |
| 包内安装 | `Catlog\Installer` | `php candy catlog:init` 薄命令调用 |
| 配置模板 | `Candy/Vendor/Catlog/Catlog.inc.php` | 默认留 Vendor |
| 项目配置 | `Source/Inc/Catlog.inc.php` | **`catlog:init` 发布后** |
| UI 静态 | `Candy/Vendor/Catlog/static` | 页面直接引用 `/Candy/Vendor/Catlog/static`（不拷 Public） |

**不要**默认挂全局 tags；生产关闭 `enable`。

## 启用步骤

```bash
cd backend
php candy catlog:init
# 覆盖：php candy catlog:init --force
```

会：

1. 发布 `Source/Inc/Catlog.inc.php`（`enable=true`）
2. 注册 tags（Bootstrap::mergeTags）
3. UI 留在包内，直接用 `/Candy/Vendor/Catlog/static`

依赖：

- PHP 扩展 `xhprof`
- 可用 `Cache::store()`（基类含 List 能力）
- 建议 `require_debug=true`（仅 DEBUG 采样）

## 查看报告

开发测试入口（已加）：

```
http://www.candy.com/Home/Index/catlog.html
```

密码默认 `change-me`（`Source/Inc/Catlog.inc.php` → `auth.password`）。

或代码中调用：

```php
\Catlog\Xhprof::showResult();
// 等价：listen('view_xhprof');
```

## 配置项（Inc）

```php
return [
    'enable'        => true,
    'require_debug' => true,
    'time_limit'    => 0,          // 秒；>0 仅记录更慢请求
    'ignore_url_arr'=> ['/test', '*catlog*', '*xhprof*'],
    'key_prefix'    => 'xhprof',
    'log_num'       => 1000,
    'view_wtred'    => 3,
    'ui_html'       => '/Candy/Vendor/Catlog/static',
    'auth'          => [
        'enable'   => true,
        'password' => 'change-me',
    ],
];
```

也可在运行期改静态属性（init 后会被 Inc 覆盖）：

```php
\Catlog\Xhprof::$time_limit = 1;
```

## Hook 说明

| 标签 | 行为 |
|------|------|
| `app_begin` | `Xhprof::start`（与缓存驱动解耦，不再依赖 `cache_loading`） |
| `app_end` / `app_stop` | `Xhprof::stop` |
| `view_xhprof` | `Xhprof::showResult` |

`enable=false` 或未 init 时，`Bootstrap::hooks()` 为空，不采样。

## 性能指标

| 指标 | 含义 |
|------|------|
| wt / excl_wt | 总耗时 / 自身耗时（微秒） |
| mu / excl_mu | 内存 |
| cpu | CPU 时间 |
| ct | 调用次数 |

## 注意事项

- 生产务必 `enable=false` 或关闭 DEBUG
- 报告含 URI/IP，务必开启 `auth`
- 数据存 Cache；旧 serialize 数据可读，新数据为 JSON
- run_id 优先 `Snowflake::id()`

## 故障排查

| 现象 | 检查 |
|------|------|
| 无采样 | `enable`、xhprof 扩展、DEBUG、`Cache::store()` |
| 报告 401 | `auth.password` |
| CSS/JS 404 | 确认可访问 `/Candy/Vendor/Catlog/static/css/catlog-ui.css`（调试环境勿拦截该路径） |
| 写入失败 | Cache 是否可用；看 Debug 面板 |

## 架构边界

| 做 | 不做 |
|----|------|
| 可选 Vendor + 包内 Bootstrap/Installer | 迁入 `Candy\Core` / `Candy\Extend` |
| Console 仅薄命令调包内 Installer | 默认挂每个请求 |
| 与 Apidoc 共用 tags 双层 merge | 自动扫描 Vendor tags |
