<?php

namespace PHPSocketIO\Parser;

use Exception;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Debug;

/**
 * Socket.IO 解码器类
 * 
 * 该类用于解码Socket.IO数据包，将字符串格式的数据包转换为PHP数组格式。
 * 继承自Emitter，解码完成后会触发'decoded'事件。
 * 
 * @package PHPSocketIO\Parser
 * @extends Emitter
 */
class Decoder extends Emitter
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        Debug::debug('Decoder __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Decoder __destruct');
    }

    /**
     * 添加要解码的对象
     * 
     * @param string $obj 要解码的字符串对象
     * @return void
     * @throws Exception
     */
    public function add($obj): void
    {
        if (is_string($obj)) {
            $packet = self::decodeString($obj);
            $this->emit('decoded', $packet);
        }
    }

    /**
     * 解码字符串格式的Socket.IO数据包
     * 
     * @param string $str 要解码的字符串
     * @return array 解码后的数据包数组
     * @throws Exception 当遇到非法附件时抛出异常
     */
    public function decodeString($str): array
    {
        $p = [];
        $i = 0;

        // 查找数据包类型
        $p['type'] = $str[0];
        if (! isset(Parser::$types[$p['type']])) {
            return self::error();
        }

        // 如果是二进制类型，查找附件数量
        if (Parser::BINARY_EVENT == $p['type'] || Parser::BINARY_ACK == $p['type']) {
            $buf = '';
            while ($str[++$i] != '-') {
                $buf .= $str[$i];
                if ($i == strlen($str)) {
                    break;
                }
            }
            if ($buf != intval($buf) || $str[$i] != '-') {
                throw new Exception('Illegal attachments');
            }
            $p['attachments'] = intval($buf);
        }

        // 查找命名空间（如果有）
        if (isset($str[$i + 1]) && '/' === $str[$i + 1]) {
            $p['nsp'] = '';
            while (++$i) {
                if ($i === strlen($str)) {
                    break;
                }
                $c = $str[$i];
                if (',' === $c) {
                    break;
                }
                $p['nsp'] .= $c;
            }
        } else {
            $p['nsp'] = '/';
        }

        // 查找数据包ID
        if (isset($str[$i + 1])) {
            $next = $str[$i + 1];
            if ('' !== $next && strval((int)$next) === strval($next)) {
                $p['id'] = '';
                while (++$i) {
                    $c = $str[$i];
                    if (null == $c || strval((int)$c) != strval($c)) {
                        --$i;
                        break;
                    }
                    $p['id'] .= $str[$i];
                    if ($i == strlen($str)) {
                        break;
                    }
                }
                $p['id'] = (int)$p['id'];
            }
        }

        // 查找JSON数据
        if (isset($str[++$i])) {
            $p['data'] = json_decode(substr($str, $i), true);
        }

        return $p;
    }

    /**
     * 生成错误数据包
     * 
     * @return array 错误数据包
     */
    public static function error(): array
    {
        return [
            'type' => Parser::ERROR,
            'data' => 'parser error'
        ];
    }
}
