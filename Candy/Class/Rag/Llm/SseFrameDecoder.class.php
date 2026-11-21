<?php
/***
 * Server-Sent Events 帧解码器
 *
 * 业务说明：将 Provider 返回的任意分片拼接为完整 SSE data 帧；不负责业务事件解释，
 * 只负责帧边界、JSON 解码和 [DONE] 终态识别。
 *
 * $Author: Codex $
 * $Date: 2026-09-18 04:10:03 $
 *
 * @package Candy\Extend\Rag\Llm
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Llm;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Provider-neutral SSE 帧解码器。
 */
final class SseFrameDecoder
{
    private string $buffer = '';

    private bool $done = false;

    /**
     * 推入一段网络分片并消费其中的完整 SSE 帧。
     *
     * 分片可能在 UTF-8 字节、换行符或 JSON 中间截断；方法只在帧分隔符完整出现后
     * 调用回调。回调返回 false 时停止继续消费，但不会伪造终态。
     *
     * @param string $chunk 网络原始分片
     * @param callable $onFrame 完整帧回调，参数为 ['done'=>bool,'data'=>?array,'raw'=>string]
     * @return bool 是否允许继续接收
     */
    public function push(string $chunk, callable $onFrame): bool
    {
        if ($this->done) {
            return false;
        }
        $this->buffer .= $chunk;
        while (true) {
            $frame = $this->takeFrame();
            if ($frame === null) {
                return true;
            }
            if (!$this->dispatch($frame, $onFrame)) {
                return false;
            }
            if ($this->done) {
                return true;
            }
        }
    }

    /**
     * 在网络请求结束时校验是否还有未闭合的 SSE 帧。
     *
     * @param callable $onFrame 完整帧回调
     * @return bool 是否可正常结束
     */
    public function finish(callable $onFrame): bool
    {
        unset($onFrame);
        return trim($this->buffer) === '';
    }

    /**
     * 判断是否已经收到 Provider 的 [DONE] 帧。
     *
     * @return bool 是否收到流终态标记
     */
    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * 取出一个完整帧；不完整时保留缓冲区。
     *
     * @return string|null 完整帧正文
     */
    private function takeFrame(): ?string
    {
        $separators = ["\r\n\r\n", "\n\n", "\r\r"];
        $position = null;
        $separatorLength = 0;
        foreach ($separators as $separator) {
            $candidate = strpos($this->buffer, $separator);
            if ($candidate === false) {
                continue;
            }
            if ($position === null || $candidate < $position) {
                $position = $candidate;
                $separatorLength = strlen($separator);
            }
        }
        if ($position === null) {
            return null;
        }

        $frame = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + $separatorLength);

        return $frame;
    }

    /**
     * 解析并派发一个完整 SSE 帧。
     *
     * @param string $frame 帧正文
     * @param callable $onFrame 完整帧回调
     * @return bool 是否允许继续接收
     */
    private function dispatch(string $frame, callable $onFrame): bool
    {
        $dataLines = [];
        $lines = preg_split("/\r\n|\n|\r/", $frame);
        foreach ($lines ?: [] as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $value = substr($line, 5);
            $dataLines[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
        }
        if ($dataLines === []) {
            return true;
        }

        $raw = implode("\n", $dataLines);
        if (trim($raw) === '[DONE]') {
            $this->done = true;

            return $onFrame([
                'done' => true,
                'data' => null,
                'raw' => '[DONE]',
            ]) !== false;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \UnexpectedValueException(
                'SSE JSON 解码失败: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        if (!is_array($data)) {
            throw new \UnexpectedValueException('SSE data 必须为 JSON 对象');
        }

        return $onFrame([
            'done' => false,
            'data' => $data,
            'raw' => $raw,
        ]) !== false;
    }
}
