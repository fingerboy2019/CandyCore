<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 18/12/10
 * Time: 下午7:05
 */

namespace QL\Services;

use \Candy\Core\Debug;
use \QL\QueryList;
use \Candy\Extend\Network\CurlMulti;

/**
 * 多线程请求服务类
 * 提供多线程HTTP请求功能，支持并发发送多个请求
 *
 * @method MultiRequestService withHeaders($headers) 设置请求头
 * @method MultiRequestService withOptions($options) 设置请求选项
 * @method MultiRequestService concurrency($concurrency) 设置并发数
 */
class MultiRequestService
{
    /**
     * QueryList实例
     * @var QueryList
     */
    protected $ql;
    
    /**
     * 多线程请求实例
     * @var CurlMulti
     */
    protected $multiRequest;
    
    /**
     * 请求方法
     * @var string
     */
    protected $method;
    
    /**
     * 请求URL列表
     * @var array
     */
    protected $urls;

    /**
     * MultiRequestService构造函数
     *
     * @param QueryList $ql QueryList实例
     * @param string $method 请求方法
     * @param array $urls 请求URL列表
     */
    public function __construct(QueryList $ql, $method, $urls)
    {
        $this->ql = $ql;
        $this->method = $method;
        $this->urls = $urls;
		$this->multiRequest = N('CurlMulti');
    }

    /**
     * 调用多线程请求实例的方法
     *
     * @param string $name 方法名
     * @param array $arguments 方法参数
     * @return $this
     */
    public function __call($name, $arguments)
    {
        $this->multiRequest = N('CurlMulti')->$name(...$arguments);
        return $this;
    }

    /**
     * 设置请求成功回调函数
     *
     * @param \Closure $success 成功回调函数
     * @return $this
     */
    public function success(\Closure $success)
    {
        $this->multiRequest = $this->multiRequest->success(function(CurlMulti $response, $index) use ($success){
           $this->ql->setHtml((String)$response->getResult());
           $success($this->ql,$response, $index);
        });
        return $this;
    }

    /**
     * 设置请求失败回调函数
     *
     * @param \Closure $error 失败回调函数
     * @return $this
     */
    public function error(\Closure $error)
    {
        $this->multiRequest = $this->multiRequest->error(function(Debug $reason, $index) use ($error){
            $error($this->ql,$reason, $index);
        });
        return $this;
    }

    /**
     * 发送多线程请求
     *
     * @return void
     */
    public function send()
    {
        $this->multiRequest->{$this->method}($this->urls);
    }
}