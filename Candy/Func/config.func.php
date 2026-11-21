<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 获取和设置配置参数，支持批量定义和多维数组操作
 *
 * 该函数是框架的核心配置管理函数，用于获取、设置或批量设置配置参数。
 * 支持简单配置获取、设置，以及多维数组配置的获取和设置。
 *
 * 使用示例：
 * - 获取配置: C('DB_HOST') - 获取DB_HOST配置值
 * - 设置配置: C('DB_HOST', 'localhost') - 设置DB_HOST配置值
 * - 多维数组: C('DB.HOST') - 获取DB数组中的HOST值
 * - 批量设置: C(['DB_HOST' => 'localhost', 'DB_PORT' => 3306]) - 批量设置配置
 *
 * Swoole 协程下自动切换为 Coroutine::getContext 存储，避免协程间数据串扰。
 *
 * @param string|array|null $name 配置变量名、多维配置路径或配置数组
 * @param mixed $value 配置值，当$name为字符串时有效
 * @param mixed $default 默认值，当配置不存在时返回的默认值
 * @return mixed 当获取配置时返回配置值，设置配置时返回null
 * @example
 * ```php
 * C('DB_HOST');                    // 读取
 * C('DB_HOST', 'localhost');        // 设置
 * C('DB.HOST');                     // 多维读取
 * C(['DB_HOST' => 'localhost']);    // 批量设置
 * ```
 */
function C(mixed $name = null,mixed $value='',mixed $default=null): mixed
{
	// 协程下用 Coroutine::getContext 隔离，避免协程间串数据
	if (isSwooleCoroutine()) {
		$ctx = \Candy\Core\CoroutineContext::getContext();
		if (!isset($ctx['_c_config'])) {
			$ctx['_c_config'] = [];
		}
		$_config = &$ctx['_c_config'];
	} else {
		static $_config = [];
	}

	//重置静态变量
	if (is_null($name) && is_null($value)) {
		$_config = [];
		return $_config;
	}
	
	// 无参数时获取所有
	if (is_null($name) && $value === '') return $_config;
	
	// 优先执行设置获取或赋值
	if(is_string($name)){
		$keys = explode('.', $name);
		$key = array_shift($keys);
		if($value === ''){
			// 配置键别名（读取）：COMPILE_DIR ↔ COMPOLE_DIR
			if (count($keys) === 0) {
				if ($key === 'COMPILE_DIR' && isset($_config['COMPOLE_DIR'])) {
					return $_config['COMPOLE_DIR'];
				}
				if ($key === 'COMPOLE_DIR' && isset($_config['COMPILE_DIR'])) {
					return $_config['COMPILE_DIR'];
				}
			}
			//取值
			//【2026-11-12 修复】原写法是 $value = $_config[$key] ??= (...)，??= 会在键不存在时
			//静默植入空数组，于是"读一个不存在的多级路径"变成了"写入污染"：
			//   C('kk.x.y') 返回 NULL（正常），但 C('kk') 从 null 变成 []，
			//   导致 is_null() 判断失效、isset() 反而为真。
			//   而且那个 [] 赋值毫无用处——后续下钻必然失败、照样返回 default。
			// 读操作不应修改配置，这里改为纯读。
			if (!array_key_exists($key, $_config)) {
				return $default;
			}
			$value = $_config[$key];
			while(count($keys) > 0){
				$key = array_shift($keys);
				if(isset($value[$key])){
					$value = $value[$key];
				}else{
					return $default;
				}
			}
			return $value;
		}else{
			//赋值
			if(count($keys) > 0){
				//多维数组
				$array = $_config[$key] ??= [];
				$list = [['key'=>$key,'value'=>$array]];
				while(count($keys) > 0){
					$subkey = array_shift($keys);
					$last = count($list) - 1;
					if(count($keys) > 0){
						//不是最后一层
						if(isset($list[$last]['value'][$subkey])){
							$list[] = ['key'=>$subkey,'value'=>$list[$last]['value'][$subkey]];
						}else{
							$list[] = ['key'=>$subkey,'value'=>[]];
						}
					} else {
						//最后一层
						$list[$last]['value'][$subkey] = $value;
					}
				}
				//合并结果
				while(count($list) > 0){
					$item = array_pop($list);
					$key = $item['key'];
					$value = $item['value'];
					if(count($list) > 0){
						$list[count($list) - 1]['value'][$key] = $value;
					}else{
						$_config[$key] = $value;
					}
				}
			} else {
				//一维数组
				$_config[$key] = $value;
				if ($key === 'COMPILE_DIR' || $key === 'COMPOLE_DIR') {
					$_config['COMPILE_DIR'] = $value;
					$_config['COMPOLE_DIR'] = $value;
				}
			}
			return true;
		}
	}
	
	// 批量设置
	if (is_array($name)){
		//$arr = array_change_key_case($name, CASE_UPPER);
		$arr = $name;
		empty($_config) && $_config = [];
		$_config = empty($value) ? array_merge($_config, $arr) : array_merge($arr, $_config);
		return null;
	}
	
	return null; // 避免非法参数
}

/**
 * 获取和设置请求级数据
 *
 * WebServer 为每个请求注入 REDIRECT_REQUEST_UUID，T() 按 UUID 保存独立数据桶；
 * PHP-FPM 和普通 CLI 没有 UUID 时降级使用 C()，依靠 PHP 请求生命周期自然隔离。
 * 长驻服务必须在请求或消息结束时调用 T(null, null) 删除当前 UUID 数据桶。
 * Swoole 协程下自动切换为 Coroutine::getContext 存储。
 *
 * @param string|array|null $name 数据键、多维键路径、批量数据或 null
 * @param mixed $value 设置值；空字符串表示读取，name/value 同为 null 表示清空当前请求桶
 * @param mixed $default 读取不存在键时的默认值
 * @param string $uuid 显式请求 UUID；为空时读取 REDIRECT_REQUEST_UUID
 * @return mixed
 */
