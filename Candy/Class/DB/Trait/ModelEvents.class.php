<?php
/***
 * 模型事件
 *
 * 业务说明：在 Model 写操作（insert / update / delete）前后触发钩子。
 * 支持 TP 风格的 $beforeInsert = ['methodName'] 声明（可改数据、可中断），
 * 同时向框架 Event 系统广播 model.* 与 {Model}.* 事件（纯通知）。
 *
 * 使用示例：
 * ```php
 * class Users extends BaseModel
 * {
 *     protected array $beforeInsert = ['fillTimestamp'];
 *     protected array $afterInsert  = ['clearCache'];
 *
 *     protected function fillTimestamp(array &$data): bool
 *     {
 *         $data['created_at'] = date('Y-m-d H:i:s');
 *         return true;                      // false = 中断本次插入
 *     }
 * }
 * ```
 *
 * 全局监听（纯通知，不能改数据）：
 * ```php
 * \Candy\Core\Event::listen('model.afterInsert', fn($p) => ...);
 * \Candy\Core\Event::listen('Users.afterInsert', fn($p) => ...);   // 精确到模型
 * ```
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-12 20:30:00 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait ModelEvents
{
    /** 支持的模型事件名 */
    private const MODEL_EVENT_NAMES = [
        'beforeInsert', 'afterInsert',
        'beforeUpdate', 'afterUpdate',
        'beforeDelete', 'afterDelete',
    ];

    /** 触发模型事件
     *
     * BaseModel 与 DBServer 共用本 trait：
     * - 在 Model 上调用时 $this 即 Model；
     * - 在 DBServer 上调用时通过 $modelObserver 转发到所属 Model
     *   （insert/update/delete 实际在 DBServer 执行，钩子却声明在 Model 上）。
     *
     * @param string $event   事件名
     * @param array  $payload 载荷（引用传递，钩子方法可原地修改）
     * @return bool 返回 false 表示调用方应中断本次操作
     */
    protected function fireModelEvent(string $event, array &$payload = []): bool
    {
        if (!in_array($event, self::MODEL_EVENT_NAMES, true)) {
            return true;
        }

        $model = $this->resolveEventOwner();

        // 模型级开关：$modelEvents = false 整类关闭（protected 属性需反射读）
        if ($this->readModelProperty($model, 'modelEvents') === false) {
            return true;
        }

        // ① TP 风格：$beforeInsert = ['method1', 'method2']；返回 false 中断
        $hooks = $this->readModelProperty($model, $event);
        if ($hooks !== null) {
            foreach ((array) $hooks as $method) {
                if (!is_string($method) || $method === '' || !method_exists($model, $method)) {
                    continue;
                }
                if ($this->invokeModelHook($model, $method, $payload) === false) {
                    return false;
                }
            }
        }

        // ② Event 系统广播（纯通知；Event::emit 值传递，不能回写 payload）
        $this->broadcastModelEvent($event, $payload, $model);

        return true;
    }

    /**
     * 读取 Model 上的属性（支持 protected / private）
     *
     * insert/update/delete 在 DBServer 上执行，而 $beforeInsert / $modelEvents
     * 都声明在 Model 上且通常是 protected；跨类直接访问会报
     * "Cannot access protected property"，因此统一走反射。
     *
     * @param object $model Model 实例
     * @param string $key   属性名
     * @return mixed 属性值；不存在或未初始化返回 null
     */
    private function readModelProperty(object $model, string $key): mixed
    {
        if (!property_exists($model, $key)) {
            return null;
        }

        try {
            $prop = new \ReflectionProperty($model, $key);
            if (method_exists($prop, 'isInitialized') && !$prop->isInitialized($model)) {
                return null;
            }

            return $prop->getValue($model);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 解析事件归属对象：DBServer 上取 modelObserver，否则就是自己 */
    private function resolveEventOwner(): object
    {
        if (property_exists($this, 'modelObserver') && $this->modelObserver instanceof \Candy\Extend\DB\BaseModel) {
            return $this->modelObserver;
        }

        return $this;
    }

    /**
     * 调用 Model 上的钩子方法
     *
     * 钩子通常是 protected，跨对象不可直接调用，这里用 Closure::bindTo
     * 把作用域绑到 Model 类上，从而可以访问 protected/private 方法。
     *
     * @param object $model   Model 实例
     * @param string $method  方法名
     * @param array  $payload 载荷（引用）
     * @return mixed 钩子返回值
     */
    private function invokeModelHook(object $model, string $method, array &$payload): mixed
    {
        try {
            $closure = \Closure::bind(
                function (array &$data) use ($method) {
                    return $this->{$method}($data);
                },
                $model,
                get_class($model)
            );

            return $closure($payload);
        } catch (\Throwable $e) {
            // 钩子异常按"中断"处理，避免带着半成品数据继续落库
            if (method_exists($this, 'setMsg')) {
                static::setMsg('模型事件 ' . $method . ' 执行异常：' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * 向框架 Event 系统广播模型事件
     *
     * 同时发两个 tag：model.beforeInsert（全模型）、Users.beforeInsert（单模型）
     *
     * @param string $event   事件名
     * @param array  $payload 载荷
     * @param object $model   归属 Model
     * @return void
     */
    private function broadcastModelEvent(string $event, array $payload, object $model): void
    {
        if (!class_exists('\Candy\Core\Event')) {
            return;
        }

        $payload['__model'] = get_class($model);
        $payload['__event'] = $event;

        try {
            \Candy\Core\Event::emit('model.' . $event, $payload);

            $parts = explode('\\', get_class($model));
            $short = (string) end($parts);
            $short = (string) preg_replace('/Model$/', '', $short);
            if ($short !== '' && $short !== 'Base') {
                \Candy\Core\Event::emit($short . '.' . $event, $payload);
            }
        } catch (\Throwable $e) {
            // 事件广播失败不影响主流程
        }
    }
}
