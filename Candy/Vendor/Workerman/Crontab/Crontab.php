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
namespace Workerman\Crontab;
use \Workerman\Timer;

/**
 * Class Crontab
 * 定时任务类，用于处理基于 cron 表达式的定时任务
 * @package Workerman\Crontab
 */
class Crontab
{
    /**
     * cron 表达式规则
     * @var string
     */
    protected $_rule;

    /**
     * 定时任务回调函数
     * @var callable
     */
    protected $_callback;

    /**
     * 任务名称
     * @var string
     */
    protected $_name;

    /**
     * 任务ID
     * @var int
     */
    protected $_id;

    /**
     * 所有定时任务实例数组
     * @var array
     */
    protected static $_instances = [];

    /**
     * Crontab constructor.
     * 初始化定时任务
     * @param string $rule cron 表达式规则
     * @param callable $callback 定时任务回调函数
     * @param string $name 任务名称（可选）
     */
    public function __construct($rule, $callback, $name = '')
    {
        $this->_rule = $rule;
        $this->_callback = $callback;
        $this->_name = $name;
        $this->_id = static::createId();
        static::$_instances[$this->_id] = $this;
        static::tryInit();
        static::schedule($this);
    }

    /**
     * 获取 cron 表达式规则
     * @return string
     */
    public function getRule()
    {
        return $this->_rule;
    }

    /**
     * 获取任务回调函数
     * @return callable
     */
    public function getCallback()
    {
        return $this->_callback;
    }

    /**
     * 获取任务名称
     * @return string
     */
    public function getName()
    {
        return $this->_name;
    }

    /**
     * 获取任务ID
     * @return int
     */
    public function getId()
    {
        return $this->_id;
    }

    /**
     * 销毁当前定时任务
     * @return bool 销毁是否成功
     */
    public function destroy()
    {
        return static::remove($this->_id);
    }

    /**
     * 获取所有定时任务实例
     * @return array 所有定时任务实例数组
     */
    public static function getAll()
    {
        return static::$_instances;
    }

    /**
     * 移除指定的定时任务
     * @param mixed $id 任务ID或Crontab实例
     * @return bool 移除是否成功
     */
    public static function remove($id)
    {
        if ($id instanceof Crontab) {
            $id = $id->getId();
        }
        if (!isset(static::$_instances[$id])) {
            return false;
        }
        unset(static::$_instances[$id]);
        return true;
    }

    /**
     * 创建唯一任务ID
     * @return int 唯一任务ID
     */
    protected static function createId()
    {
        static $id = 0;
        return ++$id;
    }

    /**
     * 尝试初始化定时任务调度器（仅标记一次）
     */
    protected static function tryInit()
    {
        static $inited = false;
        if ($inited) {
            return;
        }
        $inited = true;
    }

    /**
     * 为单个任务注册下次执行定时器（支持秒级表达式）
     *
     * @param Crontab $crontab 任务实例
     * @return void
     */
    protected static function schedule(self $crontab): void
    {
        $parser = new Parser();
        $arm = null;
        $arm = function () use ($crontab, $parser, &$arm) {
            if (!isset(static::$_instances[$crontab->getId()])) {
                return;
            }
            $rule = $crontab->getRule();
            $cb = $crontab->getCallback();
            if (!$cb || !$rule) {
                return;
            }
            $next = $parser->nextRunTimestamp($rule, time());
            if ($next <= 0) {
                return;
            }
            $delay = max(0.000001, $next - microtime(true));
            Timer::add($delay, function () use ($crontab, $cb, $arm) {
                if (!isset(static::$_instances[$crontab->getId()])) {
                    return;
                }
                \call_user_func($cb);
                $arm();
            }, null, false);
        };
        $arm();
    }

}
