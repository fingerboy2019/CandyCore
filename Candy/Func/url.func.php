<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * URL组装 支持不同URL模式
 *
 * 委托 {@see \Candy\Core\Trait\Url\UrlGenerator::build()} 实现；实例由 {@see N()} 容器解析。
 * 支持PATHINFO、REWRITE、GET等多种URL模式，并兼容各种特殊格式的URL构建需求。
 *
 * 使用示例：
 * - 基本URL: U('Index/index') - 生成控制器/操作的URL
 * - 带参数: U('User/detail', ['id' => 1]) - 生成带参数的URL
 * - 锚点和域名: U('Article/read#section1@blog.example.com') - 生成带锚点和指定域名的URL
 * - 跨应用: U('Admin://User/index') - 生成跨应用的URL
 * - 二维数组参数: U(['Index/index', ['id' => 1], 'html', true]) - 使用数组方式传递所有参数
 *
 * @param mixed ...$params 可变参数列表，支持多种传参方式：
 *                        - 单参数模式: U('Index/index')
 *                        - 双参数模式: U('Index/index', ['id' => 1])
 *                        - 数组模式: U(['Index/index', ['id' => 1], 'html', true])
 * @return string 组装好的URL字符串
 */
function U(...$params): string
{
	return N(\Candy\Core\Trait\Url\UrlGenerator::class)->build(...$params);
}
