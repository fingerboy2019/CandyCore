<?php
/**
 * fork 选项
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ForkOptions
{
    /**
     * @param bool        $force      允许在 FPM 等不安全上下文里 fork（默认 false）
     * @param bool        $graceful   子进程用 exit(0) 收尾；默认 false 走 SIGKILL 自身，
     *                                 避免触发 shutdown 函数把父进程正在发的响应写坏
     * @param string|null $resultFile 子进程结果落盘路径，null = 自动生成临时文件
     * @param bool        $wait       父进程是否阻塞等待子进程结束（默认 false = 发起即返回）
     * @param int         $waitMs     wait 为真时最多等多少毫秒，0 = 一直等
     */
    public function __construct(
        public bool $force = false,
        public bool $graceful = false,
        public ?string $resultFile = null,
        public bool $wait = false,
        public int $waitMs = 0,
    ) {
    }
}
