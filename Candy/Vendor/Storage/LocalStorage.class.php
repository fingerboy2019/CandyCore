<?php
/***
 * Candy框架 本地存储支持
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-22 14:30:00 $   
 */
 
declare(strict_types=1);
namespace Storage;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class LocalStorage extends Storage
{
	public $rootPath;	
	
	//实例化对象
	public function __construct(){
        $this->rootPath = C('CANDYROOT');
    }
	
    /**
     * 初始化入口
	 *
     * @return LocalStorage
     */
    protected function initialize(): Storage
    {
        // 计算链接前缀
        $type = strtolower((string) G('storage.local_http_protocol', '', ''));
        if (empty($type)) {
            $this->prefix = $this->baseFile(false);
        } else {
            $this->prefix = $this->baseFile(true);
            [, $domain] = explode('://', strtr($this->prefix, '\\', '/'));
            if ($type === 'auto') $this->prefix = "//{$domain}";
            elseif ($type === 'http') $this->prefix = "http://{$domain}";
            elseif ($type === 'https') $this->prefix = "https://{$domain}";
        }
        // 初始化配置并返回当前实例
        return parent::initialize();
    }

    /**
     * 获取当前实例对象
	 *
     * @return LocalStorage
     */
    public static function instance($name = null): Storage
    {
        return parent::instance($name);
    }

    /**
     * 文件储存在本地
	 *
     * @param string $name 文件名称
     * @param string $file 文件内容
     * @param boolean $safe 安全模式
     * @param string $attname 下载名称
     * @return array
     */
    public function set(string $name, $file,bool $safe = false,?string $attname = null): array
    {
        try {
            $path = $this->path($name, $safe);
            file_exists(dirname($path)) || mkdir(dirname($path), 0755, true);
            if (file_put_contents($path, $file)) {
                return $this->info($name, $safe, $attname);
            }
        } catch (\Exception $e) {
            return [];
        }
        return [];
    }

    /**
     * 根据文件名读取文件内容
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return string
     */
    public function get(string $name,bool $safe = false)
    {
        if (!$this->has($name, $safe)) return '';
        return file_get_contents($this->path($name, $safe));
    }

    /**
     * 删除存储的文件
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return boolean
     */
    public function del(string $name,bool $safe = false): bool
    {
        if ($this->has($name, $safe)) {
            return @unlink($this->path($name, $safe));
        } else {
            return false;
        }
    }

    /**
     * 检查文件是否已经存在
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return boolean
     */
    public function has(string $name,bool $safe = false): bool
    {
        return file_exists($this->path($name, $safe));
    }

    /**
     * 获取文件当前URL地址
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @param string $attname 下载名称
     * @return string|null
     */
    public function url(string $name,bool $safe = false,?string $attname = null): string
    {
        $webPath = rtrim(\Candy\Core\Bootstrap::uploadWebPrefix($safe), '/') . $this->delSuffix($name);
        // uploadWebPrefix 已含租户段；delSuffix(name) 通常以 / 开头
        if ($this->linkType === 'full' && $this->prefix !== '') {
            return rtrim($this->prefix, '/') . $webPath . $this->getSuffix($attname);
        }
        return $webPath . $this->getSuffix($attname);
    }

    /**
     * 获取路径
	 *
     * @param bool $complete 
     * @return string
     */
    public function baseFile(bool $complete = false): string
    {
        return $complete ? ((isSsl() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) : '';
    }
	
    /**
     * 获取文件存储路径
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return string
     */
    public function path(string $name,bool $safe = false): string
    {
        $path = \Candy\Core\Bootstrap::uploadRoot($safe) . $this->delSuffix($name);
        return strtr($path, '\\', '/');
    }

    /**
     * 获取文件存储信息
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @param string $attname 下载名称
     * @return array
     */
    public function info(string $name,bool $safe = false,?string $attname = null): array
    {
        return $this->has($name, $safe) ? [
            'url' => $this->url($name, $safe, $attname),
            'key' => rtrim(\Candy\Core\Bootstrap::uploadWebPrefix($safe), '/') . '/' . ltrim($this->delSuffix($name), '/'),
            'file' => $this->path($name, $safe),
        ] : [];
    }
}
