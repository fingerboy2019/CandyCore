<?php
/***
 * 模型级关系声明
 *
 * 业务说明：让 Model 声明 belongsTo / hasMany / belongsToMany，提供 with() 预加载
 *          与 load() 懒加载，消除同一段关联逻辑在多处重复，并天然避免 N+1。
 *
 * 设计要点：
 *  1. 多对多**不走 r_select 递归**（attachRSelectChildren 只挂一层），改为中间表 + 目标表两次查询，
 *     结果更直观、不产生 JOIN 笛卡尔积。
 *  2. 预加载一律 whereIn 批量查，再在 PHP 侧按外键分组，保证 N 条主记录只产生 2 次查询。
 *  3. 关系未声明时抛异常，而不是静默返回空 —— r_select 最大的坑就是「写错不报错，只是查不出数据」。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-12 14:15:00 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait RelationDeclare
{
    /**
     * 关系声明：别名 => 配置
     *
     * 三种类型（位置数组，与 r_select 一致的紧凑风格）：
     *   'creator'  => ['belongsTo',     'Users',    'user_id']
     *                 本表 user_id  →  Users.id
     *
     *   'comments' => ['hasMany',       'Comments', 'task_id']
     *                 目标表 Comment.task_id → 本表 id
     *
     *   'tags'     => ['belongsToMany', 'Tags',     'task_id', 'tag_id', 'TagLinks']
     *                 本表 id → 中间表 TagLinks.task_id → 目标表 Tags.id（tag_id 为中间表里指向目标的外键）
     *
     * 也支持关联数组写法（更明确，推荐新代码使用）：
     *   'tags' => ['type' => 'belongsToMany', 'model' => 'Tags', 'fk' => 'task_id',
     *              'rk' => 'tag_id', 'pivot' => 'TagLinks', 'fields' => 'id,name']
     *
     * @var array<string, array>
     */
    protected array $relations = [];

    /** @var array<int, string> 待预加载的关系别名队列 */
    private array $pendingWith = [];


    
    /** @var array<string, array{agg: string, column: string}> 待聚合的关系队列 */
    private array $pendingWithAgg = [];

    /**
     * 追加关联计数列（Laravel withCount 等价）
     *
     * 生成 `<关系名>_count` 字段，不加载关联数据本身，只做聚合。
     *
     * ```php
     * M('Tickets')->withCount('comments')->select();
     * // 每行多一个 comments_count
     * ```
     *
     * @param string|array<int, string> $relations 关系别名（可传数组）
     * @return static
     */
    public function withCount(string|array $relations): static
    {
        foreach ((array) $relations as $name) {
            $name = (string) $name;
            if ($name !== '') {
                $this->pendingWithAgg[$name] = ['agg' => 'count', 'column' => '*'];
            }
        }

        return $this;
    }

    /**
     * 追加关联求和列（Laravel withSum 等价）
     *
     * @param string $relation 关系别名
     * @param string $column   目标表要汇总的列
     * @return static
     */
    public function withSum(string $relation, string $column): static
    {
        if ($relation !== '' && $column !== '') {
            $this->pendingWithAgg[$relation] = ['agg' => 'sum', 'column' => $column];
        }

        return $this;
    }

    /**
     * 追加关联求平均列（Laravel withAvg 等价）
     *
     * @param string $relation 关系别名
     * @param string $column   目标表要平均的列
     * @return static
     */
    public function withAvg(string $relation, string $column): static
    {
        if ($relation !== '' && $column !== '') {
            $this->pendingWithAgg[$relation] = ['agg' => 'avg', 'column' => $column];
        }

        return $this;
    }

    /**
     * 执行待聚合的关系（在关系预加载之后调用）
     *
     * @param array<int, array> $rows 主记录
     * @return array<int, array>
     */
    private function applyPendingAgg(array $rows): array
    {
        if ($this->pendingWithAgg === [] || $rows === []) {
            $this->pendingWithAgg = [];

            return $rows;
        }

        $aggs             = $this->pendingWithAgg;
        $this->pendingWithAgg = [];

        foreach ($aggs as $name => $spec) {
            $cfg = $this->resolveRelation((string) $name);
            $agg = $spec['agg'];
            $col = $spec['column'];

            $alias = $name . '_' . $agg;

            // hasMany：目标表 fk → 本表 pk
            if ($cfg['type'] === 'hasMany') {
                $ids = [];
                foreach ($rows as $r) {
                    $v = $r[$cfg['pk']] ?? null;
                    if ($v !== null && $v !== '') {
                        $ids[(string) $v] = $v;
                    }
                }
                $map = [];
                if ($ids !== []) {
                    $expr = $agg === 'count'
                        ? 'COUNT(*) AS __agg'
                        : strtoupper($agg) . '(' . $col . ') AS __agg';
                    $res = $this->relationQuery($cfg['model'], $cfg['fk'] . ', ' . $expr)
                        ->where([$cfg['fk'], 'IN', array_values($ids)])
                        ->group($cfg['fk'])
                        ->select();
                    foreach ((array) $res as $item) {
                        if (is_array($item)) {
                            $map[(string) ($item[$cfg['fk']] ?? '')] = $item['__agg'] ?? 0;
                        }
                    }
                }
                foreach ($rows as &$r) {
                    $key = (string) ($r[$cfg['pk']] ?? '');
                    $raw = $map[$key] ?? 0;
                    $r[$alias] = $agg === 'count' ? (int) $raw : (float) $raw;
                }
                unset($r);
                continue;
            }

            // belongsTo：本表 fk → 目标表 pk（命中即 1，否则 0）
            if ($cfg['type'] === 'belongsTo') {
                $keys = [];
                foreach ($rows as $r) {
                    $v = $r[$cfg['fk']] ?? null;
                    if ($v !== null && $v !== '' && $v !== 0 && $v !== '0') {
                        $keys[(string) $v] = $v;
                    }
                }
                $hit = [];
                if ($keys !== []) {
                    $res = $this->relationQuery($cfg['model'], $cfg['pk'])
                        ->where([$cfg['pk'], 'IN', array_values($keys)])
                        ->select();
                    foreach ((array) $res as $item) {
                        if (is_array($item)) {
                            $hit[(string) ($item[$cfg['pk']] ?? '')] = true;
                        }
                    }
                }
                foreach ($rows as &$r) {
                    $k = (string) ($r[$cfg['fk']] ?? '');
                    $r[$alias] = ($k !== '' && isset($hit[$k])) ? 1 : 0;
                }
                unset($r);
                continue;
            }

            // belongsToMany：本表 pk → 中间表 fk 的计数
            if ($cfg['type'] === 'belongsToMany') {
                $ids = [];
                foreach ($rows as $r) {
                    $v = $r[$cfg['pk']] ?? null;
                    if ($v !== null && $v !== '') {
                        $ids[(string) $v] = $v;
                    }
                }
                $map = [];
                if ($ids !== []) {
                    $res = $this->relationQuery(
                        $cfg['pivot'],
                        $cfg['fk'] . ', COUNT(*) AS __agg'
                    )->where([$cfg['fk'], 'IN', array_values($ids)])
                      ->group($cfg['fk'])
                      ->select();
                    foreach ((array) $res as $item) {
                        if (is_array($item)) {
                            $map[(string) ($item[$cfg['fk']] ?? '')] = $item['__agg'] ?? 0;
                        }
                    }
                }
                foreach ($rows as &$r) {
                    $r[$alias] = (int) ($map[(string) ($r[$cfg['pk']] ?? '')] ?? 0);
                }
                unset($r);
            }
        }

        return $rows;
    }

    /**
     * 基于关联的条件过滤（Laravel whereHas 等价）
     *
     * 生成 EXISTS 子查询，不产生笛卡尔积、不需要 JOIN。
     * 三种关系类型都支持；回调里可继续对子查询加条件。
     *
     * ```php
     * // 有评论的工单
     * M('Tickets')->whereHas('comments')->select();
     *
     * // 有紧急评论的工单
     * M('Tickets')->whereHas('comments', fn($q) => $q->where(['level' => 'urgent']))->select();
     * ```
     *
     * @param string        $relation 关系别名（须在 $relations 声明）
     * @param callable|null $callback 子查询回调，参数为关联 Model 实例
     * @return static
     * @throws \InvalidArgumentException 关系未声明或类型不支持
     */
    public function whereHas(string $relation, ?callable $callback = null): static
    {
        $cfg    = $this->resolveRelation($relation);
        $target = $cfg['model'];
        $pk     = $cfg['pk'];
        $fk     = $cfg['fk'];

        if ($cfg['type'] === 'belongsTo') {
            // 本表 fk → 目标表 pk
            $sub = $this->relationQuery($target, $pk);
            $sub->whereColumn($pk, '=', ['outer', $fk]);
            if ($callback !== null) {
                $callback($sub);
            }
            $this->whereExists($sub);

            return $this;
        }

        if ($cfg['type'] === 'hasMany') {
            // 目标表 fk → 本表 pk
            $sub = $this->relationQuery($target, $fk);
            $sub->whereColumn($fk, '=', ['outer', $pk]);
            if ($callback !== null) {
                $callback($sub);
            }
            $this->whereExists($sub);

            return $this;
        }

        if ($cfg['type'] === 'belongsToMany') {
            // 本表 pk → 中间表 fk，中间表 rk → 目标表 pk
            // 用两层 EXISTS：外层查中间表，内层查目标表
            $rk    = $cfg['rk'];
            $pivot = $cfg['pivot'];

            $inner = $this->relationQuery($target, $pk);
            $inner->whereColumn($pk, '=', ['outer', $rk]);
            if ($callback !== null) {
                $callback($inner);
            }

            $outer = $this->relationQuery($pivot, $fk);
            $outer->whereColumn($fk, '=', ['outer', $pk]);
            $outer->whereExists($inner);

            $this->whereExists($outer);

            return $this;
        }

        throw new \InvalidArgumentException('whereHas 不支持的关系类型: ' . $cfg['type']);
    }

    /**
     * whereHas 的反义：没有匹配关联记录
     *
     * @param string        $relation 关系别名
     * @param callable|null $callback 子查询回调
     * @return static
     */
    public function whereDoesntHave(string $relation, ?callable $callback = null): static
    {
        $cfg    = $this->resolveRelation($relation);
        $target = $cfg['model'];
        $pk     = $cfg['pk'];
        $fk     = $cfg['fk'];

        if ($cfg['type'] === 'belongsTo') {
            $sub = $this->relationQuery($target, $pk);
            $sub->whereColumn($pk, '=', ['outer', $fk]);
            if ($callback !== null) {
                $callback($sub);
            }
            $this->whereNotExists($sub);

            return $this;
        }

        if ($cfg['type'] === 'hasMany') {
            $sub = $this->relationQuery($target, $fk);
            $sub->whereColumn($fk, '=', ['outer', $pk]);
            if ($callback !== null) {
                $callback($sub);
            }
            $this->whereNotExists($sub);

            return $this;
        }

        if ($cfg['type'] === 'belongsToMany') {
            $rk    = $cfg['rk'];
            $pivot = $cfg['pivot'];
            $inner = $this->relationQuery($target, $pk);
            $inner->whereColumn($pk, '=', ['outer', $rk]);
            if ($callback !== null) {
                $callback($inner);
            }
            $outer = $this->relationQuery($pivot, $fk);
            $outer->whereColumn($fk, '=', ['outer', $pk]);
            $outer->whereExists($inner);
            $this->whereNotExists($outer);

            return $this;
        }

        throw new \InvalidArgumentException('whereDoesntHave 不支持的关系类型: ' . $cfg['type']);
    }

    /**
     * 设定本批次查询要预加载的关系
     *
     * @param string|array $names 关系别名，可传数组
     * @return static 当前模型
     */
    public function with(string|array $names): static
    {
        foreach ((array) $names as $name) {
            $name = (string) $name;
            if ($name !== '' && !in_array($name, $this->pendingWith, true)) {
                $this->pendingWith[] = $name;
            }
        }

        return $this;
    }

    /**
     * 懒加载单条记录的指定关系
     *
     * @param array|null $row  单条主记录
     * @param string     $name 关系别名
     * @return array 挂好关系的记录；$row 为 null 时返回空数组
     */
    public function load(?array $row, string $name): array
    {
        if (!is_array($row) || $row === []) {
            return [];
        }

        $loaded = $this->eagerLoadRelations([$row], [$name]);

        return $loaded[0] ?? $row;
    }

    /**
     * 查询结果挂钩（由 BaseModel::__call 在 select/find 后调用）
     *
     * @param mixed $result select 返回 array<int,array>，find 返回 ?array
     * @param bool  $single 是否为单条查询
     * @return mixed 挂好关系的原始结构
     */
    protected function applyPendingRelations(mixed $result, bool $single = false): mixed
    {
        // 只有 withCount/withSum 而没有 with 时，pendingWith 为空但聚合仍需执行
        if ($this->pendingWith === [] && $this->pendingWithAgg === []) {
            return $result;
        }

        if (!is_array($result)) {
            $this->resetPendingWith();
            $this->pendingWithAgg = [];

            return $result;
        }

        $names = $this->pendingWith;
        $this->resetPendingWith();

        if ($single) {
            if ($result === []) {
                return $result;
            }
            $rows = $this->eagerLoadRelations([$result], $names);
            $rows = $this->applyPendingAgg($rows);

            return $rows[0] ?? $result;
        }

        if ($result === []) {
            return $result;
        }

        $rows = $this->eagerLoadRelations($result, $names);

        return $this->applyPendingAgg($rows);
    }

    /**
     * 批量预加载多个关系
     *
     * @param array<int, array> $rows  主记录集合
     * @param array<int, string> $names 关系别名
     * @return array<int, array> 挂好关系的记录集合
     */
    private function eagerLoadRelations(array $rows, array $names): array
    {
        foreach ($names as $name) {
            $rows = $this->eagerLoadOne($rows, (string) $name);
        }

        return $rows;
    }

    /**
     * 预加载单个关系
     *
     * @param array<int, array> $rows 主记录集合
     * @param string            $name 关系别名
     * @return array<int, array>
     */
    private function eagerLoadOne(array $rows, string $name): array
    {
        $cfg = $this->resolveRelation($name);

        return match ($cfg['type']) {
            'belongsTo'     => $this->eagerBelongsTo($rows, $name, $cfg),
            'hasMany'       => $this->eagerHasMany($rows, $name, $cfg),
            'belongsToMany' => $this->eagerBelongsToMany($rows, $name, $cfg),
            'morphTo'       => $this->eagerMorphTo($rows, $name, $cfg),
            'morphMany'     => $this->eagerMorphMany($rows, $name, $cfg),
            'morphToMany'   => $this->eagerMorphToMany($rows, $name, $cfg),
            'morphOne'      => $this->eagerMorphOne($rows, $name, $cfg),
            default         => throw new \RuntimeException('未知关系类型: ' . $cfg['type']),
        };
    }

    /**
     * belongsTo 预加载：本表 fk → 目标表 pk
     *
     * @param array<int, array> $rows 主记录
     * @param string            $name 关系别名
     * @param array             $cfg  关系配置
     * @return array<int, array>
     */
    private function eagerBelongsTo(array $rows, string $name, array $cfg): array
    {
        $fk     = $cfg['fk'];
        $target = $cfg['model'];
        $pk     = $cfg['pk'];

        $keys = [];
        foreach ($rows as $r) {
            $v = $r[$fk] ?? null;
            if ($v !== null && $v !== '' && $v !== 0 && $v !== '0') {
                $keys[(string) $v] = $v;
            }
        }

        $map = [];
        if ($keys !== []) {
            $related = $this->relationQuery($target, $cfg['fields'])
                ->where([$pk, 'IN', array_values($keys)])
                ->select();
            foreach ((array) $related as $item) {
                if (is_array($item)) {
                    $map[(string) ($item[$pk] ?? '')] = $item;
                }
            }
        }

        foreach ($rows as &$r) {
            $r[$name] = $map[(string) ($r[$fk] ?? '')] ?? null;
        }
        unset($r);

        return $rows;
    }

    /**
     * hasMany 预加载：目标表 fk → 本表 pk
     *
     * @param array<int, array> $rows 主记录
     * @param string            $name 关系别名
     * @param array             $cfg  关系配置
     * @return array<int, array>
     */
    private function eagerHasMany(array $rows, string $name, array $cfg): array
    {
        $fk     = $cfg['fk'];
        $target = $cfg['model'];
        $pk     = $cfg['pk'];

        $ids = [];
        foreach ($rows as $r) {
            $v = $r[$pk] ?? null;
            if ($v !== null && $v !== '') {
                $ids[(string) $v] = $v;
            }
        }

        $grouped = [];
        if ($ids !== []) {
            $related = $this->relationQuery($target, $cfg['fields'])
                ->where([$fk, 'IN', array_values($ids)])
                ->select();
            foreach ((array) $related as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $k = (string) ($item[$fk] ?? '');
                if ($k !== '') {
                    $grouped[$k][] = $item;
                }
            }
        }

        foreach ($rows as &$r) {
            $r[$name] = $grouped[(string) ($r[$pk] ?? '')] ?? [];
        }
        unset($r);

        return $rows;
    }

    /**
     * belongsToMany 预加载：本表 pk → 中间表 fk → 中间表 rk → 目标表 pk
     *
     * 两次查询：①中间表按主记录 id 集合捞出 [fk, rk]；②目标表按 rk 集合捞出实体。
     * 不走 JOIN，避免笛卡尔积，也不需要 r_select 的递归挂载。
     *
     * @param array<int, array> $rows 主记录
     * @param string            $name 关系别名
     * @param array             $cfg  关系配置
     * @return array<int, array>
     */
    private function eagerBelongsToMany(array $rows, string $name, array $cfg): array
    {
        $fk      = $cfg['fk'];
        $rk      = $cfg['rk'];
        $pivot   = $cfg['pivot'];
        $target  = $cfg['model'];
        $pk      = $cfg['pk'];

        if ($pivot === '') {
            throw new \RuntimeException('belongsToMany 关系「' . $name . '」缺少中间表 model');
        }

        $ids = [];
        foreach ($rows as $r) {
            $v = $r[$pk] ?? null;
            if ($v !== null && $v !== '') {
                $ids[(string) $v] = $v;
            }
        }

        $grouped = [];
        if ($ids !== []) {
            $links = $this->relationQuery($pivot, $fk . ',' . $rk)
                ->where([$fk, 'IN', array_values($ids)])
                ->select();

            $rIds = [];
            $pairOf = [];
            foreach ((array) $links as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $lFk = (string) ($l[$fk] ?? '');
                $lRk = (string) ($l[$rk] ?? '');
                if ($lFk === '' || $lRk === '') {
                    continue;
                }
                $rIds[$lRk] = $l[$rk];
                $pairOf[] = [$lFk, $lRk];
            }

            $map = [];
            if ($rIds !== []) {
                $targets = $this->relationQuery($target, $cfg['fields'])
                    ->where([$pk, 'IN', array_values($rIds)])
                    ->select();
                foreach ((array) $targets as $t) {
                    if (is_array($t)) {
                        $map[(string) ($t[$pk] ?? '')] = $t;
                    }
                }
            }

            foreach ($pairOf as [$lFk, $lRk]) {
                if (isset($map[$lRk])) {
                    $grouped[$lFk][] = $map[$lRk];
                }
            }
        }

        foreach ($rows as &$r) {
            $r[$name] = $grouped[(string) ($r[$pk] ?? '')] ?? [];
        }
        unset($r);

        return $rows;
    }

    /**
     * 构造关联查询实例（独立实例，避免污染主查询状态）
     *
     * @param string $model  Model 名（驼峰）
     * @param string $fields 投影字段，空串表示 *
     * @return object
     */
    private function relationQuery(string $model, string $fields = ''): object
    {
        $q = M($model);
        if ($fields !== '') {
            $q->field($fields);
        }

        return $q;
    }

    /**
     * 归一化关系配置
     *
     * @param string $name 关系别名
     * @return array{type:string,model:string,fk:string,pk:string,rk:string,pivot:string,fields:string}
     */
    private function resolveRelation(string $name): array
    {
        if (!array_key_exists($name, $this->relations)) {
            throw new \InvalidArgumentException(
                static::class . ' 未声明关系「' . $name . '」，请在 $relations 中登记'
            );
        }

        $cfg = $this->relations[$name];
        if (!is_array($cfg) || $cfg === []) {
            throw new \InvalidArgumentException('关系「' . $name . '」配置为空');
        }

        // 关联数组写法
        if (isset($cfg['type'])) {
            $out = [
                'type'   => (string) $cfg['type'],
                'model'  => (string) ($cfg['model'] ?? ''),
                'fk'     => (string) ($cfg['fk'] ?? ''),
                'rk'     => (string) ($cfg['rk'] ?? ''),
                'pivot'  => (string) ($cfg['pivot'] ?? ''),
                'pk'     => (string) ($cfg['pk'] ?? 'id'),
                'fields' => (string) ($cfg['fields'] ?? ''),
            ];
        } else {
            // 位置数组写法（紧凑风格）
            $type = (string) ($cfg[0] ?? '');
            $out  = [
                'type'   => $type,
                'model'  => (string) ($cfg[1] ?? ''),
                'fk'     => (string) ($cfg[2] ?? ''),
                'rk'     => (string) ($cfg[3] ?? ''),
                'pivot'  => (string) ($cfg[4] ?? ''),
                'pk'     => 'id',
                'fields' => '',
            ];
        }

        // morphTo 用 map（类型值 => Model 名）代替单一 model，不走这条检查
        if ($out['type'] !== 'morphTo' && $out['model'] === '') {
            throw new \InvalidArgumentException('关系「' . $name . '」缺少目标 Model');
        }

        if (!in_array($out['type'], ['belongsTo', 'hasMany', 'belongsToMany', 'morphTo', 'morphMany', 'morphToMany', 'morphOne'], true)) {
            throw new \InvalidArgumentException('关系「' . $name . '」类型不合法: ' . $out['type']);
        }


        if ($out['type'] === 'belongsToMany' && $out['pivot'] === '') {
            throw new \InvalidArgumentException('关系「' . $name . '」是 belongsToMany，必须提供中间表 model');
        }

        // 多态关联：morphTo / morphMany
        if (in_array($out['type'], ['morphTo', 'morphMany', 'morphToMany', 'morphOne'], true)) {
            $out['morph_type'] = (string) ($cfg['morph_type'] ?? '');
            $out['morph_id']   = (string) ($cfg['morph_id'] ?? '');
            $out['map']        = is_array($cfg['map'] ?? null) ? $cfg['map'] : [];
            $out['value']      = (string) ($cfg['value'] ?? '');

            if ($out['type'] === 'morphTo') {
                if ($out['morph_type'] === '' || $out['morph_id'] === '') {
                    throw new \InvalidArgumentException(
                        '关系「' . $name . '」是 morphTo，必须提供 morph_type 与 morph_id 列名'
                    );
                }
                if ($out['map'] === []) {
                    throw new \InvalidArgumentException('关系「' . $name . '」是 morphTo，必须提供 map（类型值 => Model 名）');
                }

                return $out;
            }

            // morphToMany：中间表带类型列的多态多对多
            if ($out['type'] === 'morphToMany') {
                if ($out['pivot'] === '') {
                    throw new \InvalidArgumentException('关系「' . $name . '」是 morphToMany，必须提供中间表 model（pivot）');
                }
                if ($out['morph_type'] === '' || $out['fk'] === '' || $out['rk'] === '' || $out['value'] === '') {
                    throw new \InvalidArgumentException(
                        '关系「' . $name . '」是 morphToMany，必须提供 morph_type（中间表类型列）、fk（中间表存本表ID的列）、rk（中间表存目标ID的列）、value（本表对应的类型值）'
                    );
                }
                if ($out['model'] === '') {
                    throw new \InvalidArgumentException('关系「' . $name . '」是 morphToMany，必须提供目标 Model');
                }

                return $out;
            }

            // morphMany / morphOne（同一套配置语义，区别只在取多条还是一条）
            $morphKind = $out['type'];
            if ($out['model'] === '') {
                throw new \InvalidArgumentException('关系「' . $name . '」是 ' . $morphKind . '，必须提供目标 Model');
            }
            if ($out['morph_type'] === '' || $out['fk'] === '' || $out['value'] === '') {
                throw new \InvalidArgumentException(
                    '关系「' . $name . '」是 ' . $morphKind . '，必须提供 morph_type（目标表类型列）、fk（目标表 ID 列）、value（本表对应的类型值）'
                );
            }

            return $out;
        }

        return $out;
    }


    /**
     * 预加载 morphTo（本表有 xxx_type + xxx_id 两列，指向多种目标表）
     *
     * 按 type 值分组，每组一次查询，避免 N+1。
     *
     * ```php
     * // 配置
     * 'item' => [
     *     'type'       => 'morphTo',
     *     'morph_type' => 'target_type',   // 本表存的类型列
     *     'morph_id'   => 'target_id',     // 本表存的 ID 列
     *     'map'        => ['ticket' => 'Tickets', 'project' => 'Projects'],
     * ]
     * ```
     *
     * @param array<int, array> $rows 主记录
     * @param string            $name 关系别名
     * @param array             $cfg  归一化配置
     * @return array<int, array>
     */
    private function eagerMorphTo(array $rows, string $name, array $cfg): array
    {
        $typeCol = $cfg['morph_type'];
        $idCol   = $cfg['morph_id'];
        $map     = $cfg['map'];

        // ① 按 type 分组收集 id
        $buckets = [];
        foreach ($rows as $row) {
            $type = isset($row[$typeCol]) ? (string) $row[$typeCol] : '';
            $id   = $row[$idCol] ?? null;
            if ($type === '' || $id === null || $id === '' || $id === 0 || $id === '0') {
                continue;
            }
            $buckets[$type][(string) $id] = $id;
        }

        // ② 每组查一次
        $loaded = [];
        foreach ($buckets as $type => $ids) {
            $model = $map[$type] ?? '';
            if ($model === '' || $ids === []) {
                continue;
            }
            try {
                $res = $this->relationQuery($model, $cfg['fields'])
                    ->where([$cfg['pk'], 'IN', array_values($ids)])
                    ->select();
            } catch (\Throwable) {
                continue;
            }
            foreach ((array) $res as $item) {
                if (is_array($item)) {
                    $loaded[$type][(string) ($item[$cfg['pk']] ?? '')] = $item;
                }
            }
        }

        // ③ 回填（未命中的置 null，保持键存在）
        foreach ($rows as $i => $row) {
            $type = isset($row[$typeCol]) ? (string) $row[$typeCol] : '';
            $id   = (string) ($row[$idCol] ?? '');
            $rows[$i][$name] = ($type !== '' && $id !== '' && isset($loaded[$type][$id]))
                ? $loaded[$type][$id]
                : null;
        }

        return $rows;
    }

    /**
     * 预加载 morphMany（目标表有 xxx_type + xxx_id 两列指向本表）
     *
     * ```php
     * // 配置
     * 'comments' => [
     *     'type'       => 'morphMany',
     *     'model'      => 'TicketComments',
     *     'morph_type' => 'target_type',   // 目标表的类型列
     *     'fk'         => 'target_id',     // 目标表的 ID 列
     *     'value'      => 'ticket',        // 本表对应的类型值
     * ]
     * ```
     *
     * @param array<int, array> $rows 主记录
     * @param string            $name 关系别名
     * @param array             $cfg  归一化配置
     * @return array<int, array>
     */
    private function eagerMorphMany(array $rows, string $name, array $cfg): array
    {
        $pks = [];
        foreach ($rows as $row) {
            $v = $row[$cfg['pk']] ?? null;
            if ($v !== null && $v !== '') {
                $pks[(string) $v] = $v;
            }
        }

        $grouped = [];
        if ($pks !== []) {
            try {
                $res = $this->relationQuery($cfg['model'], $cfg['fields'])
                    ->where([$cfg['morph_type'], '=', $cfg['value']])
                    ->where([$cfg['fk'], 'IN', array_values($pks)])
                    ->select();
            } catch (\Throwable) {
                $res = [];
            }
            foreach ((array) $res as $item) {
                if (is_array($item)) {
                    $grouped[(string) ($item[$cfg['fk']] ?? '')][] = $item;
                }
            }
        }

        foreach ($rows as $i => $row) {
            $rows[$i][$name] = $grouped[(string) ($row[$cfg['pk']] ?? '')] ?? [];
        }

        return $rows;
    }

    /**
     * 预加载 morphToMany（多态多对多）
     *
     * 中间表结构：morph_type 类型列 + fk（本表 ID）+ rk（目标表 ID）。
     * 与 belongsToMany 的区别是中间表多一列类型，用来区分"这条关联属于哪种父类型"。
     *
     * ```php
     * // 关系配置
     * 'tags' => [
     *     'type'       => 'morphToMany',
     *     'model'      => 'Tags',        // 目标 Model
     *     'pivot'      => 'Taggables',   // 中间表 Model
     *     'morph_type' => 'taggable_type',
     *     'value'      => 'post',        // 本表在中间表里对应的类型值
     *     'fk'         => 'taggable_id', // 中间表存本表 ID 的列
     *     'rk'         => 'tag_id',      // 中间表存目标 ID 的列
     * ]
     * ```
     *
     * @param array<int, array> $rows 主记录
     * @param string            $name 关系别名
     * @param array             $cfg  归一化配置
     * @return array<int, array>
     */
    private function eagerMorphToMany(array $rows, string $name, array $cfg): array
    {
        $pk = $cfg['pk'];
        $fk = $cfg['fk'];
        $rk = $cfg['rk'];

        $ids = [];
        foreach ($rows as $r) {
            $v = $r[$pk] ?? null;
            if ($v !== null && $v !== '') {
                $ids[(string) $v] = $v;
            }
        }

        $grouped = [];
        if ($ids !== []) {
            // ① 按「类型值 + 本表ID」取中间表关联对
            try {
                $links = $this->relationQuery($cfg['pivot'], $fk . ',' . $rk)
                    ->where([$cfg['morph_type'], '=', $cfg['value']])
                    ->where([$fk, 'IN', array_values($ids)])
                    ->select();
            } catch (\Throwable) {
                $links = [];
            }

            $rIds   = [];
            $pairOf = [];
            foreach ((array) $links as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $lFk = (string) ($l[$fk] ?? '');
                $lRk = (string) ($l[$rk] ?? '');
                if ($lFk === '' || $lRk === '') {
                    continue;
                }
                $rIds[$lRk] = $l[$rk];
                $pairOf[]   = [$lFk, $lRk];
            }

            // ② 一次取回所有目标记录
            $targetMap = [];
            if ($rIds !== []) {
                try {
                    $targets = $this->relationQuery($cfg['model'], $cfg['fields'])
                        ->where([$pk, 'IN', array_values($rIds)])
                        ->select();
                } catch (\Throwable) {
                    $targets = [];
                }
                foreach ((array) $targets as $t) {
                    if (is_array($t)) {
                        $targetMap[(string) ($t[$pk] ?? '')] = $t;
                    }
                }
            }

            // ③ 按本表 ID 归组
            foreach ($pairOf as $pair) {
                [$lFk, $lRk] = $pair;
                if (isset($targetMap[$lRk])) {
                    $grouped[$lFk][] = $targetMap[$lRk];
                }
            }
        }

        foreach ($rows as $i => $row) {
            $rows[$i][$name] = $grouped[(string) ($row[$pk] ?? '')] ?? [];
        }

        return $rows;
    }

    /**
     * 预加载 morphOne（多态一对一）
     *
     * 与 morphMany 配置完全相同（目标表带 xxx_type + xxx_id 两列），区别只在结果：
     * morphMany 返回数组，morphOne 返回单条记录或 null。
     * 实现上直接复用 eagerMorphMany 再取首条，避免重复写一遍查询逻辑。
     *
     * ```php
     * 'avatar' => [
     *     'type'       => 'morphOne',
     *     'model'      => 'Attachments',
     *     'morph_type' => 'target_type',
     *     'fk'         => 'target_id',
     *     'value'      => 'ticket',
     * ]
     * ```
     *
     * @param array<int, array> $rows 主记录
     * @param string            $name 关系别名
     * @param array             $cfg  归一化配置
     * @return array<int, array>
     */
    private function eagerMorphOne(array $rows, string $name, array $cfg): array
    {
        $rows = $this->eagerMorphMany($rows, $name, $cfg);

        foreach ($rows as $i => $row) {
            $list = $row[$name] ?? [];
            // 只看第一条；空数组时为 null，便于调用方直接判空
            $rows[$i][$name] = is_array($list) && $list !== [] ? reset($list) : null;
        }

        return $rows;
    }

    /**
     * 清空待预加载队列
     *
     * @return void
     */
    private function resetPendingWith(): void
    {
        $this->pendingWith = [];
    }
}
