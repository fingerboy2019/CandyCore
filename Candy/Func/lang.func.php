<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 语言配置获取函数
 *
 * 用于获取框架中的语言配置信息，支持多维键、默认值、懒加载等增强功能。
 * 从系统配置的LANGINFO中检索语言数据，提供统一的语言项访问接口。
 *
 * 增强特性：
 * - 支持多维键访问，如 L('user.profile.name')
 * - 支持设置默认值，如 L('key', '默认值')
 * - 自动懒加载对应的语言文件
 * - 支持从 Lang 核心类缓存中获取（如果已初始化）
 *
 * @param string|null $key 语言键名，支持多维键，为null时返回所有语言配置
 * @param string $default 默认值，当键不存在时返回
 * @return mixed 返回语言配置值，键名不存在时返回默认值，无参数时返回完整语言配置数组
 *
 * @example
 * ```php
 * // 获取单个语言项
 * $welcomeText = L('welcome'); // 返回: '欢迎使用系统'
 *
 * // 获取带默认值的语言项
 * $title = L('pageTitle', '默认标题');
 *
 * // 获取多维语言项
 * $userName = L('user.profile.name');
 * $loginMsg = L('user.login.success');
 *
 * // 获取所有语言配置
 * $allLang = L(); // 返回完整的语言配置数组
 * ```
 */
function L($key = null, string $default = ''): mixed
{
	$LANG = C('LANGINFO');

	if(is_null($key)){
		// 返回所有语言配置
		return $LANG;
	}

	// 支持多维键访问（如 'user.profile.name'）
	if(strpos($key, '.') !== false){
		$keys = explode('.', $key);
		$value = $LANG;
		foreach($keys as $k){
			if(!isset($value[$k])){
				// 记录缺失的键（调试模式）
				if(isDebug()){
					\Candy\Core\Log::input("Missing language key: {$key}", -1, C('LOGPATH') . 'lang-missing-' . date('Y-m-d') . '.log');
				}
				return $default;
			}
			$value = $value[$k];
		}
		return is_string($value) ? $value : $default;
	}

	// 常规一维键访问
	if(isset($LANG[$key])){
		return $LANG[$key];
	}

	// 跨应用语言回退：当前应用 LANGINFO 不含该键时，按 Lang.inc.php fallback_map
	// 懒加载对应应用语言文件并合并后重查（R-B1/R-B2 配置驱动，替代硬编码 Home 白名单）
	$fallbackMap = (array)(loadConfig('Lang')['fallback_map'] ?? []);
	$fallbackPrefix = null;
	$fallbackSources = null;
	if ($fallbackMap !== []) {
		foreach ($fallbackMap as $prefix => $sources) {
			if (is_string($prefix) && str_starts_with((string)$key, $prefix)) {
				$fallbackPrefix = $prefix;
				$fallbackSources = (array)$sources;
				break;
			}
		}
	}
	if ($fallbackSources !== null) {
		// 按前缀记录已合并集合；不同前缀各自懒加载（不能用一个全局布尔，否则第二个前缀被跳过）
		$loadedPrefixes = is_array(C('LANGINFO_FALLBACK')) ? C('LANGINFO_FALLBACK') : [];
		if (!in_array($fallbackPrefix, $loadedPrefixes, true)) {
			$paths = [];
			foreach ($fallbackSources as $appName => $files) {
				foreach ((array)$files as $file) {
					$paths[] = $appName . '/' . $file;
				}
			}
			// 复用 importLang 按 (应用:文件) 列表懒加载并合并（R-B3 接入死代码）
			importLang($paths);
			$loadedPrefixes[] = $fallbackPrefix;
			C('LANGINFO_FALLBACK', $loadedPrefixes);
		}
		if (isset(C('LANGINFO')[$key])) {
			return C('LANGINFO')[$key];
		}
	}

	// 记录缺失的键（调试模式）
	if(isDebug()){
		\Candy\Core\Log::input("Missing language key: {$key}", -1, C('LOGPATH') . 'lang-missing-' . date('Y-m-d') . '.log');
	}

	return $default;
}

