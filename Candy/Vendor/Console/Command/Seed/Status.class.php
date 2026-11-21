<?php
/**
 * 种子：查看状态
 *
 * 列出全部填充文件与执行情况。
 *
 * 用法：php candy seed:status
 *
 * @package Console\Command\Seed
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 21:00:00
 */

declare(strict_types=1);
namespace Console\Command\Seed;

use \Console\{Command, Input, Output};

class Status extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('seed:status')
            ->setDescription('Show seeder status');
    }

    /**
     * 执行指令
     *
     * @return int
     */
    protected function handle(): int
    {
        try {
            $rows = (new \Candy\Extend\Seeder\Manager())->status();
            if ($rows === []) {
                $this->output->writeln('<comment>No seeder files found.</comment>');

                return 0;
            }

            $this->output->writeln('<info>Seeders (' . count($rows) . '):</info>');
            foreach ($rows as $row) {
                $mark = $row['ran'] ? '<info>ran</info>' : '<comment>pending</comment>';
                $batch = $row['batch'] === null ? '-' : (string) $row['batch'];
                $this->output->writeln('  ' . $mark . '  batch=' . $batch . '  ' . $row['seeder']);
            }

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
