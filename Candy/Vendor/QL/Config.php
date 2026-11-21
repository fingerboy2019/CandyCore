<?php
/***
 * Candy框架 QueryList配置库
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2023-06-28 10:14:25 $   
 */
 
namespace QL;
use \Candy\Extend\Collection;

/**
 * QueryList配置类
 * 用于管理QueryList的插件和自定义方法绑定
 */
class Config
{
    /**
     * Config类单例实例
     * @var null|Config
     */
    protected static $instance = null;

    /**
     * 插件集合
     * @var Collection
     */
    protected $plugins;
    
    /**
     * 自定义方法绑定集合
     * @var Collection
     */
    protected $binds;
	
    /**
     * Config构造函数
     * 初始化插件和绑定集合
     */
    public function __construct()
    {
        $this->plugins = new Collection();
        $this->binds = new Collection();
    }

    /**
     * 获取Config单例对象
     *
     * @return Config
     */
    public static function getInstance(): Config
    {
        self::$instance || self::$instance = new self();
        return self::$instance;
    }

    /**
     * 安装插件
     *
     * @param string|array $plugins 插件名称或插件数组
     * @param array ...$opt 插件选项
     * @return $this
     */
    public function use($plugins,...$opt): Config
    {
        if(is_string($plugins)){
            $this->plugins->push([$plugins,$opt]);
        }else{
            $this->plugins = $this->plugins->merge($plugins);
        }
        return $this;
    }

    /**
     * 绑定自定义方法
     *
     * @param string $name 方法名称
     * @param \Closure $provider 方法实现闭包
     * @return $this
     */
    public function bind(string $name, \Closure $provider): Config
    {
        $this->binds[$name] = $provider;
        return $this;
    }

    /**
     * 引导配置，安装插件和绑定方法
     *
     * @param QueryList $queryList QueryList实例
     * @return void
     */
    public function bootstrap(QueryList $queryList): void
    {
        $this->installPlugins($queryList);
        $this->installBind($queryList);
    }

    /**
     * 安装所有插件
     *
     * @param QueryList $queryList QueryList实例
     * @return void
     */
    protected function installPlugins(QueryList $queryList): void
    {
        $this->plugins->each(function($plugin) use($queryList){
            if(is_string($plugin)){
                $queryList->use($plugin);
            }else{
                $queryList->use($plugin[0],...$plugin[1]);
            }
        });
    }

    /**
     * 安装所有自定义方法绑定
     *
     * @param QueryList $queryList QueryList实例
     * @return void
     */
    protected function installBind(QueryList $queryList): void
    {
        $this->binds->each(function ($provider,$name) use($queryList){
            $queryList->bind($name,$provider);
        });
    }

}