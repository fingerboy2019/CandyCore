<?php
/***
 * SMTP 邮件发送
 *
 * 业务说明：Socket SMTP 发信，支持 SSL/STARTTLS、AUTH LOGIN、EHLO
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 17:40:00 $
 *
 * @package Candy\Extend\Network\Mail
 */

declare(strict_types=1);
namespace Candy\Extend\Network\Mail;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Smtp {
	public string $hostName = 'localhost';
	public int $timeOut = 30;
	public string $logFile = '';
	public bool $debug = false;

	/** @var resource|false */
	public $sock = false;

	public string $relayHost = '';
	public int $smtpPort = 25;
	public bool $auth = false;
	public string $user = '';
	public string $pass = '';

	/** @var string 加密方式：空|ssl|tls */
	public string $secure = '';

	/**
	 * 初始化 SMTP 连接配置
	 *
	 * @param string $relayHost SMTP 服务器地址
	 * @param int $smtpPort 端口（465=SSL，587=TLS，25=明文）
	 * @param bool $auth 是否认证
	 * @param string $user 用户名
	 * @param string $pass 密码
	 * @param string $secure 加密：''|ssl|tls；空则按端口推断
	 */
	public function __construct(
		string $relayHost,
		int $smtpPort = 25,
		bool $auth = false,
		string $user = '',
		string $pass = '',
		string $secure = ''
	) {
		$this->smtpPort = $smtpPort;
		$this->relayHost = $relayHost;
		$this->auth = $auth;
		$this->user = $user;
		$this->pass = $pass;
		$this->secure = self::normalizeSecure($secure, $smtpPort);
	}

	/**
	 * 推断加密方式
	 *
	 * @param string $secure 显式配置
	 * @param int $port 端口
	 * @return string
	 */
	public static function normalizeSecure(string $secure, int $port): string
	{
		$secure = strtolower(trim($secure));
		if (in_array($secure, ['ssl', 'tls'], true)) {
			return $secure;
		}
		if ($port === 465) {
			return 'ssl';
		}
		if ($port === 587) {
			return 'tls';
		}
		return '';
	}

	/**
	 * 发送电子邮件
	 *
	 * @param string $to 收件人（逗号分隔）
	 * @param string $from 发件人
	 * @param string $subject 主题
	 * @param string $body 正文
	 * @param string $mailtype HTML|TEXT
	 * @param string $cc 抄送
	 * @param string $bcc 密送
	 * @param string $additional_headers 附加头
	 * @return bool
	 */
	public function sendMail(
		string $to,
		string $from,
		string $subject = '',
		string $body = '',
		string $mailtype = 'HTML',
		string $cc = '',
		string $bcc = '',
		string $additional_headers = ''
	): bool {
		$mailFrom = $this->getAddress($this->stripComment($from));
		$body = preg_replace('/(^|(\r\n))(\.)/', "\1.\3", $body) ?? $body;

		$header = "MIME-Version: 1.0\r\n";
		if (strtoupper($mailtype) === 'HTML') {
			$header .= "Content-Type: text/html; charset=UTF-8\r\n";
		} else {
			$header .= "Content-Type: text/plain; charset=UTF-8\r\n";
		}
		$header .= 'To: ' . $to . "\r\n";
		if ($cc !== '') {
			$header .= 'Cc: ' . $cc . "\r\n";
		}
		$header .= 'From: ' . $from . "\r\n";
		$header .= 'Subject: ' . $this->encodeHeader($subject) . "\r\n";
		$header .= $additional_headers;
		$header .= 'Date: ' . date('r') . "\r\n";
		$header .= 'X-Mailer: Candy SMTP (PHP/' . phpversion() . ")\r\n";
		[$msec, $sec] = explode(' ', microtime());
		$header .= 'Message-ID: <' . date('YmdHis', (int) $sec) . '.' . ((int) ($msec * 1000000)) . '.' . $mailFrom . ">\r\n";

		$recipients = explode(',', $this->stripComment($to));
		if ($cc !== '') {
			$recipients = array_merge($recipients, explode(',', $this->stripComment($cc)));
		}
		if ($bcc !== '') {
			$recipients = array_merge($recipients, explode(',', $this->stripComment($bcc)));
		}

		$sent = true;
		foreach ($recipients as $rcptTo) {
			$rcptTo = trim($this->getAddress($rcptTo));
			if ($rcptTo === '') {
				continue;
			}
			if (!$this->smtpSockopen($rcptTo)) {
				$this->logWrite('Error: Cannot send email to ' . $rcptTo . "\n");
				$sent = false;
				continue;
			}

			if ($this->smtpSend($this->hostName, $mailFrom, $rcptTo, $header, $body)) {
				$this->logWrite('E-mail has been sent to <' . $rcptTo . ">\n");
			} else {
				$this->logWrite('Error: Cannot send email to <' . $rcptTo . ">\n");
				$sent = false;
			}

			if (is_resource($this->sock)) {
				fclose($this->sock);
			}
			$this->sock = false;
			$this->logWrite("Disconnected from remote host\n");
		}

		return $sent;
	}

