<?php
/**
 * Candy框架 核心应用类（门面）
 *
 * @package Candy\Core
 */
declare(strict_types=1);
namespace Candy\Core;

use Candy\Core\Trait\App\AppDispatcher;
use Candy\Core\Trait\App\AppEnvironment;
use Candy\Core\Trait\App\RuntimeClassLoader;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class App
{
	/**
	 *   construct
	 * @return mixed
	 */
	public function __construct() {}

	/**
	 * Init
	 * @param mixed $worker
	 */
	public static function Init($worker): void {}

	/**
	 * Ws Run
	 * @param mixed $client_id
	 * @param mixed $message
	 */
	public static function wsRun(string $client_id, string $message): void
	{
		Bootstrap::wsRun($client_id, $message);
	}

	/**
	 * Run
	 * @param mixed $docType
	 */
	public static function run(bool $docType = false): void
	{
		Bootstrap::run($docType);
	}

	/**
	 * Loading
	 */
	public static function loading(): void
	{
		AppEnvironment::loading();
	}

	/**
	 * Dispatch
	 */
	public static function dispatch(): void
	{
		AppDispatcher::dispatch();
	}

	/**
	 * Common
	 * @param mixed $className
	 * @param mixed $app
	 * @param mixed $isAddon
	 * @return bool
	 */
	public static function common(string $className, string $app = '', ?bool $isAddon = null): bool
	{
		return RuntimeClassLoader::common($className, $app, $isAddon);
	}

	/**
	 * Model
	 * @param mixed $className
	 * @param mixed $app
	 * @return bool
	 */
	public static function model(string $className, string $app = ''): bool
	{
		return RuntimeClassLoader::model($className, $app);
	}

	/**
	 * Service
	 * @param mixed $className
	 * @param mixed $app
	 * @return bool
	 */
	public static function service(string $className, string $app = ''): bool
	{
		return RuntimeClassLoader::service($className, $app);
	}

	/**
	 * 加载并编译验证器类
	 *
	 * @param string $className 运行时类名（如 GroupValidate）
	 * @param string $app 应用名
	 * @return bool
	 * @static
	 */
	public static function validate(string $className, string $app = ''): bool
	{
		return RuntimeClassLoader::validate($className, $app);
	}

	/**
	 * 加载并编译响应 Schema 类
	 *
	 * @param string $className 运行时类名（如 GroupSchema）
	 * @param string $app 应用名
	 * @return bool
	 * @static
	 */
	public static function schema(string $className, string $app = ''): bool
	{
		return RuntimeClassLoader::schema($className, $app);
	}

	/**
	 * Controler
	 * @param mixed $className
	 * @param mixed $app
	 * @param mixed $isAddon
	 * @return bool
	 */
	public static function controler(string $className, string $app = '', ?bool $isAddon = null): bool
	{
		return RuntimeClassLoader::controler($className, $app, $isAddon);
	}

	/**
	 * Get Root Path
	 * @return string
	 */
	public static function getRootPath(): string
	{
		return C('CANDYROOT');
	}

	/**
	 * Get Base Path
	 * @return string
	 */
	public static function getBasePath(): string
	{
		return RuntimeClassLoader::getBasePath();
	}

	/**
	 * 解析应用层运行时 FQCN（租户覆写优先）
	 *
	 * @param string $type action|common|model|service|validate|schema
	 * @param string $app 应用名
	 * @param string $runtimeClass 运行时类名
	 * @return string
	 * @static
	 */
	public static function resolveFqcn(string $type, string $app, string $runtimeClass): string
	{
		return RuntimeClassLoader::resolveFqcn($type, $app, $runtimeClass);
	}
}
