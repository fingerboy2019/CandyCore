<?php
/***
 * GlobalData 客户端桥（给框架侧写数据用）
 *
 * 业务说明：WebSocket 服务（`Service/Entry/ws.php`）每秒从 GlobalData 读三样东西并对外投递：
 *   `cron`          到点的推送 / 回调任务（`$cron[时间戳]['push'][目标] = ['msg'=>..,'url'=>..]`）
 *   `sendmsgfailed` 离线消息（用户绑定上线时补发）
 *   `userList`      在线用户映射（`ids[连接id] => 目标`、`phone[目标] => 连接id`）
 *
 * 所以框架要「让服务推一条消息」，只要往 GlobalData 的 `cron[now]` 里塞一条即可——
 * 最多 1 秒后 WS 的定时器就会把它发出去并删掉。**这就是用户脚本自带的推送接口**。
 *
 * 用法：
 * ```php
 * $gd = GlobalData::make();                       // 连 127.0.0.1:2207
 * $gd->pushNow('all', '你好');                     // 立即推（下一秒内）
 * $gd->pushAt(time() + 60, 'u1001', '提醒');       // 定时推
 * $gd->cronList();                                // 看当前排队的定时项
 * $gd->online();                                  // 在线用户
 * ```
 *
 * 连接是**惰性**的，GlobalData 服务没开时不会崩，而是返回明确错误（`ok=false`），
 * 页面/接口据此提示「请先在服务页开启 GlobalData」。
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 13:55:00
 */
namespace Candy\Extend\Tools\Service;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class GlobalData
{
    /** 默认地址（与 Entry/ws.php 的 --gd-port 对应） */
    public const DEFAULT_ADDRESS = '127.0.0.1:2207';

    /** 连接实例 */
    private static ?object $client = null;

    /** 连接地址 */
    private static string $address = self::DEFAULT_ADDRESS;

    /** 最近一次错误 */
    private static string $error = '';

    /**
     * 设置地址（例如配置里改了端口）
     *
     * @param string $address ip:port
     * @return void
     */
    public static function setAddress(string $address): void
    {
        if ($address !== '' && $address !== self::$address) {
            self::$address = $address;
            self::$client = null;
        }
    }

    /**
     * 拿客户端（惰性连接）
     *
     * @return object|null
     */
    public static function make(): ?object
    {
        if (self::$client !== null) {
            return self::$client;
        }

        try {
            self::boot();
            self::$client = new \GlobalData\Client(self::$address);
            self::$error = '';
            return self::$client;
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            self::$client = null;
            return null;
        }
    }

    /**
     * 加载 Workerman Autoloader 与 GlobalData\Client
     *
     * @return void
     */
    protected static function boot(): void
    {
        if (class_exists('\\GlobalData\\Client', false)) {
            return;
        }
        $root = Manager::root();
        $autoloader = $root . 'Candy/Vendor/Workerman/Workerman/Autoloader.php';
        $client = $root . 'Candy/Vendor/Workerman/GlobalData/Client.php';
        if (!is_file($autoloader) || !is_file($client)) {
            throw new \RuntimeException('缺少 Workerman/GlobalData：' . $client);
        }
        require_once $autoloader;
        require_once $client;
    }

    /**
     * 最近一次错误
     *
     * @return string
     */
    public static function error(): string
    {
        return self::$error;
    }

    /**
     * 服务是否可用（能读到 userList 即视为通）
     *
     * @return bool
     */
    public static function available(): bool
    {
        $client = self::make();
        if ($client === null) {
            return false;
        }
        $stamp = 'p' . time();
        try {
            $client->candyProbe = $stamp;
            return (string) $client->candyProbe === $stamp;
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            self::$client = null;
            return false;
        }
    }

    /**
     * 读一个键
     *
     * @param string $key     键
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function read(string $key, mixed $default = null): mixed
    {
        $client = self::make();
        if ($client === null) {
            return $default;
        }
        try {
            $value = $client->{$key};
            return $value === null ? $default : $value;
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            self::$client = null;
            return $default;
        }
    }

    /**
     * 写一个键
     *
     * @param string $key   键
     * @param mixed  $value 值
     * @return bool
     */
    public static function write(string $key, mixed $value): bool
    {
        $client = self::make();
        if ($client === null) {
            return false;
        }
        try {
            $client->{$key} = $value;
            return true;
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            self::$client = null;
            return false;
        }
    }

    /**
     * 当前排队的定时项
     *
     * @return array<int|string, mixed>
     */
    public static function cronList(): array
    {
        $cron = self::read('cron', []);
        if (!is_array($cron)) {
            return [];
        }
        ksort($cron, SORT_NUMERIC);
        return $cron;
    }

    /**
     * 在线用户映射
     *
     * @return array<string, mixed>
     */
    public static function online(): array
    {
        $list = self::read('userList', []);
        return is_array($list) ? $list : [];
    }

    /**
     * 立即推送（下一秒内由 WS 定时器发出并自动从 cron 移除）
     *
     * @param string $target 目标（`all` 或用户标识）
     * @param string $msg    消息内容
     * @param string $url    全部离线时的回调地址（可空）
     * @param int|null $at   指定时间戳（默认现在）
     * @return array{ok:bool, message:string, at:int}
     */
    public static function pushNow(string $target, string $msg, string $url = '', ?int $at = null): array
    {
        $at = $at ?? time();
        if ($target === '') {
            $target = 'all';
        }
        if ($msg === '') {
            return ['ok' => false, 'message' => '消息内容不能为空', 'at' => $at];
        }

        $cron = self::cronList();
        $cron[$at]['push'][$target] = ['msg' => $msg, 'url' => $url];

        if (!self::write('cron', $cron)) {
            return ['ok' => false, 'message' => self::error() !== '' ? ('写入 GlobalData 失败：' . self::error()) : '写入 GlobalData 失败（服务可能未启动）', 'at' => $at];
        }
        return ['ok' => true, 'message' => '已提交推送（' . date('H:i:s', $at) . ' 起 1 秒内投递）', 'at' => $at];
    }

    /**
     * 定时推送
     *
     * @param int    $at     执行时间戳
     * @param string $target 目标
     * @param string $msg    内容
     * @param string $url    离线回调
     * @return array{ok:bool, message:string, at:int}
     */
    public static function pushAt(int $at, string $target, string $msg, string $url = ''): array
    {
        return self::pushNow($target, $msg, $url, $at);
    }

    /**
     * 提交一个定时回调（到点由 WS 发 HTTP，可带周期）
     *
     * @param int    $at       首次执行时间戳
     * @param string $url      回调地址
     * @param string $method   get / post
     * @param array  $post     POST 数据
     * @param int    $interval 周期秒（0=一次）
     * @return array{ok:bool, message:string, at:int}
     */
    public static function callback(int $at, string $url, string $method = 'get', array $post = [], int $interval = 0): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'message' => '回调地址必须是 http(s):// 开头', 'at' => $at];
        }
        $cron = self::cronList();
        $cron[$at]['sever'][md5($url)] = [
            'url'      => $url,
            'type'     => strtolower($method) === 'post' ? 'post' : 'get',
            'post'     => $post,
            'interval' => max(0, $interval),
        ];
        if (!self::write('cron', $cron)) {
            return ['ok' => false, 'message' => '写入 GlobalData 失败（服务可能未启动）', 'at' => $at];
        }
        return ['ok' => true, 'message' => '已提交定时回调', 'at' => $at];
    }
}
