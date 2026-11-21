<?php
/***
 * Candy框架 Version类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare (strict_types = 1);
namespace Console\Command;

use \Console\{Command,Input,Output};

class Version extends Command
{
    protected function configure(): void
    {
        // 指令配置
        $this->setName('version')
            ->setDescription('show Candy framework version.');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln(sprintf('version <comment>%s</comment>', CANDY_VERSION));
    }

}
