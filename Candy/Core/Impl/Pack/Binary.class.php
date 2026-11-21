<?php
/***
 * 二进制序列化与落盘工具
 *
 * 业务说明：自主二进制打包/解包，区分索引与关联数组，支持压缩、CRC 校验、原子落盘
 * 线格式：PROTOCOL_VERSION=3；有符号 32 位整数；unpack 在整包上按 offset/end 流式解析
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-10-24 11:06:07 $
 *
 * @package Candy\Core\Cache\Pack
 */

namespace Candy\Core\Impl\Pack;

class BinaryException extends \Exception {}
class PackException extends BinaryException {}
class UnsupportedValueException extends PackException {}
class UnpackException extends BinaryException {}
class MagicMismatchException extends UnpackException {}
class VersionMismatchException extends UnpackException {}
class CrcCheckFailedException extends UnpackException {}
class LengthMismatchException extends UnpackException {}
class CircularReferenceException extends PackException {}
class RecursionDepthExceededException extends PackException {}

final class Binary
{
    // 核心协议常量
    private const MAGIC_NUMBER = 0x55AA55AA;
    /** 协议版本：v3 起 32 位整数按有符号解，且无历史包兼容义务 */
    private const PROTOCOL_VERSION = 3;
    private const MAX_RECURSION_DEPTH = 64;
    private const COMPRESS_MIN_SIZE = 512;

    // 数据类型标识符
    private const TYPE_NULL        = 'n';
    private const TYPE_BOOLEAN     = 'b';
    private const TYPE_INTEGER     = 'i';    // 32 位有符号（大端补码）
    private const TYPE_BIGINT      = 'I';    // 64 位有符号（大端）
    private const TYPE_DOUBLE      = 'f';
    private const TYPE_STRING      = 's';
    private const TYPE_ARRAY_ASSOC = 'a';
    private const TYPE_ARRAY_INDEX = 'A';
    private const TYPE_DATETIME    = 'd';
    private const TYPE_BINARY      = 'B';
    private const TYPE_COMPRESSED  = 'z';

    // 压缩算法标识符
    private const COMPRESS_LZ4     = 1;
    private const COMPRESS_SNAPPY  = 2;
    private const COMPRESS_BROTLI  = 3;
    private const COMPRESS_ZLIB    = 4;

    // 转储文件头常量
    private const DUMP_HEADER_SIZE = 32;
    private const DUMP_VERSION     = 2;

    /**
     * 各压缩扩展可用性缓存（算法 ID => bool）
     *
     * @var array<int, bool>|null
     */
    private static ?array $compressAvailable = null;

