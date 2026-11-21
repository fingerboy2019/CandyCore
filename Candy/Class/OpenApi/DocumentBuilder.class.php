<?php
/***
 * OpenAPI 3.0 文档装配器
 *
 * 业务说明：复用 Apidoc 扫描与 ContractEnricher，产出完整 OAS 文档供 CLI/HTTP
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 23:07:48 $
 *
 * @package Candy\Extend\OpenApi
 */

declare(strict_types=1);

namespace Candy\Extend\OpenApi;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Apidoc\parses\ParseApiMenus;
use Apidoc\providers\CandyService;
use Apidoc\utils\{ConfigProvider, ControlClassResolver, Helper};
use Candy\Extend\Schema\{Base as SchemaBase, ResponseDataType};

/**
 * DocumentBuilder
 */
final class DocumentBuilder
{
	/**
	 * 构建 OpenAPI 文档
	 *
	 * @param string $appKey 应用 key，或 all 合并全部
	 * @param array<string,mixed> $options server/version 等覆盖
	 * @return array<string,mixed> OAS 根对象
	 */
	public static function build(string $appKey = 'admin', array $options = []): array
	{
		$config = self::loadConfig();
		$openapiCfg = is_array($config['openapi'] ?? null) ? $config['openapi'] : [];
		if (($openapiCfg['enable'] ?? true) === false) {
			throw new \RuntimeException('OpenAPI 导出已禁用（openapi.enable=false）');
		}

		$appKey = trim($appKey) !== '' ? trim($appKey) : 'admin';
		if (strtolower($appKey) === 'all') {
			return self::buildAll($config, $openapiCfg, $options);
		}

		return self::buildOne($appKey, $config, $openapiCfg, $options, false);
	}

	/**
	 * 加载并注入 Apidoc 配置
	 *
	 * @return array<string,mixed>
	 */
	public static function loadConfig(): array
	{
		// 与 HTTP 入口一致：定义 APIDOC_* 常量并注入 ConfigProvider
		(new CandyService())->initConfig();
		$config = ConfigProvider::get() ?: CandyService::getApidocConfig();
		if (!is_array($config)) {
			$config = [];
		}
		if (!isset($config['openapi']) || !is_array($config['openapi'])) {
			$config['openapi'] = [];
		}
		$config['openapi'] = array_merge([
			'enable'   => true,
			'version'  => '1.0.0',
			'server'   => '',
			'public'   => false,
			'public_controllers' => ['Login'],
			'request_body_content_type' => 'application/json',
			'cache'    => ['enable' => false],
		], $config['openapi']);

		ConfigProvider::set($config);
		return ConfigProvider::get() ?: $config;
	}

	/**
	 * @param array<string,mixed> $config 配置
	 * @param array<string,mixed> $openapiCfg openapi 段
	 * @param array<string,mixed> $options 选项
	 * @return array<string,mixed>
	 */
	private static function buildAll(array $config, array $openapiCfg, array $options): array
	{
		$apps = is_array($config['apps'] ?? null) ? $config['apps'] : [];
		$merged = [
			'openapi' => '3.0.3',
			'info'    => [
				'title'       => (string)($config['title'] ?? 'Candy API'),
				'description' => (string)($config['desc'] ?? ''),
				'version'     => (string)($options['version'] ?? $openapiCfg['version'] ?? '1.0.0'),
			],
			'servers' => self::servers($openapiCfg, $options),
			'tags'     => [],
			'paths'    => [],
			'components' => [
				'schemas'         => self::envelopeSchemas(),
				'securitySchemes' => self::securitySchemes(),
			],
			'security' => [['bearerAuth' => []]],
		];
		$tagNames = [];

		foreach ($apps as $app) {
			if (!is_array($app)) {
				continue;
			}
			$key = (string)($app['key'] ?? '');
			if ($key === '') {
				continue;
			}
			$doc = self::buildOne($key, $config, $openapiCfg, $options, true);
			$prefix = ucfirst($key);
			foreach ($doc['tags'] ?? [] as $tag) {
				if (!is_array($tag)) {
					continue;
				}
				$name = $prefix . '.' . ($tag['name'] ?? '');
				if ($name === $prefix . '.' || isset($tagNames[$name])) {
					continue;
				}
				$tagNames[$name] = true;
				$merged['tags'][] = [
					'name'        => $name,
					'description' => (string)($tag['description'] ?? ''),
				];
			}
			foreach ($doc['paths'] ?? [] as $path => $ops) {
				if (!is_array($ops)) {
					continue;
				}
				foreach ($ops as $method => &$op) {
					if (!is_array($op)) {
						continue;
					}
					$tags = $op['tags'] ?? [];
					$op['tags'] = array_map(
						static fn($t) => $prefix . '.' . $t,
						is_array($tags) ? $tags : []
					);
					$op['operationId'] = $prefix . '_' . ($op['operationId'] ?? '');
				}
				unset($op);
				if (!isset($merged['paths'][$path])) {
					$merged['paths'][$path] = $ops;
				} else {
					$merged['paths'][$path] = array_merge($merged['paths'][$path], $ops);
				}
			}
			foreach ($doc['components']['schemas'] ?? [] as $name => $schema) {
				if ($name === 'ApiSuccess' || $name === 'ApiError' || $name === 'ApiPage') {
					continue;
				}
				$merged['components']['schemas'][$prefix . '_' . $name] = $schema;
			}
		}

		ksort($merged['paths']);
		return $merged;
	}

