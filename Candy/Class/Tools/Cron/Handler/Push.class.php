<?php
/***
 * 框架内置任务处理器：WebSocket 推送
 *
 * 原理：不直接持有 WS 连接，而是把消息写进 GlobalData 的 `cron[时间戳]['push'][目标]`，
 * 由 WebSocket 服务的 1 秒定时器取走并投递（这套协议沿用 `Service/Entry/ws.php`）。
 * GlobalData 不可用时回落到离线队列 `Cron\Queue`。
 *
 * params：
 *   target  目标（`all` 或用户标识，默认 all）
 *   msg     消息内容（必填）
 *   url     全部离线时的回调地址（可空）
 *
 * @package Candy\Extend\Tools\Cron\Handler
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 14:22:00
 */
namespace Candy\Extend\Tools\Cron\Handler;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Tools\Cron\Queue;
use Candy\Extend\Tools\Service\GlobalData;

final class Push
{
    /** handler 标识 */
    public const HANDLER_ID = 'push';

    /** 展示名 */
    public const HANDLER_TITLE = 'WebSocket 推送（框架内置）';

    /** 说明 */
    public const HANDLER_DESC = '把消息交给 WebSocket 服务推送给在线用户；离线时进队列等上线补发';

    /**
     * 执行
     *
     * @param array<string, mixed> $params 参数
     * @return array{code:int, message:string}
     */
    public function run(array $params = []): array
    {
        $target = trim((string) ($params['target'] ?? 'all'));
        if ($target === '') {
            $target = 'all';
        }
        $msg = (string) ($params['msg'] ?? '');
        if ($msg === '') {
            return ['code' => 1, 'message' => 'msg 不能为空'];
        }
        $app = trim((string) ($params['app'] ?? 'default'));
        if ($app === '') {
            $app = 'default';
        }

        // 走框架推送能力（GatewayWorker）：在线直达，离线自动留存
        try {
            $pusher = \Candy\Extend\Tools\Push\Target::app($app);
            if ($target === 'all') {
                $res = $pusher->all($msg);
            } elseif (str_starts_with($target, '#')) {
                $res = $pusher->toGroup(substr($target, 1), $msg);
            } else {
                $res = $pusher->toUid($target, $msg);
            }
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => '推送异常：' . $e->getMessage()];
        }

        if (!empty($res['ok'])) {
            return ['code' => 0, 'message' => '已推送：' . (string) ($res['message'] ?? '')];
        }
        if ((int) ($res['queued'] ?? 0) > 0) {
            return ['code' => 0, 'message' => '目标离线，已存补发队列：' . (string) ($res['message'] ?? '')];
        }
        return ['code' => 1, 'message' => '推送失败：' . (string) ($res['message'] ?? '')];
    }
}
