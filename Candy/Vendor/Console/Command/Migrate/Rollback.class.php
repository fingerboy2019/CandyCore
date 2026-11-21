<?php
/**
 * 迁移：回滚最近批次
 *
 * 回滚最近 N 个批次，批内按逆序执行 down()。
 *
 * 用法：php candy migrate:rollback [--step=N]
 *
 * @package Console\Command\Migrate
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 21:00:00
 */

declare(strict_types=1);
namespace Console\Command\Migrate;

use \Console\{Command, Input, Output};
use \Console\Input\Option;
class Rollback extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('migrate:rollback')
            ->addOption('step', null, Option::VALUE_OPTIONAL, 'Number of batches to rollback', 1)
            ->setDescription('Rollback the last database migration batch');
    }

    /**
     * 执行指令
     *
     * @return int 成功 0，失败 1
     */
    protected function handle(): int
    {
        $stepOpt = $this->input->getOption('step');
        $step = ($stepOpt === null || $stepOpt === '') ? 1 : (int) $stepOpt;
        if ($step < 1) {
            $this->output->writeln('<error>--step must be greater than 0</error>');

            return 1;
        }

        try {
            $rolled = (new \Candy\Extend\Migration\Manager())->rollback($step);
            if ($rolled === []) {
                $this->output->writeln('<comment>Nothing to rollback.</comment>');

                return 0;
            }
            foreach ($rolled as $name) {
                $this->output->writeln('<info>Rolled back: ' . $name . '</info>');
            }
            $this->output->writeln('<info>Rollback completed (' . count($rolled) . ').</info>');

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
