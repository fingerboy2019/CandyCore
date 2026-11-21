<?php
/**
 * 进程执行异常基类
 *
 * Runner / Forker / Caller 抛出的所有异常的父类，调用方可以用它一次性兜住。
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process\Exception;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class ProcessException extends \RuntimeException
{
    /**
     * 触发异常的命令（数组形式已拼成可读串）
     *
     * @var string
     */
    private string $command = '';

    /**
     * 附带命令后返回自身，便于链式构造
     *
     * @param string $command 命令可读串
     * @return static
     */
    public function setCommand(string $command): static
    {
        $this->command = $command;
        return $this;
    }

    /**
     * 取命令可读串
     *
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * 构造一条带命令上下文的异常
     *
     * @param string          $command  命令可读串
     * @param string          $message  错误原因
     * @param \Throwable|null $previous 前置异常
     * @return self
     */
    public static function wrap(string $command, string $message, ?\Throwable $previous = null): self
    {
        $text = $message;
        if ($command !== '') {
            $text .= ' [命令: ' . $command . ']';
        }
        return (new self($text, 0, $previous))->setCommand($command);
    }
}
