<?php
/**
 * Candy框架 Container容器类
 * 
 * 依赖注入容器(Dependency Injection Container)，负责对象管理、自动解析、依赖注入等核心功能。
 * 实现了单例模式、对象缓存、方法调用、反射机制等关键特性，遵循PSR容器标准设计。
 * 通过实现ArrayAccess接口，支持数组式操作容器，同时支持Countable和IteratorAggregate接口。
 * 
 * 主要功能：
 * - 自动依赖解析和注入
 * - 对象实例缓存与单例管理
 * - 类、闭包、接口实现的绑定
 * - 反射API的封装与优化
 * - 缓存系统集成，支持容器数据持久化
 * 
 * @package Candy\Core
 * @author: 刘森 (fingerboy@qq.com) $
 * @date: 2023-10-23 10:43:14 $
 * @implements \ArrayAccess
 * @implements \Countable
 * @implements \IteratorAggregate
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Container implements \ArrayAccess {
	/**
     * 容器绑定标识，存储类名/标识与实际实现的映射关系
     * @var array<string, mixed> 键为类名或标识，值为类名、闭包或对象实例
     */
	protected $bind = [];
	
	/**
     * 反射类缓存，优化性能，避免重复创建反射对象
     * @var array<string, \ReflectionClass> 键为"Reflection"+类名，值为反射类实例
     */
	protected $reflectionBind = [];
	
	/**
     * 容器对象单例实例
     * @static
     * @var Container|null 容器单例对象
     */
	protected static $instance;
	
	
    /**
     * 容器中已创建的对象实例缓存
     * @var array<string, object> 键为类名或标识，值为对应的对象实例
     */
    protected $instances = [];

    /**
     * 容器实例化后的回调函数列表
     * @var array<string, array<callable>> 键为类名(支持通配符*)，值为回调函数数组
     */
    protected $invokeCallback = [];
    
    /**
     * 容器全局参数
     * @var array<string, mixed> 存储容器级别的配置参数
     */
    protected $parameter = [];

    /**
     * 缓存对象，用于持久化存储容器数据
     * @static
     * @var Cache|false 缓存对象实例或false(表示未启用缓存)
     */
    protected static $cache = false;
	
    /**
     * 获取容器单例实例
     * 
     * 实现单例模式，确保全局只有一个容器实例。
     * 在非CLI模式下，会初始化缓存系统以支持容器数据持久化。
     * 
     * @return Container 容器单例实例
     */
    public static function getInstance(): object
    {
        if (is_null(self::$instance)) {
            self::$instance = new static;
        }
		
        if (self::$cache === false) {
            //在CLI模式下 自身储存不依赖缓存
            if (!\Candy\Core\Cache::isFileDriver() && isWebServer() === false ) {
                self::$cache = \Candy\Core\Cache::store();
            }
        }
		
		return self::$instance;
	}

	/** 返回当前运行上下文的实例缓存；Swoole 协程不共享对象实例。 */
	protected function &instancesStore(): array
	{
		if (\Candy\Core\Request::inCoroutine()) {
			$ctx = CoroutineContext::getContext();
			if (!isset($ctx['_container_instances'])) {
				$ctx['_container_instances'] = [];
			}
			return $ctx['_container_instances'];
		}
		return $this->instances;
	}
	
	/**
     * 创建类的实例 已经存在则直接获取
     * 
     * 根据类名或标识创建或获取对象实例，支持自动依赖注入。
     * 如果指定的实例已存在且不强制创建新实例，则直接返回现有实例。
     * 支持实例化后立即执行指定的方法链。
     * 
     * @param string $abstract 类名或者标识
     * @param array $vars 构造函数参数
     * @param bool $newInstance 是否每次创建新的实例
     * @param array $invokeFunction 实例化后要调用的方法数组，格式为[[方法名, [参数]], ...]
     * @return mixed 创建的对象实例或类名(静态类)
     */
    public function make(string $abstract,array $vars = [],bool $newInstance = false,array $invokeFunction = []):mixed
    {
        $abstract = $this->getAlias($abstract);
		$instances =& $this->instancesStore();

		if (isset($instances[$abstract]) && $newInstance === false) return $instances[$abstract];
		if (isset($this->bind[$abstract]) && is_object($this->bind[$abstract])) {
			return $this->bind[$abstract];
		}
		
        if (isset($this->bind[$abstract]) && $this->bind[$abstract] instanceof \Closure) {
            $object = $this->invokeFunction($this->bind[$abstract], $vars);
        } else {
            $object = $this->invokeClass($abstract, $vars);
        }
		
		//执行方法
		if (is_object($object) && count($invokeFunction) > 0){
			//多个方法
			if (is_array($invokeFunction[0])){
				foreach($invokeFunction as $infunc){
					[$func, $funcVars] = $infunc;
					$object->$func(...$funcVars);
				}
			}else{
				[$func, $funcVars] = $invokeFunction;
				$object->$func(...$funcVars);
			}
		}
		
        if ($newInstance === false) {
			$instances[$abstract] = $object;
        }
        
        return $object;
    }
	
    /**
     * 删除容器中的对象实例
     * 
     * 从容器实例缓存中删除指定的对象，同时更新持久化缓存(如果启用)。
     * 
     * @param string $name 类名或者标识
     * @return void
     */
    public function delete($name): void
    {
        $name = $this->getAlias($name);
        
		//删除对象
		$instances =& $this->instancesStore();
		if (isset($instances[$name])) {
			unset($instances[$name]);
        }
    }
	
	/**
     * 根据别名获取真实类名
     * 
     * 递归解析别名，直到获取到真实的类名。
     * 支持多级别名嵌套，确保最终获取到实际的实现类名。
     * 
     * @param string $abstract 类名或标识别名
     * @return string 解析后的真实类名
     */
    public function getAlias(string $abstract): string
    {
        if (isset($this->bind[$abstract])) {
            $name = $this->bind[$abstract];

            if (is_string($name)) {
                return $this->getAlias($name);
            }
        }
        return $abstract;
    }
	
    /**
     * 绑定一个类实例到容器
     * 
     * 将对象实例直接存储到容器中，同时更新绑定和实例缓存，供后续使用。
     * 支持链式调用，方便进行多个绑定操作。
     * 
     * @param string $abstract 类名或者标识
     * @param object $instance 类的实例
     * @return Container 当前容器实例，支持链式调用
     */
    public function instance(string $abstract,object $instance)
    {
        $abstract = $this->getAlias($abstract);

		$this->bind[$abstract] = $instance;
		$instances =& $this->instancesStore();
		$instances[$abstract] = $instance;
		
        return $this;
    }
	
	/**
     * 执行函数或者闭包方法 支持参数调用
     * 
     * 使用反射机制执行函数或闭包，自动处理参数绑定。
     * 支持传入自定义参数，当参数不足时会使用默认值。
     * 
     * @param string|\Closure $function 函数名或者闭包对象
     * @param array $vars 调用参数数组
     * @return mixed 函数执行结果
     * @throws \Exception 当函数不存在时抛出异常
     */
    public function invokeFunction(string|\Closure $function,array $vars = []):mixed
    {
        try {
            $reflect = new \ReflectionFunction($function);
        } catch (\Exception $e) {
            Debug::showMessage("function not exists: {$function}()", $function, $e->getMessage());
            stop();
        }

        $args = $this->bindParams($reflect, $vars);

        return $function(...$args);
    }
	
	
    /**
     * 调用反射执行类的方法 支持参数绑定
     * 
     * 使用反射机制调用类的方法，支持实例方法和静态方法。
     * 自动处理参数绑定，并支持将私有方法设为可访问。
     * 
     * @param mixed $method 方法名或数组格式的[类, 方法名]
     * @param array $vars 调用参数数组
     * @param bool $accessible 是否强制将方法设为可访问(用于私有/保护方法)
     * @return mixed 方法执行结果
     * @throws \ReflectionException 当方法不存在时抛出异常
     */
    public function invokeMethod(mixed $method,array $vars = [],bool $accessible = false):mixed
    {
        if (is_array($method)) {
            [$class, $method] = $method;
            $class = is_object($class) ? $class : $this->invokeClass($class);
        } else {
            // 静态方法
            [$class, $method] = explode('::', $method);
        }

        try {
            $reflect = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException $e) {
            $class = is_object($class) ? $class::class : $class;
			Debug::showMessage('method not exists: ' . $class . '::' . $method . '()', 'PHP');
			stop();
        }

        $args = $this->bindParams($reflect, $vars);

        // PHP 8.1 起 ReflectionMethod::setAccessible() 已无效果（反射默认可访问私有成员），
        // 8.5 正式弃用；$accessible 参数保留仅为兼容旧调用签名。

        return $reflect->invokeArgs(is_object($class) ? $class : null, $args);
    }
	
    /**
     * 调用反射执行callable 支持参数绑定
     * 
     * 智能识别callable类型并调用相应的执行方法。
     * 支持闭包、普通函数和类方法三种形式的可调用对象。
     * 
     * @param mixed $callable 闭包、函数名或类方法名
     * @param array $vars 调用参数数组
     * @param bool $accessible 是否强制将方法设为可访问(用于私有/保护方法)
     * @return mixed 执行结果
     */
    public function invoke(mixed $callable,array $vars = [],bool $accessible = false):mixed
    {
        if ($callable instanceof \Closure) {
            return $this->invokeFunction($callable, $vars);
        } elseif (is_string($callable) && false == str_contains($callable, '::')) {
            return $this->invokeFunction($callable, $vars);
        } else {
            return $this->invokeMethod($callable, $vars, $accessible);
        }
    }
	
	/**
     * 获取反射类
     * 
     * 创建并缓存类的反射对象，避免重复创建提高性能。
     * 如果类不存在则显示错误信息并终止执行。
     * 
     * @param string $class 类名
     * @return \ReflectionClass 反射类实例
     * @throws \ReflectionException 当类不存在时抛出异常
     */
    public function invokeReflectionClass(string $class): \ReflectionClass
    {
		$key = 'Reflection' . $class;
		$reflect = $this->reflectionBind[$key] ?? null;
		if($reflect === null){
			try {
				$reflect = new \ReflectionClass($class);
				$this->reflectionBind[$key] = $reflect;
			} catch (\ReflectionException $e) {
				Debug::showMessage('类库['. $class  .']不存在请检查！', 'PHP');
				stop();
			}
		}
		
		return $reflect;
	}
	
	/**
     * 调用反射执行类的实例化 支持依赖注入
     * 
     * 使用反射机制创建类的实例，自动处理构造函数参数依赖注入。
     * 支持特殊的__make静态方法，并在实例化后执行注册的回调函数。
     * 对于应用类还会进行属性注释的权限检查。
     * 
     * @param string $class 类名
     * @param array $vars 构造函数参数
     * @return mixed 创建的对象实例或类名(静态类)
     */
    public function invokeClass(string $class,array $vars = []):mixed
    {
        $reflect = $this->invokeReflectionClass($class);
        
		//注入注释权限限制功能（开发模式每次重扫；生产环境命中缓存则跳过）
		if(stripos($class, 'APP\\') !== false && (!Node::useNodeCache() || !isset(Node::getMethodsData()[$class]))){
			$methods = [];
			$methods[$class] = \Candy\Extend\Attribute\utils\Tool::getAnnotationInfo($class, $reflect);
			Node::saveMethodList($methods);
		}
		
		if ($reflect->hasMethod('__make')) {
			$method = $reflect->getMethod('__make');
			if ($method->isPublic() && $method->isStatic()) {
				//执行构造方法
				$args = $this->bindParams($method, $vars);
				$method->invokeArgs(null, $args);
			}
			return $class;
		}
        
		//构造函数
		$constructor = $reflect->getConstructor();

		// 纯静态工具类（无构造函数、无公开实例方法、存在静态方法）只返回类名
		// 避免 Model 等同时含静态辅助方法与实例方法时被误判为静态类
		if ($constructor === null) {
			$hasStaticMethod = false;
			$hasInstanceMethod = false;
			foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				$name = $method->getName();
				if (str_starts_with($name, '__')) {
					continue;
				}
				if ($method->isStatic()) {
					$hasStaticMethod = true;
				} else {
					$hasInstanceMethod = true;
					break;
				}
			}
			if ($hasStaticMethod && !$hasInstanceMethod) {
				return $class;
			}
		}
		$args = $constructor ? $this->bindParams($constructor, $vars) : [];
        $object = empty($args) ? $reflect->newInstance() : $reflect->newInstanceArgs($args);
		$this->invokeAfter($class, $object);
        return $object;
    }
	
	/**
     * 执行invokeClass回调
     * 
     * 在类实例化后执行注册的回调函数。
     * 支持全局回调(适用于所有类)和特定类回调。
     * 
     * @param string $class 对象类名
     * @param object $object 容器对象实例
     * @return void
     */
    protected function invokeAfter(string $class,object $object): void
    {
        if (isset($this->invokeCallback['*'])) {
            foreach ($this->invokeCallback['*'] as $callback) {
                $callback($object, $this);
            }
        }

        if (isset($this->invokeCallback[$class])) {
            foreach ($this->invokeCallback[$class] as $callback) {
                $callback($object, $this);
            }
        }
    }
	
    /**
     * 绑定参数
     * 
     * 根据反射信息自动绑定函数或方法的参数。
     * 支持按位置绑定和按名称绑定两种模式。
     * 当参数不足时，会尝试使用参数的默认值。
     * 
     * @param \ReflectionFunctionAbstract $reflect 反射函数/方法对象
     * @param array $vars 参数数组
     * @return array 绑定后的参数数组
     */
    public function bindParams(\ReflectionFunctionAbstract $reflect,array $vars = []): array
    {
        if ($reflect->getNumberOfParameters() === 0) {
            return [];
        }

        $params = $reflect->getParameters();
        $isList = $vars !== [] && array_is_list($vars);
        $args = [];

        foreach ($params as $index => $param) {
            $name = $param->getName();

            if (!$isList && array_key_exists($name, $vars)) {
                $args[$name] = $vars[$name];
                continue;
            }

            if ($isList && array_key_exists($index, $vars)) {
                $args[$name] = $vars[$index];
                continue;
            }

            if ($isList && $vars !== []) {
                $args[$name] = array_shift($vars);
                continue;
            }

            $paramType = $param->getType();
            if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                // 可选类依赖（含默认值）不自动注入，避免 make() 默认缓存成进程单例
                // 典型坑：ViewRenderer(?View $view = null) 在 Workerman 下串请求
                if ($param->isDefaultValueAvailable()) {
                    $args[$name] = $param->getDefaultValue();
                    continue;
                }
                $args[$name] = $this->make($paramType->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $args[$name] = null;
                continue;
            }

            Debug::showMessage('method param miss:' . $name);
        }

        return $args;
    }

    /**
     * 从容器解析类型依赖（供 bindParams 与外部扩展使用）
     */
    public function resolve(string $abstract, array $vars = [], bool $newInstance = false): mixed
    {
        return $this->make($abstract, $vars, $newInstance);
    }
	
    /**
     * 获取容器中的对象实例 不存在则创建
     * 
     * 静态方法，直接通过容器单例获取或创建对象实例。
     * 提供便捷的静态调用方式，内部调用make方法实现。
     * 
     * @param string $abstract 类名或者标识
     * @param array $vars 构造函数参数
     * @param bool $newInstance 是否每次创建新的实例
     * @param array $invokeFunction 实例化后要调用的方法数组
     * @return object 创建的对象实例
     */
    public static function pull(string $abstract,array $vars = [],bool $newInstance = false,array $invokeFunction = []):object
    {
        return self::getInstance()->make($abstract, $vars, $newInstance, $invokeFunction);
    }
	
    /**
     * 获取容器中的对象实例
     * 
     * 检查并获取容器中已存在的对象实例，不存在则返回null。
     * 与make方法不同，此方法不会自动创建新实例。
     * 
     * @param string $abstract 类名或者标识
     * @return mixed 对象实例或null
     */
    public function get(string $abstract):mixed
    {
        // 协程下优先返回当前协程绑定的 Request/Response
        if (\Candy\Core\Request::inCoroutine() && ($abstract === 'Request' || $abstract === 'Response')) {
			$ctx = CoroutineContext::getContext();
            if (isset($ctx['_container_' . $abstract])) {
                return $ctx['_container_' . $abstract];
            }
        }
        if ($this->has($abstract)) {
            return $this->make($abstract);
        }else{
			return null;
		}
    }
	
	    /** @var array<string, string> 请求级单例：abstract => concrete 类名（flush 时两个 key 都要清） */
    private array $scopedBindings = [];


    /**
     * 注册请求级单例（scoped）
     *
     * 与 bind() 的区别：bind 的实例在常驻模式下**跨请求复用**，scoped 的实例会在
     * 每次请求结束被 flushScopedInstances() 清掉，下次请求重新创建。
     *
     * 用于携带请求级状态的类（如 Service 里的 $userMapCache）——
     * 在 Workerman/Swoole 常驻模式下，不清理就会把上一个请求的用户数据带到下一个请求。
     *
     * @param string $abstract 抽象名
     * @param mixed  $concrete 具体实现（Closure / 类名 / 实例）
     * @return object
     */
    public function scoped(string $abstract, mixed $concrete = null): object
    {
        // 记录 concrete **原样**（含前导反斜杠）：make() 内部用 $abstract 原串做 $instances 的 key，
        // 这里若做 ltrim 就会清不掉，表现为 flush 后仍是同一对象
        $this->scopedBindings[$abstract] = is_string($concrete) ? $concrete : '';

        return $this->bind($abstract, $concrete);
    }

    /**
     * 清理所有 scoped 实例（每个请求/消息结束时调用）
     *
     * @return void
     */
    public function flushScopedInstances(): void
    {
        if ($this->scopedBindings === []) {
            return;
        }

        $instances =& $this->instancesStore();
        foreach ($this->scopedBindings as $abstract => $concrete) {
            // ① abstract key（S() 用 instance() 注册，会同时写 bind + instances）
            unset($instances[$abstract], $this->bind[$abstract]);
            // ② concrete 类名 key（S() 内部 make($concreteClass) 的缓存落点）
            //    两种写法都清：make() 拿到的可能是带前导反斜杠的全限定名
            if ($concrete !== '') {
                unset($instances[$concrete], $instances[ltrim($concrete, '\\')]);
                unset($this->bind[$concrete], $this->bind[ltrim($concrete, '\\')]);
            }
        }
    }

    /**
     * 判断某个 abstract 是否注册为 scoped
     *
     * @param string $abstract 抽象名
     * @return bool
     */
    public function isScoped(string $abstract): bool
    {
        return isset($this->scopedBindings[$abstract]);
    }

