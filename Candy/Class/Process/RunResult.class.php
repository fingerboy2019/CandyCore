<?php
/**
 * 同步执行结果
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class RunResult
{
    /**
     * @param string   $command   命令可读串
     * @param int      $exitCode  退出码（-1 表示无法取得）
     * @param string   $stdout    标准输出
     * @param string   $stderr    标准错误
     * @param bool     $timedOut  是否因超时被终止
     * @param float    $duration  耗时（秒）
     * @param int|null $signal    终止信号（可能为 null）
     */
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly float $duration,
        public readonly ?int $signal = null,
    ) {
    }

    /**
     * 是否成功（退出码 0 且未超时）
     *
     * @return bool
     */
    public function ok(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }

    /**
     * 合并输出（stdout + stderr），用于写日志或给人看
     *
     * @return string
     */
    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }

    /**
     * 末 N 行输出（默认 40 行），用于错误摘要，避免把整坨日志塞进报错信息
     *
     * @param int $lines 行数
     * @return string
     */
    public function tail(int $lines = 40): string
    {
        $text = trim($this->output());
        if ($text === '') {
            return '';
        }
        $rows = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if (count($rows) <= $lines) {
            return $text;
        }
        return implode(PHP_EOL, array_slice($rows, -$lines));
    }

    /**
     * 转数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'command'  => $this->command,
            'exitCode' => $this->exitCode,
            'stdout'   => $this->stdout,
            'stderr'   => $this->stderr,
            'timedOut' => $this->timedOut,
            'duration' => $this->duration,
            'signal'   => $this->signal,
        ];
    }
}
