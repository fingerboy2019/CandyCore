<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 租户隔离库配置缓存键前缀（逻辑键；经 Cache::sharedKey 落在平台共享命名空间）
 *
 * 必须用共享键：登录时尚无 TENANT_ID，业务请求已有 TENANT_ID，若走租户段会读写两套键
 */
const TENANT_DB_CACHE_KEY_PREFIX = 'tenant:db:';

/**
 * 租户业务库表前缀出厂默认值（仅当 Tenant.inc.php 未声明或非法时回落）
 *
 * 初始化写入密文的 TABPREFIX 取自 Tenant.inc.php；运行时以密文/隔离连接为准，
 * 驱动层按 TABPREFIX 自动拼接逻辑表名。业务禁止硬编码 st_xxx。
 */
const TENANT_TABPREFIX_DEFAULT = 'st_';

/**
 * 租户库配置默认缓存秒数（7 天；宜 ≥ Refresh Token 寿命，可用 C('TENANT_DB_CACHE_TTL') 覆盖）
 */
const TENANT_DB_CACHE_TTL_DEFAULT = 604800;

/**
 * 校验租户业务库表前缀格式
 *
 * @param string $prefix 候选前缀
 * @return bool 是否合法（小写字母开头，允许数字下划线，最长 16）
 */
function isValidTenantTabPrefix(string $prefix): bool
{
	return preg_match('/^[a-z][a-z0-9_]{0,15}$/', $prefix) === 1;
}

/**
 * 使平台默认前缀进程内缓存失效
 *
 * @return void
 */
function tenantTabPrefixInvalidate(): void
{
	unset($GLOBALS['__tenant_tabprefix']);
}

/**
 * 读取平台默认业务库表前缀（仅用于初始化写入密文 / 密文缺失时兜底）
 *
 * 运行时业务拼接应优先 resolveTenantTabPrefix()（隔离连接或密文）。
 *
 * @return string 已校验的表前缀
 */
function tenantTabPrefix(): string
{
	if (isset($GLOBALS['__tenant_tabprefix']) && is_string($GLOBALS['__tenant_tabprefix'])
		&& $GLOBALS['__tenant_tabprefix'] !== '') {
		return $GLOBALS['__tenant_tabprefix'];
	}

	$raw = '';
	$root = defined('CANDYROOT') ? rtrim((string)CANDYROOT, '/\\') . '/' : '';
	$file = $root . 'Source/Inc/Tenant.inc.php';
	if ($root !== '' && is_file($file)) {
		$config = include $file;
		if (is_array($config)) {
			$raw = strtolower(trim((string)($config['tab_prefix'] ?? '')));
		}
	}

	if ($raw === '' || !isValidTenantTabPrefix($raw)) {
		$raw = TENANT_TABPREFIX_DEFAULT;
	}

	$GLOBALS['__tenant_tabprefix'] = $raw;
	return $raw;
}

/**
 * 规范化租户库连接配置中的 TABPREFIX
 *
 * 信任密文/入参中已合法的前缀；仅当缺失或非法时回落平台默认。
 * 入驻初始化必须事先写入平台默认，保证密文自洽。
 *
 * @param array $config 原始连接配置
 * @return array 已规范化 TABPREFIX / prefix 的配置
 */
function normalizeTenantDbConfig(array $config): array
{
	$raw = strtolower(trim((string)($config['TABPREFIX'] ?? $config['prefix'] ?? '')));
	if ($raw === '' || !isValidTenantTabPrefix($raw)) {
		$raw = tenantTabPrefix();
	}
	$config['TABPREFIX'] = $raw;
	$config['prefix'] = $raw;
	return $config;
}

/**
 * 解析租户业务库当前 TABPREFIX（隔离连接 → 密文 → 平台默认）
 *
 * @param string|null $tenantId 六位租户号；空则尝试当前 Host 租户
 * @return string 已校验前缀
 */
