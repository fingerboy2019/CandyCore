<?php
declare(strict_types=1);

/***
 * 缓存落盘的预写日志（WAL）
 *
 * 业务说明：
 *   给 Cache 的 dump 机制补上「两次 dump 之间」的持久化。
 *   dump 是低频全量（默认 5 秒，为降 IO）；WAL 是高频增量（每条写都记）。
 *   崩溃后用 loadDump + replay 把 dump 之后的操作重放回来，丢失窗口从
 *   dump 间隔（5 秒）压到 WAL 刷盘间隔（默认 100ms）。
 *
 * 记录格式（小端无关，全部大端）：
 *   +--------+--------+------+--------+-----+----------+--------+-------+
 *   | seq:8  | ts:4   | op:1 | kLen:2 | key | valLen:4 | value  | crc:4 |
 *   +--------+--------+------+--------+-----+----------+--------+-------+
 *   value 为 PackBinary::pack 结果；op: 1=set 2=delete
 *   crc 覆盖本条前面全部字节，用于识别尾部被截断的半条记录
 *
 * 为什么必须有 seq：
 *   dump 文件头记 dumpSeq，恢复时只重放 seq > dumpSeq 的记录。
 *   没有 seq 就无法和 dump 对齐，会重复应用。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-16 22:30:00 $
 *
 * @package Candy\Core\Impl\Cache
 */

namespace Candy\Core\Impl\Cache;

