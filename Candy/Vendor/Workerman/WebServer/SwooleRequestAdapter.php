<?php
/**
 * Swoole HTTP Request 到 Candy/Workerman 请求接口的轻量适配器
 *
 * 业务说明：提供框架常用的 rawBody、cookie、header、sessionId 等只读接口；
 * Session 仍由 Candy 自定义缓存驱动初始化，避免混用 Workerman Session Handler。
 *
 * @package WebServer
 */
namespace WebServer;

use Candy\Core\Cache;
use Candy\Core\Container;
use Candy\Core\SwooleSession;
use Swoole\Http\Request;

final class SwooleRequestAdapter
{
    private ?SwooleSession $session = null;
    private ?string $sessionIdOverride = null;

    public function __construct(private readonly Request $request)
    {
    }

    public function rawBody(): string
    {
        return (string)($this->request->rawContent() ?: '');
    }

    public function cookie(?string $name = null): mixed
    {
        $cookies = is_array($this->request->cookie ?? null) ? $this->request->cookie : [];
        return $name === null || $name === '' ? $cookies : ($cookies[$name] ?? null);
    }

    public function header(?string $name = null): mixed
    {
        $headers = is_array($this->request->header ?? null) ? $this->request->header : [];
        return $name === null || $name === '' ? $headers : ($headers[strtolower($name)] ?? null);
    }

    public function sessionId(?string $sessionId = null): string
    {
        if ($sessionId !== null && $sessionId !== '') {
            $this->sessionIdOverride = preg_replace('/[^a-zA-Z0-9,-]/', '', $sessionId) ?: '';
            return $this->sessionIdOverride;
        }
        return $this->sessionIdOverride ?: (string)(($this->request->cookie['PHPSID'] ?? null)
            ?: ($this->request->cookie['PHPSESSID'] ?? ''));
    }

    public function session(?string $sessionId = null): SwooleSession
    {
        if ($sessionId !== null && $sessionId !== '') {
            $this->sessionId($sessionId);
        }
        if ($this->session !== null) {
            return $this->session;
        }
        $id = $this->sessionId();
        if ($id === '') {
            $id = bin2hex(random_bytes(24));
            $this->sessionIdOverride = $id;
            $response = Container::getInstance()->get('Response');
            if ($response !== null && method_exists($response, 'cookie')) {
                $response->cookie('PHPSESSID', $id, 0, '/', '', false, true, 'Lax');
            }
        }
        return $this->session = new SwooleSession(Cache::store(), $id);
    }

    public function saveSession(): void
    {
        $this->session?->save();
    }

    public function host(): string
    {
        return (string)($this->request->header['host'] ?? '');
    }

    public function uri(): string
    {
        return (string)($this->request->server['request_uri'] ?? '/');
    }

    public function method(): string
    {
        return strtoupper((string)($this->request->server['request_method'] ?? 'GET'));
    }
}
