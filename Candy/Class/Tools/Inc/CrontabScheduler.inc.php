<?php
/***
 * 计划任务调度器配置（框架默认值）
 *
 * 处理器白名单、GlobalData 键前缀、轮询参数、完成回调。
 * 由 /sys/config 维护；改完要重启「计划任务调度」服务才生效。
 *
 * @package Workerman\Crontab
 */
return [
    'prefix' => 'candy_crontab_',

    'engine' => [
        'queryInterval'       => 0,
        'queryIntervalMax'    => 5,
        'queryEmptyCountMax'  => 2,
        'bootstrapDelay'      => 5,
        'gdReadyMaxAttempts'  => 30,
        'versionWatchInterval' => 2,
    ],

    // 时间戳队列（推送 / HTTP 回调）的 tick 驱动
    // 由「计划任务调度」服务进程在启动后挂载；File 兜底驱动建议把 tick_interval 调到 5~10 秒以降低 IO
    'queue' => [
        'auto_tick'     => true,
        'tick_interval' => 1,
    ],

    // 执行记录写入 GlobalData：run_summary 各任务上次摘要；run_logs 明细保留天数
    'record' => [
        'retention_days' => 7,
    ],

    // HTTP 回调任务限制（SSRF / 超时 / 可选主机白名单）
    'http_callback' => [
        'block_private_ip' => true,
        'default_timeout'  => 10,
        'max_timeout'      => 30,
        'allowed_hosts'    => [],
    ],

    // 执行命令任务（shell_callback）—— 默认关闭
    // ⚠️ 开启后，能访问 /sys/crontab 并持有令牌的人即可在服务器上执行命令。
    //    建议同时配置 allow_prefix 前缀白名单（例如只允许 'php ' 与 '/usr/bin/'）。
    'shell' => [
        'enable'          => false,
        'allow_prefix'    => [],
        'default_timeout' => 60,
        'max_timeout'     => 300,
    ],

    // 任务执行完成回调（单次任务自动暂停等）；项目没实现该类时会被 is_callable 静默跳过
    'on_job_finished' => null,

    // 处理器白名单由 Config 加载时自动扫描 Jobs 目录（勿在此直接调用类）
];