function T(mixed $name = null, mixed $value = '', mixed $default = null, string $uuid = ''): mixed
{
	// 协程下用 Coroutine::getContext 隔离
	if (isSwooleCoroutine()) {
		$ctx = \Candy\Core\CoroutineContext::getContext();
		if (!isset($ctx['_t_contexts'])) {
			$ctx['_t_contexts'] = [];
		}
		$contexts = &$ctx['_t_contexts'];
	} else {
		static $contexts = [];
	}

	$requestUuid = trim($uuid);
	if ($requestUuid === '') {
		$requestUuid = isSwooleCoroutine()
			? trim((string)R()->server('REDIRECT_REQUEST_UUID', ''))
			: trim((string)I('server.REDIRECT_REQUEST_UUID', '', ''));
	}

	// 无 UUID 时回退 C()；协程下 C() 已走 Coroutine::getContext
	if ($requestUuid === '') {
		return C($name, $value, $default);
	}

	// 长驻进程在 finally 中调用，只删除当前请求桶，不影响 C() 和其他 UUID。
	if ($name === null && $value === null) {
		unset($contexts[$requestUuid]);
		return [];
	}

	$config = $contexts[$requestUuid] ?? [];
	if ($name === null && $value === '') {
		return $config;
	}

	if (is_string($name)) {
		$keys = explode('.', $name);
		$key = array_shift($keys);
		if ($key === null || $key === '') {
			return $default;
		}

		if ($value === '') {
			$current = array_key_exists($key, $config)
				? $config[$key]
				: (count($keys) > 0 ? [] : $default);
			foreach ($keys as $subKey) {
				if (!is_array($current) || !array_key_exists($subKey, $current)) {
					return $default;
				}
				$current = $current[$subKey];
			}
			return $current;
		}

		if ($keys === []) {
			$config[$key] = $value;
			$contexts[$requestUuid] = $config;
			return true;
		}

		$root = isset($config[$key]) && is_array($config[$key]) ? $config[$key] : [];
		$cursor =& $root;
		foreach ($keys as $index => $subKey) {
			if ($index === array_key_last($keys)) {
				$cursor[$subKey] = $value;
				break;
			}
			if (!isset($cursor[$subKey]) || !is_array($cursor[$subKey])) {
				$cursor[$subKey] = [];
			}
			$cursor =& $cursor[$subKey];
		}
		unset($cursor);
		$config[$key] = $root;
		$contexts[$requestUuid] = $config;
		return true;
	}

	if (is_array($name)) {
		$contexts[$requestUuid] = empty($value)
			? array_merge($config, $name)
			: array_merge($name, $config);
		return true;
	}

	return null;
}

/**
 * 获取和设置配置参数 支持批量定义 S函数扩展  workerman模式进程间共享变量
 *
 * 该函数是C函数的扩展版本，主要增加了进程间共享变量的支持，特别适用于workerman等
 * 多进程环境下的配置共享。在无缓存类支持时会降级使用C函数的缓存机制。
 *
 * 使用示例：
 * - 获取配置: G('DRIVER') - 获取DRIVER配置值
 * - 设置配置: G('DRIVER', 'mysql') - 设置DRIVER配置值
 * - 多维数组: G('DB.HOST') - 获取DB数组中的HOST值
 * - 批量设置: G(['DRIVER' => 'mysql', 'CHARSET' => 'utf8']) - 批量设置配置
 * - 默认值: G('UNKNOWN_CONFIG', '', '默认值') - 获取不存在配置时返回默认值
 *
 * - 重置本地缓存: G(null, null) - 清空进程内缓存，下次访问重新加载
 *
 * @param mixed $name 配置变量名、二维配置路径或配置数组，为null时返回所有配置
 * @param mixed $value 配置值，当$name为字符串时有效；G(null,null) 时重置进程内缓存
 * @param mixed $default 默认值，当配置不存在时返回的默认值
 * Swoole 协程下跳过本地缓存，每次操作直连 Redis，避免协程间数据串扰。
 *
 * @return mixed 获取配置时返回配置值，设置配置时返回null
 */
