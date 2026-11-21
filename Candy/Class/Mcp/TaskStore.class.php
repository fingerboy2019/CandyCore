<?php
/***
 * MCP Tasks 扩展本地存储
 *
 * 业务说明：io.modelcontextprotocol/tasks 轮询句柄落 Runtime/Mcp/tasks；跨 Worker 不共享（单机/单进程约定）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 10:54:51 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class TaskStore
{
	/**
	 * 创建任务并返回完整记录（含 taskId）
	 *
	 * @param string               $server  server 名
	 * @param string               $tool    来源 Tool（可空）
	 * @param array<string, mixed> $seed    status / data / message / ttl_sec
	 * @return array<string, mixed>
	 */
	public static function create(string $server, string $tool, array $seed): array
	{
		$id = bin2hex(random_bytes(16));
		$ttl = isset($seed['ttl_sec']) ? (int) $seed['ttl_sec'] : 3600;
		if ($ttl < 60) {
			$ttl = 60;
		}
		if ($ttl > 86400) {
			$ttl = 86400;
		}
		$status = trim((string) ($seed['status'] ?? 'working'));
		if ($status === '') {
			$status = 'working';
		}
		$now = time();
		$record = [
			'taskId'    => $id,
			'server'    => $server,
			'tool'      => $tool,
			'status'    => $status,
			'message'   => (string) ($seed['message'] ?? ''),
			'progress'  => isset($seed['progress']) ? (int) $seed['progress'] : null,
			'data'      => is_array($seed['data'] ?? null) ? $seed['data'] : ($seed['data'] ?? null),
			'createdAt' => $now,
			'updatedAt' => $now,
			'expiresAt' => $now + $ttl,
		];
		self::write($id, $record);

		return $record;
	}

	/**
	 * 读取任务（过期则删除并返回 null）
	 *
	 * @param string $taskId 任务 ID
	 * @return array<string, mixed>|null
	 */
	public static function get(string $taskId): ?array
	{
		$taskId = preg_replace('/[^a-f0-9]/', '', strtolower($taskId)) ?? '';
		if ($taskId === '' || strlen($taskId) < 16) {
			return null;
		}
		$path = self::path($taskId);
		if (!is_file($path)) {
			return null;
		}
		$raw = file_get_contents($path);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data)) {
			return null;
		}
		$exp = (int) ($data['expiresAt'] ?? 0);
		if ($exp > 0 && $exp < time()) {
			@unlink($path);

			return null;
		}

		return $data;
	}

	/**
	 * 合并更新任务字段
	 *
	 * @param string               $taskId 任务 ID
	 * @param array<string, mixed> $patch  可含 status/message/progress/data/input
	 * @return array<string, mixed>|null 更新后记录；不存在则 null
	 */
	public static function update(string $taskId, array $patch): ?array
	{
		$cur = self::get($taskId);
		if ($cur === null) {
			return null;
		}
		foreach (['status', 'message'] as $k) {
			if (array_key_exists($k, $patch)) {
				$cur[$k] = (string) $patch[$k];
			}
		}
		if (array_key_exists('progress', $patch)) {
			$cur['progress'] = $patch['progress'] === null ? null : (int) $patch['progress'];
		}
		if (array_key_exists('data', $patch)) {
			$cur['data'] = $patch['data'];
		}
		if (array_key_exists('input', $patch)) {
			$cur['input'] = $patch['input'];
		}
		$cur['updatedAt'] = time();
		self::write((string) $cur['taskId'], $cur);

		return $cur;
	}

	/**
	 * 任务文件路径
	 *
	 * @param string $taskId 任务 ID
	 * @return string
	 */
	private static function path(string $taskId): string
	{
		return self::dir() . '/' . $taskId . '.json';
	}

	/**
	 * 存储目录（{CANDYROOT}/Runtime/Mcp/tasks）
	 *
	 * @return string
	 */
	private static function dir(): string
	{
		$root = defined('CANDYROOT') ? (string) CANDYROOT : (string) C('CANDYROOT');
		$dir = rtrim($root, '/\\') . '/Runtime/Mcp/tasks';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}

		return $dir;
	}

	/**
	 * 落盘
	 *
	 * @param string               $taskId 任务 ID
	 * @param array<string, mixed> $record 记录
	 * @return void
	 */
	private static function write(string $taskId, array $record): void
	{
		$path = self::path($taskId);
		$json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException('TaskStore encode failed');
		}
		if (file_put_contents($path, $json, LOCK_EX) === false) {
			throw new \RuntimeException('TaskStore write failed: ' . $path);
		}
	}
}