	/**
	 * 执行 SMTP 协议发信
	 *
	 * @param string $helo EHLO/HELO 主机名
	 * @param string $from 发件人地址
	 * @param string $to 收件人地址
	 * @param string $header 邮件头
	 * @param string $body 正文
	 * @return bool
	 */
	private function smtpSend(string $helo, string $from, string $to, string $header, string $body = ''): bool
	{
		if (!$this->smtpGreet($helo)) {
			return $this->smtpError('EHLO/HELO');
		}

		if ($this->secure === 'tls') {
			if (!$this->smtpPutCmd('STARTTLS')) {
				return $this->smtpError('STARTTLS');
			}
			$crypto = @stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			if ($crypto !== true) {
				$this->logWrite("Error: STARTTLS handshake failed\n");
				return false;
			}
			if (!$this->smtpGreet($helo)) {
				return $this->smtpError('EHLO after STARTTLS');
			}
		}

		if ($this->auth && !$this->smtpAuthLogin()) {
			return false;
		}

		if (!$this->smtpPutCmd('MAIL', 'FROM:<' . $from . '>')) {
			return $this->smtpError('MAIL FROM');
		}
		if (!$this->smtpPutCmd('RCPT', 'TO:<' . $to . '>')) {
			return $this->smtpError('RCPT TO');
		}
		if (!$this->smtpPutCmd('DATA')) {
			return $this->smtpError('DATA');
		}
		if (!$this->smtpMessage($header, $body)) {
			return $this->smtpError('message body');
		}
		if (!$this->smtpEom()) {
			return $this->smtpError('EOM');
		}
		if (!$this->smtpPutCmd('QUIT')) {
			return $this->smtpError('QUIT');
		}

		return true;
	}

	/**
	 * EHLO/HELO 问候
	 *
	 * @param string $helo 主机名
	 * @return bool
	 */
	private function smtpGreet(string $helo): bool
	{
		if ($this->smtpPutCmd('EHLO', $helo)) {
			return true;
		}
		return $this->smtpPutCmd('HELO', $helo);
	}

	/**
	 * AUTH LOGIN 认证（标准三回合）
	 *
	 * @return bool
	 */
	private function smtpAuthLogin(): bool
	{
		if (!$this->smtpWriteLine('AUTH LOGIN')) {
			return $this->smtpError('AUTH LOGIN');
		}
		$resp = $this->smtpReadResponse();
		if ($resp['code'] !== '334') {
			return $this->smtpError('AUTH LOGIN challenge (' . $resp['text'] . ')');
		}

		if (!$this->smtpWriteLine(base64_encode($this->user))) {
			return false;
		}
		$resp = $this->smtpReadResponse();
		if ($resp['code'] !== '334') {
			return $this->smtpError('AUTH LOGIN username (' . $resp['text'] . ')');
		}

		if (!$this->smtpWriteLine(base64_encode($this->pass))) {
			return false;
		}
		$resp = $this->smtpReadResponse();
		if ($resp['code'] !== '235') {
			return $this->smtpError('AUTH LOGIN password (' . $resp['text'] . ')');
		}

		return true;
	}

