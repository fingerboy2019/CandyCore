<?php
/**
 * 记录级授权门面
 *
 * 与框架既有的「节点级 RBAC」(`Node::checkAuth`) 互补：
 * - 节点级：能不能调用这个 Action（粗粒度，注解 auth: true + 角色权限表）；
 * - 记录级（本类）：能不能对「这一条数据」做这个操作（如"能不能改这张工单"）。
 *
 * 两种用法：
 * ① 闭包能力
 * ```php
 * Gate::define('manage-system', fn($user) => ($user['role'] ?? '') === 'admin');
 * Gate::allows('manage-system');
 * ```
 * ② Policy 类（推荐，职责更集中）
 * ```php
 * Gate::policy(TicketsModel::class, TicketsPolicy::class);
 * Gate::allows('update', $ticket);        // → TicketsPolicy::update($user, $ticket)
 * Gate::allows(['update', $ticket]);      // 同上，数组形式更接近 Laravel
 * ```
 * Policy 方法签名：`public function update(array $user, mixed $model): bool`
 * 方法不存在时按「拒绝」处理（fail-closed）。
 *
 * 全局前置 `Gate::before()`：返回非 null 即短路（如 Admin 直接放行）。
 *
 * @package Candy\Extend\Auth
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:10:00
 */

declare(strict_types=1);

namespace Candy\Extend\Auth;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Gate
{
    /** @var array<string, callable> 名称 => 闭包能力 */
    private static array $abilities = [];

    /** @var array<string, class-string> Model 类 => Policy 类 */
    private static array $policies = [];

    /** @var callable|null 全局前置：返回非 null 即短路 */
    private static $before = null;

    /** @var array<int, callable> 全局后置：可覆盖结果 */
    private static array $after = [];

    /** @var bool 是否已绑定框架默认前置（Admin 放行） */
    private static bool $defaultBeforeBound = false;

    /**
     * 注册一个闭包能力
     *
     * @param string   $ability  能力名（如 manage-system）
     * @param callable $callback 回调，参数为 (user, ...$args)，返回 bool
     * @return void
     */
    public static function define(string $ability, callable $callback): void
    {
        self::$abilities[$ability] = $callback;
    }

    /**
     * 为 Model 绑定 Policy 类
     *
     * @param string $modelClass  Model 全限定类名
     * @param string $policyClass Policy 全限定类名
     * @return void
     */
    public static function policy(string $modelClass, string $policyClass): void
    {
        self::$policies[ltrim($modelClass, '\\')] = ltrim($policyClass, '\\');
    }

    /**
     * 设置全局前置（返回非 null 即短路，常用于 Admin 放行）
     *
     * @param callable $callback 回调，签名 ($user, $ability, $arguments)：bool|null
     * @return void
     */
    public static function before(callable $callback): void
    {
        self::$before = $callback;
    }

    /**
     * 追加全局后置（可覆盖前置/策略的拒绝结果）
     *
     * @param callable $callback 回调，签名 ($user, $ability, $arguments, $result)：bool|null
     * @return void
     */
    public static function after(callable $callback): void
    {
        self::$after[] = $callback;
    }

    /**
     * 判定是否允许
     *
     * @param string|array<int, mixed> $ability   能力名，或 [能力名, ...参数]
     * @param mixed                    $arguments 数据参数（Model 实例 / ID / 任意值）
     * @param array<string, mixed>|null $user     用户数组；null 时自动取当前登录用户
     * @return bool
     */
    public static function allows(string|array $ability, mixed $arguments = null, ?array $user = null): bool
    {
        [$name, $args] = self::normalizeAbility($ability, $arguments);
        $user = $user ?? self::currentUser();

        self::bindDefaultBefore();

        // ① 全局前置
        if (self::$before !== null) {
            $result = (self::$before)($user, $name, $args);
            if ($result !== null) {
                return (bool) $result;
            }
        }

        // ② 闭包能力
        if (isset(self::$abilities[$name])) {
            $result = (bool) (self::$abilities[$name])($user, ...$args);
        } else {
            // ③ Policy
            $result = self::viaPolicy($name, $args, $user);
        }

        // ④ 全局后置（非 null 即覆盖）
        foreach (self::$after as $callback) {
            $override = $callback($user, $name, $args, $result);
            if ($override !== null) {
                $result = (bool) $override;
            }
        }

        return $result;
    }

    /**
     * 判定是否拒绝
     *
     * @param string|array<int, mixed> $ability   能力名或 [能力名, ...参数]
     * @param mixed                    $arguments 数据参数
     * @param array<string, mixed>|null $user     用户；null 取当前登录用户
     * @return bool
     */
    public static function denies(string|array $ability, mixed $arguments = null, ?array $user = null): bool
    {
        return !self::allows($ability, $arguments, $user);
    }

