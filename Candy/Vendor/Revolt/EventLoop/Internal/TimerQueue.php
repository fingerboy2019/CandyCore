<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * 使用数组存储的二叉树实现堆数据结构的定时器队列。
 *
 * @internal
 */
final class TimerQueue
{
    /**
     * 定时器回调数组。
     *
     * @var array<int, TimerCallback>
     */
    private array $callbacks = [];

    /**
     * 回调标识符到数组索引的映射。
     *
     * @var array<string, int>
     */
    private array $pointers = [];

    /**
     * 将回调函数插入队列。
     *
     * 时间复杂度: O(log(n))。
     *
     * @param TimerCallback $callback 要插入的定时器回调。
     */
    public function insert(TimerCallback $callback): void
    {
        \assert(!isset($this->pointers[$callback->id]));

        $node = \count($this->callbacks);
        $this->callbacks[$node] = $callback;
        $this->pointers[$callback->id] = $node;

        $this->heapifyUp($node);
    }

    /**
     * 从队列中移除给定的回调函数。
     *
     * 时间复杂度: O(log(n))。
     *
     * @param TimerCallback $callback 要移除的定时器回调。
     */
    public function remove(TimerCallback $callback): void
    {
        $id = $callback->id;

        if (!isset($this->pointers[$id])) {
            return;
        }

        $this->removeAndRebuild($this->pointers[$id]);
    }

    /**
     * 如果堆顶的回调函数已过期，则删除并返回该回调函数，否则返回 null。
     *
     * 时间复杂度: O(log(n))。
     *
     * @param float $now 当前事件循环时间。
     *
     * @return TimerCallback|null 堆顶已过期的回调函数，或 null（如果回调函数未过期）。
     */
    public function extract(float $now): ?TimerCallback
    {
        if (!$this->callbacks) {
            return null;
        }

        $callback = $this->callbacks[0];
        if ($callback->expiration > $now) {
            return null;
        }

        $this->removeAndRebuild(0);

        return $callback;
    }

    /**
     * 返回堆顶回调函数的到期时间值。
     *
     * 时间复杂度: O(1)。
     *
     * @return float|null 堆顶回调函数的到期时间，或 null（如果堆为空）。
     */
    public function peek(): ?float
    {
        return isset($this->callbacks[0]) ? $this->callbacks[0]->expiration : null;
    }

    /**
     * 从给定节点向上重建数据数组。
     *
     * @param int $node 要开始重建的节点索引。
     */
    private function heapifyUp(int $node): void
    {
        $entry = $this->callbacks[$node];
        while ($node !== 0 && $entry->expiration < $this->callbacks[$parent = ($node - 1) >> 1]->expiration) {
            $this->swap($node, $parent);
            $node = $parent;
        }
    }

    /**
     * 从给定节点向下重建数据数组。
     *
     * @param int $node 要开始重建的节点索引。
     */
    private function heapifyDown(int $node): void
    {
        $length = \count($this->callbacks);
        while (($child = ($node << 1) + 1) < $length) {
            if ($this->callbacks[$child]->expiration < $this->callbacks[$node]->expiration
                && ($child + 1 >= $length || $this->callbacks[$child]->expiration < $this->callbacks[$child + 1]->expiration)
            ) {
                // Left child is less than parent and right child.
                $swap = $child;
            } elseif ($child + 1 < $length && $this->callbacks[$child + 1]->expiration < $this->callbacks[$node]->expiration) {
                // Right child is less than parent and left child.
                $swap = $child + 1;
            } else { // Left and right child are greater than parent.
                break;
            }

            $this->swap($node, $swap);
            $node = $swap;
        }
    }

    /**
     * 交换两个节点的位置。
     *
     * @param int $left 左侧节点索引。
     * @param int $right 右侧节点索引。
     */
    private function swap(int $left, int $right): void
    {
        $temp = $this->callbacks[$left];

        $this->callbacks[$left] = $this->callbacks[$right];
        $this->pointers[$this->callbacks[$right]->id] = $left;

        $this->callbacks[$right] = $temp;
        $this->pointers[$temp->id] = $right;
    }

    /**
     * 移除给定节点并重新构建数据数组。
     *
     * @param int $node 要移除的节点索引。
     */
    private function removeAndRebuild(int $node): void
    {
        $length = \count($this->callbacks) - 1;
        $id = $this->callbacks[$node]->id;
        $left = $this->callbacks[$node] = $this->callbacks[$length];
        $this->pointers[$left->id] = $node;
        unset($this->callbacks[$length], $this->pointers[$id]);

        if ($node < $length) { // don't need to do anything if we removed the last element
            $parent = ($node - 1) >> 1;
            if ($parent >= 0 && $this->callbacks[$node]->expiration < $this->callbacks[$parent]->expiration) {
                $this->heapifyUp($node);
            } else {
                $this->heapifyDown($node);
            }
        }
    }
}
