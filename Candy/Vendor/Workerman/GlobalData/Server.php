<?php
namespace GlobalData;
use Workerman\Protocols\Frame;
use Workerman\Worker;

/**
 * Class Server
 * 全局数据服务器类，用于提供全局共享数据的服务
 * @package GlobalData
 */
class Server
{
    /**
     * Worker实例
     * @var Worker
     */
    protected $_worker = null;

    /**
     * 所有共享数据
     * @var array
     */
    protected $_dataArray = [];

    /**
     * Server constructor.
     * 初始化全局数据服务器
     * @param string $address 服务器监听地址，格式为 ip:port 或 unix:///path/to/socket
     */
    public function __construct($address = '0.0.0.0:2207')
    {
        if (strpos($address, 'unix://') === 0) {
            $worker           = new Worker($address);
            $worker->protocol = Frame::class;
        } else {
            $worker = new Worker("frame://$address");
        }

        $worker->count = 1;
        $worker->name = 'globalDataServer';
        $worker->onMessage = [$this, 'onMessage'];
        $worker->reloadable = false;
        $this->_worker = $worker; 
    }
    
    /**
     * 处理客户端消息
     * @param TcpConnection $connection 客户端连接实例
     * @param string $buffer 客户端发送的数据
     */
    public function onMessage($connection, $buffer)
    {
        if($buffer === 'ping')
        {
            return;
        }
        $data = @unserialize($buffer, ['allowed_classes' => false]);
        if(!$buffer || !isset($data['cmd']) || !isset($data['key']))
        {
            return $connection->close(serialize('bad request'));
        }
        $cmd = $data['cmd'];
        $key = $data['key'];
        switch($cmd) 
        {
            case 'get':
                if(!isset($this->_dataArray[$key]))
                {
                   return $connection->send('N;');
                }
                return $connection->send(serialize($this->_dataArray[$key]));
                break;
            case 'set':
                $this->_dataArray[$key] = $data['value'];
                $connection->send('b:1;');
                break;
            case 'add':
                if(isset($this->_dataArray[$key]))
                {
                    return $connection->send('b:0;');
                }
                $this->_dataArray[$key] = $data['value'];
                return $connection->send('b:1;');
                break;
            case 'increment':
                if(!isset($this->_dataArray[$key]))
                {
                    return $connection->send('b:0;');
                }
                if(!is_numeric($this->_dataArray[$key]))
                {
                    $this->_dataArray[$key] = 0;
                }
                $this->_dataArray[$key] = $this->_dataArray[$key]+$data['step'];
                return $connection->send(serialize($this->_dataArray[$key]));
                break;
            case 'cas':
                $old_value = !isset($this->_dataArray[$key]) ? null : $this->_dataArray[$key];
                if(md5(serialize($old_value)) === $data['md5'])
                {
                    $this->_dataArray[$key] = $data['value'];
                    return $connection->send('b:1;');
                }
                $connection->send('b:0;');
                break;
            case 'delete':
                unset($this->_dataArray[$key]);
                $connection->send('b:1;');
                break;
            default:
                $connection->close(serialize('bad cmd '. $cmd));
        }
    }
}

