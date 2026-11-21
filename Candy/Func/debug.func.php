<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 调试数据打印输出函数
 * 
 * 该函数用于在调试过程中格式化输出各种类型的数据，包括数组、对象、资源和基本数据类型。
 * 支持HTML环境下的彩色高亮显示，并能自动判断环境类型选择适当的输出方式。
 * 可接受多个参数进行批量输出。
 *
 * 使用示例：
 * ```php
 * // 打印单个变量
 * P($userInfo);
 * 
 * // 打印多个变量
 * P($userInfo, $settings, $results);
 * 
 * // 打印复杂数据结构
 * P(['name' => 'test', 'data' => [1, 2, 3]]);
 * ```
 *
 * @param mixed ...$args 可变参数，要打印的数据，支持任意数量和类型的参数
 * @return void 无返回值，直接输出格式化的数据
 * 
 * @note
 * - 在Web环境下使用彩色HTML格式输出，便于阅读
 * - 在CLI环境下使用普通文本格式输出
 * - 对于数组和对象使用print_r，对于其他类型使用var_dump
 * - 如果没有提供参数，会输出错误提示
 */
function P(...$args): void
{
	if(count($args)<1){
		\Candy\Core\Debug::addMsg('<font color=\'red\'>必须为p()函数提供参数!</font>');
	}else{
		ob_start();
		if(is_null(C('API'))){
			$style = '<style>
				pre#debug{margin:10px;font-size:14px;color:#222;font-family:Consolas ;line-height:1.2em;background:#f6f6f6;border-left:5px solid #444;padding:5px;width:95%;word-break:break-all;}
				pre#debug b{font-weight:400;}
				#debug #debug_str{color:#E75B22;}
				#debug #debug_keywords{font-weight:800;color:00f;}
				#debug #debug_tag1{color:#22f;}
				#debug #debug_tag2{color:#f33;font-weight:800;}
				#debug #debug_var{color:#33f;}
				#debug #debug_var_str{color:#f00;}
				#debug #debug_set{color:#0C9CAE;}</style>';
			foreach($args as $arg){
				if (is_array($arg) || is_object($arg)){
					print_r($arg);
					echo '<br>';
				} else if (is_resource($arg)){
					echo (string)$arg . '<br>';
				} else {
					var_dump($arg);
					echo '<br>';
				}
			}
			$out = ob_get_clean(); //缓冲输出给$out 变量
			$out = preg_replace('/"(.*)"/', '<b id="debug_var_str">"' . '\\1' . '"</b>', $out); //高亮字符串变量
			$out = preg_replace('/=\>(.*)/', '=>' . '<b id="debug_str">' . '\\1' . '</b>', $out); //高亮=>后面的值
			$out = preg_replace('/\[(.*)\]/', '<b id="debug_tag1">[</b><b id="debug_var">' . '\\1' . '</b><b id="debug_tag1">]</b>', $out); //高亮变量
			$out = str_replace(['    ', '(', ')', '=>'], ['  ', '<b id="debug_tag2">(</i>', '<b id="debug_tag2">)</b>', '<b id="debug_set">=></b>'], $out);
			$keywords = ['Array', 'int', 'string', 'object', 'null']; //关键字高亮
			$keywordsTo = $keywords;
			foreach($keywords as $key => $val){
				$keywordsTo[$key] = '<b id="debug_keywords">' . $val . '</b>';
			}
			$out = str_replace($keywords, $keywordsTo, $out);
			$out = str_replace("\n\n", "\n", $out);
			$out = $style . '<pre id="debug">' . $out . '</pre>';;
		}else{
			echo '<pre>';
			foreach($args as $arg){
				if (is_array($arg) || is_object($arg)){
					print_r($arg);
					echo '<br>';
				} else if (is_resource($arg)){
					echo (string)$arg . '<br>';
				} else {
					var_dump($arg);
					echo '<br>';
				}
			}
			$out = ob_get_clean();
		}
		\Candy\Core\Respond::buff($out);
	}
}

/**
 * 关闭调试模式
 *
 * @param $falg	调式模式的关闭开关
 */
function debug(int $flag = 0): void
{
	$GLOBALS['debug'] = $flag;
}

