<?php
/***
 * Candy框架调试模式类和异常处理核心类
 * 
 * 该类是Candy框架的核心组件之一，负责以下功能：
 * - 框架的错误捕获和处理
 * - 异常处理和格式化显示
 * - 调试信息的收集、管理和展示
 * - 脚本执行时间统计
 * - 文件加载跟踪
 * - SQL语句记录
 * - 应用程序信息收集
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @final 该类不允许被继承
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Debug {
	/** 返回当前请求的调试状态；Swoole 协程使用 Coroutine Context，其他模式使用兼容静态属性。 */
	private static function &contextState(): array
	{
		if (Request::inCoroutine()) {
			$ctx = CoroutineContext::getContext();
			if (!isset($ctx['_candy_debug'])) {
				$ctx['_candy_debug'] = [
					'includefile' => [], 'application' => [], 'info' => [], 'sqls' => [],
					'paths' => [], 'errors' => [], 'timers' => [],
				];
			}
			return $ctx['_candy_debug'];
		}
		$state = [
			'includefile' => &self::$includefile,
			'application' => &self::$application,
			'info' => &self::$info,
			'sqls' => &self::$sqls,
			'paths' => &self::$paths,
			'errors' => &self::$errors,
			'timers' => &self::$timers,
		];
		return $state;
	}

	/** 获取当前请求调试快照，供响应渲染使用。 */
	public static function snapshot(): array
	{
		$state =& self::contextState();
		return $state;
	}

	/** 清空当前请求调试状态。 */
	public static function reset(): void
	{
		$state =& self::contextState();
		foreach (['includefile', 'application', 'info', 'sqls', 'paths', 'errors', 'timers'] as $key) {
			$state[$key] = [];
		}
	}

	/**
	 * 已加载文件列表
	 * 
	 * 存储已加载的框架文件和应用文件信息
	 * 格式: ['framework' => [...], 'app' => [...]]
	 * @var array
	 */
	public static $includefile = [];
	
	/**
	 * 应用程序信息
	 * 
	 * 存储应用程序相关的调试信息
	 * @var array
	 */
	public static $application = [];
	
	/**
	 * 一般调试信息
	 * 
	 * 存储通用的调试消息
	 * @var array
	 */
	public static $info = [];
	
	/**
	 * SQL语句记录
	 * 
	 * 存储执行过的SQL语句信息
	 * @var array
	 */
	public static $sqls = [];
	
	/**
	 * 路径信息
	 * 
	 * 存储与路径相关的调试信息
	 * @var array
	 */
	public static $paths = [];
	
	/**
	 * 错误信息列表
	 * 
	 * 存储捕获到的错误信息
	 * @var array
	 */
	public static $errors = [];
	
	/**
	 * 计时器数据
	 * 
	 * 存储不同名称计时器的开始时间
	 * 格式: ['timer_name' => start_time]
	 * @var array
	 */
	private static $timers = [];
	
	/**
	 * 错误类型映射表
	 * 
	 * 将PHP错误常量映射为人类可读的错误类型名称
	 * @var array
	 */
	private static $msg = [
		E_WARNING => '运行时警告',
		E_NOTICE => '运行时提醒',
		E_USER_ERROR => '自定义错误', 
		E_USER_WARNING => '自定义警告',
		E_USER_NOTICE => '自定义提醒',
		E_DEPRECATED => '已弃用功能警告',
		'Unknown' => '未知错误'
	];
	
	/**
	 * 初始化调试器
	 * 
	 * 在脚本开始处调用此方法，执行以下初始化操作：
	 * 1. 启动默认计时器，用于记录脚本执行时间
	 * 2. 设置自定义错误处理函数
	 * 3. 设置自定义异常处理函数
	 * 4. 注册脚本关闭时的处理函数
	 * 5. 根据调试模式配置适当的错误报告级别
	 * 6. 在CLI模式下清理静态数据数组
	 *
	 * @return void
	 */
	public static function start(): void
	{
		self::startTimer();
		$get = I('get.', [], '');

		//添加一个强制刷新缓存的方法
		if(isset($get['reflush'])){
			if(!\Candy\Core\Cache::isFileDriver() && $cache = \Candy\Core\Cache::store()){
				$cache->clear();
			} 
		}

		//自定义异常处理
		set_error_handler([__CLASS__, 'catcher']);
		set_exception_handler([__CLASS__, 'exception']);
		register_shutdown_function([__CLASS__, 'shutdown']);	
		if(\isDebug()){
			error_reporting(E_ALL);
		}else{
			error_reporting(E_ALL^E_WARNING^E_NOTICE);
		}
		ini_set('display_errors', 'Off'); 		//屏蔽错误输出
		
		if(\isWebServer()){
			
			self::reset();

			// Workerman 进程常驻：必须每请求清零，否则「数据库总用时/次数」会跨请求累加，误导排查
			try {
				$dbEngine = 'Candy\\Extend\\DB\\Driver\\' . \Candy\Extend\DB\DBServer::resolveDriverClass((string) G('DRIVER'));
				if (class_exists($dbEngine)) {
					$dbEngine::$execute = 0;
					$dbEngine::$totalcate = 0;
				}
			} catch (\Throwable) {
			}
		}
	}
	
	/**
	 * 获取默认计时器的运行时间
	 * 
	 * 返回从脚本开始（调用start方法时）到当前时刻的执行时间，单位为毫秒
	 * 这是getTimer('default')方法的便捷封装
	 *
	 * @return float 执行时间（毫秒），精确到小数点后两位
	 */
	public static function spent(): float
	{
		return self::getTimer();
	}
	
	/**
	 * 可安全忽略的运行时噪音（不记录、不展示）
	 *
	 * - Workerman 停止时 stream_select 被 SIGINT 打断（EINTR / errno 4）
	 * - open_basedir / shmop 等已知无害警告
	 */
	private static function isIgnorableRuntimeNoise(string $errstr): bool
	{
		$lower = strtolower($errstr);

		if (str_contains($lower, 'open_basedir restriction')) {
			return true;
		}
		if (str_contains($lower, 'shmop_open') || str_contains($lower, 'no such file or directory')) {
			return true;
		}
		if (str_contains($lower, 'stream_select()') && (
			str_contains($lower, 'interrupted system call') ||
			str_contains($lower, 'unable to select [4]')
		)) {
			return true;
		}

		return false;
	}

	/**
	 * 错误处理函数
	 * 
	 * PHP错误发生时自动调用，捕获并根据环境记录错误信息
	 * 此方法会根据错误级别和环境决定如何处理错误：
	 * - 在调试模式下，将错误信息添加到调试消息队列
	 * - 非调试模式下，将错误记录到日志文件
	 * - 忽略某些特定类型的错误（如open_basedir限制警告）
	 *
	 * @param int $errno 错误级别，如E_WARNING、E_NOTICE等
	 * @param string $errstr 错误描述信息
	 * @param string $errfile 发生错误的文件路径
	 * @param int $errline 发生错误的行号
	 * @return bool 返回 true 表示已处理，阻止 PHP 默认错误输出
	 */
	public static function catcher(int $errno, string $errstr, string $errfile, int $errline): bool
	{
		if (self::isIgnorableRuntimeNoise($errstr) || in_array($errno, [E_NOTICE, E_USER_NOTICE], true)) {
			return true;
		}

		$errType = self::$msg[$errno] ?? self::$msg['Unknown'];
		$message = sprintf("[%s] %s in %s on line %d", $errType, trim($errstr), $errfile, $errline);
		if (\isDebug()) {
			self::addMsg("<font color='red'><b>{$errType}</b>[{$errfile}:{$errline}]: {$errstr}</font>", 4);
		} else {
			Log::input($message, -1, C('LOGPATH') . 'error-'. date('Y-m-d') .'.log');
		}

		return true;
	}
	
	/**
	 * 异常处理函数
	 * 
	 * PHP异常发生时自动调用，处理并格式化展示异常信息
	 * 此方法会对异常信息进行处理，移除框架路径前缀，使错误信息更清晰
	 * 根据执行环境（CLI或Web）以不同方式展示异常
	 *
	 * @param \Throwable $e 异常对象，包含异常信息、文件路径和行号等
	 * @return void
	 */
	public static function exception($e): void
	{
		$path = $e->getFile();
		$message = $e->getMessage();
		if (\isWebServer()) {
			echo $message . "\n";
		} else {
			if (!is_null(C('API'))) {
				$detailed = self::formatErrorDetail($e->getMessage(), $e->getFile(), $e->getLine());
			} else {
				$detailed = self::formatThrowableDetail($e);
			}
			self::showMessage($message, 'Exception', $detailed);
		}
	}
	
	/**
	 * 关闭处理函数
	 * 
	 * 脚本执行结束时自动调用，捕获并处理未被自定义错误处理器捕获的致命错误
	 * 例如：E_ERROR、E_PARSE、E_CORE_ERROR等致命错误
	 * 此方法会对错误信息进行处理，移除框架路径前缀，使错误信息更清晰
	 *
	 * @return void
	 * @since 1.0.0
	 * @uses error_get_last() 获取最后一个错误信息
	 * @uses showMessage() 展示格式化的错误信息
	 */
	public static function shutdown(): void
	{
		$error = error_get_last();

		// 2026-11-13 修复：error_get_last() 会返回任何最后一次错误（含 E_WARNING/E_NOTICE/E_DEPRECATED），
		// 原来只判 $error !== null 就当作致命错误 —— 一旦脚本结束前最后一条是 warning，
		// Web 下会直接 echo 一行然后 return，跳过 Respond::create()->send()（响应丢内容）与析构。
		$fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
		$isFatal = $error !== null
			&& in_array((int) ($error['type'] ?? 0), $fatalTypes, true)
			&& !self::isIgnorableRuntimeNoise((string) ($error['message'] ?? ''));

		if ($isFatal) {
			$path    = (string) ($error['file'] ?? '');
			$line    = (int) ($error['line'] ?? 0);
			$message = (string) ($error['message'] ?? '');

			// 致命错误：清掉半截输出，避免「半张页面 + 一行错误」
			while (ob_get_level() > 0) {
				@ob_end_clean();
			}
			Respond::$buff = '';
			@ini_set('display_errors', '0');
			if (!headers_sent()) {
				@http_response_code(500);
			}

			// 先收尾（连接 / 锁 / 临时文件），再输出错误页
			runApplicationDestructor();

			$detailed = self::formatErrorDetail($message, $path, $line);
			self::showMessage($message, 'Shutdown', $detailed);

			return;
		}

		if (empty(Respond::$buff) === false) {
			Respond::create()->send();
		}

		runApplicationDestructor();
	}
	
	/**
	 * 添加调试消息
	 * 
	 * 根据不同类型将调试消息添加到相应的存储数组中，仅在调试模式下有效
	 * 支持多种类型的调试信息分类存储，方便统一管理和展示
	 *
	 * @param string $msg 调试消息字符串，可以包含HTML标签以增强显示效果
	 * @param int $type 消息类型，用于确定消息的存储位置
	 *                 0 - 一般调试信息，存储在$info数组
	 *                 1 - 应用文件信息，存储在$includefile['app']数组
	 *                 2 - SQL语句信息，存储在$sqls数组
	 *                 3 - 路径信息，存储在$paths数组
	 *                 4 - 错误信息，存储在$errors数组
	 *                 5 - 框架文件信息，存储在$includefile['framework']数组
	 *                 6 - 应用程序信息，存储在$application数组
	 *                 9 - 静默（Autoload 内部实现类，不写入 includefile）
	 * @return void
	 */
	public static function addMsg(string $msg,int $type = 0): void
	{
		if(\isDebug()){
			$state =& self::contextState();
			match($type){
				0 => $state['info'][] = $msg,
				1 => $state['includefile']['app'][] = $msg,
				2 => $state['sqls'][] = $msg,
				3 => $state['paths'][] = $msg,
				4 => $state['errors'][] = $msg,
				5 => (function() use ($msg) {
					$state =& self::contextState();
					//补充先载入的类库
					if(empty($state['includefile']['framework'])){
						$state['includefile']['framework'] = [
							'<b> Cache </b>类',
							'<b> Autoload </b>类',
							'<b> Debug </b>类',
						];
					}
					$state['includefile']['framework'][] = $msg;
				})(),
				6 => $state['application'][] = $msg,
				default => null
			};
		}
	}
	
	/**
	 * 展示错误信息
	 * 
	 * 根据不同的执行环境（CLI/HTTP）以不同方式展示格式化的错误信息
	 * 在Web环境下，支持普通页面和API接口两种响应方式
	 * API接口支持JSON和XML两种数据格式
	 *
	 * @param string $msg 简洁的错误消息
	 * @param string $type 错误类型标识，如'Exception'、'Shutdown'等
	 * @param string $detailed 详细的错误信息，通常包含文件路径和行号
	 * @return void 此方法会终止脚本执行（在Web环境下）
	 */
	public static function showMessage(string $msg,string $type = '',string $detailed = ''): void
    {
		$msg = convertEncoding($msg);
		if (self::isIgnorableRuntimeNoise($msg)) {
			return;
		}

		$isApi = !is_null(C('API'));

		if (!$isApi && $detailed !== '' && !str_contains($detailed, 'candy-err-trace') && $type !== 'Shutdown') {
			$detailed .= self::formatBacktraceHtml();
		}

		if (\isWebServer()) {
			echo $msg;
		} else {
			if (is_null(C('API'))) {
				\showExceptionMsg('shutdown', $msg, $detailed, $type);
			} else {
				$format = C('API');

				$data['code'] = 9999;
				if ($detailed !== '') {
					$data['message'] = convertEncoding(self::toPlainText($detailed));
				} else {
					$data['message'] = $msg;
				}
				$data['sessionid'] = \session_id();

				$data = formatApiResponseData($data, (string)$format);

				respond((string)$data, 200, $format);
			}
		}
    }

	/**
	 * 格式化错误详情（HTML，用于 shutdown 错误页 detail 区域）
	 *
	 * @param string $message 错误摘要
	 * @param string|null $file 出错文件
	 * @param int|null $line 出错行号
	 * @return string
	 */
	public static function formatErrorDetail(string $message, ?string $file = null, ?int $line = null): string
	{
		$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$rows = '';

		if ($file !== null && $file !== '') {
			$rows .= '<tr><th>文件</th><td><code class="candy-err-path">' . $e(self::shortPath($file)) . '</code></td></tr>';
		}
		if ($line !== null && $line > 0) {
			$rows .= '<tr><th>行号</th><td>' . $line . '</td></tr>';
		}
		if ($message !== '') {
			$rows .= '<tr><th>说明</th><td>' . $e($message) . '</td></tr>';
		}

		if ($rows === '') {
			return '';
		}

		return '<table class="candy-err-table">' . $rows . '</table>';
	}

	/**
	 * 异常详情：基本信息 + 调用堆栈
	 *
	 * @param \Throwable $e
	 * @param int $limit 堆栈帧上限
	 * @return string
	 */
	public static function formatThrowableDetail(\Throwable $e, int $limit = 25): string
	{
		$detail = self::formatErrorDetail($e->getMessage(), $e->getFile(), $e->getLine());
		$trace = self::formatStackTraceHtml($e->getTrace(), $e->getFile(), $e->getLine(), $limit);

		return $detail . $trace;
	}

	/**
	 * 从当前调用点生成堆栈 HTML（业务错误、权限检查等无 Throwable 的场景）
	 *
	 * @param int $limit
	 * @param int $skip 跳过的栈帧数（默认跳过 showMessage 自身）
	 * @return string
	 */
	public static function formatBacktraceHtml(int $limit = 25, int $skip = 1): string
	{
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + $skip + 15);
		$trace = array_slice($trace, $skip);
		$trace = self::filterInternalFrames($trace);

		return self::formatStackTraceHtml($trace, null, null, $limit);
	}

	/**
	 * 将堆栈帧格式化为 HTML 列表
	 *
	 * @param array<int, array<string, mixed>> $trace
	 * @param string|null $throwFile 异常抛出文件（仅 Throwable 场景）
	 * @param int|null $throwLine 异常抛出行号
	 * @param int $limit
	 * @return string
	 */
	public static function formatStackTraceHtml(array $trace, ?string $throwFile = null, ?int $throwLine = null, int $limit = 25): string
	{
		$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$items = '';
		$index = 0;

		if ($throwFile !== null && $throwLine !== null && $throwLine > 0) {
			$items .= self::stackFrameItemHtml($index++, [
				'file' => $throwFile,
				'line' => $throwLine,
				'function' => '(throw)',
				'class' => '',
				'type' => '',
			], $e);
		}

		foreach (array_slice($trace, 0, $limit) as $frame) {
			$items .= self::stackFrameItemHtml($index++, $frame, $e);
		}

		if ($items === '') {
			return '';
		}

		return '<div class="candy-err-trace"><p class="candy-trace-title">调用堆栈</p><ol class="candy-trace-steps">' . $items . '</ol></div>';
	}

	/**
	 * 缩短路径显示（去掉 CANDYROOT 前缀，并将 Runtime 编译路径映射为源码路径）
	 */
	private static function shortPath(string $path): string
	{
		static $root = null;
		if ($root === null) {
			$root = defined('CANDYROOT') ? rtrim((string)CANDYROOT, '/\\') : '';
		}

		$customStreamPrefix = 'candy://#$#read#$#';
		if (str_starts_with($path, $customStreamPrefix)) {
			return '[internal script]';
		}

		$path = self::mapCompiledPath($path);

		if ($root !== '' && str_starts_with($path, $root)) {
			$short = substr($path, strlen($root));

			return $short !== '' ? $short : $path;
		}

		return $path;
	}

	/**
	 * Runtime 编译缓存路径 → Application 源码路径
	 *
	 * Runtime/Controls/Admin/  => Application/Admin/Controls/
	 * authaction.own.nonal.class.php => Auth.class.php
	 */
	private static function mapCompiledPath(string $path): string
	{
		$path = str_replace('\\', '/', $path);

		if (str_contains($path, '/Runtime/Comps/') && preg_match('#/%[^/]+\.php$#', $path)) {
			$source = self::mapTemplateCompilePath($path);
			if ($source !== null) {
				return $source;
			}
		}

		if (preg_match('#^(.*?)/Runtime/(Controls|Models|Services)/Addons/([^/]+)/(.+)$#', $path, $m)) {
			return $m[1] . '/Addons/' . $m[3] . '/' . $m[2] . '/' . self::mapCompiledBasename($m[4], $m[2]);
		}

		if (preg_match('#^(.*?)/Runtime/(Controls|Models|Services)/([^/]+)/(.+)$#', $path, $m)) {
			return $m[1] . '/Application/' . $m[3] . '/' . $m[2] . '/' . self::mapCompiledBasename($m[4], $m[2]);
		}

		return $path;
	}

	/**
	 * 模板编译缓存：从文件头 Compiled from 读取源码模板路径
	 */
	private static function mapTemplateCompilePath(string $compiledPath): ?string
	{
		if (is_readable($compiledPath) === false) {
			return null;
		}

		$head = file_get_contents($compiledPath, false, null, 0, 1024);
		if ($head === false || preg_match('/Compiled from\s+(.+?)\s*\*\//s', $head, $m) !== 1) {
			return null;
		}

		$from = trim($m[1]);
		$root = defined('CANDYROOT') ? rtrim((string)CANDYROOT, '/\\') : '';

		// 新格式：头部为相对项目根的路径，如 /Application/Admin/Views/default/Auth_index.shtml
		if (str_starts_with($from, '/')) {
			return $root !== '' ? $root . $from : $from;
		}

		// 旧格式：仅模板文件名，按 Comps 目录结构回推 Views 路径
		if ($root === '') {
			return null;
		}

		$compiledPath = str_replace('\\', '/', $compiledPath);

		if (preg_match('#^(.*?)/Runtime/Comps/Addons/([^/]+)/(.+)/%[^/]+\.php$#', $compiledPath, $cm)) {
			return $cm[1] . '/Addons/' . $cm[2] . '/Views/' . $cm[3] . '/' . $from;
		}

		if (preg_match('#^(.*?)/Runtime/Comps/([^/]+)/(.+)/%[^/]+\.php$#', $compiledPath, $cm)) {
			return $cm[1] . '/Application/' . $cm[2] . '/Views/' . $cm[3] . '/' . $from;
		}

		if (preg_match('#^(.*?)/Runtime/Comps/Addons/([^/]+)/%[^/]+\.php$#', $compiledPath, $cm)) {
			return $cm[1] . '/Addons/' . $cm[2] . '/Views/' . $from;
		}

		if (preg_match('#^(.*?)/Runtime/Comps/([^/]+)/%[^/]+\.php$#', $compiledPath, $cm)) {
			return $cm[1] . '/Application/' . $cm[2] . '/Views/' . $from;
		}

		return null;
	}

	/**
	 * 编译缓存文件名 → 源码类文件名
	 */
	private static function mapCompiledBasename(string $filename, string $category): string
	{
		if (preg_match('/^common\.[^.]+\.class\.php$/i', $filename)) {
			return 'Common.class.php';
		}

		if (!preg_match('/^(.+)\.(own|parent|son|family|lose)\.[^.]+\.class\.php$/i', $filename, $m)) {
			return $filename;
		}

		$raw = strtolower($m[1]);
		$name = match (strtolower($category)) {
			'controls' => str_ends_with($raw, 'action') ? substr($raw, 0, -6) : $raw,
			'models' => str_ends_with($raw, 'model') ? substr($raw, 0, -5) : $raw,
			'services' => str_ends_with($raw, 'service') ? substr($raw, 0, -7) : $raw,
			default => $raw,
		};

		return ucfirst($name) . '.class.php';
	}

	/**
	 * 单帧堆栈 HTML
	 *
	 * @param int $no
	 * @param array<string, mixed> $frame
	 * @param callable(string): string $e
	 * @return string
	 */
	private static function stackFrameItemHtml(int $no, array $frame, callable $e): string
	{
		$class = (string)($frame['class'] ?? '');
		$type = (string)($frame['type'] ?? '');
		$func = (string)($frame['function'] ?? '');

		if ($func === '(throw)') {
			$call = '<span class="candy-trace-call candy-trace-throw">异常抛出点</span>';
		} else {
			$callName = $class !== '' ? $class . $type . $func . '()' : $func . '()';
			$call = '<span class="candy-trace-call">' . $e($callName) . '</span>';
		}

		$file = (string)($frame['file'] ?? '');
		$line = (int)($frame['line'] ?? 0);
		$at = '';
		if ($file !== '') {
			$at = ' <span class="candy-trace-at">@</span> <code class="candy-err-path">' . $e(self::shortPath($file)) . ':' . $line . '</code>';
		}

		return '<li class="candy-trace-item"><span class="candy-trace-no">#' . $no . '</span> ' . $call . $at . '</li>';
	}

	/**
	 * 过滤错误处理内部的栈帧，保留业务调用链
	 *
	 * @param array<int, array<string, mixed>> $trace
	 * @return array<int, array<string, mixed>>
	 */
	private static function filterInternalFrames(array $trace): array
	{
		return array_values(array_filter($trace, static function (array $frame): bool {
			$class = (string)($frame['class'] ?? '');
			$func = (string)($frame['function'] ?? '');

			if ($func === 'showExceptionMsg' && $class === '') {
				return false;
			}

			if ($class === Debug::class) {
				return false;
			}

			if (str_contains($class, 'Impl\\Respond\\Exception')) {
				return false;
			}

			return true;
		}));
	}

	/**
	 * 将 HTML 详情转为 API 可用的纯文本
	 *
	 * @param string $html
	 * @return string
	 */
	public static function toPlainText(string $html): string
	{
		$text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
		$text = preg_replace('/<\/p>/i', "\n", $text) ?? $text;
		$text = preg_replace('/<\/li>/i', "\n", $text) ?? $text;
		$text = preg_replace('/<\/tr>/i', "\n", $text) ?? $text;
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}

	/**
	 * 开始计时器
	 * 
	 * 为指定名称的计时器记录开始时间点，用于后续计算代码执行时间
	 * 可以创建多个不同名称的计时器，分别跟踪不同代码块的执行时间
	 *
	 * @param string $name 计时器名称，默认为'default'
	 * @return void
	 */
	public static function startTimer(string $name = 'default'): void
	{
		$state =& self::contextState();
		$state['timers'][$name] = \microtime(true);
	}

	/**
	 * 获取计时器值
	 * 
	 * 返回指定名称计时器从开始到现在的运行时间，单位为毫秒
	 * 如果指定的计时器不存在，返回0.0
	 * 结果会被四舍五入到小数点后两位
	 *
	 * @param string $name 计时器名称，默认为'default'
	 * @return float 运行时间（毫秒），精确到小数点后两位
	 */
	public static function getTimer(string $name = 'default'): float
	{
		$state =& self::contextState();
		return isset($state['timers'][$name])  ? \round((\microtime(true) - $state['timers'][$name]) * 1000, 2) : 0.0;
	}

	/**
	 * 格式化异常信息
	 * 
	 * 将异常信息和堆栈跟踪转换为更友好、更易读的格式
	 * 特别处理包含自定义流前缀的文件路径，提高可读性
	 * 主要用于开发环境中调试复杂的异常情况
	 *
	 * @param \Throwable $exception 异常对象，包含异常信息和堆栈跟踪
	 * @return void 直接输出格式化后的异常信息到标准输出
	 */
	public static function exceptionFormat(\Throwable $exception): void
	{
		$customStreamPrefix = 'candy://#$#read#$#';
		$replacementText = '[internal script]'; // 或者 'unknown file', '', etc.

		echo "Uncaught Exception: " . $exception->getMessage() . "\n";
		echo "File: " . str_replace($customStreamPrefix, $replacementText, $exception->getFile()) . " (Original)\n"; // 原始的文件可能包含数据流头
		echo "Line: " . $exception->getLine() . "\n";
		echo "Customized Stack Trace:\n";

		$trace = $exception->getTrace();
		$firstFrame = [
			'file' => str_replace($customStreamPrefix, $replacementText, $exception->getFile()),
			'line' => $exception->getLine(),
			'function' => '???', // 无法直接获取，或者根据需要设置
			'class' => '???',
			'type' => '???'
		];
		
		if (isset($firstFrame['file']) && \str_starts_with($firstFrame['file'], $customStreamPrefix)) {
			$firstFrame['file'] = $replacementText;
		}
		
		$formattedTrace = [
			\sprintf(
				"#%d %s%s%s() at %s:%d",
				0, // 根调用通常是#0
				$firstFrame['class'] ?? '',
				$firstFrame['type'] ?? '',
				$firstFrame['function'] ?? '???',
				$firstFrame['file'] ?? 'unknown file',
				$firstFrame['line'] ?? 'unknown line'
			)
		];
		
		foreach ($trace as $index => $frame) {
			// 检查 'file' 键是否存在，并检查是否以自定义数据流头开头
			if (isset($frame['file']) && \str_starts_with($frame['file'], $customStreamPrefix)) {
				$frame['file'] = $replacementText;
			}
			
			$formattedTrace[] = \sprintf(
				"#%d %s%s%s() at %s:%d",
				$index + 1, // 堆栈深度
				$frame['class'] ?? '',
				$frame['type'] ?? '',
				$frame['function'] ?? '???', // 如果没有function字段，或者想隐藏
				$frame['file'] ?? 'unknown file',
				$frame['line'] ?? 'unknown line'
			);
		}

		// 输出格式化后的堆栈跟踪
		echo str_replace($customStreamPrefix, '', \implode("\n", $formattedTrace)) . "\n";
	}
}