function G(mixed $name = null,mixed $value = '',mixed $default = null)
{
	// 协程下跳过本地缓存，每次直接读写 Redis，避免协程间串数据
	if (isSwooleCoroutine()) {
		return gCoroutine($name, $value, $default);
	}

	static $cache = null;
	static $local = null;
	static $localRev = 0;
	static $lastSyncAt = 0;

	if ($cache === null) {
		$cache = \Candy\Core\Cache::store();
	}

	// 重置进程内缓存
	if (is_null($name) && is_null($value)) {
		$local = null;
		$localRev = 0;
		$lastSyncAt = 0;
		return [];
	}

	if ($local === null) {
		$payload = $cache->get(G_CACHE_MAIN_KEY, null);
		[$local, $localRev] = parseSharedGlobalPayload($payload);
		$lastSyncAt = time();
	}

	// 返回所有数据
	if (is_null($name)) {
		syncSharedGlobalCache($cache, $local, $localRev, $lastSyncAt);
		return $local;
	}

	// 单键读写
	if (is_string($name)) {
		if ($value === '') {
			syncSharedGlobalCache($cache, $local, $localRev, $lastSyncAt);
			return readSharedGlobalValue($cache, $local, $localRev, $name, $default);
		}

		// 点号赋值展开成多维数组 —— 与 C() 对齐（2026-11-12 修复）
		$pathKeys = strpos($name, '.') !== false ? explode('.', $name) : [$name];
		if (count($pathKeys) === 1) {
			// 一维：值未变化时不重新压缩和写回全局共享载荷，避免每个请求重复写入大缓存。
			if (array_key_exists($name, $local) && $local[$name] === $value) {
				return true;
			}
			$local[$name] = $value;
		} else {
			$rootKey = array_shift($pathKeys);
			if (!isset($local[$rootKey]) || !is_array($local[$rootKey])) {
				$local[$rootKey] = [];
			}
			$ref = &$local[$rootKey];
			while (count($pathKeys) > 0) {
				$k = array_shift($pathKeys);
				if (count($pathKeys) > 0) {
					if (!isset($ref[$k]) || !is_array($ref[$k])) {
						$ref[$k] = [];
					}
					$ref = &$ref[$k];
				} else {
					$ref[$k] = $value;
				}
			}
			unset($ref);
		}

		$localRev = (int)microtime(true);
		$persisted = persistSharedGlobalCache($cache, $local, $localRev);
		$lastSyncAt = time();
		return $persisted;
	}

	// 批量设置
	if (is_array($name)) {
		// 启动路径经常重复提交同一组全局配置，只在确有变化时持久化。
		$changed = false;
		foreach ($name as $key => $val) {
			if (array_key_exists($key, $local) && $local[$key] === $val) {
				continue;
			}
			$local[$key] = $val;
			$changed = true;
		}
		if (!$changed) {
			return true;
		}
		$localRev = (int)microtime(true);
		$persisted = persistSharedGlobalCache($cache, $local, $localRev);
		$lastSyncAt = time();
		return $persisted;
	}

	return true;
}

/**
 * G() 协程模式：跳过本地缓存，每次操作直连 Redis
 */
function gCoroutine(mixed $name = null, mixed $value = '', mixed $default = null): mixed
{
	$cache = \Candy\Core\Cache::store();

	if (is_null($name) && is_null($value)) {
		return [];
	}

	// 读取全部
	if (is_null($name) && $value === '') {
		$payload = $cache->get(G_CACHE_MAIN_KEY, null);
		[$local] = parseSharedGlobalPayload($payload);
		return $local;
	}

	// 单键读写
	if (is_string($name)) {
		if ($value === '') {
			// 读
			$payload = $cache->get(G_CACHE_MAIN_KEY, null);
			[$local] = parseSharedGlobalPayload($payload);
			$dummyRev = 0;
			return readSharedGlobalValue($cache, $local, $dummyRev, $name, $default);
		}

		// 写
		$payload = $cache->get(G_CACHE_MAIN_KEY, null);
		[$local] = parseSharedGlobalPayload($payload);
		// 点号赋值展开成多维数组 —— 与 C() 对齐（2026-11-12 修复）
		$pathKeys = strpos($name, '.') !== false ? explode('.', $name) : [$name];
		if (count($pathKeys) === 1) {
			// 协程路径同样避免无变化时重复压缩和写回共享载荷。
			if (array_key_exists($name, $local) && $local[$name] === $value) {
				return true;
			}
			$local[$name] = $value;
		} else {
			$rootKey = array_shift($pathKeys);
			if (!isset($local[$rootKey]) || !is_array($local[$rootKey])) {
				$local[$rootKey] = [];
			}
			$ref = &$local[$rootKey];
			while (count($pathKeys) > 0) {
				$k = array_shift($pathKeys);
				if (count($pathKeys) > 0) {
					if (!isset($ref[$k]) || !is_array($ref[$k])) {
						$ref[$k] = [];
					}
					$ref = &$ref[$k];
				} else {
					$ref[$k] = $value;
				}
			}
			unset($ref);
		}
		$newRev = (int)microtime(true);
		return persistSharedGlobalCache($cache, $local, $newRev);
	}

	// 批量写
	if (is_array($name)) {
		$payload = $cache->get(G_CACHE_MAIN_KEY, null);
		[$local] = parseSharedGlobalPayload($payload);
		// 协程批量配置只在值发生变化时写回共享载荷。
		$changed = false;
		foreach ($name as $key => $val) {
			if (array_key_exists($key, $local) && $local[$key] === $val) {
				continue;
			}
			$local[$key] = $val;
			$changed = true;
		}
		if (!$changed) {
			return true;
		}
		$newRev = (int)microtime(true);
		return persistSharedGlobalCache($cache, $local, $newRev);
	}

	return true;
}

/**
 * 配置加载函数
 * 
 * 用于从环境变量或配置文件中加载指定名称的配置数据。
 * 支持配置缓存机制，优先从.env文件获取配置，若不存在则尝试从.inc.php文件加载。
 * 
 * @param string $name 配置名称，不包含扩展名
 * @param bool $return 是否返回配置数据，为false时直接包含配置文件
 * @return mixed|array 当$return为true时返回配置数组，当$name为空时返回空数组，当$return为false时无返回值
 * 
 * @example
 * ```php
 * // 加载数据库配置并返回
 * $dbConfig = loadConfig('database');
 * // 返回: ['host' => 'localhost', 'user' => 'root', 'password' => '123456']
 * 
 * // 直接包含配置文件（不返回）
 * loadConfig('system', false);
 * 
 * // 加载多个配置
 * $db = loadConfig('database');
 * $cache = loadConfig('cache');
 * ```
 * 
 * @note
 * - 函数内部使用静态变量缓存已加载的配置，提高性能
 * - 环境变量配置优先于.inc.php文件
 * - 配置文件路径格式为：INCPATH . $name . '.inc.php'
 */
