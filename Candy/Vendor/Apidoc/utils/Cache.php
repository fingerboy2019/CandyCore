<?php

declare (strict_types = 1);

namespace Apidoc\utils;

/**
 * 文件缓存类
 */
class Cache
{
	/**
     * 缓存对象
     */
    protected $cache = null;
	
    /**
     * 架构函数
     */
    public function __construct()
    {
		$this->cache = \Candy\Core\Cache::store();
    }
	
	public function __call($name, $arguments) {
        $this->cache->$name(...$arguments);
    }
}
