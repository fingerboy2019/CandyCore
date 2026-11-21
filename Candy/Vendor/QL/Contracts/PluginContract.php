<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/22
 */

namespace QL\Contracts;

use \QL\QueryList;

/**
 * 插件契约接口
 * 定义插件必须实现的安装方法
 */
interface PluginContract
{
    /**
     * 安装插件
     *
     * @param QueryList $queryList QueryList实例
     * @param array ...$opt 插件选项
     * @return mixed
     */
    public static function install(QueryList $queryList,...$opt);
}