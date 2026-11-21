<?php

namespace PHPSocketIO\Parser;

use Exception;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Debug;

/**
 * Socket.IO 编码器类
 * 
 * 该类用于编码Socket.IO数据包，将PHP数组格式的数据包转换为字符串格式。
 * 继承自Emitter，目前不支持二进制事件和二进制确认。
 * 
 * @package PHPSocketIO\Parser
 * @extends Emitter
 */
class Encoder extends Emitter
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        Debug::debug('Encoder __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Encoder __destruct');
    }

    /**
     * 编码Socket.IO数据包
     * 
     * @param array $obj 要编码的数据包数组
     * @return array 编码后的字符串数组
     */
    public function encode($obj): array
    {
        if (Parser::BINARY_EVENT == $obj['type'] || Parser::BINARY_ACK == $obj['type']) {
            echo new Exception("not support BINARY_EVENT BINARY_ACK");
            return [];
        } else {
            $encoding = self::encodeAsString($obj);
            return [$encoding];
        }
    }

    /**
     * 将数据包数组编码为字符串
     * 
     * @param array $obj 要编码的数据包数组
     * @return string 编码后的字符串
     */
    public static function encodeAsString($obj): string
    {
        $str = '';
        $nsp = false;

        // 首先是数据包类型
        $str .= $obj['type'];

        // 如果有附件，添加附件数量
        if (Parser::BINARY_EVENT == $obj['type'] || Parser::BINARY_ACK == $obj['type']) {
            $str .= $obj['attachments'];
            $str .= '-';
        }

        // 如果命名空间不是'/'，添加命名空间和逗号
        if (! empty($obj['nsp']) && '/' !== $obj['nsp']) {
            $nsp = true;
            $str .= $obj['nsp'];
        }

        // 紧接着添加ID
        if (isset($obj['id'])) {
            if ($nsp) {
                $str .= ',';
                $nsp = false;
            }
            $str .= $obj['id'];
        }

        // 最后添加JSON数据
        if (isset($obj['data'])) {
            if ($nsp) {
                $str .= ',';
            }
            $str .= json_encode($obj['data']);
        }

        return $str;
    }
}
