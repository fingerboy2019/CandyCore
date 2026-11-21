<?php
/**
 * 字典全局函数
 *
 * 与 sa_dict / sa_dict_item 配合，供控制器、模型、模板读取字典项
 *
 * $Date: 2026-11-05 20:00:55 $
 */

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 按编码获取启用中的字典项（含 label_display）
 *
 * 请求内按 code 缓存，避免同页重复查库
 * Portal 且已绑定 Host 租户时，优先读当前租户 PostgreSQL（TenantDict），禁止回退平台库
 *
 * @param string $code 字典编码
 * @return array<int, array<string, mixed>>
 */
function dictOptions(string $code): array
{
	static $cache = [];
	$code = trim($code);
	if ($code === '') {
		return [];
	}

	$isPortal = defined('APPNAME') && APPNAME === 'Portal';
	$tenantId = '';
	try {
		$ctx = \APP\Extend\TenantContext::current();
		if (is_array($ctx) && !empty($ctx['tenant_id'])) {
			$tenantId = (string)$ctx['tenant_id'];
		}
	} catch (\Throwable) {
		$tenantId = '';
	}
	// Host 上下文偶发未注入时，回退请求作用域 TENANT_ID（inject 写入）。
	if ($tenantId === '') {
		$tenantId = trim((string)C('TENANT_ID', ''));
	}
	$cacheKey = $tenantId !== '' ? ('t:' . $tenantId . ':' . $code) : ($isPortal ? ('portal:' . $code) : ('p:' . $code));
	if (array_key_exists($cacheKey, $cache)) {
		return $cache[$cacheKey];
	}

	try {
		if ($tenantId !== '') {
			// 业务平台各应用共享租户上下文；只要存在租户号，就必须走租户字典服务。
			$result = S('Portal://TenantDict')->itemsByCode($tenantId, $code);
			$cache[$cacheKey] = is_array($result['data'] ?? null) ? $result['data'] : [];
		} elseif ($isPortal) {
			// Portal 无平台库 Dict 模型；无租户时返回空，禁止 M('Dict') 触发缺文件致命错误。
			$cache[$cacheKey] = [];
			return $cache[$cacheKey];
		} else {
			$result = M('Dict')->itemsByCode($code);
			$cache[$cacheKey] = is_array($result['data'] ?? null) ? $result['data'] : [];
		}
	} catch (\Throwable) {
		$cache[$cacheKey] = [];
	}

	return $cache[$cacheKey];
}

/**
 * 从字典项取库内中文 label（供 dictLabel 兜底）
 *
 * @param string $code 字典编码
 * @param string|int $value 字典值
 * @return string
 */
function dictValueLabel(string $code, string|int $value): string
{
	$valueStr = trim((string) $value);
	foreach (dictOptions($code) as $item) {
		if ((string) ($item['value'] ?? '') === $valueStr) {
			return (string) ($item['label'] ?? '');
		}
	}

	return '';
}

/**
 * 字典值 => 展示文案映射（已走 label_display 国际化）
 *
 * @param string $code 字典编码
 * @return array<string, string>
 */
function dictJsMap(string $code): array
{
	$map = [];
	foreach (dictOptions($code) as $item) {
		$val = (string) ($item['value'] ?? '');
		if ($val === '') {
			continue;
		}
		$map[$val] = (string) ($item['label_display'] ?? $item['label'] ?? $val);
	}

	return $map;
}

/**
 * 为列表行批量附加字典展示字段
 *
 * @param array<int, array<string, mixed>> $rows 数据行
 * @param string $field 存储字典值的字段名
 * @param string $code 字典编码
 * @param string|null $displayField 输出字段名，默认 {field}_text
 * @return array<int, array<string, mixed>>
 */
function dictRowsDisplay(array $rows, string $field, string $code, ?string $displayField = null): array
{
	$displayField = $displayField ?: ($field . '_text');
	$map = dictJsMap($code);

	foreach ($rows as $index => $row) {
		if (!is_array($row)) {
			continue;
		}
		$val = (string) ($row[$field] ?? '');
		$row[$displayField] = $map[$val] ?? dictLabel($code, $val, dictValueLabel($code, $val));
		$rows[$index] = $row;
	}

	return $rows;
}