	/**
	 * @param string $appKey app
	 * @param array<string,mixed> $config 配置
	 * @param array<string,mixed> $openapiCfg openapi
	 * @param array<string,mixed> $options 选项
	 * @param bool $forMerge 是否合并模式（不影响单文档）
	 * @return array<string,mixed>
	 */
	private static function buildOne(
		string $appKey,
		array $config,
		array $openapiCfg,
		array $options,
		bool $_forMerge = false
	): array {
		$current = Helper::getCurrentAppConfig($appKey);
		$appConfig = is_array($current['appConfig'] ?? null) ? $current['appConfig'] : [];
		$title = (string)($appConfig['title'] ?? $appKey);

		$menus = (new ParseApiMenus($config))->renderApiMenus($appKey, true);
		$tree = is_array($menus['data'] ?? null) ? $menus['data'] : [];

		$tags = [];
		$tagSeen = [];
		$paths = [];
		$schemas = self::envelopeSchemas();
		$publicControllers = array_map(
			'strtolower',
			array_map('strval', is_array($openapiCfg['public_controllers'] ?? null)
				? $openapiCfg['public_controllers']
				: ['Login'])
		);
		$contentType = (string)($openapiCfg['request_body_content_type'] ?? 'application/json');
		$appFolder = self::guessAppFolder($appConfig);

		self::walkApiTree($tree, function (array $controller, array $method) use (
			&$tags,
			&$tagSeen,
			&$paths,
			&$schemas,
			$publicControllers,
			$contentType,
			$appFolder
		): void {
			$ctrlName = (string)($controller['controller'] ?? 'Api');
			$ctrlTitle = (string)($controller['title'] ?? $ctrlName);
			if (!isset($tagSeen[$ctrlName])) {
				$tagSeen[$ctrlName] = true;
				$tags[] = [
					'name'        => $ctrlName,
					'description' => $ctrlTitle,
				];
			}

			$url = self::normalizePath((string)($method['url'] ?? ''));
			if ($url === '') {
				return;
			}
			// 双保险：跳过 @NotParse
			if (!empty($method['notParse'])) {
				return;
			}
			$methodName = (string)($method['name'] ?? 'index');
			$httpMethods = self::normalizeHttpMethods($method['method'] ?? 'GET');
			$isPublic = in_array(strtolower($ctrlName), $publicControllers, true)
				|| self::controllerHasNoAuth((string)($controller['path'] ?? ''));

			// 收集 Schema 组件
			self::collectSchemaComponents($method, $appFolder, $schemas);

			$operationBase = [
				'tags'        => [$ctrlName],
				'summary'     => (string)($method['title'] ?? $methodName),
				'operationId' => $ctrlName . '_' . $methodName,
				'parameters'  => [],
				'responses'   => self::buildResponses($method, $schemas),
			];
			if ($isPublic) {
				$operationBase['security'] = [];
			}

			$query = is_array($method['query'] ?? null) ? $method['query'] : [];
			$header = is_array($method['header'] ?? null) ? $method['header'] : [];
			$routeParam = is_array($method['routeParam'] ?? null) ? $method['routeParam'] : [];
			$operationBase['parameters'] = array_merge(
				FieldMapper::toParameters($header, 'header'),
				FieldMapper::toParameters($query, 'query'),
				FieldMapper::toParameters($routeParam, 'path')
			);

			$body = is_array($method['param'] ?? null) ? $method['param'] : [];
			if ($body !== []) {
				$operationBase['requestBody'] = [
					'required' => self::bodyHasRequired($body),
					'content'  => [
						$contentType => [
							'schema' => FieldMapper::toObjectSchema($body),
						],
					],
				];
			}

			if (!isset($paths[$url])) {
				$paths[$url] = [];
			}
			foreach ($httpMethods as $http) {
				$op = $operationBase;
				$op['operationId'] = $ctrlName . '_' . $methodName . (
					count($httpMethods) > 1 ? '_' . strtolower($http) : ''
				);
				$paths[$url][strtolower($http)] = $op;
			}
		});

		ksort($paths);

		return [
			'openapi' => '3.0.3',
			'info'    => [
				'title'       => $title,
				'description' => (string)($config['desc'] ?? ''),
				'version'     => (string)($options['version'] ?? $openapiCfg['version'] ?? '1.0.0'),
			],
			'servers' => self::servers($openapiCfg, $options),
			'tags'     => $tags,
			'paths'    => $paths,
			'components' => [
				'schemas'         => $schemas,
				'securitySchemes' => self::securitySchemes(),
			],
			'security' => [['bearerAuth' => []]],
		];
	}

