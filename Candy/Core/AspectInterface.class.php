<?php
/***
 * 切面接口
 *
 * 实现这个接口就能被 #[Aspect] 织入到方法前后。
 * 三个钩子：
 *   before($ctx)          方法执行前
 *   after($ctx, $ret)     方法正常返回后（能拿到返回值）
 *   onError($ctx, $e)     方法抛异常时（处理后请继续抛，或按需吞掉）
 *
 * $ctx 结构：
 *   [
 *     'class'  => 'APP\Service\Home\TicketsService',  // FQCN（不是短类名，实测确认）
 *     'method' => 'updateAssignees',  // 方法名
 *     'args'   => [...],              // 入参（值传递，改了不影响原方法）
 *     'start'  => 1234567.89,         // 微秒时间戳
 *     'target' => $this,             // 对象实例（静态方法不织入，正常非 null）
 *   ]
 *
 * ⚠️ target 只在本次调用内使用，不要存进静态变量/缓存，否则引用滞留。
 *
 * ⚠️ 切面里抛异常会中断原方法（before 阶段），谨慎使用。
 * ⚠️ 切面必须轻量 —— 它在每个被标注的方法上都跑。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-14 11:10:00 $
 *
 * @package Candy\Core
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface AspectInterface
{
    /**
     * 方法执行前
     *
     * @param array $ctx 上下文（class/method/args/start）
     * @return void
     */
    public function before(array $ctx): void;

    /**
     * 方法正常返回后
     *
     * @param array $ctx 上下文
     * @param mixed $ret 返回值（引用传递，可修改）
     * @return void
     */
    public function after(array $ctx, mixed &$ret): void;

    /**
     * 方法抛异常时
     *
     * @param array      $ctx 上下文
     * @param \Throwable $e   异常
     * @return void
     */
    public function onError(array $ctx, \Throwable $e): void;
}
