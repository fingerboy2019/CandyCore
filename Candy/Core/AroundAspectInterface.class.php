<?php
/***
 * 环绕切面接口
 *
 * 与 AspectInterface（before/after/onError 三段式）不同，环绕切面能**包住**方法体：
 * 自己决定何时调用 $next()、调用几次、要不要调用。
 *
 * 适用场景：
 *   事务（开事务 → $next() → 提交 / 回滚）
 *   缓存（命中就返回，不调 $next()）
 *   重试（失败再调 $next()）
 *   限流（不满足就不调 $next()）
 *
 * 用法：
 *   class TxAspect implements AroundAspectInterface
 *   {
 *       public function around(array $ctx, callable $next): mixed
 *       {
 *           return M('Xxx')->transaction(fn() => $next());
 *       }
 *   }
 *
 *   #[Aspect(TxAspect::class)]
 *   public function saveGroup(string $group, array $pairs): int { ... }
 *
 * 执行顺序（同一方法上有多个切面时）：
 *   ① 所有 before 切面先跑（priority 大的先）
 *   ② around 切面按 priority 嵌套，**大的在外层**，最内层是原方法
 *   ③ 所有 after 切面最后跑（能拿到返回值）
 *   ④ 任一步抛异常 → 所有 onError 切面跑，然后继续抛
 *
 * ⚠️ around 里调用 $next() 会执行原方法，**不要重复调用**（重试类切面除外）。
 * ⚠️ around 抛异常等同原方法抛异常，会走 onError 切面。
 * ⚠️ 宿主方法返回 void 时，$next() 返回 null，本方法也应当返回 void 语义。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-14 18:06:00 $
 *
 * @package Candy\Core
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface AroundAspectInterface
{
    /**
     * 环绕通知
     *
     * @param array    $ctx  上下文（class/method/args/start/key/target）
     * @param callable $next 调用它才会执行原方法；可调用多次（重试）
     * @return mixed 原方法的返回值（或切面自己决定的值）
     */
    public function around(array $ctx, callable $next): mixed;
}