/**
 * 取某个 inc 配置的两个候选路径
 *
 * @param string $name 配置名
 * @return array{project:string,framework:string}
 */
function incPaths(string $name): array
{
    $name = trim($name);
    $out = ['project' => '', 'framework' => ''];
    if ($name === '') {
        return $out;
    }

    $inc = function_exists('C') ? (string) C('INCPATH') : (defined('INCPATH') ? (string) INCPATH : '');
    if ($inc !== '') {
        $p = rtrim($inc, "/\\") . DIRECTORY_SEPARATOR . $name . '.inc.php';
        if (is_file($p)) {
            $out['project'] = $p;
        }
    }
    // <Candy>/Class/Tools/Inc/（本文件在 <Candy>/Func/ 下）
    $f = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Class' . DIRECTORY_SEPARATOR
        . 'Tools' . DIRECTORY_SEPARATOR . 'Inc' . DIRECTORY_SEPARATOR . $name . '.inc.php';

    // 标准件：随框架发布，一定存在 —— 不做 stat（省一次 file_exists）
    // 缺失即框架本身不完整（服务也起不来），属显式故障，不该被静默当成"没有配置"
    if (in_array($name, incStandardNames(), true)) {
        return ['project' => $out['project'], 'framework' => $f];
    }
    if (is_file($f)) {
        $out['framework'] = $f;
    }
    return $out;
}

/**
 * 框架自带的标准配置名（随框架发布、必然存在）
 *
 * 加新配置到 Candy/Class/Tools/Inc/ 时，记得把名字登记到这里，
 * 否则该配置仍会走 is_file 判断（功能不受影响，只多一次 stat）。
 *
 * @return array<int, string>
 */
function incStandardNames(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Class' . DIRECTORY_SEPARATOR
        . 'Tools' . DIRECTORY_SEPARATOR . 'Inc' . DIRECTORY_SEPARATOR;
    $names = [];
    foreach ((array) @glob($dir . '*.inc.php') as $file) {
        $names[] = basename($file, '.inc.php');
    }
    return $names;
}

/**
 * 深合并「框架默认 ← 项目覆盖」
 *
 * 规则：
 *   关联数组 → 递归合并，覆盖方优先
 *   列表数组 → 覆盖方整体替换（避免按索引串位）
 *
 * @param array<mixed> $base     框架默认
 * @param array<mixed> $override 项目覆盖
 * @return array<mixed>
 */
function incMergeDefaults(array $base, array $override, string $path = ''): array
{
    $isList = static function (array $a): bool {
        return array_keys($a) === range(0, count($a) - 1);
    };

    foreach ($override as $key => $value) {
        // ★ 以「框架默认」为范围：默认里没有的键一律丢弃，防止拼错键名 / 注入非法参数
        if (!array_key_exists($key, $base)) {
            $GLOBALS['__incDroppedKeys'][$path . (string) $key] = $value;
            continue;
        }
        $old = $base[$key];
        if (is_array($old) && is_array($value)) {
            // 任一侧是列表 → 覆盖方整体替换（列表不做逐项合并，避免按索引串位）
            if ($isList($old) || $isList($value)) {
                $base[$key] = $value;
                continue;
            }
            $base[$key] = incMergeDefaults($old, $value, $path . $key . '.');
            continue;
        }
        $base[$key] = $value;
    }
    return $base;
}

/**
 * 最近一次合并被丢弃的键（超出框架默认范围的）
 *
 * @param bool $reset 取完是否清空
 * @return array<string, mixed>
 */
function incDroppedKeys(bool $reset = false): array
{
    $out = isset($GLOBALS['__incDroppedKeys']) && is_array($GLOBALS['__incDroppedKeys'])
        ? $GLOBALS['__incDroppedKeys'] : [];
    if ($reset) {
        $GLOBALS['__incDroppedKeys'] = [];
    }
    return $out;
}

/**
 * 解析某个 inc 配置「实际生效」的文件路径
 *
 * 顺序：项目 Source/Inc/<name>.inc.php 优先 → 框架 Candy/Class/Tools/Inc/<name>.inc.php
 * 框架目录承载「装上就能用」的默认配置，项目侧同名文件用于覆盖。
 *
 * @param string $name 配置名（不带 .inc.php）
 * @return string 绝对路径；'' 表示两处都没有
 */
