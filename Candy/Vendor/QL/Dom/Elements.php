<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/19
 */

namespace QL\Dom;

use \Extend\Collection;
use \QL\phpQuery\phpQueryObject;
use \phpDocumentor\Reflection\Types\Null_;

/**
 * 元素集合类
 * 封装了phpQueryObject，提供了更便捷的DOM元素操作方法
 *
 * @method Elements toReference(&$var) 获取引用
 * @method Elements documentFragment($state = null) 获取文档片段
 * @method Elements toRoot() 回到根节点
 * @method Elements getDocumentIDRef(&$documentID) 获取文档ID引用
 * @method Elements getDocument() 获取文档
 * @method \DOMDocument getDOMDocument() 获取DOM文档对象
 * @method Elements getDocumentID() 获取文档ID
 * @method Elements unloadDocument() 卸载文档
 * @method bool isHTML() 是否为HTML格式
 * @method bool isXHTML() 是否为XHTML格式
 * @method bool isXML() 是否为XML格式
 * @method string serialize() 序列化元素
 * @method array serializeArray($submit = null) 序列化元素为数组
 * @method \DOMElement|\DOMElement[] get($index = null, $callback1 = null, $callback2 = null, $callback3 = null) 获取元素
 * @method string|array getString($index = null, $callback1 = null, $callback2 = null, $callback3 = null) 获取元素字符串
 * @method string|array getStrings($index = null, $callback1 = null, $callback2 = null, $callback3 = null) 获取元素字符串数组
 * @method Elements newInstance($newStack = null) 创建新实例
 * @method Elements find($selectors, $context = null, $noHistory = false) 查找元素
 * @method Elements|bool is($selector, $nodes = null) 检查元素是否匹配选择器
 * @method Elements filterCallback($callback, $_skipHistory = false) 过滤元素（回调函数）
 * @method Elements filter($selectors, $_skipHistory = false) 过滤元素（选择器）
 * @method Elements load($url, $data = null, $callback = null) 加载URL内容
 * @method Elements trigger($type, $data = []) 触发事件
 * @method Elements triggerHandler($type, $data = []) 触发事件处理器
 * @method Elements bind($type, $data, $callback = null) 绑定事件
 * @method Elements unbind($type = null, $callback = null) 解绑事件
 * @method Elements change($callback = null) 绑定change事件
 * @method Elements submit($callback = null) 绑定submit事件
 * @method Elements click($callback = null) 绑定click事件
 * @method Elements wrapAllOld($wrapper) 包装所有元素（旧方法）
 * @method Elements wrapAll($wrapper) 包装所有元素
 * @method Elements wrapAllPHP($codeBefore, $codeAfter) 使用PHP代码包装所有元素
 * @method Elements wrap($wrapper) 包装每个元素
 * @method Elements wrapPHP($codeBefore, $codeAfter) 使用PHP代码包装每个元素
 * @method Elements wrapInner($wrapper) 包装元素内部内容
 * @method Elements wrapInnerPHP($codeBefore, $codeAfter) 使用PHP代码包装元素内部内容
 * @method Elements contents() 获取元素内容
 * @method Elements contentsUnwrap() 移除内容包装
 * @method Elements switchWith($markup) 替换元素
 * @method Elements eq($num) 获取指定索引的元素
 * @method Elements size() 获取元素数量
 * @method Elements length() 获取元素长度
 * @method int count() 获取元素数量
 * @method Elements end($level = 1) 回到上一个选择器状态
 * @method Elements _clone() 克隆元素
 * @method Elements replaceWithPHP($code) 使用PHP代码替换元素
 * @method Elements replaceWith($content) 替换元素内容
 * @method Elements replaceAll($selector) 替换所有匹配的元素
 * @method Elements remove($selector = null) 移除元素
 * @method Elements|string markup($markup = null, $callback1 = null, $callback2 = null, $callback3 = null) 获取或设置标记
 * @method string markupOuter($callback1 = null, $callback2 = null, $callback3 = null) 获取外部标记
 * @method Elements|string html($html = null, $callback1 = null, $callback2 = null, $callback3 = null) 获取或设置HTML
 * @method Elements|string xml($xml = null, $callback1 = null, $callback2 = null, $callback3 = null) 获取或设置XML
 * @method string htmlOuter($callback1 = null, $callback2 = null, $callback3 = null) 获取外部HTML
 * @method string xmlOuter($callback1 = null, $callback2 = null, $callback3 = null) 获取外部XML
 * @method Elements php($code) 执行PHP代码
 * @method string markupPHP($code) 使用PHP代码处理标记
 * @method string markupOuterPHP() 获取外部标记并执行PHP代码
 * @method Elements children($selector) 获取子元素
 * @method Elements ancestors($selector) 获取祖先元素
 * @method Elements append($content) 追加内容
 * @method Elements appendPHP($content) 使用PHP代码追加内容
 * @method Elements appendTo($seletor) 追加到指定元素
 * @method Elements prepend($content) 前置内容
 * @method Elements prependPHP($content) 使用PHP代码前置内容
 * @method Elements prependTo($seletor) 前置到指定元素
 * @method Elements before($content) 在元素前插入内容
 * @method Elements beforePHP($content) 使用PHP代码在元素前插入内容
 * @method Elements insertBefore($seletor) 插入到指定元素前
 * @method Elements after($content) 在元素后插入内容
 * @method Elements afterPHP($content) 使用PHP代码在元素后插入内容
 * @method Elements insertAfter($seletor) 插入到指定元素后
 * @method Elements insert($target, $type) 插入元素
 * @method int index($subject) 获取元素索引
 * @method Elements slice($start, $end = null) 截取元素
 * @method Elements reverse() 反转元素顺序
 * @method Elements|string text($text = null, $callback1 = null, $callback2 = null, $callback3 = null) 获取或设置文本内容
 * @method Elements plugin($class, $file = null) 加载插件
 * @method Elements _next($selector = null) 获取下一个元素
 * @method Elements _prev($selector = null) 获取上一个元素
 * @method Elements prev($selector = null) 获取前一个元素
 * @method Elements prevAll($selector = null) 获取所有前一个元素
 * @method Elements nextAll($selector = null) 获取所有后一个元素
 * @method Elements siblings($selector = null) 获取兄弟元素
 * @method Elements not($selector = null) 排除元素
 * @method Elements add($selector = null) 添加元素
 * @method Elements parent($selector = null) 获取父元素
 * @method Elements parents($selector = null) 获取所有父元素
 * @method Elements stack($nodeTypes = null) 获取元素栈
 * @method Elements|string attr($attr = null, $value = null) 获取或设置属性
 * @method Elements attrPHP($attr, $code) 使用PHP代码处理属性
 * @method Elements removeAttr($attr) 移除属性
 * @method Elements|string val($val = null) 获取或设置值
 * @method Elements andSelf() 包含自身
 * @method Elements addClass($className) 添加类名
 * @method Elements addClassPHP($className) 使用PHP代码添加类名
 * @method bool hasClass($className) 是否包含指定类名
 * @method Elements removeClass($className) 移除类名
 * @method Elements toggleClass($className) 切换类名
 * @method Elements _empty() 清空元素内容
 * @method Elements callback($callback, $param1 = null, $param2 = null, $param3 = null) 执行回调函数
 * @method string data($key, $value = null) 获取或设置数据
 * @method Elements removeData($key) 移除数据
 * @method void rewind() 重置迭代器
 * @method Elements current() 获取当前元素
 * @method int key() 获取当前键
 * @method Elements next($cssSelector = null) 获取下一个元素
 * @method bool valid() 检查迭代器是否有效
 * @method bool offsetExists($offset) 检查偏移量是否存在
 * @method Elements offsetGet($offset) 获取指定偏移量的元素
 * @method void offsetSet($offset, $value) 设置指定偏移量的元素
 * @method string whois($oneNode) 获取节点信息
 * @method Elements dump() 打印元素
 * @method Elements dumpWhois() 打印元素信息
 * @method Elements dumpLength() 打印元素长度
 * @method Elements dumpTree($html, $title) 打印元素树
 * @method void dumpDie() 打印元素并退出
 */
