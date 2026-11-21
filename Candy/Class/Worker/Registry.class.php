<?php
declare(strict_types=1);
/***
 * 框架内置 Worker 注册表
 *
 * 业务说明：把「框架跑起来就需要」的常驻 worker 内置进来，**项目零配置可用**。
 *
 * 内置（不需要写 Process.inc.php）：
 *   globaldata  Candy\Extend\Worker\GlobalData      共享内存服务（地址取 Api.inc globalDataGateway）
 *   scheduler   Workerman\Crontab\Server            计划任务秒级调度器
 *
 * 项目扩展（可选，写在 Source/Inc/Process.inc.php）：
 *   'rpc'  => ['handler' => \Candy\Extend\Rpc\Server::class, 'init' => '0.0.0.0:2020'],
 *   'mcp'  => ['handler' => \Candy\Extend\Mcp\HttpServer::class, 'init' => '127.0.0.1:8108'],
 *   同名键可覆盖内置项；某个键写成 false 可以单独关掉某个内置 worker。
 *
 * @package Candy\Extend\Worker
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 09:25:00
 */
namespace Candy\Extend\Worker;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Registry
{
    /**
     * 框架内置 worker（装上即可用）
     *
     * @return array<string, array<string, mixed>>
     */
    public static function builtin(): array
    {
        return [
            'globaldata' => [
                'handler'    => GlobalData::class,
                'init'       => GlobalData::resolveGateway(),
                'count'      => 1,
                'reloadable' => false,
            ],
            'scheduler' => [
                'handler'    => \Workerman\Crontab\Server::class,
                'count'      => 1,
                'reloadable' => false,
            ],
        ];
    }

    /**
     * 项目自定义 worker（Source/Inc/Process.inc.php，可为空）
     *
     * @return array<string, mixed>
     */
    public static function project(): array
    {
        if (!defined('CANDY') || !function_exists('C') || !function_exists('loadConfig')) {
            return [];
        }
        $path = (string) C('INCPATH') . 'Process.inc.php';
        if (!is_file($path)) {
            return [];
        }
        $config = loadConfig('Process');
        return is_array($config) ? $config : [];
    }

    /**
     * 最终 worker 清单：内置 + 项目（项目同名覆盖，false 表示关闭该内置项）
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $all = self::builtin();
        foreach (self::project() as $name => $item) {
            if ($item === false) {
                unset($all[$name]);
                continue;
            }
            if (!is_array($item) || empty($item['handler'])) {
                continue;   // 格式不对的条目直接跳过，不要因为一条配错导致整个进程组起不来
            }
            $all[$name] = $item;
        }
        return $all;
    }

    /**
     * 自省信息（排障用）
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        $project = self::project();
        return [
            'builtin'  => array_keys(self::builtin()),
            'project'  => array_keys($project),
            'disabled' => array_keys(array_filter($project, static fn ($v) => $v === false)),
            'workers'  => array_keys(self::all()),
            'config'   => is_file((string) (function_exists('C') ? C('INCPATH') : '') . 'Process.inc.php'),
        ];
    }
}
