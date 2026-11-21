<?php
/**
 * Candy框架控制器基类
 *
 * 控制器基类，供 Application 通过 `extends Action` 派生；**非 final**，允许项目继承。
 * 视图能力通过组合 {@see ViewRenderer}，在基类提供与 View 同名的显式代理方法，
 * 控制器可直接 `$this->display()` 调用，无需 `$view->` 或魔法转发。
 *
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 10:00:00
 * @package Candy\Core
 */

declare(strict_types=1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Action
{
	protected ?ViewRenderer $viewRenderer = null;

	/**
	 * View Renderer
	 * @return ViewRenderer
	 */
	protected function viewRenderer(): ViewRenderer
	{
		return $this->viewRenderer ??= Container::getInstance()->make(ViewRenderer::class, [], true);
	}

	/**
	 * Get View Renderer
	 * @return ViewRenderer
	 */
	public function getViewRenderer(): ViewRenderer
	{
		return $this->viewRenderer();
	}

	/**
	 * Assign
	 * @param mixed $args
	 * @param mixed $value
	 * @param mixed $apidata
	 * @param mixed $callFunc
	 */
	public function assign(mixed $args, mixed $value = null, bool $apidata = true, string $callFunc = ''): void
	{
		$this->viewRenderer()->assign($args, $value, $apidata, $callFunc);
	}

	/**
	 * Display
	 * @param mixed $name
	 * @param mixed $cacheId
	 * @param mixed $single
	 * @param mixed $templateDir
	 */
	public function display(?string $name = null, string $cacheId = '', bool $single = false, ?string $templateDir = null): void
	{
		$this->viewRenderer()->display($name, $cacheId, $single, $templateDir);
	}

	/**
	 * Fetch
	 * @param mixed $name
	 * @param mixed $cacheId
	 * @param mixed $single
	 * @param mixed $templateDir
	 * @return string
	 */
	public function fetch(?string $name = null, string $cacheId = '', bool $single = false, ?string $templateDir = null): string
	{
		return $this->viewRenderer()->fetch($name, $cacheId, $single, $templateDir);
	}

	/**
	 * Template Exist
	 * @param mixed $name
	 * @param mixed $single
	 * @return bool
	 */
	public function templateExist(string $name, bool $single = false): bool
	{
		return $this->viewRenderer()->templateExist($name, $single);
	}

	/**
	 * Halt
	 * @param mixed $msg
	 */
	public function halt(?string $msg): void
	{
		$this->viewRenderer()->halt($msg);
	}

	/**
	 * Assign By Ref
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function assignByRef(string $key, mixed $value = null): void
	{
		$this->viewRenderer()->assignByRef($key, $value);
	}

	/**
	 * Assign Clear
	 * @param mixed $tplVar
	 */
	public function assignClear(mixed $tplVar): void
	{
		$this->viewRenderer()->assignClear($tplVar);
	}

	/**
	 * Assign Clear All
	 */
	public function assignClearAll(): void
	{
		$this->viewRenderer()->assignClearAll();
	}

	/**
	 * Apidata Clear All
	 */
	public function apidataClearAll(): void
	{
		$this->viewRenderer()->apidataClearAll();
	}

	/**
	 * Apidata Clear
	 * @param mixed $tplVar
	 */
	public function apidataClear(mixed $tplVar): void
	{
		$this->viewRenderer()->apidataClear($tplVar);
	}

	/**
	 * Set Val
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function setVal(string $key, mixed $value = null): void
	{
		$this->viewRenderer()->setVal($key, $value);
	}

	/**
	 * Register Outputfilter
	 * @param mixed $funcname
	 */
	public function registerOutputfilter(string $funcname): void
	{
		$this->viewRenderer()->registerOutputfilter($funcname);
	}

	/**
	 * Compile File
	 * @param mixed $templateName
	 * @param mixed $cacheId
	 * @param mixed $single
	 * @param mixed $templateDir
	 * @param mixed $compileDir
	 * @return string
	 */
	public function compileFile(?string $templateName, string $cacheId = '', bool $single = false, ?string $templateDir = null, string $compileDir = ''): string
	{
		return $this->viewRenderer()->compileFile($templateName, $cacheId, $single, $templateDir, $compileDir);
	}

	/**
	 * Get Control
	 * @return string
	 */
	public function getControl(): string
	{
		return $this->viewRenderer()->getControl();
	}

	/**
	 * Get Action
	 * @return string
	 */
	public function getAction(): string
	{
		return $this->viewRenderer()->getAction();
	}

	/**
	 * Get Caching
	 * @return bool
	 */
	public function getCaching(): bool
	{
		return $this->viewRenderer()->getCaching();
	}

	/**
	 * Set Caching
	 * @param mixed $caching
	 */
	public function setCaching(bool $caching): void
	{
		$this->viewRenderer()->setCaching($caching);
	}

	/**
	 * Get Content
	 * @return string
	 */
	public function getContent(): string
	{
		return $this->viewRenderer()->getContent();
	}

	/**
	 * 控制器核心执行方法
	 *
	 * Workerman 长驻时 `isDebug()` 常为 false，控制器以单例复用；
	 * 须在每次 working 丢弃上一请求的 ViewRenderer，避免 title/列表等模板变量串请求。
	 */
	public function working(): void
	{
		// 路由已完成，刷新 Request 实例以捕获最终的超全局状态
		// 此后的控制器 init()/action() 可通过 request()->xxx() 安全读取
		\Candy\Core\Request::refresh();

		$this->viewRenderer = null;

		$control = $this->getControl();
		$action = $this->getAction();

		if(is_null(C('S404'))){
			[$check, $allow] = Node::checkAuth();

			if(method_exists($this, 'init')){
				call_user_func([$this, 'init']);
			}

			if(method_exists($this, $action)){
				if (preg_match('/^[_]/i', $action)) {
					showExceptionMsg('error', $action . '操作被禁止访问。');
				}

				if($check){
					Debug::addMsg('<font color="red">'. C('APPNAME') . (is_null(C('ISADDON')) ? '项目' : '插件') .'开启操作权限自动检查!</font>', 6);
				}
				if($allow){
					listen('action_begin');
					$before_action = '_before_'. $action .'_';
					// 洋葱中间件（Pipe 阶段）：包裹控制器动作，支持前置/后置/短路
					Security::runPipeline(function () use ($action, $before_action) {
						if(method_exists($this, $before_action)){
							call_user_func([$this, $before_action]);
						}
						call_user_func([$this, $action]);
					});
					listen('action_end');
				}else{
					if (!is_null(C('API'))) {
						$noauth = L($action . 'NoAuth') ?: L('noPermission') ?: '权限不够!';
						$this->assign(['code' => 403, 'message' => $noauth]);
						$this->display();
						return;
					}
					$exist = $this->templateExist('Public/noAuth');
					if($exist){
						$this->display('Public/noAuth');
					}else{
						$noauth = L($action.'NoAuth') ?: '权限不够!';
						showExceptionMsg('error', $noauth);
					}
				}
			}else{
				$exist = $this->templateExist($control .'/'. $action);
				if($exist){
					$this->display();
				}else{
					Debug::addMsg('<font color=\'red\'>没有'. $action . '这个操作！</font>', 4);
					$this->display404('没有'. $action . '这个操作！');
				}
			}
		}else{
			if(is_dir(C('APP_PATH'))){
				$this->display404($control .'控制器不存在。');
			}else{
				$this->display404(C('APPNAME').'项目'. (is_null(G('Addons')) ? '' : '(扩展)') .'不存在。');
			}
		}
		Security::runMiddlewares('Finish');
		listen('app_end');
	}

	/**
	 * Redirect
	 * @param mixed $path
	 * @param mixed $args
	 */
	protected function redirect(string $path, string $args = ''): void
	{
		if(!is_null(C('API'))){
			$msg = ['status'=>'ok','msg'=>'操作重定向','operate'=>U($path,$args,C('API'))];
			$this->assign($msg);
			$this->display();
		}
		$redirectUrl = U($path, $args);
		$scriptUrl = json_encode(
			$redirectUrl,
			JSON_UNESCAPED_UNICODE
			| JSON_UNESCAPED_SLASHES
			| JSON_HEX_TAG
			| JSON_HEX_AMP
			| JSON_HEX_APOS
			| JSON_HEX_QUOT
		);
		Respond::buff("<script>\nlocation=" . $scriptUrl . ";\n</script>");
		// 同时输出标准 Location，避免 302 只有脚本体而无法被浏览器、网关和 API 客户端继续跟随。
		Respond::create('', 'html', 302)
			->header(['Location' => $redirectUrl])
			->send();
		// FPM 在请求结束时自然退出；内置协程 WebServer 会继续执行后续路由，
		// 必须立刻中断，否则已写入的 302 会被后续 HTML 响应覆盖成 200。
		if (isWebServer()) {
			stop();
		}
	}

	/**
	 * Success
	 * @param mixed $mess
	 * @param mixed $timeout
	 * @param mixed $location
	 * @param mixed $title
	 * @return bool
	 */
	protected function success(string $mess='操作成功',int $timeout=1,string $location='',string $title=''):bool
	{
		return $this->showMessage($mess, $timeout, $location, $title, true);
	}

	/**
	 * Error
	 * @param mixed $mess
	 * @param mixed $timeout
	 * @param mixed $location
	 * @param mixed $title
	 * @return bool
	 */
	protected function error(string $mess='操作失败',int $timeout=3,string $location='',string $title=''):bool
	{
		return $this->showMessage($mess, $timeout, $location, $title, false);
	}

	/**
	 * Show Message
	 * @param mixed $mess
	 * @param mixed $timeout
	 * @param mixed $location
	 * @param mixed $title
	 * @param mixed $isSuccess
	 * @return bool
	 */
	private function showMessage(string $mess, int $timeout, string $location, string $title, bool $isSuccess): bool
	{
		$this->assign('code', $isSuccess ? 0 : 1);
		$this->assign('mark', $isSuccess ? 'success' : 'error');
		$this->pub($mess, $timeout, $location, $title);

		$exist = $this->templateExist('Public/success');
		if($exist){
			$this->display('Public/success');
		}
		return false;
	}

	/**
	 * Pub
	 * @param mixed $mess
	 * @param mixed $timeout
	 * @param mixed $location
	 * @param mixed $title
	 */
	private function pub(string $mess,int $timeout,string $location,string $title): void
	{
		$this->setCaching(false);

		if(is_array($mess))
			$mess = implode(',', $mess);

		$this->assign('message', $mess);

		if(!is_null(C('API'))){
			if(!is_null(C('APIDATA'))){
				$this->assign('apidata', C('APIDATA'));
			}

			$this->display();
			stop();
		}

		if($location==''){
			$location = Request::inCoroutine()
				? (string)R()->server('HTTP_REFERER', '')
				: (string)I('server.HTTP_REFERER', '', '');
		}else if(stristr($location, 'http://')){
			$location=urldecode($location);
		}else{
			$location = U($location);
		}

		$href = $location;

		$this->assign('timeout', $timeout);
		$this->assign('location', $location);
		$this->assign('href', $href);
	}

	/**
	 * Instance
	 * @param mixed $className
	 * @return object
	 */
	protected function instance(string $className):object
	{
		return Container::getInstance()->get($className);
	}

	/**
	 * Display404
	 * @param mixed $msg
	 */
	private function display404(string $msg): void
	{
		if(isDebug()){
			$msg = C('S404') === true ? $msg : C('S404');
			$this->halt($msg);
		}else{
			$exist = $this->templateExist('Public/404');
			if($exist){
				$this->display('Public/404');
			}else{
				Respond::create('', 'html', 404)->send();
			}
		}
	}
}
