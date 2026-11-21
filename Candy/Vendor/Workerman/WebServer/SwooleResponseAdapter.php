<?php
/**
 * Swoole HTTP Response 到 Candy Respond 接口的适配器
 *
 * @package WebServer
 *
 * $Date: 2026-09-18 04:38:03 $
 */
namespace WebServer;

use Swoole\Http\Response;

final class SwooleResponseAdapter
{
    private ?string $body = null;
    private int $status = 200;
    private array $headers = [];
    private array $cookies = [];
    private bool $committed = false;
    private bool $streamStarted = false;

    public function __construct(private readonly Response $response)
    {
    }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = array_map('trim', explode(':', $value, 2));
            }
            if (is_string($name) && $name !== '') {
                $this->header($name, (string)$value);
            }
        }
        return $this;
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function withBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function header(string $name, ?string $value = null): static
    {
        if ($value !== null) {
            $this->headers[strtolower($name)] = [$name, $value];
        }
        return $this;
    }

    public function getHeader(string $name): mixed
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    public function status(int $status): static
    {
        return $this->withStatus($status);
    }

    public function cookie(string $name, ?string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = ''): bool
    {
        $this->cookies[] = [$name, $value ?? '', $expire, $path, $domain, $secure, $httpOnly, $sameSite];
        return true;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function statusCode(): int
    {
        return $this->status;
    }

    /**
     * 向已建立的 SSE 响应写入一段数据。
     *
     * @param string $chunk SSE 帧
     * @return void
     */
    public function write(string $chunk): void
    {
        if ($this->committed) {
            return;
        }
        if (!$this->streamStarted) {
            $this->applyResponseState();
            $this->streamStarted = true;
        }
        $this->response->write($chunk);
    }

    /**
     * 结束当前 SSE 响应。
     *
     * @return void
     */
    public function endStream(): void
    {
        if ($this->committed) {
            return;
        }
        if (!$this->streamStarted) {
            $this->applyResponseState();
            $this->streamStarted = true;
        }
        $this->response->end();
        $this->committed = true;
    }

    /**
     * 判断原生响应是否已经结束。
     *
     * @return bool 是否已提交或结束
     */
    public function isCommitted(): bool
    {
        return $this->committed;
    }

    /** 将当前请求累积的响应状态一次性写入 Swoole 原生 Response。 */
    public function commit(?string $fallbackBody = null): void
    {
        if ($this->committed) {
            return;
        }
        $this->committed = true;

        $this->applyResponseState();
        $this->response->end($this->body ?? $fallbackBody ?? '');
    }

    /**
     * 将当前请求累积的状态同步到 Swoole 原生响应。
     *
     * @return void
     */
    private function applyResponseState(): void
    {
        // 兼容尚未迁移到 headers()/Respond 的旧业务代码。新代码应直接调用本适配器，
        // 以避免依赖 PHP 原生 header 缓冲区。
        foreach (headers_list() as $line) {
            if (preg_match('#^HTTP/[^ ]+\\s+(\\d{3})#i', $line, $matches)) {
                $this->status = (int) $matches[1];
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($name);
            if (!isset($this->headers[$key])) {
                $this->headers[$key] = [$name, $value];
            }
        }
        $this->response->status($this->status);
        foreach ($this->headers as [$name, $value]) {
            $this->response->header($name, $value);
        }
        foreach ($this->cookies as [$name, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite]) {
            $this->response->cookie($name, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite);
        }
    }
}
