# DataFreeze — 热 / 冷 / 冻 数据分层

| 项 | 内容 |
|----|------|
| 类 | `Candy\Extend\DB\DataFreeze` |
| 路径 | `Candy/Class/DB/DataFreeze.class.php` |
| 对照 | [CandyImStorageDesign.md](../../../../raw/doc/CandyImStorageDesign.md) |

与 `DBService`（整库备份还原）并列，供 IM 及任意业务表复用。

## 分层语义

- **热**：最近 `hot_months`（H）个自然月，`1 ≤ H < 12`
- **冷**：热溢出；**去年**可长期留在库内供上翻
- **冻**：冷表中 `year ≤ 今年 - freeze_year_offset`（默认 offset=2，即前年及更早）整年打包出库后删除

在线查询只合并热+冷；冻包仅 `thaw` 审计使用。

## 入口

```php
use Candy\Extend\DB\DataFreeze;

// 一次 tick
$r = DataFreeze::runTier([
    'hot_table'    => 'sa_im_message',
    'cold_table'   => 'sa_im_message_archive',
    'time_column'  => 'createtime',
    'hot_months'   => 3,
    'freeze_year_offset' => 2,
    'pack_dir'     => rtrim(CANDYROOT, '/\\') . '/Data/freeze/im',
    'meta_table'   => 'sa_im_message_freeze', // 可选
    'pk'           => 'id',
    'batch_size'   => 1000,
    'set_archived_at' => true, // 冷表有 archived_at 时打开
]);

// 冷热兼容读（不读冻包）
$q = DataFreeze::queryRange([
    'hot_table'  => 'sa_im_message',
    'cold_table' => 'sa_im_message_archive',
    'pk'         => 'id',
    'limit'      => 30,
    'before_id'  => 0,
    'order'      => 'desc',
    // 'extra_where_sql' => 'conversation_id = ?',
    // 'extra_params' => [$cid],
]);

// 审计解冻
DataFreeze::thaw([
    'pack_path'    => CANDYROOT . '/Data/freeze/im/2024',
    'target_table' => 'sa_im_message_thaw',
    'truncate_first' => true,
]);
```

## 步骤 API

| 方法 | 作用 |
|------|------|
| `moveHotToCold` | 热→冷 |
| `pack` | 冷表某自然年 → 目录包（jsonl[.gz] + manifest） |
| `purge` | checksum 校验后删冷表该年 |
| `thaw` | 冻包 → 目标表 |
| `listColdYears` | 冷表年份列表 |
| `hotWindowStart` / `yearRange` | 时间工具 |

`runTier` = `moveHotToCold` + 对满足 offset 的年份 `pack`→`purge`。

## 冻包目录

```text
{pack_dir}/{year}/
  data.jsonl.gz
  manifest.json   # checksum_sha256、count、id 范围等
```

路径用 `CANDYROOT` / `Data/freeze/...`，禁止硬编码仓库 `backend/` 前缀。
