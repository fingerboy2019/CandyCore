<?php
/***
 * Candy框架 S3 兼容存储驱动
 *
 * 业务说明：轻量 SigV4 客户端，覆盖 AWS S3 / Cloudflare R2 / MinIO
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 16:36:00 $
 */

declare(strict_types=1);
namespace Storage;

use Candy\Extend\Network\Http;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class S3Storage extends Storage
{
    /** @var string API Endpoint（不含协议与桶） */
    private string $endpoint = '';

    /** @var string 区域，R2 常用 auto */
    private string $region = 'us-east-1';

    /** @var string 桶名 */
    private string $bucket = '';

    /** @var string 公网/CDN 域名（可空） */
    private string $domain = '';

    /** @var string Access Key */
    private string $accessKey = '';

    /** @var string Secret Key */
    private string $secretKey = '';

    /** @var string 对象键前缀 */
    private string $dir = '';

    /** @var bool 是否 path-style（endpoint/bucket/key） */
    private bool $pathStyle = true;

    /** @var string http|https|auto */
    private string $protocol = 'https';

    public function __construct() {}

    /**
     * 初始化入口
     *
     * 从 G('storage.s3_*') 读取配置并计算公开访问前缀
     *
     * @return Storage
     */
    protected function initialize(): Storage
    {
        if (is_null(G('storage'))) {
            throw new \Exception('配置为空');
        }

        $read = static function (string $s3Key, string $cosKey, string $default = ''): string {
            $value = (string) G($s3Key, '', '');
            return $value !== '' ? $value : (string) G($cosKey, '', $default);
        };
        $this->endpoint = $this->normalizeHost($read('storage.s3_endpoint', 'storage.cos_endpoint'));
        $this->region = trim($read('storage.s3_region', 'storage.cos_region', 'us-east-1')) ?: 'us-east-1';
        $this->bucket = trim($read('storage.s3_bucket', 'storage.cos_bucket'));
        $this->domain = $this->normalizeHost($read('storage.s3_http_domain', 'storage.cos_domain'));
        $this->accessKey = trim($read('storage.s3_access_key', 'storage.cos_keyid'));
        $this->secretKey = trim($read('storage.s3_secret_key', 'storage.cos_secret'));
        $this->dir = trim($read('storage.s3_dir', 'storage.cos_dir'), '/');
        $pathStyle = strtolower(trim($read('storage.s3_path_style', 'storage.cos_path_style', '1')));
        $this->pathStyle = !in_array($pathStyle, ['0', 'false', 'no', 'off', 'virtual'], true);
        $this->protocol = strtolower(trim($read('storage.s3_http_protocol', 'storage.cos_is_https', 'https'))) ?: 'https';

        if ($this->endpoint === '' || $this->bucket === '' || $this->accessKey === '' || $this->secretKey === '') {
            throw new \Exception('未配置完整的 S3 参数（endpoint/bucket/access_key/secret_key）');
        }

        $pubHost = $this->domain !== '' ? $this->domain : $this->publicFallbackHost();
        if ($this->protocol === 'auto') {
            $this->prefix = '//' . $pubHost;
        } elseif ($this->protocol === 'http') {
            $this->prefix = 'http://' . $pubHost;
        } else {
            $this->prefix = 'https://' . $pubHost;
        }

        return parent::initialize();
    }

    /**
     * 获取当前实例对象
     *
     * @param string|null $name
     * @return Storage
     */
    public static function instance(?string $name = null): Storage
    {
        return parent::instance($name);
    }

    /**
     * 上传文件内容（PUT Object）
     *
     * @param string $name 文件名称
     * @param string $file 文件内容
     * @param bool $safe 安全模式（S3 忽略，保留签名兼容）
     * @param string|null $attname 下载名称
     * @return array
     */
    public function set(string $name, string $file, bool $safe = false, ?string $attname = null): array
    {
        unset($safe);
        $key = $this->objectKey($name);
        $headers = [];
        if (is_string($attname) && $attname !== '') {
            $headers['Content-Disposition'] = 'inline;filename=' . rawurlencode($attname);
        }
        $mime = self::mime(pathinfo($key, PATHINFO_EXTENSION));
        if ($mime !== '') {
            $headers['Content-Type'] = explode(',', $mime)[0];
        }

        $result = $this->signedRequest('PUT', $key, $file, $headers);
        $code = (int) ($result['http_code'] ?? 0);
        if ($code >= 200 && $code < 300) {
            return [
                'file' => $this->path($name),
                'url' => $this->url($name, false, $attname),
                'key' => $key,
            ];
        }
        return [];
    }

    /**
     * 根据文件名读取文件内容
     *
     * @param string $name 文件名称
     * @param bool $safe 安全模式
     * @return string
     */
    public function get(string $name, bool $safe = false): string
    {
        unset($safe);
        $result = $this->signedRequest('GET', $this->objectKey($name));
        if ((int) ($result['http_code'] ?? 0) === 200) {
            return (string) ($result['content'] ?? '');
        }
        return '';
    }

    /**
     * 删除存储的文件
     *
     * @param string $name 文件名称
     * @param bool $safe 安全模式
     * @return bool
     */
    public function del(string $name, bool $safe = false): bool
    {
        unset($safe);
        [$file] = explode('?', $name, 2);
        $result = $this->signedRequest('DELETE', $this->objectKey($file));
        $code = (int) ($result['http_code'] ?? 0);
        return $code === 204 || $code === 200;
    }

    /**
     * 判断文件是否存在
     *
     * @param string $name 文件名称
     * @param bool $safe 安全模式
     * @return bool
     */
    public function has(string $name, bool $safe = false): bool
    {
        unset($safe);
        $result = $this->signedRequest('HEAD', $this->objectKey($this->delSuffix($name)));
        return (int) ($result['http_code'] ?? 0) === 200;
    }

    /**
     * 获取文件当前 URL 地址
     *
     * @param string $name 文件名称
     * @param bool $safe 安全模式
     * @param string|null $attname 下载名称
     * @return string
     */
    public function url(string $name, bool $safe = false, ?string $attname = null): string
    {
        unset($safe);
        $key = $this->objectKey($this->delSuffix($name));
        return rtrim($this->prefix, '/') . '/' . ltrim($key, '/') . $this->getSuffix($attname);
    }

    /**
     * 获取文件存储路径（云存储等同 URL）
     *
     * @param string $name 文件名称
     * @param bool $safe 安全模式
     * @return string
     */
    public function path(string $name, bool $safe = false): string
    {
        return $this->url($name, $safe);
    }

    /**
     * 获取文件存储信息
     *
     * @param string $name 文件名称
     * @param bool $safe 安全模式
     * @param string|null $attname 下载名称
     * @return array
     */
    public function info(string $name, bool $safe = false, ?string $attname = null): array
    {
        return $this->has($name, $safe) ? [
            'url' => $this->url($name, $safe, $attname),
            'key' => $this->objectKey($name),
            'file' => $this->path($name, $safe),
        ] : [];
    }

    /**
     * 获取文件上传地址（API Endpoint）
     *
     * @return string
     */
    public function upload(): string
    {
        return $this->apiBaseUrl();
    }

    /**
     * 获取上传令牌占位（直传 STS 非本期）
     *
     * @param string|null $name 文件名称
     * @param int $expires 有效时间
     * @param string|null $attname 下载名称
     * @return array
     */
    public function buildUploadToken(?string $name = null, int $expires = 3600, ?string $attname = null): array
    {
        unset($expires);
        return [
            'siteurl' => $name ? $this->url($name, false, $attname) : $this->prefix,
            'keyid' => $this->accessKey,
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'bucket' => $this->bucket,
            'path_style' => $this->pathStyle ? 1 : 0,
        ];
    }

    /**
     * ListObjectsV2 列举一层目录
     *
     * @param string $prefix 前缀（相对路径，可空）
     * @param string $delimiter 分隔符，默认 /
     * @param int $maxKeys 最大条数
     * @return array{contents: list<array{key:string,size:int,mtime:int}>, prefixes: list<string>}
     */
    public function listObjects(string $prefix = '', string $delimiter = '/', int $maxKeys = 1000): array
    {
        $prefix = ltrim($prefix, '/');
        if ($this->dir !== '') {
            $prefix = $prefix === '' ? $this->dir . '/' : $this->dir . '/' . $prefix;
        }

        $query = [
            'list-type' => '2',
            'prefix' => $prefix,
            'delimiter' => $delimiter,
            'max-keys' => (string) max(1, min(1000, $maxKeys)),
        ];
        $result = $this->signedRequest('GET', '', '', [], $query);
        if ((int) ($result['http_code'] ?? 0) !== 200) {
            throw new \RuntimeException('S3 ListObjects 失败：HTTP ' . (int) ($result['http_code'] ?? 0));
        }

        $xml = @simplexml_load_string((string) ($result['content'] ?? ''));
        if ($xml === false) {
            throw new \RuntimeException('S3 ListObjects 响应解析失败');
        }

        $contents = [];
        foreach ($xml->Contents ?? [] as $node) {
            $contents[] = [
                'key' => (string) $node->Key,
                'size' => (int) $node->Size,
                'mtime' => strtotime((string) $node->LastModified) ?: 0,
            ];
        }
        $prefixes = [];
        foreach ($xml->CommonPrefixes ?? [] as $node) {
            $p = (string) $node->Prefix;
            if ($p !== '') {
                $prefixes[] = $p;
            }
        }

        return ['contents' => $contents, 'prefixes' => $prefixes];
    }

    /**
     * 规范化对象键（含可选 dir 前缀）
     *
     * @param string $name
     * @return string
     * @access private
     */
    private function objectKey(string $name): string
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        if ($this->dir === '') {
            return $name;
        }
        if ($name === '' || str_starts_with($name, $this->dir . '/')) {
            return $name === '' ? $this->dir . '/' : $name;
        }
        return $this->dir . '/' . $name;
    }

    /**
     * 去掉域名协议与尾斜杠
     *
     * @param string $host
     * @return string
     * @access private
     */
    private function normalizeHost(string $host): string
    {
        $host = trim($host);
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        return rtrim($host, '/');
    }

    /**
     * 无自定义域名时的公开 Host
     *
     * @return string
     * @access private
     */
    private function publicFallbackHost(): string
    {
        if ($this->pathStyle) {
            return $this->endpoint . '/' . $this->bucket;
        }
        return $this->bucket . '.' . $this->endpoint;
    }

    /**
     * API 基址（含协议）
     *
     * @return string
     * @access private
     */
    private function apiBaseUrl(): string
    {
        $scheme = $this->protocol === 'http' ? 'http' : 'https';
        if ($this->pathStyle) {
            return $scheme . '://' . $this->endpoint . '/' . $this->bucket;
        }
        return $scheme . '://' . $this->bucket . '.' . $this->endpoint;
    }

    /**
     * 构造对象 API URL（不含 query）
     *
     * @param string $key 对象键
     * @return string
     * @access private
     */
    private function objectApiUrl(string $key): string
    {
        $base = rtrim($this->apiBaseUrl(), '/');
        $key = ltrim($key, '/');
        if ($key === '') {
            return $base;
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $key)));
        return $base . '/' . $encoded;
    }

    /**
     * 发起已签名的 S3 请求
     *
     * @param string $method HTTP 方法
     * @param string $key 对象键（列举时可空）
     * @param string $body 请求体
     * @param array<string, string> $extraHeaders 额外头
     * @param array<string, string> $query Query 参数
     * @return array
     * @access private
     */
    private function signedRequest(
        string $method,
        string $key,
        string $body = '',
        array $extraHeaders = [],
        array $query = []
    ): array {
        $method = strtoupper($method);
        $url = $this->objectApiUrl($key);
        if ($query !== []) {
            ksort($query);
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $payloadHash = hash('sha256', $body);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? '');
        if (!empty($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }
        $canonicalUri = $this->canonicalUri((string) ($parsed['path'] ?? '/'));
        $canonicalQuery = $this->canonicalQueryString((string) ($parsed['query'] ?? ''));

        $headers = array_change_key_case($extraHeaders, CASE_LOWER);
        $headers['host'] = $host;
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $amzDate;
        if ($method === 'PUT' && $body !== '' && !isset($headers['content-type'])) {
            $headers['content-type'] = 'application/octet-stream';
        }
        if ($method === 'PUT' || $method === 'POST') {
            $headers['content-length'] = (string) strlen($body);
        }

        ksort($headers);
        $signedHeaderNames = [];
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $signedHeaderNames[] = $name;
            $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', (string) $value) ?? '') . "\n";
        }
        $signedHeaders = implode(';', $signedHeaderNames);

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $this->headerCase($name) . ': ' . $value;
        }

        $options = [
            'returnHeader' => true,
            'headers' => $headerLines,
            'ssl_verify' => true,
            'follow_location' => false,
            'timeout' => 60,
        ];
        if ($body !== '' || in_array($method, ['PUT', 'POST'], true)) {
            $options['data'] = $body;
        }

        $result = Http::request($method, $url, $options);
        return is_array($result) ? $result : ['http_code' => 0, 'content' => ''];
    }

    /**
     * Canonical URI
     *
     * @param string $path
     * @return string
     * @access private
     */
    private function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $parts = explode('/', $path);
        $encoded = [];
        foreach ($parts as $i => $part) {
            if ($i === 0 && $part === '') {
                $encoded[] = '';
                continue;
            }
            $encoded[] = rawurlencode(rawurldecode($part));
        }
        $uri = implode('/', $encoded);
        return str_starts_with($uri, '/') ? $uri : '/' . $uri;
    }

    /**
     * Canonical query string
     *
     * @param string $query
     * @return string
     * @access private
     */
    private function canonicalQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $params = [];
        parse_str($query, $params);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $pairs);
    }

    /**
     * 派生 SigV4 签名密钥
     *
     * @param string $dateStamp Ymd
     * @return string
     * @access private
     */
    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * 头名还原常见大小写
     *
     * @param string $name
     * @return string
     * @access private
     */
    private function headerCase(string $name): string
    {
        $map = [
            'host' => 'Host',
            'authorization' => 'Authorization',
            'content-type' => 'Content-Type',
            'content-length' => 'Content-Length',
            'content-disposition' => 'Content-Disposition',
            'x-amz-content-sha256' => 'x-amz-content-sha256',
            'x-amz-date' => 'x-amz-date',
        ];
        return $map[$name] ?? $name;
    }
}
