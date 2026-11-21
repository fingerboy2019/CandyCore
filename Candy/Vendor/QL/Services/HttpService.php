<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/22
 */

namespace QL\Services;

use \QL\QueryList;
use \Candy\Extend\Network\Http;

/**
 * HTTP服务类
 * 提供HTTP请求功能，包括GET、POST和POST JSON请求
 */
class HttpService
{
    /**
     * 发送GET请求
     *
     * @param QueryList $ql QueryList实例
     * @param string $url 请求URL
     * @param string|array $args 请求参数
     * @param array $otherArgs 其他请求参数
     * @return QueryList
     */
    public static function get(QueryList $ql,$url,$args = '',$otherArgs = [])
    {
        $html = Http::get($url,$args,$otherArgs);
        $ql->setHtml($html['content']);
        return $ql;
    }

    /**
     * 发送POST请求
     *
     * @param QueryList $ql QueryList实例
     * @param string $url 请求URL
     * @param string|array $args 请求参数
     * @param array $otherArgs 其他请求参数
     * @return QueryList
     */
    public static function post(QueryList $ql,$url,$args = '',$otherArgs = [])
    {
        $html = Http::post($url,$args,$otherArgs);
        $ql->setHtml($html['content']);
        return $ql;
    }

    /**
     * 发送POST JSON请求
     *
     * @param QueryList $ql QueryList实例
     * @param string $url 请求URL
     * @param string|array $args 请求参数
     * @param array $otherArgs 其他请求参数
     * @return QueryList
     */
    public static function postJson(QueryList $ql,$url,$args = '',$otherArgs = [])
    {
        $html = Http::post($url,$args,$otherArgs);
        $ql->setHtml($html['content']);
        return $ql;
    }
}
