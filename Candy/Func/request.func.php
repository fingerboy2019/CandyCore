<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 输入参数获取与过滤函数
 * 
 * 提供统一的输入参数获取接口，支持从GET、POST、PUT和自动判断的请求方式中获取参数，
 * 可指定参数类型修饰符和过滤器，确保获取的数据安全可靠。
 * 
 * @param string $name 参数名称，格式支持：
 *                     - 普通参数名：'username'
 *                     - 指定来源：'get.username'、'post.password'、'put.data'、'param.id'
 *                     - 原始请求体：'raw'（不经过默认过滤）
 *                     - 指定类型：'id/d'（整数）、'name/s'（字符串）
 *                     - 获取所有：''（空字符串）
 * @param mixed $default 默认值，当参数不存在时返回
 * @param mixed $filter 过滤函数或规则
 * @param mixed $datas 自定义数据源，默认从请求中获取
 * @return mixed 获取到的参数值，根据参数类型修饰符或默认值确定返回类型
 * 
 * @example
 * ```php
 * // 获取GET参数
 * $id = I('get.id', 0, 'intval');
 * 
 * // 获取POST参数
 * $name = I('post.name', '');
 *
 * // 获取原始 JSON / 文本请求体
 * $raw = I('raw');
 * 
 * // 自动判断参数来源
 * $keyword = I('keyword');
 * 
 * // 指定参数类型
 * $page = I('page/d', 1); // 整数类型
 * 
 * // 使用自定义过滤
 * $content = I('content', '', 'htmlspecialchars');
 * ```
 * 
 * @note
 * - 函数内部会自动处理PUT请求的原始数据
 * - 当启用VAR_AUTO_STRING配置时，默认会将参数强制转换为字符串类型
 * - param模式会根据当前请求方法自动选择GET或POST
 */
