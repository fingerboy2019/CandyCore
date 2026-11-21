# NoSQL 服务器组件使用文档

## 1. 组件简介

Candy NoSQL 是一个基于 Workerman 的**类 Redis 网络 KV 服务**：多用户认证、多数据库隔离、内存高性能存储、周期性落盘持久化、内置向量检索与限流。

- **对标**：Redis 子集（KV / Hash / Set / SortedSet / Stream / 队列 / 锁 / 计数器 / 位图 / 布隆 / HyperLogLog / 向量 HNSW）
- **不是**：Mongo/SleekDB 式文档数据库、SQL 数据库
- **进程**：独立 Workerman Worker（TCP，单进程强制保证内存共享），端口默认 2404
- **协议**：`Candy\Extend\Protocols\Binary`（4 字节长度头 + PackBinary 序列化）

## 2. 启动方式

### 2.1 命令行启动（推荐）

```bash
# 启动 NoSQL 服务器（后台驻留）
php candy nosql start --host=0.0.0.0 --port=2404 \
    --users=admin:admin123,alice:secret \
    --dump-dir=/data/nosql-dump \
    --max-rps=1000 --max-rps-per-user=100

# 停止 / 重启 / 热重载 / 状态查看
php candy nosql stop
php candy nosql restart   # 自动恢复上次启动参数
php candy nosql reload
php candy nosql status
```

**参数说明：**

| 参数 | 默认 | 说明 |
|------|------|------|
| `--host` / `--port` | 0.0.0.0:2404 | 监听地址 |
| `--users` | 空（允许任意用户） | 明文 `user1:pass1,user2:pass2`（认证协议需明文密码，不支持 hash） |
| `--dump-dir` | 空（不落盘） | 周期性落盘目录，不指定则纯内存 |
| `--max-rps` | 1000 | 全局限流（每秒请求数） |
| `--max-rps-per-user` | 100 | 单用户限流 |

### 2.2 代码方式启动

```php
use NoSQL\Server;

$server = new Server('0.0.0.0:2404', [
    'users' => ['admin' => 'admin123'],
    'auth_timeout' => 10,
    'driver_config' => [
        'dumpFile' => '/data/nosql-dump/',   // 目录，自动按用户+库生成独立文件
        'dumpInterval' => 5,                 // 周期落盘间隔（秒）
    ],
    'per_user_max_keys' => 5000,
    'max_instance_pool' => 100,
    'max_rps' => 1000,
    'max_rps_per_user' => 100,
]);
Worker::runAll();
```

## 3. 客户端使用

```php
use NoSQL\Client;

// 1. 创建客户端（默认短连接；Workerman 进程内自动长连接）
$client = new Client('127.0.0.1:2404');

// 2. 认证（challenge 挑战码防重放）
$client->auth('admin', 'admin123');

// 3. 选库（可选，默认 'default'；数据按 用户_库_ 前缀隔离）
$client->selectDb('orders');

// 4. 基础操作
$client->set('user:1001', ['name' => 'Alice', 'email' => 'alice@example.com'], 3600);
$data = $client->get('user:1001');           // ['name' => 'Alice', ...]
$client->incr('order:counter');              // 1
$client->push('queue:pay', ['order_id' => 1001]);   // 队列
$orderId = $client->pop('queue:pay');

// 5. 关闭
$client->close();
```

**长连接模式**：`new Client('127.0.0.1:2404', ['persistent' => true])` 或 Workerman 进程内自动启用；不同用户的 persistent 客户端自动使用独立连接。

## 4. 支持的命令（经 MethodMapper 白名单反射）

| 类别 | 命令 |
|------|------|
| 基础 | `set` `get` `delete` `has` `ttl` `expire` `persist` `rename` |
| 批量 | `mSet` `mGet` `mDelete` `mHas` |
| 计数器 | `incr` `decr` `incrByFloat` |
| 队列 | `push` `pop` `range` |
| CAS | `cas` |
| 锁 | `__acquireLock` `__releaseLock` |
| 向量 | `vectorSet` `vectorGet` `vectorDelete` `vectorScanSearch` |
| 系统 | `ping` `selectDb` `info` |

> 完整可用命令 = Cache 基类 + 具体驱动（Memory 等）公开方法中未被黑名单屏蔽者；
> 内部/危险方法（落盘、锁内部、向量内部等）已通过黑名单屏蔽，客户端不可调用。

## 5. 安全特性

- **多用户认证**：challenge 挑战码（服务端生成随机串，客户端 `hash(sha256, 密码+挑战)` 应答），防重放、防客户端操控
- **认证防爆破**：默认 5 次失败锁定 300 秒（基于 IP，计数持久化到 `Runtime/NoSql/auth.json`，跨重启生效）
- **多库隔离**：数据按 `{user}_{db}_` 前缀隔离，不同用户/库互不可见
- **未知用户拒绝**：配置了用户列表时，未在列表中的用户名一律拒绝（防枚举/防冒充）
- **限流**：全局限流 + 单用户限流（429）
- **方法白名单**：反射 + 黑名单，客户端只能调用公开业务方法

## 6. 持久化与落盘

- **周期性落盘**：每 1 秒扫描实例池，各实例按 `dumpInterval`（默认 5 秒）节流，**增量落盘**（只写变更键，无写入零 IO）
- **目录自动分文件**：`dump-dir` 配置为目录时，自动按用户+库前缀生成独立 dump 文件（`md5(prefix).dump`）
- **恢复**：实例创建时自动 `loadDump()`；重启后数据恢复
- **数据窗口**：崩溃（kill -9/断电）最多丢失 `dumpInterval` 秒内的写入；正常 stop 时无条件全量落盘
- **调优**：关键数据保持 5s；可重建缓存调大 `dumpInterval` 或不落盘

## 7. 运维

- 独立 PID/日志/状态文件：`Runtime/NoSql/`
- `getStats()`（经 info 或直接调用）可查看各实例内存/键数/脏键数
- 备份：直接复制 dump 目录即可（原子文件）
- 单进程单点：重启恢复靠 dump；需要高可用需引入主从或外部持久源

## 8. 注意

- **明文密码**：认证协议需要服务端持有明文密码复算挑战码，`--users` 会明文出现在 ps 中；建议内网部署 + 强密码
- **无 TLS**：明文 TCP，跨网段部署建议加隧道/防火墙
- **单包限制**：PackBinary 单包最大 10MB，超大 value 写入会失败
