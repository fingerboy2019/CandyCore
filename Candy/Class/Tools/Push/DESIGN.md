# CandyPHP 推送服务（框架能力）设计

> 版本：2026-11-16 初稿
> 定位：与 `sys/*` 端点同级的**框架内置能力**，装上即可用，零配置可跑

---

## 一、要解决的问题

1. 现在推送是**项目插件**（`Service/Entry/ws.php` + `GlobalData` 的 `cron` 队列 + 自写 `binduser` 协议），
   协议非标准、在线映射靠手维护、加 tag/回执会越堆越乱。
2. 框架已内置 `Candy/Vendor/Workerman/GatewayWorker`（v4.0.2），它原生提供连接管理、uid 绑定、
   分组、在线判断、多机扩展 —— 这些正是"极光式推送"需要的前 6 项能力。
3. 用户要求：
   - 「这个服务能力也要做成**框架级**的，**摆脱配置**，**开启就能使用**」
   - 「做好设计，**包分应用注册使用服务**，这样可以**服务多样应用**」

---

## 二、能力边界（说清什么给、什么不给）

| 能力 | 来源 | 说明 |
|---|---|---|
| 连接管理 / 心跳 / 断线重连 | ✅ GatewayWorker | 组件自带 |
| uid 绑定、按 uid 推 | ✅ GatewayWorker | `bindUid` / `sendToUid` |
| 分组（tag）、按组推 | ✅ GatewayWorker | `joinGroup` / `sendToGroup` |
| 广播 | ✅ GatewayWorker | `sendToAll` |
| 在线查询 | ✅ GatewayWorker | `isUidOnline` / `getOnlineUidList` |
| **离线留存 / 上线补发** | ⚠️ **本能力实现** | 组件不自带 |
| **送达回执（ACK）** | ⚠️ 本能力实现（可选，第一版不做） | 组件不自带 |
| 多应用隔离 | ⚠️ 本能力实现 | 用命名空间前缀 |

> **不要对外宣称"极光推送"**：极光有 APNs/FCM 系统级推送、厂商通道、送达统计。
> 本能力是**站内在线推送 + 离线补发**，语义与极光一致的部分只有 alias/tag/广播/离线。

---

## 三、目录结构与类职责

```
Candy/Class/Tools/Push/
├─ Config.class.php     默认配置 + 项目覆盖（Source/Inc/Push.inc.php 可选）
├─ Registry.class.php   应用注册表：app → 策略（幂等、未注册按默认策略）
├─ Bootstrap.class.php  应用注册入口（mergeTags / register），供 Application/tags.php
├─ Boot.class.php       三进程启动器：Register + Gateway + BusinessWorker
├─ Target.class.php     推送目标：app / uid / #tag / all（自动加前缀）
├─ Offline.class.php    离线留存与补发（Cache + TTL + 上限）
├─ Message.class.php    消息体规范与序列化
├─ Events.class.php     BusinessWorker 的业务回调（onConnect/onMessage/onClose）
└─ Endpoint/Push.class.php  （已存在）管理面：状态/在线/发送/离线队列
```

| 类 | 一句话职责 |
|---|---|
| `Config` | 所有默认值集中在此；项目不写配置就等于用默认值 |
| `Registry` | 记住"有哪些应用在用推送、各自什么策略" |
| `Bootstrap` | 让项目用一行 `tags.php` 完成注册 |
| `Boot` | 把三个 Workerman 进程起来（只做启动，不含业务） |
| `Target` | 把"发给谁"翻译成 Gateway 的调用 |
| `Offline` | 目标不在线时存下来，上线时补发 |
| `Message` | 统一消息体，避免各应用自己拼 |
| `Events` | 连接建立/收到消息/断开时的业务处理 |

---

## 四、多应用：一个集群，多个命名空间

### 4.1 命名规则

```
uid   = "{app}:{user_id}"          例 home:34 / admin:1
group = "{app}:{tag}"              例 home:dept-12 / admin:ops
app 缺省 = "default"
```

**隔离靠前缀**：`home:34` 与 `admin:34` 是两个不同 uid，互不串。
同一用户同时登录门户和后台时，是两个独立连接、两个独立离线队列。

### 4.2 应用注册

```php
// Application/tags.php（项目侧，一行）
return \Candy\Extend\Tools\Push\Bootstrap::mergeTags([
    'home'  => ['label' => '门户'],
    'admin' => ['label' => '后台', 'offline' => ['ttl' => 86400, 'max' => 100]],
]);
```

- **不注册也能用**：任何 `app` 名首次出现时按默认策略自动注册（"开启就能使用"）
- 注册只影响策略（离线 TTL/上限/是否启用），不是使用前提
- `Registry::all()` 供 `/sys/push` 页面展示"有哪些应用在用"

### 4.3 业务调用形态

```php
use Candy\Extend\Tools\Push\Target;

Target::app('home')->toUid(34, '你有新工单', ['ticket_id' => 12]);   // 单人
Target::app('home')->toGroup('dept-12', '部门通知');                  // 分组
Target::app('home')->all('系统维护');                                // 本应用广播
Target::broadcast('服务将在 22:00 重启');                            // 全应用广播
```

返回值统一 `['ok' => bool, 'sent' => int, 'queued' => int, 'message' => string]`。

---

## 五、客户端协议

连接：`ws://host:4020/?token=<endpoint-token>`

首帧登录：

```json
{"act":"login","app":"home","uid":34,"tags":["dept-12"]}
```

