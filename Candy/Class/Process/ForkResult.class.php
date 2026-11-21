<?php
/**
 * fork 结果
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ForkResult
{
    /**
     * @param bool        $forked    是否真的 fork 了（false = 不可用或已降级）
     * @param int         $pid       子进程号；降级时为 0
     * @param bool        $degraded  是否发生了降级
     * @param string      $mode      实际走的形态：fork / sync / spawn
     * @param string      $reason    降级原因（degraded 为真时非空）
     * @param string|null $resultFile 子进程结果落盘路径
     * @param mixed       $return    降级为同步执行时，直接带回返回值
     */
    public function __construct(
        public readonly bool $forked,
        public readonly int $pid,
        public readonly bool $degraded,
        public readonly string $mode,
        public readonly string $reason = '',
        public readonly ?string $resultFile = null,
        public readonly mixed $return = null,
    ) {
    }

    /**
     * 转数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'forked'     => $this->forked,
            'pid'        => $this->pid,
            'degraded'   => $this->degraded,
            'mode'       => $this->mode,
            'reason'     => $this->reason,
            'resultFile' => $this->resultFile,
        ];
    }
}
