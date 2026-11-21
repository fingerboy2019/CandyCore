<?php
/**
 * 进程信息
 *
 * alive() / find() 的返回值。
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ProcessInfo
{
    /**
     * @param int    $pid     进程号（0 表示未知，如 Windows 后台进程）
     * @param string $command 命令行
     * @param bool   $running 是否在运行
     */
    public function __construct(
        public readonly int $pid,
        public readonly string $command = '',
        public readonly bool $running = true,
    ) {
    }

    /**
     * 是否拿到了可信 pid
     *
     * @return bool
     */
    public function hasPid(): bool
    {
        return $this->pid > 0;
    }

    /**
     * 转数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pid'     => $this->pid,
            'command' => $this->command,
            'running' => $this->running,
        ];
    }
}
