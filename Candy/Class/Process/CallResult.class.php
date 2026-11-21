<?php
/**
 * 同进程调用结果
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class CallResult
{
    /**
     * @param bool        $ok       是否成功（退出码 0）
     * @param string      $output   捕获到的输出
     * @param int         $exitCode 退出码
     * @param string|null $error    异常信息（有异常时非空）
     * @param float       $duration 耗时（秒）
     * @param bool        $stopped  命令是否主动调用了 stop()（属正常结束）
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $output,
        public readonly int $exitCode,
        public readonly ?string $error,
        public readonly float $duration,
        public readonly bool $stopped = false,
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
            'ok'       => $this->ok,
            'output'   => $this->output,
            'exitCode' => $this->exitCode,
            'error'    => $this->error,
            'duration' => $this->duration,
            'stopped'  => $this->stopped,
        ];
    }
}
