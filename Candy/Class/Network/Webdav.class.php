<?php
/**
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
 * Webdav::move('https://example.com/webdav/file.txt', 'https://example.com/webdav/new_file.txt');
 * 
 * // 锁定资源
 * $token = Webdav::lock('https://example.com/webdav/important.txt');
 * // 解锁资源
 * Webdav::unlock('https://example.com/webdav/important.txt', $token);
 * ```
 * 
 * @package Candy\Extend\Network
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-01-23 14:00:00
 * @final 此类不可被继承
 */

declare(strict_types=1);
namespace Candy\Extend\Network;
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Webdav {
    /**
     * 统一认证参数：兼容文档约定的 auth=['用户名','密码']，
     * 转换为底层 Http 类实际使用的 webdav=['user'=>..,'password'=>..] 格式
     *
     * @param array $options 请求选项
     * @return array 转换后的选项
     */
    private static function normalizeOptions(array $options): array
    {
        if (!empty($options['auth']) && is_array($options['auth']) && isset($options['auth'][0], $options['auth'][1])) {
            $options['webdav'] = $options['webdav'] ?? [];
            $options['webdav']['user'] = $options['webdav']['user'] ?? (string) $options['auth'][0];
            $options['webdav']['password'] = $options['webdav']['password'] ?? (string) $options['auth'][1];
        }
        return $options;
    }

    /**
     * 获取WebDAV资源的属性信息
     * 
     * 通过PROPFIND方法检索WebDAV服务器上资源的属性信息，如资源名称、大小、类型、最后修改时间等。
     * 支持递归获取多个层级的资源信息。
     * 
     * @param string $path 要查询的资源路径，必须是完整的URL
     * @param array $options HTTP请求选项数组，支持以下参数：
     *                      - headers: 自定义HTTP头信息数组
     *                      - timeout: 请求超时时间（秒）
     *                      - user_agent: 自定义User-Agent
     *                      - auth: 认证信息数组，格式为['username', 'password']
     *                      - webdav: WebDAV特定选项数组
     *                      - depth: 递归深度，0表示仅当前资源，1表示当前资源及其直接子资源
     * @return array|false 成功时返回包含资源属性的关联数组，失败时返回false
     * 
     * @example
     * ```php
     * // 获取目录内容列表
     * $files = Webdav::propfind('https://example.com/webdav/', ['depth' => 1]);
     * foreach ($files as $file) {
     *     echo $file['displayname'] . '<br>';
     * }
     * ```
     */
    public static function propfind(string $path, array $options = []) {
        $options = self::normalizeOptions($options);
        // Depth 头：默认 1（当前资源 + 直接子项）；infinity 需显式指定
        $depth = $options['depth'] ?? 1;
        $depthStr = $depth === 'infinity' ? 'infinity' : (string) (int) $depth;
        $options['headers'][] = 'Depth: ' . $depthStr;
        $response = Http::request('PROPFIND', $path, $options);
        
        if ($response['http_code'] == 207) {
            return self::parsePropfindResponse($response['content']);
        }
        return false;
    }

    /**
     * 在WebDAV服务器上创建新目录
     * 
     * 通过MKCOL方法在指定路径创建新的目录。路径必须指向新目录的完整URL。
     * 目录创建成功返回true，失败返回false。
     * 
     * @param string $path 要创建的目录路径，必须是完整的URL，通常以斜杠结尾
     * @param array $options HTTP请求选项数组，支持以下参数：
     *                      - headers: 自定义HTTP头信息数组
     *                      - timeout: 请求超时时间（秒）
     *                      - user_agent: 自定义User-Agent
     *                      - auth: 认证信息数组，格式为['username', 'password']
     * @return bool 目录创建成功返回true，失败返回false
     * 
     * @example
     * ```php
     * // 创建新目录
     * $success = Webdav::mkcol('https://example.com/webdav/new_folder/');
     * if ($success) {
     *     echo '目录创建成功';
     * }
     * ```
     */
    public static function mkcol(string $path, array $options = []) {
        $options = self::normalizeOptions($options);
        $response = Http::request('MKCOL', $path, $options);
        return $response['http_code'] === 201;
    }

    /**
     * 移动或重命名WebDAV服务器上的资源
     * 
     * 通过MOVE方法将源路径的资源移动到目标路径，可用于文件或目录的移动和重命名操作。
     * 操作成功返回true，失败返回false。
     * 
     * @param string $source 源资源路径，必须是完整的URL
     * @param string $destination 目标路径，必须是完整的URL
     * @param array $options HTTP请求选项数组，支持以下参数：
     *                      - headers: 自定义HTTP头信息数组
     *                      - timeout: 请求超时时间（秒）
     *                      - user_agent: 自定义User-Agent
     *                      - auth: 认证信息数组，格式为['username', 'password']
     * @return bool 移动/重命名成功返回true，失败返回false
     * 
     * @example
     * ```php
     * // 移动文件
     * $success = Webdav::move(
     *     'https://example.com/webdav/file.txt',
     *     'https://example.com/webdav/folder/file.txt'
     * );
     * 
     * // 重命名文件
     * $success = Webdav::move(
     *     'https://example.com/webdav/old_name.txt',
     *     'https://example.com/webdav/new_name.txt'
     * );
     * ```
     */
    public static function move(string $source, string $destination, array $options = []) {
        $options = self::normalizeOptions($options);
        $options['headers'][] = "Destination: {$destination}";
        $response = Http::request('MOVE', $source, $options);
        return $response['http_code'] === 201 || $response['http_code'] === 204;
    }

    /**
     * 复制WebDAV服务器上的资源
     * 
     * 通过COPY方法将资源从源路径复制到目标路径，可用于复制文件或整个目录结构。
     * 支持设置是否覆盖已存在的目标资源。
     * 
     * @param string $source 源资源路径，必须是完整的URL
     * @param string $destination 目标资源路径，必须是完整的URL
     * @param array $options HTTP请求选项数组，支持以下参数：
     *                      - headers: 自定义HTTP头信息数组
     *                      - timeout: 请求超时时间（秒）
     *                      - user_agent: 自定义User-Agent
     *                      - auth: 认证信息数组，格式为['username', 'password']
     *                      - overwrite: 是否覆盖已存在的目标资源，默认false
     * @return bool 复制成功返回true，失败返回false
     * 
     * @example
     * ```php
     * // 复制文件
     * $success = Webdav::copy(
     *     'https://example.com/webdav/original.txt',
     *     'https://example.com/webdav/copy.txt'
     * );
     * 
     * // 复制到其他目录
     * $success = Webdav::copy(
     *     'https://example.com/webdav/file.txt',
     *     'https://example.com/webdav/backup/file.txt'
     * );
     * ```
     */
    public static function copy(string $source, string $destination, array $options = []) {
        $options = self::normalizeOptions($options);
        $options['headers'][] = "Destination: {$destination}";
        
        //设置覆盖策略：
        //- 如果指定overwrite为true，则允许覆盖已存在的目标资源
        //- 否则不允许覆盖，保留已有资源
        if (isset($options['overwrite']) && $options['overwrite']) {
            $options['headers'][] = "Overwrite: T";  // True，允许覆盖
        } else {
            $options['headers'][] = "Overwrite: F";  // False，不允许覆盖
        }
        
        //使用父类Http的request方法发送COPY请求
        $response = Http::request('COPY', $source, $options);
        return $response['http_code'] === 201 || $response['http_code'] === 204;
    }

    /**
     * 锁定WebDAV服务器上的资源
     * 
     * 通过LOCK方法创建资源的独占锁，防止其他客户端修改该资源。锁定成功后返回锁令牌，
     * 该令牌用于后续的解锁操作或更新操作。
     * 
     * @param string $path 要锁定的资源路径，必须是完整的URL
     * @param array $options HTTP请求选项数组，支持以下参数：
     *                      - headers: 自定义HTTP头信息数组
     *                      - timeout: 请求超时时间（秒）
     *                      - user_agent: 自定义User-Agent
     *                      - auth: 认证信息数组，格式为['username', 'password']
     *                      - owner: 锁所有者信息，默认为'Candy Framework'
     * @return string|false 锁定成功返回锁令牌字符串，失败返回false
     * 
     * @example
     * ```php
     * // 锁定资源
     * $token = Webdav::lock(
     *     'https://example.com/webdav/important.txt',
     *     ['owner' => 'admin@example.com']
     * );
     * if ($token) {
     *     echo '锁定成功，令牌：' . $token;
     *     // 使用锁令牌更新文件...
     * }
     * ```
     */
    public static function lock(string $path, array $options = []) {
        $options = self::normalizeOptions($options);
        // owner 需 XML 转义，防止特殊字符破坏报文
        $owner = htmlspecialchars((string) ($options['owner'] ?? 'Candy Framework'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="utf-8" ?>
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
                <D:owner>' . $owner . '</D:owner>
            </D:lockinfo>';
        
        $options['headers'][] = 'Content-Type: application/xml; charset="utf-8"';
        $options['data'] = $xml;
        
        $response = Http::request('LOCK', $path, $options);
        
        if ($response['http_code'] === 200) {
            return self::parseLockToken($response['content']);
        }
        return false;
    }

    /**
     * 解锁WebDAV服务器上的资源
     * 
     * 通过UNLOCK方法解除先前锁定的资源，允许其他客户端访问和修改该资源。
     * 解锁操作需要提供有效的锁令牌。
     * 
     * @param string $path 要解锁的资源路径，必须是完整的URL
     * @param string $token 锁令牌，由lock方法返回
     * @param array $options HTTP请求选项数组，支持以下参数：
     *                      - headers: 自定义HTTP头信息数组
     *                      - timeout: 请求超时时间（秒）
     *                      - user_agent: 自定义User-Agent
     *                      - auth: 认证信息数组，格式为['username', 'password']
     * @return bool 解锁成功返回true，失败返回false
     * 
     * @example
     * ```php
     * // 先锁定资源获取令牌
     * $token = Webdav::lock('https://example.com/webdav/important.txt');
     * 
     * // 完成操作后解锁
     * if ($token) {
     *     $success = Webdav::unlock(
     *         'https://example.com/webdav/important.txt',
     *         $token
     *     );
     *     if ($success) {
     *         echo '解锁成功';
     *     }
     * }
     * ```
     */
    public static function unlock(string $path, string $token, array $options = []) {
        $options = self::normalizeOptions($options);
        $options['headers'][] = "Lock-Token: <{$token}>";
        $response = Http::request('UNLOCK', $path, $options);
        return $response['http_code'] === 204;
    }

    /**
     * 解析WebDAV PROPFIND方法返回的XML响应内容
     * 
     * 该私有方法负责将WebDAV服务器返回的XML格式响应解析为结构化的PHP数组，
     * 提取出资源的各种属性信息，如文件路径、显示名称、内容长度、创建日期等。
     * 
     * @param string $xml WebDAV服务器返回的XML响应内容
     * @return array 返回解析后的资源属性数组，每个元素包含一个资源的完整属性
     */
    private static function parsePropfindResponse(string $xml): array {
        $result = [];
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        
        $responses = $dom->getElementsByTagName('response');
        
        foreach ($responses as $response) {
            $item = [];
            $hrefNode = $response->getElementsByTagName('href')->item(0);
            $item['href'] = $hrefNode ? $hrefNode->nodeValue : '';
            
            $propstat = $response->getElementsByTagName('propstat')->item(0);
            if (!$propstat) {
                $result[] = $item;
                continue;
            }
            $props = $propstat->getElementsByTagName('prop')->item(0);
            if (!$props) {
                $result[] = $item;
                continue;
            }
            
            foreach ($props->childNodes as $prop) {
                if ($prop instanceof \DOMElement) {
                    $item[$prop->localName] = $prop->nodeValue;
                }
            }
            
            $result[] = $item;
        }
        
        return $result;
    }

    /**
     * 从WebDAV LOCK方法返回的XML响应中提取锁令牌
     * 
     * 该私有方法负责解析WebDAV服务器返回的锁响应XML，提取出资源的锁令牌信息，
     * 该令牌用于后续的UNLOCK操作或带锁的资源更新操作。
     * 
     * @param string $xml WebDAV服务器返回的XML响应内容
     * @return string|false 成功时返回锁令牌字符串，失败时返回false
     */
    private static function parseLockToken(string $xml) {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        
        $locktoken = $dom->getElementsByTagName('locktoken')->item(0);
        if ($locktoken) {
            return $locktoken->getElementsByTagName('href')->item(0)->nodeValue;
        }
        return false;
    }
}
