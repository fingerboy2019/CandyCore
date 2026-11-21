<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/22
 */

namespace QL\Services;

use \QL\QueryList;

/**
 * 插件服务类
 * 提供插件安装功能，支持安装单个插件或多个插件
 */
class PluginService
{
    /**
     * 安装插件
     *
     * @param QueryList $queryList QueryList实例
     * @param string|array $plugins 插件类名或插件类名数组
     * @param array ...$opt 插件安装选项
     * @return QueryList
     */
    public static function install(QueryList $queryList, $plugins, ...$opt)
    {
        if(is_array($plugins))
        {
            // 安装多个插件
            foreach ($plugins as $plugin) {
                $plugin::install($queryList);
            }
        }else{
            // 安装单个插件
            $plugins::install($queryList,...$opt);
        }
        return $queryList;
    }
}