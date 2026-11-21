<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/20
 */

namespace QL\Contracts;

use \QL\Kernel;

/**
 * 服务提供者契约接口
 * 定义服务提供者必须实现的注册方法
 */
interface ServiceProviderContract
{
    /**
     * 注册服务
     *
     * @param Kernel $kernel Kernel实例
     * @return mixed
     */
    public function register(Kernel $kernel);
}