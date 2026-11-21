# View 类

## 概述

* Candy框架 模板引擎核心类
 * 
 * 该类负责处理模板的编译、渲染和显示，支持变量分配、模板继承、标签解析等功能。
 * 提供完整的模板引擎解决方案，包括缓存机制、变量调节器、标签编译、多模板目录
 * 结构、千人千面个性化需求等特性，是框架中负责视图渲染的核心组件。
 * 
 * @package Candy\Core
 * @author 刘森 <fingerboy@qq.com>
 * @date 2019-07-26 11:31:47

## 主要方法


### /**
 * Candy框架 模板引擎核心类
 * 
 * 该类负责处理模板的编译、渲染和显示，支持变量分配、模板继承、标签解析等功能。
 * 提供完整的模板引擎解决方案，包括缓存机制、变量调节器、标签编译、多模板目录
 * 结构、千人千面个性化需求等特性，是框架中负责视图渲染的核心组件。
 * 
 * @package Candy\Core
 * @author 刘森 <fingerboy@qq.com>
 * @date 2019-07-26 11:31:47
 */
 
declare(strict_types=1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class View {
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
	 * 输出过滤器
	 * @var array
	 * @access private
	 */
	private array  $outPutFilter = ['beforeHtmlShow'];
	
	/**
	 * 缓存开关状态
	 * @var int
	 * @access public
	 */
	public int $caching = 0;
	
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

	/**
	 * 构造方法，用于初始化模板引擎
	 * 
	 * 初始化模板引擎的各种配置参数，包括控制器、操作、分隔符、目录路径等。
	 * 根据API模式设置不同的初始化流程，同时初始化缓存实例。
	 * 配置内容包括模板分隔符、目录路径、模板后缀、变量数组等。
	 * 
	 * @access public
	 * @return void
	 */
	public function __construct(){
		// 初始化控制器和操作名称（保留多驼峰，与 Views/{Control}/ 一致）
		$this->control = \Candy\Extend\Str::controlName((string) C('CONTROL'));
		$this->action = C('ACTION');     // 默认操作名

		$this->templateDir = is_null(C('TEMPLATE_DIR')) ? C('CANDYROOT') :()


### /**
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
			Hook:()


### /**
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
	public function display(?string $name=null, string $cacheId = '', bool $single = false, ?string $templateDir = null):()


### /**
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
	public function fetch(?string $name=null,string $cacheId = '',bool $single = false,?string $templateDir = null):()


### /**
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
	private function makeContent(?string $name=null,string $cacheId = '',bool $single = false,?string $templateDir = null):()


### /**
	 * 模板变量分配
	 *
	 * 将变量分配到模板中，支持单个变量或数组批量分配。
	 * 同时处理API数据和函数回调。
	 *
	 * @param string|array $args 键名或数组
	 * @param mixed $value 键值
	 * @param bool $apidata 是否处理API数据
	 * @param string $call_func 回调函数名
	 * @access public
	 * @return void
	 */
	public function assign($args, $value = null,bool $apidata = true,string $call_func = ''):()


### /**
	 * 模板编译方法
	 *
	 * 编译模板文件，处理模板内容并返回编译后的结果。
	 *
	 * @param string|null $template_name 模板名称
	 * @param string $cacheId 缓存ID
	 * @param bool $single 是否单独处理
	 * @param string|null $templateDir 模板目录
	 * @param string $compile_dir 编译目录
	 * @access public
	 * @return string 编译后的模板内容
	 */
	public function compileFile(?string $template_name, string $cacheId = '', bool $single = false, ?string $templateDir = null, string $compile_dir = ''):()


### /**
	 * 设置模板名称
	 *
	 * 根据控制器、操作和分隔符生成模板名称，并处理千人千面个性化需求。
	 *
	 * @param string|null $name 模板名称
	 * @param bool $single 是否单独处理
	 * @access private
	 * @return void
	 */
	private function templateName(?string $name = null,bool $single = false):()


### /**
	 * 获取模板路径
	 *
	 * 根据模板名称和配置计算并返回模板文件的完整路径
	 * 优先检查插件目录，然后是默认目录
	 *
	 * @access private
	 * @return string 模板路径
	 */
	private function templatePath():()


### /**
	 * 设置模板缓存路径
	 *
	 * 根据模板名称和缓存ID生成缓存文件路径
	 * 支持纯静态缓存和动态缓存两种模式
	 *
	 * @param string $cacheId 缓存ID
	 * @access private
	 * @return void
	 */
	private function cachePath(string $cacheId):()

