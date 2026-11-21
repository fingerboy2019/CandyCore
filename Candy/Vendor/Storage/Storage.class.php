<?php
/***
 * Candy框架 文件存储引擎管理
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 12:36:00 $   
 */

declare(strict_types=1);
namespace Storage;

use \Storage\LocalStorage;
use \Candy\Core\Container;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class Storage
{
	
    /**
     * 链接前缀
	 *
     * @var string
     */
    protected $prefix;

    /**
     * 链接类型
	 *
     * @var string
     */
    protected $linkType;
	
    /**
     * 限制文件类型
	 *
     * @var array
     */
    protected $allowType = ['jpg', 'gif', 'png']; 
	
    /**
     * 限制文件上传大小
	 *
     * @var array
     */
    protected $maxSize = 1000000; 
	
    /**
     * 随机重命名文件
	 *
     * @var bool
     */
    private $isRandName = true;
	
    /**
     * 源文件名
	 *
     * @var string
     */
    private $originName = '';
	
    /**
     * 临时文件名
	 *
     * @var string
     */
    private $tmpFileName = '';
	
    /**
     * 文件类型
	 *
     * @var string
     */
    private $fileType = '';
	
    /**
     * 文件大小
	 *
     * @var int
     */
    private $fileSize = 0;
	
    /**
     * 文件目录
	 *
     * @var int
     */
    private $filePath = '';

	private $newFileName;
	private $errorMess;
	private $errorNum;

    /**
     * 存储初始化
	 *
     * @return Storage
     */
    protected function initialize(): Storage
    {
        $this->linkType = G('storage.link_type');
        return $this;
    }

    /**
     * 静态访问启用
	 *
     * @param string $method 方法名称
     * @param array $arguments 调用参数
     * @return mixed
     */
    public static function __callStatic(string $method,array $arguments): mixed
    {
        if (method_exists($instance = self::instance(), $method)) {
            return call_user_func_array([$instance, $method], $arguments);
        } else {
            throw new \Exception("method not exists: " . get_class($instance) . "->{$method}()");
        }
    }

    /**
     * 设置文件驱动名称
	 *
     * @param string $name 驱动名称
     * @return static
     */
    public static function instance(?string	$name = null): Storage
    {
        $type = strtolower((string) (is_null($name) ? G('storage.type', '', 'local') : $name));
        // 腾讯云 COS 使用 S3 兼容 API，复用 S3 SigV4 驱动，避免额外 SDK 依赖。
        $class = match ($type) {
            'cos', 'tencentcos', 'qcloud' => 'S3',
            default => ucfirst($type),
        };
        if (class_exists($object = "\\Storage\\{$class}Storage")) {
			$instance = Container::getInstance()->get($object);
			if(is_null($instance)){
				$instance = Container::getInstance()->make($object)->initialize();
				
			}else{
				$instance->initialize();
			}
			return $instance;
        } else {
            throw new \Exception("File driver [{$class}] does not exist.");
        }
    }

    /**
     * 获取文件相对名称
	 *
     * @param string $url 文件访问链接
     * @param string $ext 文件后缀名称
     * @param string $pre 文件存储前缀
     * @param string $fun 名称规则方法
     * @return string
     */
    public static function name(string $url,string $ext = '',string $pre = '',string $fun = 'md5'): string
    {
        if (empty($ext)) $ext = pathinfo($url, 4);
        [$xmd, $ext] = [$fun($url), trim($ext, '.\\/')];
        $attr = [trim($pre, '.\\/'), substr($xmd, 0, 2), substr($xmd, 2, 30)];
        return trim(join('/', $attr), '/') . '.' . strtolower($ext ? $ext : 'tmp');
    }

    /**
     * 下载文件到本地
	 *
     * @param string $url 文件URL地址
     * @param boolean $force 是否强制下载
     * @param integer $expire 文件保留时间
     * @return array
     */
    public static function down(string $url,bool $force = false,int $expire = 0): array
    {
        try {
            if (!self::isAllowedRemoteUrl($url)) {
                throw new \InvalidArgumentException('不允许下载该 URL');
            }
            $file = LocalStorage::instance();
            $name = self::name($url, '', 'down/');
            if (empty($force) && $file->has($name)) {
                if ($expire < 1 || filemtime($file->path($name)) + $expire > time()) {
                    return $file->info($name);
                }
            }
            return $file->set($name, file_get_contents($url));
        } catch (\Exception $e) {
            return ['url' => $url, 'hash' => md5($url), 'key' => $url, 'file' => $url];
        }
    }

    /**
     * 根据文件后缀获取文件MINE
	 *
     * @param mixed $exts 文件后缀
     * @param array $mime 文件信息
     * @return string
     */
    public static function mime(mixed $exts,array $mime = []): string
    {
        $mimes = self::mimes();
        foreach (is_string($exts) ? explode(',', $exts) : $exts as $ext) {
            $mime[] = $mimes[strtolower($ext)] ??= 'application/octet-stream';
        }
        return join(',', array_unique($mime));
    }

    /**
     * 获取所有文件的信息
	 *
     * @return array
     */
    public static function mimes(): array
    {
        static $mimes = [];
        if (count($mimes) > 0) return $mimes;
        return $mimes = include __DIR__ . '/bin/mimes.php';
    }

    /**
     * 获取下载链接后缀
	 *
     * @param string $attname 下载名称
     * @return string
     */
    protected function getSuffix($attname): string
    {
        if ($this->linkType === 'full') {
            if (is_string($attname) && strlen($attname) > 0) {
                return "?attname=" . urlencode($attname);
            }
        }
        return '';
    }

    /**
     * 获取文件基础名称
	 *
     * @param string $name 文件名称
     * @return string
     */
    protected function delSuffix(string $name): string
    {
        if (strpos($name, '?') !== false) {
            return strstr($name, '?', true);
        }
        return $name;
    }

    /**
     * 校验远程下载 URL，降低 SSRF 风险
     *
     * 仅允许 http/https，并拦截常见内网主机名
     *
     * @param string $url 远程文件地址
     * @return bool
     * @access private
     * @static
     */
    private static function isAllowedRemoteUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'];
        if (in_array($host, $blockedHosts, true)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        return true;
    }

    /**
     * 上传时 set() 第二个参数是否传本地临时路径（FTP 驱动为 true）
     *
     * @return bool
     * @access protected
     */
    protected function usesLocalPathForUpload(): bool
    {
        return false;
    }
	
	/**
	 * 调用该方法上传文件
	 *
	 * @param	string	$fileFile	上传文件的表单名称 
	 * @return	mixed			 	 
	 */
	public function uploadFile(string $fileField, array $confing = []): mixed
	{
		//扩展配置
		if(!empty($confing))
			$this->setOption($confing);
		
		/* 从当前请求文件快照取出上传信息 */
		$files = I('files');
		$file = is_array($files[$fileField] ?? null) ? $files[$fileField] : [
			'name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE,
		];
		$name = $file['name'] ?? '';
		$tmp_name = $file['tmp_name'] ?? '';
		$size = $file['size'] ?? 0;
		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		/* 如果是多个文件上传则$file['name']会是一个数组 */
		if(is_array($name))
		{  		
			$errors = [];
			$return = true;
			/*多个文件上传则循环处理 ， 这个循环只有检查上传文件的作用，并没有真正上传 */
			for($i = 0; $i < count($name); $i++){ 
				/*设置文件信息 */
				if($this->setFiles($name[$i], $tmp_name[$i], $size[$i], $error[$i] )){
					if(!$this->checkFileSize() || !$this->checkFileType()){
						$errors[] = $this->getError();
						$return=false;	
					}
				}else{
					$errors[] = $this->getError();
					$return=false;
				}
				/* 如果有问题，则重新初使化属性 */
				if(!$return)  					
					$this->setFiles();
			}
		
			if($return){
				/* 存放所有上传后文件名的变量数组 */
				$fileNames = [];  			 
				/* 如果上传的多个文件都是合法的，则通过循环向服务器上传文件 */
				for($i = 0; $i < count($name);  $i++){ 
					if($this->setFiles($name[$i], $tmp_name[$i], $size[$i], $error[$i] )){
						$this->setNewFileName();
						$info = $this->copyFile();
						if(!$info){
							$errors[] = $this->getError();
						}
						$fileNames[] = $info;	
					}					
				}
				$this->newFileName = $fileNames;
			}
			$this->errorMess = $errors;
			
			return $return ?: $this->newFileName;
		} else {/*上传单个文件处理方法*/
			$return = false;
			$info = [];
			if($this->setFiles($name, $tmp_name, $size, $error)){
				if($this->checkFileSize() && $this->checkFileType()){	
					$this->setNewFileName();
					$info = $this->copyFile();
					if(is_array($info) && $info !== []){
						$return = true;
					}
				}
			}
			if(!$return){
				$this->errorMess = $this->getError();
			}

			return $return ? [$info] : false;
		}
	}
	
	
	/* 设置和$_FILES有关的内容 */
	private function setFiles(string $name='',string $tmp_name='',int $size=0,int $error=0): bool
	{
		$this->setOption('errorNum', $error);
		if($error)
			return false;
		$this->setOption('originName', $name);
		$this->setOption('tmpFileName',$tmp_name);
		$this->setOption('fileType', strtolower(pathinfo($name, PATHINFO_EXTENSION)));
		$this->setOption('fileSize', $size);
		return true;
	}
	
	/* 检查上传的文件是否是允许的大小 */
	private function checkFileSize(): bool
	{
		if ($this->fileSize > $this->maxSize){
			$this->setOption('errorNum', -2);
			return false;
		}else{
			return true;
		}
	}
	
	/* 检查上传的文件是否是合法的类型 */
	private function checkFileType(): bool
	{
		if (in_array(strtolower($this->fileType), $this->allowType)){
			return true;
		}else {
			$this->setOption('errorNum', -1);
			return false;
		}
	}

	/**
	 * 上传失败后，调用该方法则返回，上传出错信息
	 * @param	void	 没有参数
	 * @return	string 	 返回上传文件出错的信息报告，如果是多文件上传返回数组
	 */
	public function getErrorMsg(): string
	{
		return $this->errorMess ?? '';
	}
	
	/* 设置上传出错信息 */
	private function getError(): string
	{
		$str = "上传文件 [{$this->originName}] 时出错 : ";
		$str .= match ($this->errorNum){
			4 => '没有文件被上传',
			3 => '文件只有部分被上传',
			2 => '上传文件的大小超过了HTML表单中MAX_FILE_SIZE选项指定的值',
			1 => '上传的文件超过了php.ini中upload_max_filesize选项限制的值',
			-1 => '未允许类型',
			-2 => "文件过大,上传的文件不能超过{$this->maxSize}个字节",
			-3 => '上传失败',
			-4 => '建立存放上传文件目录失败，请重新指定上传目录',
			-5 => '必须指定上传文件的路径',
			default => '未知错误',
		};
		return $str;
	}
	
	/* 成员属性设置值 */
	private function setOption(mixed $key,mixed $val = null): void
	{
		if(is_array($key)){
			foreach($key as $k=>$v){
				$this->setOption($k, $v);
			}
		}else{
			if($key == 'allowType'){
				$this->allowType = array_merge($this->allowType, $val);
			}else{
				$this->$key = $val;
			}
		}
	}
	
	/* 设置上传后的文件名称 */
	private function setNewFileName(): void
	{
		if ($this->isRandName){
			$this->setOption('newFileName', $this->proRandName());	
		} else{ 
			$this->setOption('newFileName', $this->originName);
		} 
	}
	
	/* 设置随机文件名 */
	private function proRandName(): string
	{		
		$fileName = date('YmdHis').rand(100,999).chr(rand(97,122));   	
		return $fileName.'.'.$this->fileType; 
	}
	
	/* 复制上传文件到指定的位置 */
	private function copyFile()
	{
		if(!$this->errorNum){
			$path = '/' . trim((string)$this->filePath, '/') . '/';
			$payload = $this->usesLocalPathForUpload()
				? $this->tmpFileName
				: file_get_contents($this->tmpFileName);
			$info = $this->set($path . $this->newFileName, $payload);
			if(empty($info)){
				$this->setOption('errorNum', -3);
				return false;
			}
			return $info;
		} else {
			return false;
		}
	}
}
