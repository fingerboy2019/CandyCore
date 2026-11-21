<?php
/**
 * 种子：创建填充文件
 *
 * 在 Data/seeders 下生成填充文件骨架。
 *
 * 用法：php candy seed:make users_seed
 *
 * @package Console\Command\Seed
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 21:00:00
 */

declare(strict_types=1);
namespace Console\Command\Seed;

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
        $this->setName('seed:make')
            ->addArgument('name', Argument::REQUIRED, 'Seeder name, e.g. users_seed')
            ->setDescription('Create a new seeder file');
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
            $this->output->writeln('<error>Seeder name is required.</error>');

            return 1;
        }

        try {
            $path = (new \Candy\Extend\Seeder\Manager())->make($name);
            $this->output->writeln('<info>Created Seeder: ' . basename($path) . '</info>');

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