function incResolve(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $dirs = [];
    $inc = function_exists('C') ? (string) C('INCPATH') : (defined('INCPATH') ? (string) INCPATH : '');
    if ($inc !== '') {
        $dirs[] = rtrim($inc, "/\\") . DIRECTORY_SEPARATOR;
    }
    // 框架默认目录：<Candy>/Class/Tools/Inc/（本文件在 <Candy>/Func/ 下）
    $dirs[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Class' . DIRECTORY_SEPARATOR
        . 'Tools' . DIRECTORY_SEPARATOR . 'Inc' . DIRECTORY_SEPARATOR;

    foreach ($dirs as $dir) {
        $path = $dir . $name . '.inc.php';
        if (is_file($path)) {
            return $path;
        }
    }
    return '';
}

function loadConfig(string $name = '',bool $return = true)
{
	static $_loadConfig = [];
	if(empty($name)) return [];
	
	//有缓存
	if($return && isset($_loadConfig[$name])) return $_loadConfig[$name];
	
	//优先读取.env文件
	//$envConfig = G('envConfig');
	$envConfig = null;
	$config = '';
	if(is_null($envConfig) === false || is_file('./env')){
		$keyType = is_null($envConfig) ? false : ($envConfig['keyType'] ?: false);
		$envName = $keyType === true ? strtoupper($name) : strtolower($name);
		//修正名称后的缓存
		if($return && isset($_loadConfig[$envName])) return $_loadConfig[$envName];
		$config = envGet($envName);
		empty($config) OR $_loadConfig[$envName] = $config;
	}
	
	//空配置尝试读取inc
	if(empty($config)){
		// 框架默认（Candy/Class/Tools/Inc）+ 项目覆盖（Source/Inc）深合并
		$paths = incPaths($name);
		$base = ($paths['framework'] !== '') ? include($paths['framework']) : [];
		$over = ($paths['project'] !== '') ? include($paths['project']) : [];
		$base = is_array($base) ? $base : [];
		$over = is_array($over) ? $over : [];
		if ($base !== [] && $over !== []) {
			$GLOBALS['__incDroppedKeys'] = [];
			$config   = incMergeDefaults($base, $over);
			$configPath = $paths['project'];      // 生效内容来自两处，路径记项目侧
		} else {
			$config     = $over !== [] ? $over : $base;
			$configPath = $over !== [] ? $paths['project'] : $paths['framework'];
		}
		$_loadConfig[$name] = $config;
	}
	
	if($return){
		return $config;
	}elseif(!empty($configPath)){
		includeCache($configPath);
	}
}

/**
 * 生成 inc 配置缓存键。
 *
 * Cache 的点号是嵌套键语法，不能用于配置缓存的字面键；使用带版本的冒号命名空间
 * 使配置缓存始终作为独立键读写，同时自然隔离旧版错误的 `configs` 主键。
 *
 * @param string $configPath 配置文件绝对路径
 * @return string 配置缓存键
 */
function incConfigCacheKey(string $configPath): string
{
	return 'configs:v2:' . md5($configPath);
}

/**
 * inc 配置信息读取函数
 * 
 * 该函数用于读取框架的.inc.php配置文件，支持配置缓存机制，提高配置读取效率。
 * 函数会优先从缓存中获取配置信息，如果缓存不存在，则读取磁盘上的配置文件。
 * 
 * @param string $name 配置文件名，不包含扩展名
 * @return array 配置数据数组，文件不存在或读取失败时返回空数组
 * 
 * @example
 * ```php
 * // 读取数据库配置
 * $dbConfig = readInc('database');
 * 
 * // 读取系统配置
 * $systemConfig = readInc('system');
 * 
 * // 读取用户配置
 * $userConfig = readInc('user');
 * ```
 * 
 * @note
 * - 配置文件路径格式：C('INCPATH') . $name . '.inc.php'
 * - 函数会使用缓存机制避免重复读取文件，提高性能
 * - 当缓存不可用时，直接读取磁盘文件
 */
function readInc(string $name = ''): array
{
	if(empty($name)){
		return [];
	}

	$configPath = C('INCPATH') . $name . '.inc.php';
	if(!is_file($configPath)){
		return [];
	}

	// ── 分流①：opcache 开启 → 直接 include ──────────────────────────
	// 字节码由 opcache 缓存，其 validate_timestamps / revalidate_freq 负责自动失效。
	// 实测（1KB 配置）：include 0.1~0.6μs，比 Redis GET(30~100μs) 快 50~500 倍。
	if(readIncUseOpcache()){
		$config = include $configPath;
		return is_array($config) ? $config : [];
	}

	// ── 分流②：opcache 未开启 → Redis + mtime 比对 ──────────────────
	// 没有 opcache 时 include 每次都要重新解析 PHP（100~500μs），用 Redis 才划算；
	// 但缓存里必须带文件 mtime，读取时比对，新旧不一致就重载
	// —— 这样既减少 IO，又不会像旧实现那样「改了永远不生效」。
	$cache = \Candy\Core\Cache::store();
	if(!$cache){
		$config = include $configPath;
		return is_array($config) ? $config : [];
	}

	$cacheKey = 'configs:v3:' . md5($configPath);
	$mtime    = (int) (@filemtime($configPath) ?: 0);
	$cached   = $cache->get($cacheKey, null);

	if(is_array($cached) && isset($cached['mtime'], $cached['config'])
		&& (int) $cached['mtime'] === $mtime){
		return (array) $cached['config'];
	}

	$config = include $configPath;
	$config = is_array($config) ? $config : [];
	$cache->set($cacheKey, ['mtime' => $mtime, 'config' => $config]);

	return $config;
}

/**
 * 当前进程是否真正启用了 opcache（用于 readInc 选择读取策略）
 *
 * 用 opcache_get_status(false) 判定，而不是只看 ini —— 因为 opcache.enable=On
 * 不代表每个 SAPI 都生效（例如 CLI 还受 opcache.enable_cli 控制）。
 * 结果在同一次请求内用静态变量记住，避免每读一次配置就探测一次。
 *
 * @return bool
 */
function readIncUseOpcache(): bool
{
	static $enabled = null;
	if($enabled !== null){
		return $enabled;
	}

	$enabled = false;
	if(function_exists('opcache_get_status')){
		$status  = @opcache_get_status(false);
		$enabled = is_array($status) && !empty($status['opcache_enabled']);
	}

	return $enabled;
}

/**
 * 修改inc配置文件
 *
 * 该函数用于修改框架的.inc.php配置文件，可以合并更新或完全替换配置信息。
 * 支持配置文件内容的读取、更新和缓存机制，确保配置的一致性和高效性。
 *
 * 使用示例：
 * ```php
 * // 更新配置项
 * editInc('user', ['active_timeout' => 3600, 'max_login_attempts' => 5]);
 * 
 * // 完全替换配置
 * editInc('system', ['debug' => true, 'cache' => false], false);
 * ```
 *
 * @param string $name 配置文件名，不包含扩展名
 * @param array $data 要更新的配置数据数组
 * @param bool $update 是否合并更新（true）或完全替换（false），默认为true
 * @return bool 配置更新成功返回true，失败返回false
 */
function editInc(string $name = '',array $data = [],bool $update = true): bool
{
	if(empty($name) || !is_array($data)) return false;

	//配置路径
	$configPath = C('INCPATH') . $name . '.inc.php';
	$config = is_file($configPath) ? include($configPath) : [];
	
	if(empty($data) && $update) return false;

	$config = $update ? array_merge($config, $data) : $data;
	
	//修改配置
	$configContent = '<?php'. PHP_EOL .'defined(\'CANDY\') OR die(\'You Are A Bad Guy. o_O???\');'. PHP_EOL .'return ' . incExport($config) . ';' . PHP_EOL;
	file_put_contents($configPath, $configContent);
	
	//存进缓存（必须存解析后的数组；勿存 PHP 源码字符串，否则 readInc 返回类型错误）
	$cache = \Candy\Core\Cache::store();
	if($cache && \Candy\Core\Impl\Pack\Binary::canPack($config)){
		$cache->set(incConfigCacheKey($configPath), $config);
	}
	
	return true;
}

/**
 * 将变量数组分配到视图变量中
 *
 * 该函数用于将一组变量分配到视图变量数组中，
 * 支持变量提取并特殊处理函数名形式的变量。
 * 主要用于视图渲染前的数据准备阶段。
 *
 * 使用示例：
 * - 分配普通变量: assign(['var' => 'title', 'value' => '首页'], $viewVars)
 * - 调用数据函数: assign(['var' => 'articles', 'value' => 'cmsData', 'source' => 'article'], $viewVars)
 *
 * @param array $params 模板 assign 参数，必须包含 var 和 value
 * @param array $viewVars 视图变量数组的引用，分配的变量将存储在这里
 * @return void 无返回值
 * @note 如果变量值恰好是一个函数名，会调用该函数并将返回值赋给视图变量
 */
function assign(array $params, &$viewVars):void
{
	$var = trim((string)($params['var'] ?? $params['name'] ?? ''));
	if($var === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $var) !== 1){
		return;
	}
	$value = $params['value'] ?? null;
	unset($params['var'], $params['name'], $params['value']);
	if(is_string($value) && function_exists($value)){
		$viewVars[$var] = $value($params);
		return;
	}
	$viewVars[$var] = $value;
}

