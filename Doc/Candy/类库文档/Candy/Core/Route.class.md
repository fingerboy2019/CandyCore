# Route 类

## 概述

* Candy框架 路由处理核心类
 * 
 * 负责URL路由解析、规则注册、请求分发和参数处理的核心组件
 * 支持静态路由、动态路由、正则路由、分组路由等功能
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-26 11:31:47
 * @final 该类为最终类，不允许被继承

## 主要方法


### rule()


### getRule()


### parseRoute()


### initConfig()


### contrastRoute()


### /**
	 * 检测URL和规则路由是否匹配
	 * 
	 * 对比URL路径和路由规则，检查是否匹配并提取动态变量
	 * 支持动态变量定义、类型限制和排除规则
	 * 
	 * @param string $regx URL路径字符串
	 * @param string $rule 路由规则字符串
	 * @return array|false 匹配成功返回包含动态变量的数组，失败返回false
	 * @example self::checkUrlMatch('/user/123', 'user/:id') => ['id' => '123']
	 */
	private static function checkUrlMatch(string $regx,string $rule){
		$m1 = explode('/',$regx);
		$m2 = explode('/',$rule);
		$var = [];         
		foreach($m2 as $key=>$val){
			if(0 === strpos($val,'[:()


### /**
	 * 解析规则路由
	 * 
	 * 将规则路由解析为具体的控制器/操作地址或重定向URL
	 * 支持动态变量替换、参数传递和HTTP重定向功能
	 * 
	 * 路由规则格式：
	 * - '路由规则'=>'[控制器/操作]?额外参数1=值1&额外参数2=值2...'
	 * - '路由规则'=>array('[控制器/操作]','额外参数1=值1&额外参数2=值2...')
	 * - '路由规则'=>'外部地址'
	 * - '路由规则'=>array('外部地址','重定向代码')
	 * 
	 * 变量使用说明：
	 * - 路由规则中 :开头 表示动态变量
	 * - 外部地址中可以用动态变量 采用 :1 :2 的方式
	 * 
	 * @param string $rule 路由规则字符串
	 * @param string|array $route 路由地址或数组
	 * @param string $regx 原始URL路径
	 * @return string 解析后的路由地址
	 * @example self::parseRule('news/:id', 'News/read', '/news/123') => 'News/read/id/123'
	 * @example self::parseRule('new/:id', array('/new.php?id=:1', 301), '/new/123') => 执行301重定向到/new.php?id=123
	 */
	private static function parseRule(string $rule, $route,string $regx):()


### /**
	 * 解析正则路由
	 * 
	 * 处理正则表达式匹配成功后的路由解析
	 * 支持参数提取、动态变量替换和HTTP重定向
	 * 
	 * 正则路由格式：
	 * - '路由正则'=>'[控制器/操作]?参数1=值1&参数2=值2...'
	 * - '路由正则'=>array('[控制器/操作]?参数1=值1&参数2=值2...','额外参数1=值1&额外参数2=值2...')
	 * - '路由正则'=>'外部地址'
	 * - '路由正则'=>array('外部地址','重定向代码')
	 * 
	 * 参数使用说明：
	 * - 参数值和外部地址中可以用动态变量，采用 :1 :2 的方式引用捕获组
	 * 
	 * @param array $matches 正则表达式匹配结果数组
	 * @param string|array $route 路由地址或数组
	 * @param string $regx 原始URL路径
	 * @return string 解析后的路由地址
	 * @example self::parseRegex(['/news/123', '123'], 'News/read?id=:1', '/news/123') => 'News/read/id/123'
	 * @example self::parseRegex(['/new/123', '123'], array('/new.php?id=:1', 301), '/new/123') => 执行301重定向
	 */
	private static function parseRegex(array $matches, $route,string $regx):()


### /**
	 * 执行正则匹配下的闭包方法
	 * 
	 * 通过反射机制调用路由匹配中的闭包函数
	 * 自动处理参数绑定，支持默认参数值
	 * 
	 * @param callable $closure 要执行的闭包函数
	 * @param array $var 正则匹配捕获的参数数组
	 * @return mixed 闭包函数的返回值 ReflectionFunction 用于反射获取闭包函数的参数信
	 * @example self::invokeRegx(function($id) { return $id; }, ['/user/123', '123']) => '123'
	 */
	private static function invokeRegx($closure,array $var = []):()


### /**
	 * 执行规则匹配下的闭包方法
	 * 
	 * 通过反射机制调用规则路由匹配中的闭包函数
	 * 根据参数名称自动绑定路由变量，支持默认参数值
	 * 
	 * @param callable $closure 要执行的闭包函数
	 * @param array $var 规则匹配提取的参数数组(键值对形式)
	 * @return mixed 闭包函数的返回值
	 * @example self::invokeRule(function($id) { return $id; }, ['id' => '123']) => '123'
	 */
	private static function invokeRule($closure,array $var = []):()