function I(string $name,mixed $default = '',mixed $filter = null,mixed $datas = null):mixed
{
	// ⚠️ 不能用 static 缓存请求体：常驻运行时（Workerman/Swoole）函数静态变量跨请求保留，
	// 会导致 I('put') 返回上一个请求的 body（本项目实测过：提交了但参数是上一次的）。
	// 统一放 $GLOBALS['_PUT']，由 Bootstrap::run() 每请求重置。
	if(strpos($name,'/')){ // 指定修饰符
		[$name, $type] = explode('/', $name, 2);
	}elseif(C('VAR_AUTO_STRING')){ // 默认强制转换为字符串
        $type = 's';
    }
    if(strpos($name,'.')){ // 指定参数来源
        [$method, $name] = explode('.',$name,2);
    }else{ // 默认为自动判断
        $method = $name ?: 'param';
		$name = '';
    }
	$inSwoole = isSwooleCoroutine();
    switch(strtolower($method)){
		case 'raw':
			return isWebServer() ? R()->rawBody() : (string)file_get_contents('php://input');
        case 'get':
			if ($inSwoole) {
			$input = R()->getData();
        } else {
        	$input = &$_GET;
        }
        	break;
        case 'post':
			// 常驻运行时 $_POST 不参与读取；表单为空时回退解析原始请求体（前端统一发 application/json）
			$input = $inSwoole ? R()->postData() : $_POST;
			if (empty($input)) {
				$input = R()->bodyParams();
			}
        	break;
        case 'put':
			if(!isset($GLOBALS['_PUT']) || is_null($GLOBALS['_PUT'])){
				// 统一走 bodyParams()：JSON 与 form-urlencoded 都能解析，三种运行时一致
				$GLOBALS['_PUT'] = R()->bodyParams();
			}
			$input = $GLOBALS['_PUT'];
			break;
        case 'param':
            $input = match($inSwoole ? R()->method() : ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
				'POST' => ($inSwoole ? R()->postData() : $_POST) ?: R()->bodyParams(),
                'PUT' => (function() {
                    if(!isset($GLOBALS['_PUT']) || is_null($GLOBALS['_PUT'])){
                        // 统一走 bodyParams()：JSON 与 form-urlencoded 都能解析，三种运行时一致
                        $GLOBALS['_PUT'] = R()->bodyParams();
                    }
                    return $GLOBALS['_PUT'];
                })(),
                default => $inSwoole ? R()->getData() : $_GET,
            };
            break;
        case 'path':
            $input = [];
			$pathInfo = $inSwoole
				? (string)R()->server('PATH_INFO', '')
				: (string)($_SERVER['PATH_INFO'] ?? '');
            if($pathInfo !== ''){
                $depr = C('URL_PATHINFO_DEPR') ?? '/';
				$input = explode($depr, trim($pathInfo, $depr));
            }
            break;
        case 'request':
			if ($inSwoole) {
			$input = R()->all();
        } else {
        	$input = &$_REQUEST;
        }   
        	break;
        case 'session':
			$input = session(); 
        	break;
        case 'cookie':   
        	$input = cookie();    
        	break;
        case 'server':
			if ($inSwoole) {
			$input = R()->serverData();
        } else {
        	$input = &$_SERVER;
        }    
        	break;
        case 'data':   
        	$input = &$datas;      
        	break;
        case 'files':
			if ($inSwoole) {
				return '' == $name ? R()->filesData() : R()->file($name);
			}
			if(''==$name){
				return $_FILES;
			}else{
				return $_FILES[$name];
			}
        	break;
        default:
            return null;
    }
	
    if('' == $name){ // 获取全部变量
        $data = $input;
        $filters = $filter ??= C('DEFAULT_FILTER');
        if($filters){
            if(is_string($filters)){
                $filters = explode(',',$filters);
            }
            foreach($filters as $f){
                $data = \Candy\Extend\Arr::arrayMapRecursive($f,$data); // 参数过滤
            }
        }
    }elseif(isset($input[$name])){ // 取值操作
        $data = $input[$name];
        $filters = $filter ??= C('DEFAULT_FILTER');
        if($filters){
            if(is_string($filters)){
                if(0 === strpos($filters,'/')){
                    if(1 !== preg_match($filters,(string)$data)){
                        // 支持正则验证
                        return $default ??= null;
                    }
                }else{
                    $filters = explode(',',$filters);                    
                }
            }elseif(is_int($filters)){
                $filters = [$filters];
            }
            
            if(is_array($filters)){
                foreach($filters as $f){
                    if(function_exists($f)){
                        $data = is_array($data) ? \Candy\Extend\Arr::arrayMapRecursive($f,$data) : $f($data); // 参数过滤
                    }else{
                        $data = filter_var($data,is_int($f) ? $f : filter_id($f));
                        if(false === $data){
                            return $default ??= null;
                        }
                    }
                }
            }
        }
        if(!empty($type)){
            $data = match(strtolower($type)) {
                'a' => (array)$data,
                'd' => (int)$data,
                'f' => (float)$data,
                'b' => (bool)$data,
                's' => (string)$data,
                default => (string)$data,
            };
        }
    }else{ // 变量默认值
        $data = $default ??= null;
    }
    is_array($data) && array_walk_recursive($data,'stripslashesDeep');
    return $data;
}

/**
 * Session数据操作函数
 * 
 * 提供对session数据的获取、设置、删除等操作，兼容Web环境和CLI环境。
 * 在Web环境中优先使用框架的Session管理，在CLI环境中使用原生$_SESSION。
 * 支持点语法访问嵌套数组，如'user.info.name'。
 * 
 * @param string $key Session键名，空字符串表示获取全部数据或清除所有数据
 * @param mixed $value Session值
 *                    - 空字符串('')：获取指定键的数据
 *                    - null：删除指定键或清除所有session
 *                    - 其他值：设置session数据
 * @return mixed 根据操作返回不同的值
 *               - 获取数据时：返回对应的值或null（不存在时）
 *               - 获取全部时：返回整个session数组
 *               - 设置或删除时：无返回值
 * 
 * @example
 * ```php
 * // 获取所有session数据
 * $allSession = session();
 * 
 * // 获取特定session值
 * $userId = session('user_id');
 * 
 * // 使用点语法获取嵌套数据
 * $userName = session('user.info.name');
 * 
 * // 设置session值
 * session('user_id', 1001);
 * session('user', ['name' => 'admin', 'role' => 'admin']);
 * 
 * // 删除特定session值
 * session('user_id', null);
 * 
 * // 清除所有session数据
 * session('', null);
 * ```
 */
