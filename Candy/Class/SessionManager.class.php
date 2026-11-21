<?php
/***
 * 统一会话管理器
 *
 * 框架级别会话读写统一入口，支持缓存优先、文件回退。
 * 适用于 Workerman 多 Worker 模式与 PHP-FPM 模式。
 *
 * 规则：
 * - Cache 驱动为文件类（file/auto）时，直接使用文件存储
 * - Cache 驱动为 Redis/Memcache 等时，优先写入 Cache
 * - Cache 写入失败或不可用时，自动回退文件
 * - 文件始终作为最终保底写入，确保不丢会话
 *
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-30 18:30:00
 */
declare(strict_types=1);

namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class SessionManager
{
    /** @var string 会话文件存储路径 */
    private static string $savePath = '';

    /** @var string 会话文件前缀 */
    private static string $filePrefix = 'ses_';

    /** @var array<string,bool> 只适合进程内/单机的缓存驱动，不用于会话共享 */
    /** @var string 缓存键前缀 */
    private static string $cachePrefix = 'ses_';

    /** @var bool 是否使用缓存（非文件驱动时启用） */
    private static bool $useCache = false;

    /** @var object|null 缓存实例 */
    private static ?object $cache = null;

    /**
     * 初始化会话管理器
     *
     * @param string $savePath 会话文件路径
     * @return void
     */
    public static function init(string $savePath = ''): void
    {
        self::$savePath = $savePath ?: rtrim((string)(C('RUNTIME') ?? sys_get_temp_dir()), '/\\') . '/Data/session/';
        if (!is_dir(self::$savePath)) {
            @mkdir(self::$savePath, 0775, true);
        }
        self::detectCache();
    }

    /**
     * 检测是否可使用缓存
     */
    /**
     * 检测是否可使用缓存
     *
     * 规则：只有支持跨进程/跨服务器共享的缓存驱动才用于会话。
     * 本地驱动（File/Memory/Shmop/Apcu 等）跳过，直接使用文件。
     */
    private static function detectCache(): void
    {
        try {
            if (\Candy\Core\Cache::isFileDriver()) {
                return; // 文件驱动，不使用缓存
            }
            $cache = \Candy\Core\Cache::store();
            if ($cache === null) return;
            $class = strtolower((new \ReflectionClass($cache))->getShortName());
            foreach (['memory', 'shmop', 'apcu', 'xcache', 'wincache'] as $local) {
                if (str_contains($class, $local)) {
                    return; // 进程本地驱动，不使用缓存
                }
            }
            // 是共享缓存驱动（Redis/Memcache 等），启用缓存会话
            self::$cache = $cache;
            self::$useCache = true;
        } catch (\Throwable) {
        }
    }

    /**
     * 读取会话
     *
     * 优先读缓存，未命中时回退文件（兼容历史会话数据）。
     *
     * @param string $sessionId 会话 ID
     * @return string 会话数据
     */
    public static function read(string $sessionId): string
    {
        // 优先读缓存
        if (self::$useCache && self::$cache !== null) {
            try {
                $cached = self::$cache->get(self::$cachePrefix . $sessionId);
                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
            } catch (\Throwable) {
            }
        }
        // 缓存未命中或不可用时读文件（兼容历史数据）
        $file = self::$savePath . self::$filePrefix . $sessionId;
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if (is_string($raw)) {
                return $raw;
            }
        }
        return '';
    }

    /**
     * 写入会话
     *
     * 缓存可用时仅写缓存（无文件 I/O），缓存不可用时回退文件。
     *
     * @param string $sessionId 会话 ID
     * @param string $data 会话数据
     * @param int $lifetime 过期时间（秒）
     * @return bool
     */
    public static function write(string $sessionId, string $data, int $lifetime = 1440): bool
    {
        if (self::$useCache && self::$cache !== null) {
            try {
                self::$cache->set(self::$cachePrefix . $sessionId, $data, $lifetime);
                return true; // 缓存写入成功，跳过文件
            } catch (\Throwable) {
                // 缓存异常，降级文件
            }
        }
        // 缓存不可用或写入失败时写文件
        $file = self::$savePath . self::$filePrefix . $sessionId;
        return (bool)@file_put_contents($file, $data);
    }

    /**
     * 删除会话
     *
     * 缓存和文件都尝试删除，确保无论当前模式为何都能清除。
     *
     * @param string $sessionId 会话 ID
     * @return bool
     */
    public static function destroy(string $sessionId): bool
    {
        if (self::$useCache && self::$cache !== null) {
            try {
                self::$cache->delete(self::$cachePrefix . $sessionId);
            } catch (\Throwable) {
            }
        }
        $file = self::$savePath . self::$filePrefix . $sessionId;
        if (is_file($file)) {
            @unlink($file);
        }
        return true;
    }

    /**
     * 获取会话文件路径
     *
     * @return string
     */
    public static function getSavePath(): string
    {
        return self::$savePath;
    }

    /**
     * 是否使用缓存
     *
     * @return bool
     */
    public static function isCacheEnabled(): bool
    {
        return self::$useCache;
    }

    /**
     * 获取当前缓存驱动名
     *
     * @return string
     */
    public static function getCacheDriver(): string
    {
        if (!self::$useCache || self::$cache === null) {
            return 'file';
        }
        try {
            return (new \ReflectionClass(self::$cache))->getShortName();
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}

