<?php
/**
 * Redis Stream 模拟实现（Memcached / GlobalData 共用，packMode=raw）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

use Throwable;

trait SimulatedStream
{
    private const STREAM_PACK_MODE = 'raw';

    protected function simulatedStreamKey(string $key): string
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);

        return $paths !== null ? $mainKey : $key;
    }

    /** @return array{messages: array, groups: array} */
    protected function simulatedEmptyStream(): array
    {
        return ['messages' => [], 'groups' => []];
    }

    protected function simulatedNormalizeStream(mixed $data): array
    {
        if (!is_array($data) || !isset($data['messages'])) {
            return $this->simulatedEmptyStream();
        }

        $data['groups'] ??= [];

        return $data;
    }

    protected function simulatedGenerateStreamId(array $messages, ?string $id): string|false
    {
        if ($id === '*') {
            $nowMs = (int)(microtime(true) * 1000);
            $lastId = array_key_last($messages);
            $seq = 0;
            if ($lastId !== null) {
                [$lastTs, $lastSeq] = explode('-', (string)$lastId);
                if ((int)$lastTs === $nowMs) {
                    $seq = (int)$lastSeq + 1;
                }
            }

            return $nowMs . '-' . $seq;
        }

        if (!preg_match('/^\d+-\d+$/', (string)$id)) {
            return false;
        }

        $lastId = array_key_last($messages);
        if ($lastId !== null && strcmp((string)$id, (string)$lastId) <= 0) {
            return false;
        }

        return (string)$id;
    }

    protected function simulatedTrimStream(array &$stream, int $maxLen): void
    {
        if ($maxLen <= 0 || count($stream['messages']) <= $maxLen) {
            return;
        }

        $removeCount = count($stream['messages']) - $maxLen;
        $removeIds = array_slice(array_keys($stream['messages']), 0, $removeCount);
        foreach ($removeIds as $removeId) {
            unset($stream['messages'][$removeId]);
            foreach ($stream['groups'] as &$group) {
                unset($group['pel'][$removeId]);
            }
            unset($group);
        }
    }

  /** @return array<int, array{id: string, fields: array}> */
    protected function simulatedStreamRange(array $messages, string $start, string $end, int $count = 0): array
    {
        $result = [];
        $messageCount = 0;
        $startId = $start === '-' ? (string)(array_key_first($messages) ?? '0-0') : $start;
        $endId = $end === '+' ? (string)(array_key_last($messages) ?? '0-0') : $end;

        foreach ($messages as $id => $msg) {
            if (strcmp((string)$id, $startId) < 0) {
                continue;
            }
            if (strcmp((string)$id, $endId) > 0) {
                break;
            }

            $result[] = [
                'id' => (string)$id,
                'fields' => $msg['fields'] ?? [],
            ];
            $messageCount++;

            if ($count > 0 && $messageCount >= $count) {
                break;
            }
        }

        return $result;
    }

    public function __rawXAdd(string $key, array $fieldValues, ?string $id = '*', int $maxLen = 0): string|false
    {
        $key = $this->simulatedStreamKey($key);

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($fieldValues, $id, $maxLen) {
            $stream = $this->simulatedNormalizeStream($found ? $data : null);
            $newId = $this->simulatedGenerateStreamId($stream['messages'], $id);
            if ($newId === false) {
                return ['return' => false, 'skip_save' => true];
            }

            $stream['messages'][$newId] = [
                'fields' => $fieldValues,
                'time' => microtime(true),
            ];
            $this->simulatedTrimStream($stream, $maxLen);

            return ['return' => $newId, 'data' => $stream];
        }, 3, self::STREAM_PACK_MODE);

        return is_string($result) ? $result : false;
    }

    public function __rawXRange(string $key, string $start = '-', string $end = '+', int $count = 0): array|false
    {
        $key = $this->simulatedStreamKey($key);

        try {
            $stream = $this->simulatedNormalizeStream(
                $this->simulatedReadValue($key, null, self::STREAM_PACK_MODE)
            );

            if ($stream['messages'] === [] && $this->simulatedReadFromHotCache($key) === null) {
                $load = $this->simulatedCasLoad($key, self::STREAM_PACK_MODE);
                if (empty($load['found']) && empty($load['error'])) {
                    return [];
                }
                if (!empty($load['error'])) {
                    return false;
                }
            }

            return $this->simulatedStreamRange($stream['messages'], $start, $end, $count);
        } catch (Throwable) {
            return false;
        }
    }

    public function __rawXRead(string|array $keys, int $count = 0, int $blockMs = 0): array|false
    {
        if (!is_array($keys)) {
            return false;
        }
        if ($keys === []) {
            return [];
        }

        try {
            $result = [];
            $startTime = microtime(true);

            do {
                $hasNewData = false;
                foreach ($keys as $key => $lastId) {
                    $streamKey = $this->simulatedStreamKey((string)$key);
                    $stream = $this->simulatedNormalizeStream(
                        $this->simulatedReadValue($streamKey, null, self::STREAM_PACK_MODE)
                    );
                    $messages = $stream['messages'];

                    if ($lastId === '$') {
                        $lastId = (string)(array_key_last($messages) ?? '0-0');
                    }

                    $keyMessages = [];
                    $messageCount = 0;
                    foreach ($messages as $id => $msg) {
                        if (strcmp((string)$id, (string)$lastId) <= 0) {
                            continue;
                        }

                        $keyMessages[] = [
                            'id' => (string)$id,
                            'fields' => $msg['fields'] ?? [],
                        ];
                        $messageCount++;
                        $hasNewData = true;

                        if ($count > 0 && $messageCount >= $count) {
                            break;
                        }
                    }

                    if ($keyMessages !== []) {
                        $result[$streamKey] = $keyMessages;
                    }
                }

                if ($hasNewData || $blockMs === 0) {
                    break;
                }

                usleep(10000);
            } while ((microtime(true) - $startTime) * 1000 < $blockMs);

            return $result === [] ? false : $result;
        } catch (Throwable) {
            return false;
        }
    }

    public function __rawXGroup(string $key, string $group, string $startId = '0', bool $mkStream = false): bool
    {
        $key = $this->simulatedStreamKey($key);

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($group, $startId, $mkStream) {
            if (!$found && !$mkStream) {
                return ['return' => false, 'skip_save' => true];
            }

            $stream = $this->simulatedNormalizeStream($found ? $data : null);
            if (isset($stream['groups'][$group])) {
                return ['return' => false, 'skip_save' => true];
            }

            $stream['groups'][$group] = [
                'last_delivered_id' => $startId === '0' ? '0-0' : $startId,
                'consumers' => [],
                'pel' => [],
            ];

            return ['return' => true, 'data' => $stream];
        }, 3, self::STREAM_PACK_MODE);

        return $result === true;
    }

    public function __rawXReadGroup(string $group, string $consumer, string $key, int $count = 0, int $blockMs = 0): array|false
    {
        $key = $this->simulatedStreamKey($key);

        try {
            $result = [];
            $startTime = microtime(true);
            $maxRetries = 3;
            $retry = 0;

            do {
                $mutateResult = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($group, $consumer, $count) {
                    if (!$found) {
                        return ['return' => [], 'skip_save' => true];
                    }

                    $stream = $this->simulatedNormalizeStream($data);
                    if (!isset($stream['groups'][$group])) {
                        return ['return' => [], 'skip_save' => true];
                    }

                    $messages = $stream['messages'];
                    $groupData = &$stream['groups'][$group];
                    $batch = [];
                    $messageCount = 0;

                    foreach ($groupData['pel'] as $id => $pelEntry) {
                        if (($pelEntry['consumer'] ?? '') !== $consumer) {
                            continue;
                        }
                        if (!isset($messages[$id])) {
                            continue;
                        }

                        $batch[] = [
                            'id' => (string)$id,
                            'fields' => $messages[$id]['fields'] ?? [],
                        ];
                        $messageCount++;
                        $groupData['pel'][$id]['delivery_count'] = ($pelEntry['delivery_count'] ?? 0) + 1;
                        $groupData['pel'][$id]['last_delivery_time'] = microtime(true);

                        if ($count > 0 && $messageCount >= $count) {
                            break;
                        }
                    }

                    if (($count === 0 || $messageCount < $count) && $messages !== []) {
                        $lastDeliveredId = $groupData['last_delivered_id'] ?? '0-0';
                        foreach ($messages as $id => $msg) {
                            if (strcmp((string)$id, (string)$lastDeliveredId) <= 0) {
                                continue;
                            }

                            $batch[] = [
                                'id' => (string)$id,
                                'fields' => $msg['fields'] ?? [],
                            ];
                            $messageCount++;
                            $groupData['pel'][$id] = [
                                'consumer' => $consumer,
                                'delivery_count' => 1,
                                'last_delivery_time' => microtime(true),
                            ];
                            $groupData['last_delivered_id'] = (string)$id;

                            if ($count > 0 && $messageCount >= $count) {
                                break;
                            }
                        }
                    }

                    unset($groupData);
                    $stream['groups'][$group]['consumers'][$consumer] = [
                        'last_active' => microtime(true),
                    ];

                    if ($batch === []) {
                        return ['return' => [], 'skip_save' => true];
                    }

                    return ['return' => $batch, 'data' => $stream];
                }, $maxRetries, self::STREAM_PACK_MODE);

                if (is_array($mutateResult) && $mutateResult !== []) {
                    $result = $mutateResult;
                    break;
                }

                if ($blockMs === 0) {
                    break;
                }

                usleep(10000);
                $retry++;
            } while ($retry < $maxRetries || ((microtime(true) - $startTime) * 1000 < $blockMs));

            return $result === [] ? false : $result;
        } catch (Throwable) {
            return false;
        }
    }

    public function __rawXAck(string $key, string $group, array $ids): int|false
    {
        $key = $this->simulatedStreamKey($key);

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($group, $ids) {
            if (!$found) {
                return ['return' => 0, 'skip_save' => true];
            }

            $stream = $this->simulatedNormalizeStream($data);
            if (!isset($stream['groups'][$group])) {
                return ['return' => 0, 'skip_save' => true];
            }

            $ackCount = 0;
            foreach ($ids as $id) {
                if (isset($stream['groups'][$group]['pel'][$id])) {
                    unset($stream['groups'][$group]['pel'][$id]);
                    $ackCount++;
                }
            }

            if ($ackCount === 0) {
                return ['return' => 0, 'skip_save' => true];
            }

            return ['return' => $ackCount, 'data' => $stream];
        }, 3, self::STREAM_PACK_MODE);

        return is_int($result) ? $result : false;
    }

    public function __rawXRevRange(string $key, string $start = '+', string $end = '-', int $count = 0): array|false
    {
        $result = $this->__rawXRange($key, $end, $start, $count);

        return is_array($result) ? array_reverse($result) : $result;
    }

    public function __rawXLen(string $key): int
    {
        $key = $this->simulatedStreamKey($key);
        $stream = $this->simulatedNormalizeStream(
            $this->simulatedReadValue($key, null, self::STREAM_PACK_MODE)
        );

        return count($stream['messages']);
    }

    public function __rawXDel(string $key, array $ids): int|false
    {
        $key = $this->simulatedStreamKey($key);

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($ids) {
            if (!$found) {
                return ['return' => 0, 'skip_save' => true];
            }

            $stream = $this->simulatedNormalizeStream($data);
            $deletedCount = 0;

            foreach ($ids as $id) {
                if (!isset($stream['messages'][$id])) {
                    continue;
                }
                unset($stream['messages'][$id]);
                $deletedCount++;
                foreach ($stream['groups'] as &$group) {
                    unset($group['pel'][$id]);
                }
                unset($group);
            }

            if ($deletedCount === 0) {
                return ['return' => 0, 'skip_save' => true];
            }

            return [
                'return' => $deletedCount,
                'data' => $stream,
                'refresh_hot_cache' => false,
            ];
        }, 3, self::STREAM_PACK_MODE);

        return is_int($result) ? $result : false;
    }

    public function __rawXTrim(string $key, int $maxLen): int|false
    {
        $key = $this->simulatedStreamKey($key);

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($maxLen) {
            if (!$found) {
                return ['return' => 0, 'skip_save' => true];
            }

            $stream = $this->simulatedNormalizeStream($data);
            $originalCount = count($stream['messages']);
            if ($originalCount <= $maxLen) {
                return ['return' => 0, 'skip_save' => true];
            }

            $stream['messages'] = array_slice($stream['messages'], -$maxLen, null, true);
            $deletedCount = $originalCount - count($stream['messages']);

            return [
                'return' => $deletedCount,
                'data' => $stream,
                'refresh_hot_cache' => false,
            ];
        }, 3, self::STREAM_PACK_MODE);

        return is_int($result) ? $result : false;
    }
}
