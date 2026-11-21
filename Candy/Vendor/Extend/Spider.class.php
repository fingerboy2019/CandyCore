<?php
/**
 * Spider 蜘蛛类
 * 
 * 该类用于实现PHP蜘蛛程序，旨在模仿 JS 版 TVBox Spider 的写法，简化 PHP 源开发。
 * 在道长drpy-node组件 BaseSpider 的基础上进一步优化适合框架使用
 * 源地址：https://github.com/hjdhnx/drpy-node/
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-08-18 19:19:11 $
 */
declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class Spider {
    
    // 默认请求头
    protected $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language' => 'zh-CN,zh;q=0.9',
    ];

    /**
     * @var HtmlParser
     */
    protected $htmlParser;

    public function __construct() {
        // 屏蔽一般警告，避免污染 JSON 输出
        error_reporting(E_ALL);
        ini_set('display_errors', '1');

        $container = \Candy\Core\Container::getInstance();
        $this->htmlParser = $container->get('HtmlParser')
            ?? $container->instance('HtmlParser', $container->make(HtmlParser::class))->get('HtmlParser');
    }
    
    /**
     * 代理请求 (可选)
     * @param array $params
     * @return mixed
     */
    public function localProxy($params) {
        return null;
    }

    /**
     * 执行 Action (可选)
     * @param string $action 动作名称
     * @param string $value 参数值
     * @return mixed
     */
    public function action($action, $value) {
        return '';
    }
    
    protected function pdfa($html, $rule) {
        return $this->htmlParser->pdfa($html, $rule);
    }
    
    protected function pdfh($html, $rule, $baseUrl = '') {
        return $this->htmlParser->pdfh($html, $rule, $baseUrl);
    }
    
    protected function pd($html, $rule, $baseUrl = '') {
        if (empty($baseUrl)) {
            $baseUrl = $this->tryGetHost();
        }
        return $this->htmlParser->pd($html, $rule, $baseUrl);
    }
    
    private function tryGetHost() {
        try {
            $ref = new \ReflectionClass($this);

            // 1. 尝试获取 HOST 属性 (优先)
            if ($ref->hasProperty('HOST')) {
                $prop = $ref->getProperty('HOST');
                $val = $prop->getValue($this);
                if (!empty($val)) {
                    return $val;
                }
            }

            // 2. 尝试获取 const HOST 常量
            if ($ref->hasConstant('HOST')) {
                return $ref->getConstant('HOST');
            }
        } catch (\Exception $e) {
            // ignore
        }
        return '';
    }

    /**
     * 快速构建分页返回结果
     * @param array $list 视频列表
     * @param int $pg 当前页码
     * @param int $total 总记录数 (可选)
     * @param int $limit 每页条数 (默认 20)
     * @return array
     */
    protected function pageResult($list, $pg, $total = 0, $limit = 20) {
        $pg = max(1, intval($pg));
        $count = count($list);
        
        if ($total > 0) {
            $pagecount = ceil($total / $limit);
        } else {
            // 如果没有提供 total，尝试根据当前列表数量估算
            if ($count < $limit) {
                // 当前页数据少于限制，说明是最后一页
                $pagecount = $pg;
                $total = ($pg - 1) * $limit + $count;
            } else {
                // 还有下一页，设置一个较大的页数
                $pagecount = 9999;
                $total = 99999;
            }
        }
        
        return [
            'list' => $list,
            'page' => $pg,
            'pagecount' => intval($pagecount),
            'limit' => intval($limit),
            'total' => intval($total)
        ];
    }

    /**
     * 封装 HTTP 请求
     * @param string $url 请求地址
     * @param array $options CURL 选项
     * @param array $headers 请求头
     * @return string|bool
     */
    protected function fetch($url, $options = [], $headers = []) {
        if (!$this->isPublicUrl((string)$url)) {
            return false;
        }

        // 支持从 options 中传递 headers
        if (isset($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
            unset($options['headers']);
        }

        $ch = curl_init();
        if ($ch === false) {
            return false;
        }
        
        // 1. 解析自定义 header 为关联数组
        $customHeaders = [];
        foreach ($headers as $k => $v) {
            if (is_numeric($k)) {
                // 处理 "Key: Value" 格式
                $parts = explode(':', $v, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $customHeaders[$key] = $value;
                }
            } else {
                $customHeaders[$k] = $v;
            }
        }

        // 2. 合并请求头 (自定义覆盖默认)
        $finalHeadersMap = array_merge($this->headers, $customHeaders);

        // 3. 转换回 CURL 所需的索引数组
        $mergedHeaders = [];
        foreach ($finalHeadersMap as $k => $v) {
            if ($v === "") {
                // To send empty header in CURL, use "Header;" (no colon)
                $mergedHeaders[] = $k . ";";
            } else {
                $mergedHeaders[] = "$k: $v";
            }
        }

        $defaultOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXFILESIZE => 33554432,
            CURLOPT_ENCODING => '', // 支持 GZIP 自动解压
            CURLOPT_HTTPHEADER => $mergedHeaders,
        ];

        // 处理 POST 数据
        if (isset($options['body'])) {
            $defaultOptions[CURLOPT_POST] = true;
            $defaultOptions[CURLOPT_POSTFIELDS] = $options['body'];
            unset($options['body']);
        }
        
        // 处理 Cookie
        if (isset($options['cookie'])) {
            $defaultOptions[CURLOPT_COOKIE] = $options['cookie'];
            unset($options['cookie']);
        }

        // 合并用户自定义选项
        foreach ($options as $k => $v) {
            $defaultOptions[$k] = $v;
        }

        // 禁止调用方通过自定义选项关闭 TLS 或打开跨主机重定向。
        $defaultOptions[CURLOPT_SSL_VERIFYPEER] = true;
        $defaultOptions[CURLOPT_SSL_VERIFYHOST] = 2;
        $defaultOptions[CURLOPT_FOLLOWLOCATION] = false;
        $defaultOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $defaultOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;

        curl_setopt_array($ch, $defaultOptions);
        $result = curl_exec($ch);
        
        
        return $result;
    }

    /**
     * 判断蜘蛛请求地址是否为公网 HTTP(S) 地址。
     *
     * @param string $url 待请求地址
     * @return bool 是否允许请求
     */
    private function isPublicUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } elseif (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            $ips = is_array($resolved) ? $resolved : [];
        }
        if ($ips === []) {
            return false;
        }

        foreach (array_unique($ips) as $ip) {
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return false;
            }
        }
        return true;
    }

    protected function fetchJson($url, $options = []) {
        $resp = $this->fetch($url, $options);
        return json_decode($resp, true) ?: [];
    }

    /**
     * 自动运行，处理路由
     */
    public function run() {
        if (defined('DRPY_BRIDGE')) {
            return;
        }
        $ac = I('get.ac', '');
        $t = I('get.t', '');
        $pg = I('get.pg', '1');
        $wd = I('get.wd', '');
        $ids = I('get.ids', '');
        $play = I('get.play', ''); // 某些源使用 play 参数传递播放ID
        $flag = I('get.flag', ''); // 播放线路
        $filter = I('get.filter') === 'true'; // 是否过滤
        $extend = I('get.ext', ''); // 扩展参数
        if (!empty($extend) && is_string($extend)) {
            $decoded = json_decode(base64_decode($extend), true);
            if (is_array($decoded)) {
                $extend = $decoded;
            }
        }
        $action = I('get.action', ''); // Action 动作
        $value = I('get.value', ''); // Action 参数

        $this->init($extend);

        try {
            // 0. Action (优先处理)
            if ($ac === 'action') {
                return json($this->action($action, $value));
            }

            // 1. 播放 (Play)
            // 优先检测 play 参数或 ac=play
            if ($ac === 'play' || !empty($play)) {
                $playId = !empty($play) ? $play : (I('get.id', ''));
                return json($this->playerContent($flag, $playId));
            }

            // 2. 搜索 (Search)
            // 有 wd 则是搜索
            if (!empty($wd)) {
                return json($this->searchContent($wd, false, $pg));
            }

            // 3. 详情 (Detail)
            // 有 ids 且 ac 不为空
            if (!empty($ids) && !empty($ac)) {
                // ids 可能是逗号分隔的字符串
                $idList = explode(',', $ids);
                return json($this->detailContent($idList));
            }

            // 4. 分类 (Category)
            // 有 t 且 ac 不为空
            if ($t !== '' && !empty($ac)) {
                // 处理 filter
                $filterData = []; // 暂未实现复杂 filter 解析，可根据需要扩展
                return json($this->categoryContent($t, $pg, $filterData, $extend));
            }

            // 5. 首页 (默认)
            // 通常返回 {class: [...], list: [...]}
            // 可以分别调用 homeContent 和 homeVideoContent 合并
            $homeData = $this->homeContent($filter);
            $videoData = $this->homeVideoContent();
            
            $result = [
                'class' => $homeData['class'] ?? [],
            ];
            
            // 如果 homeContent 只有 class，合并 homeVideoContent 的 list
            if (isset($videoData['list'])) {
                $result['list'] = $videoData['list'];
            }
            // 如果 homeContent 也有 list，优先使用 homeContent 的 list (视具体逻辑而定，这里简单的合并)
            if (isset($homeData['list']) && !empty($homeData['list'])) {
                $result['list'] = $homeData['list'];
            }
            // 兼容：如果 homeContent 返回了 filters
            if (isset($homeData['filters'])) {
                $result['filters'] = $homeData['filters'];
            }
            
            return json($result);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 初始化方法
     * @param string $extend 扩展参数
     */
    abstract function init(string $extend = ''): void;

    /**
     * 获取首页分类
     * @param array $filter 筛选条件
     * @return array ['class' => []]
     */
    abstract function homeContent($filter): array;

    /**
     * 获取首页推荐视频
     * @return array eturn ['list' => []]
     */
    abstract function homeVideoContent(): array;

    /**
     * 获取分类详情
     * @param string $tid 分类ID
     * @param int $pg 页码
     * @param array $filter 筛选条件
     * @param array $extend 扩展参数
     * @return array ['list' => [], 'page' => $pg, 'pagecount' => 1, 'limit' => 20, 'total' => 0]
     */
    abstract function categoryContent($tid, $pg = 1, $filter = [], $extend = []): array;

    /**
     * 获取视频详情
     * @param array $ids 视频ID列表
     * @return array ['list' => []]
     */
    abstract function detailContent($ids): array;

    /**
     * 搜索视频
     * @param string $key 关键词
     * @param bool $quick 快速搜索
     * @param int $pg 页码
     * @return array ['list' => []]
     */
    abstract function searchContent($key, $quick = false, $pg = 1): array;

    /**
     * 获取播放地址
     * @param string $flag 播放线路
     * @param string $id 视频播放ID
     * @param array $vipFlags VIP标识
     * @return array ['parse' => 0, 'url' => '', 'header' => []]
     */
    abstract function playerContent($flag, $id, $vipFlags = []): array;
}
