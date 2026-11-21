<?php
/**
 * 进程能力不可用异常
 *
 * 典型场景：
 *  - proc_open 被 disable_functions 禁用（宝塔、部分虚拟主机常见）
 *  - pcntl 扩展未安装，fork 形态不可用
 *  - 指定的命令不在 Caller 白名单内
 *
 * 抛出时 message 必须写清「缺什么、怎么开」，不要只抛一个「不支持」。
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process\Exception;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class ProcessUnavailableException extends ProcessException
{
    /**
     * 缺失的能力标识：sync / spawn / fork / call
     *
     * @var string
     */
    private string $mode = '';

    /**
     * 设置能力标识
     *
     * @param string $mode 能力标识
     * @return static
     */
    public function setMode(string $mode): static
    {
        $this->mode = $mode;
        return $this;
    }

    /**
     * 取能力标识
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }
}
