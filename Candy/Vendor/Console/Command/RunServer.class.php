<?php
/***
 * Candy框架 RunServer类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare (strict_types = 1);
namespace Console\Command;

use \Console\{Command,Input,Output};
use \Console\Input\Option;

class RunServer extends Command
{
    public function configure(): void
    {
        $this->setName('run')
            ->addOption(
                'host',
                'H',
                Option::VALUE_OPTIONAL,
                'The host to server the application on',
                '0.0.0.0'
            )
            ->addOption(
                'port',
                'p',
                Option::VALUE_OPTIONAL,
                'The port to server the application on',
                8000
            )
            ->addOption(
                'root',
                'r',
                Option::VALUE_OPTIONAL,
                'The document root of the application',
                ''
            )
            ->setDescription('PHP Built-in Server for Candy.');
    }

    public function execute(Input $input, Output $output)
    {
        $host = $input->getOption('host');
        $port = $input->getOption('port');
        $root = $input->getOption('root');
        if (empty($root)) {
            $root = C('CANDYROOT');
        }

        $command = sprintf(
            'php -S %s:%d -t %s %s',
            $host,
            $port,
            \escapeshellarg($root),
            \escapeshellarg($root . 'index.php')
        );

        $output->writeln(sprintf('Candy Development server is started On <http://%s:%s/>', '0.0.0.0' == $host ? '127.0.0.1' : $host, $port));
        $output->writeln(sprintf('You can exit with <info>`CTRL-C`</info>'));
        $output->writeln(sprintf('Document root is: %s', $root));
        \passthru($command);
    }

}
