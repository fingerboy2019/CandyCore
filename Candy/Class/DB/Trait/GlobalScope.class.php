<?php
/**
 * 全局作用域
 *
 * 给某个 Model 的所有查询自动叠加条件（如"只要未删除的"），
 * 不用每个查询点都手写。Laravel globalScope 的等价能力。
 *
 * ```php
 * class Tickets extends BaseModel
 * {
 *     protected function bootGlobalScopes(): void
 *     {
 *         // 默认只看未删除的
 *         $this->addGlobalScope('notDeleted', fn($q) => $q->where(['deleted_at' => null]));
 *     }
 * }
 *
 * M('Tickets')->select();                       // 自动带 deleted_at IS NULL
 * M('Tickets')->withoutGlobalScope('notDeleted')->select();   // 本次豁免
 * M('Tickets')->withoutGlobalScopes()->select();              // 全部豁免
 * ```
 *
 * @package Candy\Extend\DB\Trait
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 23:10:00
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait GlobalScope
{
    /** @var array<string, callable> 已注册的全局作用域：名称 => 回调 */
    private array $globalScopes = [];

    /** @var array<int, string> 本次查询要豁免的作用域名 */
    private array $skippedGlobalScopes = [];

    /** @var bool 本次查询是否豁免全部作用域 */
    private bool $skipAllGlobalScopes = false;

    /** @var bool 本轮查询是否已注入过（防止链式多次调用重复注入） */
    private bool $globalScopesApplied = false;

    /**
     * 注册全局作用域（子类在 bootGlobalScopes() 里调用）
     *
     * @param string   $name   作用域名（用于单独豁免）
     * @param callable $scope  回调，参数为当前 Model 实例
     * @return static
     */
    public function addGlobalScope(string $name, callable $scope): static
    {
        if ($name !== '') {
            $this->globalScopes[$name] = $scope;
        }

        return $this;
    }

    /**
     * 本次查询豁免指定作用域
     *
     * @param string|array<int, string> $names 作用域名
     * @return static
     */
    public function withoutGlobalScope(string|array $names): static
    {
        foreach ((array) $names as $name) {
            $name = (string) $name;
            if ($name !== '' && !in_array($name, $this->skippedGlobalScopes, true)) {
                $this->skippedGlobalScopes[] = $name;
            }
        }

        return $this;
    }

    /**
     * 本次查询豁免全部作用域
     *
     * @return static
     */
    public function withoutGlobalScopes(): static
    {
        $this->skipAllGlobalScopes = true;

        return $this;
    }

    /**
     * 子类可覆写：在这里注册全局作用域
     *
     * @return void
     */
    protected function bootGlobalScopes(): void
    {
    }

    /**
     * 把生效的全局作用域注入当前查询（幂等）
     *
     * 由 BaseModel::__call 在 select/find/total/count 前调用。
     *
     * @return void
     */
    protected function applyGlobalScopes(): void
    {
        if ($this->globalScopesApplied) {
            return;
        }
        $this->globalScopesApplied = true;

        // 每次注入前都调一次 bootGlobalScopes：
        // 子类实现是"按名字覆盖注册"（addGlobalScope 用名字做 key），天然幂等
        $this->bootGlobalScopes();

        if ($this->globalScopes === []) {
            return;
        }

        foreach ($this->globalScopes as $name => $scope) {
            if ($this->skipAllGlobalScopes || in_array($name, $this->skippedGlobalScopes, true)) {
                continue;
            }
            try {
                $scope($this);
            } catch (\Throwable) {
                // 单个作用域异常不影响主查询
            }
        }
    }

    /**
     * 重置作用域注入标记（每次新的查询链开始时调用）
     *
     * @return void
     */
    protected function resetGlobalScopeState(): void
    {
        $this->globalScopesApplied    = false;
        $this->skippedGlobalScopes    = [];
        $this->skipAllGlobalScopes    = false;
    }
}
