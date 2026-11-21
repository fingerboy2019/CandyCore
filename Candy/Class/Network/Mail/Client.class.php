<?php
/***
 * 邮件接收客户端（Client）
 *
 * 业务说明：POP3/IMAP 收信，IMAP 带 tag 命令，支持 SSL/STARTTLS
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 17:42:00 $
 *
 * @package Candy\Extend\Network\Mail
 */

declare(strict_types=1);
namespace Candy\Extend\Network\Mail;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Client {
	private string $host;
	private int $port;
	private string $protocol;
	private string $secure;
	private string $username;
	private string $password;
	private int $timeout;

	/** @var resource|false */
	private $connection = false;

	public bool $debug = false;
	public string $logFile = '';
	private string $error = '';

	private int $imapTag = 0;

	/**
	 * 初始化邮件接收配置
	 *
	 * @param string $host 服务器地址
	 * @param int $port 端口，0 为自动
	 * @param string $protocol pop3|imap
	 * @param bool $ssl 兼容参数：true 等价 secure=ssl
	 * @param string $username 用户名
	 * @param string $password 密码
	 * @param int $timeout 超时秒
	 * @param string $secure 加密：''|ssl|tls
	 */
	public function __construct(
		string $host,
		int $port = 0,
		string $protocol = 'pop3',
		bool $ssl = true,
		string $username = '',
		string $password = '',
		int $timeout = 30,
		string $secure = ''
	) {
		$this->host = $host;
		$this->protocol = strtolower($protocol);
		$this->username = $username;
		$this->password = $password;
		$this->timeout = $timeout;

		if ($secure !== '') {
			$this->secure = strtolower($secure);
		} elseif ($ssl) {
			$this->secure = 'ssl';
		} else {
			$this->secure = '';
		}

		if ($port === 0) {
			if ($this->protocol === 'pop3') {
				$this->port = $this->secure === 'ssl' ? 995 : 110;
			} else {
				$this->port = $this->secure === 'ssl' ? 993 : 143;
			}
		} else {
			$this->port = $port;
		}
	}

	/**
	 * 生成 IMAP 命令 tag
	 *
	 * @param int $num 序号
	 * @return string 如 A001
	 */
	public static function formatImapTag(int $num): string
	{
		return 'A' . str_pad((string) max(1, $num), 3, '0', STR_PAD_LEFT);
	}

	/**
	 * IMAP 字符串引号转义
	 *
	 * @param string $value 原值
	 * @return string
	 */
	public static function quoteImap(string $value): string
	{
		$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
		return '"' . $escaped . '"';
	}

	/**
	 * 连接邮件服务器
	 *
	 * @return bool
	 */
	public function connect(): bool
	{
		try {
			$remote = $this->host . ':' . $this->port;
			if ($this->secure === 'ssl') {
				$remote = 'ssl://' . $remote;
			}

			$errno = 0;
			$errstr = '';
			$socket = @stream_socket_client(
				$remote,
				$errno,
				$errstr,
				$this->timeout,
				STREAM_CLIENT_CONNECT
			);

			if (!$socket) {
				$this->error = "连接失败: $errstr ($errno)";
				$this->logWrite($this->error . "\n");
				return false;
			}

			stream_set_timeout($socket, $this->timeout);
			$this->connection = $socket;

			$greeting = $this->readLine();
			$this->logWrite("服务器响应: $greeting\n");

			if ($this->protocol === 'pop3') {
				if (!preg_match('/^\+OK/i', $greeting)) {
					$this->error = "POP3 连接失败: $greeting";
					$this->close();
					return false;
				}
			} elseif (!preg_match('/^\* OK/i', $greeting)) {
				$this->error = "IMAP 连接失败: $greeting";
				$this->close();
				return false;
			}

			if ($this->protocol === 'imap' && $this->secure === 'tls') {
				$result = $this->imapCommand('STARTTLS');
				if (!$result['ok']) {
					$this->error = 'IMAP STARTTLS 失败: ' . ($result['lines'][count($result['lines']) - 1] ?? '');
					$this->close();
					return false;
				}
				$crypto = @stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
				if ($crypto !== true) {
					$this->error = 'IMAP STARTTLS 握手失败';
					$this->close();
					return false;
				}
			}

			$this->logWrite("成功连接到 {$this->protocol} 服务器 {$this->host}:{$this->port}\n");
			return true;
		} catch (\Throwable $e) {
			$this->error = '连接异常: ' . $e->getMessage();
			$this->logWrite($this->error . "\n");
			return false;
		}
	}