	/**
	 * 递归遍历菜单树，回调每个接口方法
	 *
	 * @param list<array<string,mixed>> $nodes 节点
	 * @param callable(array,array):void $fn 回调
	 * @return void
	 */
	private static function walkApiTree(array $nodes, callable $fn): void
	{
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			if (!empty($node['controller']) && is_array($node['children'] ?? null)) {
				foreach ($node['children'] as $child) {
					if (!is_array($child)) {
						continue;
					}
					// 方法节点：有 name，且通常有 url/method；排除再嵌套的控制器
					if (!empty($child['controller'])) {
						self::walkApiTree([$child], $fn);
						continue;
					}
					if (empty($child['name'])) {
						continue;
					}
					$fn($node, $child);
				}
				continue;
			}
			if (!empty($node['children']) && is_array($node['children'])) {
				self::walkApiTree($node['children'], $fn);
			}
		}
	}

	/**
	 * @param array<string,mixed> $openapiCfg 配置
	 * @param array<string,mixed> $options 选项
	 * @return list<array{url:string}>
	 */
	private static function servers(array $openapiCfg, array $options): array
	{
		$url = trim((string)($options['server'] ?? $openapiCfg['server'] ?? ''));
		$server = I('server.', [], '');
		if ($url === '' && !empty($server['HTTP_HOST'])) {
			$scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
			$url = $scheme . '://' . $server['HTTP_HOST'];
		}
		if ($url === '') {
			$url = '/';
		}
		return [['url' => rtrim($url, '/') ?: '/']];
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function securitySchemes(): array
	{
		return [
			'bearerAuth' => [
				'type'         => 'http',
				'scheme'       => 'bearer',
				'bearerFormat' => 'JWT',
				'description'  => '业务 JWT（Authorization: Bearer）',
			],
		];
	}

	/**
	 * 统一响应信封组件
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function envelopeSchemas(): array
	{
		return [
			'ApiSuccess' => [
				'type'       => 'object',
				'required'   => ['code', 'message'],
				'properties' => [
					'code'    => ['type' => 'integer', 'description' => '业务码，0 成功', 'example' => 0],
					'message' => ['type' => 'string', 'description' => '提示'],
					'data'    => ['description' => '业务数据', 'nullable' => true],
				],
			],
			'ApiPage' => [
				'type'       => 'object',
				'required'   => ['code', 'message'],
				'properties' => [
					'code'    => ['type' => 'integer', 'description' => '业务码，0 成功', 'example' => 0],
					'message' => ['type' => 'string', 'description' => '提示'],
					'count'   => ['type' => 'integer', 'description' => '总记录数'],
					'data'    => [
						'type'  => 'array',
						'items' => ['type' => 'object', 'additionalProperties' => true],
					],
				],
			],
			'ApiError' => [
				'type'       => 'object',
				'required'   => ['code', 'message'],
				'properties' => [
					'code'    => ['type' => 'integer', 'description' => '非 0 表示失败', 'example' => 1],
					'message' => ['type' => 'string', 'description' => '错误提示'],
				],
			],
		];
	}

	/**
	 * @param array<string,mixed> $method 方法注解
	 * @param array<string,array<string,mixed>> $schemas 组件表（引用）
	 * @return array<string,mixed>
	 */
	private static function buildResponses(array $method, array &$schemas): array
	{
		$successFields = is_array($method['responseSuccess'] ?? null) ? $method['responseSuccess'] : [];
		$schemaName = self::schemaRefNameFromContract($method);
		$dataType = self::dataTypeFromContract($method);

		$successSchema = null;
		if ($schemaName !== '' && isset($schemas[$schemaName])) {
			if ($dataType === ResponseDataType::Page->value || $dataType === 'page') {
				$successSchema = [
					'type'       => 'object',
					'required'   => ['code', 'message'],
					'properties' => [
						'code'    => ['type' => 'integer', 'example' => 0],
						'message' => ['type' => 'string'],
						'count'   => ['type' => 'integer'],
						'data'    => [
							'type'  => 'array',
							'items' => ['$ref' => '#/components/schemas/' . $schemaName],
						],
					],
				];
			} elseif ($dataType === ResponseDataType::List->value || $dataType === 'list') {
				$successSchema = [
					'type'       => 'object',
					'required'   => ['code', 'message'],
					'properties' => [
						'code'    => ['type' => 'integer', 'example' => 0],
						'message' => ['type' => 'string'],
						'data'    => [
							'type'  => 'array',
							'items' => ['$ref' => '#/components/schemas/' . $schemaName],
						],
					],
				];
			} elseif ($dataType === ResponseDataType::None->value || $dataType === 'none') {
				$successSchema = ['$ref' => '#/components/schemas/ApiSuccess'];
			} else {
				$successSchema = [
					'allOf' => [
						['$ref' => '#/components/schemas/ApiSuccess'],
						[
							'type'       => 'object',
							'properties' => [
								'data' => ['$ref' => '#/components/schemas/' . $schemaName],
							],
						],
					],
				];
			}
		}

		if ($successSchema === null) {
			if ($successFields !== []) {
				$successSchema = FieldMapper::responseFieldsToSchema($successFields);
			} else {
				$successSchema = ['$ref' => '#/components/schemas/ApiSuccess'];
			}
		}

		$errorFields = is_array($method['responseError'] ?? null) ? $method['responseError'] : [];
		$errorSchema = $errorFields !== []
			? FieldMapper::responseFieldsToSchema($errorFields)
			: ['$ref' => '#/components/schemas/ApiError'];

		return [
			'200' => [
				'description' => '成功',
				'content'     => [
					'application/json' => [
						'schema' => $successSchema,
					],
				],
			],
			'default' => [
				'description' => '业务失败或其他错误',
				'content'     => [
					'application/json' => [
						'schema' => $errorSchema,
					],
				],
			],
		];
	}

	/**
	 * @param array<string,mixed> $method 方法
	 * @param string $appFolder 应用目录名
	 * @param array<string,array<string,mixed>> $schemas 组件
	 * @return void
	 */
	private static function collectSchemaComponents(array $method, string $appFolder, array &$schemas): void
	{
		$contract = self::readContract($method);
		$ref = (string)($contract['schema'] ?? '');
		if ($ref === '') {
			return;
		}
		$class = self::resolveSchemaClass($ref, $appFolder);
		if ($class === '' || !class_exists($class) || !is_subclass_of($class, SchemaBase::class)) {
			return;
		}
		/** @var class-string<SchemaBase> $class */
		$chunk = $class::openApiSchemas();
		if (!is_array($chunk)) {
			return;
		}
		foreach ($chunk as $name => $schema) {
			if (is_string($name) && is_array($schema)) {
				$schemas[$name] = $schema;
			}
		}
	}

	/**
	 * @param array<string,mixed> $method 方法
	 * @return string
	 */
	private static function schemaRefNameFromContract(array $method): string
	{
		$contract = self::readContract($method);
		$ref = trim((string)($contract['schema'] ?? ''));
		if ($ref === '') {
			return '';
		}
		if (str_contains($ref, '\\')) {
			$short = substr($ref, strrpos($ref, '\\') + 1);
			return str_ends_with($short, 'Schema') ? substr($short, 0, -6) : $short;
		}
		return str_ends_with($ref, 'Schema') ? substr($ref, 0, -6) : $ref;
	}

	/**
	 * @param array<string,mixed> $method 方法
	 * @return string
	 */
	private static function dataTypeFromContract(array $method): string
	{
		$contract = self::readContract($method);
		return strtolower(trim((string)($contract['dataType'] ?? '')));
	}

	/**
	 * @param array<string,mixed> $method 方法
	 * @return array<string,string>
	 */
	private static function readContract(array $method): array
	{
		$raw = $method['apiContract'] ?? null;
		if ($raw === null || $raw === '' || $raw === true) {
			return [];
		}
		if (!is_array($raw)) {
			return [];
		}
		if (isset($raw[0]) && is_array($raw[0])) {
			$raw = $raw[0];
		}
		$out = [];
		foreach (['validate', 'scene', 'schema', 'dataType'] as $k) {
			if (isset($raw[$k]) && is_string($raw[$k]) && $raw[$k] !== '') {
				$out[$k] = $raw[$k];
			}
		}
		return $out;
	}

	/**
	 * @param string $ref 短名或 FQCN
	 * @param string $app 应用
	 * @return string
	 */
	private static function resolveSchemaClass(string $ref, string $app): string
	{
		$ref = trim($ref);
		if ($ref === '') {
			return '';
		}
		if (str_contains($ref, '\\')) {
			return ltrim($ref, '\\');
		}
		$name = str_ends_with($ref, 'Schema') ? $ref : $ref . 'Schema';
		return 'APP\\Schema\\' . $app . '\\' . $name;
	}

	/**
	 * @param array<string,mixed> $appConfig app
	 * @return string
	 */
	private static function guessAppFolder(array $appConfig): string
	{
		$path = (string)($appConfig['path'] ?? '');
		if (preg_match('#Application[/\\\\]([^/\\\\]+)[/\\\\]Controls#', $path, $m)) {
			return $m[1];
		}
		$key = (string)($appConfig['key'] ?? 'Admin');
		return ucfirst($key);
	}

	/**
	 * @param string $sourceFqcn 源码 FQCN
	 * @return bool
	 */
	private static function controllerHasNoAuth(string $sourceFqcn): bool
	{
		if ($sourceFqcn === '') {
			return false;
		}
		try {
			$class = ControlClassResolver::resolve($sourceFqcn, []);
			if (!class_exists($class, false) && !class_exists($class)) {
				return false;
			}
			return method_exists($class, '__noAuth');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @param mixed $method method 字段
	 * @return list<string>
	 */
	private static function normalizeHttpMethods($method): array
	{
		if (is_array($method)) {
			$list = array_map(static fn($m) => strtoupper(trim((string)$m)), $method);
		} else {
			$list = Helper::handleApiMethod($method);
		}
		$list = array_values(array_unique(array_filter($list)));
		if ($list === [] || (count($list) === 1 && ($list[0] === '*' || $list[0] === ''))) {
			return ['GET'];
		}
		return $list;
	}

	/**
	 * @param string $url URL
	 * @return string
	 */
	private static function normalizePath(string $url): string
	{
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		// 去掉 host
		if (preg_match('#^https?://[^/]+(/.*)$#i', $url, $m)) {
			$url = $m[1];
		}
		if ($url[0] !== '/') {
			$url = '/' . $url;
		}
		return $url;
	}

	/**
	 * @param list<array<string,mixed>> $body 字段
	 * @return bool
	 */
	private static function bodyHasRequired(array $body): bool
	{
		foreach ($body as $field) {
			if (!is_array($field)) {
				continue;
			}
			$r = $field['required'] ?? false;
			if ($r === true || $r === 1 || $r === '1' || $r === 'true') {
				return true;
			}
		}
		return false;
	}
}
