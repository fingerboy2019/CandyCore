<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/20
 */

namespace QL\Providers;

use \QL\Kernel;
use \QL\Services\EncodeService;
use \QL\Contracts\ServiceProviderContract;

/**
 * 编码服务提供者
 * 注册编码转换服务
 */
class EncodeServiceProvider implements ServiceProviderContract
{
    /**
     * 注册服务
     *
     * @param Kernel $kernel Kernel实例
     * @return void
     */
    public function register(Kernel $kernel)
    {
        $kernel->bind('encoding',function (string $outputEncoding,string $inputEncoding = null){
            return EncodeService::convert($this,$outputEncoding,$inputEncoding);
        });
    }
}