/**
     * 绑定一个类、闭包、实例、接口实现到容器
     * 
     * 支持多种绑定方式：
     * - 数组绑定：批量绑定多个类或接口
     * - 闭包绑定：注册可执行的闭包函数
     * - 对象绑定：直接绑定对象实例
     * - 类名绑定：绑定接口到具体实现类
     * 
     * @param string|array $abstract 类标识、接口名或绑定数组
     * @param mixed $concrete 要绑定的类、闭包或者实例
     * @return Container 当前容器实例，支持链式调用
     */
    public function bind(string|array $abstract, mixed $concrete = null): object
    {
        if (is_array($abstract)) {
            array_walk($abstract, [$this, 'bind']);
        }elseif ($concrete instanceof \Closure) {
            $this->bind[$abstract] = $concrete;
        } elseif (is_object($concrete)) {
            $this->instance($abstract, $concrete);
        } else {
            $this->bind[$abstract] = $concrete;
        }

        return $this;
    }

    /**
     * 设置容器参数
     * 
     * 用于存储和管理容器中的全局配置参数。
     * 会清空现有参数并设置新的参数字典。
     * 
     * @param array<string, mixed> $data 参数字典数组
     * @return void
     */
    public function setParameter(array $data = []):void
	{
        $this->parameter= [];
        foreach ($data as $key => $value){
            $this->parameter[$key] =$value;
        }
    }
	
    /**
     * 判断容器中是否存在类及标识
     * 
     * 检查指定的类名或标识是否已在容器中绑定或已有实例。
     * 同时检查绑定缓存和实例缓存。
     * 
     * @param string $abstract 类名或者标识
     * @return bool 是否存在绑定或实例
     */
    public function bound(string $abstract): bool
    {
		$instances =& $this->instancesStore();
		return isset($this->bind[$abstract]) || isset($instances[$abstract]);
    }
	
	/**
     * 判断容器中是否存在类及标识
     * 
     * has方法是bound方法的别名，用于保持接口一致性。
     * 实现PSR容器接口的has方法。
     * 
     * @param string $name 类名或者标识
     * @return bool 是否存在绑定或实例
     */
    public function has(string $name): bool
    {
		// Swoole 协程请求对象只保存在当前 Coroutine Context，不能写入进程级容器。
		if (\Candy\Core\Request::inCoroutine() && ($name === 'Request' || $name === 'Response')) {
			return isset(CoroutineContext::getContext()['_container_' . $name]);
		}
        return $this->bound($name);
    }
	
    /**
     * 判断容器中是否存在对象实例
     * 
     * 检查指定的类名或标识是否已有实例存在于实例缓存中。
     * 与bound/has方法不同，此方法仅检查实例缓存。
     * 
     * @param string $abstract 类名或者标识
     * @return bool 是否存在实例
     */
    public function exists(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);
		$instances =& $this->instancesStore();
		return isset($instances[$abstract]);
    }

	/**
	 * 清除请求级容器实例（Action / View / ViewRenderer）
	 *
	 * Workerman 长驻且非 DEBUG 时控制器为单例；View 若被容器缓存则会跨请求
	 * 残留 viewVars、templateDir、control。每请求丢弃 *Action 与视图相关实例。
	 *
	 * @return void
	 */
	public function flushActionInstances(): void
	{
		// 先清 scoped 实例（Service / 带请求级状态的类），否则常驻模式下会跨请求污染
		$this->flushScopedInstances();

		$instances =& $this->instancesStore();
		foreach (array_keys($instances) as $abstract) {
			if (!is_string($abstract)) {
				continue;
			}
			if (str_ends_with($abstract, 'Action')
				|| $abstract === View::class
				|| $abstract === ViewRenderer::class
			) {
				unset($instances[$abstract]);
			}
		}
	}
	
	    /**
     * 魔术方法：设置容器元素
     * 
     * 当使用赋值操作符设置属性时触发，将绑定操作转换为属性设置语法。
     * 例如：$container->app = new App(); 等效于 $container->bind('app', new App());
     * 
     * @param string $name 类名或标识
     * @param mixed $value 要绑定的值
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->bind($name, $value);
    }

    /**
     * 魔术方法：获取容器元素
     * 
     * 当访问容器属性时触发，将属性访问转换为获取操作。
     * 例如：$app = $container->app; 等效于 $app = $container->get('app');
     * 
     * @param string $name 类名或标识
     * @return mixed 获取的对象或null
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * 魔术方法：检查元素是否存在
     * 
     * 当使用isset()函数检查容器属性时触发，检查实例是否存在。
     * 例如：isset($container->app) 等效于 $container->exists('app');
     * 
     * @param string $name 类名或标识
     * @return bool 是否存在实例
     */
    public function __isset(string $name): bool
    {
        return $this->exists($name);
    }

    /**
     * 魔术方法：删除容器元素
     * 
     * 当使用unset()函数删除容器属性时触发，从实例缓存中删除对象。
     * 例如：unset($container->app) 等效于 $container->delete('app');
     * 
     * @param string $name 类名或标识
     * @return void
     */
    public function __unset(string $name): void
    {
        $this->delete($name);
    }
	
	    /**
     * ArrayAccess接口：检查偏移量是否存在
     * 
     * 当使用isset()函数检查数组访问时触发，实现数组式接口。
     * 例如：isset($container['app']) 等效于 $container->exists('app');
     * 
     * @param mixed $key 键名
     * @return bool 是否存在实例
     */
    public function offsetExists(mixed $key): bool
    {
        return $this->exists($key);
    }

    /**
     * ArrayAccess接口：获取偏移量的值
     * 
     * 当使用数组访问读取值时触发，实现数组式接口。
     * 例如：$app = $container['app'] 等效于 $app = $container->make('app');
     * 
     * @param mixed $key 键名
     * @return mixed 获取的对象
     */
    public function offsetGet(mixed $key): mixed
    {
        return $this->make($key);
    }

    /**
     * ArrayAccess接口：设置偏移量的值
     * 
     * 当使用数组访问设置值时触发，实现数组式接口。
     * 例如：$container['app'] = new App() 等效于 $container->bind('app', new App());
     * 
     * @param mixed $key 键名
     * @param mixed $value 要绑定的值
     * @return void
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->bind($key, $value);
    }

    /**
     * ArrayAccess接口：删除偏移量
     * 
     * 当使用unset()函数删除数组元素时触发，实现数组式接口。
     * 例如：unset($container['app']) 等效于 $container->delete('app');
     * 
     * @param mixed $key 键名
     * @return void
     */
    public function offsetUnset(mixed $key): void
    {
        $this->delete($key);
    }

    /**
     * Countable接口：获取容器中实例的数量
     * 
     * 当使用count()函数计算容器时触发，实现Countable接口。
     * 例如：count($container) 返回容器中实例的数量。
     * 
     * @return int 实例数量
     */
    public function count(): int
    {
		$instances =& $this->instancesStore();
		return count($instances);
    }

    /**
     * IteratorAggregate接口：获取迭代器
     * 
     * 允许容器对象被foreach遍历，实现IteratorAggregate接口。
     * 例如：foreach($container as $key => $instance) {...}
     * 
     * @return \ArrayIterator 数组迭代器实例
     */
    public function getIterator(): object
    {
		$instances =& $this->instancesStore();
		return new \ArrayIterator($instances);
    }
}
