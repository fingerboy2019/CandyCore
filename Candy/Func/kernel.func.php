<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 安全的常量定义函数
 *
 * 该函数提供了一个安全的方式来定义常量，只有当常量未被定义时才进行定义操作，
 * 避免重复定义常量导致的PHP警告错误。
 *
 * 使用示例：
 * ```php
 * // 定义应用路径常量
 * doDefine('APP_PATH', '/path/to/application');
 * 
 * // 定义版本号常量
 * doDefine('VERSION', '1.0.0');
 * ```
 *
 * @param string $key 常量名称
 * @param mixed $value 常量值
 * @return void
 */
function doDefine(string $key,mixed $value): void
{
	defined($key) OR define($key, $value);
}

/**
 * 判断是否处于调试模式
 *
 * 该函数用于检测当前应用是否运行在调试模式下。调试模式需要同时满足DEBUG常量
 * 定义为1且全局debug变量设置为1的条件。
 *
 * 使用示例：
 * ```php
 * if (isDebug()) {
 *     // 调试模式下的代码逻辑
 *     var_dump($debugInfo);
 * } else {
 *     // 生产环境下的代码逻辑
 *     logError($errorMsg);
 * }
 * ```
 *
 * @return boolean 如果处于调试模式返回true，否则返回false
 */
function isDebug(): bool
{
	return (defined('DEBUG') && DEBUG == 1 && (isset($GLOBALS['debug']) && $GLOBALS['debug'] === 1)) ? true : false;
}

/**
 * 程序终止函数 - exit()方法重写
 *
 * 该函数提供了更加灵活的程序终止机制，会触发应用程序停止事件监听，
 * 并根据运行环境（Web服务器或CLI）采用不同的终止策略。在Web服务器环境下返回控制，
 * 在命令行环境下直接调用exit()终止程序。
 *
 * @param string $msg 可选的退出消息，仅在CLI环境下有效
 * @return void Web服务器环境下返回void，CLI环境下不会返回
 * 
 * @example
 * ```php
 * // 基本使用 - 在Web环境中终止程序
 * stop();
 * 
 * // 带消息的终止 - 仅在CLI环境下有效
 * stop('程序执行完毕');
 * 
 * // 在条件判断后终止程序
 * if ($error) {
 *     P('发生错误，终止执行');
 *     stop();
 * }
 * ```
 * 
 * @note
 * - 在Web服务器环境下，函数只是返回控制流，而不会真正终止PHP进程
 * - 函数会触发'app_stop'事件，可通过listen()函数注册回调处理程序
 * - 适用于调试和正常程序流程控制场景
 */
function stop(string $msg = ' ')
{
	//监听异常退出
	listen('app_stop');
	
	if(isWebServer()){
		if($msg != ' ') echo $msg;
		throw new \Exception('jump_exit');
	}else{
		exit($msg);
	}
}

/**
 * 打印数据并终止程序执行
 *
 * 该函数是一个调试辅助函数，用于在开发过程中打印变量数据并立即终止程序执行，
 * 内部会调用P函数进行格式化输出，然后调用stop函数终止程序流程。
 * 适用于调试过程中快速查看变量内容并中断执行的场景。
 *
 * 使用示例：
 * - 打印单个变量并中断: halt($userInfo);
 * - 打印多个变量并中断: halt($userInfo, $orderData, $config);
 * - 打印表达式结果并中断: halt($result === true ? '成功' : '失败');
 *
 * @param mixed ...$args 可变参数，要打印的变量或表达式
 * @return void 无返回值，函数会终止程序执行
 */
function halt(...$args): void
{
	P(...$args);
	stop();
}

/**
 * 显示模板化信息
 *
 * 该函数用于显示框架预定义的模板化信息，支持CLI模式和Web模式。
 * 可用于显示调试信息、错误信息、状态信息等，提供统一的信息展示方式。
 *
 * 使用示例：
 * ```php
 * // 显示默认调试信息
 * showExceptionMsg();
 * 
 * // 显示错误信息
 * showExceptionMsg('error', '操作失败', ['code' => 500, 'message' => '服务器内部错误'], 'danger');
 * 
 * // 显示成功信息并终止程序
 * showExceptionMsg('success', '操作成功', [], 'success', true);
 * ```
 *
 * @param string $name 模板名称，默认为'debug'
 * @param string|null $msg 提示信息，默认为空字符串
 * @param mixed $detailed 详细信息，可以是任意类型，默认为空字符串
 * @param string $type 信息类型，用于样式区分，默认为空字符串
 * @param bool $die 是否在显示后终止程序，默认为false
 * @return void
 */
function showExceptionMsg(string $name = 'shutdown', mixed $msg = '', string $detailed = '', string $type = '', bool $die = false): void
{
	if(isWebServer()){
		echo $msg . "\n";
	}else{
		\Candy\Core\Container::getInstance()->invokeClass('\\Candy\\Core\\Impl\\Respond\\Exception', [$name, [$msg, $detailed, $type, $die]]);
	}
}

