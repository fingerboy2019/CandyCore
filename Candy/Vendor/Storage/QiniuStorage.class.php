<?php
/***
 * Candy框架 七牛云存储支持
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 12:36:00 $   
 */

declare(strict_types=1);
namespace Storage;
use \Candy\Extend\Network\Http;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');


class QiniuStorage extends Storage
{
    private $bucket;
    private $domain;
    private $accessKey;
    private $secretKey;
	
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
        $this->bucket = G('storage.qiniu_bucket');
        $this->domain = G('storage.qiniu_http_domain');
        $this->accessKey = G('storage.qiniu_access_key');
        $this->secretKey = G('storage.qiniu_secret_key');
        // 计算链接前缀
        $type = strtolower(G('storage.qiniu_http_protocol'));
        if ($type === 'auto') $this->prefix = "//{$this->domain}";
        elseif ($type === 'http') $this->prefix = "http://{$this->domain}";
        elseif ($type === 'https') $this->prefix = "https://{$this->domain}";
        else throw new \Exception('未配置七牛云URL域名哦');
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
        $token = $this->buildUploadToken($name, 3600, $attname);
        $data = ['key' => $name, 'token' => $token, 'fileName' => $name];
        $file = ['field' => "file", 'name' => $name, 'content' => $file];
        $result = Http::submit($this->upload(), $data, $file, [], 'POST', false);
        $decoded = json($result['content'], 'de');
        if (!is_array($decoded) || empty($decoded['url'])) {
            return [];
        }
        return $decoded;
    }


    /**
     * 根据文件名读取文件内容
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return string
     */
    public function get(string $name,bool $safe = false): string
    {
        $url = $this->url($name, $safe) . "?e=" . time();
        $token = "{$this->accessKey}:{$this->safeBase64(hash_hmac('sha1', $url, $this->secretKey, true))}";
        return file_get_contents("{$url}&token={$token}");
    }

    /**
     * 删除存储的文件
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return boolean|null
     */
    public function del(string $name,bool $safe = false)
    {
        [$EncodedEntryURI, $AccessToken] = $this->getAccessToken($name, 'delete');
        $data = json_decode(Http::post("http://rs.qiniu.com/delete/{$EncodedEntryURI}", [], [
            'headers' => ["Authorization:QBox {$AccessToken}"],
        ])['content'], true);
        return empty($data['error']);
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
        return $this->info($name, $safe) !== [];
    }

    /**
     * 获取文件当前URL地址
	 *
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @param string $attname 下载名称
     * @return string
     */
    public function url(string $name,bool $safe = false, $attname = null): string
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
    public function info(string $name,bool $safe = false, $attname = null): array
    {
        [$entry, $token] = $this->getAccessToken($name);
        $data = json_decode(Http::get("http://rs.qiniu.com/stat/{$entry}", [], ['headers' => ["Authorization: QBox {$token}"]])['content'], true);
        return isset($data['md5']) ? ['file' => $name, 'url' => $this->url($name, $safe, $attname), 'key' => $name] : [];
    }

    /**
     * 获取文件上传地址
	 *
     * @return string
     * @throws \Exception
     */
    public function upload(): string
    {
        $protocol = isSsl() ? 'https' : 'http';
        return match (G('storage.qiniu_region')) {
            '华东' => "{$protocol}://up.qiniup.com",
            '华北' => "{$protocol}://up-z1.qiniup.com",
            '华南' => "{$protocol}://up-z2.qiniup.com",
            '北美' => "{$protocol}://up-na0.qiniup.com",
            '东南亚' => "{$protocol}://up-as0.qiniup.com",
            default => throw new \Exception('未配置七牛云空间区域哦'),
        };
    }

    /**
     * 获取文件上传令牌
	 *
     * @param string $name 文件名称
     * @param integer $expires 有效时间
     * @param string $attname 下载名称
     * @return string
     */
    public function buildUploadToken(?string $name = null,int $expires = 3600,?string $attname = null): string
    {
        $policy = $this->safeBase64(json_encode([
            "deadline"   => time() + $expires, "scope" => is_null($name) ? $this->bucket : "{$this->bucket}:{$name}",
            'returnBody' => json_encode([
                'uploaded' => true, 'filename' => '$(key)', 'url' => "{$this->prefix}/$(key){$this->getSuffix($attname)}", 'key' => $name, 'file' => $name,
            ], JSON_UNESCAPED_UNICODE),
        ]));
        return "{$this->accessKey}:{$this->safeBase64(hash_hmac('sha1', $policy, $this->secretKey, true))}:{$policy}";
    }

    /**
     * URL安全的Base64编码
	 *
     * @param string $content
     * @return string
     */
    private function safeBase64(string $content): string
    {
        return str_replace(['+', '/'], ['-', '_'], base64_encode($content));
    }

    /**
     * 获取对象管理凭证
	 *
     * @param string $name 文件名称
     * @param string $type 操作类型
     * @return array
     */
    private function getAccessToken(string $name,string $type = 'stat'): array
    {
        $entry = $this->safeBase64("{$this->bucket}:{$name}");
        $sign = hash_hmac('sha1', "/{$type}/{$entry}\n", $this->secretKey, true);
        return [$entry, "{$this->accessKey}:{$this->safeBase64($sign)}"];
    }
}