class Elements
{
    /**
     * phpQuery对象实例
     * @var phpQueryObject
     */
    protected $elements;

    /**
     * Elements构造函数
     *
     * @param phpQueryObject $elements phpQuery对象实例
     */
    public function __construct(phpQueryObject $elements)
    {
        $this->elements = $elements;
    }

    /**
     * 获取属性或phpQuery对象的属性
     *
     * @param string $name 属性名
     * @return mixed
     */
    public function __get($name)
    {
        return property_exists($this->elements, $name) ? $this->elements->$name : $this->elements->attr($name);
    }

    /**
     * 调用phpQuery对象的方法
     *
     * @param string $name 方法名
     * @param array $arguments 方法参数
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        $obj = call_user_func_array([$this->elements, $name], $arguments);
        if ($obj instanceof phpQueryObject) {
            $obj = new self($obj);
        } else if (is_string($obj)) {
            $obj = trim($obj);
        }
        return $obj;
    }

    /**
     * 遍历元素
     *
     * @param callable $callback 回调函数
     * @return $this
     */
    public function each(callable $callback)
    {
        foreach ($this->elements as $key => $element) {
            $break = $callback(new self(call_user_func_array(['QL\phpQuery', 'pq'], func_get_args())), $key);
            if ($break === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * 映射元素
     *
     * @param callable $callback 回调函数
     * @return \Extend\Collection
     */
    public function map($callback)
    {
        $collection = new \Candy\Extend\Collection();
        $this->elements->each(function ($dom) use (&$collection, $callback) {
            $collection->push($callback(new self(call_user_func_array(['QL\phpQuery', 'pq'], func_get_args()))));
        });
        return $collection;
    }

    /**
     * 获取所有元素的指定属性
     *
     * @param string $attr HTML属性名
     * @return \Extend\Collection 属性值集合
     */
    public function attrs($attr)
    {
        return $this->map(function ($item) use ($attr) {
            return $item->attr($attr);
        });
    }

    /**
     * 获取所有元素的文本内容
     *
     * @return \Extend\Collection 文本内容集合
     */
    public function texts()
    {
        return $this->map(function ($item) {
            return trim($item->text());
        });
    }

    /**
     * 获取所有元素的HTML内容
     *
     * @return \Extend\Collection HTML内容集合
     */
    public function htmls()
    {
        return $this->map(function ($item) {
            return trim($item->html());
        });
    }

    /**
     * 获取所有元素的外部HTML内容
     *
     * @return \Extend\Collection 外部HTML内容集合
     */
    public function htmlOuters()
    {
        return $this->map(function ($item) {
            return trim($item->htmlOuter());
        });
    }

    /**
     * 获取phpQuery对象实例
     *
     * @return phpQueryObject
     */
    public function getElements(): phpQueryObject
    {
        return $this->elements;
    }

}