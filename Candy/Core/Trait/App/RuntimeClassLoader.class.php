<?php
/***
 * 控制器/模型/服务/验证器/Schema 类自动加载与编译缓存
 *
 * 业务说明：源码编译进 Runtime；有 TENANT_ID 时优先各层下 Tenant/{id}/ 源码，
 * 独立命名空间与 .tenant.{id} 编译后缀，无租户源码则回退公共实现
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-04 17:33:21 $
 *
 * @package Candy\Core\Trait\App
 */
declare(strict_types=1);
namespace Candy\Core\Trait\App;

use Candy\Core\Bootstrap;
use Candy\Core\Debug;
use Candy\Core\CipherFile;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class RuntimeClassLoader
{
	/** @var array<string, true> */
	private static array $runtimeCache = [];

	/** @var list<string> 可选层：无源码时软失败（不报 S404） */
	private const OPTIONAL_TYPES = ['validate', 'schema'];

	/** @var list<string> 编译类型 */
	private const COMPILE_TYPES = ['action', 'common', 'model', 'service', 'validate', 'schema'];

	/**
	 * 当前请求租户 ID（无租户为 null）
	 *
	 * @return string|null
	 * @access private
	 * @static
	 */
	private static function currentTenantId(): ?string
	{
		return Bootstrap::tenantId();
	}

	/**
	 * 编译类型对应应用内目录段
	 *
	 * @param string $type action|common|model|service|validate|schema
	 * @return string 如 Controls/
	 * @access private
	 * @static
	 */
	private static function typeDirectory(string $type): string
	{
		return match ($type) {
			'common', 'action' => 'Controls/',
			'model' => 'Models/',
			'service' => 'Services/',
			'validate' => 'Validates/',
			'schema' => 'Schemas/',
			default => 'Controls/',
		};
	}

	/**
	 * 编译类型对应命名空间层名
	 *
	 * @param string $type 编译类型
	 * @return string Control|Model|Service|Validate|Schema|Addon
	 * @access private
	 * @static
	 */
	private static function typeLayer(string $type, ?bool $isAddon = null): string
	{
		// 未显式传入时默认非插件；调用方应按「目标应用」判定，禁止套用当前请求 ISADDON
		$isAddon ??= false;

		return match ($type) {
			'model' => 'Model',
			'service' => 'Service',
			'validate' => 'Validate',
			'schema' => 'Schema',
			'common', 'action' => $isAddon ? 'Addon' : 'Control',
			default => '',
		};
	}

	/**
	 * 判断目标应用是否为 Addon（按 Addons 目录，不读全局 ISADDON 误伤 Admin）
	 *
	 * @param string $app 应用名
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function isAddonApp(string $app): bool
	{
		$app = trim($app);
		if ($app === '') {
			return false;
		}
		if (\Candy\Core\Addon::resolveDirName($app) !== null) {
			return true;
		}
		// 兜底：当前请求已标记扩展，且解析目标就是当前 APPNAME
		if (!is_null(C('ISADDON')) && strcasecmp($app, trim((string) C('APPNAME'))) === 0) {
			return true;
		}
		return false;
	}

	/**
	 * 解析源码路径：租户优先，无则公共
	 *
	 * @param string $appPath 应用根路径（含尾随 /）
	 * @param string $type 编译类型
	 * @param string $shortName 源码短名
	 * @return array{0: string, 1: bool, 2: string|null} [源码路径, 是否租户源码, tenantId]
	 * @access private
	 * @static
	 */
	private static function resolveSourcePath(string $appPath, string $type, string $shortName): array
	{
		$secondAry = self::typeDirectory($type);
		$layer = rtrim($secondAry, '/');
		$tenantId = self::currentTenantId();

		// Hallmark 覆写优先：{root}/{layer}/Hallmark/{val}/X.class.php
		$hallmark = C('HALLMARK');
		if ($hallmark !== null && $hallmark !== '') {
			$hmSrc = $appPath . $layer . '/Hallmark/' . $hallmark . '/' . $shortName . '.class.php';
			if (fileExistsCase($hmSrc) || is_file($hmSrc)) {
				return [$hmSrc, true, $tenantId];
			}
		}

		// Tenant 覆写
		if ($tenantId !== null) {
			$tenantSrc = Bootstrap::tenantOverlayPath($appPath, $layer, $shortName . '.class.php');
			if ($tenantSrc !== null && (fileExistsCase($tenantSrc) || is_file($tenantSrc))) {
				return [$tenantSrc, true, $tenantId];
			}
		}

		return [$appPath . $secondAry . $shortName . '.class.php', false, $tenantId];
	}

	/**
	 * 解析应用层运行时 FQCN（租户有源码则带 Tenant\{id}，否则公共）
	 *
	 * 供 AppDispatcher、M()/S()、Autoload 统一拼类名
	 *
	 * @param string $type action|common|model|service|validate|schema
	 * @param string $app 应用名
	 * @param string $runtimeClass 运行时类名（含 Action/Model 等后缀；common 为 Common）
	 * @return string 完整类名（无前导反斜杠）
	 * @static
	 */
	public static function resolveFqcn(string $type, string $app, string $runtimeClass): string
	{
		$app = $app !== '' ? $app : (string) C('APPNAME');
		$runtimeClass = ucfirst($runtimeClass);
		// 按目标应用是否 Addon 选 Control/Addon 层，避免跨应用时套用当前 ISADDON
		$layer = self::typeLayer($type, self::isAddonApp($app));
		if ($layer === '') {
			return '';
		}

		// common 运行时类名为 Common（无 Action）
		if ($type === 'common' && str_ends_with($runtimeClass, 'Action')) {
			$runtimeClass = substr($runtimeClass, 0, -strlen('Action'));
		}

		$appPath = self::getBasePath() . $app . '/';
		$shortName = self::runtimeShortName($runtimeClass, $type);
		[, $isTenant, $tenantId] = self::resolveSourcePath(Bootstrap::resolveAppRoot($app), $type, $shortName);

		if ($isTenant && $tenantId !== null) {
			return 'APP\\' . $layer . '\\' . $app . '\\Tenant\\' . $tenantId . '\\' . $runtimeClass;
		}

		return 'APP\\' . $layer . '\\' . $app . '\\' . $runtimeClass;
	}

	/**
	 * 容器/缓存键用的租户后缀（无租户为空串）
	 *
	 * @return string 如 _t_Ab12Cd
	 * @static
	 */
	public static function tenantKeySuffix(): string
	{
		$tenantId = self::currentTenantId();
		return $tenantId !== null ? '_t_' . $tenantId : '';
	}

	/**
	 * Common
	 * @param mixed $className
	 * @param mixed $app
	 * @param mixed $isAddon
	 * @return bool
	 */
	public static function common(string $className, string $app = '', ?bool $isAddon = null): bool
	{
		return self::autoCreateAndImportClass($className, $app, 'common', [], $isAddon);
	}

	/**
	 * Model
	 * @param mixed $className
	 * @param mixed $app
	 * @return bool
	 */
	public static function model(string $className, string $app = ''): bool
	{
		if (str_ends_with($className, 'Model') === false) {
			$appPath = Bootstrap::resolveAppRoot($app !== '' ? $app : (string) C('APPNAME'));
			[$modelPath] = self::resolveSourcePath($appPath, 'model', $className);
			return requireCache($modelPath);
		}

		return self::autoCreateAndImportClass($className, $app, 'model');
	}

	/**
	 * Service
	 * @param mixed $className
	 * @param mixed $app
	 * @return bool
	 */
	public static function service(string $className, string $app = ''): bool
	{
		return self::autoCreateAndImportClass($className, $app, 'service');
	}

	/**
	 * 加载并编译 Validate 类
	 *
	 * @param string $className 运行时类名（须以 Validate 结尾）或短名
	 * @param string $app 应用名
	 * @return bool
	 * @static
	 */
	public static function validate(string $className, string $app = ''): bool
	{
		if (str_ends_with($className, 'Validate') === false) {
			$appPath = Bootstrap::resolveAppRoot($app !== '' ? $app : (string) C('APPNAME'));
			[$validatePath] = self::resolveSourcePath($appPath, 'validate', $className);
			return requireCache($validatePath);
		}

		return self::autoCreateAndImportClass($className, $app, 'validate');
	}

	/**
	 * 加载并编译 Schema 类
	 *
	 * @param string $className 运行时类名（须以 Schema 结尾）或短名
	 * @param string $app 应用名
	 * @return bool
	 * @static
	 */
	public static function schema(string $className, string $app = ''): bool
	{
		if (str_ends_with($className, 'Schema') === false) {
			$appPath = Bootstrap::resolveAppRoot($app !== '' ? $app : (string) C('APPNAME'));
			[$schemaPath] = self::resolveSourcePath($appPath, 'schema', $className);
			return requireCache($schemaPath);
		}

		return self::autoCreateAndImportClass($className, $app, 'schema');
	}

	/**
	 * Controler
	 * @param mixed $className
	 * @param mixed $app
	 * @param mixed $isAddon
	 * @return bool
	 */
	public static function controler(string $className, string $app = '', ?bool $isAddon = null): bool
	{
		return self::autoCreateAndImportClass($className, $app, 'action', [], $isAddon);
	}

	/**
	 * Get Base Path
	 * @return string
	 */
	public static function getBasePath(): string
	{
		return C('CANDYROOT') . 'Application' . DS;
	}

	/**
	 * 由运行时类名还原源码短名
	 *
	 * @param string $className 运行时类名
	 * @param string $type 编译类型
	 * @return string 源码短名
	 * @access private
	 * @static
	 */
	private static function runtimeShortName(string $className, string $type): string
	{
		// common：运行时/源码均为 Common（无 Action 后缀）
		if ($type === 'common') {
			if (str_ends_with($className, 'Action')) {
				return substr($className, 0, -strlen('Action'));
			}
			return $className;
		}

		$suffix = self::runtimeSuffix($type);
		if ($suffix !== '' && str_ends_with($className, $suffix)) {
			return substr($className, 0, -strlen($suffix));
		}

		return $className;
	}

	/**
	 * 编译类型对应的运行时类名后缀
	 *
	 * @param string $type 编译类型
	 * @return string 如 Service、Model；common 为空
	 * @access private
	 * @static
	 */
	private static function runtimeSuffix(string $type): string
	{
		return match ($type) {
			'model' => 'Model',
			'action' => 'Action',
			'service' => 'Service',
			'validate' => 'Validate',
			'schema' => 'Schema',
			default => '',
		};
	}

	/**
	 * 将自动加载入口名规范为运行时类名（带编译后缀）
	 *
	 * 源码短名（如 SchedulerJobLibrary）与运行时名（SchedulerJobLibraryService）均可传入
	 *
	 * @param string $className 入口类名
	 * @param string $type 编译类型
	 * @return string 运行时类名
	 * @access private
	 * @static
	 */
	private static function ensureRuntimeClassName(string $className, string $type): string
	{
		$className = ucfirst($className);
		if ($type === 'common') {
			return self::runtimeShortName($className, $type);
		}

		$suffix = self::runtimeSuffix($type);
		if ($suffix !== '' && !str_ends_with($className, $suffix)) {
			return $className . $suffix;
		}

		return $className;
	}

	/**
	 * Auto Create And Import Class
	 * @param mixed $className
	 * @param mixed $app
	 * @param mixed $type
	 * @param mixed $importList
	 * @param mixed $isAddon
	 * @return bool
	 */
	private static function autoCreateAndImportClass(
		string $className,
		mixed $app = '',
		string $type = 'action',
		array $importList = [],
		?bool $isAddon = null
	): bool
	{
		$app = $app ?: C('APPNAME');
		$className = ucfirst($className);
		// 目录为准：源码根与 Runtime 编译目录必须一致走 Addons/{App}，
		// 禁止调用方传入的 isAddon/全局 ISADDON 把 Addon 编进 Controls/{App}/ 造成同 FQCN 双份。
		$appPath = Bootstrap::resolveAppRoot((string) $app);
		$isAddon = self::isAddonApp((string) $app);
		if ($isAddon) {
			// 逻辑名供 FQCN / Runtime 缓存键；磁盘根走拆段探测（Creator/Hub → CreatorHub）
			$logical = \Candy\Core\Addon::resolveDirName((string) $app);
			$root = \Candy\Core\Addon::resolveRoot((string) $app);
			if ($logical !== null) {
				$app = $logical;
			}
			if ($root !== null) {
				$appPath = $root;
			}
		}

		$secondAry = self::typeDirectory($type);
		if (!in_array($type, self::COMPILE_TYPES, true)) {
			showExceptionMsg('shutdown', '<font color=\'red\'>类库自动加载范围越界</font>');
			return false;
		}

		// 运行时名（带后缀）用于 class_exists；源码短名用于定位源文件与 Service 缓存键
		$runtimeClassName = self::ensureRuntimeClassName($className, $type);
		$shortName = self::runtimeShortName($runtimeClassName, $type);
		// model 历史：短名入口缓存键为 {short}model；带 Model 后缀入口为 {runtime}
		$flieMark = ($type === 'model' && $className === $shortName) ? 'model' : '';
		// service：短名与 *Service 共用同一编译缓存，避免双份文件与错误 FQCN 校验
		$fileKey = $type === 'service' ? $shortName : $runtimeClassName;

		[$src, $isTenant, $tenantId] = self::resolveSourcePath($appPath, $type, $shortName);

		// 插件层：Plugins/{Plug}/{Controls|…}/Hallmark/{val}/ → Tenant/{id}/ → 公共插件源码
		$plugRoot = C('PLUGPATH') . C('RAPPNAME') . '/';
		$layer = rtrim($secondAry, '/');
		$extsrc = $plugRoot . $secondAry . $shortName . '.class.php';
		$pluginIsTenant = false;

		// Hallmark 插件覆写
		$hallmark = C('HALLMARK');
		if ($hallmark !== null && $hallmark !== '') {
			$hmPlugin = $plugRoot . $layer . '/Hallmark/' . $hallmark . '/' . $shortName . '.class.php';
			if (fileExistsCase($hmPlugin) || is_file($hmPlugin)) {
				$extsrc = $hmPlugin;
				$pluginIsTenant = true;
			}
		}

		// Tenant 插件覆写
		if (!$pluginIsTenant) {
			$extsrcTenant = Bootstrap::tenantOverlayFile($plugRoot, $layer, $shortName . '.class.php');
			if ($extsrcTenant !== null) {
				$extsrc = $extsrcTenant;
				$pluginIsTenant = true;
			}
		}
		// 应用或插件任一租户覆写：编译产物带 .tenant.{id}，避免互盖
		$tenantCompile = ($isTenant || $pluginIsTenant) && $tenantId !== null;

		isset($importList[$app]) OR $importList[$app][$type] = [];
		if (in_array($runtimeClassName, $importList[$app][$type])) {
			showExceptionMsg('shutdown', '<font color=\'red\'>' . $runtimeClassName . ' 不能被重复继承！</font>');
		} else {
			$importList[$app][$type][] = $runtimeClassName;
		}

		$path = C('RUNTIME') . $secondAry . ($isAddon ? 'Addons/' : '') . $app . '/';

		// 编译产物：租户源码带 .tenant.{id}；回退公共时排除租户文件
		$baseGlob = $path . strtolower($fileKey) . $flieMark;
		if ($type === 'common') {
			$baseGlob = $path . 'common';
		}
		if ($tenantCompile) {
			$aimList = glob($baseGlob . '.*.tenant.' . $tenantId . '.class.php') ?: [];
			if ($type === 'common') {
				$aimList = glob($path . 'common.*.tenant.' . $tenantId . '.class.php') ?: [];
			}
		} else {
			$all = glob($baseGlob . '.*') ?: [];
			if ($type === 'common') {
				$all = glob($path . 'common.*') ?: [];
			}
			$aimList = array_values(array_filter(
				$all,
				static fn(string $f): bool => !str_contains($f, '.tenant.')
			));
		}

		if ((count($aimList) > 1 && stripos($fileKey, 'Extend') === false) || count($aimList) > 2) {
			foreach ($aimList as $mp) {
				unlink($mp);
			}
			$aimList = [];
		}

		$tmpFile = empty($aimList) ? '' : $aimList[0];
		if (empty($tmpFile) || (empty($tmpFile) === false &&
				((fileExistsCase($src) && fileExistsCase($extsrc) && filemtime($tmpFile) < filemtime($src) && filemtime($tmpFile) > filemtime($extsrc))
				|| (fileExistsCase($src) && fileExistsCase($extsrc) == false && filemtime($tmpFile) < filemtime($src))
				|| (fileExistsCase($src) == false && fileExistsCase($extsrc) && filemtime($tmpFile) < filemtime($extsrc)))
			)
		) {
			if (is_file($src) || is_file($extsrc)) {
				// Service 用短名写缓存文件；其余层用运行时类名（与历史产物一致）
				$tmpFile = self::getCmpiledFile($fileKey, $src, $extsrc, $type, $path, $flieMark, (string) $app, $appPath, $isTenant, $tenantId, $tenantCompile, $isAddon);
			} else {
				if (!in_array($type, self::OPTIONAL_TYPES, true)) {
					C('S404', '控制器不存在。');
					if (in_array($type, ['model', 'service'], true)) {
						showExceptionMsg('shutdown', '<font color=\'red\'>文件' . $src . '不存在!</font>');
					}
				}
			}
		}

		$displayName = $shortName;
		if ($tmpFile === false) {
			showExceptionMsg('shutdown', '<b> ' . $displayName . ' </b>类编译失败请检查系统写入权限',);
		} else {
			if (empty($tmpFile) === false) {
				$runtimeFqcn = self::runtimeFqcn($runtimeClassName, (string) $app, $type, $isTenant, $tenantId, $isAddon);
				// 已加载则跳过，避免 include 同一编译文件导致 Cannot redeclare
				$already = ($runtimeFqcn !== '' && (
					class_exists($runtimeFqcn, false)
					|| interface_exists($runtimeFqcn, false)
					|| trait_exists($runtimeFqcn, false)
				)) || !empty(self::$runtimeCache[$tmpFile]);
				if (!$already) {
					include_once $tmpFile;
					self::$runtimeCache[$tmpFile] = true;
				}
				if ($runtimeFqcn !== '' && !class_exists($runtimeFqcn, false) && !interface_exists($runtimeFqcn, false) && !trait_exists($runtimeFqcn, false)) {
					showExceptionMsg(
						'shutdown',
						'<font color=\'red\'>类 ' . $runtimeFqcn . ' 编译后未定义，请检查源码或清理 Runtime 后重试（' . $tmpFile . '）</font>'
					);
				} elseif ($runtimeFqcn !== '' && $shortName !== $runtimeClassName) {
					// 短名 FQCN 别名到编译类，兼容 Workerman 等直接引用源码短名的代码
					$shortFqcn = self::runtimeFqcn($shortName, (string) $app, $type, $isTenant, $tenantId, $isAddon);
					if (
						$shortFqcn !== ''
						&& $shortFqcn !== $runtimeFqcn
						&& !class_exists($shortFqcn, false)
						&& !interface_exists($shortFqcn, false)
						&& !trait_exists($shortFqcn, false)
						&& (class_exists($runtimeFqcn, false) || interface_exists($runtimeFqcn, false) || trait_exists($runtimeFqcn, false))
					) {
						class_alias($runtimeFqcn, $shortFqcn);
					}
				}
				$tenantNote = ($tenantCompile && $tenantId !== null) ? ' <font color=\'green\'>[tenant:' . $tenantId . ']</font>' : '';
				Debug::addMsg('<b> ' . $displayName . ' </b>类' . $tenantNote, 1);
			} elseif (in_array($type, ['model', 'service'], true)) {
				showExceptionMsg('shutdown', '<font color=\'red\'>' . $displayName . ' 类未能加载，源码或编译缓存异常</font>');
			}
		}

		return true;
	}

	/**
	 * 由运行时短名还原待校验的 FQCN
	 *
	 * @param string $className 运行时类名
	 * @param string $app 应用名
	 * @param string $type 编译类型
	 * @param bool $isTenant 是否租户源码
	 * @param string|null $tenantId 租户 ID
	 * @return string 完整类名
	 * @access private
	 * @static
	 */
	private static function runtimeFqcn(
		string $className,
		string $app,
		string $type,
		bool $isTenant = false,
		?string $tenantId = null,
		?bool $isAddon = null
	): string
	{
		$app = (string) $app;
		if ($app === '' || $className === '') {
			return '';
		}

		$ns = self::typeLayer($type, $isAddon);
		if ($ns === '') {
			return '';
		}

		$runtimeClass = $className;
		if ($type === 'common' && str_ends_with($runtimeClass, 'Action')) {
			$runtimeClass = substr($runtimeClass, 0, -strlen('Action'));
		}

		if ($isTenant && $tenantId !== null) {
			return 'APP\\' . $ns . '\\' . $app . '\\Tenant\\' . $tenantId . '\\' . $runtimeClass;
		}

		return 'APP\\' . $ns . '\\' . $app . '\\' . $runtimeClass;
	}

	/**
	 * 租户源码：改写 namespace，父类无租户覆写时限定到公共 FQCN
	 *
	 * @param string $classContent 编译中的类源码
	 * @param string $type 编译类型
	 * @param string $appName 应用名
	 * @param string $appPath 应用根路径
	 * @param string $tenantId 租户 ID
	 * @return string
	 * @access private
	 * @static
	 */
	/**
	 * AOP 切面织入
	 *
	 * 扫描源码里带 #[\Candy\Core\Attribute\Aspect(...)] 的方法，把方法体包一层
	 * 切面调用（before / after / onError）。织入结果先用 php -l 自检，
	 * 不合法就回退原文并记日志 —— 编译坏掉会让整个类不可用，代价远大于"少了切面"。
	 *
	 * 可用配置 AOP_ENABLE=0 全局关闭（关闭后源码原样返回）。
	 *
	 * @param string $classContent 源码
	 * @param string $type         编译类型（action/common/model/service/validate/schema）
	 * @param string $appName      应用名（仅用于日志）
	 * @return string 织入后的源码
	 */
	private static function applyAspects(string $classContent, string $type, string $appName): string
	{
		// 快速跳过：没有 Aspect 字样
		if ($classContent === '' || strpos($classContent, 'Aspect') === false) {
			return $classContent;
		}

		// 全局开关
		$enabled = C('AOP_ENABLE');
		if ($enabled !== null && $enabled !== ''
			&& !in_array(strtolower(trim((string) $enabled)), ['1', 'true', 'yes', 'on'], true)) {
			return $classContent;
		}

		$woven = $classContent;
		try {
			$woven = \Candy\Core\AspectWeaver::weave($classContent);
		} catch (\Throwable $e) {
			self::logAspectIssue($appName, $type, '织入异常：' . $e->getMessage());
			return $classContent;
		}

		if ($woven === $classContent) {
			return $classContent;   // 没有切面，原样返回
		}

		// 自检：语法必须合法，否则回退
		if (!self::syntaxCheck($woven)) {
			self::logAspectIssue($appName, $type, '织入后语法不合法，已回退原文');
			return $classContent;
		}

		return $woven;
	}

	/**
	 * 语法自检（织入产物必须能过 php -l）
	 *
	 * 优先用 php -l（最准）；exec 被禁用时退化为括号配对检查。
	 *
	 * @param string $code
	 * @return bool
	 */
	private static function syntaxCheck(string $code): bool
	{
		$canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
		if ($canExec) {
			$tmp = sys_get_temp_dir() . '/candy-aop-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
			if (@file_put_contents($tmp, $code) !== false) {
				$out = [];
				$rc = 1;
				// 优先用当前 PHP 自身的二进制。生产环境常把 php 装在
				// /www/server/php/8x/bin/php，裸 `php` 不在 PATH，
				// exec 直接失败（127）—— 那属于"无法验证"，不是"语法错误"。
				$bin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
				@exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
				@unlink($tmp);

				$text = implode("\n", (array) $out);
				// 明确成功
				if (stripos($text, 'No syntax errors') !== false) {
					return true;
				}
				// 明确失败（真语法错误）
				if (stripos($text, 'Parse error') !== false || stripos($text, 'syntax error') !== false) {
					return false;
				}
				// 其它情况（命令不存在 / 无输出 / 权限问题）→ 交给下面的退化检查，
				// 绝不因为"自检跑不起来"就把织入产物丢掉。
				if ($rc === 0) {
					return true;
				}
			}
		}

		// 退化：token 括号配对检查（弱一些，但能挡住明显的结构破坏）
		try {
			$tokens = token_get_all($code);
			$depth = 0;
			foreach ($tokens as $tk) {
				if ($tk === '{' || $tk === '(' || $tk === '[') { $depth++; }
				elseif ($tk === '}' || $tk === ')' || $tk === ']') {
					$depth--;
					if ($depth < 0) { return false; }
				}
			}
			return $depth === 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * 记录织入问题（不打断编译）
	 *
	 * @param string $app
	 * @param string $type
	 * @param string $msg
	 * @return void
	 */
	private static function logAspectIssue(string $app, string $type, string $msg): void
	{
		try {
			$dir = CANDYROOT . 'Runtime/Logs/';
			if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
			@file_put_contents(
				$dir . 'aop-' . date('Y-m-d') . '.log',
				'[' . date('H:i:s') . '] [' . $app . '/' . $type . '] ' . $msg . PHP_EOL,
				FILE_APPEND
			);
		} catch (\Throwable $ignored) {
		}
	}

	private static function applyTenantCompileTransforms(
		string $classContent,
		string $type,
		string $appName,
		string $appPath,
		string $tenantId,
		?bool $isAddon = null
	): string
	{
		$layer = self::typeLayer($type, $isAddon);
		if ($layer === '') {
			return $classContent;
		}

		$publicNs = 'APP\\' . $layer . '\\' . $appName;
		$tenantNs = $publicNs . '\\Tenant\\' . $tenantId;

		// 任意 APP\{Layer}\{App}… 命名空间统一改写到租户 NS
		$classContent = preg_replace(
			'/namespace\s+APP\\\\' . preg_quote($layer, '/') . '\\\\' . preg_quote($appName, '/') . '(?:\\\\[^;]*)?\s*;/',
			'namespace ' . $tenantNs . ';',
			$classContent,
			1
		) ?? $classContent;

		// 相对父类：租户无对应源码则指向公共 FQCN
		if (preg_match('/class\s+\S+\s+extends\s+([^\\\\\s{][^\\s{]*)\s*\{/', $classContent, $m)) {
			$parent = trim($m[1]);
			if ($parent !== '' && !str_starts_with($parent, '\\') && !str_starts_with($parent, 'APP\\')) {
				$parentShort = $parent;
				$parentType = $type;
				foreach (['Model' => 'model', 'Service' => 'service', 'Validate' => 'validate', 'Schema' => 'schema', 'Action' => 'action'] as $suf => $t) {
					if (str_ends_with($parent, $suf)) {
						$parentShort = substr($parent, 0, -strlen($suf));
						$parentType = $t;
						// CommonAction → 源码 Common，目录 Controls
						if ($parentShort === 'Common') {
							$parentType = 'common';
						}
						break;
					}
				}
				if (in_array($parent, ['Common', 'Base'], true)) {
					$parentShort = $parent;
					$parentType = $parent === 'Base' ? 'action' : 'common';
				}

				$secondAry = self::typeDirectory($parentType === 'common' ? 'common' : $parentType);

				// Hallmark 父类覆写
				$parentLayer = rtrim($secondAry, '/');
				$hmVal = C('HALLMARK');
				$hmParent = null;
				if ($hmVal !== null && $hmVal !== '') {
					$hmPath = $appPath . $parentLayer . '/Hallmark/' . $hmVal . '/' . $parentShort . '.class.php';
					if (fileExistsCase($hmPath)) {
						$hmParent = $hmPath;
					}
				}

				// Tenant 父类覆写（Hallmark 优先）
				$tenantParent = $hmParent ?? Bootstrap::tenantOverlayPath($appPath, $parentLayer, $parentShort . '.class.php');
				if (($hmParent === null) && ($tenantParent === null || !fileExistsCase($tenantParent))) {
					// Common 公共运行时类名为 Common；经 Action 链加载时为 CommonAction
					$publicParent = '\\APP\\' . self::typeLayer($parentType === 'common' ? 'common' : $parentType, $isAddon) . '\\' . $appName . '\\' . $parent;
					if ($parentType === 'common' && $parent === 'CommonAction') {
						// 保持 CommonAction：公共侧由 action 编译产物提供
						$publicParent = '\\APP\\Control\\' . $appName . '\\CommonAction';
						if ($isAddon) {
							$publicParent = '\\APP\\Addon\\' . $appName . '\\CommonAction';
						}
					} elseif ($parentType === 'common' && $parent === 'Common') {
						$publicParent = '\\APP\\' . ($isAddon ? 'Addon' : 'Control') . '\\' . $appName . '\\Common';
					}
					$classContent = preg_replace(
						'/class\s+(\S+)\s+extends\s+' . preg_quote($parent, '/') . '\s*\{/',
						'class $1 extends ' . $publicParent . ' {',
						$classContent,
						1
					) ?? $classContent;
				}
			}
		}

		return $classContent;
	}

	/**
	 * Get Cmpiled File
	 * @param mixed $className
	 * @param mixed $src
	 * @param mixed $extsrc
	 * @param mixed $type
	 * @param mixed $path
	 * @param mixed $flieMark
	 * @param mixed $appName
	 * @param mixed $appPath
	 * @param mixed $isTenant
	 * @param mixed $tenantId
	 * @param mixed $tenantCompile
	 * @param mixed $isAddon
	 * @return mixed
	 */
	private static function getCmpiledFile(
		string $className,
		string $src,
		string $extsrc,
		string $type,
		string $path,
		string $flieMark,
		string $appName = '',
		string $appPath = '',
		bool $isTenant = false,
		?string $tenantId = null,
		bool $tenantCompile = false,
		?bool $isAddon = null
	): mixed {
		$appName = $appName !== '' ? $appName : (string) C('APPNAME');
		if ($appPath === '') {
			$appPath = Bootstrap::resolveAppRoot($appName);
		}
		// 仅插件租户覆写时也要隔离编译产物
		$tenantCompile = $tenantCompile || ($isTenant && $tenantId !== null);

		$className = ucfirst($className);

		$super = '/extends\s+(.+?)\s*{/i';

		$lastType = 'nonal';
		$parent = false;

		$extend = false;
		$extClassContent = '';
		$extClassName = '';
		if (fileExistsCase($extsrc)) {
			$extClassContent = CipherFile::getInstance()->fileGetContents($extsrc);
			if (preg_match($super, $extClassContent, $tarr)) {
				$lastType = strtolower($tarr[1]);
			} else {
				$extend = true;
				$lastType = strtolower($className);
				$extClassName = $className . 'Base';
			}
		}

		$baseClassFileExits = fileExistsCase($appPath . 'Controls/Base.class.php')
			|| fileExistsCase($appPath . 'Base.class.php');

		if ($type == 'model') {
			$driver = G('DRIVER');
			$driverName = '';
			$driverPath = '';
			if (stripos($driver, '\\') === false) {
				$driver = \Candy\Extend\DB\DBServer::resolveDriverClass((string) $driver);
				if (is_file(CANDYPATH . 'Candy/Class/DB/Driver/' . $driver . '.class.php')) {
					$driverName = '\\Candy\\Extend\\DB\\Driver\\' . $driver;
					$driverPath = CANDYPATH . 'Candy/Class/DB/Driver/' . $driver . '.class.php';
				} elseif (is_file(C('SOURCEPATH') . 'Class/' . $driver . '.class.php')) {
					$driverName = '\\APP\\Extend\\' . $driver;
					$driverPath = C('SOURCEPATH') . 'Class/' . $driver . '.class.php';
				} elseif (is_file(C('SOURCEPATH') . 'Vendor/' . $driver . '.php')) {
					$driverName = $driver;
					$driverPath = C('SOURCEPATH') . 'Vendor/' . $driver . '.php';
				}
			} else {
				$driverRightPath = trim(strtr($driver, '\\', '/'), '/');
				if (stripos($driver, 'APP\\Extend\\') !== false && is_file(C('SOURCEPATH') . 'Class/' . $driverRightPath . '.class.php')) {
					$driverName = $driver;
					$driverPath = C('SOURCEPATH') . 'Class/' . $driverRightPath . '.class.php';
				} elseif (is_file(C('SOURCEPATH') . 'Vendor/' . $driverRightPath . '.php')) {
					$driverName = $driver;
					$driverPath = C('SOURCEPATH') . 'Vendor/' . $driverRightPath . '.php';
				}
			}

			if ($driverName !== '' && class_exists($driverName) === false && is_file($driverPath)) {
				require_once $driverPath;
			}

			if ($driverName === '' || class_exists($driverName) === false) {
				showExceptionMsg('shutdown', '数据库驱动' . $driver . '不存在');
			}
		}

		$classContent = '';
		$search = '/class\s+(.+?)\s*{/i';
		if (fileExistsCase($src)) {
			$classContent = empty($extClassContent) ? CipherFile::getInstance()->fileGetContents($src) : ($extend === false ? $extClassContent : CipherFile::getInstance()->fileGetContents($src));

			if ($type == 'service') {
				if (preg_match($super, $classContent, $extends)) {
					if (trim($extends[1]) == 'Common') {
						showExceptionMsg('shutdown', '<font color=\'red\'>' . $extends[0] . '类库为Service类库不能继承Common类库，避免受到init的初始化影响！</font>');
					}
					$search = '/class\s+(.+?)\s+extends\s+(.+?)\s*{/i';
				} else {
					$search = '/class\s+(.+?)\s*{/i';
				}
				$replace = $baseClassFileExits
					? 'class \1Service extends ' . ('\\APP\\' . ($isAddon ? 'Addon' : 'Control') . '\\' . $appName . '\\Base') . ' {'
					: 'class \1Service extends \Candy\APP\Common\Base {';
			} elseif ($type == 'validate') {
				if (preg_match($super, $classContent, $extends)) {
					$parent = true;
					$search = '/class\s+(.+?)\s+extends\s+(.+?)\s*{/i';
					$lastType = strtolower($extends[1]);
					$replace = 'class \1Validate extends ' . trim($extends[1]) . 'Validate {';
				} else {
					$search = '/class\s+(.+?)\s*{/i';
					$replace = 'class \1Validate extends \\Candy\\Extend\\Validate\\Base {';
				}
			} elseif ($type == 'schema') {
				if (preg_match($super, $classContent, $extends)) {
					$parent = true;
					$search = '/class\s+(.+?)\s+extends\s+(.+?)\s*{/i';
					$lastType = strtolower($extends[1]);
					$replace = 'class \1Schema extends ' . trim($extends[1]) . 'Schema {';
				} else {
					$search = '/class\s+(.+?)\s*{/i';
					$replace = 'class \1Schema extends \\Candy\\Extend\\Schema\\Base {';
				}
			} elseif ($type == 'common') {
				if (preg_match($super, $classContent, $extends)) {
					if (trim($extends[1]) != 'Base') {
						showExceptionMsg('shutdown', '<font color=\'red\'>Common类库不能继承' . $extends[1] . '类库，其只被允许继承Base类库！</font>');
					}
					$search = '/class\s+(.+?)\s+extends\s+(.+?)\s*{/i';
				} else {
					$search = '/class\s+(.+?)\s*{/i';
				}

				$replace = $baseClassFileExits
					? 'class Common extends ' . ('\\APP\\' . ($isAddon ? 'Addon' : 'Control') . '\\' . $appName . '\\Base') . ' {'
					: 'class \1 extends \Candy\APP\Common\Base {';
			} else {
				if (preg_match($super, $classContent, $extends)) {
					$parent = true;
					$search = '/class\s+(.+?)\s+extends\s+(.+?)\s*{/i';
					$lastType = strtolower($extends[1]);
					if ($type == 'model') {
						$replace = 'class \1Model extends ' . $extends[1] . 'Model {';
					} else {
						if (trim($extends[1]) != 'Base') {
							$replace = 'class \1Action extends \2Action {';
						} else {
							$replace = $baseClassFileExits ? 'class \1Action extends ' . ('\\APP\\' . ($isAddon ? 'Addon' : 'Control') . '\\' . $appName . '\\Base') . ' {' : 'class \1Action extends \Candy\APP\Common\Base {';
						}
					}
				} else {
					$replace = $type == 'model' ? 'class \1Model extends \\Candy\\Extend\\DB\\BaseModel {' : 'class \1Action extends Common {';
				}
			}

			$classContent = preg_replace($search, $replace, $classContent, 1);

			if (!empty($extClassContent) && $extend) {
				$lastType = 'extend';
				$search = '/class\s+(.+?)\s*{/i';
				$extClassContent = preg_replace($search, 'class \1' . (($type == 'common') ? '' : ucfirst($type)) . ' extends ' . $extClassName . ' {', $extClassContent, 1);

				$classContent = preg_replace('/class\s+(.+?)\s+extends\s+(.+?)\s*{/i', 'class ' . $extClassName . ' extends \2 {', $classContent, 1);
				$classContent = str_replace('?>', '', $classContent) . PHP_EOL . PHP_EOL . str_replace('<?php', '', $extClassContent);
			}
		} else {
			if ($type == 'common') {
				$classContent = empty($extClassContent) ? str_replace('{APPNAME}', $appName, base64_decode('PD9waHAKbmFtZXNwYWNlIEFQUFxDb250cm9sXHtBUFBOQU1FfTsKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCmNsYXNzIENvbW1vbiBleHRlbmRzIFxDYW5keVxBUFBcQ29tbW9uXEJhc2UgewoJZnVuY3Rpb24gaW5pdCgpewoKCX0JCn0K')) : $extClassContent;

				if ($baseClassFileExits) {
					$lastType = 'base';
					$nameSpace = '\\APP\\' . ($isAddon ? 'Addon' : 'Control') . '\\' . $appName . '\\Base';
					$classContent = preg_replace('/class\s+(.+?)\s+extends\s+(.+?)\s*{/i', 'class \1 extends ' . $nameSpace . ' {', $classContent, 1);
				}
			} else {
				if (empty($extClassContent)) {
					if (in_array($type, ['model', 'service'], true)) {
						showExceptionMsg('shutdown', '<font color=\'red\'>文件' . $src . '不存在!</font>');
					} elseif (in_array($type, self::OPTIONAL_TYPES, true)) {
						return false;
					} else {
						if (is_null(C('S404'))) {
							C('S404', true);
							Debug::addMsg('<font color=\'red\'>类库不存在,应在[' . str_replace(C('CANDYROOT'), '/', $src) . ']文件里，声明一个名为' . str_replace('Action', '', $className) . '的类！</font>');
						}
					}
				} else {
					$lastType = 'extend';
					$search = '/class\s+(.+?)\s*{/i';
					$replace = match ($type) {
						'model' => 'class \1Model extends \\Candy\\Extend\\DB\\BaseModel {',
						'service' => 'class \1Service extends \Candy\APP\Common\Base {',
						'validate' => 'class \1Validate extends \\Candy\\Extend\\Validate\\Base {',
						'schema' => 'class \1Schema extends \\Candy\\Extend\\Schema\\Base {',
						default => 'class \1Action extends Common {'
					};
					$classContent = preg_replace($search, $replace, $extClassContent, 1);
					if ($type == 'action') {
						Debug::addMsg('<font color=\'red\'>类库不存在,应在' . str_replace(C('CANDYROOT'), '/', $src) . '文件，声明一个类名为' . str_replace('Action', '', $className) . '的类！</font>');
					}
				}
			}
		}

		if (!empty($classContent)) {
			// 仅应用侧租户源码改写 namespace；插件租户覆写仍合并进当前类
			if ($isTenant && $tenantId !== null) {
				$classContent = self::applyTenantCompileTransforms($classContent, $type, $appName, $appPath, $tenantId, $isAddon);
			}

			// AOP 切面织入（2026-11-14）：把 #[Aspect] 标注的方法体包一层切面调用
			// 织入失败会用 php -l 自检出来并回退原文 —— 宁可没切面，不能让整类编译坏掉
			$classContent = self::applyAspects($classContent, $type, $appName);

			$tenantSuffix = ($tenantCompile && $tenantId !== null) ? ('.tenant.' . $tenantId) : '';

			if ($type == 'common') {
				$classPathName = 'common.' . $lastType . $tenantSuffix . '.class.php';
			} elseif (fileExistsCase($src) == false) {
				$classPathName = strtolower($className) . $flieMark . '.lose.' . $lastType . $tenantSuffix . '.class.php';
			} elseif ($parent && $extend) {
				$classPathName = strtolower($className) . $flieMark . '.family.' . $lastType . $tenantSuffix . '.class.php';
			} elseif ($parent && !$extend) {
				$classPathName = strtolower($className) . $flieMark . '.parent.' . $lastType . $tenantSuffix . '.class.php';
			} elseif (!$parent && $extend) {
				$classPathName = strtolower($className) . $flieMark . '.son.' . $lastType . $tenantSuffix . '.class.php';
			} else {
				$classPathName = strtolower($className) . $flieMark . '.own.' . $lastType . $tenantSuffix . '.class.php';
			}

			$writePath = $path . $classPathName;

			$classContent = preg_replace_callback('#\t*\/\/\s*hook\s+([^\s]+)#is', 'Candy\Core\Hook::processHookCallback', $classContent);

			if (is_dir($path) === false) {
				\Candy\Extend\Dir::create($path);
			}

			$tmpWrite = $writePath . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
			$written = file_put_contents($tmpWrite, $classContent);
			if ($written !== false && @rename($tmpWrite, $writePath)) {
				return $writePath;
			}
			if (is_file($tmpWrite)) {
				@unlink($tmpWrite);
			}
			if ($written !== false && file_put_contents($writePath, $classContent) !== false) {
				return $writePath;
			}
		}

		return false;
	}
}
