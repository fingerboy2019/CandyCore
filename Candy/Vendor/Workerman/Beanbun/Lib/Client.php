<?php
/**
 * Beanbun 全局数据客户端适配。
 *
 * $Date: 2026-08-18 20:25:00 $
 */
namespace Beanbun\Lib;

/**
 * 全局数据客户端类
 * 
 * 用于与全局数据服务器通信，实现数据的存储、读取和操作
 * @version 1.0.1
 */
class Client
{
    /**
     * 超时时间（秒）
     * @var int
     */
    public $timeout = 5;

    /**
     * 心跳间隔（秒）
     * @var int
     */
    public $pingInterval = 25;

    /**
     * 全局数据服务器地址列表
     * @var array
     */
    protected $_globalServers = [];

    /**
     * 与全局服务器的连接资源
     * @var resource
     */
    protected $_globalConnections = null;

    /**
     * 缓存数组
     * @var array
     */
    protected $_cache = [];

    /**
     * 构造函数
     * 
     * @param array|string $servers 服务器地址列表或单个服务器地址
     * @throws \Exception 当服务器地址为空时抛出异常
     */
    public function __construct($servers)
    {
        if (empty($servers)) {
            throw new \Exception('servers empty');
        }
        $this->_globalServers = array_values((array) $servers);
    }

    /**
     * 获取与全局服务器的连接
     * 
     * @param string $key 用于确定连接哪个服务器的键名
     * @return resource 连接资源
     * @throws \Exception 当连接失败时抛出异常
     */
    protected function getConnection($key)
    {
        $offset = crc32($key) % count($this->_globalServers);
        if ($offset < 0) {
            $offset = -$offset;
        }

        if (!isset($this->_globalConnections[$offset]) || feof($this->_globalConnections[$offset])) {
            $connection = stream_socket_client("tcp://{$this->_globalServers[$offset]}", $code, $msg, $this->timeout);
            if (!$connection) {
                throw new \Exception($msg);
            }
            stream_set_timeout($connection, $this->timeout);
            if (class_exists('\Workerman\Timer') && php_sapi_name() === 'cli') {
                $timer_id = \Workerman\Timer::add($this->pingInterval, function ($connection) use (&$timer_id) {
                    $buffer = pack('N', 8) . "ping";
                    if (strlen($buffer) !== @fwrite($connection, $buffer)) {
                        @fclose($connection);
                        \Workerman\Timer::del($timer_id);
                    }
                }, [$connection]);
            }
            $this->_globalConnections[$offset] = $connection;
        }
        return $this->_globalConnections[$offset];
    }

