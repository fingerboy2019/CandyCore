<?php
/**
 * 计划任务：执行到期的任务
 *
 * 一条系统 cron 即可驱动全部定时任务：
 *   * * * * * cd /path/to/site && php candy schedule:run >> /dev/null 2>&1
 *
 * 用法：php candy schedule:run [--force] [--at=YYYY-MM-DD HH:MM:SS]
 *
 * @package Console\Command\Schedule
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:20:00
 */

declare(strict_types=1);
namespace Console\Command\Schedule;

use \Console\{Command, Input, Output};
use \Console\Input\Option;

class Run extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('schedule:run')
            ->addOption('force', 'f', Option::VALUE_NONE, 'Run all tasks regardless of cron expression')
            ->addOption('at', null, Option::VALUE_OPTIONAL, 'Evaluate at this time (Y-m-d H:i:s), default now', null)
            ->setDescription('Run due scheduled tasks');
    }

    /**
     * 执行指令
     *
     * @return int 全部成功 0，有失败 1
     */
    protected function handle(): int
    {
        $force = (bool) $this->input->getOption('force');
        $at = $this->input->getOption('at');
        $now = null;

        if ($at !== null && trim((string) $at) !== '') {
            $ts = strtotime((string) $at);
            if ($ts === false) {
                $this->output->writeln('<error>无效的 --at 时间格式，应为 Y-m-d H:i:s</error>');

                return 1;
            }
            $now = $ts;
        }

        try {
            $manager = new \Candy\Extend\Schedule\Manager();
            $results = $manager->runDue($now, $force);
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        if ($results === []) {
            $this->output->writeln('<comment>No task due.</comment>');

            return 0;
        }

        $failed = 0;
        foreach ($results as $row) {
            if ($row['ok']) {
                $this->output->writeln('<info>OK   ' . $row['name'] . ' (' . $row['ms'] . 'ms)</info>');
            } else {
                $failed++;
                $this->output->writeln('<error>FAIL ' . $row['name'] . ': ' . $row['message'] . '</error>');
            }
        }

        $this->output->writeln('<info>Done: ' . (count($results) - $failed) . ' ok, ' . $failed . ' failed.</info>');

        return $failed > 0 ? 1 : 0;
    }
}
