<?php
/**
 * 进程执行超时异常
 *
 * 只在「同步执行且设置了超时」时抛出。抛出前子进程已被 SIGTERM → SIGKILL 终止，
 * 调用方拿到异常后不必再自行清理进程。
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process\Exception;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class ProcessTimeoutException extends ProcessException
{
    /**
     * 设定的超时秒数
     *
     * @var int
     */
    private int $timeout = 0;

    /**
     * 已捕获的输出（超时时并非空，便于排查卡在哪一步）
     *
     * @var string
     */
    private string $partialOutput = '';

    /**
     * 设置超时秒数
     *
     * @param int $timeout 超时秒数
     * @return static
     */
    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * 取超时秒数
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * 设置超时那一刻已拿到的输出，排查时非常有用
     *
     * @param string $partialOutput 已捕获输出（stdout + stderr）
     * @return static
     */
    public function setPartialOutput(string $partialOutput): static
    {
        $this->partialOutput = $partialOutput;
        return $this;
    }

    /**
     * 取已捕获输出
     *
     * @return string
     */
    public function getPartialOutput(): string
    {
        return $this->partialOutput;
    }
}
