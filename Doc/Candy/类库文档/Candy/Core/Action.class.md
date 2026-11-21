# Action 类

## 概述

* Candy框架控制器基类
 * 
 * Action类是Candy框架中所有控制器的基础类，继承自View类
 * 负责处理请求分发、权限验证、动作执行、视图渲染等核心功能
 * 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 10:00:00
 * @package Candy\Core
 * @extends View 视图基类，提供模板渲染功能

## 主要方法


### /**
 * Candy框架控制器基类
 * 
 * Action类是Candy框架中所有控制器的基础类，继承自View类
 * 负责处理请求分发、权限验证、动作执行、视图渲染等核心功能
 * 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 10:00:00
 * @package Candy\Core
 * @extends View 视图基类，提供模板渲染功能
 */

//语法严格模式开启
declare(strict_types=1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Action extends View {
	
	/**
	 * 控制器核心执行方法
	 * 
	 * 负责控制器的初始化、动作方法的调用、权限验证和响应处理
	 * 主要流程：
	 * 1. 初始化父类View
	 * 2. 执行init方法（如果存在）
	 * 3. 检查并执行动作方法
	 * 4. 处理权限验证
	 * 5. 执行前置操作和后置事件
	 * 6. 处理404错误情况
	 * 7. 触发应用结束事件
	 * 
	 * @return void
	 */
	public function working():()


### /**
	 * URL重定向方法
	 * 
	 * 用于在控制器中进行页面跳转，支持普通模式和API模式
	 * 在API模式下返回JSON数据，在普通模式下使用JavaScript进行页面跳转
	 * 
	 * @param string $path 重定向的目标路径
	 * @param string $args 重定向时传递的参数，格式为'key/value'或'key1/value1/key2/value2'
	 * @return void
	 * @example 
	 * $this->redirect("index");  // 重定向到当前模块的index方法
	 * $this->redirect("user/index");  // 重定向到user控制器的index方法
	 * $this->redirect("user/index", 'page/5');  // 重定向并传递参数
	 */
	protected function redirect(string $path, string $args = ''):()


### /**
	 * 成功消息提示方法
	 * 
	 * 显示操作成功的消息提示框，并支持定时跳转
	 * 内部调用showMessage方法实现具体功能
	 * 
	 * @param string $mess 提示消息内容，默认为'操作成功'
	 * @param int $timeout 跳转等待时间（秒），默认为1秒
	 * @param string $location 跳转目标地址，留空则返回上一页
	 * @param string $title 提示框标题
	 * @return bool 始终返回false
	 */
	protected function success(string $mess='操作成功',int $timeout=1,string $location='',string $title=''):()


### /**
	 * 错误消息提示方法
	 * 
	 * 显示操作失败的消息提示框，并支持定时跳转
	 * 内部调用showMessage方法实现具体功能
	 * 
	 * @param string $mess 提示消息内容，默认为'操作失败'
	 * @param int $timeout 跳转等待时间（秒），默认为3秒
	 * @param string $location 跳转目标地址，留空则返回上一页
	 * @param string $title 提示框标题
	 * @return bool 始终返回false
	 */
	protected function error(string $mess='操作失败',int $timeout=3,string $location='',string $title=''):()


### /**
	 * 通用消息提示方法（私有）
	 * 
	 * 内部方法，用于显示成功或失败的消息提示
	 * 清理API数据，设置消息状态，调用pub方法处理具体显示逻辑
	 * 
	 * @param string $mess 提示消息内容
	 * @param int $timeout 跳转等待时间（秒）
	 * @param string $location 跳转目标地址
	 * @param string $title 提示框标题
	 * @param bool $isSuccess 是否为成功消息
	 * @return bool 始终返回false
	 * @access private
	 */
	private function showMessage(string $mess, int $timeout, string $location, string $title, bool $isSuccess):()


### /**
	 * 消息提示框显示逻辑处理方法（私有）
	 * 
	 * 设置缓存状态，处理消息内容，处理跳转地址，并为视图分配变量
	 * 在API模式下直接输出数据，在普通模式下设置跳转参数
	 * 
	 * @param string $mess 提示消息内容
	 * @param int $timeout 跳转等待时间（秒）
	 * @param string $location 跳转目标地址
	 * @param string $title 提示框标题
	 * @return void
	 * @access private
	 */
	private function pub(string $mess,int $timeout,string $location,string $title):()


### /**
	 * 获取类实例方法
	 * 
	 * 通过容器获取指定类的实例，实现依赖注入
	 * 从Container容器中获取非静态对象实例
	 * 
	 * @param string $className 类名
	 * @return object 返回请求的类实例
	 */
	protected function instance(string $className):()


### /**
	 * 404错误页面输出方法（私有）
	 * 
	 * 处理404错误的显示逻辑
	 * 在调试模式下显示错误提示，非调试模式下尝试显示自定义404页面
	 * 如果没有自定义404页面则返回标准HTTP 404响应
	 * 
	 * @param string $msg 错误提示信息
	 * @return void
	 * @access private
	 */
	private function display404(string $msg):()

