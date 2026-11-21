<?php
/**
 * GlobalData 键级操作（expire / persist / rename）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

use Throwable;

trait GdKeyOps
{
    protected function simulatedKeyExpire(string $key, int $ttl): bool
    {
        try {
            $gd = $this->getGdClient();
            $expire = $ttl > 0 ? time() + $ttl : 0;
            $maxRetry = 3;

            for ($retryCount = 0; $retryCount < $maxRetry; $retryCount++) {
                $oldBucket = $gd->{$this->getGdBucketKey()} ?? [];
                if (!isset($oldBucket[$key])) {
                    return false;
                }

                $newBucket = $oldBucket;
                $newBucket[$key]['expire'] = $expire;

                if ($gd->cas($this->getGdBucketKey(), $oldBucket, $newBucket)) {
                    if (isset($this->hotCache[$key])) {
                        $this->hotCache[$key]['expire'] = $expire;
                    }
                    return true;
                }

                usleep(1000);
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    protected function simulatedKeyPersist(string $key): bool
    {
        try {
            $gd = $this->getGdClient();
            $maxRetry = 3;

            for ($retryCount = 0; $retryCount < $maxRetry; $retryCount++) {
                $oldBucket = $gd->{$this->getGdBucketKey()} ?? [];
                if (!isset($oldBucket[$key])) {
                    return false;
                }

                $newBucket = $oldBucket;
                $newBucket[$key]['expire'] = 0;

                if ($gd->cas($this->getGdBucketKey(), $oldBucket, $newBucket)) {
                    if (isset($this->hotCache[$key])) {
                        $this->hotCache[$key]['expire'] = 0;
                    }
                    return true;
                }

                usleep(1000);
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    protected function simulatedKeyRename(string $oldKey, string $newKey): bool
    {
        try {
            $gd = $this->getGdClient();
            $maxRetry = 3;

            for ($retryCount = 0; $retryCount < $maxRetry; $retryCount++) {
                $oldBucket = $gd->{$this->getGdBucketKey()} ?? [];
                if (!isset($oldBucket[$oldKey])) {
                    return false;
                }

                if (isset($oldBucket[$newKey])) {
                    return false;
                }

                $newBucket = $oldBucket;
                $newBucket[$newKey] = $newBucket[$oldKey];
                unset($newBucket[$oldKey]);

                if ($gd->cas($this->getGdBucketKey(), $oldBucket, $newBucket)) {
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

                usleep(1000);
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }
}