	/**
	 * 登录认证
	 *
	 * @return bool
	 */
	public function login(): bool
	{
		if (!$this->connection) {
			$this->error = '未建立连接';
			return false;
		}

		try {
			if ($this->protocol === 'pop3') {
				$this->sendPop3Command('USER ' . $this->username);
				$response = $this->readPop3Response();
				if (!preg_match('/^\+OK/i', $response)) {
					$this->error = "POP3 用户名认证失败: $response";
					return false;
				}

				$this->sendPop3Command('PASS ' . $this->password);
				$response = $this->readPop3Response();
				if (!preg_match('/^\+OK/i', $response)) {
					$this->error = "POP3 密码认证失败: $response";
					return false;
				}
			} else {
				$cmd = 'LOGIN ' . self::quoteImap($this->username) . ' ' . self::quoteImap($this->password);
				$result = $this->imapCommand($cmd);
				if (!$result['ok']) {
					$this->error = 'IMAP 认证失败: ' . ($result['lines'][count($result['lines']) - 1] ?? '');
					return false;
				}
			}

			$this->logWrite("认证成功\n");
			return true;
		} catch (\Throwable $e) {
			$this->error = '认证异常: ' . $e->getMessage();
			$this->logWrite($this->error . "\n");
			return false;
		}
	}

	/**
	 * 获取邮件列表
	 *
	 * @param int $limit 限制数量，0 为全部
	 * @return array<int,array<string,mixed>>
	 */
	public function getMailList(int $limit = 0): array
	{
		if (!$this->connection) {
			$this->error = '未建立连接';
			return [];
		}

		$mails = [];

		try {
			if ($this->protocol === 'pop3') {
				$this->sendPop3Command('LIST');
				$response = $this->readPop3Multiline();
				if (!preg_match('/^\+OK/i', $response[0] ?? '')) {
					$this->error = '获取邮件列表失败: ' . ($response[0] ?? '');
					return [];
				}
				for ($i = 1; $i < count($response); $i++) {
					if (preg_match('/^(\d+) (\d+)$/', $response[$i], $matches)) {
						$mails[] = ['id' => (int) $matches[1], 'size' => (int) $matches[2]];
					}
				}
			} else {
				$select = $this->imapCommand('SELECT INBOX');
				if (!$select['ok']) {
					$this->error = '选择收件箱失败: ' . ($select['lines'][count($select['lines']) - 1] ?? '');
					return [];
				}

				$search = $this->imapCommand('SEARCH ALL');
				if (!$search['ok']) {
					$this->error = '搜索邮件失败: ' . ($search['lines'][count($search['lines']) - 1] ?? '');
					return [];
				}

				foreach ($search['lines'] as $line) {
					if (preg_match('/^\* SEARCH (.*)$/', $line, $matches)) {
						$ids = array_filter(explode(' ', trim($matches[1])));
						foreach ($ids as $id) {
							$mails[] = ['id' => (int) $id];
						}
					}
				}
			}

			if ($limit > 0 && count($mails) > $limit) {
				$mails = array_slice($mails, -$limit);
			}

			$this->logWrite('成功获取 ' . count($mails) . " 封邮件\n");
			return $mails;
		} catch (\Throwable $e) {
			$this->error = '获取邮件列表异常: ' . $e->getMessage();
			$this->logWrite($this->error . "\n");
			return [];
		}
	}

	/**
	 * 获取单封邮件
	 *
	 * @param int $mailId 邮件序号
	 * @return array<string,mixed>
	 */
	public function getMail(int $mailId): array
	{
		if (!$this->connection) {
			$this->error = '未建立连接';
			return [];
		}

		try {
			if ($this->protocol === 'pop3') {
				$this->sendPop3Command("RETR $mailId");
				$response = $this->readPop3Multiline();
				if (!preg_match('/^\+OK/i', $response[0] ?? '')) {
					$this->error = '获取邮件失败: ' . ($response[0] ?? '');
					return [];
				}
				$mailContent = implode("\r\n", array_slice($response, 1));
			} else {
				$result = $this->imapCommand("FETCH $mailId BODY.PEEK[]", true);
				if (!$result['ok']) {
					$this->error = '获取邮件失败: ' . ($result['lines'][count($result['lines']) - 1] ?? '');
					return [];
				}
				$mailContent = $this->extractImapFetchBody($result['lines']);
			}

			$mail = $this->parseMailContent($mailContent);
			$mail['id'] = $mailId;
			$this->logWrite("成功获取邮件 #$mailId\n");
			return $mail;
		} catch (\Throwable $e) {
			$this->error = '获取邮件异常: ' . $e->getMessage();
			$this->logWrite($this->error . "\n");
			return [];
		}
	}

