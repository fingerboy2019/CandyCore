<?php
/**
 * Candy框架 View 响应类型
 *
 * 将模板渲染为 HTML 响应。组合 ViewRenderer 完成渲染，
 * 在控制器上下文中复用当前 Action 的 ViewRenderer（保留 $this->assign 变量）。
 *
 * @package Candy\Core\Respond
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:42:51
 */

declare(strict_types=1);

namespace Candy\Core\Impl\Respond;

use Candy\Core\Action;
use Candy\Core\Container;
use Candy\Core\Request;
use Candy\Core\Respond;
use Candy\Core\ViewRenderer;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class View extends Respond
{
	protected string $contentType = 'text/html';

	/** @var ViewRenderer 模板渲染器 */
	protected ViewRenderer $renderer;

	/** @var string|null 模板名，null 时使用当前 control/action 默认模板 */
	protected ?string $template;

	/**
	 *   construct
	 * @param mixed $content
	 * @param mixed $code
	 * @return mixed
	 */
	public function __construct(?string $content = null, int $code = 200)
	{
		$this->template = $content;
		$this->code = $code;
		$this->renderer = $this->resolveRenderer();
	}

	/**
	 * 解析 ViewRenderer：优先复用容器内当前控制器实例
	 */
	protected function resolveRenderer(): ViewRenderer
	{
		$control = I('get.m', '', '');
		$className = '\\APP\\' . (is_null(C('ISADDON')) ? 'Control\\' : 'Addon\\')
			. C('APPNAME') . '\\' . ucfirst((string)$control) . 'Action';
		$className = class_exists($className) ? $className : Action::class;

		$action = Container::getInstance()->make($className, [], false);
		if ($action instanceof Action) {
			return $action->getViewRenderer();
		}

		return Container::getInstance()->make(ViewRenderer::class, [], true);
	}

	/**
	 * 模板变量赋值
	 *
	 * @param string|array $name 变量名或批量数组
	 * @param mixed $value 变量值（$name 为数组时忽略）
	 * @return $this
	 */
	public function assign($name, $value = null): self
	{
		$this->renderer->assign($name, $value);
		return $this;
	}

	/**
	 * 渲染模板并输出 HTTP 响应
	 *
	 * @param string $data 附加原始数据（传入 Respond::init）
	 * @return string 渲染后的 HTML
	 */
	public function output(string $data = ''): string
	{
		$this->init($data, $this->code);

		if ($this->template !== null && $this->template !== '') {
			$this->renderer->display($this->template);
		} else {
			$this->renderer->display();
		}

		return $this->renderer->engine()->getContent();
	}
}