/**
 * 内存使用计算函数
 *
 * 该函数用于计算并格式化当前PHP脚本的内存使用情况。默认会使用框架初始化时记录的
 * 起始内存值（存储在C('startMemory')中）作为基准，计算内存增量，并调用sizeCount()
 * 函数将字节数格式化为人类可读的大小格式（如KB、MB等）。适用于性能分析和调试场景。
 *
 * @param int $startMemory 起始内存值（字节），默认为0（此时会使用框架初始化时记录的值）
 * @return string 格式化后的内存使用情况字符串，如 "1.25 MB"
 * 
 * @example
 * ```php
 * // 使用默认起始内存值（框架初始化时的内存占用）
 * $memoryUsed = usageMemory();
 * echo "当前脚本使用内存: $memoryUsed";
 * 
 * // 手动指定起始内存值（用于测量代码块内存消耗）
 * $startMem = memory_get_usage();
 * // 执行一些内存密集型操作
 * $data = array_fill(0, 100000, 'test');
 * $memoryUsed = usageMemory($startMem);
 * echo "操作消耗内存: $memoryUsed";
 * 
 * // 在调试信息中使用
 * P(['执行时间' => microtime(true) - C('startTime'), '内存使用' => usageMemory()]);
 * ```
 * @note
 * - 函数返回的是相对于起始点的内存增量，而非绝对内存使用量
 * - 结果已经过格式化，便于阅读和展示
 * - 在性能监控和优化过程中非常有用
 */
function usageMemory(int $startMemory = 0): string
{
	$startMemory === 0 && $startMemory = C('startMemory');
	return sizeCount(memory_get_usage() - $startMemory);
}

/**
 * 日志读取函数
 * 
 * 该函数用于读取系统日志文件内容，支持指定日志路径和控制是否直接显示日志内容。
 * 函数内部调用Candy\Core\Log类的read方法来处理实际的日志读取操作。
 * 
 * @param string|null $path 日志文件路径，为null时使用默认日志路径
 * @param bool $display 是否直接显示日志内容，默认为false
 * @return string 日志文件内容
 * 
 * @example
 * ```php
 * // 读取默认日志
 * $logContent = readLog();
 * 
 * // 读取指定路径的日志
 * $errorLog = readLog('./logs/error.log');
 * 
 * // 读取日志并直接显示
 * $displayLog = readLog('./logs/system.log', true);
 * ```
 * 
 * @note
 * - 函数依赖\Candy\Core\Log类提供实际的日志读取功能
 * - 当$display为true时，日志内容会被直接输出到页面
 */
function readLog(?string $path = null,bool $display=false):string
{
	return \Candy\Core\Log::read($path, $display);
}

/**
 * 日志记录函数
 * 
 * 该函数提供了一个简单的接口用于记录系统日志信息，支持自定义日志类型和存储路径。
 * 内部调用Candy\Core\Log类的input方法处理实际的日志写入操作。
 * 适用于记录系统错误、用户操作、调试信息等各类日志数据。
 * 
 * @param string $message 要记录的日志消息内容
 * @param int $type 日志类型，默认为0（一般日志），可根据系统配置定义不同类型
 * @param string $path 日志文件路径，为空时使用默认日志路径
 * @return void 无返回值
 * 
 * @example
 * ```php
 * // 记录普通日志
 * writeLog('用户登录成功: ' . $username);
 * 
 * // 记录错误日志（类型为1）
 * writeLog('数据库连接失败: ' . mysqli_error($link), 1);
 * 
 * // 记录到自定义路径
 * writeLog('定时任务执行结果', 0, './logs/cron.log');
 * 
 * // 记录带时间戳的详细日志
 * writeLog(date('Y-m-d H:i:s') . ' - API请求: ' . $apiUrl, 0);
 * ```
 * 
 * @note
 * - 当日志消息为空时，函数不会执行任何操作
 * - 函数依赖\Candy\Core\Log类提供实际的日志写入功能
 * - 日志类型$type的具体含义取决于Log类的实现，通常0表示普通日志
 * - 建议根据不同的日志级别和类型使用不同的type值
 * - 在生产环境中，应谨慎记录敏感信息，避免日志泄露安全风险
 */
function writeLog(string $message,int $type=0,string $path = ''): void
{
	if(!empty($message))
		\Candy\Core\Log::input($message, $type, $path);
}
