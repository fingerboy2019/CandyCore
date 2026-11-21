<?php
/***
 * 离线消息留存（GlobalData 优先，Cache 兜底）
 *
 * 用户要求：**不落 MySQL**。离线消息是瞬时数据，量小、丢了可接受。
 * 存储优先级：GlobalData（内存，读即最新）→ 框架 G()（Cache）兜底。
 *
 * 键：push.offline.{app}:{uid}
 * 值：消息数组（按时间正序，最新在后）
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:50:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Tools\Service\GlobalData;

final class Offline
{
    /** 键前缀 */
    public const PREFIX = 'push.offline.';

    /** 索引键（GlobalData 不能列举键，用它记录"哪些目标有离线"） */
    public const INDEX_KEY = 'push.offline.index';

    /** @var int 锁重入深度 */
    private static int $lockDepth = 0;

    /**
     * 拼存储键
     *
     * @param string $app 应用
     * @param mixed  $uid 用户
     * @return string
     */
    public static function key(string $app, mixed $uid): string
    {
        return self::PREFIX . Registry::uid($app, $uid);
    }

    /**
     * 存一条离线消息
     *
     * @param string $app 应用
     * @param mixed  $uid 用户
     * @param mixed  $msg 消息
     * @return int 队列长度（0 = 未启用或失败）
     */
    public static function push(string $app, mixed $uid, mixed $msg): int
    {
        $cfg = self::cfg($app);
        if (empty($cfg['enable'])) {
            return 0;
        }
        $key = self::key($app, $uid);
        $max = (int) ($cfg['max'] ?? 200);
        $persist = (bool) ($cfg['persist'] ?? true);
        // 锁必须包住「读 → 改 → 写」全过程，只锁 save 会丢消息
        return (int) self::withLock(static function () use ($key, $msg, $max, $persist): int {
            $list = self::load($key, $persist);
            $list[] = Message::normalize($msg);
            if ($max > 0 && count($list) > $max) {
                $list = array_slice($list, -$max);
            }
            $ok = self::doSave($key, $list, $persist);
            if ($ok) {
                self::indexTouch($key, $persist);
            }
            return $ok ? count($list) : 0;
        });
    }

    /**
     * 有离线消息的目标索引
     *
     * @return array<string, int> key => 最后写入时间
     */
    public static function index(): array
    {
        $raw = self::rawRead(self::INDEX_KEY);
        return is_array($raw) ? $raw : [];
    }

    /**
     * 索引总条数（管理面 KPI）
     *
     * @return int
     */
    public static function total(): int
    {
        return count(self::index());
    }

    /**
     * 记一笔索引
     *
     * @param string $key 队列键
     * @return void
     */
    protected static function indexTouch(string $key, bool $persist = true): void
    {
        $idx = self::index();
        $idx[$key] = time();
        // 索引也跟随应用的持久化策略，否则 persist=false 重启后会留下「有目标无消息」的幽灵条目
        self::rawWrite(self::INDEX_KEY, $idx, $persist);
    }

    /**
     * 从索引移除
     *
     * @param string $key 队列键
     * @return void
     */
    protected static function indexForget(string $key): void
    {
        $idx = self::index();
        if (isset($idx[$key])) {
            unset($idx[$key]);
            // 两种副本都要清（持久化策略可能中途改过）
            self::rawWrite(self::INDEX_KEY, $idx, true);
        }
    }

    /**
     * 原始读（不按 TTL 过滤）
     *
     * @param string $key 键
     * @return mixed
     */
    protected static function rawRead(string $key): mixed
    {
        $raw = GlobalData::read($key, null);
        if (!is_array($raw)) {
            $raw = function_exists('G') ? G($key) : null;
        }
        return $raw;
    }

    /**
     * 原始写
     *
     * @param string $key  键
     * @param mixed  $data 数据
     * @return bool
     */
    protected static function rawWrite(string $key, mixed $data, bool $persist = true): bool
    {
        $ok = false;
        if (class_exists(GlobalData::class) && GlobalData::available()) {
            $ok = GlobalData::write($key, $data);
        }
        if ($persist && function_exists('G')) {
            G($key, $data);
            $ok = $ok || (G($key) === $data);
        }
        return $ok;
    }

    /**
     * 取出并清空（补发时用）
     *
     * @param string $app 应用
     * @param mixed  $uid 用户
     * @return array<int, array<string, mixed>>
     */
    public static function take(string $app, mixed $uid): array
    {
        $key = self::key($app, $uid);
        $persist = (bool) (self::cfg($app)['persist'] ?? true);
        return (array) self::withLock(static function () use ($key, $persist): array {
            $list = self::load($key, $persist);
            if ($list !== []) {
                self::doSave($key, [], $persist);
            }
            self::indexForget($key);
            return $list;
        });
    }

    /**
     * 队列长度
     *
     * @param string $app 应用
     * @param mixed  $uid 用户
     * @return int
     */
    public static function count(string $app, mixed $uid): int
    {
        return count(self::load(self::key($app, $uid)));
    }

    /**
     * 清空
     *
     * @param string $app 应用
     * @param mixed  $uid 用户
     * @return bool
     */
    public static function clear(string $app, mixed $uid): bool
    {
        $key = self::key($app, $uid);
        $persist = (bool) (self::cfg($app)['persist'] ?? true);
        return (bool) self::withLock(static function () use ($key, $persist): bool {
            self::indexForget($key);
            return self::doSave($key, [], $persist);
        });
    }

    /**
     * 读应用策略
     *
     * @param string $app 应用
     * @return array<string, mixed>
     */
    protected static function cfg(string $app): array
    {
        $appCfg = Registry::ensure($app);
        $cfg = is_array($appCfg['offline'] ?? null) ? $appCfg['offline'] : [];
        return array_replace((array) (Config::get()['offline'] ?? []), $cfg);
    }

    /**
     * 读队列（惰性剔除超 TTL 的条目）
     *
     * @param string $key 键
     * @return array<int, array<string, mixed>>
     */
    protected static function load(string $key, bool $persist = true): array
    {
        $raw = GlobalData::read($key, null);
        if (!is_array($raw) && $persist) {
            // 只有开了持久化的应用才会写 G() 副本
            $raw = function_exists('G') ? G($key) : null;
        }
        if (!is_array($raw)) {
            return [];
        }
        $ttl = (int) (Config::get()['offline']['ttl'] ?? 0);
        $now = time();
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($ttl > 0 && ($now - (int) ($item['ts'] ?? $now)) > $ttl) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 写队列（GlobalData 优先，G() 兜底）
     *
     * @param string                            $key  键
     * @param array<int, array<string, mixed>>  $list 消息
     * @return bool
     */
    protected static function withLock(callable $fn): mixed
    {
        // 同一进程内可重入（多应用并发推送/补发时不会自锁）
        if (self::$lockDepth > 0) {
            return $fn();
        }
        $lockFile = (defined('CANDYPATH') ? CANDYPATH : sys_get_temp_dir() . '/')
            . 'Runtime' . DIRECTORY_SEPARATOR . 'push-offline.lock';
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = @fopen($lockFile, 'c');
        if ($fp !== false) {
            @flock($fp, LOCK_EX);
        }
        self::$lockDepth++;
        try {
            return $fn();
        } finally {
            self::$lockDepth--;
            if ($fp !== false) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }

    /**
     * 真正写入（在锁内调用）
     *
     * @param string                           $key  键
     * @param array<int, array<string, mixed>> $list 消息
     * @return bool
     */
    protected static function doSave(string $key, array $list, bool $persist = true): bool
    {
        $ok = false;
        if (class_exists(GlobalData::class) && GlobalData::available()) {
            $ok = GlobalData::write($key, $list);
        }
        if ($persist && function_exists('G')) {
            // persist=false 的应用不写 G()，省 Redis
            G($key, $list);
            $ok = $ok || is_array(G($key));
        }
        return $ok;
    }
}
