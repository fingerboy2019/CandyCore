<?php
/***
 * GlobalData 网关配置（框架默认值）
 *
 * 计划任务调度依赖 GlobalData 共享任务快照；这里的地址要与 Process.inc.php 中
 * globaldata 的 init 保持一致（默认值就是两者对齐的 127.0.0.1:2207）。
 *
 * @package Candy\Extend\Worker
 */
return [
    'globalDataGateway' => '127.0.0.1:2207',
];
