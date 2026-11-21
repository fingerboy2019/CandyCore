<?php
/**
 * 后台执行结果
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SpawnResult
{
    /**
     * @param string      $command 命令可读串
     * @param int         $pid     进程号，0 = 当前平台拿不到（Windows）
     * @param string      $logPath 日志文件
     * @param string|null $marker  标记串
     */
    public function __construct(
        public readonly string $command,
        public readonly int $pid,
        public readonly string $logPath,
        public readonly ?string $marker = null,
    ) {
    }

    /**
     * 是否拿到了可信 pid（拿不到时停止/重启要用 find() 按命令行反查）
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
            'command' => $this->command,
            'pid'     => $this->pid,
            'logPath' => $this->logPath,
            'marker'  => $this->marker,
        ];
    }
}
