<?php
/**
 * Memcached CAS 存储适配（供 Simulated 结构 Trait 使用）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait MemcachedCasStorage
{
    use CasRawMutation;

    abstract protected function getMemcachedClient(): \Memcached;

    protected function simulatedCasLoad(string $key, string $packMode = 'data'): array
    {
        try {
            $mem = $this->getMemcachedClient();
            $serialized = $mem->get($key, null, \Memcached::GET_EXTENDED);
            $code = $mem->getResultCode();

            if ($code === \Memcached::RES_NOTFOUND) {
                $load = ['found' => false, 'data' => null, 'ctx' => ['cas' => null]];
                $this->simulatedCasReportSuccess();

                return $load;
            }

            if ($code !== \Memcached::RES_SUCCESS) {
                $load = ['found' => false, 'data' => null, 'ctx' => [], 'error' => true];
                $this->simulatedCasReportLoad($load);

                return $load;
            }

            $load = [
                'found' => true,
                'data' => $this->simulatedUnpack($serialized['value'], $packMode),
                'ctx' => ['cas' => $serialized['cas'] ?? null],
            ];
            $this->simulatedCasReportSuccess();

            return $load;
        } catch (Throwable) {
            $load = ['found' => false, 'data' => null, 'ctx' => [], 'error' => true];
            $this->simulatedCasReportLoad($load);

            return $load;
        }
    }

    protected function simulatedCasCommit(
        string $key,
        mixed $data,
        array $ctx,
        bool $delete = false,
        string $packMode = 'data'
    ): bool {
        if ($delete) {
            $success = $this->simulatedCasDeleteKey($key);
            $this->simulatedCasReportCommit($success);

            return $success;
        }

        try {
            $mem = $this->getMemcachedClient();
            $packed = $this->simulatedPack($data, $packMode);
            $cas = $ctx['cas'] ?? null;

            if ($cas !== null) {
                $mem->cas($cas, $key, $packed);
            } else {
                $mem->set($key, $packed);
            }

            $success = $mem->getResultCode() === \Memcached::RES_SUCCESS;
            $this->simulatedCasReportCommit($success);

            return $success;
        } catch (Throwable) {
            $this->simulatedCasReportCommit(false);

            return false;
        }
    }

    protected function simulatedCasDeleteKey(string $key): bool
    {
        try {
            $mem = $this->getMemcachedClient();
            $mem->delete($key);

            return in_array(
                $mem->getResultCode(),
                [\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND],
                true
            );
        } catch (Throwable) {
            return false;
        }
    }

    protected function simulatedCasCreate(string $key, mixed $data, string $packMode = 'data'): bool
    {
        try {
            $mem = $this->getMemcachedClient();
            $mem->add($key, $this->simulatedPack($data, $packMode));

            return $mem->getResultCode() === \Memcached::RES_SUCCESS;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 带 add 降级的 CAS 变更（计数器/队列入队等）
     *
     * @param callable(bool $found, mixed $data, array $ctx): ?array $modifier
     */
    protected function simulatedMemcachedCasMutate(
        string $key,
        callable $modifier,
        int $maxRetries = 3,
        string $packMode = 'data'
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

            if (!empty($result['delete'])) {
                if ($this->simulatedCasDeleteKey($key)) {
                    $this->deleteHotCache($key);
                    return $result['return'] ?? null;
                }
                continue;
            }

            if (!array_key_exists('data', $result)) {
                return $result['return'] ?? null;
            }

            $packed = $this->simulatedPack($result['data'], $packMode);
            $mem = $this->getMemcachedClient();
            $success = false;
            $cas = $load['ctx']['cas'] ?? null;

            if ($cas !== null) {
                $mem->cas($cas, $key, $packed);
                $success = $mem->getResultCode() === \Memcached::RES_SUCCESS;
            } else {
                $mem->add($key, $packed);
                $success = $mem->getResultCode() === \Memcached::RES_SUCCESS;
                if (!$success) {
                    continue;
                }
            }

            if ($success) {
                if (!empty($result['refresh_hot_cache']) || !array_key_exists('refresh_hot_cache', $result)) {
                    $this->simulatedRefreshHotCache($key, $result['data']);
                } elseif (($result['refresh_hot_cache'] ?? null) === false) {
                    $this->deleteHotCache($key);
                }
                $this->simulatedCasReportSuccess();

                return $result['return'] ?? null;
            }
        }

        $this->simulatedCasReportCommit(false);

        return null;
    }
}

trait MemcachedSimulatedBridge
{
    /**
     * @param callable(bool $found, mixed $data, array $ctx): ?array $modifier
     */
    protected function simulatedDriverCasMutate(
        string $key,
        callable $modifier,
        int $maxRetries = 3,
        string $packMode = 'data'
    ): mixed {
        return $this->simulatedMemcachedCasMutate($key, $modifier, $maxRetries, $packMode);
    }
}
