<?php
/***
 * 框架进程组配置（框架默认值）
 *
 * 这里只写「项目额外需要的 worker」和「对内置 worker 的覆盖」：
 *   · 同名键 → 覆盖内置项（例：只改 globaldata 的 init 地址）
 *   · 写 false → 关掉某个内置 worker
 *
 * 框架内置（不用在这里声明）：
 *   globaldata  Candy\Extend\Worker\GlobalData   共享内存，地址取 Api.inc → globalDataGateway
 *   scheduler   Workerman\Crontab\Server          计划任务秒级调度器
 *
 * 完整清单 = Candy\Extend\Worker\Registry::all()
 *
 * 本页由 /sys/config 维护；改完要重启「计划任务调度」服务才生效。
 *
 * @package Candy\Extend\Worker
 */

return [];
