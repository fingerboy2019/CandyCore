<?php

namespace Workerman\Mqtt\Tools;

/**
 * Debug
 * MQTT调试工具类，用于调试MQTT消息
 */
class Debug
{
    /**
     * 要调试的编码数据
     * @var string
     */
    private $encode;

    /**
     * 构造函数
     *
     * @param string $encode 要调试的编码数据
     */
    public function __construct(string $encode = '')
    {
        $this->encode = $encode;
    }

    /**
     * 设置要调试的编码数据
     *
     * @param string $encode 要调试的编码数据
     * @return self 当前实例
     */
    public function setEncode(string $encode): self
    {
        $this->encode = $encode;

        return $this;
    }

    /**
     * 获取要调试的编码数据
     *
     * @return string 要调试的编码数据
     */
    public function getEncode(): string
    {
        return $this->encode;
    }

    /**
     * 获取十六进制转储
     *
     * @return string 十六进制转储字符串
     */
    public function hexDump(): string
    {
        return $this->toHexDump($this->getEncode());
    }

    /**
     * 获取带ASCII字符的十六进制转储
     *
     * @return string 带ASCII字符的十六进制转储字符串
     */
    public function hexDumpAscii(): string
    {
        return $this->toHexDump($this->getEncode(), true);
    }

    /**
     * 获取ASCII转储
     *
     * @return string ASCII转储字符串
     */
    public function ascii(): string
    {
        return $this->toAscii($this->getEncode());
    }

    /**
     * 获取可打印文本
     *
     * @return string 可打印文本
     */
    public function printableText(): string
    {
        return $this->getEncode();
    }

    /**
     * 获取十六进制流
     *
     * @return string 十六进制流字符串
     */
    public function hexStream(): string
    {
        return bin2hex($this->getEncode());
    }

    /**
     * 生成十六进制转储
     *
     * @param string $contents 要转储的内容
     * @param bool $hasAscii 是否包含ASCII字符
     * @return string 十六进制转储字符串
     */
    private function toHexDump(string $contents, bool $hasAscii = false): string
    {
        $address = $column = 0;
        $result = $hexDump = $asciiDump = '';

        $sprintf = '%08x    %-48s';

        if ($hasAscii) {
            $sprintf = '%08x    %-48s   %s';
        }

        foreach (str_split($contents) as $c) {
            $hexDump = $hexDump . sprintf('%02x ', ord($c));
            if ($hasAscii) {
                if (ord($c) > 31 && ord($c) < 128) {
                    $asciiDump .= $c;
                } else {
                    $asciiDump .= '.';
                }
            }
            $column++;
            if (($column % 16) == 0) {
                $line = sprintf($sprintf, $address, $hexDump, $asciiDump);
                $result .= $line . PHP_EOL;

                $asciiDump = '';
                $hexDump = '';
                $column = 0;
                $address += 16;
            }
        }

        if ($column > 0) {
            $line = sprintf($sprintf, $address, $hexDump, $asciiDump);
            $result .= $line;
        }

        return $result;
    }

    /**
     * 生成ASCII转储
     *
     * @param string $contents 要转储的内容
     * @return string ASCII转储字符串
     */
    private function toAscii(string $contents): string
    {
        $address = $column = 0;
        $result = $asciiDump = '';

        $sprintf = '%08x    %s';

        foreach (str_split($contents) as $c) {
            if (ord($c) > 31 && ord($c) < 128) {
                $asciiDump .= $c;
            } else {
                $asciiDump .= '.';
            }

            $column++;
            if (($column % 16) == 0) {
                $line = sprintf($sprintf, $address, $asciiDump);
                $result .= $line . PHP_EOL;

                $asciiDump = '';
                $column = 0;
                $address += 16;
            }
        }

        if ($column > 0) {
            $line = sprintf($sprintf, $address, $asciiDump);
            $result .= $line;
        }

        return $result;
    }
}
