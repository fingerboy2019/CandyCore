<?php

namespace PHPSocketIO;

/**
 * 调试工具类
 * 
 * 该类提供了一个简单的调试功能，可以根据全局 $debug 变量控制是否输出调试信息。
 * 
 * @package PHPSocketIO
 */
class Debug
{
    /**
     * 输出调试信息
     * 
     * 如果全局 $debug 变量为 true，则输出变量的详细信息。
     * 
     * @param mixed $var 要输出的变量
     * @return void
     */
    public static function debug($var)
    {
        global $debug;
        if ($debug) {
            echo var_export($var, true) . "\n";
        }
    }
}