	/**
	 * 删除邮件
	 *
	 * @param int $mailId 邮件序号
	 * @return bool
	 */
	public function deleteMail(int $mailId): bool
	{
		if (!$this->connection) {
			$this->error = '未建立连接';
			return false;
		}

		try {
			if ($this->protocol === 'pop3') {
				$this->sendPop3Command("DELE $mailId");
				$response = $this->readPop3Response();
				if (!preg_match('/^\+OK/i', $response)) {
					$this->error = "删除邮件失败: $response";
					return false;
				}
			} else {
				$store = $this->imapCommand("STORE $mailId +FLAGS (\\Deleted)");
				if (!$store['ok']) {
					$this->error = '标记删除失败: ' . ($store['lines'][count($store['lines']) - 1] ?? '');
					return false;
				}
				$expunge = $this->imapCommand('EXPUNGE');
				if (!$expunge['ok']) {
					$this->error = 'EXPUNGE 失败: ' . ($expunge['lines'][count($expunge['lines']) - 1] ?? '');
					return false;
				}
			}

			$this->logWrite("成功删除邮件 #$mailId\n");
			return true;
		} catch (\Throwable $e) {
			$this->error = '删除邮件异常: ' . $e->getMessage();
			$this->logWrite($this->error . "\n");
			return false;
		}
	}

	/**
	 * 关闭连接
	 *
	 * @return bool
	 */
	public function close(): bool
	{
		if (!$this->connection) {
			return true;
		}

		try {
			if ($this->protocol === 'pop3') {
				$this->sendPop3Command('QUIT');
				$this->readPop3Response();
			} else {
				$this->imapCommand('LOGOUT');
			}
		} catch (\Throwable $e) {
			$this->error = '关闭连接异常: ' . $e->getMessage();
		}

		fclose($this->connection);
		$this->connection = false;
		$this->logWrite("成功关闭连接\n");
		return true;
	}

	/**
	 * 发送 IMAP 带 tag 命令并读取响应
	 *
	 * @param string $command 命令体（不含 tag）
	 * @param bool $withLiterals 是否读取字面量（FETCH）
	 * @return array{ok:bool,tag:string,lines:array<int,string>}
	 */
	private function imapCommand(string $command, bool $withLiterals = false): array
	{
		$this->imapTag++;
		$tag = self::formatImapTag($this->imapTag);
		$line = $tag . ' ' . $command;
		$this->logWrite("发送命令: $line\n");
		fwrite($this->connection, $line . "\r\n");

		$lines = [];
		$ok = false;

		while (!feof($this->connection)) {
			$raw = $this->readLine();
			if ($raw === '') {
				break;
			}

			if ($withLiterals && preg_match('/\{(\d+)\}\s*$/', $raw, $m)) {
				$literal = $this->readLiteral((int) $m[1]);
				$lines[] = $literal;
				continue;
			}

			$lines[] = $raw;

			if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/i', $raw, $status)) {
				$ok = strtoupper($status[1]) === 'OK';
				break;
			}
		}

