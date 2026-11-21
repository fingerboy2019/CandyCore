<?php
declare(strict_types=1);
/***
 * 控制台命令：服务依赖预检
 *
 * 用法：
 *   php candy doctor                # 文本报告
 *   php candy doctor --json         # JSON（给脚本/CI）
 *   php candy doctor process        # 只检查某个服务
 *
 * 退出码：0 = 无阻断项；1 = 有阻断项（可直接用于部署前检查）
 * 与 `/sys/service` 页面共用 Candy\Extend\Tools\Service\Doctor，检查项只维护一份。
 *
 * @package Console\Command
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 09:50:00
 */
namespace Console\Command;

use \Console\{Command, Input, Output};
use \Console\Input\{Option, Argument};

class Doctor extends Command
{
    /**
     * 配置指令
     */
    protected function configure(): void
    {
        $this->setName('Doctor')
            ->addArgument('service', Argument::OPTIONAL, '只检查某个服务（如 process / push）', '')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出')
            ->setDescription('服务依赖预检：PHP 版本 / 扩展 / 端口占用 / 目录权限 / pid 文件 / 进程表');
    }

    /**
     * 执行
     *
     * @param Input  $input  输入
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input, Output $output)
    {
        $service = (string) ($input->getArgument('service') ?? '');
        $json = (bool) $input->getOption('json');

        $report = \Candy\Extend\Tools\Service\Doctor::run($service === '' ? null : $service);

        if ($json) {
            echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
        } else {
            echo \Candy\Extend\Tools\Service\Doctor::toText($report);
        }

        return $report['ok'] ? 0 : 1;
    }
}
