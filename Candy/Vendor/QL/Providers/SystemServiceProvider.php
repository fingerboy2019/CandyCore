<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/22
 */

namespace QL\Providers;

use \QL\Kernel;
use \QL\Contracts\ServiceProviderContract;

/**
 * 系统服务提供者
 * 注册系统级别的服务
 */
class SystemServiceProvider implements ServiceProviderContract
{
    /**
     * 注册服务
     *
     * @param Kernel $kernel Kernel实例
     * @return void
     */
    public function register(Kernel $kernel)
    {
        // 注册HTML设置服务
        $kernel->bind('html',function (...$args){
            $this->setHtml(...$args);
            return $this;
        });

        // 注册查询数据服务
        $kernel->bind('queryData',function (?\Closure $callback = null){
            return $this->query()->getData($callback)->all();
        });

        // 注册管道服务
        $kernel->bind('pipe',function (?\Closure $callback = null){
            return $callback($this);
        });

    }
}