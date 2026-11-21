<?php
/***
 * Candy框架 阿里云OSS存储支持
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 12:36:00 $   
 */

declare(strict_types=1);
namespace Storage;
use \Candy\Extend\Network\Http;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class AliossStorage extends Storage
{
    /**
     * 数据中心
	 *
     * @var string
     */
    private $point;

    /**
     * 存储空间名称
	 *
     * @var string
     */
    private $bucket;

    /**
     * 绑定访问域名
	 *
     * @var string
     */
    private $domain;

    /**
     * AccessKeyId
	 *
     * @var string
     */
    private $accessKey;

    /**
     * AccessKeySecret
	 *
     * @var string
     */
    private $secretKey;

    

    /**
     * 目录名称
	 *
     * @var string
     */
    private $dir;


    //实例化对象
    function __construct(){}
	
    /**
     * 初始化入口
	 *
     * @return $this
     */
    protected function initialize(): Storage
    {
		if(is_null(G('storage'))) throw new \Exception('配置为空');
        // 读取配置文件
        $this->point = G('storage.alioss_point');
        $this->bucket = G('storage.alioss_bucket');
        $this->domain = G('storage.alioss_http_domain');
        $this->accessKey = G('storage.alioss_access_key');
        $this->secretKey = G('storage.alioss_secret_key');
        $this->dir = G('storage.alioss_dir', '');
        // 计算链接前缀
        $type = strtolower(G('storage.alioss_http_protocol'));
        if ($type === 'auto') $this->prefix = "//{$this->bucket}.{$this->domain}";
        elseif ($type === 'http') $this->prefix = "http://{$this->bucket}.{$this->domain}";
        elseif ($type === 'https') $this->prefix = "https://{$this->bucket}.{$this->domain}";
        else throw new \Exception('未配置阿里云URL域名哦');
        // 初始化配置并返回当前实例
        return parent::initialize();
    }

    /**
     * 获取当前实例对象
	 *
     * @return static
     */
    public static function instance(?string $name = null): Storage
    {
        return parent::instance($name);
    }

    /**
     * 上传文件内容
	 *
     * @param string $name 文件名称
     * @param string $file 文件内容
     * @param boolean $safe 安全模式
     * @param string $attname 下载名称
     * @return array
     */
    public function set(string $name,string $file,bool $safe = false,?string $attname = null): array
    {
        $name = empty($this->dir) ? $name : $this->dir . '/' . $name;
        $token = $this->buildUploadToken($name);
        $data = ['key' => $name];
        $data['policy'] = $token['policy'];
        $data['Signature'] = $token['signature'];
        $data['OSSAccessKeyId'] = $this->accessKey;
        $data['success_action_status'] = '200';
        if (is_string($attname) && strlen($attname) > 0) {
            $filename = urlencode($attname);
            $data['Content-Disposition'] = "inline;filename={$filename}";
        }
        $file = ['field' => 'file', 'name' => $name, 'content' => $file];
        if (is_numeric(stripos((string)Http::submit($this->upload(), $data, $file)['http_code'], '200'))) {
            return ['file' => $this->path($name, $safe), 'url' => $this->url($name, $safe, $attname), 'key' => $name];
        } else {
            return [];
        }
    }

    /**
     * 根据文件名读取文件内容
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return false|string
     */
    public function get(string $name,bool $safe = false): string
    {
        return file_get_contents($this->url($name, $safe));
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
        [$file] = explode('?', $name);
        $result = Http::request('DELETE', "http://{$this->bucket}.{$this->point}/{$file}", [
            'returnHeader' => true, 'headers' => $this->headerSign('DELETE', $file),
        ]);
        return (int)($result['http_code'] ?? 0) === 204;
    }

    /**
     * 判断文件是否存在
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return boolean
     */
    public function has(string $name,bool $safe = false): bool
    {
        $file = $this->delSuffix($name);
        $result = Http::request('HEAD', "http://{$this->bucket}.{$this->point}/{$file}", [
            'returnHeader' => true, 'headers' => $this->headerSign('HEAD', $file),
        ]);
        return (int)($result['http_code'] ?? 0) === 200;
    }

    /**
     * 获取文件当前URL地址
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @param string $attname 下载名称
     * @return string
     */
    public function url(string $name,bool $safe = false,?string $attname = null): string
    {
        return "{$this->prefix}/{$this->delSuffix($name)}{$this->getSuffix($attname)}";
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
        return $this->url($name, $safe);
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
            'key' => $name, 'file' => $this->path($name, $safe),
        ] : [];
    }

    /**
     * 获取文件上传地址
	 *
     * @return string
     */
    public function upload(): string
    {
        $http = isSsl() ? 'https' : 'http';
        return "{$http}://{$this->bucket}.{$this->point}";
    }

    /**
     * 获取文件上传令牌
	 *
     * @param string $name 文件名称
     * @param integer $expires 有效时间
     * @param string $attname 下载名称
     * @return array
     */
    public function buildUploadToken(?string $name = null,int $expires = 3600,?string $attname = null): array
    {
        $data = [
            'policy'  => base64_encode(json_encode([
                'conditions' => [['content-length-range', 0, 1048576000]],
                'expiration' => date('Y-m-d\TH:i:s.000\Z', time() + $expires),
            ])),
            'siteurl' => $this->url($name, false, $attname),
            'keyid'   => $this->accessKey,
        ];
        $data['signature'] = base64_encode(hash_hmac('sha1', $data['policy'], $this->secretKey, true));
        return $data;
    }

    /**
     * 操作请求头信息签名
	 *
     * @param string $method 请求方式
     * @param string $soruce 资源名称
     * @param array $header 请求头信息
     * @return array
     */
    private function headerSign(string $method,string $soruce,array $header = []):array
    {
        if (empty($header['Date'])) $header['Date'] = gmdate('D, d M Y H:i:s \G\M\T');
        if (empty($header['Content-Type'])) $header['Content-Type'] = 'application/xml';
        uksort($header, 'strnatcasecmp');
        $content = "{$method}\n\n";
        foreach ($header as $key => $value) {
            $value = str_replace(["\r", "\n"], '', $value);
            if (in_array(strtolower($key), ['content-md5', 'content-type', 'date'])) {
                $content .= "{$value}\n";
            } elseif (stripos($key, 'x-oss-') === 0) {
                $content .= strtolower($key) . ":{$value}\n";
            }
        }
        $content = rawurldecode($content) . "/{$this->bucket}/{$soruce}";
        $signature = base64_encode(hash_hmac('sha1', $content, $this->secretKey, true));
        $header['Authorization'] = "OSS {$this->accessKey}:{$signature}";
        foreach ($header as $key => $value) $header[$key] = "{$key}: {$value}";
        return array_values($header);
    }

}