/**
 * 生成字典项对应的语言包键名
 *
 * 约定格式：DICT_{dict_code}_{value}，code/value 仅保留字母数字下划线
 *
 * @param string $code 字典编码（如 common_status）
 * @param string|int $value 字典项存储值
 * @return string
 */
function dictLangKey(string $code, string|int $value): string
{
	$code = strtolower(trim($code));
	$value = trim((string) $value);
	$code = preg_replace('/[^a-z0-9_]/', '_', $code);
	$value = preg_replace('/[^a-z0-9_]/i', '_', $value);

	return 'DICT_' . $code . '_' . $value;
}

/**
 * 解析字典项显示文案（模式 2：语言包优先，中文 label 兜底）
 *
 * zh-cn 通常不在语言包维护 DICT_*，直接回退为库内 label（种子/后台维护）
 * 其他语言读 L('DICT_{code}_{value}')，缺失时同样回退中文 label
 *
 * @param string $code 字典编码
 * @param string|int $value 字典项存储值
 * @param string $fallbackLabel 库内中文 label，作兜底
 * @return string
 */
function dictLabel(string $code, string|int $value, string $fallbackLabel = ''): string
{
	$valueStr = trim((string) $value);
	$fallback = $fallbackLabel;
	if ($fallback === '' && $code !== '' && $valueStr !== '') {
		$fallback = dictValueLabel($code, $valueStr);
	}
	if ($fallback === '') {
		$fallback = $valueStr;
	}
	if ($code === '' || $valueStr === '') {
		return $fallback;
	}

	return (string) L(dictLangKey($code, $value), $fallback);
}

/**
 * 为单条字典项附加 label_display 字段
 *
 * 保留原 label 供后台编辑，label_display 供列表/下拉展示
 *
 * @param string $code 字典编码
 * @param array $item 字典项行（含 label、value）
 * @return array
 */
function dictResolveItem(string $code, array $item): array
{
	$item['label_display'] = dictLabel(
		$code,
		(string) ($item['value'] ?? ''),
		(string) ($item['label'] ?? '')
	);

	return $item;
}

/**
 * 批量解析字典项显示文案
 *
 * @param string $code 字典编码
 * @param array<int, array> $items 字典项列表
 * @return array<int, array>
 */
function dictResolveItems(string $code, array $items): array
{
	foreach ($items as $index => $item) {
		if (is_array($item)) {
			$items[$index] = dictResolveItem($code, $item);
		}
	}

	return $items;
}

/**
 * 语言库文件引入函数
 * 
 * 用于引入指定应用和操作的语言库文件，支持批量引入多个语言文件。
 * 函数会根据应用名称和操作名自动定位对应的语言文件路径，并在文件存在时进行引入。
 * 
 * @param array $path 语言文件路径数组，格式为["应用名称/操作名"]，例如：[
 *                     "Home/Common",
 *                     "Admin/User"
 *                   ]
 * @return void
 * 
 * @example
 * ```php
 * // 引入单个语言文件
 * importLang(['Home/Common']);
 * 
 * // 引入多个语言文件
 * importLang([
 *     'Admin/Login',
 *     'Admin/Common',
 *     'Home/Message'
 * ]);
 * ```
 * 
 * @note
 * - 语言文件路径格式为：CANDYROOT . 'Application/' . $appName . '/Languages/' . G('LANG') . '/' . $action . '.lang.php'
 * - 函数会自动检查文件是否存在，不存在的文件会被跳过
 * - 引入完成后，会更新LANGINFO配置
 */
function importLang(array $path = []): void
{
	// 基于当前 LANGINFO 合并（勿覆盖既有应用语言），语言文件都不存在时保持原样
	$LANG = is_array(C('LANGINFO')) ? C('LANGINFO') : [];

	if(!empty($path)){
		foreach($path as $lang){
			[$appName, $action] = explode('/', $lang, 2);
			$file = C('CANDYROOT') .'Application/'. $appName .'/Languages/'. G('LANG') .'/'. $action .'.lang.php';
			if(fileExistsCase($file))
				$LANG += include $file; // 语言文件 return 数组，须 += 合并
		}
		C('LANGINFO', $LANG);
	}
}
