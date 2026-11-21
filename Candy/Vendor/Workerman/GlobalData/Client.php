<?php
namespace GlobalData;

/**
 * 连接失败时的错误出口（vendored Client 会调它，但包里没有定义）
 *
 * 不补这个函数，连接一失败就变成
 * `Call to undefined function GlobalData\showExceptionMsg()`，
 * 把真正的错误盖掉，还会打断调用方的错误处理流程。
 * `getConnection()` 的 docblock 本来就声明「连接失败抛 Exception」，这里按声明实现。
 */
if (!function_exists(__NAMESPACE__ . '\showExceptionMsg')) {
    function showExceptionMsg($type, $message, $code = '', $file = null, $exit = false): void
    {
        throw new \Exception((string) $message, is_numeric($code) ? (int) $code : 0);
    }
}

/**
 * Class Client
 * 全局数据客户端类，用于访问全局共享数据服务
 * $Date: 2026-08-18 20:25:00 $
 * @version 1.0.3
 * @package GlobalData
 */
class Client 
{
    /**
     * 超时时间
     * @var int
     */
    public $timeout = 5;
    
    /**
     * 心跳间隔时间
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
     * 本地缓存
     * @var array
     */
    protected $_cache = [];
    
    /**
     * 构造方法
     * @param array|string $servers 服务器地址列表或单个服务器地址
     * @throws \Exception 当服务器地址为空时抛出异常
     */
    public function __construct($servers)
    {
        if(empty($servers))
        {
            throw new \Exception('servers empty');
        }
        $servers = is_array($servers) ? $servers : [$servers];
        $this->_globalServers = array_values($servers);
    }

    /**
     * 获取与全局服务器的连接
     * @param string $key 数据键名，用于确定连接哪个服务器
     * @return resource 与服务器的连接资源
     * @throws \Exception 当连接失败时抛出异常
     */
    protected function getConnection($key)
    {
        $offset = crc32($key)%count($this->_globalServers);
        if($offset < 0)
        {
            $offset = -$offset;
        }
        
        if(!isset($this->_globalConnections[$offset]) || !is_resource($this->_globalConnections[$offset]) || feof($this->_globalConnections[$offset]))
        {
		    $address = $this->_globalServers[$offset];
            if(strpos($address,'unix://')!==0){
                $address = "tcp://{$address}";
            }
            $connection = @stream_socket_client($address, $code, $msg, $this->timeout);
            if($connection === false)
            {
                showExceptionMsg('shutdown', '使用的GlobalData出现问题，错误信息:'. $msg . '；请检查相关配置。', $msg, null, true);
            } else {
                stream_set_timeout($connection, $this->timeout);
                if(class_exists('\Workerman\Timer') && php_sapi_name() === 'cli')
                {
                    $timer_id = \Workerman\Timer::add($this->pingInterval, function($connection)use(&$timer_id)
                    {
                        $buffer = pack('N', 8)."ping";
                        if(strlen($buffer) !== @fwrite($connection, $buffer))
                        {
                            @fclose($connection);
                            \Workerman\Timer::del($timer_id);
                        }
                    }, [$connection]);
                }
                $this->_globalConnections[$offset] = $connection;
            } 
        }
        return $this->_globalConnections[$offset];
    }
    
    /**
     * 测试连接状态
     * @return bool 连接成功返回true，失败返回false
     */
    public function getStatus()
    {
        $connection = @stream_socket_client('tcp://' . $this->_globalServers[0], $code, $msg, $this->timeout);
        return $connection !== false;
    }

    /**
     * 魔术方法__set，设置全局数据
     * @param string $key 数据键名
     * @param mixed $value 数据值
     * @throws \Exception 当写入失败时抛出异常
     */
    public function __set($key, $value) 
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
           'cmd'   => 'set',
           'key'   => $key,
           'value' => $value,
        ], $connection);
        $this->readFromRemote($connection);
    }

    /**
     * 魔术方法__isset，检查全局数据是否存在
     * @param string $key 数据键名
     * @return bool 数据存在返回true，不存在返回false
     */
    public function __isset($key)
    {
        return null !== $this->__get($key);
    }

    /**
     * 魔术方法__unset，删除全局数据
     * @param string $key 数据键名
     * @throws \Exception 当删除失败时抛出异常
     */
    public function __unset($key) 
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
           'cmd' => 'delete',
           'key' => $key
        ], $connection);
        $this->readFromRemote($connection);
    }
  
    /**
     * 魔术方法__get，获取全局数据
     * @param string $key 数据键名
     * @return mixed 获取的数据值
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
     * 比较并交换（Compare And Swap）
     * @param string $key 数据键名
     * @param mixed $old_value 旧值
     * @param mixed $new_value 新值
     * @return bool 交换成功返回true，失败返回false
     * @throws \Exception 当操作失败时抛出异常
     */
    public function cas($key, $old_value, $new_value)
    {
        $connection = $this->getConnection($key);
        $this->writeToRemote([
           'cmd'     => 'cas',
           'md5' => md5(serialize($old_value)),
           'key'     => $key,
           'value'   => $new_value,
        ],$connection);
        return $this->readFromRemote($connection);
    }
    
    /**
     * 添加数据（如果不存在）
     * @param string $key 数据键名
     * @param mixed $value 数据值
     * @return bool 添加成功返回true，数据已存在返回false
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
     * 递增数据
     * @param string $key 数据键名
     * @param int $step 递增步长，默认值为1
     * @return int|bool 递增后的值，失败返回false
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
     * 向远程服务器写入数据
     * @param array $data 要写入的数据
     * @param resource $connection 与服务器的连接资源
     * @throws \Exception 当写入失败时抛出异常
     */
    protected function writeToRemote($data, $connection)
    {
        $buffer = serialize($data);
        $buffer = pack('N',4 + strlen($buffer)) . $buffer;
        $len = fwrite($connection, $buffer);
        if($len !== strlen($buffer))
        {
            throw new \Exception('writeToRemote fail');
        }
    }
    
    /**
     * 从远程服务器读取数据
     * @param resource $connection 与服务器的连接资源
     * @return mixed 从服务器读取的数据
     * @throws \Exception 当读取失败时抛出异常
     */
    protected function readFromRemote($connection)
    {
        $all_buffer = '';
        $total_len = 4;
        $head_read = false;
        while(1)
        {
            $buffer = fread($connection, 8192);
            if($buffer === '' || $buffer === false)
            {
                throw new \Exception('readFromRemote fail');
            }
            $all_buffer .= $buffer;
            $recv_len = strlen($all_buffer);
            if($recv_len >= $total_len)
            {
                if($head_read)
                {
                    break;
                }
                $unpack_data = unpack('Ntotal_length', $all_buffer);
                $total_len = $unpack_data['total_length'];
                if($recv_len >= $total_len)
                {
                    break;
                }
                $head_read = true;
            }
        }
        return unserialize(substr($all_buffer, 4), ['allowed_classes' => false]);
    }
}
