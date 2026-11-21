<?php
/***
 * 数据模型基类
 *
 * 业务 Model 通过组合数据库连接访问数据，不再直接继承 Driver。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-03 23:03:19 $
 *
 * @package Candy\Extend\DB
 */
declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class BaseModel
{
    // 模型级关系声明：with() / load() / belongsTo / hasMany / belongsToMany
    use \Candy\Extend\DB\Trait\RelationDeclare;

    // 模型事件：beforeInsert / afterInsert / beforeUpdate / afterUpdate / beforeDelete / afterDelete
    use \Candy\Extend\DB\Trait\ModelEvents;

    // 全局作用域：addGlobalScope / withoutGlobalScope / withoutGlobalScopes
    use \Candy\Extend\DB\Trait\GlobalScope;


    /**
     * 输出时剔除的字段（Laravel $hidden 等价）
     *
     * 只影响**读出**（select/find），不影响写库 —— 写库的白名单是 $fillable。
     *
     * ```php
     * class Users extends BaseModel {
     *     protected array $hidden = ['password', 'usersalt', 'remember_token'];
     * }
     * ```
     *
     * @var array<int, string>
     */
    protected array $hidden = [];

    /**
     * 输出白名单（Laravel $visible 等价，非空时优先级高于 $hidden）
     *
     * 非空时**只输出列出的字段**，其余全部剔除。
     *
     * @var array<int, string>
     */
    protected array $visible = [];

    /** @var array<int, string> 本次查询临时放行的隐藏字段 */
    private array $forceVisible = [];

    /** @var bool 本次查询是否完全跳过 hidden 过滤 */
    private bool $skipHiddenOnce = false;



    public string $path = '';

    private ?DBServer $connection = null;

    private string $tableName = '';

    /** @var mixed setLink 待应用的隔离配置 */
    private mixed $pendingLinkConfig = [];

    private ?string $pendingLinkRole = null;

    private ?string $pendingDriver = null;

    /**
     * 链式查询方法（累积 where，重置 field/order 等）
     *
     * @var array<int, string>
     */
	private const CHAIN_METHODS = [
		'where', 'orwhere', 'field', 'fieldexpressions', 'order', 'limit', 'group', 'having', 'alias', 'join',
		'data', 'withtrashed', 'onlytrashed', 'cache', 'share', 'token', 'scope', 'tojson',
		'lockforupdate', 'lockforshare',
	];

    /**
     * 终结查询 / 写操作方法
     *
     * @var array<int, string>
     */
	private const TERMINAL_METHODS = [
		'find', 'select', 'total', 'count', 'save', 'update', 'add', 'insert', 'delete',
		'insertgetid', 'upsert', 'insertselect', 'setinc', 'setdec', 'concat', 'saveall', 'r_delete',
	];

    /**
     * Set Link
     * @param mixed $config
     * @param mixed $role
     * @return static
     */
    public function setLink(mixed $config = [], ?string $role = null): static
    {
        $newDriver = DBServer::resolveDriverFromConfig($config);
        if ($this->pendingDriver !== null && $this->pendingDriver !== $newDriver) {
            $this->connection = null;
        }
        $this->pendingLinkConfig = $config;
        $this->pendingLinkRole = $role;
        $this->pendingDriver = $newDriver;
        $this->connection()->setLink($config, $role);
        $this->enlistTransactionScope();
        return $this;
    }

    /**
     * 强制后续查询走写库
     *
     * @return static
     */
    public function useWrite(): static
    {
        $this->connection()->useWrite();
        return $this;
    }

    /**
     * 强制后续查询走读库
     *
     * @return static
     */
    public function useRead(): static
    {
        $this->connection()->useRead();
        return $this;
    }

    /**
     * 恢复自动读写路由
     *
     * @return static
     */
    public function useAuto(): static
    {
        $this->connection()->useAuto();
        return $this;
    }

    /**
     * 后续 select/find/total 优先异步入队（需 Db.async_read 开启）
     *
     * @param bool $on 是否启用
     * @return static
     */
    public function useAsyncRead(bool $on = true): static
    {
        $this->connection()->useAsyncRead($on);
        return $this;
    }

    /**
     * Set Table
     * @param mixed $table
     * @return static
     */
    public function setTable(string $table): static
    {
        $this->tableName = $table;
        $this->connection()->setTable($table);
        return $this;
    }

    /**
     * Get Msg
     * @return string
     */
    public function getMsg(): string
    {
        return $this->connection()->getMsg();
    }

    /**
     * Get Sql State
     * @return array
     */
    public function getSqlState(): array
    {
        return $this->connection()->getSqlState();
    }

    /**
     * 获取当前模型连接对应的 SchemaProvider。
     *
     * 供遗留 Admin 模型执行受控结构兼容，不允许借此承载业务数据读写。
     *
     * @return \Candy\Extend\DB\Schema\SchemaProviderInterface|null 元数据 Provider
     */
    public function schemaProvider(): ?\Candy\Extend\DB\Schema\SchemaProviderInterface
    {
        return SchemaMeta::providerFor($this->connection());
    }

    /**
     * Set Sql State
     * @param mixed $state
     */
    public function setSqlState(array $state): void
    {
        $this->connection()->setSqlState($state);
    }

    /**
     * 重置链式查询状态
     *
     * 写库前调用，避免常驻进程内 where 残留导致误更新/误删。
     *
     * @return static
     */
    public function resetQueryState(): static
    {
        $this->connection()->clearSql();
        $this->resetGlobalScopeState();

        return $this;
    }

    


    /**
     * 本次查询临时放行指定隐藏字段（Laravel makeVisible 等价）
     *
     * `$hidden` 会在 select/find 返回前剔掉敏感列，导致"验证密码"这类
     * 需要读取原始值的场景拿不到数据。用本方法显式放行。
     *
     * ```php
     * // 只放行 password，其余 hidden 仍生效
     * $u = M('Users')->makeVisible('password')->where(['username'=>$name])->find();
     *
     * // 放行多个
     * M('Users')->makeVisible(['password', 'usersalt'])->find(1);
     * ```
     *
     * 放行标记**只对下一次 select/find 生效**，执行完自动清除。
     *
     * @param string|array<int, string> $fields 要放行的字段名
     * @return static
     */
    public function makeVisible(string|array $fields): static
    {
        foreach ((array) $fields as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = strtolower(trim($field));
            if ($field !== '' && !in_array($field, $this->forceVisible, true)) {
                $this->forceVisible[] = $field;
            }
        }

        return $this;
    }

    /**
     * 本次查询完全跳过 `$hidden` 过滤（Laravel withoutHidden 等价）
     *
     * 用于确实需要拿到全部原始列的场合（如密码校验、数据迁移、导出）。
     * **只对下一次 select/find 生效**。
     *
     * ```php
     * $u = M('Users')->withoutHidden()->where(['username'=>$name])->find();
     * ```
     *
     * @return static
     */
    public function withoutHidden(): static
    {
        $this->skipHiddenOnce = true;

        return $this;
    }

    /**
     * 过滤单行输出字段
     *
     * 规则：`$visible` 非空 → 白名单；否则剔除 `$hidden` 列出的字段。
     *
     * @param array<string, mixed> $row 单行
     * @return array<string, mixed>
     */
    protected function filterOutputFields(array $row): array
    {
        if ($row === []) {
            return $row;
        }

        // 本次查询被 withoutHidden() 显式豁免
        if ($this->skipHiddenOnce) {
            return $row;
        }

        $forceVisible = $this->normalizeOutputFieldList($this->forceVisible);
        $visible = $this->normalizeOutputFieldList($this->visible);
        if ($visible !== []) {
            $out = [];
            foreach ($row as $key => $value) {
                if (in_array($key, $visible, true)) {
                    $out[$key] = $value;
                }
            }

            return $out;
        }

        $hidden = $this->normalizeOutputFieldList($this->hidden);
        if ($hidden === []) {
            return $row;
        }

        $out = [];
        foreach ($row as $key => $value) {
            $keyLower = strtolower((string) $key);
            // makeVisible() 放行的字段不剔除
            if (in_array($keyLower, $forceVisible, true)) {
                $out[$key] = $value;
                continue;
            }
            if (!in_array($keyLower, $hidden, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * 过滤多行输出字段
     *
     * @param array<int, mixed> $rows 结果集
     * @return array<int, mixed>
     */
    protected function filterOutputRows(array $rows): array
    {
        if ($this->skipHiddenOnce
            || ($this->normalizeOutputFieldList($this->hidden) === []
                && $this->normalizeOutputFieldList($this->visible) === []
                && $this->forceVisible === [])) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rows[$index] = $this->filterOutputFields($row);
            }
        }

        return $rows;
    }

    /**
     * 归一化字段名列表（小写、去空）
     *
     * @param mixed $fields 原始列表
     * @return array<int, string>
     */
    private function normalizeOutputFieldList(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = strtolower(trim($field));
            if ($field !== '') {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * 分块处理大结果集（Laravel chunk 等价）
     *
     * 按 offset 逐块取数，每块交给回调处理。回调返回 false 可提前中断。
     * 用 getSqlState()/setSqlState() 快照原始查询条件，保证每块条件一致。
     *
     * ```php
     * M('Tickets')->where(['status' => 'open'])->chunk(500, function (array $rows) {
     *     foreach ($rows as $row) { ... }
     * });
     * ```
     *
     * @param int      $size     每块条数（>=1）
     * @param callable $callback 回调，签名 (array $rows): bool|null|void
     * @return bool true=全部处理完；false=被回调中断
     * @throws \InvalidArgumentException size < 1 时
     */
    public function chunk(int $size, callable $callback): bool
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('chunk 的 size 必须 >= 1');
        }

        $snapshot = $this->getSqlState();
        $offset   = 0;

        try {
            while (true) {
                $this->setSqlState($snapshot);
                $this->limit($offset, $size);
                $rows = $this->select();

                if (!is_array($rows) || $rows === []) {
                    break;
                }

                if ($callback($rows) === false) {
                    $this->setSqlState($snapshot);

                    return false;
                }

                if (count($rows) < $size) {
                    break;
                }

                $offset += $size;
            }
        } finally {
            $this->setSqlState($snapshot);
        }

        return true;
    }

    /**
     * 游标逐条遍历（Laravel cursor 等价）
     *
     * 内存里始终只有一块（$size 条），适合百万级导出。
     *
     * ```php
     * foreach (M('Tickets')->where(['status' => 'open'])->cursor(1000) as $row) {
     *     // ...
     * }
     * ```
     *
     * @param int $size 每块条数（>=1）
     * @return \Generator<int, array>
     */
    public function cursor(int $size = 1000): \Generator
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('cursor 的 size 必须 >= 1');
        }

        $snapshot = $this->getSqlState();
        $offset   = 0;

        try {
            while (true) {
                $this->setSqlState($snapshot);
                $this->limit($offset, $size);
                $rows = $this->select();

                if (!is_array($rows) || $rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    yield $row;
                }

                if (count($rows) < $size) {
                    break;
                }

                $offset += $size;
            }
        } finally {
            $this->setSqlState($snapshot);
        }
    }
    /**
     * 按主键游标分批遍历（Laravel lazyById 等价）
     *
     * 与 chunk()/cursor() 的差别：
     *   cursor()   —— offset 翻页，深翻时数据库要跳过前面所有行，越翻越慢
     *   lazyById() —— `WHERE 主键 > 上一批最后一条`，深翻开销恒定
     *
     * 代价：游标必须严格按游标列升序推进，**原有 order / limit 会被忽略**。
     * 需要自定义排序又不想吃 offset 的，请用 chunk() 自行控制。
     *
     * ```php
     * foreach (M('Tickets')->where(['status' => 'open'])->lazyById(1000) as $row) {
     *     // 内存里始终只有一批
     * }
     * ```
     *
     * @param int         $size   每批条数（>=1）
     * @param string|null $column 游标列，默认用主键
     * @return \Generator<int, array>
     */
    public function lazyById(int $size = 1000, ?string $column = null): \Generator
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('lazyById 的 size 必须 >= 1');
        }

        $column   = $column !== null && $column !== '' ? $column : $this->primaryKeyName();
        $snapshot = $this->getSqlState();

        // 一次链式调用都没做过时 sql 还是空数组，直接 setSqlState 会把 defaults 里的
        // order / limit 键一起抹掉，后续 order() 会报「order() 不存在」
        if ($snapshot === []) {
            $this->resetQueryState();
            $snapshot = $this->getSqlState();
        }

        // 游标靠「大于上一批最后一条」推进，原有排序与分页会破坏这个序列，必须剔掉
        // 注意必须「置空」而不是 unset：Common::__call 要求 sql 里键存在，
        // 键被删掉会直接报「order() 不存在」
        $snapshot['order'] = '';
        $snapshot['limit'] = '';

        $last = null;

        try {
            while (true) {
                $this->setSqlState($snapshot);

                if ($last !== null) {
                    $this->where([$column, '>', $last]);
                }

                $this->order($column . ' ASC');
                $this->limit(0, $size);

                $rows = $this->select();
                if (!is_array($rows) || $rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    if (!is_array($row) || !array_key_exists($column, $row)) {
                        throw new \RuntimeException(
                            'lazyById 需要结果里含游标列「' . $column . '」，请检查 field() 是否把它排除掉了'
                        );
                    }

                    $last = $row[$column];
                    yield $row;
                }

                if (count($rows) < $size) {
                    break;
                }
            }
        } finally {
            $this->setSqlState($snapshot);
        }
    }

