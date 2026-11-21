<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/22
 */

namespace QL\Providers;

use \QL\Kernel;
use \QL\Services\PluginService;
use \QL\Contracts\ServiceProviderContract;

/**
 * 插件服务提供者
 * 注册插件安装服务
 */
class PluginServiceProvider implements ServiceProviderContract
{
    /**
     * 注册服务
     *
     * @param Kernel $kernel Kernel实例
     * @return void
     */
    public function register(Kernel $kernel)
    {
        // 注册插件安装服务
        $kernel->bind('use',function ($plugins,...$opt){
            return PluginService::install($this,$plugins,...$opt);
        });
    }

}