/**
 * ============================================================
 * 框架配置表单：loadInc（以框架默认值作 schema）+ saveInc（差异落盘）
 * ============================================================
 * 设计（用户 2026-11-17 定稿）：
 *   ① loadInc 读「框架默认配置」当 schema —— 默认值的键树就是提交范围，
 *      键名/类型/嵌套层级全部由它决定，表单不可能提交出框架不认识的键；
 *   ② 提交只保存「与默认不同」的项到 Source/Inc/<Name>.inc.php，交给深合并生效；
 *   ③ 索引数组（list）不在表单编辑 —— 递归展开成可增删行的表格等于写表单引擎，
 *      改为只读展示 + 跳转源码编辑器；
 *   ④ 布尔三态：跟随默认 / 开 / 关。否则「默认 true 的开关」关不掉
 *      （false 若被当空值跳过，读回仍是 true）。
 *
 * @package Candy\Func
 */

/**
 * 「跟随默认」在表单提交里的标记值
 */
if (!defined('INC_FORM_INHERIT')) {
    define('INC_FORM_INHERIT', '__inherit__');
}

/**
 * 读取一个配置为表单结构
 *
 * @param string $name 配置名（不带 .inc.php）
 * @return array{name:string, nodes:array<int, array<string, mixed>>, empty:bool, has_project:bool}
 */
function loadInc(string $name): array
{
    $name  = trim($name);
    $paths = incPaths($name);
    $fw    = (string) ($paths['framework'] ?? '');
    $pj    = (string) ($paths['project'] ?? '');

    if ($fw === '' || !is_file($fw)) {
        return ['name' => $name, 'nodes' => [], 'empty' => true, 'has_project' => false];
    }

    $base = (array) include $fw;
    $over = ($pj !== '' && is_file($pj)) ? (array) include $pj : [];
    $schema = isset($base['_schema']) && is_array($base['_schema']) ? $base['_schema'] : [];
    unset($base['_schema']);

    // 生效值：与页面「最终生效」一致（深合并，默认值为范围）
    $effective = incMergeDefaults($base, $over);

    $nodes = [];
    incFormNodes($base, $over, $effective, $schema, '', $nodes);

    return [
        'name'        => $name,
        'nodes'       => $nodes,
        'empty'       => $base === [],
        'has_project' => $over !== [],
    ];
}

