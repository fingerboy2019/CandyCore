<?php
/***
 * MCP STDIO 传输循环
 *
 * 业务说明：按行读写 JSON-RPC；stdout 仅协议，日志走 stderr
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 20:29:43 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class StdioServer
{
	private Protocol $protocol;

	/**
	 * @param Protocol $protocol 协议处理器
	 */
	public function __construct(Protocol $protocol)
	{
		$this->protocol = $protocol;
	}

	/**
	 * 进入阻塞读写循环直至 stdin EOF
	 *
	 * @return int 退出码（0=正常）
	 */
	public function run(): int
	{
		stream_set_blocking(STDIN, true);

		while (($line = fgets(STDIN)) !== false) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			$decoded = json_decode($line, true);
			if (!is_array($decoded)) {
				Bootstrap::log('invalid json line');
				$this->write([
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => [
						'code'    => -32700,
						'message' => 'Parse error',
					],
				]);
				continue;
			}

			$response = $this->protocol->handle($decoded);
			if ($response !== null) {
				$this->write($response);
			}
		}

		return 0;
	}

	/**
	 * 写一行 JSON 到 stdout
	 *
	 * @param array<string, mixed> $payload 响应体
	 * @return void
	 */
	private function write(array $payload): void
	{
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			Bootstrap::log('json_encode failed for response');

			return;
		}
		fwrite(STDOUT, $json . "\n");
		fflush(STDOUT);
	}
}