use Candy\Core\Impl\Pack\Binary as PackBinary;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Wal
{
    /** 文件魔数，用于识别本类产生的文件 */
    public const MAGIC = 0x57414C31;   // 'WAL1'

    /** set 操作 */
    public const OP_SET = 1;

    /** delete 操作 */
    public const OP_DEL = 2;

    /** 仅改过期时间（值不变）；value 存 ttl 秒数 */
    public const OP_EXPIRE = 3;

    /** 单条记录最大长度保护（防损坏文件读出天文数字） */
    private const MAX_RECORD = 8388608;   // 8MB

    /** @var string WAL 文件路径 */
    private string $file;

    /** @var int 刷盘间隔（毫秒），攒够这么久就刷 */
    private int $flushMs;

    /** @var int 攒够这么多条就刷 */
    private int $flushRows;

    /** @var int 已分配的最大 seq（含未刷盘的） */
    private int $lastSeq = 0;

    /** @var int 距上次 fsync 已写入的条数（数据已 fwrite，只是没 fsync） */
    private int $pending = 0;

    /** @var float 上次刷盘时刻 microtime */
    private float $lastFlush = 0.0;

    /** @var bool 文件不可用时降级（不阻断业务） */
    private bool $disabled = false;

    /** @var resource|null 打开的写句柄 */
    private $fp = null;

    /** @var string 最近一次错误 */
    private string $error = '';

    /**
     * 构造
     *
     * @param string               $file   WAL 文件绝对路径
     * @param array<string, mixed> $config flush_ms / flush_rows
     */
    public function __construct(string $file, array $config = [])
    {
        $this->file = $file;
        $this->flushMs = max(0, (int) ($config['flush_ms'] ?? 100));
        $this->flushRows = max(1, (int) ($config['flush_rows'] ?? 64));
        $this->lastFlush = microtime(true);

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $this->disabled = true;
            $this->error = 'WAL 目录不可写：' . $dir;
            return;
        }

        // 从已有文件恢复 lastSeq，避免重启后 seq 回退覆盖旧记录
        $this->lastSeq = $this->scanLastSeq();
    }

    /**
     * 追加一条记录（不立即刷盘，由 flush 决定时机）
     *
     * @param int    $op    OP_SET / OP_DEL
     * @param string $key   原始 key（未加前缀）
     * @param mixed  $value 值（delete 时忽略）
     * @return int 本条记录的 seq；失败返回 0
     */
    public function append(int $op, string $key, mixed $value = null): int
    {
        if ($this->disabled) {
            return 0;
        }
        $seq = ++$this->lastSeq;
        $one = $this->encode($seq, time(), $op, $key, $value);
        if ($one === '') {
            return $seq;
        }

        $fp = $this->open();
        if ($fp === null) {
            return $seq;
        }

        // ★ 直接 fwrite：数据立即进 OS 页缓存，进程被 kill -9 也不会丢。
        // 如果只在内存里攒 buffer，连写几条后停手就永远不落盘 —— 那等于 WAL 没生效。
        $written = @fwrite($fp, $one);
        if ($written !== strlen($one)) {
            $this->error = 'WAL 写入失败';
            return $seq;
        }
        $this->pending++;

        // fsync 才真正防断电，它才是贵的那个；攒够条数或超过 flush_ms 才做
        if ($this->pending >= $this->flushRows
            || (microtime(true) - $this->lastFlush) * 1000 >= $this->flushMs) {
            $this->sync();
        }
        return $seq;
    }

    /**
     * 把缓冲区刷到磁盘（fsync 保证真正落盘）
     *
     * @param bool $force 忽略时间窗口，强制刷
     * @return bool
     */
    public function flush(bool $force = true): bool
    {
        if ($this->disabled) {
            return false;
        }
        if (!$force && (microtime(true) - $this->lastFlush) * 1000 < $this->flushMs) {
            return true;
        }
        return $this->sync();
    }

    /**
     * 把已 fwrite 的数据 fsync 到磁盘（防断电；防进程被杀靠的是 fwrite，不是这个）
     *
     * @return bool
     */
    protected function sync(): bool
    {
        if (!is_resource($this->fp)) {
            $this->pending = 0;
            $this->lastFlush = microtime(true);
            return true;
        }
        @fflush($this->fp);
        if (function_exists('fsync')) {
            @fsync($this->fp);
        }
        $this->pending = 0;
        $this->lastFlush = microtime(true);
        return true;
    }

    /**
     * 重放 seq > $afterSeq 的记录
     *
     * 尾部记录不完整（进程被杀时只写了一半）时**停止读取并返回已解析部分**，
     * 不抛异常 —— 恢复流程不能因为最后一条坏记录整个失败。
     *
     * @param int $afterSeq 只返回 seq 大于它的记录
     * @return array<int, array{seq:int,ts:int,op:int,key:string,value:mixed}>
     */
    public function replay(int $afterSeq = 0): array
    {
        if (!is_file($this->file) || !is_readable($this->file)) {
            return [];
        }
        $fp = @fopen($this->file, 'rb');
        if ($fp === false) {
            return [];
        }

        $out = [];
        while (true) {
            $head = @fread($fp, 15);
            if ($head === false || strlen($head) < 15) {
                break;   // 尾部不完整，停
            }
            $h = @unpack('Jseq/Nts/Cop/nklen', $head);
            if (!is_array($h)) {
                break;
            }
            $klen = (int) $h['klen'];
            if ($klen <= 0 || $klen > self::MAX_RECORD) {
                break;
            }
            $key = @fread($fp, $klen);
            if ($key === false || strlen($key) < $klen) {
                break;
            }
            $lenRaw = @fread($fp, 4);
            if ($lenRaw === false || strlen($lenRaw) < 4) {
                break;
            }
            $vlen = (int) (unpack('N', $lenRaw)[1] ?? 0);
            if ($vlen < 0 || $vlen > self::MAX_RECORD) {
                break;
            }
            $value = $vlen > 0 ? @fread($fp, $vlen) : '';
            if ($value === false || strlen((string) $value) < $vlen) {
                break;
            }
            $crcRaw = @fread($fp, 4);
            if ($crcRaw === false || strlen($crcRaw) < 4) {
                break;
            }
            $wantCrc = (int) (unpack('N', $crcRaw)[1] ?? 0);
            $calcCrc = (int) sprintf('%u', crc32($head . $key . $lenRaw . $value));
            if ($wantCrc !== $calcCrc) {
                break;   // 记录损坏，停在它前面
            }
            $seq = (int) $h['seq'];
            if ($seq > $afterSeq) {
                $out[] = [
                    'seq'   => $seq,
                    'ts'    => (int) $h['ts'],
                    'op'    => (int) $h['op'],
                    'key'   => $key,
                    'value' => $vlen > 0 ? (PackBinary::tryUnpack((string) $value)['v'] ?? null) : null,
                ];
            }
        }
        @fclose($fp);
        return $out;
    }

    /**
     * dump 之后需要重写哪些 key（给增量落盘用）
     *
     * @param int $afterSeq 只统计 seq 大于它的记录
     * @return array<int, string>
     */
    public function changedKeys(int $afterSeq = 0): array
    {
        $keys = [];
        foreach ($this->replay($afterSeq) as $one) {
            $keys[$one['key']] = true;
        }
        return array_keys($keys);
    }

    /**
     * 截断：删除 seq <= $uptoSeq 的记录（dump 成功后调用）
     *
     * 顺序纪律：必须在 dump **确认落盘之后**才调，否则 dump 失败会连 WAL 一起丢。
     *
     * @param int $uptoSeq 保留 seq > 它的记录
     * @return bool
     */
    public function truncate(int $uptoSeq = 0): bool
    {
        if ($this->disabled) {
            return false;
        }
        $this->flush(true);

        $keep = $this->replay($uptoSeq);
        $tmp = $this->file . '.tmp.' . getmypid();
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            $this->error = 'WAL 截断临时文件创建失败';
            return false;
        }
        $bytes = '';
        foreach ($keep as $one) {
            $bytes .= $this->encode((int) $one['seq'], (int) $one['ts'], (int) $one['op'], (string) $one['key'], $one['value']);
        }
        $ok = ($bytes === '' || @fwrite($fp, $bytes) === strlen($bytes));
        @fflush($fp);
        if (function_exists('fsync')) {
            @fsync($fp);
        }
        @fclose($fp);
        if (!$ok) {
            @unlink($tmp);
            return false;
        }

        $this->close();
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        // 截断后 lastSeq 不能回退（新记录要能继续追加上去）
        $max = 0;
        foreach ($keep as $one) {
            $max = max($max, (int) $one['seq']);
        }
        $this->lastSeq = max($this->lastSeq, $max);
        return true;
    }

    /**
     * 当前已分配的最大 seq
     *
     * @return int
     */
    public function lastSeq(): int
    {
        return $this->lastSeq;
    }

    /**
     * 文件字节数
     *
     * @return int
     */
    public function size(): int
    {
        return is_file($this->file) ? (int) @filesize($this->file) : 0;
    }

    /**
     * 是否可用
     *
     * @return bool
     */
    public function available(): bool
    {
        return !$this->disabled;
    }

    /**
     * 最近一次错误
     *
     * @return string
     */
    public function error(): string
    {
        return $this->error;
    }

    /**
     * 清空 WAL（重置 seq）
     *
     * @param int $startSeq 重置后的起始 seq
     * @return bool
     */
    public function reset(int $startSeq = 0): bool
    {
        $this->close();
        $this->pending = 0;
        $this->lastSeq = max(0, $startSeq);
        $this->lastFlush = microtime(true);
        if (is_file($this->file)) {
            return @unlink($this->file);
        }
        return true;
    }

    /**
     * 关闭句柄（刷盘后释放）
     *
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->fp)) {
            @fflush($this->fp);
            if (function_exists('fsync')) {
                @fsync($this->fp);
            }
            @fclose($this->fp);
        }
        $this->fp = null;
    }

    /**
     * 析构时刷盘，避免进程正常退出丢最后一批
     */
    public function __destruct()
    {
        $this->flush(true);
        $this->close();
    }

    /**
     * 编码一条记录
     *
     * @param int    $seq
     * @param int    $ts
     * @param int    $op
     * @param string $key
     * @param mixed  $value
     * @return string
     */
    private function encode(int $seq, int $ts, int $op, string $key, mixed $value): string
    {
        if ($key === '' || strlen($key) > self::MAX_RECORD) {
            return '';
        }
        $klen = strlen($key);
        // PackBinary::pack 只接受数组（裸字符串会被判为非二进制内容直接抛错），
        // 用 ['v' => $value] 包一层，重放时取回 v 字段。
        $valBin = $op === self::OP_DEL ? '' : (string) PackBinary::pack(['v' => $value]);
        $vlen = strlen($valBin);
        if ($vlen > self::MAX_RECORD) {
            return '';
        }
        $head = pack('JNCn', $seq, $ts, $op, $klen);
        $mid = $key . pack('N', $vlen) . $valBin;
        $crc = (int) sprintf('%u', crc32($head . $mid));
        return $head . $mid . pack('N', $crc);
    }

    /**
     * 打开写句柄（追加模式）
     *
     * @return resource|null
     */
    private function open()
    {
        if (is_resource($this->fp)) {
            return $this->fp;
        }
        $fp = @fopen($this->file, 'ab');
        if ($fp === false) {
            $this->disabled = true;
            $this->error = 'WAL 文件打开失败：' . $this->file;
            return null;
        }
        $this->fp = $fp;
        return $fp;
    }

    /**
     * 扫描已有文件，取最大 seq
     *
     * @return int
     */
    private function scanLastSeq(): int
    {
        $rows = $this->replay(0);
        $max = 0;
        foreach ($rows as $one) {
            $max = max($max, (int) $one['seq']);
        }
        // replay 后文件指针由 fclose 释放，不需要额外处理
        return $max;
    }
}