function resolveTenantTabPrefix(?string $tenantId = null): string
{
	$id = $tenantId !== null ? trim($tenantId) : '';
	if ($id === '' && function_exists('getTenantID')) {
		$id = (string)(getTenantID() ?? '');
	}
	$id = strtoupper($id);

	if ($id !== '' && preg_match('/^[A-Z0-9]{6}$/', $id) === 1) {
		foreach ([G('DBLIST'), C('DBLIST')] as $list) {
			if (!is_array($list)) {
				continue;
			}
			foreach ([$id, 't_' . $id] as $alias) {
				$node = $list[$alias] ?? null;
				if (!is_array($node)) {
					continue;
				}
				$raw = strtolower(trim((string)($node['TABPREFIX'] ?? $node['prefix'] ?? '')));
				if ($raw !== '' && isValidTenantTabPrefix($raw)) {
					return $raw;
				}
			}
		}
		if (function_exists('loadTenantDbConfig')) {
			try {
				$cfg = loadTenantDbConfig($id);
				if (is_array($cfg)) {
					$raw = strtolower(trim((string)($cfg['TABPREFIX'] ?? $cfg['prefix'] ?? '')));
					if ($raw !== '' && isValidTenantTabPrefix($raw)) {
						return $raw;
					}
				}
			} catch (\Throwable) {
			}
		}
	}

	return tenantTabPrefix();
}

/**
 * 按当前租户连接前缀拼接物理表名
 *
 * 逻辑表名勿自带前缀，例如 tenantTable('plm_product') → st_plm_product。
 *
 * @param string $logicalName 逻辑表名（如 plm_product、mdm_item）
 * @param string|null $tenantId 租户号；空则解析当前租户
 * @return string 物理表名
 */
function tenantTable(string $logicalName, ?string $tenantId = null): string
{
	$logical = ltrim(trim($logicalName));
	// 若误传入已带平台默认前缀的旧硬编码，剥掉一次避免双前缀
	$default = tenantTabPrefix();
	if ($default !== '' && str_starts_with($logical, $default)) {
		$logical = substr($logical, strlen($default));
	}
	return resolveTenantTabPrefix($tenantId) . $logical;
}

/**
 * 读取当前租户连接的表前缀。
 *
 * @param string|null $tenantId 租户号；空则解析当前租户
 * @return string 当前连接配置中的表前缀
 */
function tenantBaseTabPrefix(?string $tenantId = null): string
{
	return resolveTenantTabPrefix($tenantId);
}

/**
 * 平台基础表：逻辑名 → 当前连接前缀 + 逻辑名。
 *
 * @param string $logicalName 逻辑表名（如 setting、user；禁止自带物理前缀）
 * @param string|null $tenantId 租户号；空则解析当前租户
 * @return string 物理表名
 */
function tenantBaseTable(string $logicalName, ?string $tenantId = null): string
{
	$logical = ltrim(trim($logicalName));
	if ($logical === '') {
		throw new \InvalidArgumentException('tenantBaseTable: 逻辑表名不能为空');
	}

	return tenantTable($logical, $tenantId);
}

/**
 * 规范化租户连接配置中的表前缀。
 *
 * 保留调用方名称以避免扩大改动范围，但不再强制写入固定前缀。
 *
 * @param array $config 租户库连接配置
 * @return array 已规范化当前连接前缀的配置
 */
function withTenantBaseTabPrefix(array $config): array
{
	$normalized = normalizeTenantDbConfig($config);
	foreach (['write', 'read'] as $role) {
		if (isset($normalized[$role]) && is_array($normalized[$role])) {
			$roleConfig = $normalized[$role];
			if (!isset($roleConfig['TABPREFIX']) && !isset($roleConfig['prefix'])) {
				$roleConfig['TABPREFIX'] = $normalized['TABPREFIX'];
			}
			$normalized[$role] = normalizeTenantDbConfig($roleConfig);
		}
	}

	return $normalized;
}

/**
 * 解析租户库连接并保留其配置表前缀。
 *
 * @param string $tenantId 租户 ID
 * @return array 可用于 M()/setLink 的连接配置
 */
function resolveTenantBaseDbConfig(string $tenantId): array
{
	if (!function_exists('resolveTenantDbConfig')) {
		throw new \RuntimeException('租户数据库解析函数不可用');
	}
	$link = resolveTenantDbConfig($tenantId);
	$config = is_array($link) ? $link : [];
	if (is_string($link)) {
		foreach ([G('DBLIST'), C('DBLIST')] as $list) {
			if (is_array($list) && isset($list[$link]) && is_array($list[$link])) {
				$config = $list[$link];
				break;
			}
		}
	}
	if ($config === []) {
		throw new \RuntimeException('租户数据库配置不可用');
	}
	return withTenantBaseTabPrefix($config);
}

/**
 * Portal 基础 Model 短名 → 逻辑表名（不含物理前缀）
 *
 * 类名 = 逻辑表 StudlyCase（如 user→User、dict_item→DictItem）；
 * 禁止 Sa* 前缀类名；新增映射时禁止把 sa_ 写进右侧。
 *
 * @param string $modelShort 模型短名（如 Setting、User、DictItem）
 * @return string 逻辑表名
 */
