<?php
/***
 * Candy框架 workerman Server类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare (strict_types = 1);
namespace Console\Command;

use \Workerman\Events\Fiber;
use \Console\{Command,Input,Output};
use \Console\Input\{Option,Argument};

class Composer extends Command
{
	public function configure(): void
    {
		$this->setName('Composer')
			 ->addArgument('command', Argument::REQUIRED, "The command for Composer.")
             ->addArgument('name', Argument::OPTIONAL, "The name to install or update for Composer.")
			 ->setDescription('Composer for Candy.');
	}
	// FIX-COMPOSER-2026-11-13 起：本方法只借 composer 下载包，类加载仍归框架 Autoload。
	// 能力边界：只支持「纯 PSR-4 + 无依赖 + 无版本约束」的包，详见类注释。
	public function execute(Input $input, Output $output)
    {
		$name = $input->getArgument('name');
		$command = $input->getArgument('command');

		// 包名白名单：只接受 vendor/package，挡掉命令注入与非法路径
		if(in_array($command, ['require', 'remove'], true)){
			if(empty($name)){
				$output->writeln('<error>The Name is not given.</error>');
				return;
			}
			if(!preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $name)){
				$output->writeln('<error>'. $name .' 不是合法包名，请用 vendor/package（不支持版本约束）。</error>');
				return;
			}
		}

		switch($command){
			case 'require': //添加依赖
			case 'remove':  //移除依赖
				$installPath = C('SOURCEPATH') . 'Vendor/' . $name;

				if($command == 'require'){
					if(is_dir($installPath)){
						$output->writeln('<error>'. $name .'已经安装,请删除后重新安装或升级.</error>');
						return;
					}

					// 临时工作目录：composer 的 composer.json / 包目录都落在这里
					$tmpPath = C('RUNTIME') . 'Composer/';
					\Candy\Extend\Dir::create($tmpPath);

					$cwd = getcwd();
					if(!@chdir($tmpPath)){
						$output->writeln('<error>无法进入临时目录: '. $tmpPath .'</error>');
						return;
					}

					$oldVendorDir = getenv('COMPOSER_VENDOR_DIR');
					putenv('COMPOSER_VENDOR_DIR=' . rtrim($tmpPath, '/'));

					$lines = [];
					$code  = 0;
					exec('composer require ' . escapeshellarg($name) . ' --no-interaction 2>&1', $lines, $code);

					if($oldVendorDir === false){
						putenv('COMPOSER_VENDOR_DIR');
					}else{
						putenv('COMPOSER_VENDOR_DIR=' . $oldVendorDir);
					}
					@chdir($cwd);

					// 清理临时 composer.json / composer.lock（存在才删，避免 E_WARNING）
					foreach(['composer.json', 'composer.lock'] as $lockFile){
						if(is_file($tmpPath . $lockFile)){
							@unlink($tmpPath . $lockFile);
						}
					}

					// 真失败就不许报成功
					if($code !== 0 || !is_dir($tmpPath . $name)){
						$output->writeln('<error>composer require '. $name .' 失败（退出码 '. $code .'）:</error>');
						foreach(array_slice($lines, -20) as $line){
							$output->writeln(str_replace(['<', '>'], ['&lt;', '&gt;'], (string)$line));
						}
						$output->writeln('<error>提示: 本命令只支持无依赖的纯 PSR-4 包。</error>');
						\Candy\Extend\Dir::delDir($tmpPath);
						return;
					}

					// 只复制主包目录（依赖包不会被复制，见类注释的能力边界）
					\Candy\Extend\Dir::create(dirname($installPath));
					\Candy\Extend\Dir::copyDir($tmpPath . $name, $installPath);

					//解析composer.json
					$composerJson = json(file_get_contents($installPath . '/composer.json'), 'de');
					$psr4 = $composerJson['autoload']['psr-4'] ?? [];
					if(empty($psr4)){
						$output->writeln('<error>'. $name .' 未声明 autoload.psr-4，本命令不支持（files/classmap/psr-0 需手工接入）。</error>');
						\Candy\Extend\Dir::delDir($installPath);
						\Candy\Extend\Dir::delDir($tmpPath);
						return;
					}

					// 生成PSR-4自动加载
					$autoloadClass = [];
					foreach($psr4 as $namespace => $path){
						$autoloadClass[strtr($namespace, '\\', '/')] = $name . '/' . rtrim($path, '/') . '/';
					}
					//特殊路径配置
					$specialclass = loadConfig('Specialclass');
					$specialclass = array_merge($specialclass, $autoloadClass);
					editInc('Specialclass', $specialclass);
					//删除临时目录
					\Candy\Extend\Dir::delDir($tmpPath);
					$output->writeln('<info>'. $name .'安装成功.</info>');
				}else{
					if(!is_dir($installPath)){
						$output->writeln('<error>'. $name .' 未安装，无需卸载.</error>');
						return;
					}

					//解析composer.json
					$composerJson = json(file_get_contents($installPath . '/composer.json'), 'de');

					//特殊路径配置
					$specialclass = loadConfig('Specialclass');

					// 只移除本包的映射，再精确删除本包目录（不动同厂商其它包）
					foreach($composerJson['autoload']['psr-4'] ?? [] as $namespace => $path){
						unset($specialclass[strtr($namespace, '\\', '/')]);
					}
					editInc('Specialclass', $specialclass, false);

					\Candy\Extend\Dir::delDir($installPath);

					// 厂商目录空了才一并删除
					[$vendorName] = explode('/', $name, 2);
					$vendorPath  = C('SOURCEPATH') . 'Vendor/' . $vendorName;
					$left = is_dir($vendorPath) ? @scandir($vendorPath) : false;
					if(is_array($left) && count($left) === 2){
						\Candy\Extend\Dir::delDir($vendorPath);
					}

					$output->writeln('<info>'. $name .'卸载成功.</info>');
				}
				break;
			default:
				//默认
				$output->writeln('<error>The Command is not allowed.</error>');
		}
	}
}
