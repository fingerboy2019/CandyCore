<?php
/***
 * 博客管理后台认证
 *
 * 业务说明：基于 Session 的登录态，账号密码来自 BlogConfig.auth
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:20:00 $
 *
 * @package Candy\Extend\Doc\Blog\Admin
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog\Admin;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Auth
{
    private const SESSION_KEY = 'blog_admin_auth';

    private const REMEMBER_COOKIE = 'blog_admin_remember';

    /**
     * 是否已登录
     *
     * @return bool
     */
    public static function check(): bool
    {
        $auth = session(self::SESSION_KEY);
        if (is_array($auth) && !empty($auth['username'])) {
            if (!empty($auth['expire']) && time() > (int) $auth['expire']) {
                self::logout();

                return false;
            }

            return true;
        }

        return self::restoreRemember();
    }

    /**
     * 当前登录用户名
     *
     * @return string
     */
    public static function username(): string
    {
        $auth = session(self::SESSION_KEY);

        return is_array($auth) ? (string) ($auth['username'] ?? '') : '';
    }

    /**
     * 登录校验并写入 Session
     *
     * @param string $username
     * @param string $password
     * @param string $captcha
     * @param bool $remember
     * @return array{code:int,message:string}
     */
    public static function login(string $username, string $password, string $captcha, bool $remember): array
    {
        $conf = \Candy\Extend\Doc\Blog\Config::load();
        $auth = is_array($conf['auth'] ?? null) ? $conf['auth'] : \Candy\Extend\Doc\Blog\Config::defaultAuth();

        if (!empty($auth['captcha'])) {
            if (!function_exists('captcha_verify') || !captcha_verify($captcha)) {
                return ['code' => 1, 'message' => '验证码错误'];
            }
        }

        $expectUser = (string) ($auth['username'] ?? '');
        if ($expectUser === '' || $username !== $expectUser) {
            return ['code' => 1, 'message' => '用户名或密码错误'];
        }

        if (!self::verifyPassword($password, (string) ($auth['password'] ?? ''))) {
            return ['code' => 1, 'message' => '用户名或密码错误'];
        }

        $ttl = $remember ? 86400 * 30 : 86400;
        session(self::SESSION_KEY, [
            'username' => $username,
            'login_at' => time(),
            'expire' => time() + $ttl,
            'remember' => $remember ? 1 : 0,
        ]);

        if ($remember) {
            self::issueRememberCookie($username, $ttl);
        } else {
            self::clearRememberCookie();
        }

        return ['code' => 0, 'message' => '登录成功'];
    }

    /**
     * 退出登录
     *
     * @return void
     */
    public static function logout(): void
    {
        session(self::SESSION_KEY, null);
        self::clearRememberCookie();
    }

    /**
     * 是否启用验证码
     *
     * @return bool
     */
    public static function captchaEnabled(): bool
    {
        $conf = \Candy\Extend\Doc\Blog\Config::load();
        $auth = is_array($conf['auth'] ?? null) ? $conf['auth'] : \Candy\Extend\Doc\Blog\Config::defaultAuth();

        return ($auth['captcha'] ?? true) !== false;
    }

    /**
     * @param string $plain
     * @param string $stored
     * @return bool
     */
    private static function verifyPassword(string $plain, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
            return password_verify($plain, $stored);
        }

        return hash_equals($stored, $plain);
    }

    /**
     * @param string $username
     * @param int $ttl
     * @return void
     */
    private static function issueRememberCookie(string $username, int $ttl): void
    {
        $payload = base64_encode(json_encode([
            'u' => $username,
            'e' => time() + $ttl,
            's' => self::sign($username, time() + $ttl),
        ], JSON_UNESCAPED_UNICODE));
        setcookie(self::REMEMBER_COOKIE, $payload, [
            'expires' => time() + $ttl,
            'path' => '/',
            'httponly' => true,
            'secure' => self::isHttps(),
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @return void
     */
    private static function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => self::isHttps(),
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @return bool
     */
    private static function restoreRemember(): bool
    {
        $raw = (string)cookie(self::REMEMBER_COOKIE);
        if ($raw === '') {
            return false;
        }
        $json = json_decode(base64_decode($raw, true) ?: '', true);
        if (!is_array($json)) {
            return false;
        }
        $user = (string) ($json['u'] ?? '');
        $expire = (int) ($json['e'] ?? 0);
        $sign = (string) ($json['s'] ?? '');
        if ($user === '' || $expire < time() || !hash_equals(self::sign($user, $expire), $sign)) {
            return false;
        }

        session(self::SESSION_KEY, [
            'username' => $user,
            'login_at' => time(),
            'expire' => $expire,
            'remember' => 1,
        ]);

        return true;
    }

    /**
     * @param string $username
     * @param int $expire
     * @return string
     */
    private static function sign(string $username, int $expire): string
    {
        $conf = \Candy\Extend\Doc\Blog\Config::load();
        // 全局随机签名密钥（BlogConfig.auth.sign_secret），不依赖可推导的 content_dir/CANDYPATH，
        // 攻击者无法离线构造合法的 remember cookie。
        $auth = is_array($conf['auth'] ?? null) ? $conf['auth'] : \Candy\Extend\Doc\Blog\Config::defaultAuth();
        $secret = (string) ($auth['sign_secret'] ?? '');
        if ($secret === '') {
            // 老配置缺 sign_secret 时拒绝签发（不退回可推导密钥）
            $secret = hash('sha256', bin2hex(random_bytes(32)));
        }

        return hash_hmac('sha256', $username . '|' . $expire, $secret);
    }

    /**
     * remember cookie 是否设置 secure：生产环境（DEBUG=0）且经 HTTPS 时为 true，
     * 开发环境（DEBUG=1，常为 HTTP）不设，保证本机登录态可用。
     *
     * @return bool
     */
    private static function isHttps(): bool
    {
        // 开发环境（DEBUG=1）强制不设 secure，避免 HTTP 下 cookie 不被浏览器发送
        if (defined('DEBUG') && DEBUG == 1) {
            return false;
        }
        $https = (string) I('server.HTTPS', '', '');

        return $https === '1' || strtolower($https) === 'on';
    }
}
