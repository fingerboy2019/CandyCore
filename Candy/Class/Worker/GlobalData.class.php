<?php
declare(strict_types=1);
/***
 * 框架内置 Worker：GlobalData 共享内存服务
 *
 * 业务说明：为跨进程共享数据（计划任务快照、在线映射、离线队列索引）提供内存服务。
 * 框架默认自带，**不需要在 Source/Inc/Process.inc.php 里注册**；
 * 想改监听地址，在 Source/Inc/Api.inc.php 里配 `globalDataGateway`，
 * 或在 Process.inc.php 里用同名键覆盖 `init`。
 *
 * 本类只做「地址解析 + 统一单参 new 风格」这层适配，**协议实现全部在
 * Candy/Vendor/Workerman/GlobalData/Server.php**（frame 帧协议 + serialize，6 条指令）。
 * 不要在 Class 下再抄一份 onMessage —— 同一份协议只该有一处实现。
 *
 * @package Candy\Extend\Worker
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-18 09:00:00
 */
namespace Candy\Extend\Worker;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class GlobalData extends \GlobalData\Server
{
    /**
     * 默认监听地址（与 Candy\Extend\Tools\Service\GlobalData::DEFAULT_ADDRESS 保持一致）
     */
    public const DEFAULT_ADDRESS = '127.0.0.1:2207';

    /**
     * @param string|null $address 监听地址 host:port（也接受 unix:///path）；null 表示按配置解析
     */
    public function __construct(?string $address = null)
    {
        parent::__construct(($address !== null && $address !== '') ? $address : self::resolveGateway());

        // 统一 new 风格下补上框架侧的 worker 标识（vendor 里 Worker 实例是 protected $_worker）
        $this->_worker->name = 'globaldata';
    }

    /**
     * 解析 GlobalData 网关地址
     *
     * 优先级：显式传参 > Source/Inc/Api.inc.php 的 globalDataGateway > 默认 127.0.0.1:2207
     *
     * @return string
     */
    public static function resolveGateway(): string
    {
        if (defined('CANDY') && function_exists('C') && function_exists('loadConfig')) {
            $inc = (string) C('INCPATH');
            if ($inc !== '' && is_file($inc . 'Api.inc.php')) {
                $api = loadConfig('Api');
                $gateway = trim((string) ($api['globalDataGateway'] ?? ''));
                if ($gateway !== '') {
                    return $gateway;
                }
            }
        }
        return self::DEFAULT_ADDRESS;
    }
}