    /**
     * 魔术方法：设置属性
     * 
     * @param string $key 键名
     * @param mixed $value 值
     * @throws \Exception 当写入失败时抛出异常
     */
    public function __set($key, $value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'set',
            'key' => $key,
            'value' => $value,
        ], $connection);
        $this->readFromRemote($connection);
    }

    /**
     * 魔术方法：检查属性是否存在
     * 
     * @param string $key 键名
     * @return bool 属性是否存在
     */
    public function __isset($key)
    {
        return null !== $this->__get($key);
    }

    /**
     * 魔术方法：删除属性
     * 
     * @param string $key 键名
     * @throws \Exception 当删除失败时抛出异常
     */
    public function __unset($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'delete',
            'key' => $key,
        ], $connection);
        $this->readFromRemote($connection);
    }

    /**
     * 魔术方法：获取属性
     * 
     * @param string $key 键名
     * @return mixed 值
     * @throws \Exception 当读取失败时抛出异常
     */
    public function __get($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'get',
            'key' => $key,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 获取数据
     * 
     * @param string $key 键名
     * @return mixed 值
     * @throws \Exception 当读取失败时抛出异常
     */
    public function get($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'get',
            'key' => $key,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 比较并交换（Compare And Swap）
     * 
     * @param string $key 键名
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @return bool 是否交换成功
     * @throws \Exception 当操作失败时抛出异常
     */
    public function cas($key, $old_value, $new_value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'cas',
            'md5' => md5(serialize($old_value)),
            'key' => $key,
            'value' => $new_value,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 更新数据
     * 
     * @param string $key 键名
     * @param callable $callback 回调函数，接收旧值，返回新值
     * @throws \Exception 当操作失败时抛出异常
     */
    public function up($key, $callback)
    {
        do {
            $old_value = $this->$key;
            $new_value = $callback($old_value);
        } while (!$this->cas($key, $old_value, $new_value));
    }

    /**
     * 添加数据
     * 
     * @param string $key 键名
     * @param mixed $value 值
     * @return bool 是否添加成功
     * @throws \Exception 当操作失败时抛出异常
     */
    public function add($key, $value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'add',
            'key' => $key,
            'value' => $value,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 递增数值
     * 
     * @param string $key 键名
     * @param int $step 步长（默认1）
     * @return int 递增后的值
     * @throws \Exception 当操作失败时抛出异常
     */
    public function increment($key, $step = 1)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'increment',
            'key' => $key,
            'step' => $step,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 向数组末尾添加元素
     * 
     * @param string $key 键名
     * @param mixed $value 要添加的值
     * @return bool 是否添加成功
     * @throws \Exception 当操作失败时抛出异常
     */
    public function push($key, $value = false)
    {
        if ($value === false) {
            return false;
        }

        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'push',
            'key' => $key,
            'value' => $value,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 移除并返回数组的第一个元素
     * 
     * @param string $key 键名
     * @return mixed 移除的元素
     * @throws \Exception 当操作失败时抛出异常
     */
    public function shift($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'shift',
            'key' => $key,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 移除并返回数组的最后一个元素
     * 
     * @param string $key 键名
     * @return mixed 移除的元素
     * @throws \Exception 当操作失败时抛出异常
     */
    public function pop($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'pop',
            'key' => $key,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 获取数组的长度
     * 
     * @param string $key 键名
     * @return int 数组长度
     * @throws \Exception 当操作失败时抛出异常
     */
    public function count($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'count',
            'key' => $key,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 随机获取数组中的一个元素
     * 
     * @param string $key 键名
     * @return mixed 随机元素
     * @throws \Exception 当操作失败时抛出异常
     */
    public function rand($key)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'rand',
            'key' => $key,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 检查元素是否在数组中
     * 
     * @param string $key 键名
     * @param mixed $value 要检查的元素
     * @return bool 是否在数组中
     * @throws \Exception 当操作失败时抛出异常
     */
    public function in($key, $value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'in',
            'key' => $key,
            'value' => $value,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 创建布隆过滤器
     * 
     * @param string $key 键名
     * @param array $value 布隆过滤器参数
     * @return bool 是否创建成功
     * @throws \Exception 当操作失败时抛出异常
     */
    public function bfNew($key, $value = [])
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'bfNew',
            'key' => $key,
            'value' => $value,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 向布隆过滤器添加元素
     * 
     * @param string $key 键名
     * @param mixed $value 要添加的元素
     * @return bool 是否添加成功
     * @throws \Exception 当操作失败时抛出异常
     */
    public function bfAdd($key, $value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'bfAdd',
            'key' => $key,
            'value' => $value,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 检查元素是否在布隆过滤器中
     * 
     * @param string $key 键名
     * @param mixed $value 要检查的元素
     * @return bool 是否在布隆过滤器中
     * @throws \Exception 当操作失败时抛出异常
     */
    public function bfIn($key, $value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
            'cmd' => 'bfIn',
            'key' => $key,
            'value' => $value,
        ], $connection);
        return $this->readFromRemote($connection);
    }

    /**
     * 向全局服务器写入数据
     * 
     * @param array $data 要写入的数据
     * @param resource $connection 连接资源
     * @throws \Exception 当写入失败时抛出异常
     */
    protected function writeToRemote($data, $connection)
    {
        $buffer = serialize($data);
        $buffer = pack('N', 4 + strlen($buffer)) . $buffer;
        $len = fwrite($connection, $buffer);
        if ($len !== strlen($buffer)) {
            throw new \Exception('writeToRemote fail');
        }
    }

    /**
     * 从全局服务器读取数据
     * 
     * @param resource $connection 连接资源
     * @return mixed 读取的数据
     * @throws \Exception 当读取失败时抛出异常
     */
    protected function readFromRemote($connection)
    {
        $all_buffer = '';
        $total_len = 4;
        $head_read = false;
        while (1) {
            $buffer = fread($connection, 8192);
            if ($buffer === '' || $buffer === false) {
                throw new \Exception('readFromRemote fail');
            }
            $all_buffer .= $buffer;
            $recv_len = strlen($all_buffer);
            if ($recv_len >= $total_len) {
                if ($head_read) {
                    break;
                }
                $unpack_data = unpack('Ntotal_length', $all_buffer);
                $total_len = $unpack_data['total_length'];
                if ($recv_len >= $total_len) {
                    break;
                }
                $head_read = true;
            }
        }
        return unserialize(substr($all_buffer, 4), ['allowed_classes' => false]);
    }
}
