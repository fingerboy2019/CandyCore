<?php
/**
 * 队列：消费任务
 *
 * 驱动 Candy\Extend\Queue\Worker，FPM 下可用（不依赖 Workerman 事件循环）。
 *
 * 用法：
 *   php candy queue:work                        # 永久消费 default 队列
 *   php candy queue:work --queue=export,notify   # 按优先级消费多个队列
 *   php candy queue:work --stop-after=60         # 空闲 60 秒后退出（适合 cron 每分钟拉一次）
 *   php candy queue:work --once                   # 只处理一个任务后退出
 *
 * @package Console\Command\Queue
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:55:00
 */

declare(strict_types=1);
namespace Console\Command\Queue;

use \Console\{Command, Input, Output};
use \Console\Input\Option;

class Work extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('queue:work')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'Comma separated queue names (priority order)', 'default')
            ->addOption('stop-after', null, Option::VALUE_OPTIONAL, 'Exit after N seconds idle (0 = forever)', 0)
            ->addOption('once', null, Option::VALUE_NONE, 'Process at most one job then exit')
            ->setDescription('Consume queued jobs');
    }

    /**
     * 执行指令
     *
     * @return int
     */
    protected function handle(): int
    {
        $queueOpt = (string) ($this->input->getOption('queue') ?? 'default');
        $queues = array_values(array_filter(array_map('trim', explode(',', $queueOpt)), fn ($q) => $q !== ''));
        if ($queues === []) {
            $queues = ['default'];
        }

        $stopAfter = (int) ($this->input->getOption('stop-after') ?? 0);
        $once = (bool) $this->input->getOption('once');
        if ($once) {
            $stopAfter = 0;
        }

        $output = $this->output;
        $logger = static function (string $line, bool $error) use ($output): void {
            $output->writeln($error ? '<error>' . $line . '</error>' : '<info>' . $line . '</info>');
        };

        try {
            $worker = new \Candy\Extend\Queue\Worker($queues, $once ? 0 : $stopAfter);

            if ($once) {
                // 单次：取一个就退
                $n = $worker->runOnce($logger);
                $this->output->writeln('<comment>Processed: ' . $n . '</comment>');

                return 0;
            }

            $done = $worker->run($logger);
            $this->output->writeln('<info>Done total: ' . $done . '</info>');

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
