<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 注册一个计划任务
 *
 * 用法（项目 Source/Inc/Schedule.inc.php 内）：
 * ```php
 * return function () {
 *     schedule(fn() => (new \APP\Service\Home\Reports())->remind())
 *         ->dailyAt('08:00')
 *         ->name('daily-report-remind');
 * };
 * ```
 *
 * @param  callable|string $callback   闭包，或 'Class::method' / 'Class@method' / 'function'
 * @param  array           $parameters 传给任务的位置参数
 * @return \Candy\Extend\Schedule\Schedule
 */
function schedule(callable|string $callback, array $parameters = []): \Candy\Extend\Schedule\Schedule
{
    return \Candy\Extend\Schedule\Schedule::call($callback, $parameters);
}