    /**
     * 判定并中断：不允许时抛出 403
     *
     * API 模式（.json）下输出平铺 {"code":403,"message":"没有权限"} 并 stop；
     * 页面模式下返回 false 由调用方决定如何处理。
     *
     * @param string|array<int, mixed> $ability   能力名或 [能力名, ...参数]
     * @param mixed                    $arguments 数据参数
     * @param array<string, mixed>|null $user     用户
     * @param string                   $message   拒绝提示
     * @return bool 允许时返回 true
     */
    public static function authorize(
        string|array $ability,
        mixed $arguments = null,
        ?array $user = null,
        string $message = '没有权限'
    ): bool {
        if (self::allows($ability, $arguments, $user)) {
            return true;
        }

        self::denyMessage($message);

        // 抛异常而非直接输出：Gate 是静态门面，拿不到控制器上下文，
        // 硬写响应会绕过框架的 .json 包装链。由调用方决定怎么呈现：
        //   try { Gate::authorize(...); } catch (GateDenied $e) {
        //       $this->assign('code', 403); $this->assign('message', $e->getMessage()); $this->display(); return;
        //   }
        throw new GateDenied($message, 403);
    }

    /**
     * 是否有任何能力被注册
     *
     * @return bool
     */
    public static function hasAny(): bool
    {
        return self::$abilities !== [] || self::$policies !== [];
    }

    /**
     * 清空注册（测试/热重载用）
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$abilities = [];
        self::$policies = [];
        self::$before = null;
        self::$after = [];
        self::$defaultBeforeBound = false;
    }

    /**
     * 通过 Policy 判定
     *
     * @param string $name 能力名
     * @param array  $args 参数（第一个通常是 Model 实例）
     * @param array  $user 用户
     * @return bool
     */
    private static function viaPolicy(string $name, array $args, array $user): bool
    {
        if ($args === []) {
            return false;
        }

        $model = $args[0];
        $modelClass = is_object($model) ? ltrim(get_class($model), '\\') : '';
        if ($modelClass === '' || !isset(self::$policies[$modelClass])) {
            return false;
        }

        $policyClass = self::$policies[$modelClass];
        if (!class_exists($policyClass)) {
            return false;
        }

        $method = str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : $name;
        if (!method_exists($policyClass, $method)) {
            // fail-closed：Policy 没实现该能力，视为拒绝
            return false;
        }

        $policy = class_exists('\Candy\Core\Container')
            ? \Candy\Core\Container::getInstance()->make($policyClass)
            : new $policyClass();

        return (bool) $policy->{$method}($user, ...$args);
    }

    /**
     * 解析能力参数为 [能力名, 参数数组]
     *
     * @param string|array<int, mixed> $ability   能力名或 [能力名, ...参数]
     * @param mixed                    $arguments 单独传入的参数
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function normalizeAbility(string|array $ability, mixed $arguments): array
    {
        if (is_array($ability)) {
            $name = (string) array_shift($ability);
            $args = array_values($ability);
            if ($arguments !== null) {
                array_unshift($args, $arguments);
            }

            return [$name, $args];
        }

        return [$ability, $arguments === null ? [] : [$arguments]];
    }

    /**
     * 取当前登录用户
     *
     * 优先用项目自定义的 currentUser()，其次 session('homeUser')，最后空数组。
     *
     * @return array<string, mixed>
     */
    private static function currentUser(): array
    {
        if (function_exists('gateCurrentUser')) {
            $user = gateCurrentUser();
            if (is_array($user)) {
                return $user;
            }
        }

        $user = session('homeUser');
        if (is_array($user)) {
            return $user;
        }

        $admin = session('adminUser');

        return is_array($admin) ? $admin : [];
    }

    /**
     * 绑定框架默认前置：Admin 全放行
     *
     * 复用 Node 的角色判定语义（项目可覆盖 getUserRole()），但 Gate 无法调用
     * Node 的私有方法，所以这里直接读同一个覆盖钩子。
     *
     * @return void
     */
    private static function bindDefaultBefore(): void
    {
        if (self::$defaultBeforeBound || self::$before !== null) {
            return;
        }
        self::$defaultBeforeBound = true;

        self::$before = static function (array $user, string $ability, array $args): ?bool {
            $role = '';
            if (function_exists('getUserRole')) {
                $role = (string) getUserRole();
            } elseif (isset($user['role'])) {
                $role = (string) $user['role'];
            }

            return strcasecmp($role, 'Admin') === 0 ? true : null;
        };
    }

    /**
     * 输出拒绝提示（页面模式）
     *
     * @param string $message 提示文案
     * @return void
     */
    private static function denyMessage(string $message): void
    {
        if (class_exists('\Candy\Core\Debug') && method_exists('\Candy\Core\Debug', 'addMsg')) {
            \Candy\Core\Debug::addMsg('[Gate] ' . $message, 0);
        }
    }
}

/**
 * 授权拒绝异常
 *
 * 由 Gate::authorize() 抛出，message 可直接展示给用户。
 *
 * @package Candy\Extend\Auth
 */
class GateDenied extends \RuntimeException
{
}