function tenantBaseLogicalTable(string $modelShort): string
{
	static $map = [
		'Setting' => 'setting',
		'User' => 'user',
		'MemberProfile' => 'member_profile',
		'MemberDuty' => 'member_duty',
		'OrgDuty' => 'org_duty',
		'OrgDept' => 'org_dept',
		'CodeRule' => 'code_rule',
		'CodeCounter' => 'code_counter',
		'Dict' => 'dict',
		'DictItem' => 'dict_item',
		'Material' => 'material',
		'MinidiskShare' => 'minidisk_share',
		'WikiLibrary' => 'wiki_library',
		'NotifyOutbox' => 'notify_outbox',
		'NotifyDelivery' => 'notify_delivery',
		'NotifyPolicy' => 'notify_policy',
		'Log' => 'log',
		'DataAskAgent' => 'data_ask_agent',
		'DataAskRun' => 'data_ask_run',
		'LlmProvider' => 'llm_provider',
		'LlmModel' => 'llm_model',
		'AiEmployee' => 'ai_employee',
		'AgentMember' => 'agent_member',
		'AgentHandoff' => 'agent_handoff',
		'AgentHandoffEvent' => 'agent_handoff_event',
		'AgentSceneRoute' => 'agent_scene_route',
		'AgentExecutionFeedback' => 'agent_execution_feedback',
		'AgentSelfMemoryProposal' => 'agent_self_memory_proposal',
		'AgentSelfMemoryRevision' => 'agent_self_memory_revision',
		'AgentWorkExperienceRevision' => 'agent_work_experience_revision',
		'AiPromptVersion' => 'ai_prompt_version',
		'AiRun' => 'ai_run',
		'AiRunEvent' => 'ai_run_event',
		'AiToolCall' => 'ai_tool_call',
		'AiWriteIntent' => 'ai_write_intent',
		'RagDoc' => 'rag_doc',
		'RagChunk' => 'rag_chunk',
		'RagIndexTask' => 'rag_index_task',
		'RagRetrievalLog' => 'rag_retrieval_log',
		'TenantImMessage' => 'tenant_im_message',
		'TenantImConversation' => 'tenant_im_conversation',
		'TenantImContact' => 'tenant_im_contact',
		'TenantImMember' => 'tenant_im_member',
		'TenantImPreference' => 'tenant_im_preference',
		'TenantImFavorite' => 'tenant_im_favorite',
		'TenantImReaction' => 'tenant_im_reaction',
		'TenantImFriend' => 'tenant_im_friend',
		'TenantImEvent' => 'tenant_im_event',
		'TenantImEventDelivery' => 'tenant_im_event_delivery',
		'AiEmployeeRagCategory' => 'ai_employee_rag_category',
		'AiEmployeeWikiLibrary' => 'ai_employee_wiki_library',
		'AgentSkillDefinition' => 'agent_skill_definition',
		'AgentSkillRevision' => 'agent_skill_revision',
		'AgentSkillBinding' => 'agent_skill_binding',
		'WorkphoneDevice' => 'workphone_device',
	];
	$short = trim($modelShort);
	if ($short === '' || !isset($map[$short])) {
		throw new \InvalidArgumentException(
			'tenantBaseLogicalTable: 未登记的基座模型 "' . $modelShort . '"'
		);
	}
	return $map[$short];
}

/**
 * 绑定租户基础表模型：使用当前租户连接的 TABPREFIX + 逻辑表名。
 *
 * @param string $modelRef 模型引用，如 Portal://Setting、User（默认 Portal://）
 * @param string $tenantId 租户 ID
 * @param string|null $logicalTable 逻辑表名；null 时按模型短名查登记表
 * @return object 已绑定连接与逻辑表的 Model
 */
function tenantBaseModel(string $modelRef, string $tenantId, ?string $logicalTable = null): object
{
	$modelRef = trim($modelRef);
	if ($modelRef === '') {
		throw new \InvalidArgumentException('tenantBaseModel: 模型引用不能为空');
	}
	if (!str_contains($modelRef, '://')) {
		$modelRef = 'Portal://' . $modelRef;
	}
	[, $short] = explode('://', $modelRef, 2);
	$short = trim($short);
	$logical = $logicalTable !== null && trim($logicalTable) !== ''
		? trim($logicalTable)
		: tenantBaseLogicalTable($short);
	$config = resolveTenantBaseDbConfig($tenantId);
	$model = M($modelRef, $config);
	$model->setTable($logical);
	if (method_exists($model, 'resetQueryState')) {
		$model->resetQueryState();
	}
	return $model;
}

