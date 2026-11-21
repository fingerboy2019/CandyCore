<?php
/***
 * Candy框架 流式响应输出类
 *
 * 为业务层提供**统一的流式输出接口**，屏蔽三种运行环境的差异：
 * - Workerman 常驻模式：数据直发 TcpConnection（服务器已由 Server::handlePhp 的 SSE 分支发头并绑定连接）
 * - FPM / CLI：header() + echo + ob_flush() + flush()，并清掉框架自身的输出缓冲
 *
 * 典型用途：Server-Sent Events（SSE）、NDJSON 逐行推送、AI 流式对话、大文本分块。
 *
 * 使用示例（控制器内）：
 * ```php
 * $stream = \Candy\Core\StreamResponse::create();
 * $stream->start();
 * foreach ($chunks as $i => $text) {
 *     if (!$stream->event('message', ['text' => $text], $i + 1)) {
 *         break;                      // 客户端已断开，立即停止
 *     }
 * }
 * $stream->close();                   // 结束并终止本次请求
 * ```
 *
 * 客户端（浏览器）：
 * ```js
 * const es = new EventSource('/Ai/chat.html');
 * es.addEventListener('message', e => console.log(JSON.parse(e.data)));
 * ```
 *
 * 注意：
 * 1. 流式请求**客户端必须带 `Accept: text/event-stream`**（SSE 标准），
 *    Workerman 靠该头进入 SSE 分支并绑定 Connection；FPM 下无此要求但建议照带。
 * 2. 本类**不经过 Respond**（Respond 是全量缓冲后一次性发送），
 *    调用 close() 会直接终止请求，避免框架再发一次响应。
 * 3. gzip 会破坏流式语义，Server 的 SSE 分支不发 Content-Encoding；
 *    FPM 下由本类显式关闭 zlib.output_compression。
 *
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com) $
 * @date 2026-11-13 $
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class StreamResponse {

	/** SSE 内容类型 */
	public const CONTENT_SSE = 'text/event-stream';

	/** 逐行 JSON（每行一个 JSON 对象） */
	public const CONTENT_NDJSON = 'application/x-ndjson';

	/** 纯文本流 */
	public const CONTENT_TEXT = 'text/plain; charset=utf-8';

	/**
	 * 是否已开始（头已发出 / 通道已就绪）
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * 是否已关闭（客户端断开或主动结束）
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * 当前内容类型
	 * @var string
	 */
	private string $contentType;

	/**
	 * 额外响应头
	 * @var array
	 */
	private array $headers;

	/**
	 * Workerman 连接对象（常驻模式下直发数据）
	 * @var object|null
	 */
	private ?object $connection = null;

	/**
	 * 运行环境标识：workerman | swoole | fpm
	 * @var string
	 */
	private string $runtime = 'fpm';

	/**
	 * 构造方法（请使用 create()）
	 *
	 * @param string $contentType 内容类型，见 CONTENT_* 常量
	 * @param array $headers 额外响应头
	 */
	private function __construct(string $contentType, array $headers = []) {
		$this->contentType = $contentType;
		$this->headers = $headers;
	}

	/**
	 * 创建流式响应实例
	 *
	 * @param string $contentType 内容类型，默认 text/event-stream
	 * @param array $headers 额外响应头
	 * @return StreamResponse
	 */
	public static function create(string $contentType = self::CONTENT_SSE, array $headers = []): StreamResponse {
		return new self($contentType, $headers);
	}

	/**
	 * 开始输出：探测运行环境并准备通道
	 *
	 * Workerman 模式下头与 Connection 绑定由 Server::handlePhp 的 SSE 分支完成，
	 * 此处只取连接对象，**不重复发头**；FPM 模式下自行发头并清空框架输出缓冲。
	 *
	 * @return StreamResponse 便于链式调用
	 */
	public function start(): StreamResponse {
		if ($this->started || $this->closed) {
			return $this;
		}
		$this->started = true;

		// ① Workerman：Server::handlePhp 的 SSE 分支已发头并 bind('Connection', $conn)
		$connection = $this->containerGet('Connection');
		if (\is_object($connection) && \method_exists($connection, 'send')) {
			$this->connection = $connection;
			$this->runtime = 'workerman';
			return $this;
		}

		// ② Swoole：Response 具备 write() 时为协程运行时
		$swooleResponse = $this->containerGet('Response');
		if (\is_object($swooleResponse) && \method_exists($swooleResponse, 'write')) {
			$this->connection = $swooleResponse;
			$this->runtime = 'swoole';
			if (!\headers_sent()) {
				\header('Content-Type: ' . $this->contentType);
				foreach ($this->headers as $name => $value) {
					\header($name . ': ' . $value);
				}
			}
			return $this;
		}

		// ③ FPM / CLI 兜底
		$this->runtime = 'fpm';
		if (!\headers_sent()) {
			\header('Content-Type: ' . $this->contentType);
			\header('Cache-Control: no-cache, no-store, must-revalidate');
			\header('Pragma: no-cache');
			\header('Connection: keep-alive');
			// nginx 反向代理必须显式关闭缓冲，否则数据会被攒着一次性发出
			\header('X-Accel-Buffering: no');
			foreach ($this->headers as $name => $value) {
				\header($name . ': ' . $value);
			}
		}
		// gzip / 输出压缩会破坏分块语义
		@\ini_set('zlib.output_compression', '0');
		@\ini_set('implicit_flush', '1');
		// 清掉框架（含 Respond::buff 之外的 PHP 输出缓冲）已开的所有层级
		while (\ob_get_level() > 0) {
			@\ob_end_flush();
		}
		@\ob_implicit_flush(true);
		return $this;
	}

	/**
	 * 写入原始数据（不做任何包装）
	 *
	 * @param string $data 要发送的字节
	 * @return bool 写入是否成功；false 表示连接不可继续使用
	 */
	public function write(string $data): bool {
		if ($this->closed) {
			return false;
		}
		if (!$this->started) {
			$this->start();
		}

		switch ($this->runtime) {
			case 'workerman':
				// 第二个参数 true：原始数据，不再走 HTTP 协议编码
				$sent = $this->connection->send($data, true);
				if ($sent === false) {
					$this->closed = true;
					return false;
				}
				return true;

			case 'swoole':
				$sent = $this->connection->write($data);
				if ($sent === false) {
					$this->closed = true;
					return false;
				}
				return true;

			default:
				echo $data;
				if (\ob_get_level() > 0) {
					@\ob_flush();
				}
				@\flush();
				return true;
		}
	}

	/**
	 * 发送一条 SSE 事件
	 *
	 * 输出格式：
	 * ```text
	 * id: 3
	 * event: message
	 * data: {"text":"你好"}
	 * ```
	 * （末尾空行是该事件结束标记）
	 *
	 * @param string $event 事件名（浏览器端 addEventListener 监听的名字）
	 * @param array|string $data 事件数据；数组会被 json_encode（不转义中文与斜杠）
	 * @param int|null $id 事件序号，SSE 断线重连时浏览器回传 Last-Event-ID
	 * @return bool 写入是否成功
	 */
	public function event(string $event, array|string $data = '', ?int $id = null): bool {
		$payload = \is_array($data)
			? (string)\json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			: $data;

		$frame = '';
		if ($id !== null) {
			$frame .= 'id: ' . $id . "\n";
		}
		$frame .= 'event: ' . $event . "\n";
		// SSE 协议按行切分：数据内的换行必须拆成多条 data:
		foreach (\explode("\n", $payload) as $line) {
			$frame .= 'data: ' . $line . "\n";
		}
		$frame .= "\n";

		return $this->write($frame);
	}

	/**
	 * 发送 SSE 注释行（常用作心跳，防代理断开空闲连接）
	 *
	 * @param string $text 注释内容
	 * @return bool 写入是否成功
	 */
	public function comment(string $text = ''): bool {
		return $this->write(': ' . $text . "\n\n");
	}

	/**
	 * 发送一行 JSON（NDJSON / JSON Lines）
	 *
	 * @param array $payload 数据
	 * @return bool 写入是否成功
	 */
	public function json(array $payload): bool {
		$line = \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $this->write($line . "\n");
	}

	/**
	 * 结束流式输出并终止本次请求
	 *
	 * 必须调用：否则框架回到正常流程后会再发送一次完整响应。
	 *
	 * @return void
	 */
	public function close(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;

		// Swoole 需显式结束响应对象
		if ($this->runtime === 'swoole' && \is_object($this->connection) && \method_exists($this->connection, 'end')) {
			$this->connection->end();
		}

		// 统一终止本次请求，避免框架回到正常流程后再发一次完整响应：
		// - workerman：stop() 抛 jump_exit，被 Server::handlePhp 的 SSE 分支捕获后直接 return
		// - FPM / CLI：stop() 内部 exit，Respond::send() 不会再执行
		stop();
	}

	/**
	 * 客户端是否已断开 / 流是否已关闭
	 *
	 * @return bool
	 */
	public function isClosed(): bool {
		return $this->closed;
	}

	/**
	 * 当前探测到的运行环境
	 *
	 * @return string workerman | swoole | fpm
	 */
	public function runtime(): string {
		return $this->runtime;
	}

	/**
	 * 从容器安全取值（容器不可用时返回 null）
	 *
	 * @param string $key 容器键名
	 * @return object|null
	 */
	private function containerGet(string $key): ?object {
		try {
			$container = Container::getInstance();
			$value = $container->get($key);
			return \is_object($value) ? $value : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