	/**
	 * 打开 SMTP 连接
	 *
	 * @param string $address 收件人（MX 模式用）
	 * @return bool
	 */
	private function smtpSockopen(string $address): bool
	{
		if ($this->relayHost === '') {
			return $this->smtpSockopenMx($address);
		}
		return $this->smtpSockopenRelay();
	}

	/**
	 * 连接中继服务器
	 *
	 * @return bool
	 */
	private function smtpSockopenRelay(): bool
	{
		$remote = $this->relayHost . ':' . $this->smtpPort;
		if ($this->secure === 'ssl') {
			$remote = 'ssl://' . $remote;
		}

		$this->logWrite('Trying to ' . $remote . "\n");
		$errno = 0;
		$errstr = '';
		$this->sock = @stream_socket_client(
			$remote,
			$errno,
			$errstr,
			$this->timeOut,
			STREAM_CLIENT_CONNECT
		);

		if (!($this->sock && $this->smtpReadGreeting())) {
			$this->logWrite('Error: Cannot connect to relay host ' . $this->relayHost . "\n");
			$this->logWrite('Error: ' . $errstr . ' (' . $errno . ")\n");
			return false;
		}

		stream_set_timeout($this->sock, $this->timeOut);
		$this->logWrite('Connected to relay host ' . $this->relayHost . "\n");
		return true;
	}

	/**
	 * 通过 MX 记录连接
	 *
	 * @param string $address 收件人邮箱
	 * @return bool
	 */
	private function smtpSockopenMx(string $address): bool
	{
		$domain = preg_replace('/^.+@([^@]+)$/', '$1', $address) ?? '';
		if ($domain === '' || !@getmxrr($domain, $MXHOSTS)) {
			$this->logWrite('Error: Cannot resolve MX "' . $domain . "\"\n");
			return false;
		}

		foreach ($MXHOSTS as $host) {
			$this->relayHost = $host;
			if ($this->smtpSockopenRelay()) {
				$this->logWrite('Connected to mx host ' . $host . "\n");
				return true;
			}
		}

		$this->logWrite('Error: Cannot connect to any mx hosts (' . implode(', ', $MXHOSTS) . ")\n");
		return false;
	}

	/**
	 * 读取连接欢迎语 220
	 *
	 * @return bool
	 */
	private function smtpReadGreeting(): bool
	{
		$resp = $this->smtpReadResponse();
		if (!str_starts_with($resp['code'], '220')) {
			$this->logWrite('Error: Remote host returned "' . $resp['text'] . "\"\n");
			return false;
		}
		return true;
	}

	/**
	 * 发送邮件头与正文
	 *
	 * @param string $header 头
	 * @param string $body 正文
	 * @return bool
	 */
	private function smtpMessage(string $header, string $body): bool
	{
		fputs($this->sock, $header . "\r\n" . $body);
		$this->smtpDebug('> ' . str_replace("\r\n", "\n> ", $header . "\n> " . $body . "\n> "));
		return true;
	}

	/**
	 * 发送 DATA 结束符
	 *
	 * @return bool
	 */
	private function smtpEom(): bool
	{
		fputs($this->sock, "\r\n.\r\n");
		$this->smtpDebug(". [EOM]\n");
		$resp = $this->smtpReadResponse();
		return str_starts_with($resp['code'], '2');
	}