    /**
     * 将数据打包成二进制字符串
     *
     * 包布局：totalLength(4) + MAGIC(4) + version(1) + body + CRC32(4)
     * 字符串入参仅接受判定为二进制的内容；文本应包在数组中再 pack
     *
     * @param string|array $data 要打包的数据(二进制字符串或数组)
     * @return string 二进制数据包
     * @throws PackException 数据包含协议不支持的类型或无法完成打包
     */
    public static function pack(string|array $data): string
    {
        try {
            $referenceStack = [];

            if (is_string($data)) {
                if (self::isBinary($data)) {
                    $body = self::TYPE_BINARY . $data;
                } else {
                    throw new PackException('字符串数据不是二进制内容，请将文本放入数组字段后再打包');
                }
            } else {
                $body = self::packData($data, 0, $referenceStack);
            }

            $packet = pack('N', self::MAGIC_NUMBER)
                . chr(self::PROTOCOL_VERSION)
                . $body;

            $totalLength = strlen($packet) + 4 + 4;
            $packet = pack('N', $totalLength) . $packet;
            $packet .= pack('N', crc32($packet));

            return $packet;
        } catch (PackException $e) {
            if (!$e instanceof UnsupportedValueException) {
                error_log("打包错误: " . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * 判断数据是否可被当前二进制协议编码。
     *
     * 该方法只做协议能力检查，不写日志、不返回空包；缓存等可选存储层可据此跳过
     * Closure、资源句柄等不可序列化值，避免每次请求重复尝试写入失败数据。
     *
     * @param mixed $data 待检查的数据
     * @return bool 可编码返回 true，否则返回 false
     */
    public static function canPack(mixed $data): bool
    {
        $referenceStack = [];

        try {
            self::packData($data, 0, $referenceStack);
            return true;
        } catch (PackException) {
            return false;
        }
    }

    /**
     * 将二进制字符串解包为原始数据
     *
     * 校验长度/魔数/版本/CRC 后，在整包上按 offset～end 流式解析 body，避免为每个数组元素 substr
     *
     * @param string $binary 二进制数据包
     * @return array|string 解包后的数组或二进制字符串,验证失败时返回空数组
     */
    public static function unpack(string $binary): array|string
    {
        try {
            return self::decodePacket($binary);
        } catch (UnpackException $e) {
            error_log("解包错误: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 静默尝试解包，供缓存等可自愈边界识别坏包并回退为未命中。
     *
     * 与 unpack() 的兼容行为不同：非法包返回 null，不写“解包错误”日志，调用方负责
     * 删除或隔离坏数据。合法的空数组仍返回空数组，可与坏包明确区分。
     *
     * @param string $binary 二进制数据包
     * @return array|string|null 解包结果；非法或截断数据返回 null
     */
    public static function tryUnpack(string $binary): array|string|null
    {
        try {
            return self::decodePacket($binary);
        } catch (UnpackException) {
            return null;
        }
    }

    /**
     * 执行完整的数据包头、版本、校验和及正文校验。
     *
     * @param string $binary 二进制数据包
     * @return array|string 解包后的数组或二进制字符串
     * @throws UnpackException 数据包不完整、版本不兼容或校验失败
     */
    private static function decodePacket(string $binary): array|string
    {
        $totalLength = strlen($binary);
        if ($totalLength < 13) {
            throw new LengthMismatchException('数据包长度不足');
        }

        $offset = 0;
        $expectTotalLength = unpack('N', $binary, $offset)[1];
        $offset += 4;
        if ($expectTotalLength !== $totalLength) throw new LengthMismatchException('总长度不匹配');

        $magic = unpack('N', $binary, $offset)[1];
        $offset += 4;
        if ($magic !== self::MAGIC_NUMBER) throw new MagicMismatchException('魔数不匹配，非法数据包');

        $version = ord($binary[$offset]);
        $offset += 1;
        if ($version !== self::PROTOCOL_VERSION) throw new VersionMismatchException('协议版本不兼容');

        $expectCrc = unpack('N', $binary, $totalLength - 4)[1];
        $actualCrc = crc32(substr($binary, 0, $totalLength - 4));
        if ($actualCrc !== $expectCrc) throw new CrcCheckFailedException('CRC校验失败，数据包已损坏');

        $bodyEnd = $totalLength - 4;
        return self::unpackData($binary, $offset, $bodyEnd, 0);
    }

    /**
     * 递归打包数据
     *
     * @param mixed $data 要打包的数据
     * @param int $depth 当前递归深度
     * @param array $referenceStack 用于循环引用检测的引用栈
     * @return string 打包后的二进制字符串
     * @throws PackException 如果数据类型不支持或递归深度超出限制
     * @access private
     * @static
     */
    private static function packData(mixed $data, int $depth, array &$referenceStack): string
    {
        $depth++;
        if ($depth > self::MAX_RECURSION_DEPTH) {
            throw new RecursionDepthExceededException('超过最大递归深度');
        }

        return match (gettype($data)) {
            'NULL' => self::TYPE_NULL,
            'boolean' => self::TYPE_BOOLEAN . chr($data ? 1 : 0),
            // 超出 32 位有符号范围走 BIGINT；32 位用大端补码（pack N 写入，unpack 按有符号还原）
            'integer' => (PHP_INT_SIZE === 8 && ($data > 0x7FFFFFFF || $data < -0x80000000))
                ? self::TYPE_BIGINT . pack('J', $data)
                : self::TYPE_INTEGER . pack('N', $data),
            'double' => self::TYPE_DOUBLE . self::packDouble($data),
            'string' => self::TYPE_STRING . self::packDynamicLength(strlen($data)) . $data,
            'array' => self::packArray($data, $depth, $referenceStack),
            'object' => $data instanceof \DateTimeInterface
                ? self::TYPE_DATETIME . pack('N', $data->getTimestamp())
                : throw new UnsupportedValueException('不支持的对象类型: ' . get_class($data)),
            default => throw new UnsupportedValueException('未知或不支持的数据类型：' . gettype($data)),
        };
    }

    /**
     * 打包数组,区分索引数组和关联数组
     *
     * 元数据与正文分块缓冲后 implode；达到 COMPRESS_MIN_SIZE 且压缩更小时外包 TYPE_COMPRESSED
     *
     * @param array $data 要打包的数组
     * @param int $depth 当前递归深度
     * @param array $referenceStack 用于循环引用检测的引用栈
     * @return string 打包后的二进制字符串
     * @throws CircularReferenceException 如果检测到循环引用
     * @throws PackException 如果数组元素无法打包
     * @access private
     * @static
     */
    private static function packArray(array $data, int $depth, array &$referenceStack): string
    {
        foreach ($referenceStack as $ref) {
            if ($ref === $data) throw new CircularReferenceException('检测到数组循环引用');
        }
        $referenceStack[] = &$data;

        $isIndexed = self::isPureIndexedArray($data);
        $typeFlag = $isIndexed ? self::TYPE_ARRAY_INDEX : self::TYPE_ARRAY_ASSOC;

        $metaChunks = [];
        $bodyChunks = [];
        $packedItemCount = 0;

        foreach ($data as $key => $value) {
            $valueBinary = self::packData($value, $depth, $referenceStack);
            if ($valueBinary === '') {
                throw new PackException("无法打包数组元素（键: $key, 值类型: " . gettype($value) . " ）");
            }

            if (!$isIndexed) {
                $keyStr = (string)$key;
                $metaChunks[] = self::packDynamicLength(strlen($keyStr)) . $keyStr;
            }
            $metaChunks[] = self::packDynamicLength(strlen($valueBinary));
            $bodyChunks[] = $valueBinary;
            $packedItemCount++;
        }

        $meta = $metaChunks === [] ? '' : implode('', $metaChunks);
        $body = $bodyChunks === [] ? '' : implode('', $bodyChunks);

        $result = $typeFlag
            . pack('V', $packedItemCount)
            . self::packDynamicLength(strlen($meta))
            . $meta
            . $body;

        if (strlen($result) >= self::COMPRESS_MIN_SIZE) {
            $compressed = self::compress($result);
            if ($compressed !== $result && strlen($compressed) < strlen($result)) {
                $result = self::TYPE_COMPRESSED . $compressed;
            }
        }

        array_pop($referenceStack);
        return $result;
    }

    /**
     * 在缓冲 [$offset, $end) 上解析恰好一个数据项
     *
     * 成功后 $offset 推进到该项末尾；调用方可用 $end 限定数组元素边界，无需先 substr
     *
     * @param string $binary 完整二进制缓冲
     * @param int $offset 当前读指针（引用，解析后前进）
     * @param int $end 本项允许读取的上界（不含）
     * @param int $depth 当前递归深度
     * @return mixed 解包后的数据
     * @throws RecursionDepthExceededException
     * @throws LengthMismatchException
     * @throws UnpackException
     * @access private
     * @static
     */
    private static function unpackData(string $binary, int &$offset, int $end, int $depth): mixed
    {
        $depth++;
        if ($depth > self::MAX_RECURSION_DEPTH) {
            throw new RecursionDepthExceededException('超过最大递归深度');
        }

        if ($offset >= $end) throw new LengthMismatchException('数据截断，无法读取类型标识');
        $type = $binary[$offset];
        $offset++;

        $value = null;

        switch ($type) {
            case self::TYPE_NULL:
                $value = null;
                break;
            case self::TYPE_BOOLEAN:
                if ($offset + 1 > $end) throw new LengthMismatchException('数据截断，无法读取布尔值');
                $value = (ord($binary[$offset]) === 1);
                $offset += 1;
                break;
            case self::TYPE_INTEGER:
                if ($offset + 4 > $end) throw new LengthMismatchException('数据截断，无法读取整数');
                // unpack N 为无符号；> 0x7FFFFFFF 时还原为有符号 32 位补码
                $u = unpack('N', $binary, $offset)[1];
                $value = $u <= 0x7FFFFFFF ? $u : $u - 0x100000000;
                $offset += 4;
                break;
            case self::TYPE_BIGINT:
                if ($offset + 8 > $end) throw new LengthMismatchException('数据截断，无法读取大整数');
                $value = unpack('J', $binary, $offset)[1];
                $offset += 8;
                break;
            case self::TYPE_DOUBLE:
                if ($offset + 8 > $end) throw new LengthMismatchException('数据截断，无法读取浮点数');
                $value = self::unpackDouble($binary, $offset);
                $offset += 8;
                break;
            case self::TYPE_STRING:
                $value = self::unpackString($binary, $offset, $end);
                break;
            case self::TYPE_ARRAY_ASSOC:
            case self::TYPE_ARRAY_INDEX:
                $value = self::unpackArray($type, $binary, $offset, $end, $depth);
                break;
            case self::TYPE_DATETIME:
                if ($offset + 4 > $end) throw new LengthMismatchException('数据截断，无法读取日期时间');
                $timestamp = unpack('N', $binary, $offset)[1];
                $offset += 4;
                $value = (new \DateTime())->setTimestamp($timestamp);
                break;
            case self::TYPE_COMPRESSED:
                // 压缩载荷占用本项剩余全部字节
                $payload = substr($binary, $offset, $end - $offset);
                $offset = $end;
                $decompressed = self::decompress($payload);
                $innerOff = 0;
                $innerEnd = strlen($decompressed);
                $value = self::unpackData($decompressed, $innerOff, $innerEnd, $depth - 1);
                if ($innerOff !== $innerEnd) {
                    throw new LengthMismatchException('解压后剩余未解析字节：' . ($innerEnd - $innerOff));
                }
                break;
            case self::TYPE_BINARY:
                $value = substr($binary, $offset, $end - $offset);
                $offset = $end;
                break;
            default:
                throw new UnpackException('未知数据类型标识: ' . (is_string($type) ? ord($type) : $type) . ' at offset ' . ($offset - 1));
        }

        if ($offset !== $end) {
            throw new LengthMismatchException('数据包剩余未解析字节：' . ($end - $offset));
        }

        return $value;
    }

    /**
     * 解包数组：元数据在原缓冲内按区间解析，正文各元素按长度边界流式 unpackData
     *
     * @param string $type TYPE_ARRAY_ASSOC 或 TYPE_ARRAY_INDEX
     * @param string $binary 完整缓冲
     * @param int $offset 读指针（引用）
     * @param int $end 本数组项上界
     * @param int $depth 递归深度
     * @return array
     * @throws LengthMismatchException
     * @throws UnpackException
     * @access private
     * @static
     */
    private static function unpackArray(string $type, string $binary, int &$offset, int $end, int $depth): array
    {
        $isIndexed = ($type === self::TYPE_ARRAY_INDEX);

        if ($offset + 4 > $end) throw new LengthMismatchException('数据截断，无法读取数组元素数量');
        $itemCount = unpack('V', $binary, $offset)[1];
        $offset += 4;

        $metaLength = self::unpackDynamicLength($binary, $offset, $end);
        if ($offset + $metaLength > $end) throw new LengthMismatchException('数据截断，无法读取数组元数据');

        // 元数据区间 [metaStart, metaEnd)，不单独 substr 拷贝整块
        $metaStart = $offset;
        $metaEnd = $offset + $metaLength;
        $offset = $metaEnd;

        $collectedKeys = [];
        $collectedValueLengths = [];
        $metaOff = $metaStart;

        for ($i = 0; $i < $itemCount; $i++) {
            if (!$isIndexed) {
                $keyLength = self::unpackDynamicLength($binary, $metaOff, $metaEnd);
                if ($metaOff + $keyLength > $metaEnd) {
                    throw new LengthMismatchException('元数据截断，无法读取键名');
                }
                $key = substr($binary, $metaOff, $keyLength);
                $metaOff += $keyLength;
            } else {
                $key = $i;
            }

            $valueLength = self::unpackDynamicLength($binary, $metaOff, $metaEnd);
            $collectedKeys[] = $key;
            $collectedValueLengths[] = $valueLength;
        }

        if ($metaOff !== $metaEnd) {
            throw new LengthMismatchException('元数据解析不完整，剩余字节: ' . ($metaEnd - $metaOff));
        }

        $result = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $key = $collectedKeys[$i];
            $valueLength = $collectedValueLengths[$i];
            $valueEnd = $offset + $valueLength;
            if ($valueEnd > $end) {
                throw new LengthMismatchException('数据截断，无法读取值数据（索引：' . $i . '）');
            }
            // 在 [offset, valueEnd) 流式解析，不再为每个 value 造临时串
            $result[$key] = self::unpackData($binary, $offset, $valueEnd, $depth);
        }

        return $result;
    }

    /**
     * 判断数组是否为纯连续索引数组(0, 1, 2...)
     *
     * @param array $data 要检查的数组
     * @return bool
     * @access private
     * @static
     */
    private static function isPureIndexedArray(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $expected = 0;
        foreach ($data as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    /**
     * 将整数长度打包成动态二进制格式(1、3或5字节)
     *
     * @param int $length 要打包的长度
     * @return string
     * @access private
     * @static
     */
    private static function packDynamicLength(int $length): string
    {
        if ($length < 0) $length = 0;
        return match (true) {
            $length < 253 => chr($length),
            $length < 0x10000 => "\xFD" . pack('n', $length),
            default => "\xFE" . pack('N', $length),
        };
    }

    /**
     * 解包动态二进制长度
     *
     * @param string $binary 缓冲
     * @param int $offset 读指针（引用）
     * @param int|null $end 上界，null 表示整串长度
     * @return int
     * @throws LengthMismatchException
     * @access private
     * @static
     */
    private static function unpackDynamicLength(string $binary, int &$offset, ?int $end = null): int
    {
        $maxOffset = $end ?? strlen($binary);
        if ($offset >= $maxOffset) throw new LengthMismatchException('数据截断，无法读取长度');

        $flag = ord($binary[$offset]);
        $offset++;

        if ($flag < 253) {
            return $flag;
        }

        if ($flag === 253) {
            if ($offset + 2 > $maxOffset) throw new LengthMismatchException('数据截断，无法读取2字节长度');
            $length = unpack('n', $binary, $offset)[1];
            $offset += 2;
            return $length;
        }

        if ($flag === 254) {
            if ($offset + 4 > $maxOffset) throw new LengthMismatchException('数据截断，无法读取4字节长度');
            $length = unpack('N', $binary, $offset)[1];
            $offset += 4;
            return $length;
        }

        throw new LengthMismatchException('数据截断，长度解析失败 (未知标志: ' . $flag . ')');
    }

    /**
     * 解包字符串（长度前缀 + 正文）
     *
     * @param string $binary 缓冲
     * @param int $offset 读指针（引用）
     * @param int $end 上界
     * @return string
     * @throws LengthMismatchException
     * @access private
     * @static
     */
    private static function unpackString(string $binary, int &$offset, int $end): string
    {
        $length = self::unpackDynamicLength($binary, $offset, $end);
        if ($offset + $length > $end) {
            throw new LengthMismatchException('数据截断，无法读取字符串数据');
        }
        $str = substr($binary, $offset, $length);
        $offset += $length;
        return $str;
    }

    /**
     * 打包大端序 IEEE754 double（8 字节）
     *
     * @param float $value
     * @return string
     * @access private
     * @static
     */
    private static function packDouble(float $value): string
    {
        return pack('E', is_finite($value) ? $value : 0.0);
    }

    /**
     * 解包大端序 IEEE754 double
     *
     * @param string $binary 缓冲
     * @param int $offset 起始偏移
     * @return float
     * @access private
     * @static
     */
    private static function unpackDouble(string $binary, int $offset = 0): float
    {
        if (strlen($binary) < $offset + 8) {
            return 0.0;
        }
        return unpack('E', $binary, $offset)[1];
    }

    /**
     * 探测并缓存各压缩扩展是否可用
     *
     * @return array<int, bool>
     * @access private
     * @static
     */
    private static function compressAvailable(): array
    {
        if (self::$compressAvailable !== null) {
            return self::$compressAvailable;
        }

        return self::$compressAvailable = [
            self::COMPRESS_LZ4 => extension_loaded('lz4'),
            self::COMPRESS_SNAPPY => extension_loaded('snappy'),
            self::COMPRESS_BROTLI => extension_loaded('brotli'),
            self::COMPRESS_ZLIB => extension_loaded('zlib'),
        ];
    }

    /**
     * 按 lz4 → snappy → brotli → zlib 尝试压缩
     *
     * @param string $data
     * @return string 成功缩小则以算法 ID 为前缀，否则原样返回
     * @access private
     * @static
     */
    private static function compress(string $data): string
    {
        $avail = self::compressAvailable();

        if ($avail[self::COMPRESS_LZ4]) {
            $compressed = @lz4_compress($data);
            if ($compressed !== false && strlen($compressed) < strlen($data)) {
                return chr(self::COMPRESS_LZ4) . $compressed;
            }
        }
        if ($avail[self::COMPRESS_SNAPPY]) {
            $compressed = @snappy_compress($data);
            if ($compressed !== false && strlen($compressed) < strlen($data)) {
                return chr(self::COMPRESS_SNAPPY) . $compressed;
            }
        }
        if ($avail[self::COMPRESS_BROTLI]) {
            $compressed = @brotli_compress($data, 6);
            if ($compressed !== false && strlen($compressed) < strlen($data)) {
                return chr(self::COMPRESS_BROTLI) . $compressed;
            }
        }
        if ($avail[self::COMPRESS_ZLIB]) {
            $compressed = @gzcompress($data, 6);
            if ($compressed !== false && strlen($compressed) < strlen($data)) {
                return chr(self::COMPRESS_ZLIB) . $compressed;
            }
        }

        return $data;
    }

    /**
     * 按算法 ID 解压缩
     *
     * @param string $data 首字节为算法 ID
     * @return string
     * @throws UnpackException
     * @access private
     * @static
     */
    private static function decompress(string $data): string
    {
        if ($data === '') return '';

        $algorithm = ord($data[0]);
        $payload = substr($data, 1);

        try {
            switch ($algorithm) {
                case self::COMPRESS_LZ4:
                    if (!extension_loaded('lz4')) throw new UnpackException('LZ4扩展未加载');
                    return @lz4_uncompress($payload) ?: throw new UnpackException('LZ4解压失败');
                case self::COMPRESS_SNAPPY:
                    if (!extension_loaded('snappy')) throw new UnpackException('Snappy扩展未加载');
                    return @snappy_uncompress($payload) ?: throw new UnpackException('Snappy解压失败');
                case self::COMPRESS_BROTLI:
                    if (!extension_loaded('brotli')) throw new UnpackException('Brotli扩展未加载');
                    return @brotli_uncompress($payload) ?: throw new UnpackException('Brotli解压失败');
                case self::COMPRESS_ZLIB:
                    if (!extension_loaded('zlib')) throw new UnpackException('Zlib扩展未加载');
                    return @gzuncompress($payload) ?: throw new UnpackException('Zlib解压失败');
                default:
                    throw new UnpackException('未知压缩算法标识: ' . $algorithm);
            }
        } catch (\Throwable $e) {
            error_log("解压缩错误: " . $e->getMessage());
            throw new UnpackException('解压数据失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 启发式判断是否为二进制串
     *
     * @param string $str
     * @return bool
     * @access private
     * @static
     */
    private static function isBinary(string $str): bool
    {
        $len = strlen($str);
        if ($len === 0) {
            return true;
        }

        $probeLen = $len > 4096 ? 4096 : $len;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', substr($str, 0, $probeLen))) {
            return true;
        }

        if ($probeLen < $len && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', $str)) {
            return true;
        }

        // mbstring 不是必需扩展：CLI 环境常常没加载它（NoSQL/Worker 首次启动就炸）。
        // 有 mbstring 走原来的判定；没有就用 PCRE 的 /u 修饰符判 UTF-8 合法性，语义等价。
        if (function_exists('mb_check_encoding')) {
            return !mb_check_encoding($str, 'UTF-8');
        }

        return preg_match('//u', $str) !== 1;
    }

    /**
     * 原子写入落盘文件
     *
     * @param string $filePath 目标路径
     * @param int $dumpMagic 落盘魔数
     * @param int $prefixCrc 前缀 CRC
     * @param array $data 数据
     * @return bool
     */
    public static function atomicDump(string $filePath, int $dumpMagic, int $prefixCrc, array $data): bool
    {
        try {
            $body = self::pack($data);
            if ($body === '') return false;

            $bodyLength = strlen($body);
            $bodyCrc = crc32($body);
            $header = pack('NCNNNN', $dumpMagic, self::DUMP_VERSION, time(), $prefixCrc, $bodyLength, $bodyCrc);
            $header = str_pad($header, self::DUMP_HEADER_SIZE, "\0");

            $fullContent = $header . $body;
            $tempFile = $filePath . '.tmp.' . uniqid(more_entropy: true);

            $writeBytes = file_put_contents($tempFile, $fullContent, LOCK_EX);
            if ($writeBytes === false || $writeBytes !== strlen($fullContent)) {
                @unlink($tempFile);
                return false;
            }

            $success = rename($tempFile, $filePath);
            if (!$success) @unlink($tempFile);

            return $success;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 加载并解析落盘文件
     *
     * @param string $filePath
     * @param int $dumpMagic
     * @param int $prefixCrc
     * @return array 失败返回空数组
     */
    public static function loadDump(string $filePath, int $dumpMagic, int $prefixCrc): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) return [];

        $content = file_get_contents($filePath);
        if ($content === false || strlen($content) < self::DUMP_HEADER_SIZE) return [];

        $header = unpack('Nmagic/Cver/NdumpTime/NprefixCrc/NbodyLen/NbodyCrc', substr($content, 0, self::DUMP_HEADER_SIZE));
        if (!$header) return [];

        if ($header['ver'] !== self::DUMP_VERSION) return [];
        if ($header['magic'] !== $dumpMagic) return [];
        if ($header['prefixCrc'] !== $prefixCrc) return [];

        $body = substr($content, self::DUMP_HEADER_SIZE);
        if (strlen($body) !== $header['bodyLen']) return [];
        if (crc32($body) !== $header['bodyCrc']) return [];

        $unpacked = self::unpack($body);
        return is_array($unpacked) ? $unpacked : [];
    }

    /**
     * 获取落盘文件写入时间戳
     *
     * @param string $filePath
     * @param int $dumpMagic
     * @param int $prefixCrc
     * @return int 失败返回 0
     */
    public static function getDumpTime(string $filePath, int $dumpMagic, int $prefixCrc): int
    {
        if (!is_file($filePath) || !is_readable($filePath)) return 0;

        $header = substr(file_get_contents($filePath, false, null, 0, self::DUMP_HEADER_SIZE), 0, self::DUMP_HEADER_SIZE);
        $parsed = unpack('Nmagic/Cver/NdumpTime/NprefixCrc', $header);

        if (!$parsed || $parsed['magic'] !== $dumpMagic || $parsed['prefixCrc'] !== $prefixCrc) return 0;
        return $parsed['dumpTime'];
    }
}
