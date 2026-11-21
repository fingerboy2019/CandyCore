<?php
/**
 * 钩子管道（洋葱层）
 *
 * 统一管理「输出内容钩子」的注册与执行，替代散落在 View / Respond 的多套注册入口。
 *
 * 设计要点：
 * 1. **通道（channel）**：按用途分组，互不干扰
 *    - `response`：响应最终输出（HTML / JSON / 文件），在 Respond::send() 执行（2026-11-13 起唯一收敛点）
 *    - `html`：仅模板渲染结果，保留给需要在 View 阶段拿内容的场景
 * 2. **类型白名单（types）**：每个钩子可声明只处理哪些内容类型，`null` = 全部
 *    - HTML 注入类钩子应声明 `['html']`，这样 JSON 输出时自动跳过，不会破坏 `JSON.parse`
 * 3. **优先级（priority）**：数字越小越先执行，同优先级按注册顺序
 *
 * 用法：
 * ```php
 * // 只处理 HTML（安全，不会碰上 JSON）
 * HookPipeline::add('response', 'okrInjectScript', 10, ['html']);
 *
 * // 处理所有输出（务必自己判断 $ctx['is_json']）
 * HookPipeline::add('response', 'okrAppendTrace', 5);
 *
 * function okrInjectScript(string $data, array $ctx): string {
 *     return str_replace('</body>', '<script>...</script></body>', $data);
 * }
 * ```
 *
 * @package Candy\Core
 * @date 2026-11-13
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class HookPipeline
{
    /**
     * @var array<string, list<array{fn: callable|string, priority: int, types: ?list<string>}>>
     */
    private static array $hooks = [];

    /**
     * 注册钩子
     *
     * @param string          $channel  通道：response / html 或自定义
     * @param callable|string $fn       函数名或闭包，签名 fn(string $data, array $ctx): string
     * @param int             $priority 优先级，越小越先执行（默认 10）
     * @param list<string>|null $types  内容类型白名单（html/json/other），null 表示全部
     * @return void
     */
    public static function add(string $channel, callable|string $fn, int $priority = 10, ?array $types = null): void
    {
        if (!isset(self::$hooks[$channel])) {
            self::$hooks[$channel] = [];
        }

        // 去重：同一通道下同一函数只保留一份（闭包按对象标识不去重）
        if (is_string($fn)) {
            foreach (self::$hooks[$channel] as $index => $hook) {
                if ($hook['fn'] === $fn) {
                    self::$hooks[$channel][$index] = ['fn' => $fn, 'priority' => $priority, 'types' => $types];
                    self::sortChannel($channel);
                    return;
                }
            }
        }

        self::$hooks[$channel][] = ['fn' => $fn, 'priority' => $priority, 'types' => $types];
        self::sortChannel($channel);
    }

    /**
     * 移除钩子
     *
     * @param string          $channel 通道
     * @param callable|string $fn      函数名或闭包
     * @return void
     */
    public static function remove(string $channel, callable|string $fn): void
    {
        if (empty(self::$hooks[$channel])) {
            return;
        }

        self::$hooks[$channel] = array_values(array_filter(
            self::$hooks[$channel],
            static fn(array $hook): bool => !($hook['fn'] === $fn || (is_string($fn) && $hook['fn'] === $fn))
        ));
    }

    /**
     * 清空钩子
     *
     * @param string|null $channel 指定通道；null 清空全部
     * @return void
     */
    public static function clear(?string $channel = null): void
    {
        if ($channel === null) {
            self::$hooks = [];
            return;
        }

        self::$hooks[$channel] = [];
    }

    /**
     * 列出某通道已注册的钩子（调试用）
     *
     * @param string $channel 通道
     * @return list<array{fn: callable|string, priority: int, types: ?list<string>}>
     */
    public static function list(string $channel): array
    {
        return self::$hooks[$channel] ?? [];
    }

    /**
     * 按通道依次执行钩子
     *
     * @param string               $channel 通道
     * @param string               $data    待处理内容
     * @param array<string, mixed> $context 上下文（content_type / is_json / is_api 等）
     * @return string 处理后的内容
     */
    public static function run(string $channel, string $data, array $context = []): string
    {
        if (empty(self::$hooks[$channel])) {
            return $data;
        }

        $contentType = (string) ($context['content_type'] ?? 'other');

        foreach (self::$hooks[$channel] as $hook) {
            // 类型白名单：声明了限定类型且当前不匹配 → 跳过
            // 这是「JSON/API 等强结构性输出不做字符串处理」的兜底
            if ($hook['types'] !== null && !in_array($contentType, $hook['types'], true)) {
                continue;
            }

            $fn = $hook['fn'];

            try {
                if (is_string($fn)) {
                    if (!function_exists($fn)) {
                        continue;
                    }
                    $data = (string) $fn($data, $context);
                } elseif (is_callable($fn)) {
                    $data = (string) $fn($data, $context);
                }
            } catch (\Throwable $e) {
                // 单个钩子异常不应破坏整个响应
                Debug::addMsg('HookPipeline 钩子执行失败(' . $channel . '): ' . $e->getMessage(), 4);
            }
        }

        return $data;
    }

    /**
     * 按优先级排序某通道
     *
     * @param string $channel 通道
     * @return void
     */
    private static function sortChannel(string $channel): void
    {
        usort(
            self::$hooks[$channel],
            static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']
        );
    }
}
