# Webdav 类

## 概述

* WebDAV协议客户端操作类
 * 
 * 提供对WebDAV服务器的完整操作支持，包括文件/目录属性获取、创建目录、
 * 文件移动/重命名、复制、锁定和解锁等功能。继承自Http类，支持各种HTTP请求选项。
 * 
 * 主要功能特点：
 * - 支持PROPFIND、MKCOL、MOVE、COPY、LOCK、UNLOCK等WebDAV方法
 * - 自动解析XML响应内容，返回结构化数据
 * - 支持自定义HTTP请求头和选项
 * - 提供资源锁定和解锁机制，保证并发操作安全
 * 
 * 使用示例：
 * ```php
 * // 获取文件/目录属性
 * $result = Webdav::propfind('https://example.com/webdav/');
 * 
 * // 创建目录
 * Webdav::mkcol('https://example.com/webdav/new_folder/');
 * 
 * // 移动/重命名文件
 * Webdav::move('https://example.com/webdav/file.txt', '

## 主要方法


### propfind()


### mkcol()


### move()


### copy()


### lock()


### unlock()


### /**
     * 解析WebDAV PROPFIND方法返回的XML响应内容
     * 
     * 该私有方法负责将WebDAV服务器返回的XML格式响应解析为结构化的PHP数组，
     * 提取出资源的各种属性信息，如文件路径、显示名称、内容长度、创建日期等。
     * 
     * @param string $xml WebDAV服务器返回的XML响应内容
     * @return array 返回解析后的资源属性数组，每个元素包含一个资源的完整属性
     */
    private static function parsePropfindResponse(string $xml):()