function session(string $key='',mixed $value='')
{
	$session = false;
	if (isSwooleCoroutine()) {
		$session = R()->session();
	} elseif(isWebServer()){
		$container = \Candy\Core\Container::getInstance();
		if ($container->has('Request')) {
			$request = $container->get('Request');
			if ($request !== null && method_exists($request, 'session')) {
				$session = $request->session();
			}
		}
	}
	
	$keys = [];
    if(empty($key)){
		if(is_null($value)){
			if($session){
				$session->flush();
			}else{
				$_SESSION = [];
			}
		}else{
			return $session ? $session->all() : $_SESSION;
		}
	}
	
	if($value === ''){ //获取值
		// 修复：stripos 返回 0 时为 falsey，应该使用 !== false 判断
		if(stripos($key, '.') !== false)
			$keys = explode('.', $key);
		
		if(count($keys) > 0){
			$tmp = $session ? $session->all() : $_SESSION;
			do{
				$key = array_shift($keys);
				if(isset($tmp[$key])){
					$tmp=$tmp[$key];
				}else{
					return ;
				}
			}while(count($keys) > 0);
			return $tmp;
		}else{
			return $session ? $session->get($key) : ($_SESSION[$key] ??= null);
		}
		return null;
	}else{
		if(is_null($value)){
			if($session){
				$session->delete($key);
			}else{
				unset($_SESSION[$key]);
			}
		}else{
			if($session){
				$session->set($key, $value);
			}else{
				$_SESSION[$key] = $value;
			}
		}
	}
}

/**
 * 获取当前会话的Session ID
 * 
 * 从当前请求中获取会话ID，优先从Request对象获取，不存在时使用原生session_id()。
 * 此函数确保在不同环境下都能正确获取会话标识。
 * 
 * @return string 当前会话的Session ID，如果会话未启动则返回空字符串
 * 
 * @example
 * ```php
 * // 获取当前会话ID
 * $sessionId = sessionId();
 * 
 * // 将会话ID用于日志记录
 * Log::record('User action with session: ' . sessionId());
 * 
 * // 将会话ID用于临时文件名
 * $tmpFileName = 'temp_' . sessionId() . '.txt';
 * ```
 * 
 * @note
 * - 在Web环境中，优先使用Request对象的sessionId()方法
 * - 在非Web环境或Request对象不存在时，使用原生session_id()函数
 * - 确保在调用前会话已经启动，否则可能返回空字符串
 */
function sessionId(): string
{
	if (isSwooleCoroutine()) {
		$session = R()->session();
		return $session !== null && method_exists($session, 'getId') ? (string)$session->getId() : '';
	}
	if (isWebServer()) {
		$container = \Candy\Core\Container::getInstance();
		// 检查Request对象是否存在
		if ($container->has('Request') && ($request = $container->get('Request')) !== null) {
			return $request->sessionId();
		}
	}
	// 默认返回PHP原生session_id或空字符串
	return session_id();
}

