<?php
/**
 * Memcached 键级操作（expire / persist / rename）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

use Throwable;

trait MemcachedKeyOps
{
    /**
     * rename 时拒绝搬运的 value 上限（字节），0 表示不限制
     */
    private const KEY_RENAME_MAX_VALUE_BYTES = 1048576;

    protected function formatMemcachedTtl(int $ttl): int
    {
        if ($ttl === 0) {
            return 0;
        }

        return $ttl <= 2592000 ? $ttl : time() + $ttl;
    }

    protected function simulatedKeyExpire(string $key, int $ttl): bool
    {
        try {
            $mem = $this->getMemcachedClient();
            $realTtl = $this->formatMemcachedTtl($ttl);

            if (method_exists($mem, 'touch')) {
                $result = $mem->touch($key, $realTtl);
                if ($result && $mem->getResultCode() === \Memcached::RES_SUCCESS) {
                    if (isset($this->hotCache[$key])) {
                        $this->hotCache[$key]['expire'] = $ttl > 0 ? time() + $ttl : 0;
                    }
                    return true;
                }
            }

            $maxRetries = 3;
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $serialized = $mem->get($key, null, \Memcached::GET_EXTENDED);
                $code = $mem->getResultCode();

                if ($code === \Memcached::RES_NOTFOUND) {
                    return false;
                }

                if ($code !== \Memcached::RES_SUCCESS) {
                    return false;
                }

                $mem->touch($key, $realTtl);
                if ($mem->getResultCode() === \Memcached::RES_SUCCESS) {
                    if (isset($this->hotCache[$key])) {
                        $this->hotCache[$key]['expire'] = $ttl > 0 ? time() + $ttl : 0;
                    }
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    protected function simulatedKeyPersist(string $key): bool
    {
        try {
            $mem = $this->getMemcachedClient();

            if (method_exists($mem, 'touch')) {
                $result = $mem->touch($key, 0);
                if ($result && $mem->getResultCode() === \Memcached::RES_SUCCESS) {
                    if (isset($this->hotCache[$key])) {
                        $this->hotCache[$key]['expire'] = 0;
                    }
                    return true;
                }

                if ($mem->getResultCode() === \Memcached::RES_NOTFOUND) {
                    return false;
                }
            }

            $maxRetries = 3;
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $serialized = $mem->get($key, null, \Memcached::GET_EXTENDED);
                $code = $mem->getResultCode();

                if ($code === \Memcached::RES_NOTFOUND) {
                    return false;
                }

                if ($code !== \Memcached::RES_SUCCESS) {
                    return false;
                }

                $mem->set($key, $serialized['value'], 0);
                if ($mem->getResultCode() === \Memcached::RES_SUCCESS) {
                    if (isset($this->hotCache[$key])) {
                        $this->hotCache[$key]['expire'] = 0;
                    }
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    protected function simulatedKeyRename(string $oldKey, string $newKey): bool
    {
        try {
            $mem = $this->getMemcachedClient();
            $maxRetries = 3;

            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $serialized = $mem->get($oldKey, null, \Memcached::GET_EXTENDED);
                $code = $mem->getResultCode();

                if ($code === \Memcached::RES_NOTFOUND) {
                    return false;
                }

                if ($code !== \Memcached::RES_SUCCESS) {
                    return false;
                }

                $value = $serialized['value'];
                if (self::KEY_RENAME_MAX_VALUE_BYTES > 0) {
                    $valueSize = is_string($value) ? strlen($value) : strlen(serialize($value));
                    if ($valueSize > self::KEY_RENAME_MAX_VALUE_BYTES) {
                        return false;
                    }
                }
                $ttl = $this->__ttl($oldKey);
                $realTtl = $this->formatMemcachedTtl($ttl > 0 ? $ttl : 0);

                $mem->add($newKey, $value, $realTtl);
                if ($mem->getResultCode() !== \Memcached::RES_SUCCESS) {
                    continue;
                }

                $mem->delete($oldKey);

                if (isset($this->hotCache[$oldKey])) {
                    $this->hotCache[$newKey] = $this->hotCache[$oldKey];
                    unset($this->hotCache[$oldKey]);
                }
                if (isset($this->lruRecords[$oldKey])) {
                    $this->lruRecords[$newKey] = $this->lruRecords[$oldKey];
                    unset($this->lruRecords[$oldKey]);
                }

                return true;
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }
}
