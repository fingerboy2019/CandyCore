<?php
/**
 * RuntimeGuard 类 - Candy 框架 Runtime 目录权限守卫
 *
 * 在 Web / Workerman（php index.php）等非 candy 命令行场景下，
 * 校验 Runtime 及其关键子目录是否具备读写权限，避免因目录属主错误
 *（如 root 启动 Workerman 导致 www 无法写 Logs）而在运行期静默失败。
 *
 * 主要功能：
 * - 校验 Runtime 根目录及标准子目录的存在性、可读性、可写性
 * - 自动尝试创建缺失的标准子目录（Cache、Logs、Comps 等）
 * - 在进程具备 chmod/chown 能力时自动修复目录权限（目标模式 0775）
 * - 将运行模式指纹写入状态文件，模式未变时跳过完整扫描以提升性能
 * - 无法自动修复时通过 Debug::showMessage 输出，与框架 shutdown / API 错误格式一致
 *
 * 运行模式指纹由以下维度组成：
 * - sapi：PHP 运行方式（cli / fpm-fcgi 等）
 * - user：当前进程用户名与 UID（如 www:1000）
 * - entry：入口脚本或 Web 服务器标识
 *
 * 使用场景：
 * - Bootstrap::boot() 阶段的环境自检（须已完成首次初始化，见 init.lock）
 * - Workerman Worker 首次请求或运行模式切换后的权限复核
 * - 部署后排查 Runtime 目录属主/权限问题
 *
 * 首次安装说明：
 * - 框架未完成初始化（无 init.lock / APP_INIT_STATUS）时自动跳过检查
 * - 此时由 Build::start() 在 route 阶段创建 Runtime，避免拦截安装流程
 *
 * 状态文件：{RUNTIME}/.runtime-guard.json
 * 手动触发重检：删除上述状态文件或切换运行用户后重启服务
 *
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-11 17:40:00
 * @final 该类不允许被继承
 */
declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class RuntimeGuard
{
	/**
	 * 运行模式状态文件名（存放于 RUNTIME 根目录）
	 *
	 * JSON 结构示例：
	 * {
	 *   "mode": {"sapi":"cli","user":"www:1000","entry":"index.php"},
	 *   "fingerprint": "84c92c49ab03b0ee...",
	 *   "checked_at": "2026-07-11 17:37:56"
	 * }
	 *
	 * @var string
	 */
	private const STATE_FILE = '.runtime-guard.json';

	/**
	 * 必须存在的 Runtime 标准子目录（相对于 RUNTIME 根路径）
	 *
	 * 与 Build::runtime() 保持一致，缺失时会尝试通过 Dir::create() 自动创建。
	 *
	 * @var string[]
	 */
	private const SUB_DIRS = [
		'Cache',
		'Comps',
		'Controls',
		'Models',
		'Services',
		'Logs',
		'Tmps',
		'Data/cache',
	];

	/**
	 * Runtime 目录目标权限（八进制）
	 *
	 * 与 Build::runtime() 创建目录后的 chmod 策略对齐，并允许同组进程写入 Logs 等目录。
	 *
	 * @var int
	 */
	private const DIR_MODE = 0775;

	/**
	 * 当前 PHP 进程内是否已执行过 verify()
	 *
	 * 避免同一请求 / Worker 生命周期内重复读取状态文件或扫描目录。
	 *
	 * @var bool
	 */
	private static bool $verified = false;

	/**
	 * 判断当前场景是否需要进行 Runtime 权限检查
	 *
	 * 以下情况跳过检查：
	 * - 框架尚未完成首次初始化（无 init.lock），由 Build::start() 负责创建 Runtime
	 * - candy 命令行工具（php candy start/restart 等）
	 *
	 * 对已完成初始化的 Web SAPI、php index.php、Workerman HTTP 请求返回 true。
	 *
	 * @return bool 需要检查时返回 true
	 */
	public static function shouldCheck(): bool
	{
		if (!self::isFrameworkInitialized()) {
			return false;
		}

		if (PHP_SAPI !== 'cli') {
			return true;
		}

		$script = basename((string)($_SERVER['argv'][0] ?? ''));
		return in_array($script, ['candy', 'candy.bat'], true) === false;
	}

	/**
	 * 判断框架是否已完成首次初始化
	 *
	 * 以 C('APP_INIT_STATUS') 为准，该值在 Autoload 阶段根据 Runtime/init.lock 是否存在设置。
	 * 首次安装、访问 ?start 引导页时尚未生成 init.lock，不应执行权限拦截。
	 *
	 * @return bool 已初始化返回 true
	 */
	private static function isFrameworkInitialized(): bool
	{
		return C('APP_INIT_STATUS') === true;
	}

	/**
	 * 执行 Runtime 目录权限校验（入口方法）
	 *
	 * 执行顺序：
	 * 1. 若当前进程已校验过、框架未初始化、或属于 candy 命令行，直接返回
	 * 2. 读取状态文件，运行模式指纹与上次一致则跳过完整扫描
	 * 3. 否则遍历 Runtime 及子目录，先尝试自动修复权限，再收集仍无法修复的错误
	 * 4. 校验通过后写入状态文件；失败则委托 Debug 展示错误并 stop()
	 *
	 * @param string|null $runtime Runtime 根路径，为空时使用 C('RUNTIME')
	 * @return void
	 */
	public static function verify(?string $runtime = null): void
	{
		if (self::$verified || !self::shouldCheck()) {
			return;
		}
		self::$verified = true;

		$runtime = rtrim($runtime ?: (string)C('RUNTIME'), DS) . DS;
		$mode = self::currentMode();

		if (self::isModeUnchanged($runtime, $mode)) {
			return;
		}

		$errors = self::inspectPaths($runtime);

		if ($errors !== []) {
			self::abort($errors, $runtime);
		}

		self::saveModeState($runtime, $mode);
	}

	/**
	 * 采集当前运行模式信息
	 *
	 * @return array{sapi: string, user: string, entry: string}
	 */
	private static function currentMode(): array
	{
		return [
			'sapi' => PHP_SAPI,
			'user' => self::processUser(),
			'entry' => self::entryScript(),
		];
	}

	/**
	 * 根据运行模式数组生成唯一指纹
	 *
	 * 使用 xxh128 对 JSON 序列化结果哈希，用于与状态文件中的 fingerprint 比对。
	 *
	 * @param array{sapi: string, user: string, entry: string} $mode 运行模式
	 * @return string 十六进制指纹字符串
	 */
	private static function modeFingerprint(array $mode): string
	{
		return hash('xxh128', json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	/**
	 * 判断运行模式是否与上次校验时一致
	 *
	 * 状态文件不存在、不可读或 JSON 解析失败时视为模式已变化，需重新检查。
	 *
	 * @param string $runtime Runtime 根路径（以 DS 结尾）
	 * @param array{sapi: string, user: string, entry: string} $mode 当前运行模式
	 * @return bool 指纹一致返回 true，表示可跳过完整扫描
	 */
	private static function isModeUnchanged(string $runtime, array $mode): bool
	{
		$stateFile = $runtime . self::STATE_FILE;
		if (!is_file($stateFile) || !is_readable($stateFile)) {
			return false;
		}

		$state = json_decode((string)file_get_contents($stateFile), true);
		if (!is_array($state)) {
			return false;
		}

		return ($state['fingerprint'] ?? '') === self::modeFingerprint($mode);
	}

	/**
	 * 将校验通过的运行模式写入状态文件
	 *
	 * 写入失败不影响本次请求（静默 @ 抑制），下次启动会重新执行完整检查。
	 *
	 * @param string $runtime Runtime 根路径（以 DS 结尾）
	 * @param array{sapi: string, user: string, entry: string} $mode 当前运行模式
	 * @return void
	 */
	private static function saveModeState(string $runtime, array $mode): void
	{
		$payload = [
			'mode' => $mode,
			'fingerprint' => self::modeFingerprint($mode),
			'checked_at' => date('Y-m-d H:i:s'),
		];

		@file_put_contents(
			$runtime . self::STATE_FILE,
			json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
			LOCK_EX
		);
	}

	/**
	 * 获取当前进程的运行用户标识
	 *
	 * Linux 下返回 "用户名:UID"（如 www:1000），
	 * 无 posix 扩展时回退为 get_current_user()。
	 *
	 * @return string 进程用户标识
	 */
	private static function processUser(): string
	{
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$uid = posix_geteuid();
			$info = posix_getpwuid($uid);
			return ($info['name'] ?? 'unknown') . ':' . $uid;
		}

		return get_current_user();
	}

	/**
	 * 获取当前入口脚本或 Web 服务器标识
	 *
	 * CLI 下取 argv[0] 的文件名；Web SAPI 下取 SERVER_SOFTWARE 或 SAPI 名称。
	 *
	 * @return string 入口标识
	 */
	private static function entryScript(): string
	{
		if (PHP_SAPI === 'cli') {
			return basename((string)($_SERVER['argv'][0] ?? 'index.php'));
		}

		return 'web:' . (string)($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI);
	}

	/**
	 * 扫描并校验所有需检查的目录路径
	 *
	 * 对每条路径：不存在则创建 → 尝试自动修复权限 → 校验可读可写。
	 * 仅在进程无权 chmod/chown 或修复后仍不满足要求时才记入错误列表。
	 *
	 * @param string $runtime Runtime 根路径（以 DS 结尾）
	 * @return string[] 错误描述列表，空数组表示全部通过
	 */
	private static function inspectPaths(string $runtime): array
	{
		if (!is_dir($runtime)) {
			\Candy\Extend\Dir::create($runtime, self::DIR_MODE);
		}
		self::repairPermissions($runtime);

		$paths = self::collectPaths($runtime);
		$errors = [];

		foreach ($paths as $path) {
			if (!is_dir($path)) {
				if (in_array($path, self::requiredPaths($runtime), true)) {
					\Candy\Extend\Dir::create($path, self::DIR_MODE);
				}
			}

			self::repairPermissions($path);

			if (!is_dir($path)) {
				$errors[] = "目录不存在：{$path}";
				continue;
			}
			if (!is_readable($path)) {
				$errors[] = "目录不可读：{$path}" . self::ownerHint($path);
				continue;
			}
			if (!is_writable($path)) {
				$errors[] = "目录不可写：{$path}" . self::ownerHint($path);
			}
		}

		return $errors;
	}

	/**
	 * 在进程具备权限时自动修复目录属主与 mode
	 *
	 * 修复策略（按序尝试，任一成功即可）：
	 * 1. 目录属主为当前进程用户 → chmod 为 DIR_MODE
	 * 2. 当前进程为 root → chown 为当前 UID 后再 chmod（修正误用 root 创建的目录）
	 *
	 * 属主为其他用户且当前进程非 root 时无法修复，由 inspectPaths 收集错误。
	 *
	 * @param string $path 目录路径
	 * @return bool 修复后目录可读且可写返回 true
	 */
	private static function repairPermissions(string $path): bool
	{
		if (!is_dir($path)) {
			return false;
		}

		if (is_readable($path) && is_writable($path)) {
			return true;
		}

		$euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
		$ownerUid = function_exists('fileowner') ? (int)fileowner($path) : null;

		if ($euid !== null && $ownerUid === $euid) {
			@chmod($path, self::DIR_MODE);
		} elseif ($euid === 0 && function_exists('chown')) {
			@chown($path, $euid);
			@chmod($path, self::DIR_MODE);
		}

		return is_readable($path) && is_writable($path);
	}

	/**
	 * 收集待检查的目录路径列表
	 *
	 * 包含标准子目录及 Runtime 下已存在的一级子目录（如 Comps、Logs 等）。
	 *
	 * @param string $runtime Runtime 根路径（以 DS 结尾）
	 * @return string[] 去重后的目录路径数组（均以 DS 结尾）
	 */
	private static function collectPaths(string $runtime): array
	{
		$paths = self::requiredPaths($runtime);

		foreach (glob($runtime . '*', GLOB_ONLYDIR) ?: [] as $dir) {
			$paths[] = rtrim((string)$dir, DS) . DS;
		}

		return array_values(array_unique($paths));
	}

	/**
	 * 构建必须检查的标准目录路径列表
	 *
	 * @param string $runtime Runtime 根路径（以 DS 结尾）
	 * @return string[] 标准目录路径数组
	 */
	private static function requiredPaths(string $runtime): array
	{
		$paths = [ $runtime ];
		foreach (self::SUB_DIRS as $sub) {
			$paths[] = $runtime . str_replace('/', DS, $sub) . DS;
		}
		return $paths;
	}

	/**
	 * 生成目录属主与当前进程用户的对比提示
	 *
	 * 用于错误信息中辅助定位 root/www 属主不一致问题。
	 *
	 * @param string $path 目录路径
	 * @return string 中文提示后缀，无法获取属主时返回空字符串
	 */
	private static function ownerHint(string $path): string
	{
		if (!function_exists('fileowner')) {
			return '';
		}

		$owner = (string)fileowner($path);
		if (function_exists('posix_getpwuid')) {
			$info = posix_getpwuid((int)fileowner($path));
			$owner = $info['name'] ?? $owner;
		}

		return "（属主：{$owner}，当前进程用户：" . self::processUser() . '）';
	}

	/**
	 * 输出权限错误信息并终止执行
	 *
	 * 委托 Debug::showMessage / showExceptionMsg 统一展示，
	 * 与框架 shutdown、异常处理保持一致（含 API JSON 格式）。
	 *
	 * @param string[] $errors 错误描述列表
	 * @param string $runtime Runtime 根路径（以 DS 结尾）
	 * @return never
	 */
	private static function abort(array $errors, string $runtime): never
	{
		$runtimeDir = rtrim($runtime, DS);
		$summary = 'Runtime 目录权限检查未通过，框架无法正常运行';
		$detailed = self::formatAbortDetail($errors, $runtimeDir);

		Debug::showMessage($summary, 'RuntimeGuard', $detailed);
		stop();
	}

	/**
	 * 格式化权限错误详情（Web 为 HTML，API 为纯文本）
	 *
	 * @param string[] $errors 错误描述列表
	 * @param string $runtimeDir Runtime 绝对路径
	 * @return string
	 */
	private static function formatAbortDetail(array $errors, string $runtimeDir): string
	{
		if (!is_null(C('API'))) {
			return self::formatAbortPlain($errors, $runtimeDir);
		}

		return self::formatAbortHtml($errors, $runtimeDir);
	}

	/**
	 * 纯文本格式（API / JSON 响应）
	 *
	 * @param string[] $errors
	 * @param string $runtimeDir
	 * @return string
	 */
	private static function formatAbortPlain(array $errors, string $runtimeDir): string
	{
		return \Candy\Core\Debug::toPlainText(self::formatAbortHtml($errors, $runtimeDir));
	}

	/**
	 * HTML 格式（shutdown 错误页）
	 *
	 * @param string[] $errors
	 * @param string $runtimeDir
	 * @return string
	 */
	private static function formatAbortHtml(array $errors, string $runtimeDir): string
	{
		$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$runtimeEsc = $e($runtimeDir);
		$userEsc = $e(self::processUser());
		$cmd = 'chown -R www:www ' . $runtimeDir . ' &amp;&amp; chmod -R 775 ' . $runtimeDir;

		$errorList = '';
		foreach ($errors as $error) {
			$errorList .= '<li>' . $e($error) . '</li>';
		}

		return <<<HTML
<p style="margin:0 0 10px;font-weight:600;color:#b91c1c;">以下目录权限异常</p>
<ul style="margin:0 0 18px;padding-left:1.4em;list-style:disc;">{$errorList}</ul>
<p style="margin:0 0 8px;font-weight:600;color:#334155;">处理建议</p>
<ol style="margin:0;padding-left:1.4em;">
<li style="margin-bottom:8px;">确认 Web / Workerman 进程用户 <strong>{$userEsc}</strong> 对目录拥有读写权限：<br><code class="candy-err-path">{$runtimeEsc}</code></li>
<li style="margin-bottom:8px;">在服务器终端执行：<pre>{$cmd}</pre></li>
<li>请勿使用 <strong>root</strong> 启动 Workerman；若目录属主为 root，需 root 执行 chown 后再以 www 启动</li>
</ol>
HTML;
	}
}
