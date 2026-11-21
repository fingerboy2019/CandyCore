<?php
/**
 * Security — 中间件调度子模块
 *
 * 从 Middleware.inc.php 加载中间件、按类型（Init / Before / After / Over / Finish）调度执行，
 * 并提供 getRequestContext() 供中间件子类获取请求上下文。
 *
 * @package Candy\Core\Security
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-11 09:30:00
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Security;

use Candy\Core\Security as SecurityBase;

trait MiddlewareRunner
{
	/** @var array<string, array<string, object>> 按类型分组的中间件实例 */
	private static $Middlewares;

	/**
	 * 运行指定类型的中间件
	 *
	 * @param string $type 中间件类型，如 Init / Before / After / Over / Finish
	 * @param string $content Over 类型待处理内容
	 * @return mixed Over 类型返回处理后的内容，其余无返回值
	 */
    public static function runMiddlewares(string $type = 'Init', string $content = '')
	{
		if(!empty(self::$Middlewares[$type])){
			// 去重：同一中间件实例只执行一次（别名会导致重复注册）
			$executed = [];

			if($type == 'Over'){
				foreach(self::$Middlewares[$type] as $middleware){
					$instanceId = spl_object_id($middleware);
					if(!isset($executed[$instanceId])){
						$content = $middleware->handle($content);
						$executed[$instanceId] = true;
					}
				}
			}else{
				foreach(self::$Middlewares[$type] as $middleware){
					$instanceId = spl_object_id($middleware);
					if(!isset($executed[$instanceId])){
						$middleware->handle();
						$executed[$instanceId] = true;
					}
				}
			}
		}

		if(isDebug() === false && $type == 'After'){
			self::_getInfo();
		}

		if($type == 'Over'){
			return $content;
		}
	}

	/**
	 * 洋葱管道：包裹一段核心逻辑，按 Pipe 阶段中间件逐层包裹后执行
	 *
	 * 与 Init/Before/After/Over/End 的「各自独立执行」不同，Pipe 是**洋葱模型**：
	 * 每个中间件收到 $next 回调，调不调、何时调由它决定 —— 可在前做前置逻辑、
	 * 在后做后置逻辑，或直接不调 $next 实现短路。
	 *
	 * 中间件约定：public function handle(callable $next)，返回值即 $next() 的返回值。
	 * 响应内容不在参数里传（框架写在 Respond::$buff），需要时用 Respond::create() 取。
	 *
	 * ⚠️ FPM 下控制器结束调 stop() 会 exit，$next() 之后的代码不执行；
	 *    需要「后置」逻辑请用 try { return $next(); } finally { ... }，在 workerman 下可靠。
	 *
	 * 未配置任何 Pipe 中间件时直接执行 $core()，零额外开销。
	 *
	 * @param callable $core 被包裹的核心逻辑
	 * @return mixed $core() 的返回值
	 */
	public static function runPipeline(callable $core): mixed
	{
		$list = self::$Middlewares['Pipe'] ?? [];
		if (empty($list)) {
			return $core();
		}

		// 去重：别名会导致同一实例注册多次
		$unique = [];
		foreach ($list as $middleware) {
			$unique[spl_object_id($middleware)] = $middleware;
		}
		$unique = array_values($unique);

		// 从内到外包裹：最内层是 $core，最外层是第一个中间件
		$pipeline = $core;
		foreach (array_reverse($unique) as $middleware) {
			$next = $pipeline;
			$pipeline = static function () use ($middleware, $next) {
				return $middleware->handle($next);
			};
		}

		return $pipeline();
	}
    /** 从配置加载中间件 @return void */
    protected static function loadMiddleware(): void
    {
		$Middlewares = loadConfig('Middleware');
		self::addMiddlewares($Middlewares);
    }
    /**
     * 公开注册入口（供路由规则等外部使用）
     *
     * addMiddleware 是 protected static，路由层无法直接调用；这里做一层薄封装。
     * 同名中间件重复注册会覆盖，因此同一规则重复命中不会叠加执行。
     *
     * @param string|object $middleware 类名或已实例化对象
     * @param string        $type       阶段：Init/Before/After/Over/End
     * @param array         $config     构造配置
     * @return void
     */
    public static function register($middleware, string $type = 'After', array $config = []): void
    {
        if ($type === '') {
            $type = 'After';
        }
        self::addMiddleware($middleware, '', $type, $config);
    }

    /** 批量注册中间件 @param array $middlewares @return void */
    protected static function addMiddlewares(array $middlewares): void
    {
		if(!empty($middlewares)){
			foreach ($middlewares as $type => $middlewareList) {
				if(!empty($middlewareList)){
					foreach($middlewareList as $key=>$middleware){
						$config = [];
						$middlewareClass = '';

						// 支持两种配置格式：
						// 1. 简单类名：'APP\Middleware\JwtAuthMiddleware'（$key 是数字索引，$middleware 是类名）
						// 2. 带配置：'APP\Middleware\JwtAuthMiddleware' => ['enabled' => true, ...]（$key 是类名，$middleware 是配置）
						if (is_string($middleware) && is_numeric($key)) {
							// 格式 1：简单类名
							$middlewareClass = $middleware;
							$config = [];
						} elseif (is_string($key) && class_exists($key)) {
							// 格式 2：类名作为 key，配置作为 value
							$middlewareClass = $key;
							$config = is_array($middleware) ? $middleware : [];
						} elseif (is_array($middleware)) {
							// 格式 2 变体：['class' => '类名', 'config' => [...]]
							$middlewareClass = $middleware['class'] ?? $middleware[0] ?? '';
							$config = $middleware['config'] ?? $middleware[1] ?? [];
						}

						if (!empty($middlewareClass) && is_subclass_of($middlewareClass, SecurityBase::class)) {
							self::addMiddleware($middlewareClass, is_numeric($key) ? '' : $key, $type, $config);
						}
					}
				}

			}
		}

    }
    /** 注册单个中间件（支持类名或实例） @return void */
    protected static function addMiddleware($middleware, string $name = '', string $type = '', array $config = []): void
    {
        if (is_string($middleware)) {
            $instance = \Candy\Core\Container::getInstance()->make($middleware, ['config' => $config], true);
            $middlewareName = $name ?: $instance->getName();
            self::$Middlewares[$type][$middlewareName] = $instance;
            
            // 注册别名
            foreach ($instance->getAliases() as $alias) {
                self::$Middlewares[$type][$alias] = $instance;
            }
        } else {
            // 已经是实例化对象
            if ($name) {
                self::$Middlewares[$type][$name] = $middleware;
            } else {
                self::$Middlewares[$type][$middleware->getName()] = $middleware;
                foreach ($middleware->getAliases() as $alias) {
                    self::$Middlewares[$type][$alias] = $middleware;
                }
            }
        }
    }
    /**
     * 获取当前请求上下文（路径、方法、是否写操作、GET/POST 等）
     * @return array<string, mixed>
     */
    protected static function getRequestContext(): array
    {
        // 长驻进程下禁止 static 缓存，否则后续请求会复用首次请求的上下文
        // 每次重新计算
		$server = I('server.', [], '');
		$get = I('get.', [], '');
		$post = I('post.', [], '');
		$cookie = cookie();
        $pathInfo = $server['PATH_INFO'] ?? '';
        
        if (empty($pathInfo)) {
            $requestUri = $server['REQUEST_URI'] ?? '/';
            $pathInfo = parse_url($requestUri, PHP_URL_PATH) ?? '/';
            $scriptName = $server['SCRIPT_NAME'] ?? '';
            if (!empty($scriptName) && strpos($pathInfo, $scriptName) === 0) {
                $pathInfo = substr($pathInfo, strlen($scriptName));
            }
            if (empty($pathInfo)) {
                $pathInfo = '/';
            }
        }
        
        return [
            'path' => strtolower($pathInfo),
            'path_info' => $pathInfo,
            'request_uri' => $server['REQUEST_URI'] ?? '/',
            'method' => $server['REQUEST_METHOD'] ?? 'GET',
            'is_ajax' => isset($server['HTTP_X_REQUESTED_WITH']) && strtolower($server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest',
            'is_get' => ($server['REQUEST_METHOD'] ?? 'GET') === 'GET',
            'is_post' => ($server['REQUEST_METHOD'] ?? 'GET') === 'POST',
            'is_put' => ($server['REQUEST_METHOD'] ?? 'GET') === 'PUT',
            'is_delete' => ($server['REQUEST_METHOD'] ?? 'GET') === 'DELETE',
            'is_write' => in_array($server['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'DELETE', 'PATCH']),
            'ip' => $server['REMOTE_ADDR'] ?? '',
            'user_agent' => $server['HTTP_USER_AGENT'] ?? '',
            'referer' => $server['HTTP_REFERER'] ?? '',
            'get' => $get,
            'post' => $post,
            'cookie' => $cookie,
            'server' => $server,
        ];
    }
}
