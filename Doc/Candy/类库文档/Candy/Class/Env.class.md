# Env 类

## 概述

* Candy框架 .env配置文件管理类
 * 
 * 提供对.env配置文件的读取、修改、删除等操作，支持分组配置管理和键名大小写转换
 * 可用于动态管理应用程序配置，支持单个或批量操作环境变量
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-07-17 13:45:33
 * @final 该类不允许被继承

## 主要方法


### /**
 * Candy框架 .env配置文件管理类
 * 
 * 提供对.env配置文件的读取、修改、删除等操作，支持分组配置管理和键名大小写转换
 * 可用于动态管理应用程序配置，支持单个或批量操作环境变量
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-07-17 13:45:33
 * @final 该类不允许被继承
 */
declare(strict_types=1);
namespace Candy\Extend;

final class Env {
    /**
     * 环境变量文件路径
     * 
     * 存储.env配置文件的绝对路径或相对路径
     * 
     * @var string
     */
    protected string $envFile;
    
    /**
     * 处理后的环境变量数组
     * 
     * 存储转换过类型的环境变量值，支持null、true、false等特殊值
     * 
     * @var array
     */
    protected array $envArr = [];
    
    /**
     * 原始环境变量数组
     * 
     * 存储从文件中读取的原始环境变量值，用于文件保存时使用
     * 
     * @var array
     */
    private array $envArray = [];
    
    /**
     * 键名大小写转换模式
     * 
     * - true: 转换为大写
     * - false: 转换为小写
     * - null: 保持原样
     * 
     * @var bool|null
     */
    protected bool|null $keyType = true;
	
    /**
     * 构造函数
     * 
     * 初始化Env类实例，设置配置文件路径、键名大小写类型，并读取.env文件内容
     * 
     * @uses G() 全局函数，用于获取配置信息
     * @uses [] 内部方法，用于读取.env文件内容到内存
     * @return void
     */
    public function __construct() {
        // 获取全局配置信息
        $envConfig = G('envConfig');
        // 设置环境变量文件路径，默认为当前目录下的.env文件
        $this->envFile = $envConfig['envFile'] ?:()


### /**
     * 设置环境变量
     * 
     * 支持单个设置或批量设置环境变量，自动处理键名大小写转换和特殊值类型
     * 可以设置分组环境变量（使用点号分隔，如 "database.host"）
     * 
     * @param mixed $key 环境变量键名或键值对数组
     * @param mixed $val 环境变量值，当$key为数组时忽略此参数
     * @return int 更新的环境变量数量
     * @example
     * // 单个设置
     * $env->set('APP_NAME', 'MyApp');
     * // 批量设置
     * $env->set(['DEBUG' => true, 'CACHE' => false]);
     * // 设置分组环境变量
     * $env->set('database.host', 'localhost');
     */
    public function set(mixed $key, mixed $val = ""):()


### /**
     * 获取环境变量
     * 
     * 根据键名获取环境变量值，支持获取分组环境变量和设置默认值
     * 如果不指定键名，则返回所有环境变量数组
     * 
     * @param mixed $key 环境变量键名，为null时返回所有环境变量
     * @param mixed $default 当环境变量不存在时的默认值
     * @return mixed 环境变量值或默认值，当不指定键名时返回所有环境变量数组
     * @example
     * // 获取单个环境变量
     * $appName = $env-\u003 eget('APP_NAME');
     * // 获取分组环境变量
     * $dbHost = $env-\u003 eget('database.host');
     * // 获取所有环境变量
     * $allEnv = $env-\u003 eget();
     * // 获取环境变量并设置默认值
     * $port = $env-\u003 eget('DB_PORT', 3306);
     */
    public function get(mixed $key = null, mixed $default = ""):()


### /**
     * 删除环境变量
     * 
     * 支持单个删除或批量删除环境变量，可选择是否删除空分组
     * 
     * @param mixed $key 环境变量键名或键名数组
     * @param bool $delGroup 当分组为空时是否删除分组，默认为true
     * @param int $i 内部参数，用于递归计数
     * @return int 删除的环境变量数量
     * @example
     * // 删除单个环境变量
     * $env-\u003 edel('APP_DEBUG');
     * // 批量删除环境变量
     * $env-\u003 edel(['DB_HOST', 'DB_USER']);
     * // 删除分组环境变量但保留空分组
     * $env-\u003 edel('database.host', false);
     */
    public function del(mixed $key, bool $delGroup = true, int $i = 0):()


### /**
     * 保存环境变量到文件
     * 
     * 将内存中的环境变量数组写入到.env文件中，自动处理分组和特殊值
     * 
     * @param int $i 要保存的环境变量数量
     * @param string $ini 分组配置的字符串内容
     * @return int 保存的环境变量数量
     * @internal 通常由set()和del()方法内部调用，不建议直接使用
     */
    protected function save(int $i = 0, string $ini = ""):()


### /**
     * 读取.env文件内容到内存
     * 
     * 从配置文件中读取环境变量并解析到内存数组中，支持分组和特殊值类型转换
     * 
     * @param bool $onGroup 是否启用分组解析，默认为true
     * @param bool $readType 读取方式，true使用parse_ini_file，false使用parse_ini_string，默认为true
     * @return static 返回当前实例，支持链式调用
     * @internal 通常在构造函数中调用，不建议直接使用
     */
    protected function array(bool $onGroup = true, bool $readType = true):()


### /**
     * 转换键名大小写
     * 
     * 根据keyType设置，将键名转换为大写、小写或保持原样
     * 
     * @param string|int $key 要转换的键名
     * @return string|int 转换后的键名
     * @internal 内部方法，用于统一处理键名大小写格式
     */
    protected function convert(string|int $key):()