/**
 * Cookie操作函数，支持获取、设置和删除Cookie
 *
 * 该函数是对PHP原生Cookie操作的封装，提供了更便捷的接口，
 * 同时支持CLI模式下的特殊处理。可用于获取单个Cookie值、所有Cookie值，
 * 设置Cookie，以及删除单个或所有Cookie。
 *
 * 使用示例：
 * - 获取Cookie: cookie('username') - 获取username的值
 * - 设置Cookie: cookie('username', 'admin', 3600) - 设置username值为admin，有效期1小时
 * - 删除Cookie: cookie('username', null) - 删除username Cookie
 * - 获取所有Cookie: cookie() - 获取所有Cookie值
 * - 删除所有Cookie: cookie('', null) - 删除所有Cookie（保留会话ID）
 *
 * @param string $name Cookie名称，空字符串时操作所有Cookie
 * @param mixed $value Cookie值，null表示删除，空字符串表示获取
 * @param int $expire 过期时间（秒），默认3600秒
 * @param string $path 有效路径，默认为根路径
 * @param string $domain 域名，默认为当前主机名
 * @param array{secure?:bool,httponly?:bool,samesite?:string,expires_at?:int} $options Cookie 安全属性与绝对过期时间
 * @return mixed 获取Cookie时返回Cookie值或Cookie数组，设置/删除时无返回值
 */
function cookie(string $name = '',mixed $value = '',int $expire = 0,string $path = '/',string $domain = '', array $options = []):mixed
{
	$inSwoole = isSwooleCoroutine();
	$secure = (bool)($options['secure'] ?? false);
	$httpOnly = (bool)($options['httponly'] ?? false);
	$sameSite = (string)($options['samesite'] ?? '');
	if($domain === '') {
		$host = $inSwoole
			? (string)R()->server('HTTP_HOST', R()->server('SERVER_NAME', ''))
			: (string)(I('server.HTTP_HOST', '', '') ?: I('server.SERVER_NAME', '', ''));
		$domain = preg_replace('/:\d+$/', '', $host);
	}
	
	$response = false;
	if ($inSwoole) {
		$response = $value === '' ? R() : R()->response();
	} elseif(isWebServer()){
		$container = \Candy\Core\Container::getInstance();
		if ($value === '' && $container->has('Request')) {
			$req = $container->get('Request');
			if ($req !== null) {
				$response = $req;
			}
		} elseif ($container->has('Response')) {
			$res = $container->get('Response');
			if ($res !== null) {
				$response = $res;
			}
		}
	}
	
	if($name === ''){
		//取或清除全部值
		if(is_null($value)){
			//保留值
			$noClean = ['PHPSID','PHPSESSID'];
			
			//获取要清除的值
		$cookies = $response
			? ($response->getHeader('Set-Cookie') ?? [])
			: (($cookieHeader = (string)I('server.HTTP_COOKIE', '', '')) !== '' ? explode('; ', $cookieHeader) : []);
			if(count($cookies) > 0){
				foreach ($cookies as $cookie) {
					[$name] = explode('=', $cookie, 2);
					if(in_array($name, $noClean) === false){
						if($response){
							$response->cookie($name, null, time() - 3600, $path, $domain, $secure, $httpOnly, $sameSite);
						}else{
							\setcookie($name, '', ['expires' => time() - 3600, 'path' => $path, 'domain' => $domain, 'secure' => $secure, 'httponly' => $httpOnly, 'samesite' => $sameSite]);
						}
						if ($inSwoole) {
							R()->removeCookie($name);
						} else {
							unset($_COOKIE[$name]);
						}
					}	
				}
			}
		}else{
			//取全部值
			if($response){
				return $response->cookie();
			}else{
				return $inSwoole ? R()->cookieData() : $_COOKIE;
			}
		}
	}else{
		if(is_null($value)){
			//删除
			if($response){
				$response->cookie($name, null, time() - 3600, $path, $domain, $secure, $httpOnly, $sameSite);
			}else{
				\setcookie($name, '', ['expires' => time() - 3600, 'path' => $path, 'domain' => $domain, 'secure' => $secure, 'httponly' => $httpOnly, 'samesite' => $sameSite]);
			}
			if ($inSwoole) {
				R()->removeCookie($name);
			} else {
				unset($_COOKIE[$name]);
			}
		}elseif($value === ''){
			//取值
			if($response){
				return $response->cookie($name);
			}else{
				return $inSwoole ? R()->cookie($name) : ($_COOKIE[$name] ??= null);
			}
		}else{
			//存值
			$time = time();
			$expire = array_key_exists('expires_at', $options)
				? (int)$options['expires_at']
				: ($expire == 0 ? $time + 3600 : $time + $expire);  //默认有效时间一小时
			if($response){
				$response->cookie($name, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite);
			}else{
				\setrawcookie($name, $value, ['expires' => $expire, 'path' => $path, 'domain' => $domain, 'secure' => $secure, 'httponly' => $httpOnly, 'samesite' => $sameSite]);
			}
			if ($inSwoole) {
				R()->setCookie($name, (string)$value);
			} else {
				$_COOKIE[$name] = $value;
				// Workerman 等：读 cookie 走 Request 快照，须同步，否则同请求内 JWT refresh 后仍读到旧 token
				if (isWebServer()) {
					try {
						$container = \Candy\Core\Container::getInstance();
						if ($container->has('Request')) {
							$req = $container->get('Request');
							if (is_object($req) && method_exists($req, 'setCookie')) {
								$req->setCookie($name, (string)$value);
							}
						}
					} catch (\Throwable) {
					}
				}
			}
		}
	}
	
	return true;
}

