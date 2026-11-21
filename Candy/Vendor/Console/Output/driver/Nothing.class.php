<?php
/***
 * Candy框架 Nothing类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Output\driver;

use \Console\Output;

class Nothing
{

    public function __construct(Output $output)
    {
        // do nothing
    }

    public function write($messages, bool $newline = false, int $options = 0): void
    {
        // do nothing
    }

    public function renderException(\Throwable $e): void
    {
        // do nothing
    }
}
