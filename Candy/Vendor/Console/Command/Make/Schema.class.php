<?php
/***
 * Candy 框架 make:schema
 *
 * 在 Application/{App}/Schemas/ 下生成响应 Schema 源码（短名）；
 * 运行时由 RuntimeClassLoader 编译为 {Name}Schema 写入 Runtime/Schemas/
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 19:28:53 $
 */

declare(strict_types=1);

namespace Console\Command\Make;

use Console\{Command, Input, Output};
use Console\Input\Argument;

class Schema extends Command
{
	protected $type = 'Schema';

	protected function configure(): void
	{
		parent::configure();
		$this->setName('make:schema')
			->addArgument('appName', Argument::OPTIONAL, 'The name of the app')
			->addArgument('tableName', Argument::OPTIONAL, 'The name of the table/model')
			->setDescription('Create a Schema class under Application/{App}/Schemas (compiled to Runtime).');
	}

	protected function execute(Input $input, Output $output)
	{
		$appName = ucfirst(trim($input->getArgument('appName') ?? ''));
		$tableName = ucfirst(trim($input->getArgument('tableName') ?? ''));

		if ($tableName === '') {
			$tableName = $appName;
			$appName = 'Home';
		}

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

		$output->writeln('<info>' . $this->type . ':' . $className . ' created successfully (runtime class: ' . $className . 'Schema).</info>');
		return true;
	}

	/**
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
 * {$className} 响应 Schema
 *
 * 业务说明：描述 API data 形状；运行时类名 {$className}Schema
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$date} \$
 *
 * @package APP\\Schema\\{$app}
 */

declare(strict_types=1);

namespace APP\\Schema\\{$app};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\\Extend\\Schema\\{ObjectSchema, ResponseDataType, Schema};

// 短名类不写 extends；编译时追加 Schema 并继承 \\Candy\\Extend\\Schema\\Base
class {$className}
{
	/**
	 * @return ResponseDataType
	 */
	public static function dataType(): ResponseDataType
	{
		return ResponseDataType::Item;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function openApiSchemas(): array
	{
		return ObjectSchema::make(self::openApiSchemaName())
			// ->prop('id', Schema::integer('主键'), true)
			->components();
	}
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
		return C('CANDYROOT') . 'Application/' . $appName . '/Schemas/' . $className . '.class.php';
	}
}
