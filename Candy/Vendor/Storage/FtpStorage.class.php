<?php
/***
 * Candy框架 FTP存储支持
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 12:36:00 $   
 */

declare(strict_types=1);
namespace Storage;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class FtpStorage extends Storage
{
    /**
     * 服务器地址
	 *
     * @var string
     */
    private $server;

    /**
     * 端口号
	 *
     * @var int
     */
    private $port = 21;

    /**
     * 超时时间
	 *
     * @var int
     */
    private $timeout = 90;

    /**
     * 用户名
	 *
     * @var string
     */
    private $user;

    /**
     * 密码
	 *
     * @var string
     */
    private $password;

    /**
     * 域名地址
	 *
     * @var string
     */
    private $domain;
    
    private $connect;
	
	//实例化对象
	public function __construct(){}

    /**
     * 初始化入口
	 *
     * @return $this
     */
    protected function initialize(): Storage
    {
		if(is_null(G('storage'))) throw new \Exception('配置为空');
        // 读取配置文件
        $this->server = G('storage.server');
        $this->user = G('storage.user');
        $this->password = G('storage.password');
        is_null(G('storage.port')) OR $this->port = G('storage.port');
        is_null(G('storage.timeout')) OR $this->timeout = G('storage.timeout');
        // 计算链接前缀
        $http_protocol = rtrim(strtolower(G('storage.http_protocol')), '/');
        if (empty($http_protocol)) 
			throw new \Exception('未配置ftp文件URL域名哦');
		
		$this->domain = $http_protocol;
		
        // 初始化配置并返回当前实例
        return parent::initialize();
    }

    /**
     * 获取当前实例对象
	 *
     * @return static
     */
    public static function instance($name = null): Storage
    {
        return parent::instance($name);
    }

    /**
     * 连接ftp服务器
	 *
     * @return static
     */
    public function connect(): void
    {
        $connect=ftp_connect($this->server, $this->port, $this->timeout);
		$login=ftp_login($connect, $this->user, $this->password);
		if((!$connect)||(!$login)){
			throw new \Exception('ftp连接失败请检查配置。');
		}
		//被动模式
		ftp_pasv($connect, true);
		$this->connect = $connect;
    }

    /**
     * FTP 上传使用本地临时文件路径，而非读取文件内容
     *
     * @return bool
     * @access protected
     */
    protected function usesLocalPathForUpload(): bool
    {
        return true;
    }

    /**
     * 上传文件内容
	 *
     * @param string $name 文件名称
     * @param string $file 本地临时文件路径
     * @param boolean $safe 安全模式（FTP 忽略）
     * @param string|null $attname 下载名称（FTP 忽略）
     * @return array
     */
    public function set(string $name, string $file, bool $safe = false, ?string $attname = null): array
    {
		$this->connect();
		//目录情况
		$dir = dirname($name);
		if($dir == '/'){
			$name = ltrim($name, '/');
		}elseif($dir != '.'){
			//包含目录信息
			if($dir[0] != '.' && $dir[0] != '/'){
				$dir = './' . $dir;
			}elseif($dir[0] == '/'){
				$dir = '.' . $dir;
			}
			//列表
			$paths = ftp_nlist($this->connect, '.');
			//检查列表
			$mkPathList = [];
			do{
				$mkPathList[] = $dir;
				$dir = dirname($dir);
			}while(stripos($dir, '/') && !in_array($dir, $paths));
			
			//创建目录
            foreach($mkPathList as $mkpath)
			{
				ftp_mkdir($this->connect, $mkpath);
			}
		}
		$upload=ftp_put($this->connect, $name, $file, FTP_BINARY); 
		//关闭
		ftp_close($this->connect);
		if(!$upload){
			return [];
        } else {
            return ['file' => $this->path($name), 'url' => $this->url($name), 'key' => $name];
        }
    }

    /**
     * 根据文件名读取文件内容
	 *
     * @param string $name 文件名称
     * @return false|string
     */
    public function get(string $name): bool|string
    {
		$tmp = C('RUNTIME') . 'Tmps/' . time() . rand(1111, 9999) . '.tmp';
		$this->connect();
        ftp_get($this->connect, $tmp, $name, FTP_BINARY);
		ftp_close($this->connect);
		$content = file_get_contents($tmp);
		unlink($tmp);
		return $content;
    }

    /**
     * 删除存储的文件
	 *
     * @param string $name 文件名称
     * @return boolean
     */
    public function del(string $name): bool
    {
		$this->connect();
		$delete = ftp_delete($this->connect, $name);
		ftp_close($this->connect);
		return $delete;
    }

    /**
     * 判断文件是否存在
	 *
     * @param string $name 文件名称
     * @return boolean
     */
    public function has(string $name): bool
    {
        $this->connect();
        $size = ftp_size($this->connect, $name);
        ftp_close($this->connect);
        // -1 表示不存在或 SIZE 不可用；0 为合法空文件
        return $size !== -1;
    }

    /**
     * 获取文件当前URL地址
	 *
     * @param string $name 文件名称
     * @return string
     */
    public function url(string $name): string
    {
        return rtrim($this->domain, '/') . '/' . ltrim($this->delSuffix($name), '/');
    }

    /**
     * 获取文件存储路径
	 *
     * @param string $name 文件名称
     * @return string
     */
    public function path(string $name): string
    {
        return $this->url($name);
    }

    /**
     * 获取文件存储信息
     *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式（FTP 忽略）
     * @param string|null $attname 下载名称（FTP 忽略）
     * @return array
     */
    public function info(string $name, bool $safe = false, ?string $attname = null): array
    {
        return $this->has($name) ? [
            'url' => $this->url($name),
            'key' => ltrim($name, '/'),
            'file' => $this->path($name),
        ] : [];
    }
}
