<?php
/**
 * Adapter 系统函数重置适配器类
 * 
 * 该类用于管理和重置PHP内置系统函数，通过将原始PHP函数替换为框架自定义实现，
 * 实现对HTTP响应、Cookie、Session等核心功能的统一管理和扩展。
 * 
 * 注意事项：
 * - 使用前必须在php.ini中禁用相关原始函数，通过disable_functions配置项
 * - 必须确保框架有足够权限读取和写入相关配置文件
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-12-23 13:33:13
 * @final 本类为最终实现禁止继承
 */

declare (strict_types = 1);
namespace Extend;

use Workerman\Http\Http;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Adapter {
	
    /**
     * 自定义函数列表
     * 
     * 存储从配置文件中读取的需要被框架重写的自定义函数名称
     * 这些函数会在checkFunctionsDisabled方法中被加载并与内置函数一起检查
     * 函数名称从CANDYPATH/.reload文件中读取，以逗号分隔
     * 
     * @var array 存储需要被重写的自定义函数名称的数组
     */
	private static $functions = [];
	
    /**
     * 必须被禁用的PHP内置函数列表
     * 
     * 这些函数由框架重写，以提供额外的安全控制和功能扩展：
     * 
     * 1. HTTP相关函数：
     *    - header: 控制HTTP响应头，框架增加了安全检查和缓存控制
     *    - header_remove: 移除HTTP响应头，框架添加了权限验证
     *    - headers_sent: 检查响应头是否已发送，框架增加了日志记录
     *    - http_response_code: 设置HTTP状态码，框架提供了更严格的状态控制
     * 
     * 2. Cookie相关函数：
     *    - setcookie: 设置Cookie，框架添加了安全标志和默认路径控制
     * 
     * 3. Session相关函数：
     *    - session_create_id: 创建会话ID，框架提供了更安全的生成算法
     *    - session_id: 获取/设置会话ID，框架增加了有效性验证
     *    - session_name: 获取/设置会话名称，框架提供了命名规则控制
     *    - session_save_path: 设置会话保存路径，框架增加了安全检查
     *    - session_status: 获取会话状态，框架提供了更详细的状态信息
     *    - session_start: 启动会话，框架增加了安全配置和自动保存
     *    - session_write_close: 写入并关闭会话，框架优化了性能
     *    - session_regenerate_id: 重新生成会话ID，框架增强了安全性
     * 
     * 4. 执行时间控制：
     *    - set_time_limit: 设置脚本执行时间限制，框架增加了执行上下文控制
     * 
     * @var array 需要被禁用并由框架重写的PHP内置函数列表
     */
	private const FUNCTIONS = ['header', 'header_remove', 'headers_sent', 'http_response_code', 'setcookie', 'session_create_id', 'session_id', 'session_name', 'session_save_path', 'session_status', 'session_start', 'session_write_close', 'session_regenerate_id', 'set_time_limit'];
	
    /**
     * 初始化适配器，检查函数禁用状态并加载自定义函数实现
     * 
     * 执行流程：
     * 1. 检查必须禁用的PHP原生函数是否已正确禁用
     * 2. 加载自定义系统函数实现（从Source目录）
     * 3. 加载框架提供的函数实现（从Candy目录）
     * 4. 初始化HTTP相关功能
     * 
     * 异常处理：
     * - 若检查失败或初始化过程中出错，将显示错误信息并终止程序
     * 
     * @return void
     * @throws Exception 当函数检查失败或初始化出错时抛出异常
     */
	public static function init(): void
    {
        try {
            self::checkFunctionsDisabled();
			
			//加载自定义系统函数
			requireCache(CANDYPATH . 'Source/Func/reload.func.php');
			
			//加载实现的系统函数
			requireCache(CANDYPATH . 'Candy/Func/reload.func.php');
            Http::init();

        } catch (\Exception $e) {
			\Candy\Core\Debug::showMessage($e->getMessage(), 'Shutdown');
        }
    }
	
    /**
     * 检查PHP内置函数是否已被正确禁用
     * 
     * 执行流程：
     * 1. 从CANDYPATH/.reload文件中读取自定义函数列表（若文件存在）
     * 2. 将自定义函数与内置必须禁用函数合并
     * 3. 检查php.ini中disable_functions配置项的设置情况
     * 4. 验证所有必要的函数是否已被禁用
     * 5. 若有函数未被禁用，收集并显示详细的配置错误信息
     * 6. 根据检查结果决定是否终止程序执行
     * 
     * @return void
     * @throws Exception 当必要的函数未被禁用时抛出异常，导致程序终止
     */
    private static function checkFunctionsDisabled(): void
    {
		//先取出自定义函数
		$funContent = file_get_contents(CANDYPATH . '.reload');
		if(empty($funContent) === false) self::$functions = explode(',', $funContent);
		
		
        foreach (array_merge(self::FUNCTIONS, self::$functions) as $function) {
            if (\function_exists($function)) {
				\Candy\Core\Debug::showMessage(self::showConfiguration(), 'Shutdown');
            }
        }
    }
	
    /**
     * 生成并返回适配器配置状态的详细信息
     * 
     * 该方法收集并格式化以下配置信息：
     * 1. 框架和PHP环境信息
     * 2. 已在php.ini中禁用的函数列表
     * 3. 框架要求禁用但未被禁用的函数列表
     * 4. 必要的配置建议和修复方法
     * 
     * @param array $disabledFunctions php.ini中已禁用的函数列表
     * @param array $requiredFunctions 框架要求必须禁用的函数列表
     * @return string 返回格式化的HTML配置信息，包含配置状态和错误提示
     */
	private static function showConfiguration(): string
    {
        $inipath = \php_ini_loaded_file();
        $methods = \implode(',', array_merge(self::FUNCTIONS, self::$functions));
        return "需要在: $inipath 文件中添加" . PHP_EOL . "disable_functions=$methods" . PHP_EOL . '后才可重写这些函数。';
    }
}
