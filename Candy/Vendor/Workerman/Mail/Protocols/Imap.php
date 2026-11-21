<?php
namespace Mail\Protocols;

use \Workerman\Connection\TcpConnection;

/**
 * IMAP协议类
 * 
 * 该类实现了IMAP协议的解析和编码功能。
 * 
 * @package Mail\Protocols
 */
class Imap
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
        // IMAP协议以\r\n为行结束符
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
    public static function encode($data, ?TcpConnection $connection = null)
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
    public static function decode($data, ?TcpConnection $connection = null)
    {
        return trim($data, "\r\n");
    }

    /**
     * 解析IMAP响应
     * 
     * @param string $response 响应数据
     * @return array 解析结果
     */
    public static function parseResponse($response)
    {
        $lines = explode("\r\n", $response);
        $taggedResponses = [];
        $untaggedResponses = [];
        
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            
            // 解析带标签的响应
            if (preg_match('/^([A-Z0-9]+)\s+(OK|NO|BAD)\s+(.*)$/', $line, $matches)) {
                $taggedResponses[] = [
                    'tag' => $matches[1],
                    'status' => $matches[2],
                    'message' => $matches[3]
                ];
            }
            // 解析无标签的响应
            elseif (preg_match('/^\*\s+(.*)$/', $line, $matches)) {
                $untaggedResponses[] = $matches[1];
            }
        }
        
        return [
            'tagged' => $taggedResponses,
            'untagged' => $untaggedResponses
        ];
    }

    /**
     * 构建IMAP命令
     * 
     * @param string $tag 标签
     * @param string $command 命令
     * @param array $params 参数
     * @return string 构建的命令
     */
    public static function buildCommand($tag, $command, $params = [])
    {
        $cmd = $tag . ' ' . strtoupper($command);
        if (!empty($params)) {
            $cmd .= ' ' . implode(' ', $params);
        }
        return $cmd . "\r\n";
    }

    /**
     * 生成IMAP标签
     * 
     * @param int $number 数字
     * @return string 标签
     */
    public static function generateTag($number)
    {
        return 'A' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}
