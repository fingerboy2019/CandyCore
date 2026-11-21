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
use ZipArchive;
use \Console\{Input,Output};
use \Console\Input\{Argument,Option};
use \Console\Command\Build\Pharbuild;

class Bin extends Pharbuild
{
	protected $type = "build:bin";
	
	/**
     * @return void
     */
    protected function configure()
    {
		parent::configure();
        $this->setName('build:bin')
			->addArgument('version', Argument::OPTIONAL, 'PHP version')
			->setDescription('build bin.');
    }
	
	/**
     * @param Input $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output)
    {
		if($this->checkEnv($output)){
			$output->writeln('Phar packing...');
			
			$version = $input->getArgument('version');
			if (!$version) {
				$version = (float)PHP_VERSION;
			}
			$version = $version >= 8.4 ? $version : 8.4;
			
			$config = loadConfig('phar');
			$supportZip = class_exists(ZipArchive::class);
			$microZipFileName = $supportZip ? "php$version.micro.sfx.zip" : "php$version.micro.sfx";
			$pharFileName = $config['phar_filename'] ?? 'candy.phar';
			$binFileName = $config['bin_filename'] ?? 'candy.bin';
			$buildDir = C('CANDYROOT') . 'Runtime/Build';
			$customIni = $config['custom_ini'] ?? '';
			
			$binFile = "$buildDir/$binFileName";
			$pharFile = "$buildDir/$pharFileName";
			$zipFile = "$buildDir/$microZipFileName";
			$sfxFile = "$buildDir/php$version.micro.sfx";
			$customIniHeaderFile = "$buildDir/custominiheader.bin";
			
			// 打包
			$command = new Pharbuild();
			//$command->execute($input, $output);
			
			// 下载 micro.sfx.zip
			if (!is_file($sfxFile) && !is_file($zipFile)) {
				$output->writeln("\r\nDownloading PHP$version ...");
				$location = 'https://download.workerman.net/php/' . $microZipFileName;
				$status = \Candy\Extend\Network\Http::down($location, $zipFile);
				if($status === false){
					$output->writeln("Fail donwload PHP$version ...");
					return false;
				}
			}

			// 解压
			if (!is_file($sfxFile) && $supportZip) {
				$zip = new ZipArchive;
				$zip->open($zipFile, ZipArchive::CHECKCONS);
				$zip->extractTo($buildDir);
			}

			// 生成二进制文件
			file_put_contents($binFile, file_get_contents($sfxFile));
			// 自定义INI
			if (!empty($customIni)) {
				if (file_exists($customIniHeaderFile)) {
					unlink($customIniHeaderFile);
				}
				$f = fopen($customIniHeaderFile, 'wb');
				fwrite($f, "\xfd\xf6\x69\xe6");
				fwrite($f, pack('N', strlen($customIni)));
				fwrite($f, $customIni);
				fclose($f);
				file_put_contents($binFile, file_get_contents($customIniHeaderFile),FILE_APPEND);
				unlink($customIniHeaderFile);
			}
			file_put_contents($binFile, file_get_contents($pharFile), FILE_APPEND);

			// 添加执行权限
			chmod($binFile, 0755);

			$output->writeln("\r\nSaved $binFileName to $binFile\r\nBuild Success!\r\n");
		}
	}
}
