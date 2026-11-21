<?php
/**
 * GlobalData CAS 存储适配（供 Simulated 结构 Trait 使用）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

use GlobalData\Client;
use Throwable;

trait GdCasStorage
{
    use CasRawMutation;

    abstract protected function getGdClient(): Client;

    abstract protected function getGdBucketKey(): string;

    abstract protected function getGdMaxKeyCount(): int;

    /** @param array<string, array> $bucket */
    abstract protected function gdLruElimination(array $bucket): array;

    protected function simulatedCasLoad(string $key, string $packMode = 'data'): array
    {
        try {
            $gd = $this->getGdClient();
            $bucket = $gd->{$this->getGdBucketKey()} ?? [];
            $now = time();

            if (!isset($bucket[$key])) {
                $load = ['found' => false, 'data' => null, 'ctx' => ['bucket' => $bucket]];
                $this->simulatedCasReportSuccess();

                return $load;
            }

            $item = $bucket[$key];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $load = ['found' => false, 'data' => null, 'ctx' => ['bucket' => $bucket, 'expired' => true]];
                $this->simulatedCasReportSuccess();

                return $load;
            }

            $load = [
                'found' => true,
                'data' => $this->simulatedUnpack($item['value'], $packMode),
                'ctx' => [
                    'bucket' => $bucket,
                    'item' => $item,
                ],
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
        try {
            $gd = $this->getGdClient();
            $now = time();
            $oldBucket = $ctx['bucket'] ?? ($gd->{$this->getGdBucketKey()} ?? []);
            $newBucket = $oldBucket;

            if ($delete) {
                unset($newBucket[$key]);
            } else {
                $prev = $newBucket[$key] ?? ($ctx['item'] ?? []);
                $newBucket[$key] = [
                    'value' => $this->simulatedPack($data, $packMode),
                    'expire' => $prev['expire'] ?? 0,
                    'ref_count' => ($prev['ref_count'] ?? 0) + 1,
                    'last_use' => $now,
                ];

                if (count($newBucket) > $this->getGdMaxKeyCount()) {
                    $newBucket = $this->gdLruElimination($newBucket);
                }
            }

            $success = (bool)$gd->cas($this->getGdBucketKey(), $oldBucket, $newBucket);
            $this->simulatedCasReportCommit($success);

            return $success;
        } catch (Throwable) {
            $this->simulatedCasReportCommit(false);

            return false;
        }
    }

    protected function simulatedCasDeleteKey(string $key): bool
    {
        return $this->simulatedCasCommit($key, null, ['bucket' => $this->getGdBucketSnapshot()], true);
    }

    /** @return array<string, array> */
    protected function getGdBucketSnapshot(): array
    {
        try {
            $gd = $this->getGdClient();

            return $gd->{$this->getGdBucketKey()} ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param callable(bool $found, mixed $data, array $ctx): ?array $modifier
     */
    protected function simulatedGdCasMutate(
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

            $delete = !empty($result['delete']);
            $data = $result['data'] ?? null;

            if (!$delete && !array_key_exists('data', $result)) {
                return $result['return'] ?? null;
            }

            if ($this->simulatedCasCommit($key, $data, $load['ctx'], $delete, $packMode)) {
                if ($delete) {
                    $this->deleteHotCache($key);
                } elseif (!empty($result['refresh_hot_cache']) || !array_key_exists('refresh_hot_cache', $result)) {
                    $this->simulatedRefreshHotCache($key, $data);
                }

                return $result['return'] ?? null;
            }

            usleep(1000);
        }

        $this->simulatedCasReportCommit(false);

        return null;
    }

    protected function simulatedGdUpdateAccessMeta(string $key, array $item): void
    {
        try {
            $gd = $this->getGdClient();
            $retryCount = 0;
            $maxRetry = 2;

            do {
                $oldBucket = $gd->{$this->getGdBucketKey()} ?? [];
                if (!isset($oldBucket[$key])) {
                    break;
                }

                $newBucket = $oldBucket;
                $newBucket[$key]['ref_count']++;
                $newBucket[$key]['last_use'] = time();

                if ($gd->cas($this->getGdBucketKey(), $oldBucket, $newBucket)) {
                    break;
                }

                $retryCount++;
            } while ($retryCount < $maxRetry);
        } catch (Throwable) {
        }
    }
}

trait GdSimulatedBridge
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
        return $this->simulatedGdCasMutate($key, $modifier, $maxRetries, $packMode);
    }
}
