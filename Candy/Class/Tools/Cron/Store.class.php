<?php
/***
 * 计划任务存储（GlobalData 优先，Cache 兜底）
 *
 * 设计：
 *   **写**：双写 —— 先写 GlobalData（主，强一致、跨进程读即最新），再写 Cache（备，持久化，GD 重启后靠它恢复）
 *   **读**：GD 有数据就用 GD；GD 不可用或没数据时读 Cache，**并把 Cache 的值回填 GD**
 *
 * 为什么这样：GlobalData 是纯内存服务（`Candy/Vendor/Workerman/GlobalData/Server.php` 无任何持久化），
 * 服务重启 jobs 就没了；而 Cache（okr 配的是 Redis）能持久化。所以 GD 当"热路径主存"，
 * Cache 当"持久兜底"，任何一侧在线都能继续工作。
 *
 * 键：
 *   GlobalData  `candy_cron_tasks`
 *   Cache(G())  `cron.tasks`
 *
 * @package Candy\Extend\Tools\Cron
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 14:10:00
 */
namespace Candy\Extend\Tools\Cron;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Tools\Service\GlobalData;

final class Store
{
    /** GlobalData 键（主存） */
    public const GD_KEY = 'candy_cron_tasks';

    /** Cache / G() 键（备用 + 持久） */
    public const CACHE_KEY = 'cron.tasks';

    /**
     * 读全部任务
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get(): array
    {
        // ① GlobalData 优先
        if (GlobalData::available()) {
            $gd = GlobalData::read(self::GD_KEY, null);
            if (is_array($gd)) {
                return array_values($gd);
            }

            // ② GD 可用但没数据 → 从 Cache 取并回填
            $cached = self::cacheGet();
            if ($cached !== []) {
                GlobalData::write(self::GD_KEY, $cached);
            }
            return $cached;
        }

        // ③ GD 不可用 → 只用 Cache
        return self::cacheGet();
    }

    /**
     * 写全部任务（双写）
     *
     * @param array<int, array<string, mixed>> $rows 任务表
     * @return bool 至少写成功一处即为 true
     */
    public static function put(array $rows): bool
    {
        $rows = array_values($rows);

        $gdOk = false;
        if (GlobalData::available()) {
            $gdOk = GlobalData::write(self::GD_KEY, $rows);
        }

        $cacheOk = self::cachePut($rows);

        return $gdOk || $cacheOk;
    }

    /**
     * 当前存储来源与计数（排障用）
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        $gdAvailable = GlobalData::available();
        $gd = $gdAvailable ? GlobalData::read(self::GD_KEY, null) : null;
        $cached = self::cacheGet();

        $source = 'cache';
        if ($gdAvailable && is_array($gd)) {
            $source = 'globaldata';
        } elseif ($gdAvailable && !is_array($gd) && $cached !== []) {
            $source = 'cache(回填待写)';
        }

        return [
            'source'       => $source,
            'gd_available' => $gdAvailable,
            'gd_count'     => is_array($gd) ? count($gd) : 0,
            'cache_count'  => count($cached),
            'gd_key'       => self::GD_KEY,
            'cache_key'    => self::CACHE_KEY,
            'gd_error'     => GlobalData::error(),
        ];
    }

    /**
     * 清两侧（危险：会清空所有任务定义，仅排障用）
     *
     * @return bool
     */
    public static function flush(): bool
    {
        $ok = false;
        if (GlobalData::available()) {
            $ok = GlobalData::write(self::GD_KEY, []) || $ok;
        }
        return self::cachePut([]) || $ok;
    }

    /**
     * 从 Cache 读
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function cacheGet(): array
    {
        if (!function_exists('G')) {
            return [];
        }
        $value = G(self::CACHE_KEY);
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * 写 Cache
     *
     * @param array<int, array<string, mixed>> $rows 任务表
     * @return bool
     */
    protected static function cachePut(array $rows): bool
    {
        if (!function_exists('G')) {
            return false;
        }
        G(self::CACHE_KEY, array_values($rows));
        return is_array(G(self::CACHE_KEY));
    }
}
