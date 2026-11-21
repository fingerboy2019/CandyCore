<?php
namespace Mail\Protocols;

use \Workerman\Connection\TcpConnection;

/**
 * POP3协议类
 * 
 * 该类实现了POP3协议的解析和编码功能。
 * 
 * @package Mail\Protocols
 */
class Pop3
{
    /**
     * 检查数据包完整性
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $data 数据
     * @return int 完整数据包的长度，0表示需要更多数据
     */
    public static function input($data, TcpConnection $connection)
    {
        // POP3协议以\r\n为行结束符
        $pos = strpos($data, "\r\n");
        if ($pos === false) {
            return 0;
        }
        
        return $pos + 2;
    }

    /**
     * 编码
     * 
     * @param TcpConnection $connection 连接对象
     * @param mixed $data 数据
     * @return string 编码后的数据
     */
    public static function encode($data, TcpConnection $connection = null)
    {
        return (string)$data;
    }

    /**
     * 解码
     * 
     * @param TcpConnection $connection 连接对象
     * @param string $data 数据
     * @return mixed 解码后的数据
     */
    public static function decode($data, TcpConnection $connection = null)
    {
        return trim($data, "\r\n");
    }

    /**
     * 解析POP3响应
     * 
     * @param string $response 响应数据
     * @return array 解析结果
     */
    public static function parseResponse($response)
    {
        $lines = explode("\r\n", $response);
        $firstLine = $lines[0];
        
        if (strpos($firstLine, '+OK') === 0) {
            return [
                'success' => true,
                'message' => substr($firstLine, 4),
                'data' => array_slice($lines, 1)
            ];
        } elseif (strpos($firstLine, '-ERR') === 0) {
            return [
                'success' => false,
                'message' => substr($firstLine, 5),
                'data' => []
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Invalid response',
            'data' => []
        ];
    }

    /**
     * 构建POP3命令
     * 
     * @param string $command 命令
     * @param array $params 参数
     * @return string 构建的命令
     */
    public static function buildCommand($command, $params = [])
    {
        $cmd = strtoupper($command);
        if (!empty($params)) {
            $cmd .= ' ' . implode(' ', $params);
        }
        return $cmd . "\r\n";
    }
}
