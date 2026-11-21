<?php
/***
 * 表结构缓存 Trait
 *
 * 各 PDO 驱动共用 Runtime/Data 字段缓存读写。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-09-15 16:50:12 $
 *
 * @package Candy\Extend\DB\Trait
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

trait TableSchemaCache
{
	/**
	 * 读取表结构缓存
	 *
	 * @param string $tabName 无前缀表名
	 * @return array{0:bool,1:array,2:string} [命中, fields, auto]
	 */
	protected function loadTableSchemaCache(string $tabName): array
	{
		$requestCache = $this->loadTableSchemaRequestCache($tabName);
		if ($requestCache !== null) {
			return $requestCache;
		}

		$cachefile = C('RUNTIME') . 'Data/' . $tabName . '.php';
		$json = !\Candy\Core\Cache::isFileDriver()
			? (file_exists($cachefile) ? ltrim((string) file_get_contents($cachefile), '<?php ') : false)
			: \Candy\Core\Cache::store()->get('data.' . md5($cachefile), false);
		if ($json === false || $json === '') {
			return [false, [], 'no'];
		}
		//⚠️ auto 只有 'yes'(3 位) / 'no'(2 位) 两种，绝不能按固定 3 位截断：
		//  无自增主键的表（如 save 复合主键 sa_ticket_reads）尾缀是 'no'，按 3 位截会
		//  连同 JSON 结尾的 '}' 一起截掉 → json_decode 失败 → 缓存恒判无效 →
		//  每个请求都重跑 information_schema 元数据查询（实测 3~14ms/次）。
		$autoSuffix = substr((string) $json, -3) === 'yes' ? 'yes' : 'no';
		$auto = $autoSuffix;
		$json = substr((string) $json, 0, $autoSuffix === 'yes' ? -3 : -2);
		$fields = (array) json_decode($json, true);
		if ($fields === [] || !isset($fields['pri']) || $fields['pri'] === '') {
			return [false, [], 'no'];
		}
		$this->saveTableSchemaRequestCache($tabName, $fields, $auto);

		return [true, $fields, $auto];
	}

	/**
	 * 写入表结构缓存
	 *
	 * @param string $tabName 无前缀表名
	 * @param array $fields 字段
	 * @param string $auto 自增
	 * @return void
	 */
	protected function saveTableSchemaCache(string $tabName, array $fields, string $auto): void
	{
		$this->saveTableSchemaRequestCache($tabName, $fields, $auto);
		if (isDebug()) {
			return;
		}
		$cachefile = C('RUNTIME') . 'Data/' . $tabName . '.php';
		$payload = json_encode($fields) . $auto;
		!\Candy\Core\Cache::isFileDriver()
			? file_put_contents($cachefile, '<?php ' . $payload)
			: \Candy\Core\Cache::store()->set('data.' . md5($cachefile), $payload);
	}

	/**
	 * 读取当前请求内的表结构缓存。
	 *
	 * 该缓存只复用当前请求已经读取过的完整字段元数据，不改变 DEBUG 模式
	 * 对跨请求 Runtime/Data 缓存的禁用策略；T() 会按 Web 请求 UUID 或协程隔离。
	 *
	 * @param string $tabName 无前缀表名
	 * @return array{0:bool,1:array,2:string}|null 命中结果，未命中返回 null
	 */
	private function loadTableSchemaRequestCache(string $tabName): ?array
	{
		if (!function_exists('T')) {
			return null;
		}

		$cached = T($this->tableSchemaRequestCacheKey($tabName), '', null);
		if (!is_array($cached) || !isset($cached['fields'], $cached['auto'])) {
			return null;
		}

		$fields = $cached['fields'];
		if (!is_array($fields) || $fields === [] || !isset($fields['pri']) || $fields['pri'] === '') {
			return null;
		}

		return [true, $fields, (string) $cached['auto']];
	}

	/**
	 * 写入当前请求内的表结构缓存。
	 *
	 * 只有包含主键元数据的完整结果才进入请求缓存，避免把表不存在或结构
	 * 不完整的探测结果固定在当前请求中。
	 *
	 * @param string $tabName 无前缀表名
	 * @param array $fields 字段
	 * @param string $auto 自增
	 * @return void
	 */
	private function saveTableSchemaRequestCache(string $tabName, array $fields, string $auto): void
	{
		if (!function_exists('T') || $fields === [] || !isset($fields['pri']) || $fields['pri'] === '') {
			return;
		}

		T($this->tableSchemaRequestCacheKey($tabName), [
			'fields' => $fields,
			'auto' => $auto,
		]);
	}

	/**
	 * 生成连接隔离的请求缓存键。
	 *
	 * 连接池键、库别名、数据库名、主机、端口、表前缀和驱动类共同参与
	 * 摘要，避免同一请求内切换租户、读写库或驱动时误用其他连接的元数据。
	 *
	 * @param string $tabName 无前缀表名
	 * @return string 请求缓存键
	 */
	private function tableSchemaRequestCacheKey(string $tabName): string
	{
		$poolKey = method_exists($this, 'resolvePoolKey')
			? (string) $this->resolvePoolKey()
			: '';
		$alias = method_exists($this, 'getDbAlias')
			? (string) $this->getDbAlias()
			: '';
		$identity = [
			static::class,
			$poolKey,
			$alias,
			(string) ($this->host ?? ''),
			(string) ($this->port ?? ''),
			(string) ($this->dbname ?? ''),
			(string) ($this->tablePrefix ?? ''),
			trim($tabName),
		];

		return '__table_schema_' . hash('sha256', implode('|', $identity));
	}
}
