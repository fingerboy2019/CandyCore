<?php
/**
 * Swoole 协程请求的缓存会话
 *
 * 业务说明：在不启动 PHP 原生 Session 的前提下，复用 Candy\Extend\Session
 * 已有的缓存键和 `php` 序列化数据格式；每个实例只归属当前请求协程。
 *
 * @package Candy\Core
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SwooleSession
{
    private array $data = [];
    private bool $dirty = false;
    private bool $destroyed = false;
    private int $lifetime;

    public function __construct(
        private readonly object $cache,
        private readonly string $id
    ) {
        $this->lifetime = (int) ini_get('session.gc_maxlifetime') ?: 1440;
        $raw = $this->cache->get($this->cacheKey(), '');
        if (is_string($raw) && $raw !== '') {
            $this->data = $this->decode($raw);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
        $this->dirty = true;
    }

    public function delete(string $name): void
    {
        unset($this->data[$name]);
        $this->dirty = true;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function flush(): void
    {
        $this->data = [];
        $this->dirty = true;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }
        if ($this->destroyed || $this->data === []) {
            $this->cache->delete($this->cacheKey());
        } else {
            $this->cache->set($this->cacheKey(), $this->encode($this->data), $this->lifetime);
        }
        $this->dirty = false;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
        $this->dirty = true;
        $this->save();
    }

    private function cacheKey(): string
    {
        return (string) (G('TABPREFIX') ?: 'session_') . $this->id;
    }

    /**
     * 解码会话缓存，兼容 Workerman 的完整数组序列化和 PHP 原生会话格式。
     *
     * @param string $raw 缓存中的会话字符串
     * @return array<string,mixed> 解码后的会话数据
     */
    private function decode(string $raw): array
    {
        $whole = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($whole)) {
            return $whole;
        }

        $data = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            $separator = strpos($raw, '|', $offset);
            if ($separator === false || $separator === $offset) {
                break;
            }
            $key = substr($raw, $offset, $separator - $offset);
            $valueOffset = $separator + 1;
            $valueLength = $this->serializedValueLength($raw, $valueOffset);
            if ($valueLength === null) {
                break;
            }
            $valueRaw = substr($raw, $valueOffset, $valueLength);
            $data[$key] = @unserialize($valueRaw, ['allowed_classes' => false]);
            $offset = $valueOffset + $valueLength;
        }

        return $data;
    }

    /**
     * 编码为 PHP 原生会话处理器使用的键值序列。
     *
     * @param array<string,mixed> $data 会话数据
     * @return string 编码后的会话字符串
     */
    private function encode(array $data): string
    {
        $encoded = '';
        foreach ($data as $key => $value) {
            $key = (string)$key;
            if ($key === '' || str_contains($key, '|')) {
                continue;
            }
            $encoded .= $key . '|' . serialize($value);
        }
        return $encoded;
    }

    /**
     * 计算一段 PHP 序列化值的字节长度，以便解析原生会话的连续键值。
     *
     * @param string $raw 完整会话字符串
     * @param int $offset 序列化值起始偏移
     * @return int|null 序列化值长度，格式非法时返回 null
     */
    private function serializedValueLength(string $raw, int $offset): ?int
    {
        $length = strlen($raw);
        if ($offset < 0 || $offset >= $length) {
            return null;
        }

        $type = $raw[$offset];
        if (in_array($type, ['N', 'b', 'i', 'd', 'R', 'r'], true)) {
            $end = strpos($raw, ';', $offset);
            return $end === false ? null : $end + 1 - $offset;
        }

        if ($type === 's') {
            $cursor = $offset + 2;
            $stringLength = $this->readSerializedInteger($raw, $cursor);
            if ($stringLength === null || substr($raw, $cursor, 2) !== ':"') {
                return null;
            }
            $valueStart = $cursor + 2;
            $valueEnd = $valueStart + $stringLength;
            if ($valueEnd + 2 > $length || substr($raw, $valueEnd, 2) !== '";') {
                return null;
            }
            return $valueEnd + 2 - $offset;
        }

        if ($type === 'a' || $type === 'O') {
            $cursor = $offset + 2;
            $headerLength = $this->readSerializedInteger($raw, $cursor);
            if ($headerLength === null || $cursor >= $length || $raw[$cursor] !== ':') {
                return null;
            }
            $cursor++;
            if ($type === 'O') {
                if (substr($raw, $cursor, 2) !== '"') {
                    return null;
                }
                $cursor++;
                $cursor += $headerLength;
                if (substr($raw, $cursor, 2) !== '":') {
                    return null;
                }
                $cursor += 2;
                $memberCount = $this->readSerializedInteger($raw, $cursor);
                if ($memberCount === null || substr($raw, $cursor, 2) !== ':{') {
                    return null;
                }
                $cursor += 2;
                $itemCount = $memberCount * 2;
            } else {
                if ($raw[$cursor] !== '{') {
                    return null;
                }
                $cursor++;
                $itemCount = $headerLength * 2;
            }
            if ($itemCount < 0 || $itemCount > 200000) {
                return null;
            }
            for ($i = 0; $i < $itemCount; $i++) {
                $itemLength = $this->serializedValueLength($raw, $cursor);
                if ($itemLength === null) {
                    return null;
                }
                $cursor += $itemLength;
            }
            if (($raw[$cursor] ?? '') !== '}') {
                return null;
            }
            return $cursor + 1 - $offset;
        }

        if ($type === 'C') {
            $cursor = $offset + 2;
            $classLength = $this->readSerializedInteger($raw, $cursor);
            if ($classLength === null || substr($raw, $cursor, 2) !== ':"') {
                return null;
            }
            $cursor += 2 + $classLength;
            if (substr($raw, $cursor, 2) !== '":') {
                return null;
            }
            $cursor += 2;
            $payloadLength = $this->readSerializedInteger($raw, $cursor);
            if ($payloadLength === null || ($raw[$cursor] ?? '') !== '{') {
                return null;
            }
            $payloadStart = $cursor + 1;
            $payloadEnd = $payloadStart + $payloadLength;
            if (($raw[$payloadEnd] ?? '') !== '}') {
                return null;
            }
            return $payloadEnd + 1 - $offset;
        }

        if ($type === 'E') {
            $end = strpos($raw, ';', $offset);
            return $end === false ? null : $end + 1 - $offset;
        }

        return null;
    }

    /**
     * 读取序列化头部中的十进制整数。
     *
     * @param string $raw 完整序列化字符串
     * @param int &$offset 当前读取偏移，会移动到整数后的字符
     * @return int|null 解析出的整数
     */
    private function readSerializedInteger(string $raw, int &$offset): ?int
    {
        $length = strlen($raw);
        $start = $offset;
        while ($offset < $length && ctype_digit($raw[$offset])) {
            $offset++;
        }
        if ($start === $offset) {
            return null;
        }
        return (int)substr($raw, $start, $offset - $start);
    }
}
