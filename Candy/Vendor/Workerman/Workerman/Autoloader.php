<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman;

/**
 * Autoload.
 */
class Autoloader
{
    /**
     * Autoload root path.
     *
     * @var string
     */
    protected static $_autoloadRootPath = '';

    /**
     * Set autoload root path.
     *
     * @param string $root_path
     * @return void
     */
    public static function setRootPath($root_path)
    {
        self::$_autoloadRootPath = $root_path;
    }

    /**
     * Load files by namespace.
     *
     * @param string $name
     * @return boolean
     */
    public static function loadByNamespace($name)
    {
        static $_loaded = [];
        if (isset($_loaded[$name])) return $_loaded[$name];
        $_loaded[$name] = false;
        
        $class_path = \str_replace('\\', \DIRECTORY_SEPARATOR, $name);
		//candy 修改框架引入机制  start
		$class_file_class = '';	//增
        if (\strpos($name, 'Workerman\\') === 0) {
            $class_file = __DIR__ . \substr($class_path, \strlen('Workerman')) . '.php';
        } else {
            if (self::$_autoloadRootPath) {
                $class_file = self::$_autoloadRootPath . \DIRECTORY_SEPARATOR . $class_path . '.php';
				$class_file_class = self::$_autoloadRootPath . \DIRECTORY_SEPARATOR . $class_path . '.class.php';  //增
            }
            if (empty($class_file) || !\is_file($class_file)) {
                //原 $class_file = __DIR__ . \DIRECTORY_SEPARATOR . '..' . \DIRECTORY_SEPARATOR . "$class_path.php";
				$class_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . "$class_path.php";	
            }
        }

        if (\is_file($class_file)) {
            require_once($class_file);
			$_loaded[$name] = true;
            
			//注销 最后验证
			//if (\class_exists($name, false)) {
            //    return true;
            //}
        }
		
		if (!empty($class_file_class) && \is_file($class_file_class)) {
            require_once($class_file_class);
			$_loaded[$name] = true;
        }
		
		//candy  兼容原生类 比如 Thread
		if (\class_exists($name, false)) {
            $_loaded[$name] = true;
        }
		
        return $_loaded[$name];
    }
}

\spl_autoload_register('\Workerman\Autoloader::loadByNamespace');