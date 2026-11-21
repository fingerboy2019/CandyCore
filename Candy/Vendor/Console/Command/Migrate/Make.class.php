<?php
/**
 * 迁移：创建迁移文件
 *
 * 在 Data/migrations 下生成带时间戳的迁移文件骨架。
 *
 * 用法：php candy migrate:make create_users_table
 *
 * @package Console\Command\Migrate
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 21:00:00
 */

declare(strict_types=1);
namespace Console\Command\Migrate;

use \Console\{Command, Input, Output};
use \Console\Input\Argument;
class Make extends Command
{
    /**
     * 指令配置
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('migrate:make')
            ->addArgument('name', Argument::REQUIRED, 'Migration name, e.g. create_users_table')
            ->setDescription('Create a new migration file');
    }

    /**
     * 执行指令
     *
     * @return int 成功 0，失败 1
     */
    protected function handle(): int
    {
        $name = trim((string) $this->input->getArgument('name'));
        if ($name === '') {
            $this->output->writeln('<error>Migration name is required.</error>');

            return 1;
        }

        try {
            $path = (new \Candy\Extend\Migration\Manager())->make($name);
            $this->output->writeln('<info>Created Migration: ' . basename($path) . '</info>');

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
