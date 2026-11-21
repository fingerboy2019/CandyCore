<?php
/***
 * MCP 服务引导（框架扩展层）
 *
 * 业务说明：合并框架 Builtin Tools 与项目 tool_classes；再按 server 白名单与风险分级注册
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 11:04:05 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

use Candy\Extend\Mcp\Tools\CandyCommands;
use Candy\Extend\Mcp\Tools\DbAggregate;
use Candy\Extend\Mcp\Tools\DbJoinSelect;
use Candy\Extend\Mcp\Tools\DbSample;
use Candy\Extend\Mcp\Tools\DbSelect;
use Candy\Extend\Mcp\Tools\KnowledgeAnswerSearch;
use Candy\Extend\Mcp\Tools\KnowledgeAsk;
use Candy\Extend\Mcp\Tools\KnowledgeSearch;
use Candy\Extend\Mcp\Tools\McpAsyncDemo;
use Candy\Extend\Mcp\Tools\McpConfirm;
use Candy\Extend\Mcp\Tools\McpGrantRenew;
use Candy\Extend\Mcp\Tools\OpenapiExport;
use Candy\Extend\Mcp\Tools\RuntimeClear;
use Candy\Extend\Mcp\Tools\SchemaTables;
use Candy\Extend\Mcp\Tools\SystemInfo;
use Candy\Extend\Mcp\Tools\TestRun;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Bootstrap
{
	/**
	 * 框架内置开发能力 Tool（与具体业务表无关）
	 *
	 * @var array<string, class-string<ToolInterface>>
	 */
	private const BUILTIN = [
		'system_info'             => SystemInfo::class,
		'candy_commands'          => CandyCommands::class,
		'runtime_clear'           => RuntimeClear::class,
		'openapi_export'          => OpenapiExport::class,
		'schema_tables'           => SchemaTables::class,
		'db_select'               => DbSelect::class,
		'db_sample'               => DbSample::class,
		'db_aggregate'            => DbAggregate::class,
		'db_join_select'          => DbJoinSelect::class,
		'mcp_grant_renew'         => McpGrantRenew::class,
		'test_run'                => TestRun::class,
		'knowledge_search'        => KnowledgeSearch::class,
		'knowledge_answer_search' => KnowledgeAnswerSearch::class,
		'knowledge_ask'           => KnowledgeAsk::class,
		'mcp_confirm'             => McpConfirm::class,
		'mcp_async_demo'          => McpAsyncDemo::class,
	];

	/**
	 * 当前是否为纯 2026 模式（关闭 initialize / 协议 Session）
	 *
	 * @return bool
	 */
	public static function isStrict2026(): bool
	{
		$mode = strtolower(trim((string) (self::config()['protocol_mode'] ?? 'compat')));

		return $mode === 'strict_2026';
	}

	/**
	 * 协议兼容模式：compat（默认）| strict_2026
	 *
	 * @return string
	 */
	public static function protocolMode(): string
	{
		return self::isStrict2026() ? 'strict_2026' : 'compat';
	}

	/**
	 * 是否为框架 Builtin Tool 名（禁止项目 tool_classes 同名覆盖）
	 *
	 * @param string $name Tool 名
	 * @return bool
	 */
	public static function isBuiltinName(string $name): bool
	{
		return $name !== '' && isset(self::BUILTIN[$name]);
	}

	/**
	 * 加载项目 MCP 配置
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array
	{
		$cfg = loadConfig('Mcp');

		return is_array($cfg) ? $cfg : [];
	}

	/**
	 * 总开关是否开启
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool
	{
		$cfg = self::config();

		return !empty($cfg['enable']);
	}

	/**
	 * 取指定 server 段配置
	 *
	 * @param string $name server 名
	 * @return array<string, mixed>|null
	 */
	public static function serverConfig(string $name): ?array
	{
		$cfg = self::config();
		$servers = $cfg['servers'] ?? [];
		if (!is_array($servers) || !isset($servers[$name]) || !is_array($servers[$name])) {
			return null;
		}

		return $servers[$name];
	}

	/**
	 * 框架 Builtin 类表（受 Mcp.inc builtin 段选用）
	 *
	 * @return array<string, class-string<ToolInterface>>
	 */
	public static function builtinClasses(): array
	{
		$cfg = self::config();
		$builtin = $cfg['builtin'] ?? [];
		if (!is_array($builtin)) {
			$builtin = [];
		}
		// enable 缺省 true；显式 false 则关闭全部 Builtin
		if (array_key_exists('enable', $builtin) && empty($builtin['enable'])) {
			return [];
		}

		$selected = $builtin['tools'] ?? array_keys(self::BUILTIN);
		if (!is_array($selected)) {
			return [];
		}

		$out = [];
		foreach ($selected as $toolName) {
			$name = (string) $toolName;
			if ($name !== '' && isset(self::BUILTIN[$name])) {
				$out[$name] = self::BUILTIN[$name];
			}
		}

		return $out;
	}

	/**
	 * 项目业务 Tool 类表（Mcp.inc tool_classes）
	 *
	 * @return array<string, class-string<ToolInterface>>
	 */
	public static function toolClasses(): array
	{
		$cfg = self::config();
		$map = $cfg['tool_classes'] ?? [];
		if (!is_array($map)) {
			return [];
		}

		$out = [];
		foreach ($map as $name => $class) {
			$n = (string) $name;
			$c = is_string($class) ? $class : '';
			if ($n === '' || $c === '') {
				continue;
			}
			// 禁止项目覆盖框架 Builtin 同名，避免边界混乱
			if (isset(self::BUILTIN[$n])) {
				self::log('skip project tool_classes shadowing builtin: ' . $n);
				continue;
			}
			$out[$n] = $c;
		}

		return $out;
	}

	/**
	 * 合并后的完整目录（Builtin ∪ 项目）
	 *
	 * @return array<string, class-string<ToolInterface>>
	 */
	public static function catalog(): array
	{
		return self::builtinClasses() + self::toolClasses();
	}

	/**
	 * 当前是否允许 ops 级 Tool
	 *
	 * @return bool
	 */
	public static function allowOps(): bool
	{
		$cfg = self::config();
		if (!empty($cfg['allow_ops'])) {
			return true;
		}

		return defined('DEBUG') && (int) DEBUG === 1;
	}

	/**
	 * 按 server 白名单构建 Registry
	 *
	 * @param string $serverName server 名
	 * @return Registry
	 * @throws \RuntimeException server 不存在或配置非法
	 */
	public static function buildRegistry(string $serverName): Registry
	{
		$server = self::serverConfig($serverName);
		if ($server === null) {
			throw new \RuntimeException('MCP server 不存在: ' . $serverName);
		}

		$whitelist = $server['tools'] ?? [];
		if (!is_array($whitelist)) {
			throw new \RuntimeException('MCP tools 白名单必须为数组');
		}

		$catalog = self::catalog();
		$allowOps = self::allowOps();
		$registry = new Registry();

		foreach ($whitelist as $toolName) {
			$name = (string) $toolName;
			if ($name === '' || !isset($catalog[$name])) {
				self::log('skip unknown or uncatalogued tool: ' . $name);
				continue;
			}

			$class = $catalog[$name];
			if (!class_exists($class)) {
				self::log('skip missing tool class: ' . $class);
				continue;
			}

			$tool = new $class();
			if (!$tool instanceof ToolInterface) {
				self::log('skip non-ToolInterface: ' . $class);
				continue;
			}
			if ($tool->name() !== $name) {
				self::log('skip tool name mismatch: catalog=' . $name . ' class=' . $tool->name());
				continue;
			}

			$risk = $tool->risk();
			if ($risk === 'ops' && !$allowOps) {
				self::log('skip ops tool (DEBUG=0 and allow_ops=false): ' . $name);
				continue;
			}

			$registry->register($tool);
		}

		return $registry;
	}

	/**
	 * 写日志（daemon 下 STDERR 可能无效，回退 Worker::log / error_log）
	 *
	 * @param string $message 日志内容
	 * @return void
	 */
	public static function log(string $message): void
	{
		$line = '[candy-mcp] ' . $message;
		$written = false;
		if (\defined('STDERR') && \is_resource(STDERR)) {
			$written = @fwrite(STDERR, $line . "\n") !== false;
		}
		if (!$written && \class_exists(\Workerman\Worker::class)) {
			try {
				\Workerman\Worker::log($line);
				$written = true;
			} catch (\Throwable) {
				$written = false;
			}
		}
		if (!$written) {
			@error_log($line);
		}
	}
}