/**
 * 框架析构函数
 * 
 * 该函数在请求/脚本收尾时调用（Respond::send、Debug::shutdown），HTTP 请求按请求 UUID 幂等。
 * 主要负责：
 * 1. FPM 下刷盘 DbMetrics 跨请求指标
 * 2. 条件性写入访问日志（actInfo.Log）
 *
 * @return void 无返回值
 *
 * @note HTTP 按请求 UUID 幂等；无 UUID 的 CLI 脚本按进程仅执行一次
 */
function runApplicationDestructor(): void
{
	$requestUuid = function_exists('I')
		? trim((string) I('server.REDIRECT_REQUEST_UUID', '', ''))
		: '';
	if ($requestUuid !== '' && function_exists('T')) {
		if (T('APPLICATION_DESTRUCTOR_RAN') === true) {
			return;
		}
		T('APPLICATION_DESTRUCTOR_RAN', true);
	} else {
		static $ranWithoutRequestUuid = false;
		if ($ranWithoutRequestUuid) {
			return;
		}
		$ranWithoutRequestUuid = true;
	}

	if (class_exists(\Candy\Extend\DB\DbMetrics::class)) {
		try {
			\Candy\Extend\DB\DbMetrics::flush();
		} catch (\Throwable) {
			// 指标失败不影响主流程
		}
	}

	if (function_exists('addLogs') && C('actInfo.Log') === true) {
		addLogs();
	}
}

/**
 * 命名空间补全函数
 * 
 * 该函数用于自动补全类名的命名空间，支持框架内置的核心类库、扩展类库和二级库的自动命名空间解析。
 * 函数使用静态数组缓存已解析的类名，提高性能，并支持直接使用已存在类的完整类名。
 * 
 * @param string $className 类名，可以是不含命名空间的简单类名或已包含命名空间的完整类名
 * @return string 返回包含完整命名空间的类名
 * 
 * @example
 * ```php
 * // 补全核心类库命名空间
 * $fullClass = replenishNamespace('Debug');
 * // 返回: \Candy\Core\Debug
 * 
 * // 补全扩展类库命名空间
 * $fullClass = replenishNamespace('Arr');
 * // 返回: \Candy\Extend\Arr
 * 
 * // 补全二级类库命名空间
 * $fullClass = replenishNamespace('Snoopy');
 * // 返回: \Extend\Snoopy
 * 
 * // 已包含命名空间的类名不变
 * $fullClass = replenishNamespace('\My\Custom\Class');
 * // 返回: \My\Custom\Class
 * ```
 * 
 * @note
 * - 函数会缓存已解析的类名，避免重复解析
 * - 对于已存在的类，直接返回原类名
 * - 对于包含命名空间分隔符'\\'的类名，不进行处理
 * - 支持的命名空间映射包括：
 *   - 核心库：\Candy\Core\
 *   - 一级扩展库：\Candy\Extend\
 *   - 二级库：\Candy\Extend\DB\、\Candy\Extend\Network\等
 */
function replenishNamespace($className): string
{
	static $fullName = [];	//已经解析过的全名
	
	//解析过的
	if(isset($fullName[$className])) return $fullName[$className];
	
	//存在的不需要解析的
	if(class_exists($className)){
		$fullName[$className] = $className;
		return $className;
	}
	
	//有空间名的不变动
	if(stripos($className, '\\') != false) return $className;
	
	$key = $className;
	
	$prefix = match($className) {
		//核心库 - 多值匹配
		'Action', 'Addon', 'Debug', 'Log', 'Node',
		'Respond', 'Stream', 'Security', 'View' => '\\Candy\\Core\\',
		
		//一级库
		'Arr', 'Dir', 'Env', 'File', 'HashMap', 'Hashids', 'JwtToken', 'Mcrypt',
		'Queue', 'Session', 'Shmop', 'Snowflake', 'Str', 'Time', 'Tree', 'Xml' => '\\Candy\\Extend\\',
		
		//二级库 DB
		'DBServer', 'DBService' => '\\Candy\\Extend\\DB\\',
		
		// 二级库 Network：短名 Http → Candy\Extend\Network\Http（出站 cURL）
		// 对照：Workerman\Http\Http=入站适配；Workerman\Protocols\Http=上游协议；Workerman\Http\Client=异步出站
		'Client', 'CurlMulti', 'Http', 'WebSocket' => '\\Candy\\Extend\\Network\\',

		// 二级库 Network\Mail：短名 Smtp → Candy\Extend\Network\Mail\Smtp（发信；收信见 Mail\Client）
		'Smtp' => '\\Candy\\Extend\\Network\\Mail\\',
		
		//默认：Vendor/Extend → APP\Extend
		default => match (true) {
			is_file(CANDYPATH . 'Candy/Vendor/Extend/' . $className . '.class.php')
				|| is_file(CANDYPATH . 'Candy/Vendor/Extend/' . $className . '.php')
				=> '\\Extend\\',
			is_file(CANDYPATH . 'Source/Class/' . $className . '.class.php')
				=> '\\APP\\Extend\\',
			default => '',
		},
	};
	$className = $prefix . $className;
	
	if($key != $className) $fullName[$key] = $className;
	
	return $className;
}
