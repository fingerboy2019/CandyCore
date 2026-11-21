<?php
/**
 * 种子：执行数据填充
 *
 * 默认只跑未执行过的；--force 忽略记录强制重跑。
 *
 * 用法：php candy seed:run [name] [--force]
 *
 * @package Console\Command\Seed
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 21:00:00
 */

declare(strict_types=1);
namespace Console\Command\Seed;

use \Console\{Command, Input, Output};
use \Console\Input\{Argument, Option};
class Run extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('seed:run')
            ->addArgument('name', Argument::OPTIONAL, 'Seeder name; omit to run all pending', null)
            ->addOption('force', 'f', Option::VALUE_NONE, 'Re-run even if already recorded')
            ->setDescription('Run database seeders');
    }

    /**
     * 执行指令
     *
     * @return int 成功 0，失败 1
     */
    protected function handle(): int
    {
        $name = $this->input->getArgument('name');
        $name = ($name === null || trim((string) $name) === '') ? null : trim((string) $name);
        $force = (bool) $this->input->getOption('force');

        try {
            $ran = (new \Candy\Extend\Seeder\Manager())->run($name, $force);
            if ($ran === []) {
                $this->output->writeln('<comment>Nothing to seed.</comment>');

                return 0;
            }
            foreach ($ran as $seederName) {
                $this->output->writeln('<info>Seeded: ' . $seederName . '</info>');
            }
            $this->output->writeln('<info>Seeding completed (' . count($ran) . ').</info>');

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