/**
 * 清理全部租户库连接共享缓存（前缀变更后必须调用，避免旧 TABPREFIX 残留）
 *
 * @return int 已清理的租户数
 */
function forgetAllTenantDbConfigs(): int
{
	$cleared = 0;
	try {
		if (!function_exists('M')) {
			return 0;
		}
		$rows = M('Admin://Tenant')->field('tenant_id')->select();
		if (!is_array($rows)) {
			return 0;
		}
		foreach ($rows as $row) {
			$tid = trim((string)($row['tenant_id'] ?? ''));
			if ($tid === '') {
				continue;
			}
			forgetTenantDbConfig($tid);
			$cleared++;
		}
	} catch (\Throwable) {
		return $cleared;
	}
	return $cleared;
}

/**
 * 租户库配置的共享缓存逻辑键
 *
 * @param string $tenantId 六位租户 ID
 * @return string 以 @ 开头的共享逻辑键
 */
function tenantDbConfigCacheKey(string $tenantId): string
{
	return \Candy\Core\Cache::sharedKey(TENANT_DB_CACHE_KEY_PREFIX . $tenantId);
}

/**
 * 租户库配置缓存 TTL（秒）
 *
 * @return int
 */
function tenantDbConfigCacheTtl(): int
{
	$ttl = C('TENANT_DB_CACHE_TTL');
	if ($ttl === null || $ttl === '' || $ttl === false) {
		return TENANT_DB_CACHE_TTL_DEFAULT;
	}
	$ttl = (int) $ttl;
	return $ttl > 0 ? $ttl : TENANT_DB_CACHE_TTL_DEFAULT;
}

/**
 * 将租户库配置注入运行时 DBLIST（进程内，供 setLink 按别名解析）
 *
 * 别名固定为六位 tenantId；不写静态 Config.inc
 *
 * @param string $tenantId 六位租户 ID（亦作隔离别名）
 * @param array $config 连接配置（HOST/USER/PASS/DBNAME/…）
 * @return void
 */
function hydrateTenantDbList(string $tenantId, array $config): void
{
	$list = G('DBLIST');
	if (!is_array($list)) {
		$list = C('DBLIST');
	}
	if (!is_array($list)) {
		$list = [];
	}
	// 注入前统一规范化，确保 DBLIST 使用密文/配置中的 TABPREFIX。
	$list[$tenantId] = normalizeTenantDbConfig($config);
	G('DBLIST', $list);
	C('DBLIST', $list);
}

/**
 * 从共享缓存读取租户库配置
 *
 * @param string $tenantId 六位租户 ID
 * @return array|null 连接配置；未命中或无效为 null
 */
function readTenantDbConfigCache(string $tenantId): ?array
{
	$tenantId = \Candy\Core\Bootstrap::normalizeTenantId($tenantId);
	if ($tenantId === null) {
		return null;
	}

	try {
		$payload = \Candy\Core\Cache::store()->get(tenantDbConfigCacheKey($tenantId), null);
	} catch (\Throwable) {
		return null;
	}

	return is_array($payload) && $payload !== [] ? $payload : null;
}

/**
 * 登录签发 JWT 时注册租户库配置（写入共享缓存 + 运行时 DBLIST）
 *
 * JWT extend **只带 tenantId**，不把 HOST/PASS 等打进 Token。
 * 后续请求凭 JWT 解析出 tenantId 后，由 resolveTenantDbLink / getTenantDbConfig 从缓存取回配置。
 *
 * 调用时机：租户端登录（或换租户）成功、签发 Access Token **之前或同时**。
 *
 * @param string $tenantId 六位租户 ID
 * @param array $config 从总库（platform）同步的连接配置
 * @param int|null $ttl 缓存秒数；null 用 TENANT_DB_CACHE_TTL / 默认 7 天
 * @return string 注册后的隔离别名（= tenantId）
 */
