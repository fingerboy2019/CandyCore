<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * 事件循环驱动程序接口。
 *
 * 驱动程序必须在自己的纤程中运行，并在单独的纤程中执行回调。如果纤程被重用，
 * 驱动程序需要在运行回调后调用 {@see FiberLocal::clear()}。
 */
interface Driver
{
    /**
     * 运行事件循环。
     *
     * 循环的一次迭代称为一个 "tick"。一个 tick 包括以下步骤：
     *
     *  1. 激活在上一个 tick 中创建/启用的回调或在 `run()` 之前创建的回调。
     *  2. 执行所有已启用的延迟回调。
     *  3. 执行所有到期的定时器、待处理的信号和可操作的流回调，每个回调每个 tick 只执行一次。
     *
     * 循环必须继续运行，直到它被显式停止、不再存在被引用的回调，或者抛出无法处理的异常。
     * 无法处理的异常是从错误处理程序抛出的异常，或者将传递给错误处理程序但不存在处理它们的异常。
     *
     * @throws \Error 如果事件循环已经在运行。
     */
    public function run(): void;

    /**
     * 停止事件循环。
     *
     * 当事件循环停止时，它会继续当前的 tick，然后退出循环。多次调用 stop 必须被忽略，且不得引发异常。
     */
    public function stop(): void;

    /**
     * 返回一个用于挂起和恢复当前纤程或{main}执行的对象。
     *
     * 来自同一纤程的调用将返回相同的挂起对象。
     *
     * @return Suspension
     */
    public function getSuspension(): Suspension;

    /**
     * 返回事件循环是否正在运行。
     *
     * @return bool 如果事件循环正在运行则返回 true，如果停止则返回 false。
     */
    public function isRunning(): bool;

    /**
     * 排队一个微任务。
     *
     * 一旦事件循环获得控制权，排队的回调必须立即执行。执行回调时必须保留排队顺序。
     * 递归调度可能会导致无限循环，使用时请谨慎。
     *
     * 不创建事件回调，因此不能被标记为禁用或取消引用。
     * 如果需要这些功能，请使用 {@see EventLoop::defer()}。
     *
     * @param \Closure(...):void $closure 要排队的回调。
     * @param mixed ...$args 回调参数。
     */
    public function queue(\Closure $closure, mixed ...$args): void;

    /**
     * 延迟执行回调。
     *
     * 延迟回调必须在一个 tick 中其他类型的回调之前执行。执行回调时必须保留启用顺序。
     *
     * 创建的回调必须立即被标记为启用，但只有在下一个 tick 之前才会被激活（即可以调用回调）。
     * 回调不能在它们被启用的同一个 tick 中被调用。
     *
     * @param \Closure(string):void $closure 要延迟的回调。`$callbackId` 将在回调调用前失效。
     *
     * @return string 可用于取消、启用或禁用回调的唯一标识符。
     */
    public function defer(\Closure $closure): string;

    /**
     * 延迟执行回调。
     *
     * 延迟是最小的且近似的，不保证准确性。调用顺序必须由哪个定时器首先过期决定，
     * 但具有相同过期时间的定时器可以以任何顺序执行。
     *
     * 创建的回调必须立即被标记为启用，但只有在下一个 tick 之前才会被激活（即可以调用回调）。
     * 回调不能在它们被启用的同一个 tick 中被调用。
     *
     * @param float $delay 延迟执行的时间，以秒为单位。
     * @param \Closure(string):void $closure 要延迟的回调。`$callbackId` 将在回调调用前失效。
     *
     * @return string 可用于取消、启用或禁用回调的唯一标识符。
     */
    public function delay(float $delay, \Closure $closure): string;

    /**
     * 重复执行回调。
     *
     * 执行之间的间隔是最小的且近似的，不保证准确性。调用顺序必须由哪个定时器首先过期决定，
     * 但具有相同过期时间的定时器可以以任何顺序执行。第一次执行在第一个间隔期后安排。
     *
     * 创建的回调必须立即被标记为启用，但只有在下一个 tick 之前才会被激活（即可以调用回调）。
     * 回调不能在它们被启用的同一个 tick 中被调用。
     *
     * @param float $interval 执行之间等待的时间间隔，以秒为单位。
     * @param \Closure(string):void $closure 要重复的回调。
     *
     * @return string 可用于取消、启用或禁用回调的唯一标识符。
     */
    public function repeat(float $interval, \Closure $closure): string;

    /**
     * 当流资源变得可读或关闭读取时执行回调。
     *
     * 警告：在本地关闭资源（例如使用 `fclose`）可能不会调用回调。在本地关闭资源时，确保 `cancel` 回调。
     * 驱动程序可以选择在无效资源上有回调时通知用户，但由于高性能影响，不强制要求这样做。
     * 因此，在关闭的资源上的回调是未定义的行为。
     *
     * 同一流上的多个回调可以以任何顺序执行。
     *
     * 创建的回调必须立即被标记为启用，但只有在下一个 tick 之前才会被激活（即可以调用回调）。
     * 回调不能在它们被启用的同一个 tick 中被调用。
     *
     * @param resource $stream 要监控的流。
     * @param \Closure(string, resource):void $closure 要执行的回调。
     *
     * @return string 可用于取消、启用或禁用回调的唯一标识符。
     */
    public function onReadable(mixed $stream, \Closure $closure): string;

