<?php
/**
 * 分段存储驱动 — Redis Stream 原生实现（File / Shmop 共用）
 *
 * 通过 segmentStream* 钩子对接各驱动的锁与 KV 读写。
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

trait SegmentStreamOps
{
    /**
     * 读取 Stream 结构（messages + groups）
     */
    abstract protected function segmentStreamLoad(string $key): mixed;

    /**
     * 持久化 Stream 结构
     */
    abstract protected function segmentStreamSave(string $key, array $stream): bool;

    /**
     * 在写锁保护下执行（XADD / XGROUP / XACK / XREADGROUP 等）
     *
     * @template T
     * @param callable(): T $fn
     * @return T|false 锁获取失败时返回 false
     */
    abstract protected function segmentStreamWithWriteLock(string $key, callable $fn): mixed;

    /**
     * 在读锁保护下执行（XRANGE / XREVRANGE / XREAD 等）
     *
     * @template T
     * @param callable(): T $fn
     * @return T|false 锁获取失败时返回 false
     */
    abstract protected function segmentStreamWithReadLock(string $key, callable $fn): mixed;

    /** @return array{messages: array, groups: array} */
    protected function segmentEmptyStream(): array
    {
        return ['messages' => [], 'groups' => []];
    }

    /** @return array{messages: array, groups: array} */
    protected function segmentNormalizeStream(mixed $data): array
    {
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
            return $this->segmentEmptyStream();
        }

        $data['groups'] ??= [];

        return $data;
    }

    public function __rawXAdd(string $key, array $fieldValues, ?string $id = '*', int $maxLen = 0): string|false
    {
        if (empty($key) || empty($fieldValues)) {
            return false;
        }

        return $this->segmentStreamWithWriteLock($key, function () use ($key, $fieldValues, $id, $maxLen): string|false {
            $stream = $this->segmentNormalizeStream($this->segmentStreamLoad($key));
            if (!isset($stream['messages'])) {
                $stream = $this->segmentEmptyStream();
            }

            $now = microtime(true);
            $nowMs = (int)($now * 1000);

            if ($id === '*') {
                $lastId = array_key_last($stream['messages']);
                if ($lastId === null) {
                    $id = $nowMs . '-0';
                } else {
                    [$lastTs, $lastSeq] = explode('-', (string)$lastId);
                    $lastTs = (int)$lastTs;
                    $lastSeq = (int)$lastSeq;
                    $id = $nowMs > $lastTs ? $nowMs . '-0' : $lastTs . '-' . ($lastSeq + 1);
                }
            } else {
                if (!preg_match('/^\d+-\d+$/', $id)) {
                    return false;
                }
                [$ts, $seq] = explode('-', $id);
                $ts = (int)$ts;
                $seq = (int)$seq;

                $lastId = array_key_last($stream['messages']);
                if ($lastId !== null) {
                    [$lastTs, $lastSeq] = explode('-', (string)$lastId);
                    $lastTs = (int)$lastTs;
                    $lastSeq = (int)$lastSeq;
                    if ($ts < $lastTs || ($ts === $lastTs && $seq <= $lastSeq)) {
                        return false;
                    }
                }
            }

            $stream['messages'][$id] = [
                'fields' => $fieldValues,
                'time' => $now,
            ];

            if ($maxLen > 0 && count($stream['messages']) > $maxLen) {
                $removeCount = count($stream['messages']) - $maxLen;
                $keysToRemove = array_slice(array_keys($stream['messages']), 0, $removeCount);
                foreach ($keysToRemove as $removeKey) {
                    unset($stream['messages'][$removeKey]);
                    foreach ($stream['groups'] as &$group) {
                        unset($group['pel'][$removeKey]);
                    }
                    unset($group);
                }
            }

            return $this->segmentStreamSave($key, $stream) ? $id : false;
        });
    }

    public function __rawXRange(string $key, string $start = '-', string $end = '+', int $count = 0): array|false
    {
        if (empty($key)) {
            return false;
        }

        $result = $this->segmentStreamWithReadLock($key, function () use ($key, $start, $end, $count): array {
            $stream = $this->segmentNormalizeStream($this->segmentStreamLoad($key));
            if (empty($stream['messages'])) {
                return [];
            }

            $messages = [];
            $messageCount = 0;

            foreach ($stream['messages'] as $id => $msg) {
                if (!$this->segmentStreamIdInForwardRange((string)$id, $start, $end)) {
                    continue;
                }

                $messages[] = [
                    'id' => $id,
                    'fields' => $msg['fields'],
                ];
                $messageCount++;

                if ($count > 0 && $messageCount >= $count) {
                    break;
                }
            }

            return $messages;
        });

        return $result === false ? false : $result;
    }

    public function __rawXRevRange(string $key, string $start = '+', string $end = '-', int $count = 0): array|false
    {
        if (empty($key)) {
            return false;
        }

        $result = $this->segmentStreamWithReadLock($key, function () use ($key, $start, $end, $count): array {
            $stream = $this->segmentNormalizeStream($this->segmentStreamLoad($key));
            if (empty($stream['messages'])) {
                return [];
            }

            $messages = [];
            $messageCount = 0;
            $messageIds = array_keys($stream['messages']);
            rsort($messageIds);

            foreach ($messageIds as $id) {
                if (!$this->segmentStreamIdInReverseRange((string)$id, $start, $end)) {
                    continue;
                }

                $messages[] = [
                    'id' => $id,
                    'fields' => $stream['messages'][$id]['fields'],
                ];
                $messageCount++;

                if ($count > 0 && $messageCount >= $count) {
                    break;
                }
            }

            return $messages;
        });

        return $result === false ? false : $result;
    }

    public function __rawXRead(string|array $keys, int $count = 0, int $blockMs = 0): array|false
    {
        if (!is_array($keys)) {
            return false;
        }
        if ($keys === []) {
            return [];
        }

        $result = [];
        $startTime = microtime(true);

        do {
            $hasNewData = false;

            foreach ($keys as $key => $lastId) {
                $keyResult = $this->segmentStreamWithReadLock((string)$key, function () use ($key, $lastId, $count, &$hasNewData): array {
                    $stream = $this->segmentNormalizeStream($this->segmentStreamLoad((string)$key));
                    if (empty($stream['messages'])) {
                        return [];
                    }

                    if ($lastId === '$') {
                        $lastId = (string)(array_key_last($stream['messages']) ?? '0');
                    }

                    $keyMessages = [];
                    foreach ($stream['messages'] as $id => $msg) {
                        if (!$this->segmentStreamIdGreaterThan((string)$id, (string)$lastId)) {
                            continue;
                        }

                        $keyMessages[] = [
                            'id' => $id,
                            'fields' => $msg['fields'],
                        ];
                        $hasNewData = true;

                        if ($count > 0 && count($keyMessages) >= $count) {
                            break;
                        }
                    }

                    return $keyMessages;
                });

                if ($keyResult === false) {
                    continue;
                }

                if ($keyResult !== []) {
                    $result[$key] = $keyResult;
                }
            }

            if ($result !== []) {
                return $result;
            }

            if (!$hasNewData && $blockMs > 0) {
                usleep(10000);
            }
        } while ($blockMs > 0 && ((microtime(true) - $startTime) * 1000) < $blockMs);

        return $result === [] ? false : $result;
    }

    public function __rawXGroup(string $key, string $group, string $startId = '0', bool $mkStream = false): bool
    {
        if (empty($key) || empty($group)) {
            return false;
        }

        $result = $this->segmentStreamWithWriteLock($key, function () use ($key, $group, $startId, $mkStream): bool {
            $stream = $this->segmentStreamLoad($key);
            if (!is_array($stream)) {
                if (!$mkStream) {
                    return false;
                }
                $stream = $this->segmentEmptyStream();
            } else {
                $stream = $this->segmentNormalizeStream($stream);
            }

            if (isset($stream['groups'][$group])) {
                return false;
            }

            $stream['groups'][$group] = [
                'last_delivered_id' => $startId,
                'consumers' => [],
                'pel' => [],
            ];

            return $this->segmentStreamSave($key, $stream);
        });

        return $result === true;
    }

    public function __rawXReadGroup(string $group, string $consumer, string $key, int $count = 0, int $blockMs = 0): array|false
    {
        if (empty($group) || empty($consumer) || empty($key)) {
            return false;
        }

        $startTime = microtime(true);

        do {
            $result = $this->segmentStreamWithWriteLock($key, function () use ($group, $consumer, $key, $count): array|false {
                $stream = $this->segmentStreamLoad($key);
                if (!is_array($stream) || !isset($stream['groups'][$group])) {
                    return false;
                }

                $stream = $this->segmentNormalizeStream($stream);
                $groupData = &$stream['groups'][$group];
                $messages = [];
                $now = microtime(true);

                foreach ($groupData['pel'] as $id => &$pelEntry) {
                    if ($pelEntry['consumer'] !== $consumer) {
                        continue;
                    }
                    if (!isset($stream['messages'][$id])) {
                        continue;
                    }

                    $messages[] = [
                        'id' => $id,
                        'fields' => $stream['messages'][$id]['fields'],
                    ];

                    $pelEntry['delivery_count']++;
                    $pelEntry['last_delivery_time'] = $now;

                    if ($count > 0 && count($messages) >= $count) {
                        break;
                    }
                }
                unset($pelEntry);

                if (($count === 0 || count($messages) < $count) && $stream['messages'] !== []) {
                    $lastId = (string)$groupData['last_delivered_id'];
                    $readCount = $count > 0 ? $count - count($messages) : 0;
                    $newMessagesRead = 0;

                    foreach ($stream['messages'] as $id => $msg) {
                        if ($lastId !== '0' && $lastId !== '$' && !$this->segmentStreamIdGreaterThan((string)$id, $lastId)) {
                            continue;
                        }

                        $messages[] = [
                            'id' => $id,
                            'fields' => $msg['fields'],
                        ];

                        $groupData['pel'][$id] = [
                            'consumer' => $consumer,
                            'delivery_count' => 1,
                            'last_delivery_time' => $now,
                        ];

                        $groupData['last_delivered_id'] = $id;
                        $newMessagesRead++;

                        if ($readCount > 0 && $newMessagesRead >= $readCount) {
                            break;
                        }
                    }
                }

                $groupData['consumers'][$consumer] = ['last_active' => $now];

                if ($messages === []) {
                    return false;
                }

                return $this->segmentStreamSave($key, $stream) ? $messages : false;
            });

            if (is_array($result)) {
                return $result;
            }

            if ($blockMs <= 0) {
                return false;
            }

            usleep(10000);
        } while ($blockMs > 0 && ((microtime(true) - $startTime) * 1000) < $blockMs);

        return false;
    }

    public function __rawXAck(string $key, string $group, array $ids): int|false
    {
        if (empty($key) || empty($group) || empty($ids)) {
            return 0;
        }

        $result = $this->segmentStreamWithWriteLock($key, function () use ($key, $group, $ids): int|false {
            $stream = $this->segmentStreamLoad($key);
            if (!is_array($stream) || !isset($stream['groups'][$group])) {
                return false;
            }

            $stream = $this->segmentNormalizeStream($stream);
            $ackCount = 0;

            foreach ($ids as $id) {
                if (isset($stream['groups'][$group]['pel'][$id])) {
                    unset($stream['groups'][$group]['pel'][$id]);
                    $ackCount++;
                }
            }

            if ($ackCount > 0 && !$this->segmentStreamSave($key, $stream)) {
                return false;
            }

            return $ackCount;
        });

        return $result === false ? false : (int)$result;
    }

    protected function segmentStreamIdGreaterThan(string $id, string $lastId): bool
    {
        if ($lastId === '0' || $lastId === '$') {
            return true;
        }

        [$currTs, $currSeq] = explode('-', $id);
        [$lastTs, $lastSeq] = explode('-', $lastId);

        $currTs = (int)$currTs;
        $currSeq = (int)$currSeq;
        $lastTs = (int)$lastTs;
        $lastSeq = (int)$lastSeq;

        return $currTs > $lastTs || ($currTs === $lastTs && $currSeq > $lastSeq);
    }

    protected function segmentStreamIdInForwardRange(string $id, string $start, string $end): bool
    {
        [$currTs, $currSeq] = explode('-', $id);
        $currTs = (int)$currTs;
        $currSeq = (int)$currSeq;

        if ($start !== '-') {
            [$startTs, $startSeq] = explode('-', $start);
            $startTs = (int)$startTs;
            $startSeq = (int)$startSeq;
            if ($currTs < $startTs || ($currTs === $startTs && $currSeq < $startSeq)) {
                return false;
            }
        }

        if ($end !== '+') {
            [$endTs, $endSeq] = explode('-', $end);
            $endTs = (int)$endTs;
            $endSeq = (int)$endSeq;
            if ($currTs > $endTs || ($currTs === $endTs && $currSeq > $endSeq)) {
                return false;
            }
        }

        return true;
    }

    protected function segmentStreamIdInReverseRange(string $id, string $start, string $end): bool
    {
        [$currTs, $currSeq] = explode('-', $id);
        $currTs = (int)$currTs;
        $currSeq = (int)$currSeq;

        if ($start !== '+') {
            [$startTs, $startSeq] = explode('-', $start);
            $startTs = (int)$startTs;
            $startSeq = (int)$startSeq;
            if ($currTs > $startTs || ($currTs === $startTs && $currSeq > $startSeq)) {
                return false;
            }
        }

        if ($end !== '-') {
            [$endTs, $endSeq] = explode('-', $end);
            $endTs = (int)$endTs;
            $endSeq = (int)$endSeq;
            if ($currTs < $endTs || ($currTs === $endTs && $currSeq < $endSeq)) {
                return false;
            }
        }

        return true;
    }
}