服务端回：`{"act":"login","ok":true,"uid":"home:34"}`

其它帧：

| act | 含义 |
|---|---|
| `join` | 加入分组 `{"act":"join","tag":"dept-12"}` |
| `leave` | 退出分组 |
| `ping` | 心跳（也可不心跳，靠 Gateway 自带） |

服务端下发消息：

```json
{"act":"message","msg_id":"...","title":"","body":"...","payload":{},"ts":1794810000}
```

> **改动点**：现 `ws.php` 用 `{"type":"binduser","user":"..."}`，前端 `app.js` 需要改一处连接逻辑。
> 建议第一版**服务端先就绪**，前端随后接（或保留旧协议兼容一版）。

---

## 六、零配置默认值（`Config::defaults()`）

```php
[
    'gateway'  => ['listen' => 'websocket://0.0.0.0:4020', 'count' => 1],
    'register' => ['listen' => 'text://127.0.0.1:1236'],
    'worker'   => ['count' => 1],
    'offline'  => ['enable' => true, 'ttl' => 604800, 'max' => 200],   // 7 天 / 200 条
    'auth'     => ['required' => true, 'token' => null],                // null = 用 endpoint token
]
```

项目侧**不写任何配置**即可运行；要改才写 `Source/Inc/Push.inc.php`。

---

## 七、离线留存

存储：框架 `G()`（Cache，非 Memory 驱动默认 Redis）

```
键：push.offline.{app}:{uid}   →  [{msg…}, {msg…}]  （按时间正序）
```

- 写入时机：`sendToUid` 前 `isUidOnline()` 为 false
- 补发时机：`onMessage('login')` 成功后立即取该 uid 队列、逐条下发、清空
- 上限：超过 `max` 丢最旧；超过 `ttl` 的条目在读写时惰性剔除
- **不落 MySQL**：离线消息是瞬时数据，量小、可丢

---

## 八、进程模型

```
Register  text://127.0.0.1:1236     注册中心（Gateway 与 BusinessWorker 互相发现）
Gateway   websocket://0.0.0.0:4020  对外连接层（持有所有 client_id）
BusinessWorker  无监听              业务层（跑 Events 回调，处理 login/join）
```

启动：`Boot::run('start')`，由 `/sys/service` 页面通过 `Service\Manager` 拉起（服务名 `push`）。

`Service.inc.php` **默认注册**：

```php
'push' => [
    'label' => '推送服务',
    'desc'  => 'GatewayWorker 三进程：连接层 + 业务层 + 注册中心',
    'entry' => 'Candy/Class/Tools/Service/Entry/push.php',
    'args'  => ['start'],
],
```

---

## 九、降级与容错（必须是"不炸"）

| 场景 | 行为 |
|---|---|
| Gateway 未启动 | `Target::send()` 不抛异常，写离线队列，返回 `ok=false, queued=n` |
| Cache 不可用 | 离线留存关闭，返回 `queued=0` 并附原因 |
| 目标 uid 不存在 | 视为离线，走留存 |
| 消息体过大 | 拒绝并返回"超过 N KB"，不静默截断 |
| 连接无 token | 拒绝握手（`auth.required=true` 时） |

---

## 十、`/sys/push` 管理面（保留，换底层）

| 动作 | 内容 |
|---|---|
| `page` | 服务状态 + **已注册应用列表** + 在线数 + 推送表单（可选 app/target）+ 离线队列 |
| `status` | JSON：`gateway_online / apps / online_cnt / offline_cnt` |
| `send` | `app` / `target`（uid 或 #tag 或 all）/ `body` |
| `offline` | 离线队列明细（按 app:uid 分组） |

---

## 十一、迁移路径

1. **新增** `Push/` 全部类 + `Service/Entry/push.php` + `Service.inc.php` 注册 `push`
2. **并行**：`ws.php` 保持运行（旧链路），`push` 起来后先内部验证
3. **切换**：前端改连 4020 新协议（Gateway 接管端口，先停 ws.php）
4. **退役**：删除 `Service/Entry/ws.php`、`GlobalData` 的 `cron` 队列逻辑、`Service.inc.php` 的 `websocket` 项
5. **同步**：`Candy/Class/Tools/Cron/Handler/Push.class.php` 改为调 `Target::app('default')->toUid/all()`

---

## 十二、验收标准

- [ ] 零配置启动：不写 `Push.inc.php`，`/sys/service` 点启动 → 三进程都在
- [ ] 连接 + 登录：客户端带 token 连上，`login` 回 `ok:true`
- [ ] 定向推送：`Target::app('home')->toUid(34,'x')` → 该客户端收到
- [ ] 隔离：`home:34` 收不到 `admin:34` 的消息
- [ ] 分组：`joinGroup('dept-12')` 后 `toGroup` 能收到
- [ ] 离线补发：断线时推送，重连登录后**自动收到**
- [ ] 降级：Gateway 停掉时 `send()` 返回 `ok=false,queued=n` 且不抛异常
- [ ] 管理面：`/sys/push?do=page` 显示在线数与已注册应用
- [ ] 回归：登录页 200/4456B，`/sys/crontab` 与 `/sys/service` 正常

---

## 十三、明确不做（第一版）

- ❌ APNs / FCM 系统级推送（那要厂商通道，与"浅显版"不符）
- ❌ 送达回执与统计（消息粒度 ACK 会显著增加复杂度）
- ❌ 消息持久化到 MySQL（离线只留 Cache）
- ❌ 多机横向扩展的运维脚本（Gateway 支持，但第一版不开）
