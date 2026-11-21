<?php
/**
 * 同进程调用选项
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class CallOptions
{
    /**
     * @param array<int, string>|null $whitelist    本次调用允许的命令名；null = 用 Caller 的全局白名单
     * @param int                     $maxDepth     嵌套深度上限，默认 3，防命令递归调自己
     * @param int                     $timeLimit    set_time_limit 秒数；0 = 不改动（默认）
     * @param bool                    $throwOnError 命令抛异常时是否向外抛；默认 false = 只记录在结果里
     */
    public function __construct(
        public ?array $whitelist = null,
        public int $maxDepth = 3,
        public int $timeLimit = 0,
        public bool $throwOnError = false,
    ) {
    }
}
