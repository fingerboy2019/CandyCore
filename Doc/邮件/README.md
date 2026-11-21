# 邮件收发系统 · 基建层

> 目标：外贸业务邮件接入（IMAP 收 + SMTP 发），为后续「询盘分类 / 信息抽取 / 回复草稿」提供干净数据。
> 当前只做**基建**，业务层（客户/询盘/草稿/界面）未实现。

## 一、目录

| 位置 | 作用 |
|---|---|
| `Candy/Class/Network/Mail/Mime.class.php` | MIME 解析（multipart/base64/QP/charset/附件） |
| `Candy/Class/Network/Mail/Imap.class.php` | IMAP 客户端（UID 增量、文件夹、SEARCH、字面量） |
| `Candy/Class/Network/Mail/Store.class.php` | 存储（账号/游标/线程/邮件/附件/原始 EML） |
| `Candy/Class/Network/Mail/Sync.class.php` | 同步编排（增量、失败不越游标） |
| `Candy/Vendor/Console/Command/Mailbox.class.php` | CLI 入口 |
| `Application/Home/Models/Mail*.class.php` | 5 个 Model（**类名必须复数**，表名由类名推导） |
| `Data/Mail/schema.sql`（权威）/ `Doc/邮件/Mail表结构.sql`（文档副本） | 建表 SQL |
| `Doc/邮件/mock-imap-server.php` | 本地 mock IMAP（无真实邮箱时验证同步） |

## 二、建表

```bash
cd /www/wwwroot/okr.candy.com
PGPASSWORD=candy psql -h 127.0.0.1 -U candy -d okr_candy -f Data/Mail/schema.sql
```

## 三、使用

```bash
PHP=/www/server/php/84/bin/php     # 必须带 mbstring；/usr/bin/php 缺 mbstring 会报 mb_strtolower undefined

$PHP candy mailbox add --email=sales@your.com --host=imap.exmail.qq.com --port=993 --secure=ssl --user=sales@your.com --pass=授权码 --name=公共询盘箱
$PHP candy mailbox list
$PHP candy mailbox sync --account=1 --limit=50            # 增量：只拉 UID > last_uid
$PHP candy mailbox stats
$PHP candy mailbox parse --file=/path/to/x.eml            # 只解析不入库，排障用
```

## 四、增量与可靠性约定

1. 游标 = `sa_mail_folders.uidvalidity` + `last_uid`
2. **UIDVALIDITY 变化 → 游标归零重扫**（旧 UID 全部失效）
3. **某封失败 → 游标停在它之前**，下次自动重试（不会漏信）
4. 唯一键 `(account_id, folder_id, uid)` 保证重复同步幂等
5. 原始 EML 落 `Data/Mail/raw/<account>/<folder>/<uid>.eml`，解析失败可回溯

## 五、本地验证（无需真实邮箱）

```bash
# 1. 造两封测试邮件到 Data/Mail/mock/
# 2. 起 mock
setsid /www/server/php/84/bin/php 'Doc/邮件/mock-imap-server.php' 14399 > Runtime/Mail/mock.log 2>&1 < /dev/null &
# 3. 加一个指向它的账号
$PHP candy mailbox add --email=test@mock.local --host=127.0.0.1 --port=14399 --secure=none --user=test --pass=x
# 4. 同步并观察 stats
$PHP candy mailbox sync --account=<id>
```

实测：2 封首次入库 → 再同步 0 新增 → 追加第 3 封后重新同步 1 新增；
3 封归到同一 thread（靠 In-Reply-To/References），附件 1 个，原始 EML 3 份。

## 六、已知坑

- **Model 类名必须复数**（`MailMessages`），框架按类名推导表名，`protected $table` 不生效
- Model 源文件必须写 `namespace APP\Model\Home;`，漏了会报「编译后未定义」
- CLI 下 `M()` 需要先 `Bootstrap::loadDbConfig()` + 手动 `C('APPNAME','Home')`
- PG 的 `boolean` 列不能传 PHP `false`（会绑成 `''`），要传 `'true'`/`'false'`
- 同步是**阻塞 socket**，只能跑在 CLI/常驻 worker，不要放进 FPM 请求

## 七、下一步（业务层）

客户/联系人归并 → 询盘建档 → 分类（规则+LLM）→ 关键信息抽取 → 回复草稿 → SMTP 发送 → 跟进提醒
