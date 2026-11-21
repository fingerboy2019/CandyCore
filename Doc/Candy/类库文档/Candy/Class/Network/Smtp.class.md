# Smtp 类

## 概述

* SMTP邮件发送类
 * 
 * 功能描述：提供基于PHP Socket的完整SMTP邮件发送功能，支持HTML内容、附件发送、抄送密送和SMTP认证。
 * 该类为静态工具类，设计为final类，不可被继承，位于Candy\Class\Network命名空间。
 * 
 * 主要功能：
 * - 支持通过SMTP服务器发送电子邮件
 * - 支持HTML格式的邮件内容
 * - 支持抄送(CC)和密送(BCC)功能
 * - 支持SMTP身份认证登录
 * - 支持通过MX记录自动查找邮件服务器
 * - 提供完整的邮件发送日志记录功能
 * - 支持自定义邮件头信息
 * 
 * @package Candy\Class\Network
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-10 14:00:00
 * @final 此类不可被继承

## 主要方法


### /**
 * SMTP邮件发送类
 * 
 * 功能描述：提供基于PHP Socket的完整SMTP邮件发送功能，支持HTML内容、附件发送、抄送密送和SMTP认证。
 * 该类为静态工具类，设计为final类，不可被继承，位于Candy\Class\Network命名空间。
 * 
 * 主要功能：
 * - 支持通过SMTP服务器发送电子邮件
 * - 支持HTML格式的邮件内容
 * - 支持抄送(CC)和密送(BCC)功能
 * - 支持SMTP身份认证登录
 * - 支持通过MX记录自动查找邮件服务器
 * - 提供完整的邮件发送日志记录功能
 * - 支持自定义邮件头信息
 * 
 * @package Candy\Class\Network
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-10 14:00:00
 * @final 此类不可被继承
 */

declare(strict_types=1);
namespace Candy\Extend\Network;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Smtp {
	public $hostName = 'localhost'; 
	public $timeOut = 30;
	public $logFile = '';
	public $debug  = false;
	public $sock = false;
	public $relayHost;
	public $smtpPort;
	public $auth;
	public $user;
	public $pass;
	
	/**
	 * 初始化SMTP连接配置
	 * 
	 * 构造函数用于设置SMTP服务器连接所需的基本参数，包括服务器地址、端口、认证信息等。
	 * 
	 * @param string $relayHost SMTP服务器地址，用于邮件转发
	 * @param int $smtpPort SMTP服务器端口，默认为25（非SSL），也可以是465（SSL）或587（TLS）
	 * @param bool $auth 是否需要SMTP身份认证，true表示需要，false表示不需要
	 * @param string $user SMTP认证用户名，当$auth为true时必填
	 * @param string $pass SMTP认证密码，当$auth为true时必填
	 */	
	public function __construct(string $relayHost,int $smtpPort = 25,bool $auth = false,string $user = '',string $pass = '')
	{
		$this->smtpPort = $smtpPort;
		$this->relayHost = $relayHost;
		$this->auth = $auth;
		$this->user = $user;
		$this->pass = $pass;
	}
	