/**
 * 兼容HTTP头设置函数
 * 
 * 提供跨环境的HTTP头设置功能，能够根据运行环境（Web服务器或CLI）自动调整HTTP头的处理方式，
 * 支持状态码设置、位置跳转和常规HTTP头设置，确保在不同环境下的一致性。
 * 
 * @param string $info HTTP头信息，格式为"名称: 值"，或在Web环境下可为空
 * @param int $code HTTP状态码，默认为0（不设置）
 * @param string $body 响应体内容，仅在Web环境下使用
 * @return void
 * 
 * @example
 * ```php
 * // 设置内容类型
 * headers('Content-Type: application/json');
 * 
 * // 执行页面跳转
 * headers('location: /new/page');
 * 
 * // 设置状态码
 * headers('Not Found', 404);
 * 
 * // 在Web环境下设置状态码和响应体
 * headers('', 200, 'Success message');
 * ```
 * 
 * @note
 * - 函数会自动检测运行环境，在Web服务器环境下使用Response对象，CLI环境下直接使用header函数
 * - 对于location头，会自动设置302状态码
 * - 当在CLI环境下且code>0时，会自动添加HTTP/1.1前缀
 */
function headers(string $info,int $code = 0,string $body = ''): void
{
	if(isWebServer()){
		$response = isSwooleCoroutine()
			? R()->response()
			: (($container = \Candy\Core\Container::getInstance())->has('Response') && ($res = $container->get('Response')) !== null ? $res : false);
		if ($response === false) {
			if ($code > 0) {
				$info = ($info !== '' ? 'HTTP/1.1 ' . $code . ' ' . $info : '');
				if ($info !== '') {
					header($info);
				} else {
					http_response_code($code);
				}
			} elseif ($info !== '') {
				header($info);
			}
			if ($body !== '') {
				echo $body;
			}
			return;
		}
		
		if(empty($info)){
			if($code > 0){
				if(empty($body)){
					$response->withStatus($code);
				}else{
					$response->withStatus($code)->withBody($body);
				}
			}
		}else{
			if(is_int(stripos(strtolower($info), 'location'))){
				[$location, $info] = explode(':', $info, 2);
				$code = 302;
			}
			if($code > 0){
				if($code == 302){
					$response->withStatus(302);
					$response->header('location', trim($info));
				}else{
					if(empty($body)){
						$response->withStatus($code, $info);
					}else{
						$response->withStatus($code, $info)->withBody($body);
					}
				}
			}else{
				[$name, $info] = explode(':', $info, 2);
				$response->header(trim($name), trim($info));
			}			
		}
	}else{
		if($code > 0){
			// 302 重定向：先设 Location，再设状态码
			if (stripos($info, 'location:') === 0 || stripos($info, 'Location:') === 0) {
				header($info);
				header('HTTP/1.1 ' . $code . ' Found');
			} else {
				$info = 'HTTP/1.1 '. $code .' ' . $info;
				header($info);
			}
		}elseif($info !== ''){
			header($info);
		}
		if(!empty($body))
			echo $body;
	}
}

