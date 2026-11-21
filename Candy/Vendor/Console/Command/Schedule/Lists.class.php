<?php
/**
 * 计划任务：列出全部任务与下次执行时间
 *
 * 用法：php candy schedule:list
 *
 * @package Console\Command\Schedule
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:20:00
 */

declare(strict_types=1);
namespace Console\Command\Schedule;

use \Console\{Command, Input, Output};

class Lists extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('schedule:list')
            ->setDescription('List all scheduled tasks');
    }

    /**
     * 执行指令
     *
     * @return int
     */
    protected function handle(): int
    {
        try {
            $manager = new \Candy\Extend\Schedule\Manager();
            $tasks = $manager->all();
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        if ($tasks === []) {
            $this->output->writeln('<comment>No scheduled tasks. Create Source/Inc/Schedule.inc.php to define them.</comment>');

            return 0;
        }

        $parser = new \Workerman\Crontab\Parser();
        $this->output->writeln('<info>Scheduled tasks (' . count($tasks) . '):</info>');

        foreach ($tasks as $index => $task) {
            $name = ($task['name'] ?? '') !== '' ? (string) $task['name'] : 'task#' . $index;
            $expr = (string) ($task['expression'] ?? '* * * * *');
            $enabled = ($task['enabled'] ?? true) ? '' : ' [disabled]';
            $desc = ($task['description'] ?? '') !== '' ? '  ' . $task['description'] : '';

            $next = $parser->nextRunTimestamp($expr);
            $nextText = $next > 0 ? date('Y-m-d H:i:s', $next) : 'n/a';

            $this->output->writeln(
                '  <comment>' . $expr . '</comment>  ' . $name . $enabled
                . '  next=' . $nextText . $desc
            );
        }

        return 0;
    }
}
