# View 类

## 概述

*
 * Candy框架 View Response
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2019-08-01 23:42:51 $

## 主要方法


### /***
 * Candy框架 View Response
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2019-08-01 23:42:51 $   
 */
 
declare(strict_types=1);
namespace Candy\Core\Respond;

use \Candy\Core\Respond;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class View extends Respond
{
    /**
     * 输出参数
     * @var array
     */
    protected $options = [];

    /**
     * 输出变量
     * @var array
     */
    protected $vars = [];

    /**
     * 输出过滤
     * @var mixed
     */
    protected $filter;

    /**
     * 输出type
     * @var string
     */
    protected $contentType = 'text/html';

    /**
     * View对象
     * @var BaseView
     */
    protected $view;

    /**
     * 模板文件路径
     * @var string
     */
    protected $template;

    /**
     * 是否内容渲染
     * @var bool
     */
    protected $isContent = false;

    public function __construct(?string $content = null, int $code = 200)
    {
        $this->template = $content;
        $this->code = $code;
		
		$className = '\\APP\\' . (is_null(C('ISADDON')) ? 'Control\\' : 'Addon\\') . C('APPNAME') . '\\' .ucfirst($_GET['m']) . 'Action';
		$className = class_exists($className) ? $className : '\\Candy\\Core\\Action';
		$this->view = \Candy\Core\Container::getInstance()->make($className, [], isDebug());
    }

    /**
     * 处理数据
     * @access protected
     * @param  mixed $data 要处理的数据
     * @return string
     */
    public function output(string $data = ''):()


### /**
     * 模板变量赋值
     * @param  string|array $name  模板变量
     * @param  mixed        $value 变量值
     * @return $this
     */
    public function assign($name, $value = null):()

