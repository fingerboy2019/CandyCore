<?php
namespace Beanbun\Queue;

/**
 * 队列接口
 * 
 * 定义了队列操作的基本方法，所有队列实现类必须实现此接口
 */
interface QueueInterface
{
    /**
     * 向队列中添加URL
     * 
     * @param string|array $url URL或包含URL和选项的数组
     * @return void
     */
    public function add($url);

    /**
     * 标记URL为已入队
     * 
     * @param string|array $url URL或包含URL和选项的数组
     * @return void
     */
    public function queued($url);

    /**
     * 获取下一个要爬取的URL
     * 
     * @return string|array|null 下一个URL或包含URL和选项的数组，若队列为空则返回null
     */
    public function next();

    /**
     * 获取队列中URL的总数
     * 
     * @return int URL总数
     */
    public function count();

    /**
     * 获取已入队URL的数量
     * 
     * @return int 已入队URL数量
     */
    public function queuedCount();

    /**
     * 检查URL是否已入队
     * 
     * @param string|array $url URL或包含URL和选项的数组
     * @return bool 是否已入队
     */
    public function isQueued($url);
}
