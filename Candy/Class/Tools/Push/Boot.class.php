<?php
/***
 * 推送服务启动器（三进程）
 *
 *   Register        text://127.0.0.1:1236          注册中心
 *   Gateway         websocket://0.0.0.0:4020        对外连接层
 *   BusinessWorker  无监听                          业务层（跑 Events）
 *
 * 参数从 Config 读，**项目不配置也能跑**。
 *
 * 用法（由 Service\Manager 拉起）：
 *   php Candy/Class/Tools/Service/Entry/push.php start
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:58:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Workerman\Worker;

final class Boot
{
    /**
     * 启动三进程（阻塞在 Worker::runAll）
     *
     * @param string $command Workerman 命令：start/stop/restart/status
     * @return void
     */
    public static function run(string $command = 'start'): void
    {
        self::loadVendor();

        $cfg = Config::get();
        $registerAddr = (string) ($cfg['register']['listen'] ?? 'text://127.0.0.1:1236');
        $gatewayAddr = (string) ($cfg['gateway']['listen'] ?? 'websocket://0.0.0.0:4020');
        $gatewayCount = max(1, (int) ($cfg['gateway']['count'] ?? 1));
        $workerCount = max(1, (int) ($cfg['worker']['count'] ?? 1));

        // Workerman 读 argv[1] 当命令；自定义参数先剔掉
        $argv = [basename((string) ($_SERVER['argv'][0] ?? 'push.php'))];
        foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $arg) {
            if (str_starts_with((string) $arg, '--')) {
                continue;
            }
            $argv[] = (string) $arg;
        }
        if (count($argv) === 1) {
            $argv[] = $command;
        }
        $_SERVER['argv'] = $argv;
        $GLOBALS['argv'] = $argv;

        // pid / 日志指到 Runtime（Entry 目录下无写权限）
        $runtime = (defined('CANDYPATH') ? CANDYPATH : '') . 'Runtime' . DIRECTORY_SEPARATOR;
        foreach (['service', 'Logs' . DIRECTORY_SEPARATOR . 'process'] as $sub) {
            $dir = $runtime . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        Worker::$pidFile = $runtime . 'service' . DIRECTORY_SEPARATOR . 'push.pid';
        Worker::$logFile = $runtime . 'Logs' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'push-workerman.log';
        Worker::$stdoutFile = $runtime . 'Logs' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'push-stdout.log';

        // ① 注册中心（集群只有一个）
        $register = new \GatewayWorker\Register($registerAddr);
        $register->name = 'pushRegister';

        // ② 连接层
        $gateway = new \GatewayWorker\Gateway($gatewayAddr);
        $gateway->name = 'pushGateway';
        $gateway->count = $gatewayCount;
        $gateway->registerAddress = self::bareAddress($registerAddr);
        $gateway->startPort = 2900;
        // 心跳：每 25 秒发 ping，连续 2 次没有任何客户端数据就断开（清理僵尸连接）
        $gateway->pingInterval = 25;
        $gateway->pingNotResponseLimit = 2;
        $gateway->pingData = '{"act":"ping"}';
        // 缓冲区上限，防止慢客户端把内存吃光
        $gateway->sendToClientBufferSize = 1048576;

        // wss 支持：页面走 https 时前端会自动连 wss://，服务端不开 SSL 就连不上。
        // 没配或证书读不到 → 保持明文 ws（不会让整个服务起不来）。
        $sslNote = ' ssl=off';
        $ssl = (array) ($cfg['gateway']['ssl'] ?? []);
        if (!empty($ssl['enable'])) {
            $pair = self::sslPair($ssl);
            if ($pair === null) {
                $sslNote = ' ssl=off(证书不可读，检查 cert/key 路径与运行用户权限)';
            } else {
                $gateway->transport = 'ssl';
                $gateway->context = ['ssl' => [
                    'local_cert'        => $pair[0],
                    'local_pk'          => $pair[1],
                    'verify_peer'       => (bool) ($ssl['verify_peer'] ?? false),
                    'allow_self_signed' => true,
                ]];
                $sslNote = ' ssl=on';
            }
        }

        // ③ 业务层
        $business = new \GatewayWorker\BusinessWorker();
        $business->name = 'pushBusiness';
        $business->count = $workerCount;
        $business->registerAddress = self::bareAddress($registerAddr);
        $business->eventHandler = Events::class;

        echo '[' . date('Y-m-d H:i:s') . '] 推送服务启动：register=' . $registerAddr
            . ' gateway=' . $gatewayAddr . ' count=' . $gatewayCount . ' worker=' . $workerCount
            . $sslNote . PHP_EOL;

        Worker::runAll();
    }

    /**
     * 去掉协议前缀，取 host:port
     *
     * @param string $listen 监听串
     * @return string
     */
    protected static function bareAddress(string $listen): string
    {
        $pos = strpos($listen, '://');
        return $pos === false ? $listen : substr($listen, $pos + 3);
    }

    /**
     * 加载 vendored Workerman + GatewayWorker
     *
     * GatewayWorker 不在框架 autoload 前缀里，逐个 require。
     *
     * @return void
     */
    protected static function loadVendor(): void
    {
        $base = (defined('CANDYPATH') ? CANDYPATH : '') . 'Vendor' . DIRECTORY_SEPARATOR . 'Workerman' . DIRECTORY_SEPARATOR;
        $files = [
            'Workerman/Autoloader.php',
            'Coroutine/Context/ContextInterface.php',
            'Coroutine/Context/Fiber.php',
            'Coroutine/Context/Swoole.php',
            'Coroutine/Context/Swow.php',
            'Coroutine/Context.php',
            'GatewayWorker/Protocols/GatewayProtocol.php',
            'GatewayWorker/Lib/Context.php',
            'GatewayWorker/Lib/Gateway.php',
            'GatewayWorker/Register.php',
            'GatewayWorker/BusinessWorker.php',
            'GatewayWorker/Gateway.php',
        ];
        foreach ($files as $rel) {
            $file = $base . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    /**
     * 校验 wss 证书对，返回 [cert, key] 或 null
     *
     * 证书必须是「运行用户可读」的：宝塔的 /www/server/panel/vhost/cert/** 是 root:600，
     * www 读不到。开启 wss 前先把它们复制到站点内并 chown www。
     *
     * @param array<string, mixed> $ssl gateway.ssl 配置
     * @return array{0:string,1:string}|null
     */
    protected static function sslPair(array $ssl): ?array
    {
        $cert = trim((string) ($ssl['cert'] ?? ''));
        $key = trim((string) ($ssl['key'] ?? ''));
        if ($cert === '' || $key === '') {
            return null;
        }
        foreach ([$cert, $key] as $one) {
            if (!is_file($one) || !is_readable($one)) {
                return null;
            }
        }
        return [$cert, $key];
    }

}
