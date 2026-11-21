<?php
/**
 * Security — 输入过滤编排
 *
 * 协调 GET/POST 简易黑名单、Referer 校验、严格注入检测（SqlInjectionGuard）
 * 与 XSS 清理（XssSanitizer）；由 runMiddlewares('After') 触发 _getInfo()。
 *
 * $Date: 2026-08-18 20:25:00 $
 *
 * @package Candy\Core\Trait\Security
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Security;

use Candy\Core\Log;

trait InputFilter
{
	use SqlInjectionGuard;
	use XssSanitizer;

	/**
	 *  get Info
	 */
	private static function _getInfo(): void
	{
		if (self::check('w') === false) {
			$get = self::requestGet();
			$post = self::requestPost();
			array_walk($get, 'Candy\Core\Security::_checkGet');
			if (C('METHOD') == 'POST') {
				array_walk($post, 'Candy\Core\Security::_checkPost');
				if (is_null(C('REMOVEXSS'))) {
					array_walk($post, 'Candy\Core\Security::_removeXss');
				}
			}

			self::_misMatch();

			$rank = C('securityRank');
			if ($rank != null) {
				self::setInjectionRank($rank);
				$server = self::requestServer();
				$cookie = self::requestCookie();
				array_walk($server, 'Candy\Core\Security::_recursive');
				array_walk($cookie, 'Candy\Core\Security::_recursive');
				if (isset($cookie[self::$bypassFilterCookieKey])) {
					$cookieStr = self::_decodeForInspection($cookie[self::$bypassFilterCookieKey]);
					if ($cookieStr != md5(serialize(self::$bypassFilterCookieKey))) {
						array_walk($post, 'Candy\Core\Security::_recursive');
					}
				} else {
					array_walk($post, 'Candy\Core\Security::_recursive');
				}
				array_walk($get, 'Candy\Core\Security::_recursive');
			}
		}
	}

	/**
	 * 获取当前请求 GET 数据
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestGet(): array
	{
		return I('get.', [], '');
	}

	/**
	 * 获取当前请求 POST 数据
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestPost(): array
	{
		return I('post.', [], '');
	}

	/**
	 * 获取当前请求 SERVER 数据
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestServer(): array
	{
		return I('server.', [], '');
	}

	/**
	 * 获取当前请求 Cookie 数据
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestCookie(): array
	{
		return cookie();
	}

	/**
	 *  mis Match
	 * @return bool
	 */
	private static function _misMatch(): bool
	{
		$server = self::requestServer();
		$post = self::requestPost();
		$referer = empty($server['HTTP_REFERER']) ? '' : $server['HTTP_REFERER'];
		if (empty($referer)) {
			return false;
		}
		$HOSTARR = [];
		preg_match('/https?:\/\/([^\/]*)/i', $referer, $HOSTARR);
		if (isset($HOSTARR[1])) {
			$referer = $HOSTARR[1];
		} else {
			if (count($post) > 0) {
				self::_unSetPost();
			}
			return false;
		}
		$referer = self::_hexEncodeForInspection($referer);
		$curhost = empty($server['HTTP_HOST']) ? ($server['SERVER_NAME'] ?? '') : $server['HTTP_HOST'];
		$curhost = self::_hexEncodeForInspection($curhost);
		if ($referer == $curhost) {
			return true;
		}
		if (count($post) > 0) {
			self::_unSetPost();
		}
		return false;
	}

	/**
	 *  un Set Post
	 */
	private static function _unSetPost(): void
	{
		$post = ['SAFE_ERROR' => 'Data Unloaded by Firewall!'];
		if (\Candy\Core\Request::inCoroutine()) {
			\R()->replacePost($post);
		}
		if (!\Candy\Core\Request::inCoroutine()) {
			$_POST = $post;
		}
	}

	/**
	 *  check Get
	 * @param mixed $get
	 */
	private static function _checkGet(mixed $get): void
	{
		static $bad = [];
		if (empty($get)) {
			return;
		}
		if (is_array($get)) {
			array_walk($get, 'Candy\Core\Security::_checkGet');
			return;
		}
		if (!is_string($get)) {
			return;
		}
		empty($bad) && $bad = loadConfig('AttackCode')['get'] ?? [];
		if (empty($bad)) {
			self::_removeXss($get);
			return;
		}
		foreach ($bad as $t) {
			if (substr_count(strtolower($get), $t) > 0) {
				self::_saveAttackLog('GET', $get);
				return;
			}
			self::_removeXss($get);
		}
	}

	/**
	 *  check Post
	 * @param mixed $post
	 */
	private static function _checkPost(mixed $post): void
	{
		static $bad = [];
		if (empty($post)) {
			return;
		}
		if (is_array($post)) {
			array_walk($post, 'Candy\Core\Security::_checkPost');
			return;
		}
		// 只检查字符串：JSON 里的 id / project_id 等数字参数不是攻击载体，
		// 而 PHP 8 的 strtolower() 只接受 string（strict_types 下 int 直接 TypeError）。
		if (!is_string($post)) {
			return;
		}
		empty($bad) && $bad = loadConfig('AttackCode')['post'];
		if (empty($bad)) {
			return;
		}
		foreach ($bad as $t) {
			if (substr_count(strtolower($post), $t) > 0) {
				self::_saveAttackLog('POST', $post);
				return;
			}
			return;
		}
	}

	/**
	 *  save Attack Log
	 * @param mixed $type
	 * @param mixed $val
	 */
	private static function _saveAttackLog(string $type, string $val): void
	{
		$logpath = C('LOGPATH') . 'attack-' . date('Y-m-d') . '.log';
		$server = self::requestServer();

		$data = [
			'url' => isset($server['QUERY_STRING']) && $server['QUERY_STRING'] ? $server['QUERY_STRING'] : ($server['REQUEST_URI'] ?? ''),
			'ip' => \Candy\Extend\Network\Client::getClientIp(),
			'time' => gmTime(),
			'type' => $type,
			'val' => $val,
			'user' => $server['HTTP_USER_AGENT'] ?? '',
		];

		$logs = Log::read($logpath);
		if (!empty($logs)) {
			$idata = 0;
			foreach ($logs as $v) {
				if (empty($v)) {
					continue;
				}
				$t = @unserialize(substr($v, 30), ['allowed_classes' => false]);
				if (!is_array($t)) {
					continue;
				}
				if ($data['ip'] == $t['ip']) {
					$idata++;
				}
				if ($idata >= 10 || ($data['time'] - $t['time'] < 20 && $data['ip'] == $t['ip'] && $data['url'] == $t['url'])) {
					self::add(['type' => 'b', 'exp_time' => gmTime() + G('blacklocktime', '', 3600)]);
					$msg = '请勿提交非法' . strtoupper($type) . '参数值：' . htmlspecialchars(self::_stripSlashesCheck($val));
					showExceptionMsg('firewall', $msg);
				}
			}
			unset($logs);
		}

		Log::input(serialize($data), -1, $logpath);
		$msg = '非法' . strtoupper($type) . '参数值：' . htmlspecialchars(self::_stripSlashesCheck($val));
		showExceptionMsg('firewall', $msg);
	}

	/**
	 *  strip Slashes Check
	 * @param mixed $string
	 * @return mixed
	 */
	protected static function _stripSlashesCheck(string $string): mixed
	{
		if (!$string) {
			return null;
		}
		if (!is_array($string)) {
			return stripslashes($string);
		}
		foreach ($string as $key => $value) {
			$string[$key] = self::_stripSlashesCheck($value);
		}
		return $string;
	}
}
