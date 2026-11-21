<?php
/***
 * Candy框架 DOMEvent类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2023-6-28 10:14:25 $   
 */

declare(strict_types=1);
namespace QL\phpQuery;

/**
 * DOM事件类
 * 用于处理DOM事件的相关功能
 */
class DOMEvent {
	/**
	 * 事件是否冒泡
	 * @var bool
	 */
	public $bubbles = true;
	/**
	 * 事件是否可取消
	 * @var bool
	 */
	public $cancelable = true;
	/**
	 * 当前事件注册的目标
	 * @var mixed
	 */
	public $currentTarget;
	/**
	 * 事件的详细信息，取决于事件类型
	 * @var mixed
	 * @link http://developer.mozilla.org/en/DOM/event.detail
	 */
	public $detail; // ???
	/**
	 * 用于指示当前正在评估事件流的哪个阶段
	 *
	 * 未实现
	 *
	 * @var mixed
	 * @link http://developer.mozilla.org/en/DOM/event.eventPhase
	 */
	public $eventPhase; // ???
	/**
	 * 事件的明确原始目标（Mozilla特定）
	 *
	 * 未实现
	 *
	 * @var mixed
	 */
	public $explicitOriginalTarget; // moz only
	/**
	 * 事件的原始目标，在任何重定向之前（Mozilla特定）
	 *
	 * 未实现
	 *
	 * @var mixed
	 */
	public $originalTarget; // moz only
	/**
	 * 标识事件的次要目标
	 * @var mixed
	 */
	public $relatedTarget;
	/**
	 * 返回事件最初分派到的目标
	 * @var mixed
	 */
	public $target;
	/**
	 * 返回事件创建的时间
	 * @var int
	 */
	public $timeStamp;
	/**
	 * 返回事件的名称（不区分大小写）
	 * @var string
	 */
	public $type;
	/**
	 * 是否运行默认行为
	 * @var bool
	 */
	public $runDefault = true;
	/**
	 * 事件数据
	 * @var mixed
	 */
	public $data = null;
	/**
	 * DOMEvent构造函数
	 *
	 * @param array $data 事件数据
	 */
	public function __construct($data) {
		foreach($data as $k => $v) {
			$this->$k = $v;
		}
		if (! $this->timeStamp)
			$this->timeStamp = time();
	}
	/**
	 * 取消事件（如果可取消）
	 *
	 * @return void
	 */
	public function preventDefault() {
		$this->runDefault = false;
	}
	/**
	 * 停止事件在DOM中的进一步传播
	 *
	 * @return void
	 */
	public function stopPropagation() {
		$this->bubbles = false;
	}
}
