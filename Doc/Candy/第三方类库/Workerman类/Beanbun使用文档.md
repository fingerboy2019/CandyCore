# Beanbun 使用文档

## 1. 类库概述

Beanbun 是一个基于 Workerman 的 PHP 爬虫框架，提供了简洁的 API 和丰富的功能，用于快速开发网络爬虫。它支持多线程爬取、队列管理、URL 去重、自定义下载器等功能。 该类库 基于 [kiddyuchina/Beanbun](https://github.com/kiddyuchina/Beanbun "Beanbun 是一个基于 Workerman 的 PHP 爬虫框架") 项目优化适配Candy框架
**主要特点：**
- 基于 Workerman，支持多线程爬取
- 内置多种队列实现（内存队列、Redis 队列）
- 支持 URL 去重和过滤
- 丰富的钩子系统，方便扩展
- 支持自定义下载器
- 支持守护进程模式

## 2. 核心类与结构

### 2.1 Beanbun 核心类

```php
namespace Beanbun;

class Beanbun
{
    // 版本信息
    const VERSION = '1.0.4';
    
    // 配置属性
    public $id = null;              // 进程 ID
    public $name = null;            // 爬虫名称
    public $max = 0;                // 最大爬取数量
    public $seed = [];              // 种子 URL
    public $daemonize = true;       // 是否以守护进程模式运行
    public $urlFilter = [];         // URL 过滤器
    public $interval = 5;           // 爬取间隔（秒）
    public $timeout = 5;            // 请求超时时间
    public $userAgent = 'pc';       // 用户代理类型
    public $logFile = '';           // 日志文件路径
    
    // 队列相关
    public $queue = '';             // 当前队列
    public $url = '';               // 当前 URL
    public $method = '';            // 请求方法
    public $options = [];           // 请求选项
    public $page = '';              // 页面内容
    
    // 钩子属性
    public $startWorker;            // 启动工作进程钩子
    public $beforeDownloadPage;     // 下载页面之前钩子
    public $downloadPage;           // 下载页面钩子
    public $afterDownloadPage;      // 下载页面之后钩子
    public $discoverUrl;            // 发现 URL 钩子
    public $afterDiscover;          // 发现 URL 之后钩子
    public $stopWorker;             // 停止工作进程钩子
    public $exceptionHandler;       // 异常处理钩子
    
    // 钩子数组
    public $hooks = [];
    public $startWorkerHooks = [];
    public $beforeDownloadPageHooks = [];
    public $downloadPageHooks = [];
    public $afterDownloadPageHooks = [];
    public $discoverUrlHooks = [];
    public $afterDiscoverHooks = [];
    public $stopWorkerHooks = [];
}
```

### 2.2 队列接口与实现

Beanbun 支持多种队列实现，通过 `QueueInterface` 接口统一管理：

```php
namespace Beanbun\Queue;

interface QueueInterface
{
    public function add($url);      // 添加 URL 到队列
    public function queued($url);   // 标记 URL 已爬取
    public function next();         // 获取下一个 URL
    public function count();        // 获取队列数量
    public function queuedCount();  // 获取已爬取数量
    public function isQueued($url); // 检查 URL 是否已爬取
}
```

**内置队列实现：**
- `MemoryQueue`：内存队列，基于 Workerman 的客户端/服务器模式
- `RedisQueue`：Redis 队列，基于 Redis 存储

### 2.3 辅助工具

- `Beanbun\Lib\Helper`：提供 URL 解析、用户代理生成等辅助功能
- `Beanbun\Lib\Client`/`Server`：内存队列的客户端和服务器实现
- `Beanbun\Lib\BloomFilter`：布隆过滤器，用于高效的 URL 去重

## 3. 安装与配置

### 3.1 环境要求

- PHP >= 5.3.3
- pcntl 扩展
- posix 扩展
- stream_socket_server 和 stream_socket_client 函数可用

### 3.2 基本配置

```php
$beanbun = new Beanbun([
    'name' => 'my_crawler',        // 爬虫名称
    'logFile' => './my_crawler.log', // 日志文件
    'max' => 1000,                 // 最大爬取数量
    'daemonize' => true,           // 守护进程模式
    'interval' => 2,               // 爬取间隔
    'timeout' => 5,                // 请求超时
    'userAgent' => 'pc',           // 用户代理类型
]);
```

## 4. 核心功能使用

### 4.1 创建爬虫实例

```php
use Beanbun\Beanbun;

$beanbun = new Beanbun([
    'name' => 'example_crawler',
    'logFile' => './example.log',
]);
```

### 4.2 设置种子 URL

```php
// 设置单个种子 URL
$beanbun->seed = 'http://example.com';

// 设置多个种子 URL
$beanbun->seed = [
    'http://example.com/page1',
    'http://example.com/page2',
];
```

### 4.3 设置 URL 过滤器

```php
// 设置正则表达式过滤器，只爬取特定 URL
$beanbun->urlFilter = [
    '/^http:\/\/example\.com\/article\//', // 只爬取文章页面
];
```

### 4.4 设置钩子函数

Beanbun 提供了丰富的钩子函数，用于扩展爬虫功能：

```php
// 启动工作进程时触发
$beanbun->startWorker = function ($beanbun) {
    echo "Worker {$beanbun->id} started\n";
};

// 下载页面之前触发
$beanbun->beforeDownloadPage = function ($beanbun) {
    echo "Downloading {$beanbun->url}\n";
};

// 下载页面时触发（可自定义下载逻辑）
$beanbun->downloadPage = function ($beanbun) {
    // 自定义下载逻辑
    $beanbun->page = file_get_contents($beanbun->url);
};

// 下载页面之后触发（解析页面内容）
$beanbun->afterDownloadPage = function ($beanbun) {
    // 解析页面内容
    echo "Downloaded {$beanbun->url}, size: " . strlen($beanbun->page) . " bytes\n";
};

// 发现新 URL 时触发
$beanbun->discoverUrl = function ($beanbun) {
    // 自定义 URL 发现逻辑
    // 默认使用 Helper::getUrlByHtml() 从页面中提取 URL
};

// 停止工作进程时触发
$beanbun->stopWorker = function ($beanbun) {
    echo "Worker {$beanbun->id} stopped\n";
};
```

### 4.5 启动爬虫

```php
// 启动爬虫
$beanbun->start();
```

## 5. 队列系统

### 5.1 使用内存队列

```php
// 使用默认内存队列
$beanbun->setQueue();

// 使用自定义配置的内存队列
$beanbun->setQueue('memory', [
    'host' => '127.0.0.1',
    'port' => 2207,
    'algorithm' => 'depth', // 爬取算法：depth（深度优先）或 breadth（广度优先）
    'bloomFilter' => true,  // 是否使用布隆过滤器
]);

// 启动内存队列服务器（单独运行）
\Beanbun\Queue\MemoryQueue::server('0.0.0.0', 2207);
```

### 5.2 使用 Redis 队列

```php
// 使用 Redis 队列
$beanbun->setQueue('redis', [
    'host' => '127.0.0.1',
    'port' => 6379,
    'algorithm' => 'depth',
    'bloomFilter' => true,
    'size' => 400000,       // 布隆过滤器大小
    'hash_count' => 14,     // 布隆过滤器哈希函数数量
]);
```

## 6. 自定义下载器

```php
// 设置自定义下载器
$beanbun->setDownloader(function () {
    return new MyCustomDownloader();
}, [
    // 下载器配置参数
]);

// 自定义下载器类示例
class MyCustomDownloader
{
    public function request($method, $url, $options)
    {
        // 自定义下载逻辑
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout']);
        // 设置其他选项...
        
        $content = curl_exec($ch);
        curl_close($ch);
        
        return ['content' => $content];
    }
}
```

## 7. 完整使用示例

```html
<?php

use Beanbun\Beanbun;

// 创建爬虫实例
$beanbun = new Beanbun([
    'name' => 'my_crawler',
    'logFile' => './my_crawler.log',
    'max' => 1000,
    'daemonize' => false, // 调试模式
    'interval' => 1,
]);

// 设置种子 URL
$beanbun->seed = 'http://example.com';

// 设置 URL 过滤器
$beanbun->urlFilter = [
    '/^http:\/\/example\.com\/article\//',
];

// 下载页面之后处理
$beanbun->afterDownloadPage = function ($beanbun) {
    // 解析页面内容
    $html = $beanbun->page;
    
    // 提取标题
    if (preg_match('/<title>(.*?)<\/title>/', $html, $match)) {
        $title = $match[1];
        echo "标题：{$title}\n";
    }
    
    // 提取内容
    if (preg_match('/<div class="content">(.*?)<\/div>/s', $html, $match)) {
        $content = $match[1];
        // 保存内容到数据库或文件
    }
};

// 启动爬虫
$beanbun->start();
```

## 8. 命令行操作

### 8.1 启动爬虫

```bash
php crawler.php start
```

### 8.2 停止爬虫

```bash
php crawler.php stop
```

### 8.3 清理爬虫数据

```bash
php crawler.php clean
```

## 9. 高级功能

### 9.1 使用中间件

```php
// 注册中间件
$beanbun->middleware(function ($beanbun) {
    // 中间件逻辑
});
```

### 9.2 自定义日志

```php
// 设置自定义日志处理
$beanbun->setLog(function ($msg, $beanbun) {
    // 自定义日志记录逻辑
    file_put_contents('./custom.log', date('Y-m-d H:i:s') . " {$msg}\n", FILE_APPEND);
});
```

### 9.3 异常处理

```php
// 设置异常处理
$beanbun->exceptionHandler = function ($exception) {
    // 自定义异常处理逻辑
    echo "Exception: " . $exception->getMessage() . "\n";
};
```

## 10. 常见问题

### 10.1 内存队列无法连接

- 确保内存队列服务器已启动
- 检查主机和端口配置是否正确
- 检查防火墙设置

### 10.2 URL 去重不生效

- 确保 `bloomFilter` 选项已启用
- 对于大量 URL，适当调整布隆过滤器的 `size` 和 `hash_count` 参数

### 10.3 爬虫性能优化

- 调整 `interval` 参数，减少请求间隔
- 增加工作进程数量
- 使用 Redis 队列替代内存队列，支持分布式爬取
- 优化页面解析逻辑，减少 CPU 消耗

## 11. 总结

Beanbun 是一个功能强大且易于使用的 PHP 爬虫框架，基于 Workerman 提供了高效的多线程爬取能力。通过灵活的钩子系统和可扩展的架构，可以轻松实现各种复杂的爬虫需求。无论是简单的页面爬取还是大规模的分布式爬取，Beanbun 都能提供良好的支持。