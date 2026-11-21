<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

function respond(string $data,int $code = 200,string $format = 'html',bool $terminate = true): void
{
	if(isset($_SERVER['GATEWAY_CLIENT_ID'])){
		// 修复：$data 是字符串，但这里尝试作为数组操作
		// 应该将 $data 解析为数组或保持原样
		$responseData = $data;
		if(!empty($_SERVER['HTTP_SOCKET_EXTEND']) && is_array($responseData)){
			foreach($_SERVER['HTTP_SOCKET_EXTEND'] as $key=>$value){
				$responseData[$key] = $value;
			}
		}

		//返回信息
		\GatewayWorker\Lib\Gateway::sendToClient($_SERVER['GATEWAY_CLIENT_ID'], json($responseData));
	}else{
		\Candy\Core\Respond::create($data, $format, $code)->send();
	}

	// $terminate = false 时只发响应、不中断流程：让调用方（View::display）正常返回，
	// 使 Action::working() 尾部的 Finish 中间件与 app_end 钩子得以执行。
	// 拦截类调用点（UrlParser / StaticAssetGuard / Markdown）保持默认 true，依赖 stop() 阻断后续。
	if($terminate){
		stop();
	}
}

/**
 * 渲染模板并输出 HTTP 响应
 *
 * 通过 Respond\View 组合 ViewRenderer 完成渲染。在控制器方法内调用时，
 * 会复用当前 Action 的 ViewRenderer，与先前的 $this->assign() 共享变量。
 *
 * @param string|null $template 模板名，null 时使用当前 control/action 默认模板
 * @param array<string, mixed> $vars 模板变量
 * @param string $data 传入 Respond::init 的附加数据
 * @param int $code HTTP 状态码
 * @return \Candy\Core\Impl\Respond\View 响应对象（已执行 output，可用 getContent() 取 HTML）
 *
 * @example
 * ```php
 * // 控制器内：先 assign 再渲染指定片段模板
 * $this->assign('title', '系统参数');
 * view('info-system');
 *
 * // 批量变量 + 默认模板
 * view(null, ['name' => 'Candy']);
 *
 * // 指定模板与状态码
 * view('Public/404', [], '', 404);
 * ```
 */
function view(?string $template = null, $vars = [], string $data = '', int $code = 200): \Candy\Core\Impl\Respond\View
{
	/** @var \Candy\Core\Impl\Respond\View $response */
	$response = \Candy\Core\Respond::create($template, 'view', $code);

	if (is_array($vars) && $vars !== []) {
		$response->assign($vars);
	}

	$response->output($data);

	return $response;
}

/**
 * 设置API响应数据并返回
 *
 * 该函数用于设置API响应数据到系统全局变量中，
 * 并同时返回传入的数据数组，方便链式调用。
 * 主要用于API接口开发场景，确保响应数据被正确记录和处理。
 *
 * 使用示例：
 * - 设置API响应数据: apiData(['code' => 200, 'message' => 'success', 'data' => $result])
 * - 链式调用: return apiData(['code' => 200, 'message' => 'success']);
 *
 * @param array $data API响应数据数组，通常包含code、message和data等字段
 * @return array 返回传入的数据数组，便于链式调用
 */
function apiData(array $data):array
{
	C('APIDATA', $data);
	return $data;
}

/**
 * 从 API 响应数组中取出并执行自定义格式化闭包
 *
 * 用于 C('API') 为非 json/xml 的扩展格式，键名 `formatFunction`。
 *
 * @param array $data 响应数据
 * @return mixed 闭包处理后的结果；未设置闭包时返回原数组
 */
function applyApiFormatFunction(array $data): mixed
{
	if (!isset($data['formatFunction']) || !$data['formatFunction'] instanceof \Closure) {
		return $data;
	}
	$formatFunction = $data['formatFunction'];
	unset($data['formatFunction']);
	return $formatFunction($data);
}

/**
 * 按当前 API 协议格式化标准响应数据
 *
 * JSON、XML 使用框架内置编码器；其他格式复用 APIDATA 中注册的
 * formatFunction 闭包，确保正常响应与异常响应遵循同一协议。
 *
 * @param array $data 标准 API 响应数组
 * @param string|null $format API 格式，默认读取 C('API')
 * @return string
 */
function formatApiResponseData(array $data, ?string $format = null): string
{
	$format = strtolower(trim((string)($format ?? C('API') ?? 'json')));
	$apiData = C('APIDATA');
	if (!isset($data['formatFunction'])
		&& is_array($apiData)
		&& isset($apiData['formatFunction'])
		&& $apiData['formatFunction'] instanceof \Closure
	) {
		$data['formatFunction'] = $apiData['formatFunction'];
	}

	if ($format === 'json') {
		unset($data['formatFunction']);
		return (string)json_encode(
			$data,
			JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}
	if ($format === 'xml') {
		unset($data['formatFunction']);
		return \Candy\Extend\Xml::arrayToXml($data);
	}

	$result = applyApiFormatFunction($data);
	if (is_string($result) || is_numeric($result)) {
		return (string)$result;
	}
	if ($result instanceof \Stringable) {
		return (string)$result;
	}

	return (string)json_encode(
		$result,
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
	);
}

/**
 * JSON编码与解码函数
 * 
 * 提供增强版的JSON处理功能，支持Unicode字符集，自动处理各种数据类型，
 * 并针对PHP版本进行兼容性适配。
 * 
 * @param mixed $data 要处理的数据，编码时可以是任意类型，解码时应为JSON字符串
 * @param string $type 操作类型，'en'表示编码（默认），非'en'表示解码
 * @return mixed 编码时返回JSON字符串，解码时返回PHP数组
 * 
 * @example
 * ```php
 * // JSON编码
 * $jsonString = json(['name' => '张三', 'age' => 25]);
 * // 输出: {"name":"张三","age":25}
 * 
 * // JSON解码
 * $array = json($jsonString, 'de');
 * // 输出: ['name' => '张三', 'age' => 25]
 * 
 * // 编码特殊值
 * json(null);  // 返回: "null"
 * json(true);  // 返回: "true"
 * json(false); // 返回: "false"
 * ```
 */
function json(mixed $data,string $type='en'):mixed
{
	if($type == 'en'){
		if(defined('JSON_UNESCAPED_UNICODE')){
			return str_replace("\\/", '/', json_encode($data, JSON_UNESCAPED_UNICODE));
		}else{
			if($data === null)
				return 'null';
			if($data === true)
				return 'true';
			if($data === false)
				return 'false';
			
			if(is_numeric($data))
				return $data;
			
			if(is_string($data)){
				$text = $data;
				$text = str_replace('\\', '\\\\', $text);
				$text = str_replace(["\r", "\n", "\t", "\""], ['\r', '\n', '\t', '\\"'], $text);
				return '"' . $text . '"';
			}
			
			if(is_array($data) || is_object($data)){
				$arr = [];
				$isObject = is_object($data) || (array_keys($data) !== range(0, count($data) - 1));
				foreach($data as $k=>$v){
					if($isObject){
						$arr[] = json($k) . ':' . json($v);
					}else{
						$arr[] = json($v);
					}
				}
				if($isObject){
					return '{' . join(',', $arr) . '}';
				}else{
					return '[' . join(',', $arr) . ']';
				}
			}

			return '';
		}
	}else{
		return json_decode($data, true);
	}
}
