<?php
/***
 * Candy框架 Buffer类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Output\driver;

use \Console\Output;

class Buffer
{
    /**
     * @var string
     */
    private $buffer = '';

    public function __construct(Output $output)
    {
        // do nothing
    }

    public function fetch(): string
    {
        $content      = $this->buffer;
        $this->buffer = '';
        return $content;
    }

    public function write($messages, bool $newline = false, int $options = 0): void
    {
        $messages = (array) $messages;

        foreach ($messages as $message) {
            $this->buffer .= $message;
        }
        if ($newline) {
            $this->buffer .= "\n";
        }
    }

    public function renderException(\Throwable $e): void
    {
        // do nothing
    }

}