/**
 * 生成并输出CSRF令牌函数
 *
 * 该函数用于生成CSRF（跨站请求伪造）保护令牌，并可选择直接输出为隐藏表单字段
 * 或仅输出生成的令牌值。内部调用 Csrf::create() 生成安全的令牌，
 * 适用于Web表单提交安全保护场景。
 *
 * @param bool $input 是否以隐藏表单字段形式输出，true表示输出input标签，false表示仅输出token值
 * @param string $name token字段的名称，默认为'token'
 * @return void 无返回值，直接输出内容
 * 
 * @example
 * ```php
 * // 在表单中输出隐藏token字段（默认形式）
 * // 输出: <input type="hidden" name="token" value="生成的token值" />
 * takeToken();
 * 
 * // 自定义字段名
 * // 输出: <input type="hidden" name="csrf_token" value="生成的token值" />
 * takeToken(true, 'csrf_token');
 * 
 * // 仅获取token值（用于AJAX请求等场景）
 * // 输出: 生成的token值（无HTML标签）
 * takeToken(false);
 * 
 * // 在JavaScript中使用token
 * echo '<script>
 *     const csrfToken = "';
 * takeToken(false);
 * echo '";
 *     // 然后在AJAX请求中使用这个token
 * </script>';
 * ```
 * @note
 * - 生成的token会自动存储在session中用于后续验证
 * - 建议在所有处理用户输入的表单中使用此函数
 * - 与validateToken()函数配合使用以实现完整的CSRF保护
 */
function takeToken(bool $input = true,string $name = 'token'): void
{
	$token = \Candy\Extend\Validate\Csrf::create();
	if($input){
		echo '<input type="hidden" name="'. $name .'" value="'. $token .'" />';
	}else{
		echo $token;
	}
}

/**
 * 获取当前请求上下文（R 规范入口）
 *
 * 框架层请求数据统一入口。非协程模式下返回全局单例，
 * Swoole 协程模式下返回当前协程隔离的实例。
 *
 * 项目层应使用本函数替代直接访问 \$_GET、\$_POST、\$_SERVER 等超全局变量，
 * 以保证 Swoole 协程下的数据隔离。
 *
 * @param array|null $source 注入 mock 数据（为空时自动读取当前请求）。
 *                           传入数组时替换当前请求实例，支持键：
 *                           get / post / server / cookie / files / method / uri / ip
 * @return \Candy\Core\Request
 *
 * @example
 * ```php
 * // 读取参数
 * request()->get('id');          // $_GET['id']
 * request()->post('name');       // $_POST['name']
 * request()->server('HTTP_HOST');// $_SERVER['HTTP_HOST']
 * request()->input('page', 1);   // $_REQUEST['page'] ?? 1
 * request()->isAjax();           // 是否 AJAX 请求
 *
 * // 测试注入
 * request(['get' => ['id' => 1], 'method' => 'POST']);
 * ```
 */
function R(?array $source = null): \Candy\Core\Request
{
	if ($source !== null) {
		$req = new \Candy\Core\Request($source);
		\Candy\Core\Request::setInstance($req);
		return $req;
	}
	return \Candy\Core\Request::current();
}

/**
 * Request 上下文兼容别名。
 *
 * 新代码统一使用 R()；保留 request() 以兼容既有业务与第三方扩展。
 */
function request(?array $source = null): \Candy\Core\Request
{
	return R($source);
}
