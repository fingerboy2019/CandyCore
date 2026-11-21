<?php
/**
 * 队列：查看状态
 *
 * 用法：php candy queue:status [--queue=default]
 *
 * @package Console\Command\Queue
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:55:00
 */

declare(strict_types=1);
namespace Console\Command\Queue;

use \Console\{Command, Input, Output};
use \Console\Input\Option;

class Status extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('queue:status')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'Queue name to inspect', 'default')
            ->setDescription('Show queue status');
    }

    /**
     * 执行指令
     *
     * @return int
     */
    protected function handle(): int
    {
        $queue = (string) ($this->input->getOption('queue') ?? 'default');

        try {
            $size = \Candy\Extend\Queue\Queue::size($queue);
            $failed = \Candy\Extend\Queue\Queue::failed(20);

            $this->output->writeln('<info>Queue "' . $queue . '": ' . $size . ' waiting</info>');

            // 延迟队列长度
            $redis = \Candy\Extend\Queue\Queue::redis();
            $delayed = (int) $redis->zCard('queue:delayed');
            $this->output->writeln('<info>Delayed: ' . $delayed . '</info>');

            if ($failed === []) {
                $this->output->writeln('<comment>No failed jobs.</comment>');

                return 0;
            }

            $this->output->writeln('<error>Failed jobs (' . count($failed) . '):</error>');
            foreach ($failed as $job) {
                $this->output->writeln('  ' . (string) ($job['id'] ?? '?')
                    . ' attempts=' . (int) ($job['attempts'] ?? 0)
                    . ' error=' . mb_substr((string) ($job['error'] ?? ''), 0, 80));
            }

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
