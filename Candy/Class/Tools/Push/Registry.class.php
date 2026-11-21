<?php
/***
 * 应用注册表 —— 一个 Gateway 集群服务多个应用
 *
 * 隔离靠命名空间前缀：
 *   uid   = "{app}:{user_id}"    例 home:34
 *   group = "{app}:{tag}"        例 home:dept-12
 *
 * **未注册的应用首次出现时按默认策略自动注册** —— 这是"开启就能使用"的关键：
 * 项目不写任何配置也能推，注册只用来调整该应用的离线策略。
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:42:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Registry
{
    /**
     * 注册一个应用（幂等，重复注册以最后一次为准）
     *
     * @param string               $app     应用名（缺省 default）
     * @param array<string, mixed> $options 覆盖项
     * @return array<string, mixed>
     */
    public static function register(string $app, array $options = [], bool $auto = false): array
    {
        $name = self::norm($app);
        if ($auto) {
            self::$autoApps[$name] = true;
        } else {
            unset(self::$autoApps[$name]);
        }
        $cfg = Config::get();
        $base = [
            'label'   => $name,
            'enable'  => true,
            'offline' => $cfg['offline'] ?? [],
        ];
        self::$apps[$name] = array_replace_recursive($base, $options);
        return self::$apps[$name];
    }

    /**
     * 取应用配置；未注册则按默认策略自动注册
     *
     * @param string $app 应用名
     * @return array<string, mixed>
     */
    public static function ensure(string $app): array
    {
        self::load();
        $name = self::norm($app);
        return self::$apps[$name] ?? self::register($name, [], true);
    }

    /**
     * 已注册的全部应用
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        self::load();
        return self::$apps;
    }

    /**
     * 从配置装载应用（幂等，只装一次）
     *
     * @return void
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $apps = Config::get()['apps'] ?? [];
        if (is_array($apps)) {
            foreach ($apps as $name => $opt) {
                self::register((string) $name, is_array($opt) ? $opt : []);
            }
        }
    }

    /**
     * 生成一个新的应用 key（类似极光的 MasterSecret）
     *
     * @return string
     */
    public static function newKey(): string
    {
        return 'pk_' . bin2hex(random_bytes(16));
    }

    /**
     * 分配一个 app_id（从 1001 起递增，稳定不复用）
     *
     * @return int
     */
    public static function nextAppId(): int
    {
        $max = 1000;
        self::load();
        foreach (self::$apps as $cfg) {
            $id = (int) ($cfg['app_id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }
        return $max + 1;
    }

    /**
     * 取应用 app_id（没有就分配一个）
     *
     * @param string $app 应用名
     * @return int
     */
    public static function appIdOf(string $app): int
    {
        $name = self::norm($app);
        self::ensure($name);
        $id = (int) (self::$apps[$name]['app_id'] ?? 0);
        if ($id <= 0) {
            $id = self::nextAppId();
            self::$apps[$name]['app_id'] = $id;
        }
        return $id;
    }

    /**
     * 按 app_id + key 双因子校验，返回应用名
     *
     * 两者必须属于同一个应用；任一不匹配都返回 null。
     *
     * @param mixed  $appId app_id
     * @param string $key   应用 key
     * @return string|null
     */
    public static function verify(mixed $appId, string $key): ?string
    {
        $id = (int) $appId;
        $key = trim($key);
        if ($id <= 0 || $key === '') {
            return null;
        }
        self::load();
        foreach (self::$apps as $name => $cfg) {
            if ((int) ($cfg['app_id'] ?? 0) === $id && (string) ($cfg['key'] ?? '') === $key) {
                return (string) $name;
            }
        }
        return null;
    }

    /**
     * 取应用 key（没有就生成一个）
     *
     * @param string $app 应用名
     * @return string
     */
    public static function keyOf(string $app): string
    {
        $name = self::norm($app);
        self::ensure($name);
        $key = (string) (self::$apps[$name]['key'] ?? '');
        if ($key === '') {
            $key = self::newKey();
            self::$apps[$name]['key'] = $key;
        }
        return $key;
    }

    /**
     * 按 app_id 反查应用名（对外标识 → 内部命名空间）
     *
     * @param mixed $appId app_id
     * @return string|null
     */
    public static function findByAppId(mixed $appId): ?string
    {
        $id = (int) $appId;
        if ($id <= 0) {
            return null;
        }
        self::load();
        foreach (self::$apps as $name => $cfg) {
            if ((int) ($cfg['app_id'] ?? 0) === $id) {
                return (string) $name;
            }
        }
        return null;
    }

    /**
     * 按 key 反查应用名
     *
     * @param string $key 应用 key
     * @return string|null
     */
    public static function findByKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        self::load();
        foreach (self::$apps as $name => $cfg) {
            if (isset($cfg['key']) && (string) $cfg['key'] === $key) {
                return (string) $name;
            }
        }
        return null;
    }

    /**
     * 把当前应用表持久化到 Source/Inc/Push.inc.php
     *
     * 只合并 apps 段，不动文件里其它配置项。
     *
     * @return bool
     */
    public static function persist(): bool
    {
        $inc = function_exists('C') ? (string) C('INCPATH') : '';
        // 写「当前实际生效」的那个文件：项目覆盖文件优先，没有就写框架默认骨架
        $path = function_exists('incResolve') ? incResolve('Push') : '';
        if ($path === '') {
            if ($inc === '') {
                return false;
            }
            $path = rtrim($inc, "/\\") . DIRECTORY_SEPARATOR . 'Push.inc.php';
        }
        $current = [];
        if (is_file($path)) {
            $raw = include $path;
            if (is_array($raw)) {
                $current = $raw;
            }
        }
        // 只持久化「显式注册」的应用：自动注册的兜底命名空间（如 default）不该写进配置文件
        $keep = [];
        foreach (self::$apps as $name => $one) {
            if (!isset(self::$autoApps[$name])) {
                $keep[$name] = $one;
            }
        }
        $current['apps'] = $keep;
        $code = "<?php
/***\n * 推送服务配置（由 /sys/push 页面维护 apps 段，可手工编辑）\n *\n * @package Candy\\Extend\\Tools\\Push\n */\nreturn " . var_export($current, true) . ";\n";
        $ok = @file_put_contents($path, $code) !== false;
        if ($ok && function_exists('opcache_invalidate')) {
            // 写配置后必须失效 opcache，否则同一秒内的下一个请求会读到旧配置
            // （表现为「刚注册的应用不在列表里」，进而 ensure() 用默认策略）
            @opcache_invalidate($path, true);
        }
        return $ok;
    }

    /**
     * 清空装载标记（测试用）
     *
     * @return void
     */
    public static function resetLoaded(): void
    {
        self::$loaded = false;
    }

    /**
     * uid 加应用前缀
     *
     * @param string $app 应用名
     * @param mixed  $uid 用户标识
     * @return string
     */
    public static function uid(string $app, mixed $uid): string
    {
        return self::norm($app) . ':' . (string) $uid;
    }

    /**
     * group 加应用前缀
     *
     * @param string $app 应用名
     * @param mixed  $tag 分组标识
     * @return string
     */
    public static function group(string $app, mixed $tag): string
    {
        return self::norm($app) . ':' . (string) $tag;
    }

    /**
     * 整表替换（删应用时用）
     *
     * @param array<string, array<string, mixed>> $apps 应用表
     * @return void
     */
    public static function replaceAll(array $apps): void
    {
        self::$apps = [];
        foreach ($apps as $name => $cfg) {
            self::register((string) $name, is_array($cfg) ? $cfg : []);
        }
        self::$loaded = true;
    }

    /**
     * 清空注册表（测试用）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$apps = [];
        self::$loaded = false;
    }

    /**
     * 规范化应用名
     *
     * @param string $app 原始名
     * @return string
     */
    protected static function norm(string $app): string
    {
        $name = strtolower(trim($app));
        return $name === '' ? 'default' : $name;
    }

    /** @var array<string, array<string, mixed>> */
    private static array $apps = [];

    /** @var bool 是否已从配置装载 */
    private static bool $loaded = false;

    /** @var array<string, bool> 自动注册（兜底）的应用，不写进配置文件 */
    private static array $autoApps = [];
}
