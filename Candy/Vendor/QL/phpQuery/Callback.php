<?php
/***
 * Candy框架 Callback回调类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2023-6-28 10:14:25 $   
 */

declare(strict_types=1);
namespace QL\phpQuery;

/**
 * 命名回调接口
 * 定义具有名称的回调函数的基本方法
 */
interface ICallbackNamed {
	/**
	 * 检查回调是否有名称
	 * @return bool
	 */
	function hasName();
	
	/**
	 * 获取回调名称
	 * @return string
	 */
	function getName();
}

/**
 * 回调类
 * 实现了类似柯里化的模式，允许将参数绑定到回调函数
 * 
 * 示例：
 * function foo($param1, $param2, $param3) {
 *   var_dump($param1, $param2, $param3);
 * }
 * $fooCurried = new Callback('foo', 
 *   'param1 is now statically set', 
 *   new CallbackParam, new CallbackParam
 * );
 * phpQuery::callbackRun($fooCurried,
 *  array('param2 value', 'param3 value'
 * );
 * 
 * Callback类支持所有接受回调的phpQuery方法。
 *
 * @link http://code.google.com/p/phpquery/wiki/Callbacks#Param_Structures
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 * 
 * @TODO??? 返回通过create_function创建的假转发函数
 * @TODO 支持paramStructure
 */
class Callback implements ICallbackNamed {
	/**
	 * 回调函数
	 * @var callable
	 */
	public $callback = null;
	
	/**
	 * 回调参数
	 * @var array
	 */
	public $params = null;
	
	/**
	 * 回调名称
	 * @var string
	 */
	protected $name;
	
	/**
	 * Callback构造函数
	 *
	 * @param callable $callback 回调函数
	 * @param mixed $param1 参数1
	 * @param mixed $param2 参数2
	 * @param mixed $param3 参数3
	 */
	public function __construct($callback, $param1 = null, $param2 = null, 
			$param3 = null) {
		$params = func_get_args();
		$params = array_slice($params, 1);
		if ($callback instanceof Callback) {
			// TODO 实现递归
		} else {
			$this->callback = $callback;
			$this->params = $params;
		}
	}
	
	/**
	 * 获取回调名称
	 *
	 * @return string
	 */
	public function getName() {
		return 'Callback: '.$this->name;
	}
	
	/**
	 * 检查回调是否有名称
	 *
	 * @return bool
	 */
	public function hasName() {
		return isset($this->name) && $this->name;
	}
	
	/**
	 * 设置回调名称
	 *
	 * @param string $name 回调名称
	 * @return $this
	 */
	public function setName($name) {
		$this->name = $name;
		return $this;
	}
	
	// TODO 测试
//	public function addParams() {
//		$params = func_get_args();
//		return new Callback($this->callback, $this->params+$params);
//	}
}

/**
 * 回调体类
 * 用于创建基于字符串代码的回调函数，是new Callback(create_function(...), ...)的简写
 * 
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 */
class CallbackBody extends Callback {
	/**
	 * CallbackBody构造函数
	 *
	 * @param string $paramList 参数列表
	 * @param string $code 函数代码
	 * @param mixed $param1 参数1
	 * @param mixed $param2 参数2
	 * @param mixed $param3 参数3
	 */
	public function __construct($paramList, $code, $param1 = null, $param2 = null, 
			$param3 = null) {
		$params = func_get_args();
		$params = array_slice($params, 2);
		$this->callback = create_function($paramList, $code);
		$this->params = $params;
	}
}

/**
 * 返回引用的回调类型
 * 执行时返回创建时传递的引用
 * 
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 */
class CallbackReturnReference extends Callback
	implements ICallbackNamed {
	/**
	 * 引用值
	 * @var mixed
	 */
	protected $reference;
	
	/**
	 * CallbackReturnReference构造函数
	 *
	 * @param mixed &$reference 引用值
	 * @param string|null $name 回调名称
	 */
	public function __construct(&$reference, $name = null){
		$this->reference =& $reference;
		$this->callback = [$this, 'callback'];
	}
	
	/**
	 * 回调函数
	 *
	 * @return mixed
	 */
	public function callback() {
		return $this->reference;
	}
	
	/**
	 * 获取回调名称
	 *
	 * @return string
	 */
	public function getName() {
		return 'Callback: '.$this->name;
	}
	
	/**
	 * 检查回调是否有名称
	 *
	 * @return bool
	 */
	public function hasName() {
		return isset($this->name) && $this->name;
	}
}

/**
 * 返回值的回调类型
 * 执行时返回创建时传递的值
 * 
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 */
class CallbackReturnValue extends Callback
	implements ICallbackNamed {
	/**
	 * 返回值
	 * @var mixed
	 */
	protected $value;
	
	/**
	 * 回调名称
	 * @var string
	 */
	protected $name;
	
	/**
	 * CallbackReturnValue构造函数
	 *
	 * @param mixed $value 返回值
	 * @param string|null $name 回调名称
	 */
	public function __construct($value, $name = null){
		$this->value =& $value;
		$this->name = $name;
		$this->callback = [$this, 'callback'];
	}
	
	/**
	 * 回调函数
	 *
	 * @return mixed
	 */
	public function callback() {
		return $this->value;
	}
	
	/**
	 * 转换为字符串
	 *
	 * @return string
	 */
	public function __toString() {
		return $this->getName();
	}
	
	/**
	 * 获取回调名称
	 *
	 * @return string
	 */
	public function getName() {
		return 'Callback: '.$this->name;
	}
	
	/**
	 * 检查回调是否有名称
	 *
	 * @return bool
	 */
	public function hasName() {
		return isset($this->name) && $this->name;
	}
}

/**
 * 参数转引用回调
 * 用于将回调的第一个参数值传递给引用，而不需要真正的回调函数
 *
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 */
class CallbackParameterToReference extends Callback {
	/**
	 * CallbackParameterToReference构造函数
	 *
	 * @param mixed &$reference 引用值
	 * @TODO 实现$paramIndex; 参数索引选择哪个回调参数将被传递给引用
	 */
	public function __construct(&$reference){
		$this->callback =& $reference;
	}
}

//class CallbackReference extends Callback {
//	/**
//	 *
//	 * @param $reference
//	 * @param $paramIndex
//	 * @todo implement $paramIndex; param index choose which callback param will be passed to reference
//	 */
//	public function __construct(&$reference, $name = null){
//		$this->callback =& $reference;
//	}
//}

/**
 * 回调参数标记类
 * 用于标记回调函数中需要动态传递的参数位置
 */
class CallbackParam {}