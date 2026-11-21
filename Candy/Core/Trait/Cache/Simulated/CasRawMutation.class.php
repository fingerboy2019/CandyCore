<?php
/**
 * CAS 读-改-写原语（Memcached / GlobalData 模拟结构层共用）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait CasRawMutation
{
  /**
   * @return array{found: bool, data: mixed, ctx: array, error?: bool}
   */
    abstract protected function simulatedCasLoad(string $key, string $packMode = 'data'): array;

    abstract protected function simulatedCasCommit(
        string $key,
        mixed $data,
        array $ctx,
        bool $delete = false,
        string $packMode = 'data'
    ): bool;

    abstract protected function simulatedCasDeleteKey(string $key): bool;

    protected function simulatedPack(mixed $data, string $packMode = 'data'): string
    {
        return $packMode === 'raw'
            ? Binary::pack($data)
            : Binary::pack(['data' => $data]);
    }

    protected function simulatedUnpack(string $serialized, string $packMode = 'data'): mixed
    {
        $unpacked = Binary::tryUnpack($serialized);

        return $packMode === 'raw'
            ? (is_array($unpacked) ? $unpacked : null)
            : (is_array($unpacked) && array_key_exists('data', $unpacked) ? $unpacked['data'] : null);
    }

    protected function simulatedRefreshHotCache(string $key, mixed $value): void
    {
        $ttl = $this->__ttl($key);
        $this->setHotCache($key, $value, $ttl > 0 ? time() + $ttl : 0);
    }

    protected function simulatedReadFromHotCache(string $key): mixed
    {
        if (!$this->enableLocalCache || !isset($this->hotCache[$key])) {
            return null;
        }

        $item = $this->hotCache[$key];
        if ($item['expire'] !== 0 && $item['expire'] < time()) {
            return null;
        }

        return $item['value'];
    }

    /**
     * @param callable(bool $found, mixed $data, array $ctx): ?array{return: mixed, data?: mixed, delete?: bool, skip_save?: bool} $modifier
     */
    protected function simulatedCasMutate(
        string $key,
        callable $modifier,
        int $maxRetries = 3,
        string $packMode = 'data',
        bool $allowCreate = false
    ): mixed {
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            $load = $this->simulatedCasLoad($key, $packMode);
            if (!empty($load['error'])) {
                return null;
            }

            $found = (bool)$load['found'];
            $result = $modifier($found, $load['data'] ?? null, $load['ctx'] ?? []);
            if ($result === null) {
                return null;
            }

            if (!empty($result['skip_save'])) {
                return $result['return'] ?? null;
            }

            $delete = !empty($result['delete']);
            if ($delete) {
                if ($this->simulatedCasDeleteKey($key)) {
                    $this->deleteHotCache($key);
                    return $result['return'] ?? null;
                }
                continue;
            }

            if (!array_key_exists('data', $result)) {
                return $result['return'] ?? null;
            }

            if (!$found && !$allowCreate) {
                return $result['return'] ?? null;
            }

            if ($this->simulatedCasCommit($key, $result['data'], $load['ctx'], false, $packMode)) {
                if (array_key_exists('refresh_hot_cache', $result) && $result['refresh_hot_cache'] === false) {
                    $this->deleteHotCache($key);
                } else {
                    $this->simulatedRefreshHotCache($key, $result['data']);
                }
                return $result['return'] ?? null;
            }
        }

        return null;
    }

    protected function simulatedReadValue(string $key, mixed $default = null, string $packMode = 'data'): mixed
    {
        $hot = $this->simulatedReadFromHotCache($key);
        if ($hot !== null) {
            return $hot;
        }

        try {
            $load = $this->simulatedCasLoad($key, $packMode);
            if (empty($load['found']) || !empty($load['error'])) {
                return $default;
            }

            $this->simulatedRefreshHotCache($key, $load['data']);
            return $load['data'];
        } catch (Throwable) {
            return $default;
        }
    }

    protected function simulatedReadArray(string $key, string $packMode = 'data'): array
    {
        $value = $this->simulatedReadValue($key, [], $packMode);
        return is_array($value) ? $value : [];
    }
}
