<?php
/**
 * Security — CSRF 防护子模块
 *
 * 负责会话 CSRF 令牌的生成与校验，以及后台免验 Cookie（跳过 POST 严格过滤）。
 * 由 Candy\Core\Security 门面类 use 组合，对外仍通过 Security::generateCsrfToken() 调用。
 *
 * @package Candy\Core\Security
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-11 09:30:00
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Security;

trait CsrfGuard
{
	/** @var string CSRF 令牌在会话中的键名 */
	private static $csrfTokenName = 'csrf_token';

	/** @var int 令牌字节长度（hex 输出为 2 倍字符数） */
	private static $csrfTokenLength = 32;

	/** @var int 令牌过期时间（秒） */
	private static $csrfTokenExpire = 3600;

	/** @var string 后台免验 Cookie 键名，InputFilter 严格过滤时共用 */
	private static string $bypassFilterCookieKey = 'fHoi[moOij8s9fdsifrewikdsaf';

	/**
	 * 初始化 CSRF 防护（会话中不存在令牌时创建）
	 * @return void
	 */
	protected static function initCsrfProtection(): void
	{
		if (session(self::$csrfTokenName) === null) {
			session(self::$csrfTokenName, bin2hex(random_bytes(self::$csrfTokenLength)));
			session(self::$csrfTokenName.'_expire', time() + self::$csrfTokenExpire);
		}
	}

	/**
	 * 生成或获取当前会话的 CSRF 令牌
	 * @return string
	 */
	public static function generateCsrfToken(): string
	{
		self::initCsrfProtection();
		return (string) session(self::$csrfTokenName);
	}

	/**
	 * 验证提交的 CSRF 令牌是否有效且未过期
	 * @param string $token 待验证令牌
	 * @return bool
	 */
	public static function verifyCsrfToken(string $token): bool
	{
		$csrf = session(self::$csrfTokenName);
		$expiresAt = session(self::$csrfTokenName.'_expire');
		if ($csrf === null || $expiresAt === null || time() > (int) $expiresAt) {
			return false;
		}
		return hash_equals((string) $csrf, $token);
	}

	/**
	 * 删除后台免验 Cookie，恢复 POST 严格过滤
	 * @return void
	 * @deprecated 请使用 clearBypassCookie()
	 */
	public static function _delTokenName(): void
	{
		self::clearBypassCookie();
	}

	/**
	 * 删除后台免验 Cookie，恢复 POST 严格过滤
	 * @return void
	 */
	public static function clearBypassCookie(): void
	{
		cookie(self::$bypassFilterCookieKey, null);
	}

	/**
	 * 设置后台免验 Cookie，后续请求可跳过 POST 严格过滤
	 * @return void
	 * @deprecated 请使用 setBypassCookie()
	 */
	public static function _setTokenName(): void
	{
		self::setBypassCookie();
	}

	/**
	 * 设置后台免验 Cookie，后续请求可跳过 POST 严格过滤
	 * @return void
	 */
	public static function setBypassCookie(): void
	{
		cookie(self::$bypassFilterCookieKey, md5(serialize(self::$bypassFilterCookieKey)));
	}
}