	/**
	 * 读取 SMTP 多行响应
	 *
	 * @return array{code:string,text:string,lines:array<int,string>}
	 */
	private function smtpReadResponse(): array
	{
		$lines = [];
		$code = '000';
		$text = '';

		if (!is_resource($this->sock)) {
			return ['code' => $code, 'text' => $text, 'lines' => $lines];
		}

		do {
			$line = fgets($this->sock, 8192);
			if ($line === false) {
				break;
			}
			$line = rtrim($line, "\r\n");
			$lines[] = $line;
			$this->smtpDebug($line . "\n");
			if (strlen($line) >= 3 && ctype_digit(substr($line, 0, 3))) {
				$code = substr($line, 0, 3);
				$text = $line;
			}
			$continued = strlen($line) >= 4 && $line[3] === '-';
		} while ($continued);

		if (!preg_match('/^[23]/', $code)) {
			$this->logWrite('Error: Remote host returned "' . $text . "\"\n");
		}

		return ['code' => $code, 'text' => $text, 'lines' => $lines];
	}

	/**
	 * 发送 SMTP 命令并校验 2xx/3xx
	 *
	 * @param string $cmd 命令
	 * @param string $arg 参数
	 * @return bool
	 */
	private function smtpPutCmd(string $cmd, string $arg = ''): bool
	{
		$line = $arg !== '' ? $cmd . ' ' . $arg : $cmd;
		if (!$this->smtpWriteLine($line)) {
			return false;
		}
		$resp = $this->smtpReadResponse();
		return preg_match('/^[23]/', $resp['code']) === 1;
	}

	/**
	 * 写入一行（无额外命令前缀）
	 *
	 * @param string $line 内容
	 * @return bool
	 */
	private function smtpWriteLine(string $line): bool
	{
		if (!is_resource($this->sock)) {
			return false;
		}
		fputs($this->sock, $line . "\r\n");
		$this->smtpDebug('> ' . $line . "\n");
		return true;
	}

	/**
	 * 记录 SMTP 错误
	 *
	 * @param string $string 操作描述
	 * @return bool
	 */
	private function smtpError(string $string): bool
	{
		$this->logWrite('Error: Error occurred while ' . $string . ".\n");
		return false;
	}

	/**
	 * 写日志
	 *
	 * @param string $message 消息
	 * @return bool
	 */
	private function logWrite(string $message): bool
	{
		$this->smtpDebug($message);
		if ($this->logFile === '') {
			return true;
		}
		$message = date('M d H:i:s ') . get_current_user() . '[' . getmypid() . ']: ' . $message;
		if (!@file_exists($this->logFile) || !($fp = @fopen($this->logFile, 'a'))) {
			$this->smtpDebug('Warning: Cannot open log file "' . $this->logFile . "\"\n");
			return false;
		}
		flock($fp, LOCK_EX);
		fputs($fp, $message);
		fclose($fp);
		return true;
	}

	/**
	 * 移除地址注释
	 *
	 * @param string $address 地址
	 * @return string
	 */
	private function stripComment(string $address): string
	{
		$comment = '/\([^()]*\)/';
		while (preg_match($comment, $address)) {
			$address = preg_replace($comment, '', $address) ?? $address;
		}
		return $address;
	}

	/**
	 * 提取纯邮箱地址
	 *
	 * @param string $address 地址
	 * @return string
	 */
	private function getAddress(string $address): string
	{
		$address = preg_replace('/([ \t\r\n])+/', '', $address) ?? $address;
		return preg_replace('/^.*<(.+)>.*$/', '$1', $address) ?? $address;
	}

	/**
	 * MIME 编码邮件主题
	 *
	 * @param string $text 原文
	 * @return string
	 */
	private function encodeHeader(string $text): string
	{
		if ($text === '' || preg_match('/^[\x20-\x7E]*$/', $text)) {
			return $text;
		}
		return '=?UTF-8?B?' . base64_encode($text) . '?=';
	}

	/**
	 * 调试输出
	 *
	 * @param string $message 消息
	 * @return void
	 */
	private function smtpDebug(string $message): void
	{
		if ($this->debug) {
			echo $message;
		}
	}
}