	/**
	 * 发送电子邮件的主要方法
	 * 
	 * 这是Smtp类的核心公共方法，用于发送电子邮件。支持多个收件人、抄送、密送，
	 * 可以设置HTML或纯文本格式的邮件内容，以及自定义邮件头信息。
	 * 
	 * @param string $to 收件人邮箱地址，可以是单个邮箱或多个邮箱用逗号分隔
	 * @param string $from 发件人邮箱地址
	 * @param string $subject 邮件主题
	 * @param string $body 邮件内容
	 * @param string $mailtype 邮件类型，可选值："HTML"（HTML格式）或"TEXT"（纯文本格式）
	 * @param string $cc 抄送邮箱地址，可以是单个邮箱或多个邮箱用逗号分隔
	 * @param string $bcc 密送邮箱地址，可以是单个邮箱或多个邮箱用逗号分隔
	 * @param string $additional_headers 附加的邮件头信息
	 * @return bool 发送成功返回true，失败返回false
	 */	
	public function sendMail(string $to,string $from,string $subject = "",string $body = "",string $mailtype = "HTML",string $cc = "",string $bcc = "",string $additional_headers = ""):()


### /**
	 * 执行SMTP协议发送邮件
	 * 
	 * 内部核心方法，负责与SMTP服务器交互，按照SMTP协议规范发送各种命令。
	 * 包括HELO/EHLO问候、身份认证、指定发件人/收件人、发送邮件内容等操作。
	 * 
	 * @param string $helo 服务器主机名，用于HELO/EHLO命令
	 * @param string $from 发件人邮箱地址
	 * @param string $to 收件人邮箱地址
	 * @param string $header 邮件头信息
	 * @param string $body 邮件正文内容
	 * @return bool 发送成功返回true，失败返回false
	 */
	private function smtpSend(string $helo,string $from,string $to,string $header,string $body = ""):()


### /**
	 * 打开SMTP服务器连接
	 * 
	 * 根据配置选择连接方式：如果指定了relayHost，则连接到指定的邮件中继服务器；
	 * 否则通过MX记录查找收件人域名的邮件服务器。
	 * 
	 * @param string $address 收件人邮箱地址，用于查找MX记录
	 * @return bool 连接成功返回true，失败返回false
	 */
	private function smtpSockopen(string $address):()


### /**
	 * 连接到指定的中继SMTP服务器
	 * 
	 * 当配置了中继服务器时，使用fsockopen连接到指定的SMTP服务器。
	 * 支持超时设置，并记录连接日志。
	 * 
	 * @return bool 连接成功返回true，失败返回false
	 */
	private function smtpSockopenRelay():()


### /**
	 * 通过MX记录查找并连接邮件服务器
	 * 
	 * 从收件人邮箱地址中提取域名，然后通过DNS的MX记录查找该域名的邮件服务器。
	 * 尝试连接到找到的每个MX服务器，直到成功连接或全部尝试失败。
	 * 
	 * @param string $address 收件人邮箱地址
	 * @return bool 成功连接到MX服务器返回true，全部失败返回false
	 */
	private function smtpSockopenMx(string $address):()


### /**
	 * 发送邮件头和正文内容
	 * 
	 * 将邮件头和正文内容写入到SMTP连接中。如果开启了调试模式，会输出发送的内容。
	 * 
	 * @param string $header 邮件头信息
	 * @param string $body 邮件正文内容
	 * @return bool 发送成功返回true
	 */
	private function smtpMessage(string $header,string $body):()


### /**
	 * 发送邮件结束标记(End Of Message)
	 * 
	 * 按照SMTP协议规范，发送邮件内容结束标记(.)，并检查服务器响应。
	 * 
	 * @return bool 服务器接收成功返回true，失败返回false
	 */
	private function smtpEom():()


### /**
	 * 检查SMTP服务器响应状态
	 * 
	 * 读取SMTP服务器的响应并验证其状态码。SMTP协议中，2xx和3xx表示成功响应。
	 * 如果响应状态码不是以2或3开头，则表示命令执行失败。
	 * 
	 * @return bool 响应状态码有效(2xx或3xx)返回true，否则返回false
	 */
	private function smtpOk():()


### /**
	 * 发送SMTP命令到服务器
	 * 
	 * 将SMTP命令和参数组合后发送到服务器，并检查服务器的响应状态。
	 * 
	 * @param string $cmd SMTP命令（如HELO、MAIL FROM、RCPT TO等）
	 * @param string $arg 命令参数
	 * @return bool 命令发送成功且服务器响应有效返回true，否则返回false
	 */
	private function smtpPutCmd(string $cmd,string $arg = ""):()


### /**
	 * 记录SMTP错误信息
	 * 
	 * 当SMTP操作失败时，记录详细的错误信息到日志。
	 * 
	 * @param string $string 错误操作描述
	 * @return bool 始终返回false，表示操作失败
	 */
	private function smtpError(string $string):()

