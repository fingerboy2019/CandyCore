<?php

namespace PHPSocketIO;

use Workerman\Worker;
use PHPSocketIO\Engine\Engine;

/**
 * Socket.IO 服务器类
 * 
 * 该类是 PHPSocketIO 的核心类，负责初始化和管理整个 Socket.IO 服务器，
 * 包括命名空间、适配器、连接事件等。
 * 
 * @package PHPSocketIO
 */
class SocketIO
{
    /**
     * Workerman Worker 实例
     * @var Worker
     */
    public $worker;
    
    /**
     * 默认命名空间的 Socket 实例
     * @var Socket
     */
    public $sockets;
    
    /**
     * 命名空间列表
     * @var array
     */
    public $nsps = [];
    
    /**
     * 命名空间类名
     * @var string
     */
    protected $_nsp = null;
    
    /**
     * Socket 类名
     * @var string
     */
    protected $_socket = null;
    
    /**
     * 适配器类名
     * @var string
     */
    protected $_adapter = null;
    
    /**
     * Engine.IO 引擎实例
     * @var Engine
     */
    public $engine = null;
    
    /**
     * 允许的来源
     * @var string
     */
    protected $_origins = '*:*';
    
    /**
     * 路径
     * @var string
     */
    protected $_path = null;

    /**
     * 构造函数
     * 
     * @param int|null $port 监听端口
     * @param array $opts 配置选项
     */
    public function __construct($port = null, $opts = [])
    {
        $nsp = $opts['nsp'] ?? '\PHPSocketIO\Nsp';
        $this->nsp($nsp);

        $socket = $opts['socket'] ?? '\PHPSocketIO\Socket';
        $this->socket($socket);

        $adapter = $opts['adapter'] ?? '\PHPSocketIO\DefaultAdapter';
        $this->adapter($adapter);
        if (isset($opts['origins'])) {
            $this->origins($opts['origins']);
        }

        unset($opts['nsp'], $opts['socket'], $opts['adapter'], $opts['origins']);

        $this->sockets = $this->of('/');

        if (! class_exists('Protocols\SocketIO')) {
            class_alias('PHPSocketIO\Engine\Protocols\SocketIO', 'Protocols\SocketIO');
        }
        if ($port) {
            $host = '0.0.0.0';
            if (isset($opts['host'])) {
                $ip = trim($opts['host'], '[]');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $host = (strpos($ip, ':') !== false) ? "[$ip]" : $ip;
                }
            }
            $worker = new Worker('SocketIO://' . $host . ':' . $port, $opts);
            $worker->name = 'PHPSocketIO';

            if (isset($opts['ssl'])) {
                $worker->transport = 'ssl';
            }

            $this->attach($worker);
        }
    }

    /**
     * 设置或获取命名空间类名
     * 
     * @param string|null $v 命名空间类名
     * @return string|SocketIO 返回命名空间类名或当前实例
     */
    public function nsp($v = null)
    {
        if (empty($v)) {
            return $this->_nsp;
        }
        $this->_nsp = $v;
        return $this;
    }

    /**
     * 设置或获取 Socket 类名
     * 
     * @param string|null $v Socket 类名
     * @return string|SocketIO 返回 Socket 类名或当前实例
     */
    public function socket($v = null)
    {
        if (empty($v)) {
            return $this->_socket;
        }
        $this->_socket = $v;
        return $this;
    }

    /**
     * 设置或获取适配器类名
     * 
     * @param string|null $v 适配器类名
     * @return string|SocketIO 返回适配器类名或当前实例
     */
    public function adapter($v = null)
    {
        if (empty($v)) {
            return $this->_adapter;
        }
        $this->_adapter = $v;
        foreach ($this->nsps as $nsp) {
            $nsp->initAdapter();
        }
        return $this;
    }

    /**
     * 设置或获取允许的来源
     * 
     * @param string|null $v 允许的来源，格式为 "*:*"
     * @return string|SocketIO 返回允许的来源或当前实例
     */
    public function origins($v = null)
    {
        if ($v === null) {
            return $this->_origins;
        }
        $this->_origins = $v;
        if (isset($this->engine)) {
            $this->engine->origins = $this->_origins;
        }
        return $this;
    }

    /**
     * 附加到 Worker 实例
     * 
     * @param Worker $srv Worker 实例
     * @param array $opts 配置选项
     * @return SocketIO 返回当前实例
     */
    public function attach($srv, $opts = []): SocketIO
    {
        $engine = new Engine();
        $engine->attach($srv, $opts);

        // Export http server
        $this->worker = $srv;

        // bind to engine events
        $this->bind($engine);

        return $this;
    }

    /**
     * 绑定到 Engine.IO 引擎
     * 
     * @param Engine $engine Engine.IO 引擎实例
     * @return SocketIO 返回当前实例
     */
    public function bind($engine): SocketIO
    {
        $this->engine = $engine;
        $this->engine->on('connection', [$this, 'onConnection']);
        $this->engine->origins = $this->_origins;
        return $this;
    }

    /**
     * 获取或创建命名空间
     * 
     * @param string $name 命名空间名称
     * @param callable|null $fn 连接事件回调
     * @return Nsp 返回命名空间实例
     */
    public function of($name, $fn = null)
    {
        if ($name[0] !== '/') {
            $name = "/$name";
        }
        if (empty($this->nsps[$name])) {
            $nsp_name = $this->nsp();
            $this->nsps[$name] = new $nsp_name($this, $name);
        }
        if ($fn) {
            $this->nsps[$name]->on('connect', $fn);
        }
        return $this->nsps[$name];
    }

    /**
     * 处理连接事件
     * 
     * @param Connection $engine_socket Engine.IO 连接实例
     * @return SocketIO 返回当前实例
     */
    public function onConnection($engine_socket): SocketIO
    {
        $client = new Client($this, $engine_socket);
        $client->connect('/');
        return $this;
    }

    /**
     * 注册事件监听器
     * 
     * @param string $event 事件名称
     * @param callable|null $callback 事件回调
     * @return mixed 返回事件注册结果
     */
    public function on()
    {
        $args = array_pad(func_get_args(), 2, null);

        if ($args[0] === 'workerStart') {
            $this->worker->onWorkerStart = $args[1];
        } elseif ($args[0] === 'workerStop') {
            $this->worker->onWorkerStop = $args[1];
        } elseif ($args[0] !== null) {
            return call_user_func_array([$this->sockets, 'on'], $args);
        }
    }

    /**
     * 设置广播目标房间
     * 
     * @param string $room 房间名称
     * @return Socket 返回 Socket 实例
     */
    public function in()
    {
        return call_user_func_array([$this->sockets, 'in'], func_get_args());
    }

    /**
     * 设置广播目标房间（in 方法的别名）
     * 
     * @param string $room 房间名称
     * @return Socket 返回 Socket 实例
     */
    public function to()
    {
        return call_user_func_array([$this->sockets, 'to'], func_get_args());
    }

    /**
     * 发送事件
     * 
     * @param string $event 事件名称
     * @param mixed $data 事件数据
     * @return Socket 返回 Socket 实例
     */
    public function emit()
    {
        return call_user_func_array([$this->sockets, 'emit'], func_get_args());
    }

    /**
     * 发送消息
     * 
     * @param mixed $data 消息数据
     * @return Socket 返回 Socket 实例
     */
    public function send()
    {
        return call_user_func_array([$this->sockets, 'send'], func_get_args());
    }

    /**
     * 写入数据
     * 
     * @param string $data 数据
     * @return Socket 返回 Socket 实例
     */
    public function write()
    {
        return call_user_func_array([$this->sockets, 'write'], func_get_args());
    }
}
