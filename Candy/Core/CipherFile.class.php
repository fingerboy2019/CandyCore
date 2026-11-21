<?php
/***
 * Candy框架 加密源码文件读写类
 * 
 * 该类提供了强大的数据流处理功能，主要用于处理文件内容的读取、写入和加密解密操作，
 * 支持通过candy://协议进行内容处理，以及对加密内容的自动解密和缓存管理。
 * 
 * 主要功能：
 * - 自定义流协议处理
 * - 文件内容的缓存管理
 * - 支持加密内容的解密处理
 * - 文件内容的读写和压缩
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-26 11:31:47 $
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class CipherFile {
	/**
	 * 流上下文资源
	 * @var resource
	 */
	public $context;
	
	/**
	 * 单例实例
	 * @var Stream|null
	 */
	private static $instance;
	
	/**
	 * 当前处理的字符串内容
	 * @var string
	 */
	private $string;
	
	/**
	 * 当前读取位置
	 * @var int
	 */
	private $position;
	
	/**
	 * 获取Stream类的单例实例
	 * 
	 * 实现了单例模式，确保在应用中只存在一个Stream实例，避免重复创建对象
	 * 
	 * @return Stream 返回Stream类的单例实例
	 */
	public static function getInstance(): CipherFile
	{
		if (self::$instance === null) {
           self::$instance = new self();
        }
		
		//返回对象
        return self::$instance;
    }
	
	/**
	 * 打开自定义流，实现PHP流包装器接口
	 * 
	 * 处理candy://协议的流打开操作，支持文件内容读取、缓存和加密内容处理
	 * 
	 * @param string $string 要打开的流路径，支持candy://协议
	 * @param string $mode 打开模式（与fopen兼容）
	 * @param int $options 流选项
	 * @param string $opened_path 引用参数，返回实际打开的路径
	 * @return bool 操作是否成功
	 */
	public function stream_open(string $path, $mode, $options, &$opened_path): bool
	{
		//不使用缓存，直接读取文件
		$string = file_get_contents($path);
				
		//错误处理：读取失败时返回false
		if($string === false) return false;
		
		if(str_ends_with($string, 'gzcp')){
			$string = base64_decode('Z3p1bmNvbXByZXNz')(substr($string,0, -4));
		}
		
		//包含密文
		if(stristr($string, '-----BEGIN CIPHERTEXT-----') !== false){
			$string = $this->transCode($string);
		}
		$this->string = $string;
		$this->position = 0;
		return true;
	}
	
	/**
	 * 从当前位置读取指定字节数的内容
	 * 
	 * 实现PHP流包装器的读取接口，从内部字符串缓冲区读取数据
	 * 
	 * @param int $count 要读取的字节数
	 * @return string 读取的内容
	 */
	public function stream_read(int $count): string
	{
		$ret = substr($this->string, $this->position, $count);
		$this->position += strlen($ret);
		return $ret;
	}
	
	/**
	 * 获取文件内容并自动解密加密内容
	 * 
	 * 读取指定文件的内容，并自动检测和处理加密内容（以-----BEGIN CIPHERTEXT-----标记）
	 * 
	 * @param string $file 文件路径
	 * @return string 文件内容，加密内容会被自动解密
	 */
	public function fileGetContents(string $file): string
	{
		$contents = file_get_contents($file);
		//包含密文
		if(stristr($contents, '-----BEGIN CIPHERTEXT-----') !== false){
			$contents = $this->transCode($contents);
		}
		return $contents;
	}
	
	/**
	 * 将内容写入文件并进行压缩加密
	 * 
	 * 将指定内容写入文件，并自动对内容进行gzip压缩加密处理
	 * 
	 * @param string $file 文件路径
	 * @param string $contents 要写入的文件内容
	 * @return void
	 */
	public function filePutContents(string $file, string $contents): void
	{
		file_put_contents($file, base64_decode('Z3pjb21wcmVzcw==')($contents, 9) . 'gzcp');
	}
	
	/**
	 * 解密加密的文件内容
	 * 
	 * 解析包含密文标记的内容，提取加密部分并进行解密处理，
	 * 支持多层加密和动态密钥处理
	 * 
	 * @param string $content 包含可能加密内容的字符串
	 * @return string 解密后的内容
	 * @internal 内部使用的解密方法，不建议外部直接调用
	 */
	private function transCode(string $content): string
	{
		//延迟加载加密类库
		if(class_exists('\Candy\Extend\Mcrypt') === false){
			require(CANDYPATH.'Candy/Class/Str/Mcrypt.class.php');
		}
		
		preg_match('/(\/\*{3})([\s\S]*)(\*\/)/U', $content, $explanatory);
		preg_match("/\/\*\*\n-----BEGIN CIPHERTEXT-----\n([\S]+)\n-----END CIPHERTEXT-----\n\*\//", $content, $matches);
		if(empty($matches)) return $content;
		$ciphertext = trim($matches[1]);
		$s = (int)substr($ciphertext, -1, 1);
		$ciphertext = substr($ciphertext, 0, -1);
		$contents = "<?php\n" . (empty($explanatory) ? '' : $explanatory[0]) . "\n" . \Candy\Extend\Mcrypt::code($ciphertext, 'localhost', 'de');
		preg_match_all('/\/\/CIPHER(.*)/', $contents, $m);
		if(isset($m[1]) && count($m[1]) > 0){
			$lv = count($m[1]);
			$code = $this->getDecode((C('AUTHCODE')?:''), $s, $lv);
			for($i=0;$i<$lv;$i++){
				$contents = str_replace($m[0][$i], (count($code) > $i ? \Candy\Extend\Mcrypt::code($m[1][$i], $code[$i], 'de') : ''), $contents);
			}
		}
		return $contents;
	}
	
	/**
	 * 生成解密所需的密钥数组
	 * 
	 * 根据授权码、起始位置和数量生成用于解密的密钥数组，
	 * 支持基于服务器信息和时间的动态密钥生成
	 * 
	 * @param string $code 授权码字符串
	 * @param int $start 起始位置偏移量
	 * @param int $number 需要生成的密钥数量
	 * @return array 生成的密钥数组
	 * @internal 内部使用的密钥生成方法，不建议外部直接调用
	 */
	private function getDecode(string $code,int $start,int $number): array
	{
		if(empty($code)) return [];
		$authcode = [];
		$auth = '';
		if(count(explode('-', $code)) > 4){
			$server = I('server.', [], '');
			$ts = array_pop(explode('-', $code));
			if(stripos('000', $ts) === false){
				$s = hexdec('0x'.$ts[0]);
				$ts[1] != '0' && $ts = substr_replace($ts, substr(md5((string)($server['HTTP_HOST'] ?? '')), $s, 1), 1, 1);
				$ts[2] != '0' && $ts = substr_replace($ts, substr(md5((string)($server['REMOTE_ADDR'] ?? '')), $s*2, 1), 2, 1);
				if($ts[3] != '0'){
					if(0x7E7 + hexdec('0x'. $ts[3]) > (int)date('Y', time())){
						$auth = strtoupper($ts);
					}
				}else{
					$auth = strtoupper($ts);
				}
			}
		}
		$code = str_replace('-', '', $code);
		for($i=1;$i<=$number;$i++){
			if($i<5){
				$key = '';
				for($z=0;$z<4;$z++){
					$t = $start + $z*4;
					if($t < 16 && $t+$i <= 16){
						$key .= substr($code, $t, $i);
					}else{
						if($t > 16){
							$key .= substr($code, $t-15, $i);
						}else{
							$key .= substr($code, $t, 16-$t);
							$key = substr($code, 0, $t+$i-16) . $key;
						}
					}
				}
			}
			$authcode[] = $key . $auth;
		}
		return $authcode;
	}
		
	/**
	 * 实现流包装器的stream_set_option接口
	 * 
	 * @return bool
	 */
	public function stream_set_option(int $option, int $arg1, int $arg2): bool
	{
		return false;
	}

	/**
	 * 检测流是否到达文件末尾
	 *
	 * @return bool 是否到达文件末尾
	 */
	public function stream_eof(): bool
	{
		return $this->position >= strlen($this->string);
	}

	/**
	 * 获取流的统计信息
	 *
	 * @return array|false 流的统计信息数组
	 */
	public function stream_stat(): array|false
	{
		return false;
	}

	/**
	 * 转换流为特定类型
	 *
	 * @return resource|false
	 */
	public function stream_cast(int $cast_as)
	{
		return false;
	}

	/**
	 * 获取URL的统计信息
	 *
	 * @return array|false URL的统计信息数组
	 */
	public function url_stat(string $path, int $flags): array|false
	{
		return false;
	}
}
