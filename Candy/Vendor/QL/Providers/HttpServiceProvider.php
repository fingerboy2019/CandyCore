<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/22
 */

namespace QL\Providers;

use \QL\Kernel;
use \QL\Contracts\ServiceProviderContract;
use \QL\Services\{HttpService,MultiRequestService};

/**
 * HTTP服务提供者
 * 注册HTTP请求服务，包括GET、POST和多线程请求
 */
class HttpServiceProvider implements ServiceProviderContract
{
    /**
     * 注册服务
     *
     * @param Kernel $kernel Kernel实例
     * @return void
     */
    public function register(Kernel $kernel)
    {
        // 注册GET请求服务
        $kernel->bind('get',function (...$args){
           return HttpService::get($this,...$args);
        });

        // 注册POST请求服务
        $kernel->bind('post',function (...$args){
            return HttpService::post($this,...$args);
        });

        // 注册POST JSON请求服务
        $kernel->bind('postJson',function (...$args){
            return HttpService::postJson($this,...$args);
        });

        // 注册多线程GET请求服务
        $kernel->bind('multiGet',function (...$args){
            return new MultiRequestService($this,'doGet',...$args);
        });

        // 注册多线程POST请求服务
        $kernel->bind('multiPost',function (...$args){
            return new MultiRequestService($this,'doPost',...$args);
        });
    }
}