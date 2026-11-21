<?php
/**
 * 计划任务定义器
 *
 * 让 FPM 模式也能像常驻模式那样声明定时任务：
 * 一条系统 cron 跑 `php candy schedule:run`，由本类判断「该不该跑」。
 *
 * cron 表达式解析复用 `Candy\Vendor\Workerman\Crontab\Parser`（支持五段/六段）。
 *
 * 定义处（项目 Source/Inc/Schedule.inc.php）：
 * ```php
 * return function (\Candy\Extend\Schedule\Schedule $schedule) {
 *     $schedule->call(fn() => (new \APP\Service\Home\Reports())->remind())
 *         ->dailyAt('08:00')
 *         ->name('daily-report-remind')
 *         ->description('每天 8 点提醒未交日报');
 * };
 * ```
 *
 * @package Candy\Extend\Schedule
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:15:00
 */

declare(strict_types=1);

namespace Candy\Extend\Schedule;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Schedule
{
    /** @var array<int, array<string, mixed>> 已注册任务 */
    private static array $tasks = [];

    /**
     * 注册一个可执行任务
     *
     * @param callable|string $callback 闭包，或 'Class::method' / 'Class@method' / 'function'
     * @param array<int, mixed> $parameters 传给任务的位置参数
     * @return self
     */
    public static function call(callable|string $callback, array $parameters = []): self
    {
        $task = [
            'callback'    => $callback,
            'parameters'  => $parameters,
            'expression'  => '* * * * *',
            'name'        => '',
            'description' => '',
            'overlap'     => 0,
            'withoutOverlapping' => false,
            'when'        => null,
            'enabled'     => true,
        ];
        self::$tasks[] = $task;

        return new self(count(self::$tasks) - 1);
    }

    /**
     * 清空全部任务
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$tasks = [];
    }

    /**
     * 获取全部任务
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$tasks;
    }

    /** @var int 当前操作的任务下标 */
    private int $index;

    /**
     * 构造（仅由 call() 内部使用）
     *
     * @param int $index 任务下标
     */
    public function __construct(int $index)
    {
        $this->index = $index;
    }

    /**
     * 设置 cron 表达式
     *
     * @param string $expression cron 表达式（五段或六段）
     * @return self
     */
    public function cron(string $expression): self
    {
        $parser = new \Workerman\Crontab\Parser();
        if (!$parser->isValid($expression)) {
            throw new \InvalidArgumentException('无效的 cron 表达式：' . $expression);
        }
        self::$tasks[$this->index]['expression'] = trim($expression);

        return $this;
    }

    /**
     * 设置任务名（用于去重锁、日志）
     *
     * @param string $name 任务名
     * @return self
     */
    public function name(string $name): self
    {
        self::$tasks[$this->index]['name'] = $name;

        return $this;
    }

    /**
     * 设置描述
     *
     * @param string $description 描述
     * @return self
     */
    public function description(string $description): self
    {
        self::$tasks[$this->index]['description'] = $description;

        return $this;
    }

    /**
     * 禁止重叠执行（上次未跑完则本次跳过）
     *
     * @param int $minutes 锁的过期分钟数，防止异常退出后永久锁死
     * @return self
     */
    public function withoutOverlapping(int $minutes = 1440): self
    {
        self::$tasks[$this->index]['withoutOverlapping'] = true;
        self::$tasks[$this->index]['overlap'] = max(1, $minutes);

        return $this;
    }

    /**
     * 条件执行
     *
     * @param callable $callback 返回 bool
     * @return self
     */
    public function when(callable $callback): self
    {
        self::$tasks[$this->index]['when'] = $callback;

        return $this;
    }

    /**
     * 启用/禁用
     *
     * @param bool $enabled 是否启用
     * @return self
     */
    public function enabled(bool $enabled = true): self
    {
        self::$tasks[$this->index]['enabled'] = $enabled;

        return $this;
    }

    /**
     * 每天指定时间
     *
     * @param string $time HH:MM
     * @return self
     */
    public function dailyAt(string $time): self
    {
        [$h, $m] = array_map('intval', explode(':', $time . ':0') + [0, 0]);

        return $this->cron(sprintf('%d %d * * *', $m, $h));
    }

    /**
     * 每天 0 点
     *
     * @return self
     */
    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * 每小时整点
     *
     * @return self
     */
    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    /**
     * 每周一 0 点
     *
     * @return self
     */
    public function weekly(): self
    {
        return $this->cron('0 0 * * 1');
    }

    /**
     * 工作日上午 9 点
     *
     * @return self
     */
    public function weekdaysAt(string $time = '09:00'): self
    {
        [$h, $m] = array_map('intval', explode(':', $time . ':0') + [0, 0]);

        return $this->cron(sprintf('%d %d * * 1-5', $m, $h));
    }

    /**
     * 每月 1 日 0 点
     *
     * @return self
     */
    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    /**
     * 每分钟
     *
     * @return self
     */
    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    /**
     * 每 N 分钟
     *
     * @param int $minutes 间隔分钟数
     * @return self
     */
    public function everyMinutes(int $minutes): self
    {
        return $this->cron('*/' . max(1, $minutes) . ' * * * *');
    }
}
