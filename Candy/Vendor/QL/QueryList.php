<?php
/***
 * Candy框架 QueryList(jae-jae/QueryList优化)
 * 
 * 一个基于phpQuery的通用列表采集类
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2023-06-28 10:14:25 $   
 */

namespace QL;
use \Extend\Collection;
use \QL\Services\MultiRequestService;

/**
 * QueryList主类
 * 一个基于phpQuery的通用列表采集类，提供简洁的API用于网页数据抓取
 *
 * @method string getHtml($rel = true) 获取HTML内容
 * @method QueryList setHtml($html) 设置HTML内容
 * @method QueryList html($html) 设置HTML内容（链式调用）
 * @method Dom\Elements find($selector) 查找匹配选择器的元素
 * @method QueryList rules(array $rules) 设置爬取规则
 * @method QueryList range($range) 设置列表切片区域
 * @method QueryList removeHead() 移除HTML头部，尝试解决乱码问题
 * @method QueryList query(Closure $callback = null) 执行查询规则
 * @method Collection getData(Closure $callback = null) 获取爬取结果
 * @method Array queryData(Closure $callback = null) 获取爬取结果数组
 * @method QueryList setData(Collection $data) 设置爬取结果数据
 * @method QueryList encoding(string $outputEncoding,string $inputEncoding = null) 转换编码
 * @method QueryList get($url,$args = null,$otherArgs = []) 发送GET请求
 * @method QueryList post($url,$args = null,$otherArgs = []) 发送POST请求
 * @method QueryList postJson($url,$args = null,$otherArgs = []) 发送POST JSON请求
 * @method MultiRequestService multiGet($urls) 发送多线程GET请求
 * @method MultiRequestService multiPost($urls) 发送多线程POST请求
 * @method QueryList use($plugins,...$opt) 安装插件
 * @method QueryList pipe(Closure $callback = null) 管道操作
 */
class QueryList
{
    /**
     * 查询对象实例
     * @var Dom\Query
     */
    protected $query;
    
    /**
     * 内核实例
     * @var Kernel
     */
    protected $kernel;
    
    /**
     * QueryList单例实例
     * @var null|QueryList
     */
    protected static $instance = null;
	
    /**
     * QueryList构造函数
     * 初始化查询对象、内核和配置
     */
    public function __construct()
    {
        $this->query = N('QL\Dom\Query', [$this]);
        $this->kernel = N('QL\Kernel', [$this])->bootstrap();
        Config::getInstance()->bootstrap($this);
    }
	
    /**
     * 调用方法
     * 如果方法存在于查询对象中，则调用查询对象的方法
     * 否则，从内核获取服务并调用
     *
     * @param string $name 方法名
     * @param array $arguments 方法参数
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if(method_exists($this->query,$name)){
            $result = $this->query->$name(...$arguments);
        }else{
            $result = $this->kernel->getService($name)->call($this,...$arguments);
        }
		return $result;
    }

    /**
     * 静态方法调用
     * 创建实例并调用对应方法
     *
     * @param string $name 方法名
     * @param array $arguments 方法参数
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        $instance = N('QL\QueryList');
        return $instance->$name(...$arguments);
    }

    /**
     * 析构函数
     * 销毁对象资源
     */
    public function __destruct()
    {
        $this->destruct();
    }

    /**
     * 获取QueryList单例对象
     *
     * @return QueryList
     */
    public static function getInstance(): QueryList
    {
        self::$instance || self::$instance = N('QL\QueryList');
        return self::$instance;
    }

    /**
     * 获取Config对象
     *
     * @return Config
     */
    public static function config(): Config
    {
        return Config::getInstance();
    }

    /**
     * 销毁对象资源
     *
     * @return void
     */
    public function destruct(): void
    {
        unset($this->query);
        unset($this->kernel);
    }

    /**
     * 重置documents数组
     *
     * @return void
     */
    public static function destructDocuments(): void
    {
        N('QL\phpQuery')::$documents = [];
    }

    /**
     * 将自定义方法绑定到QueryList对象
     *
     * @param string $name 方法名称
     * @param \Closure $provide 方法实体
     * @return $this
     */
    public function bind(string $name,\Closure $provide): QueryList
    {
        $this->kernel->bind($name,$provide);
        return $this;
    }

}