    /**
     * 当流资源变得可写或关闭写入时执行回调。
     *
     * 警告：在本地关闭资源（例如使用 `fclose`）可能不会调用回调。在本地关闭资源时，确保 `cancel` 回调。
     * 驱动程序可以选择在无效资源上有回调时通知用户，但由于高性能影响，不强制要求这样做。
     * 因此，在关闭的资源上的回调是未定义的行为。
     *
     * 同一流上的多个回调可以以任何顺序执行。
     *
     * 创建的回调必须立即被标记为启用，但只有在下一个 tick 之前才会被激活（即可以调用回调）。
     * 回调不能在它们被启用的同一个 tick 中被调用。
     *
     * @param resource $stream 要监控的流。
     * @param \Closure(string, resource):void $closure 要执行的回调。
     *
     * @return string 可用于取消、启用或禁用回调的唯一标识符。
     */
    public function onWritable(mixed $stream, \Closure $closure): string;

    /**
     * 当接收到信号时执行回调。
     *
     * 警告：在这个接口的不同实例上安装相同的信号被视为未定义行为。
     * 实现可以尝试检测这种情况（如果可能的话），但不强制要求这样做。
     * 这是由于信号在每个进程中全局注册的技术限制。
     *
     * 同一信号上的多个回调可以以任何顺序执行。
     *
     * 创建的回调会立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 回调不会在它们被启用的同一周期中被调用。
     *
     * @param int $signal 要监控的信号编号。
     * @param \Closure(string, int):void $closure 要执行的回调函数。
     *
     * @return string 用于取消、启用或禁用回调的唯一标识符。
     *
     * @throws UnsupportedFeatureException 如果不支持信号处理。
     */
    public function onSignal(int $signal, \Closure $closure): string;

    /**
     * 启用回调，使其从下一个 tick 开始生效。
     *
     * 回调必须立即被标记为启用，但只有在下一个 tick 之前才会被激活（即可以调用回调）。
     * 回调不能在它们被启用的同一个 tick 中被调用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public function enable(string $callbackId): string;

    /**
     * 取消回调。
     *
     * 这将把事件循环与所有与回调相关的资源分离。在此操作之后，回调将永久无效。
     * 即使传递了无效的标识符，调用此函数也不能失败。
     *
     * @param string $callbackId 回调标识符。
     */
    public function cancel(string $callbackId): void;

    /**
     * 立即禁用回调。
     *
     * 回调必须立即被禁用，例如，如果一个延迟回调禁用了一个后续的延迟回调，
     * 第二个延迟回调不会在这个 tick 中执行。
     *
     * 禁用回调不得使回调无效。即使传递了无效的回调标识符，调用此函数也不得失败。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     */
    public function disable(string $callbackId): string;

    /**
     * 引用一个回调。
     *
     * 这将在回调仍被监控时保持事件循环运行。回调默认具有此状态。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public function reference(string $callbackId): string;

    /**
     * 取消引用一个回调。
     *
     * 当只有取消引用的回调仍被监控时，事件循环应该退出run方法。所有回调默认都是被引用的。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     */
    public function unreference(string $callbackId): string;

    /**
     * 设置一个在发生错误时执行的回调函数。
     *
     * 回调函数接收错误作为第一个也是唯一的参数。回调函数的返回值会被忽略。
     * 如果它无法处理错误，必须抛出该错误。由回调函数抛出或在其调用过程中抛出的错误
     * 必须被抛入 `run` 循环并停止驱动程序。
     *
     * 后续调用此方法将覆盖先前的处理程序。
     *
     * @param null|\Closure(\Throwable):void $errorHandler 要执行的回调函数。`null` 将清除当前处理程序。
     */
    public function setErrorHandler(?\Closure $errorHandler): void;

    /**
     * 获取错误处理程序闭包，如果没有设置则返回 {@code null}。
     *
     * @return null|\Closure(\Throwable):void 先前的处理程序，如果没有则返回 `null`。
     */
    public function getErrorHandler(): ?\Closure;

    /**
     * 获取底层循环句柄。
     *
     * 示例：`libuv` 的 `uv_loop` 资源或 `libev` 的 `EvLoop` 对象，或 stream_select 驱动程序的 `null`。
     *
     * 注意：此函数 *不* 在 `Loop` 类中公开。用户应直接在各自的循环实例上访问它。
     *
     * @return null|object|resource 事件循环操作的循环句柄。如果没有则返回 `null`。
     */
    public function getHandle(): mixed;

    /**
     * 返回所有已注册且未取消的回调标识符。
     *
     * @return string[] 回调标识符数组。
     */
    public function getIdentifiers(): array;

    /**
     * 返回由给定回调标识符标识的回调类型。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return CallbackType 回调类型。
     */
    public function getType(string $callbackId): CallbackType;

    /**
     * 返回由给定回调标识符标识的回调当前是否已启用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return bool 如果回调当前已启用则返回 `true`，否则返回 `false`。
     */
    public function isEnabled(string $callbackId): bool;

    /**
     * 返回由给定回调标识符标识的回调当前是否被引用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return bool 如果回调当前被引用则返回 `true`，否则返回 `false`。
     */
    public function isReferenced(string $callbackId): bool;

    /**
     * 返回有关事件循环的一些有用信息。
     *
     * 如果未实现此方法，则在繁忙的应用程序中转储事件循环（即使是间接的）也是一件麻烦事。
     *
     * @return array
     */
    public function __debugInfo(): array;
}
