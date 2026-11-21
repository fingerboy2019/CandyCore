# Container 类

## 概述

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

## 主要方法


### getInstance()


### pull()


### /**
     * 获取容器中的对象实例
     * 
     * 检查并获取容器中已存在的对象实例，不存在则返回null。
     * 与make方法不同，此方法不会自动创建新实例。
     * 
     * @param string $abstract 类名或者标识
     * @return mixed 对象实例或null
     */
    public function get(string $abstract):()


### /**
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
    public function bind(string|array $abstract, mixed $concrete = null):()


### /**
     * 设置容器参数
     * 
     * 用于存储和管理容器中的全局配置参数。
     * 会清空现有参数并设置新的参数字典。
     * 
     * @param array<string, mixed> $data 参数字典数组
     * @return void
     */
    public function setParameter(array $data = []):()


### /**
     * 判断容器中是否存在类及标识
     * 
     * 检查指定的类名或标识是否已在容器中绑定或已有实例。
     * 同时检查绑定缓存和实例缓存。
     * 
     * @param string $abstract 类名或者标识
     * @return bool 是否存在绑定或实例
     */
    public function bound(string $abstract):()


### /**
     * 判断容器中是否存在类及标识
     * 
     * has方法是bound方法的别名，用于保持接口一致性。
     * 实现PSR容器接口的has方法。
     * 
     * @param string $name 类名或者标识
     * @return bool 是否存在绑定或实例
     */
    public function has(string $name):()


### /**
     * 判断容器中是否存在对象实例
     * 
     * 检查指定的类名或标识是否已有实例存在于实例缓存中。
     * 与bound/has方法不同，此方法仅检查实例缓存。
     * 
     * @param string $abstract 类名或者标识
     * @return bool 是否存在实例
     */
    public function exists(string $abstract):()


### /**
     * 魔术方法：设置容器元素
     * 
     * 当使用赋值操作符设置属性时触发，将绑定操作转换为属性设置语法。
     * 例如：$container->app = new App(); 等效于 $container->bind('app', new App());
     * 
     * @param string $name 类名或标识
     * @param mixed $value 要绑定的值
     * @return void
     */
    public function __set(string $name, mixed $value):()


### /**
     * 魔术方法：获取容器元素
     * 
     * 当访问容器属性时触发，将属性访问转换为获取操作。
     * 例如：$app = $container->app; 等效于 $app = $container->get('app');
     * 
     * @param string $name 类名或标识
     * @return mixed 获取的对象或null
     */
    public function __get(string $name):()

