<?php
/***
 * TOTP 时间型一次性密码工具
 *
 * 业务说明：RFC 6238（SHA1/30s/6 位）、otpauth URI、恢复码生成与校验；无数据库依赖
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 16:31:52 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);

namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Google Authenticator 兼容的 TOTP 薄封装
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 生成 Base32 密钥（无 padding）
     *
     * @param int $bytes 原始随机字节数，默认 20（160 bit）
     * @return string
     * @static
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            $bytes = 10;
        }
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * 构造 otpauth://totp 绑定 URI
     *
     * @param string $secret Base32 密钥
     * @param string $label 账号标签（通常为用户名）
     * @param string $issuer 发行方名称
     * @return string
     * @static
     */
    public static function getProvisioningUri(string $secret, string $label, string $issuer): string
    {
        $secret = self::normalizeSecret($secret);
        $issuer = trim($issuer) !== '' ? trim($issuer) : 'CANDY';
        $label = trim($label) !== '' ? trim($label) : 'user';
        $path = rawurlencode($issuer) . ':' . rawurlencode($label);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $path . '?' . $query;
    }

    /**
     * 计算指定时刻的 6 位验证码
     *
     * @param string $secret Base32 密钥
     * @param int|null $time Unix 时间戳，null 为当前
     * @return string
     * @static
     */
    public static function getCode(string $secret, ?int $time = null): string
    {
        $time ??= time();
        $counter = intdiv($time, self::PERIOD);
        return self::hotp(self::base32Decode(self::normalizeSecret($secret)), $counter);
    }

    /**
     * 校验用户输入的 TOTP
     *
     * 允许前后各 $window 个 30 秒窗口，比较使用 hash_equals
     *
     * @param string $secret Base32 密钥
     * @param string $code 用户输入（允许含空格）
     * @param int $window 时间窗，默认 1
     * @return bool
     * @static
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($code === '' || !ctype_digit($code) || strlen($code) !== self::DIGITS) {
            return false;
        }
        if ($window < 0) {
            $window = 0;
        }
        $secretBin = self::base32Decode(self::normalizeSecret($secret));
        if ($secretBin === '') {
            return false;
        }
        $now = time();
        $counter = intdiv($now, self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::hotp($secretBin, $counter + $i);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 生成一次性恢复码列表（明文，仅展示一次）
     *
     * 格式：XXXX-XXXX（Base32 字符，易读）
     *
     * @param int $count 条数，默认 10
     * @return list<string>
     * @static
     */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        if ($count < 1) {
            $count = 1;
        }
        if ($count > 50) {
            $count = 50;
        }
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = self::base32Encode(random_bytes(5));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        return $codes;
    }

    /**
     * 恢复码规范化后哈希（不可逆入库）
     *
     * @param string $code 明文恢复码
     * @return string
     * @static
     */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', self::normalizeRecoveryCode($code));
    }

    /**
     * 校验恢复码与已存哈希
     *
     * @param string $code 用户输入
     * @param string $hash 库中哈希
     * @return bool
     * @static
     */
    public static function verifyRecoveryCode(string $code, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return hash_equals($hash, self::hashRecoveryCode($code));
    }

    /**
     * HOTP（RFC 4226）计算
     *
     * @param string $key 二进制密钥
     * @param int $counter 计数器
     * @return string 左侧补零的 6 位数字
     * @access private
     * @static
     */
    private static function hotp(string $key, int $counter): string
    {
        if ($key === '') {
            return str_repeat('0', self::DIGITS);
        }
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );
        $otp = $truncated % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 去掉空格与 padding，转大写
     *
     * @param string $secret
     * @return string
     * @access private
     * @static
     */
    private static function normalizeSecret(string $secret): string
    {
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        return rtrim($secret, '=');
    }

    /**
     * 恢复码规范化：去空白、连字符，转大写
     *
     * @param string $code
     * @return string
     * @access private
     * @static
     */
    private static function normalizeRecoveryCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
        return $code;
    }

    /**
     * Base32 编码（RFC 4648，无 padding）
     *
     * @param string $data
     * @return string
     * @access private
     * @static
     */
    private static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $binary = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= self::BASE32[bindec($chunk)];
        }
        return $output;
    }

    /**
     * Base32 解码
     *
     * @param string $secret
     * @return string 二进制；非法字符返回空串
     * @access private
     * @static
     */
    private static function base32Decode(string $secret): string
    {
        $secret = self::normalizeSecret($secret);
        if ($secret === '') {
            return '';
        }
        $map = array_flip(str_split(self::BASE32));
        $binary = '';
        $len = strlen($secret);
        for ($i = 0; $i < $len; $i++) {
            $ch = $secret[$i];
            if (!isset($map[$ch])) {
                return '';
            }
            $binary .= str_pad(decbin($map[$ch]), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }
        return $output;
    }
}

