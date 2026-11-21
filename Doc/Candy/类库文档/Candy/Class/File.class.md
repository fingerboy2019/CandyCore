# File 类

## 概述

*
 * Candy框架 文件缓存类 (废弃 被Cache\File类替代)
 * 
 * 该类提供高性能的文件缓存机制，支持文件与内存双层缓存策略，在开启shmop扩展模式下可获得更好的性能表现。
 * 主要功能：
 * - 文件缓存的创建、读取、更新和删除(CRUD)操作
 * - 支持缓存过期时间设置与自动清理
 * - 提供双层存储机制(内存缓存优先，文件作为备份)
 * - 支持缓存目录自定义配置
 * 
 * 使用场景：
 * - 缓存配置信息、模板编译结果等静态资源
 * - 频繁访问但不常变动的数据缓存
 * - 需要持久化但又需要快速访问的数据
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-07-17 13:45:33
 * @final 该类不允许被继承
 * @example 
 * ```
 * // 创建文件缓存实例
 * $fileCache = new \Candy\Extend\File(['dir' => './cache', 'ext' => '

## 主要方法


### /***
 * Candy框架 文件缓存类 (废弃 被Cache\File类替代)
 * 
 * 该类提供高性能的文件缓存机制，支持文件与内存双层缓存策略，在开启shmop扩展模式下可获得更好的性能表现。
 * 主要功能：
 * - 文件缓存的创建、读取、更新和删除(CRUD)操作
 * - 支持缓存过期时间设置与自动清理
 * - 提供双层存储机制(内存缓存优先，文件作为备份)
 * - 支持缓存目录自定义配置
 * 
 * 使用场景：
 * - 缓存配置信息、模板编译结果等静态资源
 * - 频繁访问但不常变动的数据缓存
 * - 需要持久化但又需要快速访问的数据
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-07-17 13:45:33
 * @final 该类不允许被继承
 * @example 
 * ```
 * // 创建文件缓存实例
 * $fileCache = new \Candy\Extend\File(['dir' => './cache', 'ext' => '.cache']);
 * // 设置缓存
 * $fileCache->set('user_info', ['name' => '张三', 'age' => 25], 3600);
 * // 获取缓存
 * $userInfo = $fileCache->get('user_info');
 * // 删除缓存
 * $fileCache->delete('user_info');
 * // 清除所有缓存
 * $fileCache->flush();
 * ```
 */
 
declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class File {
    public $tipMessage;			//提示信息
    protected $cacheDir;			//缓存目录
    private $cacheFileName;			//缓存文件名
    private $cacheFileSuffix;		//缓存文件后缀
    private $cache;	            	//缓存对象
	
    /**
     * 构造函数 - 初始化文件缓存类
     * 
     * @param array $config 配置数组，支持以下选项：
     *                       - dir: 缓存目录路径，如果未设置则尝试使用C('C_cacheDir')，如果都未设置则默认使用RUNTIME/Tmps
     *                       - ext: 缓存文件后缀，默认使用.php（推荐使用，因为无法直接被HTTP访问）
     * @throws \Exception 当无法创建缓存目录时可能抛出异常
     * @example
     * ```
     * // 使用默认配置
     * $fileCache = new \Candy\Extend\File();
     * 
     * // 自定义配置
     * $fileCache = new \Candy\Extend\File([
     *     'dir' => './custom_cache',
     *     'ext' => '.cache'
     * ]);
     * ```
     */  
    public function __construct(array $config = [])
	{
        if(isset($config['dir'])){
            $this->cacheDir = $config['dir'];
        }elseif(!is_null(C('C_cacheDir'))){
            $this->cacheDir = C('C_cacheDir');
        }else {
            $this->cacheDir = C('RUNTIME').'Tmps';
        }
        
        $this->cacheFileSuffix = $config['ext'] ??= '.php';		
        if(!$this->isDirValid($this->cacheDir)){
            showExceptionMsg('error', $this->tipMessage);
        }

        $this->cache = \getCache();
    }
	
	
    /**
     * 检查缓存连接状态
     * 
     * 该方法用于检查缓存连接是否正常。在当前实现中，文件缓存始终返回true，表示连接正常。
     * 
     * @return bool 始终返回true，表示缓存连接正常
     * @note 该方法保持与其他缓存类接口的一致性，便于框架内的统一调用
     */
	public function cacheConnectError():()


### /**
     * 添加一个值 - 如果键已存在则返回false（仅在键不存在时添加）
     *
     * @param mixed $cacheKey 缓存键名，用于唯一标识缓存数据
     * @param mixed $cache_value 保存的缓存数据，支持的类型：integer, string, array, boolean, double
     * @param int $lifeTime 保存时间（秒），如果设置为0则永远不过期，默认值为0
     * @param bool $writeFile 是否强制文件缓存模式（当前版本未使用此参数）
     * @return bool 设置成功返回true，键已存在或保存失败返回false
     * @note 当缓存键已存在时，会设置tipMessage属性提示"缓存数据已存在"，可通过该属性获取错误信息
     * @example
     * ```
     * // 尝试添加不存在的缓存
     * $result = $fileCache->add('new_key', 'new_value', 3600); // 返回true
     * 
     * // 尝试添加已存在的缓存
     * $result = $fileCache->add('new_key', 'different_value'); // 返回false
     * echo $fileCache->tipMessage; // 输出：缓存数据已存在
     * ```
     */
    public function add(mixed $cacheKey,mixed $cache_value,int $lifeTime=0):()


### /**
     * 设置缓存值 - 如果键已存在则覆盖，不存在则添加
     *
     * @param mixed $cacheKey 缓存键名，用于唯一标识缓存数据
     * @param mixed $cache_value 保存的缓存数据，仅支持：integer, string, array, boolean, double 类型
     * @param int $lifeTime 保存时间（秒），如果设置为0则永远不过期，默认值为0
     * @return bool 设置成功返回true，失败返回false（如不支持的数据类型或写入失败）
     * @note 
     * - 该方法会先将数据写入内存缓存（如果可用），同时写入文件作为备份
     * - 不支持的数据类型会设置tipMessage属性为"不支持的数据类型"
     * - 文件写入失败会设置tipMessage属性为"写入缓存失败"
     * @throws 无直接异常抛出，但会在tipMessage属性中保存错误信息
     * @example
     * ```
     * // 设置字符串缓存，有效期1小时
     * $fileCache->set('user_name', '张三', 3600);
     * 
     * // 设置数组缓存，永久有效
     * $userInfo = ['id' => 1, 'name' => '李四', 'age' => 30];
     * $fileCache->set('user_1', $userInfo);
     * 
     * // 覆盖已存在的缓存
     * $fileCache->set('user_name', '王五'); // 将覆盖之前的'张三'
     * ```
     */
    public function set(mixed $cacheKey,mixed $cache_value,int $lifeTime=0):()


### /**
     * 通过指定的键获取缓存值
     *
     * @param string $cacheKey 缓存键名，用于查找缓存数据
     * @param mixed $default 当缓存不存在或已过期时返回的默认值，默认值为null
     * @return mixed 获取成功返回缓存的值，缓存不存在或已过期时返回默认值
     * @note 
     * - 优先从内存缓存中读取数据（如果可用）
     * - 内存缓存未命中时，会从文件中读取
     * - 当缓存已过期时，会自动删除文件和内存中的缓存数据
     * @example
     * ```
     * // 获取缓存，不存在时返回null
     * $value = $fileCache->get('user_name');
     * 
     * // 获取缓存，不存在时返回默认值
     * $value = $fileCache->get('user_age', 18); // 如果缓存不存在，返回18
     * 
     * // 获取数组类型的缓存
     * $userInfo = $fileCache->get('user_1');
     * echo $userInfo['name'] ?? '未知';
     * ```
     */
    public function get(string $cacheKey, mixed $default = null):()


### /**
     * 通过指定的键删除缓存
     *
     * @param string $cacheKey 要删除的缓存键名
     * @return bool 删除成功返回true，文件不存在时也返回true（因为结果一样），文件删除失败返回false
     * @note 
     * - 该方法会同时删除内存缓存和文件缓存
     * - 如果文件不存在，会设置tipMessage属性为"文件不存在"，但仍返回true
     * @example
     * ```
     * // 删除指定缓存
     * $result = $fileCache->delete('user_name');
     * if($result) {
     *     echo '缓存删除成功';
     * } else {
     *     echo '缓存删除失败: ' . $fileCache->tipMessage;
     * }
     * ```
     */
    public function delete(string $cacheKey):()


### /**
     * 清除所有缓存文件
     *
     * @return void 无返回值
     * @note 
     * - 该方法会同时清除内存缓存和文件缓存
     * - 会删除缓存目录下的所有文件（不区分过期与否）
     * - 谨慎使用此方法，会导致所有缓存数据丢失
     * @example
     * ```
     * // 清除所有缓存
     * $fileCache->flush();
     * echo '所有缓存已清除';
     * ```
     */
    public function flush():()


### /**
     * 自动清除过期缓存文件
     *
     * @return void 无返回值
     * @note 
     * - 该方法会清除内存中的fileCache键下的所有缓存
     * - 会扫描缓存目录，仅删除已过期的缓存文件
     * - 与flush()不同，此方法只删除过期文件，保留有效缓存
     * @example
     * ```
     * // 自动清理过期缓存
     * $fileCache->autoDeleteExpiredFile();
     * echo '过期缓存已清理';
     * ```
     */
    public function autoDeleteExpiredFile():()


### /**
     * 检查目录是否存在，不存在则尝试创建
     *
     * @param string $dir 要检查的目录路径
     * @return bool 目录存在或创建成功返回true，创建失败返回false
     * @note 当创建目录失败时，会设置tipMessage属性提示详细错误信息
     * @throws \Exception 当创建目录失败时可能抛出异常（在try-catch块中被捕获）
     */
    private function isDirValid(string $dir):()


### /**
     * 检查缓存是否有效（未过期）
     *
     * 根据文件的修改时间和过期时间判断缓存是否仍然有效
     *
     * @param int $expiredTime 过期时间（秒），如果为0表示永不过期，默认值为0
     * @return bool 缓存有效返回true，过期或文件不存在返回false
     * @note 该方法依赖cacheFileName属性，在调用前需要确保此属性已被设置
     */
    private function isCacheValid(int $expiredTime = 0):()


### /**
     * 获取缓存文件名
     *
     * 根据给定的键名生成对应的缓存文件名，使用md5对键名进行哈希处理
     * 文件名格式为：缓存目录/[md5('candy_键名')].文件后缀
     *
     * @param string $key 缓存键名
     * @return string 生成的缓存文件完整路径
     * @note 该方法会同时设置类的cacheFileName属性
     * @example
     * ```
     * // 获取缓存文件名
     * $fileName = $fileCache->getCacheFileName('user_info');
     * echo $fileName; // 例如: /path/to/cache/a1b2c3d4e5f6.php
     * ```
     */
	public function getCacheFileName(string $key):()

