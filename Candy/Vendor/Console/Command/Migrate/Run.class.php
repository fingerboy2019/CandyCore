<?php
/**
 * 迁移：执行待运行迁移
 *
 * 按文件名顺序执行未运行的迁移，每条独立事务，失败整批回滚。
 *
 * 用法：php candy migrate:run [--step=N]
 *
 * @package Console\Command\Migrate
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 21:00:00
 */

declare(strict_types=1);
namespace Console\Command\Migrate;

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
        $this->setName('migrate:run')
            ->addOption('step', null, Option::VALUE_OPTIONAL, 'Max number of migrations to run', null)
            ->setDescription('Run pending database migrations');
    }

    /**
     * 执行指令
     *
     * @return int 成功 0，失败 1
     */
    protected function handle(): int
    {
        $stepOpt = $this->input->getOption('step');
        $step = ($stepOpt === null || $stepOpt === '') ? null : (int) $stepOpt;

        try {
            $ran = (new \Candy\Extend\Migration\Manager())->run($step);
            if ($ran === []) {
                $this->output->writeln('<comment>Nothing to migrate.</comment>');

                return 0;
            }
            foreach ($ran as $name) {
                $this->output->writeln('<info>Migrated: ' . $name . '</info>');
            }
            $this->output->writeln('<info>Migration completed (' . count($ran) . ').</info>');

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
