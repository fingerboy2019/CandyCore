<?php
/**
 * PHP-IPv6 V1.0.
 * Copyright (c) 2010 Mr.Bin <bin_jly@163.com>
 */

namespace Dns;

/**
 * IPv6地址处理类
 * 
 * 提供IPv6地址的各种操作方法，包括地址转换、验证、压缩、扩展等
 * 
 * @package Dns
 */
class Ipv6 {
    /**
     * 获取或格式化IPv6地址
     * 
     * 常规获取IPv6地址或格式化IP地址为IPv6格式
     * 
     * @param string|null $addr 要处理的IP地址，默认为客户端IP
     * @return string 格式化后的IPv6地址或'Unknown'
     */
    function addr($addr=null) {
        !$addr && ($addr = $_SERVER['REMOTE_ADDR']);
        $type = self::type($addr);
        if ( $type === 6 && self::ipv6_check($addr) ) return $addr;
        elseif ( $type === 4 ) return self::ip426($addr);
        else return 'Unknown';
    }
 
    /**
     * 获取穿过代理的真实IP地址
     * 
     * 返回值为数组，array[0]为真实IP，array[1]为代理IP(可能为空)
     * 若array[0]和array[1]相等，则实际真实IP可能无法获取(高度匿名)
     * 
     * @return array 包含真实IP和代理IP的数组
     */
    function realip() {
        $is_proxy = false;
        if ( $_SERVER['HTTP_X_FORWARDED_FOR'] ) {
            $is_proxy = true;
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach($ips as $ip){
                if ( !self::wan_ip($ip) ) $ip = false;
                else break;
            }
        }
        if ( !$ip && $_SERVER['HTTP_CLIENT_IP'] ) {
            $is_proxy = true;
            $ip = $_SERVER['HTTP_CLIENT_IP'];
            if ( !self::wan_ip($ip) ) $ip = false;
        }
        if ( $is_proxy ) {
            $proxy = $_SERVER['REMOTE_ADDR'];
            if (!$ip) $ip = $proxy;
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return explode(',', $ip.','.$proxy);
    }
 
    /**
     * 压缩IPv6地址
     * 
     * 将标准格式的IPv6地址压缩为简洁形式
     * 
     * @param string $addr 要压缩的IPv6地址
     * @return string 压缩后的IPv6地址
     */
    function cut($addr) {
        if (!self::ipv6_check($addr)) return $addr;
        $addr = self::fill($addr);
        $arr = explode(':',$addr);
        foreach ($arr as $a) {
            $arr2[] = preg_replace('/^0{1,3}(\w+)/','\1',$a);
        }
        $addr = join(':',$arr2);
        $olen = strlen($addr);
        for($i=6;$i>0;$i--){
            // 初步压缩
            $addr = preg_replace('/:(0\:){'.$i.'}/','::',$addr,1);
            if (strlen($addr) < $olen ) break;
        }
        $addr = preg_replace('/^0\:\:/','::',$addr);
        $addr = preg_replace('/\:\:0$/','::',$addr);
        return $addr;
    }
 
    /**
     * 将IPv6地址转换为标准格式
     * 
     * 扩展压缩形式的IPv6地址为完整的标准格式
     * 
     * @param string $addr 要扩展的IPv6地址
     * @return string 标准格式的IPv6地址
     */
    function fill($addr) {
        if (!self::ipv6_check($addr)) return $addr;
        $addr = self::_fix_v4($addr);
        $arr = explode(':',$addr);
        foreach ($arr as $a) {
            $l = strlen($a);
            if ( $l > 0 && $l < 4 )
                $arr2[] = str_repeat('0', 4-$l).$a;
            else $arr2[] = $a;
        }
        $addr = join(':',$arr2);
        $fil = ':'.str_repeat('0000:', 9-count($arr));
        $addr = str_replace('::',$fil,$addr);
        $addr = preg_replace('/^\:/','0000:',$addr);
        $addr = preg_replace('/\:$/',':0000',$addr);
        return $addr;
    }
 
    /**
     * 将IPv4或IPv6地址转换为二进制格式
     * 
     * @param string $addr 要转换的IP地址
     * @return string|false 二进制格式的IP地址或转换失败时返回false
     */
    function ip2bin($addr) {
        $type = self::type($addr);
        if ( $type === 0 ) return false;
        elseif ( $type === 4 ) $addr = self::ip426($addr);
        else $addr = self::fill($addr);
        $hexstr = str_replace(':','',$addr);
        return pack('H*', $hexstr);
    }
 
    /**
     * 将二进制格式转换为IPv6地址
     * 
     * @param string $bin 二进制格式的IP地址
     * @return string|false 转换后的IPv6地址或转换失败时返回false
     */
    function bin2ip($bin) {
        if ( strlen($bin) !== 16 ) return false;
        $arr = str_split(join('',unpack('H*', $bin)), 4);
        $addr = join(':',$arr);
        return $addr;
    }
 
    /**
     * 将IPv4地址转换为IPv6格式
     * 
     * @param string $addr 要转换的IPv4地址
     * @return string 转换后的IPv6地址
     */
    function ip426($addr) {
        if (!self::ipv4_check($addr)) return $addr;
        $hex = dechex(self::ip2long($addr));
        $hex = str_repeat('0', 8-strlen($hex)).$hex;
        $ipv6 = '0000:0000:0000:0000:0000:0000:';
        $ipv6 .= substr($hex,0,4) . ':' . substr($hex,4,4);
        return $ipv6;
    }
 
    /**
     * 判断IP地址类型
     * 
     * @param string $addr 要判断的IP地址
     * @return int 6表示IPv6，4表示IPv4，0表示无效地址
     */
    function type($addr) {
        if ( self::ipv6_check($addr) ) return 6;
        elseif ( self::ipv4_check($addr) ) return 4;
        else return 0;
    }
 
    /**
     * 验证IPv4地址格式
     * 
     * @param string $addr 要验证的IPv4地址
     * @return bool 验证通过返回true，否则返回false
     */
    function ipv4_check($addr) {
        $arr = explode('.', $addr);
        $l = count($arr);
        for ( $i=0;$i<$l;$i++ ) {
            if ( strlen($arr[$i]) > 3 ) return false;
            if ( !is_numeric($arr[$i]) ) return false;
            $a = intval($arr[$i], 10);
            if ($a > 255 || $a <0) return false;
        }
        return true;
    }
 
    /**
     * 验证IPv6地址格式
     * 
     * @param string $addr 要验证的IPv6地址
     * @return bool 验证通过返回true，否则返回false
     */
    function ipv6_check($addr) {
        $addr = self::_fix_v4($addr);
        if ( strpos($addr, '.') ) return false;
        $l1 = count(explode('::',$addr));
        if ( $l1 > 2 ) return false;
        $l2 = count(explode(':',$addr));
        if ( $l2 < 3 || $l2 > 8 ) return false;
        if ( $l2 < 8 && $l1 !== 2 ) return false;
        preg_match('/^([0-9a-f]{0,4}\:)+[0-9a-f]{0,4}$/i',$addr,$arr);
        if ( !$arr[0] ) return false;
        return true;
    }
 
    /**
     * 将IPv4地址转换为长整型
     * 
     * @param string $addr 要转换的IPv4地址
     * @return int|false 转换后的长整型或转换失败时返回false
     */
    function ip2long($addr) {
        $arr = explode('.', $addr);
        $l = count($arr);
        $long = 0;
        for ( $i=0;$i<$l;$i++ ) {
            if ( strlen($arr[$i]) > 3 ) return false;
            if ( !is_numeric($arr[$i]) ) return false;
            $a = intval($arr[$i], 10);
            if ($a > 255 || $a <0) return false;
            $long += $a * pow(2, 24-$i*8);
        }
        return $long;
    }
 
    /**
     * 检查是否为外网可用地址
     * 
     * @param string $addr 要检查的IP地址
     * @return bool 是外网可用地址返回true，否则返回false
     */
    function wan_ip($addr) {
        if ( self::ipv6_check($addr) ) {
            $addr = self::fill($addr);
            // IPv4类地址处理
            $v4p = substr($addr,0,29);
            if ( $v4p == '0000:0000:0000:0000:0000:0000'
                || strtolower($v4p) == 'ffff:0000:0000:0000:0000:0000' ) {
                $t = str_replace($v4p,'',$addr);
                $t = str_replace(':','',$t);
                $ipv4 = long2ip(hexdec($t));
                return self::_wan_ipv4($ipv4);
            }
            // 取前16位进行比较
            $v6p = substr($addr,0,4);
            $bin = decbin(hexdec($v6p));
            $p = str_repeat(0, 16-strlen($bin)).$bin;
            if ( (($p&'1110000000000000')=='0010000000000000') //2000::/3
                || (($p&'1111111000000000')=='1111110000000000') //FC00::/7
                || (($p&'1111111111000000')=='1111111010000000') //FE80::/10
                || (($p&'1111111100000000')=='1111111100000000') //FF00::/8
                ) return false;
            return true;
        } else {
            return self::_wan_ipv4($addr);
        }
    }
 
    /**
     * 检查是否为IPv4外网可用地址
     * 
     * @param string $addr 要检查的IPv4地址
     * @return bool 是外网可用地址返回true，否则返回false
     */
    private function _wan_ipv4($addr){
        if ( !self::ipv4_check($addr) ) return false;
        $arr = explode('.',$addr);
        $bin = decbin($arr[0]*256+$arr[1]);
        $p = str_repeat(0, 16-strlen($bin)).$bin;
        $p8 = $p & '1111111100000000';
        $p16 = &$p;
        if ( ($p8 == '0000000000000000') // 0/8
            || ($p8 == '0000010100000000') // 5/8
            || ($p8 == '0000101000000000') // 10/8
            || ($p8 == '0001011100000000') // 23/8
            || ($p8 == '0010010000000000') // 36/8
            || ($p8 == '0010010100000000') // 37/8
            || ($p8 == '0010011100000000') // 39/8
            || ($p8 == '0010101000000000') // 42/8
            || ($p8 == '0110010000000000') // 100/8
            || ($p8 == '0110011000000000') // 102/8
            || ($p8 == '0110011100000000') // 103/8
            || ($p8 == '0110100000000000') // 104/8
            || ($p8 == '0110100100000000') // 105/8
            || ($p8 == '0110101000000000') // 106/8
            || ($p8 == '0111111100000000') // 127/8
            || ($p16 == '1010100111111110') // 169.254/16
            || (($p&'1111111111110000')=='1010110000010000') // 172.16/12
            || ($p8 == '1011001100000000') // 179/8
            || ($p8 == '1011100100000000') // 185/8
            || ($p16 == '1100000010101000') // 192.168/16
            || (($p&'1110000000000000')=='1110000000000000') // 224/8-255/8
            ) return false;
        return true;
    }
 
    /**
     * 修正IPv4位址类IPv6格式为标准IPv6格式
     * 
     * 不验证合法性
     * 
     * @param string $addr 要修正的IP地址
     * @return string 修正后的IP地址
     */
    private function _fix_v4($addr) {
        if ( !strpos($addr, '.') ) return $addr;
        preg_match('/(\d+\.){3}\d+$/',$addr,$arr);
        if ( !self::ipv4_check($arr[0]) ) return $addr;
        $hex = dechex(self::ip2long($arr[0]));
        $hex = str_repeat('0', 8-strlen($hex)).$hex;
        $v4p = substr($hex,0,4) . ':' . substr($hex,4,4);
        $p1 = str_replace($arr[0],'',$addr);
        strtolower($p1) === 'ffff:' && $p1 = '::'.$p1;
        $addr = $p1 . $v4p;
        return $addr;
    }
}