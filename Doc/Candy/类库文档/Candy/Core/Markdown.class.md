# Markdown 类

## 概述

*
 * Markdown类 - Candy框架文档渲染与博客系统
 * 
 * 提供Markdown文档解析、渲染和导航生成功能，支持两种运行模式：框架文档模式和博客模式。
 * 自动处理文档目录结构，生成导航菜单，支持上一篇/下一篇文章切换，并根据.order文件进行自定义排序。
 * 
 * 主要功能：
 * - Markdown文档解析与HTML渲染
 * - 动态菜单结构生成
 * - 目录自定义排序（基于.order文件）
 * - 支持框架文档和个人博客两种模式
 * - 自动处理目录默认页面和文件路径解析
 * - 提供页面导航功能（上一篇/下一篇）
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com) $
 * @date 2019-08-01 23:00:09 $   
 * @final 该类不允许被继承

## 主要方法


### start()


### /**
     * 获取并解析Markdown文件内容
     * 
     * 根据当前URL请求解析文件路径，读取并解析Markdown文件，生成HTML内容
     * 处理URL路径规范化、文档目录默认页面逻辑、菜单生成和模板渲染
     * 根据运行模式（文档或博客）采用不同的处理流程和模板
     * 
     * @return string 生成的HTML内容，包含解析后的Markdown和导航菜单
     */
    private static function getMarkdownContent():()


### /**
     * 生成动态菜单结构
     * 
     * 获取当前访问路径，规范化处理后调用getNavList方法生成完整的菜单HTML结构
     * 同时返回当前页面的上一篇和下一篇文章信息，用于页面导航功能
     * 
     * @return array 包含菜单HTML、上一篇页面信息和下一篇页面信息的数组
     */
    private static function generateMenuStructure(string $search):()


### /**
     * 加载.order文件中的排序规则
     * 
     * 读取指定目录下的.order文件，解析其中的内容作为目录和文件的排序规则
     * 忽略空行和以#开头的注释行，返回有效的排序项数组
     * 
     * @param string $dirPath 要加载.order文件的目录路径
     * @return array 排序规则数组，包含需要按顺序显示的文件名和目录名
     */
    private static function loadOrderList(string $dirPath):()


### /**
     * 目录和文件排序处理
     * 
     * 根据自定义排序规则（.order文件）或默认规则对目录和文件进行排序
     * 首先按照.order文件中指定的顺序排列，如果没有自定义排序则按默认规则（目录在前，文件在后）
     * 
     * @param string $basePath 要排序的基础目录路径
     * @param array $customOrder 自定义排序规则数组
     * @return array 排序后的目录和文件路径数组
     */
    private static function sortDirs(string $basePath, array $customOrder):()


### /**
     * 获取目录导航列表并生成HTML结构
     * 
     * 递归遍历目录结构，生成嵌套的导航菜单HTML，并计算当前页面的上一篇和下一篇文章信息
     * 处理活动状态标识，为当前访问的页面添加active类
     * 
     * @param string $currentPath 当前访问的URL路径
     * @param string $itemPath 当前处理的目录路径
     * @return array 包含导航菜单HTML、上一篇页面信息和下一篇页面信息的数组
     */
    private static function getNavList(string $currentPath, string $itemPath, string $search):()