function registerTenantDbConfig(string $tenantId, array $config, ?int $ttl = null): string
{
	$id = \Candy\Core\Bootstrap::normalizeTenantId($tenantId);
	if ($id === null) {
		$msg = 'registerTenantDbConfig: 非法租户 ID（须为六位字母数字）';
		stop($msg);
		throw new \RuntimeException($msg);
	}
	if ($config === []) {
		$msg = 'registerTenantDbConfig: 租户库配置不能为空（tenantId=' . $id . '）';
		stop($msg);
		throw new \RuntimeException($msg);
	}

	// 写入缓存前只做配置规范化，不能用固定前缀覆盖租户连接配置。
	$config = normalizeTenantDbConfig($config);

	$expire = $ttl !== null && $ttl > 0 ? $ttl : tenantDbConfigCacheTtl();

	try {
		// 共享命名空间：跨 Worker、跨请求按 tenantId 可读；登录时尚未有 TENANT_ID 也能写入
		\Candy\Core\Cache::store()->set(tenantDbConfigCacheKey($id), $config, $expire);
	} catch (\Throwable $e) {
		$msg = 'registerTenantDbConfig: 写入租户库配置缓存失败（tenantId=' . $id . '）：' . $e->getMessage();
		stop($msg);
		throw new \RuntimeException($msg, 0, $e);
	}

	hydrateTenantDbList($id, $config);

	return $id;
}

/**
 * 按 tenantId 解析租户库配置（DBLIST → 共享缓存 → 总库补录）
 *
 * 命中缓存时回填 DBLIST；缓存未命中则调用可选钩子 loadTenantDbConfig($id)
 * 从总库（platform）按 ID 拉取连接配置，再 registerTenantDbConfig 补录缓存。
 *
 * @param string $tenantId 六位租户 ID
 * @return string|array|null 隔离别名 / 连接数组 / 未找到
 */
function resolveTenantDbConfig(string $tenantId): string|array|null
{
	$id = \Candy\Core\Bootstrap::normalizeTenantId($tenantId);
	if ($id === null) {
		return null;
	}

	// 1. 本进程 DBLIST（登录当次或其它请求已 hydrate）
	foreach ([G('DBLIST'), C('DBLIST')] as $list) {
		if (!is_array($list) || $list === []) {
			continue;
		}
		if (isset($list[$id]) && is_array($list[$id]) && $list[$id] !== []) {
			return $id;
		}
		$prefixed = 't_' . $id;
		if (isset($list[$prefixed]) && is_array($list[$prefixed]) && $list[$prefixed] !== []) {
			return $prefixed;
		}
	}

	// 2. 共享缓存（登录 registerTenantDbConfig 写入；Worker 冷启动后仍可按 JWT tenantId 恢复）
	$cached = readTenantDbConfigCache($id);
	if ($cached !== null) {
		hydrateTenantDbList($id, $cached);
		return $id;
	}

	// 3. 缓存未命中：从总库按 ID 拉取并补录缓存（须项目实现 loadTenantDbConfig）
	$fromDb = fetchTenantDbConfigFromPlatform($id);
	if ($fromDb !== null) {
		// register 写缓存 + DBLIST，后续请求直接命中缓存
		registerTenantDbConfig($id, $fromDb);
		return $id;
	}

	return null;
}

/**
 * 缓存未命中时从总库拉取租户库配置（调用可选钩子 loadTenantDbConfig）
 *
 * 钩子须使用 platform / 默认库查询，禁止 M/D(..., true)，否则会递归索库。
 *
 * @param string $tenantId 已规范化的六位租户 ID
 * @return array|null 连接配置；未实现钩子、查询失败或无记录为 null
 */
function fetchTenantDbConfigFromPlatform(string $tenantId): ?array
{
	if (!function_exists('loadTenantDbConfig')) {
		return null;
	}

	try {
		$cfg = loadTenantDbConfig($tenantId);
	} catch (\Throwable $e) {
		\Candy\Core\Debug::addMsg(
			'[<b>Tenant</b>] loadTenantDbConfig(' . htmlspecialchars($tenantId) . ') 失败：'
			. htmlspecialchars($e->getMessage()),
			2
		);
		return null;
	}

	if (!is_array($cfg) || $cfg === []) {
		return null;
	}

	return $cfg;
}

/**
 * 清除租户库配置缓存与运行时 DBLIST 项（换租户吊销、租户停用等）
 *
 * @param string $tenantId 六位租户 ID
 * @return void
 */
function forgetTenantDbConfig(string $tenantId): void
{
	$id = \Candy\Core\Bootstrap::normalizeTenantId($tenantId);
	if ($id === null) {
		return;
	}

	try {
		\Candy\Core\Cache::store()->delete(tenantDbConfigCacheKey($id));
	} catch (\Throwable) {
		// 忽略缓存驱动异常，仍清理进程内表
	}

	foreach (['G', 'C'] as $store) {
		$list = $store === 'G' ? G('DBLIST') : C('DBLIST');
		if (!is_array($list)) {
			continue;
		}
		unset($list[$id], $list['t_' . $id]);
		if ($store === 'G') {
			G('DBLIST', $list);
		} else {
			C('DBLIST', $list);
		}
	}
}
