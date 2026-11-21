# Stream 类

## 概述

*
 * Candy框架 数据流处理核心类
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

## 主要方法


### getInstance()


### /**
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
	public function stream_open(string $path, $mode, $options, &$opened_path):()


### /**
	 * 从当前位置读取指定字节数的内容
	 * 
	 * 实现PHP流包装器的读取接口，从内部字符串缓冲区读取数据
	 * 
	 * @param int $count 要读取的字节数
	 * @return string 读取的内容
	 */
	public function stream_read(int $count):()


### /**
	 * 获取文件内容并自动解密加密内容
	 * 
	 * 读取指定文件的内容，并自动检测和处理加密内容（以-----BEGIN CIPHERTEXT-----标记）
	 * 
	 * @param string $file 文件路径
	 * @return string 文件内容，加密内容会被自动解密
	 */
	public function fileGetContents(string $file):()


### /**
	 * 将内容写入文件并进行压缩加密
	 * 
	 * 将指定内容写入文件，并自动对内容进行gzip压缩加密处理
	 * 
	 * @param string $file 文件路径
	 * @param string $contents 要写入的文件内容
	 * @return void
	 */
	public function filePutContents(string $file, string $contents):()


### /**
	 * 解密加密的文件内容
	 * 
	 * 解析包含密文标记的内容，提取加密部分并进行解密处理，
	 * 支持多层加密和动态密钥处理
	 * 
	 * @param string $content 包含可能加密内容的字符串
	 * @return string 解密后的内容
	 * @internal 内部使用的解密方法，不建议外部直接调用
	 */
	private function transCode(string $content):()


### /**
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
	private function getDecode(string $code,int $start,int $number):()


### /**
	 * 实现流包装器的stream_set_option接口
	 * 
	 * @return bool
	 */
	public function stream_set_option(int $option, int $arg1, int $arg2):()


### /**
	 * 检测流是否到达文件末尾
	 *
	 * @return bool 是否到达文件末尾
	 */
	public function stream_eof():()

* 实现流包装器的stream_set_option接口
	 * 
	 * @return bool


### /**
	 * 获取流的统计信息
	 *
	 * @return array|false 流的统计信息数组
	 */
	public function stream_stat():()

* 检测流是否到达文件末尾
	 *
	 * @return bool 是否到达文件末尾

