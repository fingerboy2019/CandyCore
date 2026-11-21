<?php
/***
 * 切面运行时（编译期织入后被生成的代码调用）
 *
 * 编译产物长这样（以 save 方法为例）：
 *
 *   public function save(array $data): int
 *   {
 *       $__asp = \Candy\Core\Aspect::begin(__CLASS__, 'save', func_get_args());
 *       try {
 *           \Candy\Core\Aspect::before($__asp);
 *           $__ret = (function () use ($data) {
 *               ... 原方法体 ...
 *           })();
 *           \Candy\Core\Aspect::after($__asp, $__ret);
 *           return $__ret;
 *       } catch (\Throwable $__e) {
 *           \Candy\Core\Aspect::onError($__asp, $__e);
 *           throw $__e;
 *       }
 *   }
 *
 * 关键设计：
 *   · 切面实例按类缓存（同一个切面类只 new 一次），避免每次调用都构造
 *   · 没有注册切面时 begin() 返回 null，后续 fire 直接 return —— 零开销
 *   · 切面里抛异常会中断原方法（before 阶段），这是有意的
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-14 11:12:00 $
 *
 * @package Candy\Core
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Aspect
{
    /** @var array<string, AspectInterface> 切面实例池，key = 类名 */
    private static array $instances = [];

    /** @var array<string, array<int, string>> 每个方法的切面类名（已按优先级排好），key = "Class::method" */
    private static array $registry = [];

    /** @var bool 是否开启（可用配置关闭，默认开） */
    private static ?bool $enabled = null;

    /**
     * 注册某个方法的切面列表（编译期生成的代码会调这个）
     *
     * @param string $class    短类名或 FQCN
     * @param string $method   方法名
     * @param array  $aspects  [[类名, 优先级], ...]
     * @return void
     */
    public static function register(string $class, string $method, array $aspects): void
    {
        $key = $class . '::' . $method;
        if (isset(self::$registry[$key])) {
            return;   // 已注册（同一请求内重复 include 时幂等）
        }
        usort($aspects, static fn($a, $b) => ((int) ($b[1] ?? 0)) <=> ((int) ($a[1] ?? 0)));
        self::$registry[$key] = array_map(static fn($a) => (string) ($a[0] ?? ''), $aspects);
    }

    /**
     * 方法进入：准备上下文
     *
     * @param string      $class  类名（FQCN，如 APP\Service\Home\TicketsService）
     * @param string      $method 方法名
     * @param array       $args   入参
     * @param object|null $target 对象实例（$this）；静态方法不织入，故正常非 null
     * @return array|null 没有切面时返回 null（后续 fire 会快速返回）
     */
    public static function begin(string $class, string $method, array $args = [], ?object $target = null): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $key = $class . '::' . $method;
        if (empty(self::$registry[$key])) {
            return null;
        }
        return [
            'class'  => $class,
            'method' => $method,
            'args'   => $args,
            'start'  => microtime(true),
            'key'    => $key,
            // 对象实例：事务/缓存/重试类切面靠它操作业务对象。
            // ⚠️ 切面不要长期持有，只在本次调用内用完即弃，避免引用滞留。
            'target' => $target,
        ];
    }

    /**
     * 触发 before 钩子
     *
     * @param array|null $ctx begin() 的返回值
     * @return void
     */
    public static function before(?array $ctx): void
    {
        if ($ctx === null) {
            return;
        }
        foreach (self::aspects($ctx['key'] ?? '') as $inst) {
            try {
                $inst->before($ctx);
            } catch (\Throwable $e) {
                self::logError($ctx, 'before', $e);
                throw $e;   // before 抛异常 = 中断原方法（有意设计）
            }
        }
    }

    /**
     * 触发 after 钩子（能拿到并修改返回值）
     *
     * @param array|null $ctx begin() 的返回值
     * @param mixed      $ret 返回值（引用传递）
     * @return void
     */
    public static function after(?array $ctx, mixed &$ret): void
    {
        if ($ctx === null) {
            return;
        }
        foreach (self::aspects($ctx['key'] ?? '') as $inst) {
            try {
                $inst->after($ctx, $ret);
            } catch (\Throwable $e) {
                // after 抛异常不影响原方法的返回值（已经算出来了），只记日志
                self::logError($ctx, 'after', $e);
            }
        }
    }

    /**
     * 统一执行入口：跑 before → around 链 → 原方法 → after，异常走 onError
     *
     * 织入产物只调这一个方法，切面类型（三段式 / 环绕式）由这里在运行时分发，
     * 所以新增切面类型不用再改编译器。
     *
     * 执行顺序：
     *   ① before 切面（priority 大的先，抛异常中断原方法）
     *   ② around 切面按 priority 嵌套（大的在外层），最内层是 $body
     *   ③ after 切面（拿到并可改返回值）
     *   ④ 任一步异常 → onError 切面跑完后原样抛出
     *
     * @param array|null $ctx  begin() 的返回值；null 表示没有切面
     * @param callable   $body 原方法体（闭包，调用它才真正执行）
     * @return mixed 原方法返回值（或 around 切面决定的值）
     * @throws \Throwable 原方法或 before/around 抛出的异常
     */
    public static function call(?array $ctx, callable $body): mixed
    {
        if ($ctx === null) {
            return $body();
        }
        $aspects = self::aspects($ctx['key'] ?? '');
        if ($aspects === []) {
            return $body();
        }

        // ① 三段式 before 先跑（保持既有语义：抛异常 = 中断原方法）
        foreach ($aspects as $inst) {
            if ($inst instanceof AroundAspectInterface) {
                continue;
            }
            try {
                $inst->before($ctx);
            } catch (\Throwable $e) {
                self::logError($ctx, 'before', $e);
                throw $e;
            }
        }

        // ② 组装 around 链：$aspects 已是 priority 降序，反转后逐个包裹
        //    结果：priority 大的在最外层，与 before 的执行顺序一致
        $around = [];
        foreach ($aspects as $inst) {
            if ($inst instanceof AroundAspectInterface) {
                $around[] = $inst;
            }
        }
        $pipeline = $body;
        foreach (array_reverse($around) as $inst) {
            $inner    = $pipeline;
            $pipeline = static fn() => $inst->around($ctx, $inner);
        }

        // ③ 执行 + 收尾
        try {
            $ret = $pipeline();
            foreach ($aspects as $inst) {
                if ($inst instanceof AroundAspectInterface) {
                    continue;
                }
                try {
                    $inst->after($ctx, $ret);
                } catch (\Throwable $e) {
                    // after 抛异常不影响返回值（值已经算出来了），只记日志
                    self::logError($ctx, 'after', $e);
                }
            }
            return $ret;
        } catch (\Throwable $e) {
            foreach ($aspects as $inst) {
                try {
                    $inst->onError($ctx, $e);
                } catch (\Throwable $inner) {
                    self::logError($ctx, 'onError', $inner);
                }
            }
            throw $e;
        }
    }

    /**
     * 触发 onError 钩子
     *
     * @param array|null $ctx begin() 的返回值
     * @param \Throwable $e   原方法抛出的异常
     * @return void
     */
    public static function onError(?array $ctx, \Throwable $e): void
    {
        if ($ctx === null) {
            return;
        }
        foreach (self::aspects($ctx['key'] ?? '') as $inst) {
            try {
                $inst->onError($ctx, $e);
            } catch (\Throwable $inner) {
                self::logError($ctx, 'onError', $inner);
            }
        }
    }

    /**
     * 清空实例池与注册表（测试用 / 长驻进程重载用）
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$instances = [];
        self::$registry  = [];
        self::$enabled   = null;
    }

    /**
     * 取某个方法对应的切面实例列表
     *
     * @param string $key "Class::method"
     * @return array<int, AspectInterface>
     */
    private static function aspects(string $key): array
    {
        if ($key === '' || empty(self::$registry[$key])) {
            return [];
        }
        $out = [];
        foreach (self::$registry[$key] as $cls) {
            if ($cls === '') {
                continue;
            }
            if (!isset(self::$instances[$cls])) {
                try {
                    // 短名解析：优先 replenishNamespace（Source/Class 下的类自动补 APP\Extend\）
                    $real = self::resolveClass($cls);
                    if ($real === null) {
                        self::logRaw('[candy-aop] 切面类不存在且无法解析：' . $cls);
                        continue;
                    }
                    $obj = new $real();
                    // 三段式切面实现 AspectInterface，环绕式切面实现 AroundAspectInterface，
                    // 两种都必须放行 —— 否则 around 切面会被静默跳过（事务不生效且无任何报错）。
                    if (!$obj instanceof AspectInterface && !$obj instanceof AroundAspectInterface) {
                        continue;
                    }
                    self::$instances[$cls] = $obj;
                } catch (\Throwable $e) {
                    self::logRaw('[candy-aop] 切面实例化失败 ' . $cls . ': ' . $e->getMessage());
                    continue;
                }
            }
            $out[] = self::$instances[$cls];
        }
        return $out;
    }

    /**
     * 把切面类名解析成真实可加载的类名
     *
     * 支持三种写法（都写在 #[Aspect(...)] 里）：
     *   · 全名        \APP\Extend\LogAspect::class
     *   · 短名        LogAspect::class        → replenishNamespace 补成 APP\Extend\LogAspect
     *   · 字符串      'LogAspect'
     *
     * replenishNamespace 的 default 分支会检查 Source/Class/Xxx.class.php，
     * 命中就补 \APP\Extend\ —— 效果等同于把类注册进 N() 的短名表。
     *
     * @param string $cls
     * @return string|null 解析成功返回类名，失败返回 null
     */
    private static function resolveClass(string $cls): ?string
    {
        $cls = trim($cls);
        if ($cls === '') {
            return null;
        }

        // 1) 本身就是完整可加载的类
        $bare = ltrim($cls, '\\');
        if (class_exists($bare)) {
            return $bare;
        }

        // 2) 走框架的短名补全（N() 用的同一套）
        if (function_exists('replenishNamespace')) {
            try {
                $full = ltrim((string) replenishNamespace($cls), '\\');
                if ($full !== '' && class_exists($full)) {
                    return $full;
                }
            } catch (\Throwable $ignored) {
            }
        }

        return null;
    }

    /**
     * 是否开启（读配置 aop_enable，默认开）
     *
     * @return bool
     */
    private static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }
        $v = function_exists('C') ? C('AOP_ENABLE') : null;
        if ($v === null || $v === '') {
            self::$enabled = true;    // 默认开（没有切面注解时零开销）
        } else {
            self::$enabled = in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
        }
        return self::$enabled;
    }

    /**
     * 记录切面自身出错（不影响业务）
     *
     * @param array  $ctx
     * @param string $phase
     * @param \Throwable $e
     * @return void
     */
    private static function logError(array $ctx, string $phase, \Throwable $e): void
    {
        self::logRaw(sprintf(
            '[candy-aop] %s 阶段出错 %s::%s: %s',
            $phase,
            (string) ($ctx['class'] ?? ''),
            (string) ($ctx['method'] ?? ''),
            $e->getMessage()
        ));
    }

    /**
     * 落日志（写到 Runtime/Logs/aop-YYYY-MM-DD.log，不打断业务）
     *
     * @param string $msg
     * @return void
     */
    private static function logRaw(string $msg): void
    {
        try {
            $dir = defined('CANDYROOT') ? CANDYROOT . 'Runtime/Logs/' : sys_get_temp_dir() . '/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents(
                $dir . 'aop-' . date('Y-m-d') . '.log',
                '[' . date('H:i:s') . '] ' . $msg . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $ignored) {
        }
    }
}