/**
 * 把一层配置展开成表单节点（递归）
 *
 * @param array<string, mixed> $base    框架默认（本层）
 * @param array<string, mixed> $over    项目覆盖（本层）
 * @param array<string, mixed> $eff     生效值（本层）
 * @param array<string, mixed> $schema  控件声明（_schema，按点路径索引）
 * @param string               $prefix  父级点路径
 * @param array<int, array<string, mixed>> $nodes 输出
 * @return void
 */
function incFormNodes(array $base, array $over, array $eff, array $schema, string $prefix, array &$nodes): void
{
    foreach ($base as $key => $def) {
        if ($key === '_schema') {
            continue;
        }
        $path   = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        $hasOver = array_key_exists($key, $over);
        $meta   = isset($schema[$path]) && is_array($schema[$path]) ? $schema[$path] : [];
        $val    = array_key_exists($key, $eff) ? $eff[$key] : null;

        // 数组：列表 → 只读；关联 → 递归
        if (is_array($def)) {
            if (incIsListArray($def)) {
                $first = $def[0] ?? [];
                $nodes[] = [
                    'path'     => $path,
                    'kind'     => 'list',
                    'count'    => is_array($val) ? count($val) : 0,
                    'keys'     => is_array($first) ? array_keys($first) : [],
                    'editable' => false,
                    'source'   => $hasOver ? 'override' : 'default',
                ];
                continue;
            }
            $nodes[] = [
                'path'   => $path,
                'kind'   => 'map',
                'depth'  => substr_count($path, '.'),
                'source' => $hasOver ? 'override' : 'default',
            ];
            incFormNodes(
                $def,
                isset($over[$key]) && is_array($over[$key]) ? $over[$key] : [],
                is_array($val) ? $val : [],
                $schema,
                $path,
                $nodes
            );
            continue;
        }

        // 标量：按类型给控件
        $kind = 'string';
        if (is_bool($def)) {
            $kind = 'bool';
        } elseif (is_int($def) || is_float($def)) {
            $kind = 'number';
        }
        if (($meta['control'] ?? '') === 'select') {
            $kind = 'select';
        }

        $nodes[] = [
            'path'    => $path,
            'kind'    => $kind,
            'value'   => $val,
            'default' => $def,
            'source'  => $hasOver ? 'override' : 'default',
            'depth'   => substr_count($path, '.'),
            'label'   => (string) ($meta['label'] ?? ''),
            'unit'    => (string) ($meta['unit'] ?? ''),
            'options' => isset($meta['options']) && is_array($meta['options']) ? $meta['options'] : [],
            'min'     => $meta['min'] ?? null,
            'max'     => $meta['max'] ?? null,
        ];
    }
}

/**
 * 保存表单提交的覆盖值（差异落盘）
 *
 * 只把「与框架默认不同」的项写进 Source/Inc/<Name>.inc.php；
 * 表单没出现的键按「保留现状」处理（部分提交不会冲掉别的覆盖），
 * 只有显式提交 INC_FORM_INHERIT 才删除该项覆盖。
 *
 * @param string               $name  配置名
 * @param array<string, mixed> $input 扁平点路径 → 值；INC_FORM_INHERIT 表示跟随默认
 * @return array<string, mixed>
 */
function saveInc(string $name, array $input): array
{
    $name = trim($name);
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
        return ['ok' => false, 'message' => '非法的配置名：' . $name];
    }

    $paths = incPaths($name);
    $fw    = (string) ($paths['framework'] ?? '');
    if ($fw === '' || !is_file($fw)) {
        return ['ok' => false, 'message' => '不是框架配置（默认文件不存在）：' . $name];
    }

    $base = (array) include $fw;
    unset($base['_schema']);

    $pj   = (string) ($paths['project'] ?? '');
    $over = ($pj !== '' && is_file($pj)) ? (array) include $pj : [];

    // ① 按框架默认的键树重建，多余键天然丢弃
    $built = incRebuild($base, $over, $input, '');

    // ② 目标路径（项目覆盖）
    $projectDir = (string) C('INCPATH');
    if ($projectDir === '' || !is_dir($projectDir)) {
        return ['ok' => false, 'message' => '项目配置目录不存在：' . $projectDir];
    }
    $target = rtrim($projectDir, "/\\") . DIRECTORY_SEPARATOR . $name . '.inc.php';

    // ③ 内容为空 → 删除覆盖文件（等于「全部跟随默认」）
    if ($built === []) {
        if (is_file($target)) {
            incBackupFile($name, $target);
            @unlink($target);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($target, true);
            }
            return [
                'ok'        => true,
                'removed'   => true,
                'message'   => '没有与默认不同的项，已删除覆盖文件（全部跟随框架默认）',
                'saved'     => [],
                'effective' => incEffective($fw, $target),
            ];
        }
        return [
            'ok'        => true,
            'removed'   => false,
            'message'   => '没有与默认不同的项，无需保存',
            'saved'     => [],
            'effective' => incEffective($fw, $target),
        ];
    }

    // ④ 生成 PHP 源码 → 语法/语义校验 → 备份 → 原子替换 → 失效 opcache
    $content = "<?php\n/**\n * {$name} 配置覆盖（由 /sys/config 表单生成）\n *\n * 只保存与框架默认不同的项；索引数组由源码编辑器维护。\n */\n"
        . "defined('CANDY') OR die('You Are A Bad Guy. o_O???');\n\n"
        . 'return ' . incExport($built) . ";\n";

    try {
        token_get_all($content, TOKEN_PARSE);
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => '生成的配置有语法错误：' . $e->getMessage()];
    }

    $tmp = $target . '.tmp-' . getmypid();
    if (@file_put_contents($tmp, $content) === false) {
        return ['ok' => false, 'message' => '写入失败：' . $tmp];
    }

    $check = null;
    try {
        $check = include $tmp;   // 语义校验：必须 return 数组
    } catch (\Throwable $e) {
        @unlink($tmp);
        return ['ok' => false, 'message' => '配置执行出错：' . $e->getMessage()];
    }
    if (!is_array($check)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => '配置文件必须 return 一个数组'];
    }

    incBackupFile($name, $target);
    @chmod($tmp, 0664);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => '替换失败（检查目录权限）'];
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($target, true);
    }

    return [
        'ok'        => true,
        'removed'   => false,
        'message'   => '已保存到 ' . $target,
        'saved'     => $built,
        'effective' => incEffective($fw, $target),
        'dropped'   => incDroppedKeys(true),
    ];
}

