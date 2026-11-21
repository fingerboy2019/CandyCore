<?php
/***
 * 推送目标（业务侧唯一入口）
 *
 * 用法：
 *   Target::app('home')->toUid(34, '你有新的工单', ['ticket_id' => 12]);
 *   Target::app('home')->toGroup('dept-12', '部门通知');
 *   Target::app('home')->all('系统维护');
 *   Target::broadcast('全站公告');
 *
 * 返回统一结构：['ok' => bool, 'sent' => int, 'queued' => int, 'message' => string]
 *
 * **降级原则：任何情况下都不抛异常。**
 * Gateway 未启动 / 目标不在线 → 写离线队列并返回 ok=false + queued>0。
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:52:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Target
{
    /**
     * 指定应用（返回可链式实例）
     *
     * @param string $app 应用名
     * @return self
     */
    public static function app(mixed $app = 'default'): self
    {
        // 数字 = app_id（公开标识，优先）；字符串 = 应用名（内部可读写法，兼容）
        $name = '';
        if (is_int($app) || (is_string($app) && ctype_digit($app))) {
            $name = (string) (Registry::findByAppId((int) $app) ?? '');
            if ($name === '') {
                // app_id 不存在：不静默推送，落 default 并留痕
                Audit::line('MISS', ['app_id' => (int) $app, 'via' => self::$via]);
                $name = 'default';
            }
        } else {
            $name = (string) $app;
        }
        if ($name === '') {
            $name = 'default';
        }
        Registry::ensure($name);
        return new self($name);
    }

    /**
     * 标记调用来源（internal=框架内部代码 / api=第三方 HTTP）
     *
     * @param string $via 来源
     * @return void
     */
    public static function via(string $via): void
    {
        self::$via = $via;
    }

    /**
     * 当前调用来源
     *
     * @return string
     */
    public static function viaName(): string
    {
        return self::$via;
    }

    /**
     * 全应用广播
     *
     * @param mixed                $msg     消息
     * @param array<string, mixed> $payload 附加数据
     * @param string               $title   标题
     * @return array<string, mixed>
     */
    public static function broadcast(mixed $msg, array $payload = [], string $title = ''): array
    {
        return self::app('default')->all($msg, $payload, $title);
    }

    /**
     * 推给单个用户
     *
     * @param mixed                $uid     用户
     * @param mixed                $msg     消息
     * @param array<string, mixed> $payload 附加数据
     * @param string               $title   标题
     * @return array<string, mixed>
     */
    public function toUid(mixed $uid, mixed $msg, array $payload = [], string $title = ''): array
    {
        $m = $this->msg($msg, $payload, $title);
        $err = $this->guard($m);
        if ($err !== null) {
            return $err;
        }
        $full = Registry::uid($this->app, $uid);
        $gw = self::gw();
        if ($gw !== null) {
            try {
                if ($gw::isUidOnline($full)) {
                    $gw::sendToUid($full, Message::encode($m));
                    $out = ['ok' => true, 'sent' => 1, 'queued' => 0, 'message' => '已发送'];
                    Audit::send($this->app, $uid, $out, $m);
                    return $out;
                }
            } catch (\Throwable $e) {
                // 落到离线
            }
        }
        $n = Offline::push($this->app, $uid, $m);
        $out = [
            'ok'     => false,
            'sent'   => 0,
            'queued' => $n,
            'message' => $gw === null
                ? '推送服务未启动，已存离线 [' . self::$lastInitError . ']'
                : '目标不在线，已存离线',
        ];
        Audit::send($this->app, $uid, $out, $m);
        return $out;
    }

    /**
     * 推给分组
     *
     * @param mixed                $tag     分组
     * @param mixed                $msg     消息
     * @param array<string, mixed> $payload 附加数据
     * @param string               $title   标题
     * @return array<string, mixed>
     */
    public function toGroup(mixed $tag, mixed $msg, array $payload = [], string $title = ''): array
    {
        $m = $this->msg($msg, $payload, $title);
        $err = $this->guard($m);
        if ($err !== null) {
            return $err;
        }
        $gw = self::gw();
        if ($gw === null) {
            return ['ok' => false, 'sent' => 0, 'queued' => 0, 'message' => '推送服务未启动'];
        }
        try {
            $group = Registry::group($this->app, $tag);
            // Gateway::sendToGroup 在 4.x 不返回发送数量，先查分组连接数再发
            $n = 0;
            try {
                $n = (int) $gw::getClientIdCountByGroup($group);
            } catch (\Throwable $e) {
                $n = 0;
            }
            $gw::sendToGroup($group, Message::encode($m));
            return ['ok' => $n > 0, 'sent' => $n, 'queued' => 0, 'message' => $n > 0 ? '已发送 ' . $n . ' 个连接' : '该分组无在线连接'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'sent' => 0, 'queued' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * 推给本应用全部在线连接
     *
     * @param mixed                $msg     消息
     * @param array<string, mixed> $payload 附加数据
     * @param string               $title   标题
     * @return array<string, mixed>
     */
    public function all(mixed $msg, array $payload = [], string $title = ''): array
    {
        $m = $this->msg($msg, $payload, $title);
        $err = $this->guard($m);
        if ($err !== null) {
            return $err;
        }
        $gw = self::gw();
        if ($gw === null) {
            return ['ok' => false, 'sent' => 0, 'queued' => 0, 'message' => '推送服务未启动'];
        }
        try {
            $uids = (array) $gw::getAllUidList();
            $n = 0;
            $prefix = Registry::uid($this->app, '');
            foreach ($uids as $uid) {
                if (strpos((string) $uid, $prefix) !== 0) {
                    continue;
                }
                $gw::sendToUid((string) $uid, Message::encode($m));
                $n++;
            }
            return ['ok' => $n > 0, 'sent' => $n, 'queued' => 0, 'message' => $n > 0 ? '已发送 ' . $n . ' 个用户' : '本应用无在线用户'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'sent' => 0, 'queued' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * 本应用的离线队列（管理面用）
     *
     * @param mixed $uid 用户
     * @return array<int, array<string, mixed>>
     */
    public function offlineOf(mixed $uid): array
    {
        return Offline::take($this->app, $uid);
    }

    /**
     * 构造消息
     *
     * @param mixed                $msg     消息
     * @param array<string, mixed> $payload 附加数据
     * @param string               $title   标题
     * @return array<string, mixed>
     */
    protected function msg(mixed $msg, array $payload, string $title): array
    {
        if (is_array($msg)) {
            return Message::normalize($msg);
        }
        return Message::make((string) $msg, $payload, $title);
    }

    /**
     * 消息大小校验（超限返回错误结构）
     *
     * @param array<string, mixed> $m 消息
     * @return array<string, mixed>|null
     */
    protected function guard(array $m): ?array
    {
        if (Message::tooLarge($m)) {
            return ['ok' => false, 'sent' => 0, 'queued' => 0, 'message' => '消息超过大小上限 ' . Message::bytes($m) . 'B'];
        }
        return null;
    }

    /**
     * 取 Gateway 静态类（不可用返回 null）
     *
     * GatewayWorker 不在框架 autoload 映射里，这里按需 require。
     *
     * @return string|null
     */
    protected static function gw(): ?string
    {
        $class = '\\GatewayWorker\\Lib\\Gateway';
        if (!class_exists($class, false)) {
            // 框架根 = Candy/（CANDYPATH 是项目根，语义不一致，用 __DIR__ 反推）
            $base = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR . 'Workerman' . DIRECTORY_SEPARATOR . 'GatewayWorker' . DIRECTORY_SEPARATOR;
            $probe = [];
            foreach (['Protocols/GatewayProtocol.php', 'Lib/Context.php', 'Lib/Gateway.php'] as $rel) {
                $file = $base . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $probe[$rel] = is_file($file) ? 'ok' : 'MISSING';
                if (is_file($file)) {
                    try {
                        require_once $file;
                    } catch (\Throwable $e) {
                        $probe[$rel] .= ':' . $e->getMessage();
                    }
                }
            }
            self::$lastInitError = json_encode(['base' => $base, 'probe' => $probe], JSON_UNESCAPED_SLASHES);
        }
        if (class_exists($class, false)) {
            return $class;
        }
        if (self::$lastInitError === '') {
            self::$lastInitError = 'class_exists false, base=' . (defined('CANDYPATH') ? CANDYPATH : '');
        }
        return null;
    }

    /** @var string 最近一次初始化失败的原因（排障用） */
    protected static string $lastInitError = '';

    /** @var string 调用来源：internal（框架内部）/ api（第三方 HTTP） */
    protected static string $via = 'internal';

    /**
     * 构造
     *
     * @param string $app 应用名
     */
    private function __construct(private string $app)
    {
    }
}
