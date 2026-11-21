<?php
/***
 * Candy框架 App类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Command\Build;

use Phar;
use \Console\{Command,Input,Output};
use \Console\Input\Argument;

class Pharbuild extends Command
{
	protected $type = "build:phar";
	
	/**
     * @return void
     */
    protected function configure()
    {
		parent::configure();
        $this->setName('build:phar')
			->addArgument('pharName', Argument::OPTIONAL, "The name of the Phar")
			->setDescription('Can be easily packaged a project into phar files. Easy to distribute and use.');
    }
	
	/**
     * @param Input $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output)
    {
		if($this->checkEnv($output)){
			$pharName = ucfirst(trim($input->getArgument('pharName') ?? ''));
			
			//创建目录
			$buildDir = C('CANDYROOT') . 'Runtime/Build';
			if (!is_dir($buildDir)) {
				if (!mkdir($buildDir,0777,true)) {
					$output->writeln('<error>Failed to create phar file output directory. Please check the permission!</error>');
					return false;
				}
			}
			
			//包名
			empty($pharName) && $pharName = 'candy.phar';
			
			$phar_file = $buildDir . DIRECTORY_SEPARATOR . $pharName;
			if (file_exists($phar_file)) {
				unlink($phar_file);
			}
			
			$exclude_pattern = $config['exclude_pattern'] ?? '';
			
			//开始打包
			$phar = new Phar($phar_file,0,'candy');
			$phar->startBuffering();
			
			//签名验证
			$config = loadConfig('phar');
			$signature_algorithm = $config['signature_algorithm'] ?? Phar::MD5;
			if (!in_array($signature_algorithm,[Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512,Phar::OPENSSL])) {
				$output->writeln('<error>The signature algorithm must be one of Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512, or Phar::OPENSSL.</error>');
				return false;
			}
			if ($signature_algorithm === Phar::OPENSSL) {
				$private_key_file = $config['private_key_file'] ?? '';
				if (!file_exists($private_key_file)) {
					$output->writeln("<error>If the value of the signature algorithm is 'Phar::OPENSSL', you must set the private key file.</error>");
					return false;
				}
				$private = openssl_get_privatekey(file_get_contents($private_key_file));
				$pkey = '';
				openssl_pkey_export($private, $pkey);
				$phar->setSignatureAlgorithm($signature_algorithm, $pkey);
			} else {
				$phar->setSignatureAlgorithm($signature_algorithm);
			}

			
			$phar->buildFromDirectory(C('CANDYROOT'), $exclude_pattern);
			
			$exclude_files = $config['exclude_files'] ?? [];
			
			// 打包生成的phar和bin文件是面向生产环境的，所以以下这些命令没有任何意义，执行的话甚至会出错，需要排除在外。
			$exclude_command_files = [];
			$exclude_files = array_unique(array_merge($exclude_command_files,$exclude_files));
			foreach ($exclude_files as $file) {
				if($phar->offsetExists($file)){
					$phar->delete($file);
				}
			}

			$output->writeln('Files collect complete, begin add file to Phar.');
			
			$phar->setStub("#!/usr/bin/env php
<?php
define('IN_PHAR', true);
Phar::mapPhar('candy');
require 'phar://candy/candy';
__HALT_COMPILER();
");
			$phar->stopBuffering();
			unset($phar);
			$output->writeln('<info>Write requests to the Phar archive, save changes to disk.</info>');
		}
	}
	
	/**
     * @throws RuntimeException
     */
    public function checkEnv(Output $output): bool
    {
        if (!class_exists(Phar::class, false)) {
			$output->writeln("<error>The 'phar' extension is required for build phar package!</error>");
			return false;
        }

        if (ini_get('phar.readonly')) {
            $command = static::$type;
			$output->writeln("The 'phar.readonly' is 'On', build phar must setting it 'Off' or exec with 'php -d phar.readonly=0 candy $command'");
			return false;
        }
		
		return true;
    }
}
