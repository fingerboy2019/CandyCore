<?php
/**
 * 后台执行选项
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SpawnOptions
{
    /**
     * @param string|null $logPath 日志文件绝对路径；null = 自动落到 Runtime/Logs/process/ 下按天分文件
     * @param string|null $cwd     工作目录，null = 继承当前进程
     * @param array|null  $env     环境变量，null = 继承
     * @param bool        $append  日志是否追加（默认追加，便于排查）
     * @param string|null $marker  标记串，会写进日志首行并用于 find() 反查进程
     */
    public function __construct(
        public ?string $logPath = null,
        public ?string $cwd = null,
        public ?array $env = null,
        public bool $append = true,
        public ?string $marker = null,
    ) {
    }
}
