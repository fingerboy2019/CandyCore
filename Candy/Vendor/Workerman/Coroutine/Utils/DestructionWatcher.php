<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Workerman\Coroutine\Utils;

use WeakMap;

/**
 * 对象销毁监听器
 * 
 * 用于监听对象的销毁事件，当对象被PHP垃圾回收机制回收时，执行注册的回调函数
 * 使用WeakMap实现，不会阻止对象被垃圾回收
 */
class DestructionWatcher
{
    /**
     * 存储对象与监听器的映射关系
     *
     * @var WeakMap<object, DestructionWatcher> 使用WeakMap关联对象和监听器实例
     */
    protected static WeakMap $objects;

    /**
     * 存储要执行的回调函数列表
     *
     * @var callable[] 当对象被销毁时，会执行这些回调函数
     */
    protected array $callbacks = [];

    /**
     * 构造函数
     *
     * @param callable|null $callback 可选的初始回调函数
     */
    public function __construct(?callable $callback = null)
    {
        if ($callback) {
            $this->callbacks[] = $callback;
        }
    }

    /**
     * 析构函数
     *
     * 当监听器实例被销毁时，逆序执行所有注册的回调函数
     */
    public function __destruct()
    {
        // 逆序执行回调函数，确保后注册的回调函数先执行
        foreach (array_reverse($this->callbacks) as $callback) {
            $callback();
        }
    }

    /**
     * 监听对象的销毁事件
     *
     * @param object $object 要监听的对象
     * @param callable $callback 当对象被销毁时执行的回调函数
     * @return void
     */
    public static function watch(object $object, callable $callback): void
    {
        // 初始化WeakMap
        static::$objects ??= new WeakMap();
        // 如果对象还没有关联监听器，创建一个新的监听器实例
        static::$objects[$object] ??= new static();
        // 向监听器添加回调函数
        static::$objects[$object]->callbacks[] = $callback;
    }

}