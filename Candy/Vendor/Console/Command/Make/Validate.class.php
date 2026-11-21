<?php
/***
 * Candy 框架 make:validate
 *
 * 在 Application/{App}/Validates/ 下生成验证器源码（短名）；
 * 运行时由 RuntimeClassLoader 编译为 {Name}Validate 写入 Runtime/Validates/
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 18:55:00 $
 */

declare(strict_types=1);

namespace Console\Command\Make;

use Console\{Command, Input, Output};
use Console\Input\Argument;

class Validate extends Command
{
	protected $type = 'Validate';

	protected function configure(): void
	{
		parent::configure();
		$this->setName('make:validate')
			->addArgument('appName', Argument::OPTIONAL, 'The name of the app')
			->addArgument('tableName', Argument::OPTIONAL, 'The name of the table/model')
			->setDescription('Create a Validate class under Application/{App}/Validates (compiled to Runtime).');
	}

	protected function execute(Input $input, Output $output)
	{
		$appName = ucfirst(trim($input->getArgument('appName') ?? ''));
		$tableName = ucfirst(trim($input->getArgument('tableName') ?? ''));

		if ($tableName === '') {
			$tableName = $appName;
			$appName = 'Home';
		}

		// 与 Model 一致：源码短名，不含 Validate 后缀
		$className = $tableName;
		$pathname = $this->getPathName($appName, $className);

		if (is_file($pathname)) {
			$output->writeln('<error>' . $this->type . ':' . $className . ' already exists!</error>');
			return false;
		}

		if (!is_dir(dirname($pathname))) {
			mkdir(dirname($pathname), 0755, true);
		}

		file_put_contents($pathname, $this->buildClass($appName, $className));

		$output->writeln('<info>' . $this->type . ':' . $className . ' created successfully (runtime class: ' . $className . 'Validate).</info>');
		return true;
	}

	/**
	 * 生成验证类源码（短名；编译时追加 Validate 后缀）
	 *
	 * @param string $app 应用名
	 * @param string $className 短类名
	 * @return string
	 */
	protected function buildClass(string $app, string $className): string
	{
		$date = date('Y-m-d H:i:s');
		return <<<PHP
<?php
/***
 * {$className} 表单验证
 *
 * 业务说明：按 scene 校验写入字段；规则 DSL 使用点号嵌套
 * 运行时类名：{$className}Validate（由 Runtime 编译追加后缀）
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$date} \$
 *
 * @package APP\\Validate\\{$app}
 */

declare(strict_types=1);

namespace APP\\Validate\\{$app};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

// 短名类不写 extends；编译时追加 Validate 并继承 \\Candy\\Extend\\Validate\\Base
class {$className}
{
	/** @var array<string,string> 字段中文名 */
	protected array \$fields = [
		// 'name' => '名称',
	];

	/** @var array<string,string> 字段 => 规则串 */
	protected array \$rules = [
		// 'name' => 'require|string|max:64|unique:id',
	];

	/** @var array<string,list<string>|array<string,string>> */
	protected array \$scenes = [
		'add' => [],
		'mod' => [],
	];

	/** @var array<string,string> */
	protected array \$messages = [];
}

PHP;
	}

	/**
	 * @param string $appName 应用
	 * @param string $className 短类名
	 * @return string
	 */
	protected function getPathName(string $appName, string $className): string
	{
		return C('CANDYROOT') . 'Application/' . $appName . '/Validates/' . $className . '.class.php';
	}
}
