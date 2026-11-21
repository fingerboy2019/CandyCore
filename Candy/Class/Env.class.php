<?php
/**
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
        $this->envFile = $envConfig['envFile'] ?: './.env';
        // 设置键名大小写类型，默认为false（不转换）
        $this->keyType = $envConfig['keyType'] ?? false;
        // 读取.env文件内容到内存，默认启用分组和文件解析模式
        $this->array(($envConfig['onGroup'] ?? true), ($envConfig['readType'] ?? true));
    }

    /**
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
    public function set(mixed $key, mixed $val = ""): int
    {
        $i = 0;
        // 处理参数，支持单个键值对或数组批量设置
        $array = is_array($key) ? $key : [$key => $val];
        
        // 定义特殊值转换函数，将null、true、false转换为字符串表示
        $rep = function ($val) {
            return ($val === null ? "'null'" : ($val === true ? "'true'" : ($val === false ? "'false'" : $val)));
        };
        
        // 遍历所有要设置的环境变量
        foreach ($array as $k => $v) {
            ++$i;
            // 转换键名大小写并去除首尾空白
            $keys = $this->convert(trim($k));
            
            // 处理分组键名（使用点号分隔）
            $arr = explode(".", $keys);
            if (!empty($one = ($arr[0] ?? "")) && !empty($two = ($arr[1] ?? ""))) {
                // 检查是否需要使用分组模式存储
                if (
                    empty(isset($this->envArray[$one]))
                    || is_array($this->envArray[$one])
                ) {
                    // 处理多级分组键名，如 "a.b.c" 会被解析为 a.b 组的 c 键
                    if (count($arr) > 2) {
                        $one = join(".", array_slice($arr, 0, -1));
                        $two = join(".", array_slice($arr, -1));
                    }
                    // 分组模式存储
                    $this->envArray[$one][$two] = $rep($v);
                    $this->envArr[$one][$two] = $v;
                } else {
                    // 非分组模式存储
                    $this->envArray[$keys] = $rep($v);
                    $this->envArr[$keys] = $v;
                }
            } else {
                // 非分组键名直接存储
                $this->envArray[$keys] = $rep($v);
                $this->envArr[$keys] = $v;
            }
        }
        return $this->save($i);
    }

    /**
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
    public function get(mixed $key = null, mixed $default = ""): mixed
    {
        // 如果指定了键名，则获取单个环境变量
        if (isset($key)) {
            // 转换键名大小写
            $keys = $this->convert($key);
            
            // 尝试直接获取环境变量值
            if (empty($data = $this->envArr[$keys] ?? "")) {
                // 尝试作为分组键名处理
                $arr = explode(".", $keys);
                if (
                    !empty($one = ($arr[0] ?? ""))
                    && !empty($two = ($arr[1] ?? ""))
                ) {
                    // 处理多级分组键名，如 "a.b.c" 会被解析为 a.b 组的 c 键
                    if (count($arr) > 2) {
                        $one = join(".", array_slice($arr, 0, -1));
                        $two = join(".", array_slice($arr, -1));
                    }
                    // 从分组中获取环境变量值
                    $data = (!empty($data = $this->envArr[$one][$two] ?? "")) ? $data : "";
                }
            }
            // 如果获取不到值，则返回默认值
            return $data ?: $default;
        }
        return $this->envArr;
    }

    /**
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
    public function del(mixed $key, bool $delGroup = true, int $i = 0): int
    {
        $array = is_array($key) ? $key : [$key];
        foreach ($array as $k) {
            $keys = $this->convert(trim($k));
            if (isset($this->envArray[$keys]) && ($delGroup === true || empty(is_array($this->envArray[$keys])) || empty($this->envArray[$keys] ?? "")))
			{
                ++$i;
                unset($this->envArray[$keys]);
                unset($this->envArr[$keys]);
            } else {
                $arr = explode(".", $keys);
                if (empty($one = ($arr[0] ?? "")) === false && empty($two = ($arr[1] ?? "")) === false) {
                    if (count($arr) > 2) {
                        $one = join(".", array_slice($arr, 0, -1));
                        $two = join(".", array_slice($arr, -1));
                    }
                    if (isset($this->envArray[$one][$two]) && (empty(is_array($this->envArray[$one][$two])) || empty($this->envArray[$one][$two] ?? "")))
					{
                        unset($this->envArray[$one][$two]);
                        unset($this->envArr[$one][$two]);
                        if (
                            $delGroup === true
                            && isset($this->envArray[$one])
                            && is_array($this->envArray[$one])
                            && count($this->envArray[$one]) == 0
                        ) {
                            unset($this->envArray[$one]);
                            unset($this->envArr[$one]);
                        }
                        ++$i;
                    }
                }
            }
        }
        return $this->save($i);
    }

    /**
     * 保存环境变量到文件
     * 
     * 将内存中的环境变量数组写入到.env文件中，自动处理分组和特殊值
     * 
     * @param int $i 要保存的环境变量数量
     * @param string $ini 分组配置的字符串内容
     * @return int 保存的环境变量数量
     * @internal 通常由set()和del()方法内部调用，不建议直接使用
     */
    protected function save(int $i = 0, string $ini = ""): int 
    {
        // 只有当有环境变量需要保存时才执行写入操作
        if ($i > 0) {
            // 定义值处理函数，处理可调用对象、数组和特殊字符串值
            $rep = function ($val) {
                // 如果是可调用对象，则执行并获取结果
                $val = is_callable($val) ? $val() : $val;
                // 如果是数组，则转换为JSON字符串
                $val = is_array($val) ? json_encode($val) : $val;
                // 处理特殊字符串值，为null、true、false添加引号
                return (
                is_string($val)
                    ? (in_array(strtolower($val), ["null", "true", "false"])
                    ? ("'" . $val . "'")
                    : $val
                ) : $val
                );
            };
            
            $env = "";
            // 遍历所有环境变量，分别处理分组和非分组变量
            foreach ($this->envArray as $key => $val) {
                if (is_array($val)) {
                    // 处理分组配置，添加分组头
                    $ini .= PHP_EOL . "[" . $key . "]" . PHP_EOL;
                    foreach ($val as $k => $v) {
                        $ini .= $k . " = " . $rep($v) . PHP_EOL;
                    }
                } else {
                    // 处理非分组配置
                    $env .= $key . " = " . $rep($val) . PHP_EOL;
                }
            }
            // 将环境变量内容写入到文件，去除首尾空白行
            @file_put_contents($this->envFile, trim($env . $ini, PHP_EOL));
        }
        return $i;
    }

    /**
     * 读取.env文件内容到内存
     * 
     * 从配置文件中读取环境变量并解析到内存数组中，支持分组和特殊值类型转换
     * 
     * @param bool $onGroup 是否启用分组解析，默认为true
     * @param bool $readType 读取方式，true使用parse_ini_file，false使用parse_ini_string，默认为true
     * @return static 返回当前实例，支持链式调用
     * @internal 通常在构造函数中调用，不建议直接使用
     */
    protected function array(bool $onGroup = true, bool $readType = true): static
    {
        // 防止重复加载，如果已经加载过则不再加载
        if(empty($this->envArray) && empty($this->envArr)){
            // 根据读取方式选择解析方法
            // readType=true: 使用parse_ini_file直接解析文件
            // readType=false: 先读取文件内容，再使用parse_ini_string解析
            $array = ($readType ? @parse_ini_file($this->envFile, $onGroup) : (@parse_ini_string(@file_get_contents($this->envFile), $onGroup)));
            
            // 如果解析成功且结果是数组
            if (!empty($array) && is_array($array)) {
                // 定义值转换函数，将字符串形式的特殊值转换为对应的数据类型
                $rep = function ($val) {
                    if (is_string($val)) {
                        $v = strtolower($val);
                        // 将字符串'null'、'false'、'true'转换为对应的PHP值
                        $val = ($v == 'null' ? null : ($v == 'false' ? false : ($v == 'true' ? true : $val)));
                    }
                    return $val;
                };
                
                // 遍历所有解析到的环境变量
                foreach ($array as $key => $val) {
                    // 转换键名大小写
                    $keys = $this->convert($key);
                    
                    // 处理分组环境变量
                    if (!empty($val) && is_array($val)) {
                        foreach ($val as $k => $v) {
                            // 转换分组内键名大小写
                            $ks = $this->convert($k);
                            // 保存原始值和转换后的值
                            $this->envArray[$keys][$ks] = $v;
                            $this->envArr[$keys][$ks] = $rep($v);
                        }
                    } else {
                        // 处理非分组环境变量
                        $this->envArray[$keys] = $val;
                        $this->envArr[$keys] = $rep($val);
                    }
                }
            }
        }
		
        return $this;
    }

    /**
     * 转换键名大小写
     * 
     * 根据keyType设置，将键名转换为大写、小写或保持原样
     * 
     * @param string|int $key 要转换的键名
     * @return string|int 转换后的键名
     * @internal 内部方法，用于统一处理键名大小写格式
     */
    protected function convert(string|int $key): string|int
    {
        return ($this->keyType === true) ? strtoupper($key) : ($this->keyType === false ? strtolower($key) : $key);
    }
}