/**
 * 按框架默认键树重建覆盖数组（递归）
 *
 * @param array<string, mixed> $base  框架默认（本层）
 * @param array<string, mixed> $over  现有覆盖（本层，用于保留 list）
 * @param array<string, mixed> $input 扁平点路径 → 值
 * @param string               $prefix 父级点路径
 * @return array<string, mixed>
 */
function incRebuild(array $base, array $over, array $input, string $prefix): array
{
    $out = [];
    foreach ($base as $key => $def) {
        if ($key === '_schema') {
            continue;
        }
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($def)) {
            // 索引数组：表单不编辑 → 原样保留现有覆盖（绝不因“没提交”而丢弃）
            if (incIsListArray($def)) {
                if (array_key_exists($key, $over)) {
                    $out[$key] = $over[$key];
                }
                continue;
            }
            $subOver = isset($over[$key]) && is_array($over[$key]) ? $over[$key] : [];
            $sub = incRebuild($def, $subOver, $input, $path);
            // 子层重建后为空、但原本有覆盖 → 说明整层都被显式“跟随默认”，保持为空即可
            if ($sub !== []) {
                $out[$key] = $sub;
            }
            continue;
        }

        // ── 标量 ──────────────────────────────────────────────
        // 没提交 = 保留现状（部分提交不能冲掉别的覆盖）
        if (!array_key_exists($path, $input)) {
            if (array_key_exists($key, $over)) {
                $out[$key] = $over[$key];
            }
            continue;
        }

        $raw = $input[$path];
        // 显式「跟随默认」或 null → 删除该项覆盖
        if ($raw === null || $raw === INC_FORM_INHERIT) {
            continue;
        }

        $val = incCastValue($def, $raw);
        if ($val === $def) {
            continue;   // 与默认相同 → 不必写
        }
        $out[$key] = $val;
    }
    return $out;
}

/**
 * 按默认值的类型转换提交值
 *
 * @param mixed $def 默认值
 * @param mixed $raw 提交值
 * @return mixed
 */
function incCastValue(mixed $def, mixed $raw): mixed
{
    if (is_bool($def)) {
        if (is_bool($raw)) {
            return $raw;
        }
        $v = strtolower(trim((string) $raw));
        if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        return $raw;
    }
    if (is_int($def)) {
        return (int) $raw;
    }
    if (is_float($def)) {
        return (float) $raw;
    }
    if (is_string($def)) {
        return (string) $raw;
    }
    return $raw;
}

/**
 * 合并后的生效值（直接读盘，绕开 loadConfig 的请求内静态缓存）
 *
 * @param string $fw     框架默认文件
 * @param string $target 项目覆盖文件
 * @return array<string, mixed>
 */
function incEffective(string $fw, string $target): array
{
    $base = is_file($fw) ? (array) include $fw : [];
    unset($base['_schema']);
    $over = is_file($target) ? (array) include $target : [];
    return incMergeDefaults($base, $over);
}

/**
 * 备份已有覆盖文件到 Runtime/config-backup/
 *
 * @param string $name   配置名
 * @param string $target 目标文件
 * @return void
 */
function incBackupFile(string $name, string $target): void
{
    if (!is_file($target)) {
        return;
    }
    $root = defined('CANDYROOT') ? CANDYROOT : (string) C('CANDYROOT');
    $dir  = rtrim((string) $root, "/\\") . DIRECTORY_SEPARATOR . 'Runtime'
        . DIRECTORY_SEPARATOR . 'config-backup' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @copy($target, $dir . $name . '-' . date('Ymd-His') . '.php');
}

/**
 * 是否「列表数组」（键为 0..n-1 连续整数）
 *
 * @param array<mixed> $a
 * @return bool
 */
function incIsListArray(array $a): bool
{
    if ($a === []) {
        return true;
    }
    return array_keys($a) === range(0, count($a) - 1);
}


/**
 * 导出为 PHP 源码（短数组语法 + 缩进）
 *
 * 不用 var_export()：它输出 `array ( )` 这种长语法，与项目源码里的 `[]` 不一致。
 *
 * @param mixed $value 值
 * @param int   $depth 缩进层级
 * @return string
 */
function incExport(mixed $value, int $depth = 0): string
{
    if (!is_array($value)) {
        return var_export($value, true);
    }
    if ($value === []) {
        return '[]';
    }
    $pad  = str_repeat('    ', $depth + 1);
    $pad0 = str_repeat('    ', $depth);
    $list = incIsListArray($value);
    $lines = [];
    foreach ($value as $k => $v) {
        $item = incExport($v, $depth + 1);
        $lines[] = $pad . ($list ? '' : var_export($k, true) . ' => ') . $item . ',';
    }
    return "[\n" . implode("\n", $lines) . "\n" . $pad0 . ']';
}
