<?php
/***
 * 推送能力的应用注册入口（供 Application/tags.php 一行接入）
 *
 * 用法：
 *   // Application/tags.php
 *   return \Candy\Extend\Tools\Push\Bootstrap::mergeTags(
 *       [],   // 其它 hook 放这里
 *       ['home' => ['label' => '门户'], 'admin' => ['label' => '后台', 'offline' => ['ttl' => 86400]]]
 *   );
 *
 * 推送不占用任何 hook 通道（它不是"随请求执行"的能力），
 * `mergeTags` 只是复用了框架功能模块的注册入口习惯，返回原样的 hook 表。
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:46:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Bootstrap
{
    /**
     * 注册应用并原样返回 hook 表（不新增 hook）
     *
     * @param array<string, mixed> $tags 原 hook 表
     * @param array<string, mixed> $apps 应用定义：app => options
     * @return array<string, mixed>
     */
    public static function mergeTags(array $tags = [], array $apps = []): array
    {
        self::register($apps);
        return $tags;
    }

    /**
     * 批量注册应用
     *
     * @param array<string, mixed> $apps 应用定义
     * @return array<string, array<string, mixed>> 注册后的全部应用
     */
    public static function register(array $apps = []): array
    {
        foreach ($apps as $name => $options) {
            Registry::register((string) $name, is_array($options) ? $options : []);
        }
        return Registry::all();
    }

    /**
     * 能力是否开启
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool) (Config::get()['enable'] ?? true);
    }
}
