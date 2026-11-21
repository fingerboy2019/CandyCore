<?php
/***
 * Candy框架 清除缓存类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare(strict_types=1);
namespace Console\Command;

use \Console\{Command,Input,Output};
use \Console\Input\Option;

class Clear extends Command
{
    protected function configure(): void
    {
        // 指令配置
        $this->setName('clear')
            ->addOption('path', 'd', Option::VALUE_OPTIONAL, 'runtime\'s
			paths to clear', null)
        ->addOption('cache', 'c', Option::VALUE_OPTIONAL, 'clear runtime Cache directory', 'Cache')
            ->addOption('node', null, Option::VALUE_NONE, 'clear Node annotation cache only (Node::clearNodeCache)')
            ->addOption('log', 'l', Option::VALUE_NONE, 'clear log file')
            ->addOption('dir', 'r', Option::VALUE_NONE, 'clear empty dir')
            ->addOption('expire', 'e', Option::VALUE_NONE, 'clear cache file if cache has expired')
            ->setDescription('Clear runtime file.');
    }

    protected function execute(Input $input, Output $output)
    {
        $clearedNode = false;
        if ($input->getOption('node')) {
            if (class_exists(\Candy\Core\Node::class)) {
                \Candy\Core\Node::clearNodeCache();
            }
            $clearedNode = true;
            $output->writeln('<info>Node cache cleared</info>');
        }

        $runtimePath = C('CANDYROOT') . 'Runtime' . DIRECTORY_SEPARATOR;
        if ($input->getOption('log')) {
            $path = $runtimePath . 'Logs';
        } elseif ($input->getOption('cache')) {
            $path = $runtimePath . ($input->getOption('path') ?? $input->getOption('cache'));
        } elseif ($clearedNode) {
            $output->writeln('<info>Clear Successed</info>');
            return;
        } else {
            $output->writeln('<error>Please specify --cache, --log, or --node</error>');
            return;
        }

        $rmdir = $input->getOption('dir') ? true : false;
        // --expire 仅当 --cache 时生效
        $cache_expire = $input->getOption('expire') && $input->getOption('cache') ? true : false;
        $this->clear(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $rmdir, $cache_expire);

        if ($input->getOption('cache') && class_exists(\Candy\Core\Node::class)) {
            \Candy\Core\Node::clearNodeCache();
        }

        $output->writeln("<info>Clear Successed</info>");
    }

    protected function clear(string $path, bool $rmdir, bool $cache_expire): void
    {
        $files = is_dir($path) ? scandir($path) : [];
        foreach ($files as $file) {
            if ('.' != $file && '..' != $file && is_dir($path . $file)) {
                $this->clear($path . $file . DIRECTORY_SEPARATOR, $rmdir, $cache_expire);
                if ($rmdir) {
                    @rmdir($path . $file);
                }
            } elseif ('.gitignore' != $file && is_file($path . $file)) {
                if ($cache_expire) {
                    if ($this->cacheHasExpired($path . $file)) {
                        unlink($path . $file);
                    }
                } else {
                    unlink($path . $file);
                }
            }
        }
    }

    /**
     * 缓存文件是否已过期
     * @param $filename string 文件路径
     * @return bool
     */
    protected function cacheHasExpired($filename): bool
	{
        $content = file_get_contents($filename);
        $expire = (int) substr($content, 8, 12);
        return 0 != $expire && time() - $expire > filemtime($filename);
    }

}
