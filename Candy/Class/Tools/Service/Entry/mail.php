<?php
/***
 * 邮件服务（POP3 / IMAP）的启动入口
 *
 * 类库：Candy/Vendor/Workerman/Mail/（Server / Client / Protocols）
 * 配置：Candy/Class/Tools/Inc/Mail.inc.php（框架默认）← Source/Inc/Mail.inc.php（项目覆盖）
 *
 * 用法：
 *   php Candy/Class/Tools/Service/Entry/mail.php start
 *   php Candy/Class/Tools/Service/Entry/mail.php stop
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 15:40:00
 */

if (PHP_SAPI !== 'cli') {
    exit("这个脚本只能在命令行运行\n");
}

$root = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;

defined('CANDY')      OR define('CANDY', true);
defined('CANDYROOT')  OR define('CANDYROOT', $root);
defined('CANDYPATH')  OR define('CANDYPATH', $root);

require_once $root . 'Candy/Autoload.php';

// 读配置（覆盖方优先）
$cfg = [];
foreach ([
    $root . 'Candy/Class/Tools/Inc/Mail.inc.php',
    $root . 'Source/Inc/Mail.inc.php',
] as $file) {
    if (is_file($file)) {
        $one = include $file;
        if (is_array($one)) {
            $cfg = array_replace_recursive($cfg, $one);
        }
    }
}

// 句柄文件按服务隔离
$pidDir = $root . 'Runtime' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR;
$logDir = $root . 'Runtime' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR;
foreach ([$pidDir, $logDir] as $dir) {
    if (!\Candy\Extend\Dir::create($dir, 0775)) {
        fwrite(STDERR, "[mail] 无法创建目录：{$dir}\n");
        exit(1);
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0775);
    }
    if (!is_writable($dir)) {
        fwrite(STDERR, "[mail] 目录不可写：{$dir}（需 chown www:www）\n");
        exit(1);
    }
}
\Workerman\Worker::$pidFile    = $pidDir . 'mail.pid';
\Workerman\Worker::$logFile    = $logDir . 'mail-workerman.log';
\Workerman\Worker::$stdoutFile = $logDir . 'mail-stdout.log';

fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] 邮件服务：pid=' . getmypid()
    . ' pop3=' . ($cfg['pop3_port'] ?? 2110)
    . ' imap=' . ($cfg['imap_port'] ?? 2143) . PHP_EOL);

// 构造 Server 时会按配置建好 POP3/IMAP（及 SSL）Worker
$server = new \Mail\Server($cfg);

// 认证：从配置 users 里取（明文比对，用 hash_equals 防时序）
$users = (array) ($cfg['users'] ?? []);
$server->onAuth(static function ($user, $pass) use ($users) {
    $name = (string) $user;
    if (!isset($users[$name])) {
        return false;
    }
    return hash_equals((string) $users[$name], (string) $pass);
});

\Workerman\Worker::runAll();
