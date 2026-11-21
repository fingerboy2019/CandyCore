<?php
/***
 * Candy框架 QueryList核心库
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2023-06-28 10:14:25 $   
 */
 
namespace QL;

use QL\Contracts\ServiceProviderContract;
use QL\Providers\{SystemServiceProvider,EncodeServiceProvider,HttpServiceProvider,PluginServiceProvider};

/**
 * Kernel类
 * QueryList的核心内核，负责服务提供者的注册和管理
 */
class Kernel
{
    /**
     * 服务提供者列表
     * @var array
     */
    protected $providers = [
        SystemServiceProvider::class,
        HttpServiceProvider::class,
        EncodeServiceProvider::class,
        PluginServiceProvider::class
    ];

    /**
     * 服务绑定集合
     * @var \Extend\Collection
     */
    protected $binds;
    
    /**
     * QueryList实例
     * @var QueryList
     */
    protected $ql;
	
    /**
     * Kernel构造函数
     *
     * @param QueryList $ql QueryList实例
     */
    public function __construct(QueryList $ql)
    {
        $this->ql = $ql;
        $this->binds = new \Candy\Extend\Collection();
    }

    /**
     * 引导内核，注册服务提供者
     *
     * @return Kernel
     */
    public function bootstrap(): Kernel
    {
        //注册服务提供者
        $this->registerProviders();
        return $this;
    }

    /**
     * 注册所有服务提供者
     *
     * @return void
     */
    public function registerProviders(): void
    {
        foreach ($this->providers as $provider) {
            $this->register(new $provider());
        }
    }

    /**
     * 绑定服务
     *
     * @param string $name 服务名称
     * @param \Closure $provider 服务提供者闭包
     * @return void
     */
    public function bind(string $name, \Closure $provider): void
    {
        $this->binds[$name] = $provider;
    }

    /**
     * 获取服务
     *
     * @param string $name 服务名称
     * @return mixed
     */
    public function getService(string $name)
    {
        if(!$this->binds->offsetExists($name)){
            \Candy\Core\Debug::showMessage("Service: {$name} not found!");
        }
        return $this->binds[$name];
    }

    /**
     * 注册单个服务提供者
     *
     * @param ServiceProviderContract $instance 服务提供者实例
     * @return void
     */
    private function register(ServiceProviderContract $instance): void
    {
        $instance->register($this);
    }
}