/**
     * 创建当前 Model 的独立链式查询实例。
     *
     * M() 在同一请求内按模型、连接和租户上下文复用实例，以降低连接与元数据
     * 初始化开销。构造 EXISTS、UNION 等嵌套查询时必须使用独立实例，否则内层
     * resetQueryState() 会清空外层条件，甚至把查询对象自身挂入子查询形成递归。
     * 新实例仍经框架连接池获取同一租户连接，不改变主库/业务库边界。
     *
     * @return static 与当前 Model 类型、连接配置和逻辑表一致的独立查询实例
     */
    public function newQuery(): static
    {
        $query = \Candy\Core\Container::getInstance()->make(static::class, [], true);
        if (!$query instanceof static) {
            throw new \RuntimeException('独立查询 Model 实例创建失败');
        }

        $query->setLink($this->pendingLinkConfig, $this->pendingLinkRole);
        if ($this->tableName !== '') {
            $query->setTable($this->tableName);
        }
        $query->path = $this->path;

        return $query;
    }

    /**
     * 添加同查询或外层查询的字段比较条件。
     *
     * 外层字段使用 ['outer', 'column'] 形式，仅供 exists 子查询关联外层查询字段。
     *
     * @param string $left 当前查询字段
     * @param string $operator 比较运算符
     * @param string|array{0:string,1:string} $right 当前查询字段或外层字段
     * @return static 当前模型
     */
    public function whereColumn(string $left, string $operator, string|array $right): static
    {
        $this->connection()->whereColumn($left, $operator, $right);
        return $this;
    }

    /**
     * 添加 EXISTS 子查询条件。
     *
     * @param object $subquery 由 M() 创建并配置完成的子查询模型
     * @return static 当前模型
     */
    public function whereExists(object $subquery): static
    {
        $this->connection()->whereExists($subquery);
        return $this;
    }

    /**
     * 添加 OR EXISTS 子查询条件。
     *
     * @param object $subquery 由 M() 创建并配置完成的子查询模型
     * @return static 当前模型
     */
    public function orWhereExists(object $subquery): static
    {
        $this->connection()->orWhereExists($subquery);
        return $this;
    }

    /**
     * 添加 OR 连接的 EXISTS 子查询组。
     *
     * @param array<int,object> $subqueries 由 M() 创建并配置完成的子查询模型
     * @return static 当前模型
     */
    public function whereExistsAny(array $subqueries): static
    {
        $this->connection()->whereExistsAny($subqueries);
        return $this;
    }

    /**
     * 添加 NOT EXISTS 子查询条件。
     *
     * @param object $subquery 由 M() 创建并配置完成的子查询模型
     * @return static 当前模型
     */
    public function whereNotExists(object $subquery): static
    {
        $this->connection()->whereNotExists($subquery);
        return $this;
    }

    /**
     * 添加 OR 连接的 NOT EXISTS 子查询组。
     *
     * @param array<int,object> $subqueries 由 M() 创建并配置完成的子查询模型
     * @return static 当前模型
     */
    public function whereNotExistsAny(array $subqueries): static
    {
        $this->connection()->whereNotExistsAny($subqueries);
        return $this;
    }

    /**
     * 编译为供外层 exists 使用的参数化 SELECT 子查询。
     *
     * @param string|null $outerQualifier 外层查询表或别名的已引用限定符
     * @return array{sql:string,data:array<int,mixed>} 子查询 SQL 与绑定参数
     */
    public function compileSubquery(?string $outerQualifier = null): array
    {
        return $this->connection()->compileSubquery($outerQualifier);
    }

    /**
     * 编译当前链式查询为参数化 SELECT，不执行也不重置查询状态。
     *
     * @param array{order?:bool,limit?:bool,lock?:bool} $options 是否保留分支排序、分页与锁
     * @return array{sql:string,data:array<int,mixed>,fields:string} SQL、绑定参数与投影字段
     */
    public function compileSelect(array $options = []): array
    {
        return $this->connection()->compileSelect($options);
    }

    /**
     * 组合多个 Model 的参数化 SELECT 为 UNION ALL，并统一执行外层分页。
     *
     * @param array<int,self> $models 已配置筛选、字段与 JOIN 的 Model 列表
     * @param array<string,string>|array<int,array{0:string,1:string}> $order 外层排序
     * @param int $offset 起始偏移
     * @param int $limit 返回条数，0 表示不分页
     * @param string $alias 派生表别名
     * @param bool $preserveBranchOrderLimit 是否保留各分支自身的排序与条数上限
     * @return array 查询结果
     */
    public function selectUnion(
        array $models,
        array $order = [],
        int $offset = 0,
        int $limit = 0,
        string $alias = 'union_rows',
        bool $preserveBranchOrderLimit = false
    ): array {
        $branches = [];
        foreach ($models as $model) {
            if (!$model instanceof self) {
                throw new \InvalidArgumentException('UNION 分支必须是 BaseModel 实例');
            }
            $branches[] = $model->compileSelect([
                'order' => $preserveBranchOrderLimit,
                'limit' => $preserveBranchOrderLimit,
                'lock' => false,
            ]);
        }

        return $this->selectCompiledUnion(
            $branches,
            $order,
            $offset,
            $limit,
            $alias,
            $preserveBranchOrderLimit
        );
    }

    /**
     * 执行已经由 ORM 链式条件编译的 UNION ALL 分支。
     *
     * @param array<int, array{sql:string,data:array<int,mixed>}> $branches 已编译的参数化查询分支
     * @param array<string,string>|array<int,array{0:string,1:string}> $order 外层排序
     * @param int $offset 起始偏移
     * @param int $limit 返回条数，0 表示不分页
     * @param string $alias 派生表别名
     * @param bool $preserveBranchOrderLimit 是否保留分支排序和条数上限
     * @return array 查询结果
     */
    protected function selectCompiledUnion(
        array $branches,
        array $order = [],
        int $offset = 0,
        int $limit = 0,
        string $alias = 'union_rows',
        bool $preserveBranchOrderLimit = false
    ): array {
        if ($preserveBranchOrderLimit) {
            $branchIndex = 0;
            foreach ($branches as &$branch) {
                if (!is_array($branch) || !isset($branch['sql'])) {
                    throw new \InvalidArgumentException('UNION 分支必须是 ORM 编译结果');
                }
                $branch['sql'] = 'SELECT * FROM (' . trim((string)$branch['sql'])
                    . ') AS union_branch_' . $branchIndex;
                $branchIndex++;
            }
            unset($branch);
        }

        return $this->connection()->selectUnion($branches, $order, $offset, $limit, $alias);
    }

    /**
     * 统计记录数（兼容别名）
     *
     * 与 total() 完全等价：无参返回 int；传入 $length 时返回 ['total'=>int,'list'=>array]
     * 业务 Model 常误写 count()，在此统一转到 ORM 的 total()，避免 BadMethodCallException
     *
     * @param int|null $offset 起始偏移（与 $length 同时传入时生效）
     * @param int|null $length 返回条数
     * @return int|array{total:int,list:array}
     */
    public function count(?int $offset = null, ?int $length = null): int|array
    {
        return $this->connection()->total($offset, $length);
    }

    /**
     * 在 Model 内执行无法由链式构造器清晰表达的参数化多行查询。
     *
     * @param string $sql 单条 SELECT SQL
     * @param array $bindings 绑定参数
     * @return array 查询结果
     */
    protected function rawSelect(string $sql, array $bindings = []): array
    {
        $this->assertRawSql($sql, 'SELECT');
        $result = $this->connection()->query($sql, 'select', $bindings);
        return is_array($result) ? $result : [];
    }

    /**
     * 在 Model 内执行无法由链式构造器清晰表达的参数化单行查询。
     *
     * @param string $sql 单条 SELECT SQL
     * @param array $bindings 绑定参数
     * @return array|null 查询结果
     */
    protected function rawFind(string $sql, array $bindings = []): ?array
    {
        $this->assertRawSql($sql, 'SELECT');
        $result = $this->connection()->query($sql, 'find', $bindings);
        return is_array($result) ? $result : null;
    }

    /**
     * 在 Model 内执行参数化写语句并返回影响行数。
     *
     * 写入成功后主动失效当前连接、库与表对应的查询缓存，保证同请求后续读取
     * 与普通 ORM 写操作具备一致的数据新鲜度。
     *
     * @param string $sql 单条 INSERT、UPDATE 或 DELETE SQL
     * @param array $bindings 绑定参数
     * @return int 影响行数
     */
    protected function rawExecute(string $sql, array $bindings = []): int
    {
        $command = strtoupper((string)strtok(ltrim($sql), " \t\r\n"));
        if (!in_array($command, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new \InvalidArgumentException('rawExecute 只允许写语句');
        }
        $this->assertRawSql($sql, $command);
        $connection = $this->connection();
        $affected = (int)$connection->query(
            $sql,
            $command === 'INSERT' ? 'update' : strtolower($command),
            $bindings
        );
        if ($affected > 0) {
            $connection->invalidateQueryCache();
        }

        return $affected;
    }

    /**
     * 在当前数据库指纹的共享事务中执行回调。
     *
     * @param callable $callback 事务业务，接收当前 Model
     * @return mixed 回调结果
     * @throws \Throwable 回调异常在回滚后继续抛出
     */
    public function transaction(callable $callback): mixed
    {
        $key = $this->transactionContextKey();
        $scope = T($key, '', null);
        if ($scope instanceof TransactionScope) {
            $scope->depth++;
            $savepoint = 'candy_sp_' . $scope->depth;
            $scope->owner->query('SAVEPOINT ' . $savepoint, 'other');
            try {
                $result = $callback($this);
                $scope->owner->query('RELEASE SAVEPOINT ' . $savepoint, 'other');
                $scope->depth--;
                return $result;
            } catch (\Throwable $exception) {
                $scope->owner->query('ROLLBACK TO SAVEPOINT ' . $savepoint, 'other');
                $scope->depth--;
                throw $exception;
            }
        }

        $connection = $this->connection();
        $connection->useWrite();
        if (!$connection->beginTransaction()) { throw new \RuntimeException('数据库事务启动失败'); }
        $scope = new TransactionScope($connection);
        T($key, $scope);
        try {
            $result = $callback($this);
            if (!$connection->commit()) { throw new \RuntimeException('数据库事务提交失败'); }
            T($key, null);
            foreach ($scope->participants as $participant) { $participant->useAuto(); }
            foreach ($scope->afterCommit as $afterCommit) { $afterCommit(); }
            return $result;
        } catch (\Throwable $exception) {
            try { $connection->rollBack(); } catch (\Throwable) {}
            T($key, null);
            foreach ($scope->participants as $participant) { $participant->useAuto(); }
            throw $exception;
        }
    }

    /**
     * 将当前模型绑定到另一个已开启事务的模型连接。
     *
     * 该入口只允许在拥有者事务回调内使用，当前模型复用同一连接池中的
     * 底层 PDO、事务上下文，但保持独立的 DBServer 表状态，不会自行开启、
     * 提交或回滚事务。业务层通过它将审计 Outbox 纳入业务事实事务。
     *
     * @param self $owner 已开启事务的拥有者模型
     * @return static 当前模型
     * @throws \LogicException 拥有者不在事务内时抛出
     */
    public function shareTransactionFrom(self $owner): static
    {
        $scope = T($owner->transactionContextKey(), '', null);
        if (!$scope instanceof TransactionScope) {
            throw new \LogicException('shareTransactionFrom 必须在拥有者 transaction 回调内调用');
        }

        $this->pendingLinkConfig = $owner->pendingLinkConfig;
        $this->pendingLinkRole = $owner->pendingLinkRole;
        $this->pendingDriver = $owner->pendingDriver;
        // DBServer 同时持有表名和字段元数据；直接共享对象会导致跨 Model
        // setTable() 覆盖事务所有者的 fieldList，后续更新因此被误判为非法字段。
        // 重新建立独立 DBServer 后，连接池仍会复用同一个底层 PDO 事务。
        $this->connection = null;
        $this->connection();
        $this->enlistTransactionScope();

        return $this;
    }

    /**
     * 注册最外层事务提交成功后执行的回调。
     *
     * @param callable $callback 提交后回调
     * @return static 当前 Model
     */
    public function afterCommit(callable $callback): static
    {
        $scope = T($this->transactionContextKey(), '', null);
        if (!$scope instanceof TransactionScope) { throw new \LogicException('afterCommit 必须在 transaction 回调内调用'); }
        $scope->afterCommit[] = $callback;
        return $this;
    }

    /**
     * Connection
     * @return DBServer
     */
    protected function connection(): DBServer
    {
        if ($this->connection === null) {
            $driver = $this->pendingDriver ?? DBServer::resolveDriverFromConfig($this->pendingLinkConfig);
            $class = '\\Candy\\Extend\\DB\\Driver\\' . $driver;
            if (!class_exists($class)) {
                throw new \RuntimeException('数据库驱动不存在: ' . $driver);
            }
            // 每个 Model 独立驱动实例，避免多表模型共享连接时 tabName / where 状态互相污染
            $this->connection = \Candy\Core\Container::getInstance()->make($class, [], true);
            $this->connection->setLink($this->pendingLinkConfig, $this->pendingLinkRole);
            // 将 Model 上的时间字段 / 自动时间戳等同步到连接（否则 insert 不会写 create_time）
            $this->connection->applyModelMeta($this);
            if ($this->tableName !== '') {
                $this->connection->setTable($this->tableName);
            }
            $this->connection->path = $this->path;
        }

        return $this->connection;
    }

    /** @return string 当前连接事务上下文键 */
    private function transactionContextKey(): string
    {
        return '_db_transaction.' . hash('sha256', buildDbContainerKey($this->pendingLinkConfig, null));
    }

    /** @return void 将当前 DBServer 加入已存在的共享事务 */
    private function enlistTransactionScope(): void
    {
        $scope = T($this->transactionContextKey(), '', null);
        if ($scope instanceof TransactionScope && $this->connection instanceof DBServer) { $scope->enlist($this->connection); }
    }

    /** @param string $sql SQL @param string $command 允许命令 @return void */
    private function assertRawSql(string $sql, string $command): void
    {
        $trimmed = trim($sql);
        if ($trimmed === '' || !str_starts_with(strtoupper($trimmed), $command)) { throw new \InvalidArgumentException('原始 SQL 命令类型不匹配'); }
        if (str_contains(rtrim($trimmed, ';'), ';')) { throw new \InvalidArgumentException('原始 SQL 只允许单条语句'); }
    }


    /**
     * 查不到就创建（Laravel firstOrCreate 等价）
     *
     * ```php
     * $u = M('Users')->firstOrCreate(['username' => 'zhangsan']);
     * $u = M('Users')->firstOrCreate(['email' => $e], ['role' => 'employee']);
     * ```
     *
     * 注意：**不是原子操作**（先查后插）。并发场景请配合唯一索引或 `cache_lock()`。
     *
     * @param array<string, mixed> $attributes 查找条件；创建时也会合并进写入数据
     * @param array<string, mixed> $values     仅在创建时追加的字段
     * @return array<string, mixed> 命中的或新建的记录；建失败返回空数组
     */
    public function firstOrCreate(array $attributes, array $values = []): array
    {
        $row = $this->resetQueryState()->where($attributes)->find();
        if (is_array($row) && $row !== []) {
            return $row;
        }

        $data = array_merge($attributes, $values);
        $id   = $this->resetQueryState()->add($data);
        if ($id === false || $id === null || $id === 0 || $id === '') {
            return [];
        }

        $saved = $this->resetQueryState()->find((int) $id);

        return is_array($saved) && $saved !== [] ? $saved : array_merge($data, ['id' => $id]);
    }

    /**
     * 查不到就创建，查到就更新（Laravel updateOrCreate 等价）
     *
     * @param array<string, mixed> $attributes 查找条件
     * @param array<string, mixed> $values     命中时更新的字段；未命中时与 attributes 合并作为插入数据
     * @return array<string, mixed> 处理后的记录；失败返回空数组
     */
    public function updateOrCreate(array $attributes, array $values = []): array
    {
        $row  = $this->resetQueryState()->where($attributes)->find();
        $pri  = $this->primaryKeyName();

        if (is_array($row) && $row !== []) {
            $id = $row[$pri] ?? null;
            if ($id === null) {
                return $row;
            }
            if ($values !== []) {
                $this->resetQueryState()->where([$pri => $id])->update($values);
            }
            $saved = $this->resetQueryState()->find($id);

            return is_array($saved) && $saved !== [] ? $saved : $row;
        }

        $data = array_merge($attributes, $values);
        $id   = $this->resetQueryState()->add($data);
        if ($id === false || $id === null || $id === 0 || $id === '') {
            return [];
        }

        $saved = $this->resetQueryState()->find((int) $id);

        return is_array($saved) && $saved !== [] ? $saved : array_merge($data, ['id' => $id]);
    }

    /**
     * 按主键查找，找不到抛异常（Laravel findOrFail 等价）
     *
     * @param int|string $pri 主键值
     * @return array<string, mixed>
     * @throws \Candy\Extend\DB\Exception\RecordNotFoundException
     */
    public function findOrFail(int|string $pri): array
    {
        $row = $this->find($pri);
        if (!is_array($row) || $row === []) {
            throw new \Candy\Extend\DB\Exception\RecordNotFoundException(
                static::class . ' 未找到主键为 ' . $pri . ' 的记录'
            );
        }

        return $row;
    }

    /**
     * 按当前查询条件取第一条，找不到抛异常（Laravel firstOrFail 等价）
     *
     * @return array<string, mixed>
     * @throws \Candy\Extend\DB\Exception\RecordNotFoundException
     */
    public function firstOrFail(): array
    {
        $row = $this->find();
        if (!is_array($row) || $row === []) {
            throw new \Candy\Extend\DB\Exception\RecordNotFoundException(
                static::class . ' 按当前条件未找到记录'
            );
        }

        return $row;
    }

    /**
     * 恢复软删除的记录（把删除时间字段置 NULL）
     *
     * ```php
     * M('Tickets')->restore($id);
     * M('Tickets')->restore([1, 2, 3]);
     * ```
     *
     * @param int|string|array<int, int|string> $pri 主键值或主键值数组
     * @return int 恢复的行数；0 表示未启用软删除或无匹配记录
     */
    public function restore(int|string|array $pri): int
    {
        if (!$this->softDeleteEnabled()) {
            return 0;
        }

        $field  = $this->deleteTimeField();
        $priKey = $this->primaryKeyName();
        $ids    = $this->normalizeIds($pri);
        if ($ids === []) {
            return 0;
        }

        // 必须 withTrashed：软删除的默认视图会把「已删除」过滤掉，
        // 不带它更新不到要恢复的那一行
        $affected = 0;
        foreach ($ids as $id) {
            $n = $this->resetQueryState()
                ->withTrashed()
                ->where([$priKey => $id])
                ->update([$field => null]);
            if (is_int($n) && $n > 0) {
                $affected += $n;
            }
        }

        return $affected;
    }

    /**
     * 永久删除（跳过软删除，直接 DELETE）
     *
     * @param int|string|array<int, int|string> $pri 主键值或主键值数组
     * @return int 删除的行数
     */
    public function forceDelete(int|string|array $pri): int
    {
        $priKey = $this->primaryKeyName();
        $ids    = $this->normalizeIds($pri);
        if ($ids === []) {
            return 0;
        }

        $affected = 0;
        foreach ($ids as $id) {
            $n = $this->resetQueryState()
                ->withTrashed()
                ->where([$priKey => $id])
                ->delete(true);
            if (is_int($n) && $n > 0) {
                $affected += $n;
            }
        }

        return $affected;
    }

    /**
     * 软删除字段名（小写）
     *
     * @return string
     */
    private function deleteTimeField(): string
    {
        $field = (string) $this->connectionProperty('deleteTime');

        return $field !== '' ? strtolower($field) : '';
    }

    /**
     * 是否启用软删除（删除时间字段是否真实存在于表中）
     *
     * `QueryWrite::delete()` 的判定条件就是「字段在 fieldList 里才算软删除」，
     * 这里保持一致，避免 restore 去更新一个不存在的列。
     *
     * @return bool
     */
    private function softDeleteEnabled(): bool
    {
        $field = $this->deleteTimeField();
        if ($field === '') {
            return false;
        }

        $fieldList = $this->connectionProperty('fieldList');
        if (!is_array($fieldList) || $fieldList === []) {
            // 字段列表还没加载（未执行过查询）：先跑一次极轻探测
            try {
                $this->resetQueryState()->limit(0, 1)->select();
            } catch (\Throwable) {
                return false;
            }
            $fieldList = $this->connectionProperty('fieldList');
        }

        if (!is_array($fieldList)) {
            return false;
        }

        foreach ($fieldList as $key => $value) {
            $name = is_string($key) ? $key : $value;
            if (is_string($name) && strtolower($name) === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * 主键字段名
     *
     * @return string
     */
    private function primaryKeyName(): string
    {
        $pk = (string) $this->connectionProperty('pk');

        return $pk !== '' ? $pk : 'id';
    }

    /**
     * 读取 DBServer 上的 protected 属性
     *
     * `$pk` / `$deleteTime` / `$fieldList` 都声明在 DBServer 上（不是 Model），
     * Model 里的同名声明会由 `applyModelMeta()` 同步过去。
     *
     * @param string $name 属性名
     * @return mixed 属性值；读取失败返回 null
     */
    private function connectionProperty(string $name): mixed
    {
        try {
            $conn = $this->connection();
            $prop = new \ReflectionProperty($conn, $name);

            return $prop->isInitialized($conn) ? $prop->getValue($conn) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 归一化主键参数为去重后的非空 id 数组
     *
     * @param int|string|array<int, int|string> $pri 主键值或数组
     * @return array<int, int|string>
     */
    private function normalizeIds(int|string|array $pri): array
    {
        $ids = is_array($pri) ? array_values($pri) : [$pri];
        $out = [];
        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     *   call
     * @param mixed $method
     * @param mixed $args
     * @return mixed
     */
    public function __call(string $method, array $args): mixed
    {
        $lower = strtolower($method);
        $conn = $this->connection();

        if (in_array($lower, self::CHAIN_METHODS, true)) {
            $conn->$lower(...$args);
            return $this;
        }

        if (in_array($lower, self::TERMINAL_METHODS, true) || method_exists($conn, $method)) {
            // 全局作用域：真正执行前注入（幂等，同一查询链只注入一次）
            $isScopedQuery = in_array($lower, ['select', 'find', 'total', 'count'], true);
            if ($isScopedQuery) {
                $this->applyGlobalScopes();
            }

            $result = $conn->$method(...$args);

            // 执行完立刻重置：M() 返回容器同一实例，不重置会让
            // withoutGlobalScopes() 的豁免标记残留到下一次查询
            if ($isScopedQuery) {
                $this->resetGlobalScopeState();
            }

            // with() 声明了预加载时，在 select/find 之后统一挂关系（批量查，避免 N+1）
            // pendingWith（关系预加载）或 pendingWithAgg（withCount/withSum/withAvg）非空都要挂
            if (($lower === 'select' || $lower === 'find')
                && ($this->pendingWith !== [] || $this->pendingWithAgg !== [])) {
                $result = $this->applyPendingRelations($result, $lower === 'find');
            }

            // 输出字段过滤（$hidden/$visible）：只影响读出，不影响写库
            if (is_array($result)) {
                if ($lower === 'select') {
                    $result = $this->filterOutputRows($result);
                } elseif ($lower === 'find') {
                    $result = $this->filterOutputFields($result);
                }
            }

            // 豁免标记只对本次查询生效，用完即清
            if ($isScopedQuery && ($this->skipHiddenOnce || $this->forceVisible !== [])) {
                $this->skipHiddenOnce = false;
                $this->forceVisible   = [];
            }

            return $result;
        }

        throw new \BadMethodCallException(static::class . '::' . $method . '() 不存在');
    }
}
