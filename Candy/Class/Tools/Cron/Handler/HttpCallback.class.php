<?php
/***
 * 框架内置任务处理器：HTTP 回调
 *
 * 为什么框架要自带：`Workerman\Crontab\Manager` 的 handler 靠 `Config::get()['handlers']` 解析，
 * 而 candy SaaS 版的 `Config::loadProjectConfig()` 只从 `Application/Admin/Services/Jobs/` 扫，
 * 裸框架里那个目录不存在 → handler 列表为空 → 任何任务都执行不了（`handler invalid`）。
 * 这个类让「装上框架就能跑任务」成立，不依赖任何项目代码。
 *
 * handler 约定（与 Manager::resolveHandler / runJob 对齐）：
 *   `new $class()` → `$instance->$method($params)` → 返回 `['code'=>int,'message'=>string]`
 *
 * params：
 *   url             必填，http(s)://
 *   method          get | post（默认 get）
 *   body            POST 数据（数组或 JSON 字符串）
 *   timeout         连接/总超时秒数（默认 10，上限 30）
 *   expected_status 期望状态码（默认 2xx 都算成功）
 *
 * @package Candy\Extend\Tools\Cron\Handler
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 14:20:00
 */
namespace Candy\Extend\Tools\Cron\Handler;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class HttpCallback
{
    /** handler 标识（任务里写 handler: 'http_callback'） */
    public const HANDLER_ID = 'http_callback';

    /** 展示名 */
    public const HANDLER_TITLE = 'HTTP 回调（框架内置）';

    /** 说明 */
    public const HANDLER_DESC = '定时请求指定 URL，可 GET/POST；适用于触发外部接口或 Webhook';

    /**
     * 执行
     *
     * @param array<string, mixed> $params 参数
     * @return array{code:int, message:string}
     */
    public function run(array $params = []): array
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['code' => 1, 'message' => 'url 必填且必须是 http(s)://'];
        }

        // 与 Workerman\Crontab\Config 的 http_callback.block_private_ip 保持一致：
        // 默认拒绝内网地址，避免任务被当成 SSRF 跳板（同机调试可显式允许）
        $blockPrivate = (bool) ($params['block_private_ip'] ?? true);
        if ($blockPrivate && $this->isPrivateHost($url)) {
            return ['code' => 1, 'message' => '拒绝内网地址（如需允许请传 block_private_ip=false）'];
        }

        $method = strtolower(trim((string) ($params['method'] ?? 'get'))) === 'post' ? 'post' : 'get';
        $body = $params['body'] ?? [];
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : ['raw' => $body];
        }
        if (!is_array($body)) {
            $body = [];
        }

        $timeout = (int) ($params['timeout'] ?? 10);
        $timeout = max(1, min(30, $timeout));

        $expected = (int) ($params['expected_status'] ?? 0);

        $t0 = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($method === 'post') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 真实耗时：以前只有 code 0/1，出问题时完全看不出「慢在哪」还是「根本连不上」
        $durationMs = round((microtime(true) - $t0) * 1000, 1);

        if ($response === false) {
            return [
                'code'        => 1,
                'message'     => '请求失败：' . $error,
                'duration_ms' => $durationMs,
                'status'      => 0,
                'error'       => $error,
            ];
        }

        // 响应体截断后带回（失败时最需要它：对方 500 到底说了什么）
        $body = is_string($response) ? $response : '';
        if (strlen($body) > 500) {
            $body = substr($body, 0, 500) . '…';
        }
        $body = preg_replace('/\s+/u', ' ', trim($body));

        if ($expected > 0) {
            if ($status !== $expected) {
                return [
                    'code'        => 1,
                    'message'     => '状态码不符：期望 ' . $expected . '，实际 ' . $status
                        . ($body !== '' ? '，响应：' . $body : ''),
                    'duration_ms' => $durationMs,
                    'status'      => $status,
                    'body'        => $body,
                ];
            }
            return [
                'code'        => 0,
                'message'     => 'HTTP ' . $status . '（符合期望，' . $durationMs . 'ms）',
                'duration_ms' => $durationMs,
                'status'      => $status,
                'body'        => $body,
            ];
        }

        if ($status >= 200 && $status < 300) {
            return [
                'code'        => 0,
                'message'     => 'HTTP ' . $status . '（' . $durationMs . 'ms）',
                'duration_ms' => $durationMs,
                'status'      => $status,
                'body'        => $body,
            ];
        }

        return [
            'code'        => 1,
            'message'     => 'HTTP ' . $status . ($body !== '' ? '：' . $body : ''),
            'duration_ms' => $durationMs,
            'status'      => $status,
            'body'        => $body,
        ];
    }

    /**
     * 是否内网 / 本机地址
     *
     * @param string $url 目标地址
     * @return bool
     */
    protected function isPrivateHost(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return true;
        }
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
            return true;
        }

        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
            // 解析不出来（域名无效）：交给 curl 去报错，不在这里拦
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
