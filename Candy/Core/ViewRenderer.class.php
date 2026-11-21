<?php
/**
 * Candy框架视图渲染器
 *
 * Action 与 View 的组合桥梁：封装模板引擎实例，暴露 assign / display / fetch 等
 * 显式 API，使控制器无需继承 View 即可获得完整渲染能力。
 *
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-24 06:05:54
 * @see Action 控制器基类，组合本类完成视图渲染
 * @see View  模板引擎核心实现
 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ViewRenderer
{
	private View $view;

	/**
	 * 构造渲染器并绑定全新 View
	 *
	 * 禁止构造参数注入 View：Container::bindParams 会对 `?View $view = null`
	 * 自动 make(View) 且默认缓存为进程单例；Workerman 下会跨请求复用
	 * viewVars / templateDir / control，导致 CMS 栏目与文章串页。
	 */
	public function __construct()
	{
		$this->view = Container::getInstance()->make(View::class, [], true);
		$this->view->assignClearAll();
		$this->view->apidataClearAll();
	}

	/**
	 * Engine
	 * @return View
	 */
	public function engine(): View
	{
		return $this->view;
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
		$this->view->assign($args, $value, $apidata, $callFunc);
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
		$this->view->display($name, $cacheId, $single, $templateDir);
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
		return $this->view->fetch($name, $cacheId, $single, $templateDir);
	}

	/**
	 * Template Exist
	 * @param mixed $name
	 * @param mixed $single
	 * @return bool
	 */
	public function templateExist(string $name, bool $single = false): bool
	{
		return $this->view->templateExist($name, $single);
	}

	/**
	 * Halt
	 * @param mixed $msg
	 */
	public function halt(?string $msg): void
	{
		$this->view->halt($msg);
	}

	/**
	 * Assign By Ref
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function assignByRef(string $key, mixed $value = null): void
	{
		$this->view->assignByRef($key, $value);
	}

	/**
	 * Assign Clear
	 * @param mixed $tplVar
	 */
	public function assignClear(mixed $tplVar): void
	{
		$this->view->assignClear($tplVar);
	}

	/**
	 * Assign Clear All
	 */
	public function assignClearAll(): void
	{
		$this->view->assignClearAll();
	}

	/**
	 * Apidata Clear All
	 */
	public function apidataClearAll(): void
	{
		$this->view->apidataClearAll();
	}

	/**
	 * Get Control
	 * @return string
	 */
	public function getControl(): string
	{
		return $this->view->control;
	}

	/**
	 * Get Action
	 * @return string
	 */
	public function getAction(): string
	{
		return $this->view->action;
	}

	/**
	 * Get Caching
	 * @return bool
	 */
	public function getCaching(): bool
	{
		return $this->view->caching;
	}

	/**
	 * Set Caching
	 * @param mixed $caching
	 */
	public function setCaching(bool $caching): void
	{
		$this->view->caching = $caching;
	}

	/**
	 * Get Content
	 * @return string
	 */
	public function getContent(): string
	{
		return $this->view->getContent();
	}

	/**
	 * Set Val
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function setVal(string $key, mixed $value = null): void
	{
		$this->view->setVal($key, $value);
	}

	/**
	 * Register Outputfilter
	 * @param mixed $funcname
	 */
	public function registerOutputfilter(string $funcname): void
	{
		$this->view->registerOutputfilter($funcname);
	}

	/**
	 * Apidata Clear
	 * @param mixed $tplVar
	 */
	public function apidataClear(mixed $tplVar): void
	{
		$this->view->apidataClear($tplVar);
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
		return $this->view->compileFile($templateName, $cacheId, $single, $templateDir, $compileDir);
	}
}
