<?php
/***
 * MCP Tool：OpenAPI 导出摘要（只读）
 *
 * 业务说明：调用 DocumentBuilder；默认可选写入文件，返回 paths 数量与摘要
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 20:36:57 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\AdminContext;
use Candy\Extend\Mcp\ToolInterface;
use Candy\Extend\OpenApi\DocumentBuilder;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class OpenapiExport implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'openapi_export';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Build OpenAPI 3.0 from Apidoc annotations; return path count and optional write to file.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function risk(): string
	{
		return 'readonly';
	}

	/**
	 * {@inheritdoc}
	 */
	public function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'app' => [
					'type'        => 'string',
					'description' => 'App key: admin|home|all (default admin)',
					'default'     => 'admin',
				],
				'output' => [
					'type'        => 'string',
					'description' => 'Optional absolute/relative path to write JSON; empty = no file',
				],
				'include_paths' => [
					'type'        => 'boolean',
					'description' => 'Include path keys list in response (default true)',
					'default'     => true,
				],
			],
			'additionalProperties' => false,
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function outputSchema(): ?array
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function handle(array $args): array
	{
		$appKey = strtolower(trim((string) ($args['app'] ?? 'admin')));
		if ($appKey === '') {
			$appKey = 'admin';
		}

		$bootApp = match ($appKey) {
			'home' => 'Home',
			'all' => 'Admin',
			default => 'Admin',
		};
		AdminContext::boot($bootApp);

		try {
			$doc = DocumentBuilder::build($appKey, []);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'OpenAPI build failed: ' . $e->getMessage()];
		}

		$paths = is_array($doc['paths'] ?? null) ? $doc['paths'] : [];
		$pathKeys = array_keys($paths);
		$includePaths = !array_key_exists('include_paths', $args) || !empty($args['include_paths']);

		$output = trim((string) ($args['output'] ?? ''));
		$written = null;
		if ($output !== '') {
			$abs = $this->resolveOutputPath($output);
			$dir = dirname($abs);
			if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
				if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
					return ['ok' => false, 'error' => 'cannot create directory: ' . $dir];
				}
			}
			$json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
			if ($json === false || @file_put_contents($abs, $json . "\n") === false) {
				return ['ok' => false, 'error' => 'write failed: ' . $abs];
			}
			$written = $abs;
		}

		$data = [
			'app'         => $appKey,
			'openapi'     => $doc['openapi'] ?? null,
			'title'       => $doc['info']['title'] ?? null,
			'path_count'  => count($pathKeys),
			'output_file' => $written,
		];
		if ($includePaths) {
			// 控制体积：最多返回 200 条 path
			$data['paths'] = array_slice($pathKeys, 0, 200);
			if (count($pathKeys) > 200) {
				$data['paths_truncated'] = true;
			}
		}

		return ['ok' => true, 'data' => $data];
	}

	/**
	 * 解析输出路径（相对则相对 CANDYROOT）
	 *
	 * @param string $output 用户传入路径
	 * @return string
	 */
	private function resolveOutputPath(string $output): string
	{
		if ($output[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $output) === 1) {
			return $output;
		}
		$root = rtrim((string) C('CANDYROOT'), '/\\');

		return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $output);
	}
}
