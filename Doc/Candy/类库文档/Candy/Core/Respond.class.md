# Respond 类

## 概述

* Candy框架 响应输出基础类
 * 
 * 提供HTTP响应管理、内容输出、头部设置、状态码控制等核心功能
 * 支持不同响应类型（HTML、JSON等）的灵活输出
 * 包含缓存控制、Cookie管理、Session管理等特性
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @abstract 抽象类，不能直接实例化，需通过静态方法create()创建具体响应类型实例

## 主要方法


### buff()


### create()


### /**
     * 设置Session对象
     * @param \Candy\Extend\Session $session Session对象
     * @return $this
     */
    public function setSession(\Candy\Extend\Session $session):()


### /**
     * 将响应数据发送到客户端浏览器
     * 
     * 负责处理完整的响应流程：获取处理后的内容、发送HTTP状态码、
     * 设置响应头信息、输出内容到客户端
     * 在支持的环境中自动调用fastcgi_finish_request提高页面响应速度
     * 
     * @return void
     * @throws \InvalidArgumentException
     */
    public function send():()


### /**
     * 处理响应数据的核心方法
     * 
     * 当数据为空时，尝试从当前控制器或Action对象获取内容
     * 支持自动关联容器中的控制器实例
     * 
     * @param mixed $data 要处理的数据
     * @return mixed 返回处理后的数据，可能是原始数据或从控制器获取的内容
     */
    protected function output(string $data):()


### /**
     * 输出数据到客户端
     * 
     * 将处理好的响应数据输出到客户端，并在调试模式下显示调试信息
     * 
     * @param string $data 要输出的数据内容
     * @return void
     */
    protected function sendData(string $data):()


### /**
     * 设置输出选项参数
     * 
     * 合并新的选项参数到已有的选项中
     * 
     * @param array $options 输出选项参数数组
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function options(array $options = []):()


### /**
     * 设置响应数据
     * 
     * 设置要输出的原始数据，数据将在后续处理中被转换为最终输出内容
     * 
     * @param mixed $data 输出数据，可以是任意类型
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function data(string $data):()


### /**
     * 设置是否允许请求缓存
     * 
     * 控制响应是否可以被浏览器或代理服务器缓存
     * 
     * @param bool $cache true表示允许缓存，false表示禁止缓存
     * @return $this 返回当前响应对象，支持链式调用
     */
    public function allowCache(bool $cache):()


### /**
     * 获取是否允许请求缓存的状态
     * 
     * @return bool 返回当前缓存设置状态
     */
    public function isAllowCache():()

