# DataLifecycle — 配置驱动的数据生命周期类库

| 项 | 内容 |
|----|------|
| 类 | `Candy\Extend\DB\DataLifecycle` |
| 配置 | `Source/Inc/DataLifecycle.inc.php` |
| 能力 | 热转冷、冷热读、年度冻结、临时解冻审查、清理 |

业务通过注册键使用，不允许在 Web 请求中动态传表名或冻包路径：

```php
use Candy\Extend\DB\DataLifecycle;

$preview = DataLifecycle::preview('im_message');
$result = DataLifecycle::queryOnline('im_message', [
    'where' => ['conversation_id' => 100],
    'before_id' => 5000,
    'limit' => 30,
]);
```

后台入口为“运维中心 → 数据生命周期”。CLI：

```bash
php candy lifecycle:status im_message
php candy lifecycle:preview im_message
php candy lifecycle:run im_message
php candy lifecycle:freeze im_message 2024
php candy lifecycle:thaw im_message 2024
php candy lifecycle:thaw-clear im_message
php candy lifecycle:cleanup im_message
```

`thaw` 是临时审查区，平时为空；不会被普通业务查询读取，也不会自动回热或回冷。
