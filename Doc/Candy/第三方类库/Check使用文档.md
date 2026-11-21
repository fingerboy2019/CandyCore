# Check 类库使用文档

敏感词检测与过滤（DFA）。**默认不启用、不挂 tags**；执行 `php candy check:init` 后可用。

运行时从 **Cache 读词列表**，进程内建 DFA 树；TXT 仅作种子与加减词回写。

## 1. 快速开始

```bash
cd backend
php candy check:init          # 发布 Source/Inc/Sensitive.inc.php + 灌 Cache
# php candy check:init -f     # 强制覆盖配置并重灌词库
```

```php
// 推荐
\Check\Sensitive::instance()->is('待检测文本');
sensitive_is('待检测文本');

// 替换 / 提取
sensitive_replace($text);
sensitive_get($text);
```

未 init 时调用会提示：先执行 `php candy check:init`。

**不**修改 `Application/tags.php`；业务按需调用即可。

## 2. 目录结构

```
Candy/Vendor/Check/
├── Bootstrap.class.php      # enabled / config（无 Hook）
├── Installer.class.php      # check:init 安装逻辑
├── WordStore.class.php      # Cache 词列表 + ver + TXT 回写
├── Sensitive.class.php      # DFA 匹配
├── Sensitive.inc.php        # 配置模板
├── helpers.php
└── word/SensitiveWord.txt   # 种子词库

Console/Command/Check/Init.class.php
Source/Inc/Sensitive.inc.php   # init 后生成
```

## 3. 配置（init 后）

`Source/Inc/Sensitive.inc.php` 主要项：

| 键 | 说明 |
|----|------|
| `enabled` | 总开关；init 发布为 `true`，改 `false` 即停用 |
| `cache_prefix` | 默认 `check:sensitive` → `{prefix}:words` / `{prefix}:ver` |
| `word_file` | 空则用 Vendor 下 `word/SensitiveWord.txt` |
| `mode` | `file` / `array`（仅种子来源；匹配不读盘） |
| `config.*` | `replace_char` / `repeat` / `mark` |
| `interference_factors` | 干扰因子（空格、`*` 等） |

## 4. 读写模型

```
检测 → ensureTree → [ver 变?] → Cache 取词 → 建树 → 匹配（不读 TXT）

add / remove → 改列表 → Cache + ver++ + 回写 TXT → 本进程重建
其它 Worker → 下次检测发现 ver 变 → 从 Cache 重建
```

## 5. API

### Sensitive（实例方法）

```php
$s = \Check\Sensitive::instance();

$s->is($text);           // bool，同 check()
$s->get($text);          // 命中词列表
$s->replace($text);      // 替换为 *
$s->mark($text);         // <mark>…</mark>
$s->add('新词');         // 持久化
$s->remove('旧词');      // 持久化；once=true 仅临时
$s->custom($fileOrArr);  // 全量换词库并持久化
```

> PHP 8+ 请勿写 `Sensitive::is()`（非静态方法）；用 `instance()` 或 helpers。

### helpers

| 函数 | 说明 |
|------|------|
| `sensitive_is` | 是否含敏感词 |
| `sensitive_get($text, $matchType=1, $wordNum=0)` | 提取 |
| `sensitive_replace` / `sensitive_mark` | 替换 / 标记 |
| `sensitive_add` / `sensitive_remove` | 加减词（持久化） |
| `sensitive_custom` | 换词库 |
| `sensitive_interference_factor` | 追加干扰因子 |

### WordStore

一般无需直接调用；调试可用：

```php
\Check\WordStore::version();
\Check\WordStore::loadWords(); // 仅 Cache
```

## 6. 关闭

将 `Source/Inc/Sensitive.inc.php` 中 `enabled` 设为 `false`，无需删 Vendor。

## 7. 注意

- 词库变更（add/remove）建议单点操作，避免多 Worker 并发写覆盖
- 首次每个进程建树约百毫秒级（约 4 万词），之后同进程接近 0ms
- 设计说明：`docs/superpowers/specs/2026-07-19-check-wordstore-design.md`