		return ['ok' => $ok, 'tag' => $tag, 'lines' => $lines];
	}

	/**
	 * 读取 IMAP 字面量内容
	 *
	 * @param int $size 字节数
	 * @return string
	 */
	private function readLiteral(int $size): string
	{
		$data = '';
		while (strlen($data) < $size && !feof($this->connection)) {
			$chunk = fread($this->connection, $size - strlen($data));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		// 字面量后的 CRLF
		$this->readLine();
		return $data;
	}

	/**
	 * 从 FETCH 响应行提取邮件原文
	 *
	 * @param array<int,string> $lines 响应行
	 * @return string
	 */
	private function extractImapFetchBody(array $lines): string
	{
		foreach ($lines as $line) {
			if (str_contains($line, 'FETCH') || str_contains($line, 'BODY')) {
				continue;
			}
			if ($line !== '' && !preg_match('/^\)/', $line)) {
				return $line;
			}
		}
		return '';
	}

	/**
	 * 发送 POP3 命令
	 *
	 * @param string $command 命令
	 * @return void
	 */
	private function sendPop3Command(string $command): void
	{
		$this->logWrite("发送命令: $command\n");
		fwrite($this->connection, $command . "\r\n");
	}

	/**
	 * 读取 POP3 单行响应
	 *
	 * @return string
	 */
	private function readPop3Response(): string
	{
		return $this->readLine();
	}

	/**
	 * 读取 POP3 多行响应（至单独一行的 .）
	 *
	 * @return array<int,string>
	 */
	private function readPop3Multiline(): array
	{
		$lines = [];
		while (!feof($this->connection)) {
			$line = $this->readLine();
			if ($line === '.') {
				break;
			}
			if (str_starts_with($line, '..')) {
				$line = substr($line, 1);
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * 读取一行
	 *
	 * @return string
	 */
	private function readLine(): string
	{
		if (!$this->connection) {
			return '';
		}
		$line = fgets($this->connection);
		if ($line === false) {
			return '';
		}
		return rtrim($line, "\r\n");
	}

	/**
	 * 解析邮件原文
	 *
	 * @param string $content 原文
	 * @return array<string,mixed>
	 */
	private function parseMailContent(string $content): array
	{
		$mail = [
			'headers' => [],
			'subject' => '',
			'from' => '',
			'to' => '',
			'date' => '',
			'body' => '',
			'html_body' => '',
		];

		$content = str_replace("\r\n", "\n", $content);
		$parts = preg_split("/\n\n/", $content, 2);
		$headersPart = $parts[0] ?? '';
		$bodyPart = $parts[1] ?? '';

		$headerLines = explode("\n", $headersPart);
		$currentHeader = '';

		foreach ($headerLines as $line) {
			if (preg_match('/^([^:\s]+):\s*(.*)$/', $line, $matches)) {
				$currentHeader = strtolower($matches[1]);
				$mail['headers'][$currentHeader] = $matches[2];
			} elseif ($currentHeader !== '' && preg_match('/^\s+(.*)$/', $line, $matches)) {
				$mail['headers'][$currentHeader] .= ' ' . $matches[1];
			}
		}

		if (isset($mail['headers']['subject'])) {
			$mail['subject'] = $this->decodeMimeHeader($mail['headers']['subject']);
		}
		if (isset($mail['headers']['from'])) {
			$mail['from'] = $this->decodeMimeHeader($mail['headers']['from']);
		}
		if (isset($mail['headers']['to'])) {
			$mail['to'] = $this->decodeMimeHeader($mail['headers']['to']);
		}
		if (isset($mail['headers']['date'])) {
			$mail['date'] = $mail['headers']['date'];
		}

		if ($bodyPart !== '') {
			$contentType = strtolower($mail['headers']['content-type'] ?? 'text/plain');
			if (str_contains($contentType, 'text/html')) {
				$mail['html_body'] = $bodyPart;
				$mail['body'] = strip_tags($bodyPart);
			} else {
				$mail['body'] = $bodyPart;
			}
		}

		return $mail;
	}

	/**
	 * 解码 MIME 邮件头
	 *
	 * @param string $header 编码头
	 * @return string
	 */
	private function decodeMimeHeader(string $header): string
	{
		return preg_replace_callback('/=\?([^?]+)\?([QB])\?([^?]+)\?=/i', function (array $matches): string {
			$charset = $matches[1];
			$encoding = $matches[2];
			$text = $matches[3];
			if (strtolower($encoding) === 'b') {
				$text = base64_decode($text) ?: '';
			} else {
				$text = quoted_printable_decode($text);
			}
			if (strtolower($charset) !== 'utf-8' && $text !== '') {
				$text = mb_convert_encoding($text, 'UTF-8', $charset);
			}
			return $text;
		}, $header) ?? $header;
	}

	/**
	 * 写日志
	 *
	 * @param string $message 消息
	 * @return bool
	 */
	private function logWrite(string $message): bool
	{
		if ($this->debug) {
			echo $message;
		}
		if ($this->logFile === '') {
			return true;
		}
		$message = date('M d H:i:s ') . get_current_user() . '[' . getmypid() . ']: ' . $message;
		if (!($fp = @fopen($this->logFile, 'a'))) {
			return false;
		}
		flock($fp, LOCK_EX);
		fputs($fp, $message);
		fclose($fp);
		return true;
	}

	/**
	 * 获取最后一次错误
	 *
	 * @return string
	 */
	public function getError(): string
	{
		return $this->error;
	}
}

