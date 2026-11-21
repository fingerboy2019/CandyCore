<?php
/**
 * 迁移：查看状态
 *
 * 列出全部迁移文件与执行情况。
 *
 * 用法：php candy migrate:status
 *
 * @package Console\Command\Migrate
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 21:00:00
 */

declare(strict_types=1);
namespace Console\Command\Migrate;

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
        $this->setName('migrate:status')
            ->setDescription('Show migration status');
    }

    /**
     * 执行指令
     *
     * @return int
     */
    protected function handle(): int
    {
        try {
            $manager = new \Candy\Extend\Migration\Manager();
            $pending = $manager->getPendingMigrations();
            $all = [];

            $dir = $manager->getMigrationsPath();
            foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $file) {
                if (str_ends_with((string) $file, '.php')) {
                    $all[] = substr((string) $file, 0, -4);
                }
            }
            sort($all, SORT_STRING);

            if ($all === []) {
                $this->output->writeln('<comment>No migration files in ' . $dir . '</comment>');

                return 0;
            }

            $pendingMap = array_flip($pending);
            $this->output->writeln('<info>Migrations (' . count($all) . '):</info>');
            foreach ($all as $name) {
                $mark = isset($pendingMap[$name]) ? '<comment>pending</comment>' : '<info>ran</info>';
                $this->output->writeln('  ' . $mark . '  ' . $name);
            }
            $this->output->writeln('<comment>Pending: ' . count($pending) . '</comment>');

            return 0;
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}
