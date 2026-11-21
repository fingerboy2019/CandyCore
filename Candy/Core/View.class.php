<?php
/**
 * Candy框架 模板引擎核心类
 * 
 * 模板引擎编排入口：组合 {@see Trait\View\TagCompiler}、{@see Trait\View\CompileCache}；
 * 输出内容过滤自 2026-11-13 起统一由 {@see \Candy\Core\HookPipeline} 在 Respond::send() 执行。
 * 完成变量分配、渲染调度与 API 输出；标签编译、编译缓存、输出过滤由子组件承担。
 * 
 * @package Candy\Core
 * @author 刘森 <fingerboy@qq.com>
 * @date 2019-07-26 11:31:47
 */
 
declare(strict_types=1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class View {
	/**
	 * 控制器名称
	 * @var string
	 * @access public
	 */
	public string $control;
	
	/**
	 * 操作名称
	 * @var string
	 * @access public
	 */
	public string $action;
	
	/**
	 * 模板左分隔符
	 * @var string
	 * @access private
	 */
	private string $leftDelimiter = '<{';
	
	/**
	 * 模板右分隔符
	 * @var string
	 * @access private
	 */
	private string $rightDelimiter = '}>';
	
	/**
	 * 模板目录
	 * @var string
	 * @access private
	 */
	private string $templateDir = '';
	
	/**
	 * 模板路径
	 * @var string
	 * @access private
	 */
	private string $templatePath = '';
	
	/**
	 * 编译目录
	 * @var string
	 * @access private
	 */
	private string $compileDir = '';
	
	/**
	 * 编译路径
	 * @var string
	 * @access private
	 */
	private string $compilePath = '';
	
	/**
	 * 缓存目录
	 * @var string
	 * @access private
	 */
	private string $cacheDir = '';
	
	/**
	 * 缓存路径
	 * @var string
	 * @access private
	 */
	private string $cachePath = '';
	
	/**
	 * 模板后缀
	 * @var string
	 * @access private
	 */
	private string $tplSuffix = '.html';
	
	/**
	 * 模板目录分隔符
	 * @var string
	 * @access private
	 */
	private string $tplSeparate = '/';
	
	/**
	 * 模板内容
	 * @var string
	 * @access private
	 */
	private string $content = '';
	
	/**
	 * 模板变量数组
	 * @var array
	 * @access private
	 */
	private array $viewVars = [];
	
	/**
	 * foreach模块参数
	 * @var array
	 * @access private
	 */
	private array  $foreachParameter = [];
	
	/**
	 * 模板名称
	 * @var string
	 * @access private
	 */
	private string $tplName = '';
	
	/**
	 * 模板全局变量组
	 * @var array
	 * @access private
	 */
	private array  $ref = [];
	
	/**
	 * 模板列表
	 * @var array
	 * @access private
	 */
	private array  $tplList = [];
	
	
	/**
	 * 缓存开关状态
	 * @var int
	 * @access public
	 */
	public bool $caching = false;
	
	/**
	 * 缓存生命周期
	 * @var int
	 * @access private
	 */
	private int $cacheLifeTime = 0;

	/**
	 * 缓存实例
	 * @var mixed
	 * @access private
	 */
	private $cache = null;

	// 修饰器缓存，避免重复加载文件
	private static array $modifierCache = [];

	private Trait\View\TagCompiler $tagCompiler;
	private Trait\View\CompileCache $compileCache;
	/**
	 *   construct
	 * @return mixed
	 */
	public function __construct(){
		// 初始化控制器和操作名称
		// 与 Views/{Control}/ 目录一致，保留多驼峰（DemoItem，勿变成 Demoitem）
		$this->control = \Candy\Extend\Str::controlName((string) C('CONTROL'));
		$this->action = C('ACTION');     // 默认操作名

		$this->templateDir = is_null(C('TEMPLATE_DIR')) ? C('CANDYROOT') : C('TEMPLATE_DIR'); // 模板文件目录
		
		// 初始化模板相关配置（无论API模式与否）
		$this->tplSuffix = G('TPLPREFIX') ?? '.html';    // 模板文件后缀
		$this->tplSeparate = G('SEPARATE') ?? '/';    // 模板目录分隔符
		
		// 非API模式下的初始化（Web视图模式）
		if(is_null(C('API'))){
			// 初始化模板分隔符
			$this->leftDelimiter = G('LEFTDELIMITER') ?? '<{';   // 模板左分隔符
			$this->rightDelimiter = G('RIGHTDELIMITER') ?? '}>';   // 模板右分隔符
			
			// 初始化目录路径配置
			
			$this->compileDir = C('COMPILE_DIR');    // 编译文件存储目录（自动生成）
			$this->cacheDir = C('CACHE_DIR');  // 缓存文件存储目录
			
			// 初始化数据存储数组
			$this->viewVars = [];   // 存储模板变量的数组
			$this->foreachParameter = [];   // foreach标签处理参数
			$this->ref = [];   // 存储模板全局变量的数组
		}

		$this->tagCompiler = new Trait\View\TagCompiler($this);
		$this->compileCache = new Trait\View\CompileCache($this, $this->tagCompiler);
	}
	/**
	 * 魔术方法，调用其他功能注册来的方法
	 * 
	 * 用于动态调用视图相关的钩子函数，如果方法不存在则显示错误信息。
	 * 
	 * @param string $name 方法名称
	 * @param array $arguments 方法参数
	 * @access public
	 * @return void
	 */
	public function __call($name, $arguments) {
		$tags = 'TAGS_VIEW_' . strtoupper($name);
        //view 注册的调用函数
		if(is_null(C($tags)) === false){
			Hook::listen('view_' . $name, ['view'=>$this, 'args'=>$arguments]);
		}else{
			Debug::showMessage('函数：'. $name .'不存在！', 'View');
		}
    }
	
	/**
	 * 显示模板内容或返回API数据
	 *
	 * 根据系统运行模式决定显示Web视图或返回API格式数据
	 * - Web模式：渲染模板并发送HTML响应
	 * - API模式：格式化数据并准备返回
	 *
	 * @param string|null $name 模板名称
	 * @param string $cacheId 缓存ID
	 * @param bool $single 是否单独处理
	 * @param string|null $templateDir 自定义模板目录
	 * @access public
	 * @return void
	 */
	public function display(?string $name=null, string $cacheId = '', bool $single = false, ?string $templateDir = null): void
	{
		// Web视图模式处理
		if(is_null(C('API'))){
			// 生成模板内容（包括编译、缓存等处理）
			$this->makeContent($name, $cacheId, $single, $templateDir);
			// 通过响应对象发送HTML内容，设置HTTP状态码为200
			// 第 4 参 false：发完不中断，让控制器后续与 Finish/app_end 生命周期得以执行
			respond($this->content, 200, 'html', false);
		}else{  // API接口模式处理
			//API模式处理
			$format = C('API');  // 获取API响应格式
			// 整理API返回数据格式
			$tmp = $this->data2api('');  // 获取所有API数据
			
			// 情况1：已包含完整的API数据结构（apidata键）
			if(isset($tmp['apidata'])){
				$data = $tmp['apidata'];
			}elseif(count($tmp)>0){
				// 情况2：数据已经是标准格式（包含code和message字段）
				if(isset($tmp['code']) && isset($tmp['message'])){
					$data = $tmp;
				}else{
					// 情况3：需要构建标准API响应结构
					$data = [];
					$data['code'] = isset($tmp['code']) ? (int)$tmp['code'] : 0;
					unset($tmp['code']);
					$data['message'] = isset($tmp['message']) ? (string)$tmp['message'] : ($data['code'] === 0 ? 'success' : 'error');
					unset($tmp['message']);
					if(count($tmp)>0){
						$data['data'] = $tmp;
					}
				}
			}else{
				// 情况4：无数据，返回默认成功格式
				$data = [
					'code' => 0,
					'message' => 'success'
				];
			}

			//补充sessionid（API模式下始终返回，用于维持会话状态）
			if(C('USE_SESSION')){
				if(isWebServer()){
					try{
						$request = Container::getInstance()->get('Request');
						if($request && method_exists($request, 'sessionId')){
							$sessionId = $request->sessionId();
							if(!empty($sessionId)){
								$data['sessionid'] = $sessionId;
							}
						}
					}catch(\Exception $e){
					}
				}else{
					if(session_status() === PHP_SESSION_NONE){
						session_start();
					}
					$sessionId = session_id();
					if(!empty($sessionId)){
						$data['sessionid'] = $sessionId;
					}
				}
			}

			$data = formatApiResponseData($data, (string)$format);

			respond((string)$data, 200, $format, false);
		}
	}
	
	/**
	 * 执行渲染模板文件
	 *
	 * 渲染模板内容并返回渲染后的字符串，不直接输出。
	 *
	 * @param string|null $name 模板名称
	 * @param string $cacheId 缓存ID
	 * @param bool $single 是否单独处理
	 * @param string|null $templateDir 模板目录
	 * @access public
	 * @return string 渲染后的模板内容
	 */
	public function fetch(?string $name=null,string $cacheId = '',bool $single = false,?string $templateDir = null): string
	{
		$this->makeContent($name, $cacheId, $single, $templateDir);
		return $this->content;
	}

	/**
	 * 获取最近一次渲染的模板内容
	 *
	 * 供 ViewRenderer、Respond 等读取 display/fetch 后的 HTML 或 API 输出体。
	 *
	 * @return string
	 */
	public function getContent(): string
	{
		return $this->content;
	}
	/**
	 * 执行显示模板文件
	 *
	 * 处理模板内容的核心方法，包括路径设置、变量分配、模板编译等。
	 *
	 * @param string|null $name 模板名称
	 * @param string $cacheId 缓存ID
	 * @param bool $single 是否单独处理
	 * @param string|null $templateDir 模板目录
	 * @access private
	 * @return void
	 */
	private function makeContent(?string $name=null,string $cacheId = '',bool $single = false,?string $templateDir = null): void
	{
		// 处理跨项目模板调用（格式：project_name:template_name）
		if(!empty($name) && stripos($name, ':')){
			[$oappname, $name] = explode(':', $name, 2);
			
			// 重置模板路径为指定应用的路径
			initTplPath($oappname);
			// 设置模板目录（优先使用传入的目录，其次使用配置项）
			$this->templateDir = is_null($templateDir) ? (is_null(C('TEMPLATE_DIR')) ? C('CANDYROOT') : C('TEMPLATE_DIR')) : $templateDir;
			// 设置编译文件目录（自动生成的缓存文件）
			$this->compileDir = C('COMPILE_DIR');
			// 设置缓存目录
			$this->cacheDir = C('CACHE_DIR');
		}
		
		// 初始化基础模板变量
		$this->tplName = '';    // 模板名称存储
		// 设置缓存状态（从G函数获取，默认关闭）
		$this->caching = (bool)G('CSTART') ?? false;
		// 设置缓存生命周期（从G函数获取，默认0表示永不过期）
		$this->cacheLifeTime = G('CTIME') ?? 0; 
		
		// 将常用全局变量分配到模板中供使用
		// 语言包相关变量
		$this->viewVars['lang'] = C('LANGINFO');      // 语言数组
		// 路径相关变量
		$this->viewVars['root'] = G('C_ROOT');      // 项目根目录
		$this->viewVars['app'] = C('APPNAME');      // 当前应用名称
		$this->viewVars['public'] = G('C_PUBLIC');  // 公共资源路径
		$this->viewVars['res'] = C('C_RES');        // 资源路径
		
		// 框架信息变量
		$this->viewVars['candy_ver'] = CANDY_VERSION;  // 框架版本号
		$this->viewVars['candy_url'] = CANDY_URL;      // 框架官网
		
		// 根据运行环境设置变量
		// 命令行模式标识
		if(isWebServer())
			$this->viewVars['cli'] = true;
		
		// 移动设备标识
		if(isMobile())
			$this->viewVars['ismobile'] = true;
		
		// 模板名称标准化处理
		if(is_null($name) || empty($name)){
			// 情况1：未指定模板名，使用默认格式：控制器/操作
			$name=ucfirst($this->control) . $this->tplSeparate . $this->action;
		}elseif(str_contains($name,'/')){
			// 情况2：指定了完整路径，标准化分隔符
			$name=ucfirst(str_replace('/', $this->tplSeparate, $name));
		}else{
			// 情况3：仅指定操作名，组合为：控制器/指定操作
			$name=ucfirst($this->control) . $this->tplSeparate . $name;
		}
		
		// 设置缓存路径（根据缓存ID构建唯一缓存文件名）
		$this->compileCache->cachePath($cacheId);
		
		// 异常处理：确保编译目录有效（RPC服务模式下的兼容处理）
		is_string($this->compileDir) OR $this->compileDir = CANDYPATH .'Runtime/Comps/'. C('APPNAME') .'/rpcserver';
		
		// 编译并获取模板内容
		// 这是核心处理步骤，包括读取模板文件、编译、缓存等流程
		$content = $this->compileCache->compileFile($name, $cacheId, $single, $this->templateDir, $this->compileDir);
		// 2026-11-13：输出过滤已统一到 Respond::send() 的 HookPipeline（response 通道），
		// 这里不再 apply —— 原实现只覆盖模板渲染，.json 接口与文件输出都会绕过它。
		// 需要「仅改 HTML」的钩子请用 HookPipeline::add('response', $fn, 10, ['html']) 注册。

		$tmpfile = str_replace(C('CANDYROOT'), '/', $this->templatePath);
		if(is_null(C('ISADDON'))){
			Debug::addMsg('当前使用的模板路径: <b> '. $tmpfile . ' </b>.', 6);
		}else{
			Debug::addMsg('当前使用' . C('APPNAME') . '插件的模板路径: <b>'. $tmpfile . '</b>.', 6);
		}

		if(is_null(C('TAGS_VIEW_FILTER')) === false)
			$content = Hook::listen('view_filter', $content, true) ?: $content;

		$this->content = Security::runMiddlewares('Over', $content);
	}

	/**
	 * Assign
	 * @param mixed $args
	 * @param mixed $value
	 * @param mixed $apidata
	 * @param mixed $call_func
	 */
	public function assign($args, $value = null,bool $apidata = true,string $call_func = ''): void
	{
		static $noassignMap = ['foreach'=>true,'view'=>true,'define'=>true,'const'=>true,'request'=>true,'server'=>true,'get'=>true,'post'=>true,'cli'=>true,'ismobile'=>true];

		if(!is_array($args)){
			$args = [$args=>$value]; 
		}
		
		foreach($args as $key => $value){
			if($key != ''){
				if(isset($noassignMap[$key])){
					Debug::addMsg('<font color="red">变量名错误 </font>assign禁止使用系统保留变量名：<b>'. implode(',', array_keys($noassignMap)) .'</b>', 4);
				}else{
					if($apidata){
						$this->data2api($key, $value, $call_func);
					}
					$this->viewVars[$key] = $value;
				}
			}
		}
	}

	/**
	 * Compile File
	 * @param mixed $template_name
	 * @param mixed $cacheId
	 * @param mixed $single
	 * @param mixed $templateDir
	 * @param mixed $compile_dir
	 * @return string
	 */
	public function compileFile(?string $template_name, string $cacheId = '', bool $single = false, ?string $templateDir = null, string $compile_dir = ''): string
	{
		return $this->compileCache->compileFile($template_name, $cacheId, $single, $templateDir, $compile_dir);
	}

	/**
	 * Compile Var Tag
	 * @param mixed $tagStr
	 * @param mixed $pattern
	 * @return string
	 */
	public function compileVarTag($tagStr, string $pattern = '/([%|\-|+|*|\/|\(|\)])/'): string
	{
		return $this->tagCompiler->compileVarTag($tagStr, $pattern);
	}

	/**
	 * Compile Foreach Tag
	 * @param mixed $tagCommand
	 * @param mixed $parseVar
	 * @return string
	 */
	public function compileForeachTag(string $tagCommand, array $parseVar): string
	{
		return $this->tagCompiler->compileForeachTag($tagCommand, $parseVar);
	}

	/**
	 * View Include
	 * @param mixed $parse
	 */
	private function viewInclude(array $parse): void
	{
		// 安全检查：确保文件路径不包含恶意字符
		$include_file = $parse['file'] ?? '';
		if (str_contains($include_file, '..') || str_contains($include_file, '://') || !preg_match('/^[a-zA-Z0-9_\/\\.-]+$/', $include_file)) {
			throw new \Exception('Invalid template include path: ' . $include_file);
		}
		
		$include_file = str_replace('/', $this->tplSeparate, $include_file);
		$templateDir = empty($parse['vars']['templateDir']) ? $this->templateDir : $parse['vars']['templateDir'];
		
		// 合并变量前进行安全检查
		$safeVars = [];
		foreach ($parse['vars'] as $key => $value) {
			if (is_string($key) && preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
				$safeVars[$key] = $value;
			}
		}
		
		$this->viewVars = array_merge($this->viewVars, $safeVars);

		// 保存并清空 foreach 上下文，使子模板编译时不继承父模板的 foreach
		// 变量绑定，{$vo} 编译为 \$viewVars['vo'] 而非依赖组号的
		// \$viewVars['foreach'][N]['vo']，与上面 vars 传值方式对齐。
		$savedForeach = $this->foreachParameter;
		$this->foreachParameter = [];

		$this->templateExist($include_file, false);
		echo $this->compileFile($include_file, '', false, $templateDir);

		$this->foreachParameter = $savedForeach;
	}
	
	/**
	 * 变量修饰器
	 *
	 * 对变量应用指定的修饰器，支持自定义修饰器和静态缓存优化
	 *
	 * @param string $modifier 修饰器名称
	 * @param mixed $value 要修饰的变量值
	 * @param mixed ...$params 修饰器的参数列表
	 * @access private
	 * @return mixed 修饰后的变量值
	 */
	private function viewModifier(string $modifier, mixed $value, ...$params): mixed
	{
		// 检查修饰器是否已缓存
		if (isset(self::$modifierCache[$modifier]) && self::$modifierCache[$modifier] !== false) {
			return call_user_func_array(self::$modifierCache[$modifier], array_merge([$value], $params));
		}
		
		$_file = CANDYPATH . 'Candy/Core/Impl/Modifier/' . $modifier . '.php';
		if (file_exists($_file)) {
			include $_file;
			
			// 缓存修饰器函数名
			self::$modifierCache[$modifier] = 'view_modifier_' . $modifier;
			
			if (function_exists(self::$modifierCache[$modifier])) {
				$args = array_merge([$value], $params);
				return call_user_func_array(self::$modifierCache[$modifier], $args);
			}
		}
		
		// 缓存不存在的修饰器
		self::$modifierCache[$modifier] = false;
		return $value;
	}

	/**
	 * Run Template Include
	 * @param mixed $compilePath
	 */
	public function runTemplateInclude(string $compilePath): void
	{
		listen('template_include');
		$viewVars = &$this->viewVars;
		include $compilePath;
	}


	/**
	 * Get Left Delimiter
	 * @return string
	 */
	public function getLeftDelimiter(): string { return $this->leftDelimiter; }
	/**
	 * Get Right Delimiter
	 * @return string
	 */
	public function getRightDelimiter(): string { return $this->rightDelimiter; }
	/**
	 * Get Template Dir
	 * @return string
	 */
	public function getTemplateDir(): string { return $this->templateDir; }
	/**
	 * Set Template Dir
	 * @param mixed $dir
	 * @param mixed $dir
	 */
	public function setTemplateDir(string $dir): void { $this->templateDir = $dir; }
	/**
	 * Get Template Path
	 * @return string
	 */
	public function getTemplatePath(): string { return $this->templatePath; }
	/**
	 * Set Template Path
	 * @param mixed $path
	 * @param mixed $path
	 */
	public function setTemplatePath(string $path): void { $this->templatePath = $path; }
	/**
	 * Get Compile Dir
	 * @return string
	 */
	public function getCompileDir(): string { return $this->compileDir; }
	/**
	 * Set Compile Dir
	 * @param mixed $dir
	 * @param mixed $dir
	 */
	public function setCompileDir(string $dir): void { $this->compileDir = $dir; }
	/**
	 * Get Compile Path
	 * @return string
	 */
	public function getCompilePath(): string { return $this->compilePath; }
	/**
	 * Set Compile Path
	 * @param mixed $path
	 * @param mixed $path
	 */
	public function setCompilePath(string $path): void { $this->compilePath = $path; }
	/**
	 * Get Cache Dir
	 * @return string
	 */
	public function getCacheDir(): string { return $this->cacheDir; }
	/**
	 * Get Cache Path
	 * @return string
	 */
	public function getCachePath(): string { return $this->cachePath; }
	/**
	 * Set Cache Path
	 * @param mixed $path
	 * @param mixed $path
	 */
	public function setCachePath(string $path): void { $this->cachePath = $path; }
	/**
	 * Get Tpl Name
	 * @return string
	 */
	public function getTplName(): string { return $this->tplName; }
	/**
	 * Set Tpl Name
	 * @param mixed $name
	 * @param mixed $name
	 */
	public function setTplName(string $name): void { $this->tplName = $name; }
	/**
	 * Get Tpl Suffix
	 * @return string
	 */
	public function getTplSuffix(): string { return $this->tplSuffix; }
	/**
	 * Get Tpl Separate
	 * @return string
	 */
	public function getTplSeparate(): string { return $this->tplSeparate; }
	/**
	 * Get Control
	 * @return string
	 */
	public function getControl(): string { return $this->control; }
	/**
	 * Get Action
	 * @return string
	 */
	public function getAction(): string { return $this->action; }
	/**
	 * Get Cache Life Time
	 * @return int
	 */
	public function getCacheLifeTime(): int { return $this->cacheLifeTime; }
	public function &getViewVars(): array { return $this->viewVars; }
	public function &getForeachParameter(): array { return $this->foreachParameter; }
	/**
	 * Set Foreach Parameter
	 * @param mixed $parameter
	 * @param mixed $parameter
	 */
	public function setForeachParameter(array $parameter): void { $this->foreachParameter = $parameter; }
	/**
	 * Get Ref
	 * @return array
	 */
	public function getRef(): array { return $this->ref; }

	/**
	 * Set Val
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function setVal(string $key, $value=null): void
	{
		$this->$key = $value;
	}
	
	/**
	 * 设置模板全局变量
	 *
	 * 设置可以在模板中直接使用的全局变量
	 *
	 * @param string $key 键名
	 * @param mixed $value 键值
	 * @access public
	 * @return void
	 */
	public function assignByRef(string $key, $value=null): void
	{
		$this->ref[$key] = $value;
	}
	
	/**
	 * 注册输出过滤器
	 *
	 * 注册模板输出前需要执行的过滤函数
	 *
	 * @param string $funcname 函数名
	 * @access public
	 * @return void
	 */
	public function registerOutputfilter(string $funcname): void
	{
		// 2026-11-13：统一走 HookPipeline —— response 通道 + 仅 html 白名单
		// 保留本方法是为了兼容旧调用点，语义等价于「只处理 HTML 的响应钩子」
		\Candy\Core\HookPipeline::add('response', $funcname, 10, ['html']);
	}
	/**
	 * Template Exist
	 * @param mixed $name
	 * @param mixed $single
	 * @return bool
	 */
	public function templateExist(string $name,bool $single = false): bool
	{
		$exist = false;
		$this->compileCache->templateName($name, $single);
		$templatePath = $this->compileCache->templatePath();
		
		//404特殊处理
		if(file_exists($templatePath) || (!empty($this->compilePath) && file_exists($this->compilePath)))
			$exist = true;
		
		//404
		if($name == 'Public/404' && $exist === false){
			if(!file_exists($templatePath)){
				$this->templateDir = C('CANDYROOT');
				$this->templatePath = C('CANDYROOT') . $this->tplName;
				$this->compileDir = C('RUNTIME') . 'Comps/404/';
				if(file_exists($this->templatePath)){
					$exist = true;
				}
			}
		}
		
		//不存在
		if($exist === false && !in_array($name, ['Public/404', 'Public/noAuth'])){
			if(C('LASTPATH') == '/mobile'){
				if(!is_null(C('TPL_PATH'))){
					$lastpath = is_dir(C('APP_PATH') . C('TPL_PATH') .'/'. C('TPLSTYLE') .'/pc') ? '/pc' : '';
				}else{
					$lastpath = is_dir(C('APP_PATH') .'Views/'. C('TPLSTYLE') .'/pc') ? '/pc' : '';
				}
				
				if(file_exists(dirname($this->templateDir) . $lastpath . '/'. $this->tplName)){
					//重新规划目录
					$this->templateDir = dirname($this->templateDir) . $lastpath;
					$this->compileDir = dirname($this->compileDir) . $lastpath;
					$this->cacheDir = dirname($this->cacheDir) . $lastpath;
					$this->assign('res', dirname(dirname(C('C_RES'))) . $lastpath .'/Resource/');
					$exist = true;
				}
			}
			
			if(!$exist){
				$this->halt('模板'. $this->tplName .'不存在。');
				
				//模板路径
				$tmpfile = str_replace(C('CANDYROOT'), '/', $this->templatePath);
				if(is_null(C('ISADDON'))){
					Debug::addMsg('使用的模板路径: <b> '. $tmpfile . ' </b>');
				}else{
					Debug::addMsg('使用' . C('APPNAME') . '插件的模板路径: <b>'. $tmpfile . '</b>');
				}
			}
		}
		return $exist;
	}
    /**
     * Assign Clear
     * @param mixed $tplVar
     */
    public function assignClear(mixed $tplVar): void
	{
        if(is_array($tplVar)){
            foreach($tplVar as $curr_var){
                unset($this->viewVars[$curr_var]);
			}
        }else{
            unset($this->viewVars[$tplVar]);
		}
    }
	
	/**
     * 清除所有的变量
     *
     * 清除所有已分配的模板变量
     *
     * @access public
     * @return void
     */
    public function assignClearAll(): void
	{
        $this->viewVars = [];
    }
	
	/**
     * 清除API数据变量
     *
     * 清除指定的API数据变量，可以是单个变量名或变量数组
     *
     * @param string|array $tplVar 变量名或变量数组
     * @access public
     * @return void
     */
    public function apidataClear($tplVar): void
	{
        if(is_array($tplVar)){
            foreach($tplVar as $curr_var){
                $this->data2api($curr_var);
			}
        }else{
            $this->data2api($tplVar);
		}
    }
	
	/**
     * 清除所有API数据变量
     *
     * 清除所有已设置的API数据变量
     *
     * @access public
     * @return void
     */
    public function apidataClearAll(): void
	{
        $this->data2api();
    }
	
	/**
	 * API数据管理
	 *
	 * 用于管理API响应数据，支持数据的设置、获取、清除等操作
	 *
	 * @param string|null $key 数据键名，为空字符串时返回所有数据
	 * @param mixed|null $value 数据值，为null时表示清除数据或获取数据
	 * @param string|null $call_func 可选的回调函数，用于处理数据值
	 * @access private
	 * @return mixed 当$key为空字符串时返回所有数据，否则返回false
	 */
private function data2api(?string $key=null, $value=null,?string $call_func=null): mixed
	{
		static $data = [];
		    
		if(is_null($value)){
			if(is_null($key)){
				$data = [];
			}elseif($key == ''){
				$apiData = C('APIDATA');
				if(is_array($apiData)){
					return array_merge($apiData, $data);
				}
				return $data;
			}else{
				$data[$key] = null;
			}
		}else{
			$data[$key] = ($call_func && function_exists($call_func)) ? $call_func($value) : $value;
		}
		return false;
	}
	
	/**
	 * 信息提示
	 *
	 * 显示错误信息并终止执行
	 *
	 * @param string|null $msg 错误信息
	 * @access public
	 * @return void
	 */
	public function halt(?string $msg): void
	{
		showExceptionMsg('halt', $msg);
	}
	
	/*
	 * 析构函数
	 */
	public function __destruct()
	{
		ob_end_clean();
		$this->viewVars = [];   //模板变量数组
		$this->foreachParameter = [];   //foreach模块参数
		$this->ref = [];   //模板全局变量组
		$this->compilePath = '';
	}
}
