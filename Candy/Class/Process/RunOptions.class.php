<?php
/**
 * 同步执行选项
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class RunOptions
{
    /**
     * @param int         $timeout        超时秒数，0 = 不限制（默认 0，绝不设成 60）
     * @param string|null $cwd            工作目录，null = 继承当前进程
     * @param array|null  $env            环境变量，null = 继承；给定数组则「继承 + 覆盖」
     * @param string|null $input          stdin 内容（写完后自动关闭）
     * @param int         $maxBytes       单流输出上限（字节），超出后停止累积
     * @param float       $graceSeconds   超时后 SIGTERM → SIGKILL 之间的等待秒数
     * @param bool        $throwOnTimeout 超时是否抛异常；false 则返回带 timedOut=true 的结果
     */
    public function __construct(
        public int $timeout = 0,
        public ?string $cwd = null,
        public ?array $env = null,
        public ?string $input = null,
        public int $maxBytes = Runner::DEFAULT_MAX_OUTPUT,
        public float $graceSeconds = 1.0,
        public bool $throwOnTimeout = true,
    ) {
    }
}
