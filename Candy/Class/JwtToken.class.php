<?php
/***
 * JWT 认证类库
 *
 * 业务说明：Cookie/Header 双模式、单设备登录、CSRF、黑名单、自动刷新
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 14:04:55 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);
namespace Candy\Extend;

use \Candy\Core\Cache;
use \Candy\Core\Request;
use \Firebase\JWT\{JWT,Key,ExpiredException,BeforeValidException,SignatureInvalidException};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class JwtToken {
    /**
     * 验签 Key 实例缓存（Workerman 长驻复用）
     *
     * @var array<string, Key>
     */
    private static array $keyCache = [];

    /**
     * 配置允许的签名算法（与 Firebase 常用对称/非对称对齐）
     *
     * @var list<string>
     */
    private const ALLOWED_ALGORITHMS = [
        'HS256', 'HS384', 'HS512',
        'RS256', 'RS384', 'RS512',
    ];

    /**
     * 访问令牌类型标识
     */
    private const ACCESS_TOKEN = 1;

    /**
     * 刷新令牌类型标识
     */
    private const REFRESH_TOKEN = 2;

    /**
     * Web客户端标识
     */
    public const TOKEN_CLIENT_WEB = 'WEB';

    /**
     * 移动客户端标识
     */
    public const TOKEN_CLIENT_MOBILE = 'MOBILE';

    /**
     * Access Token Cookie 名称
     */
    private const ACCESS_TOKEN_COOKIE = 'access_token';

    /**
     * Refresh Token Cookie 名称
     */
    private const REFRESH_TOKEN_COOKIE = 'refresh_token';

    /**
     * CSRF Token Cookie 名称
     */
    private const CSRF_TOKEN_COOKIE = 'csrf_token';

    /**
     * Redis 黑名单前缀
     */
    private const BLACKLIST_PREFIX = 'auth:blacklist:';

    /**
     * 用户登录（生成 Token 并设置 Cookie）
     *
     * @param array $user 用户信息（必须包含 id 字段）
     * @param string $client 客户端类型（WEB/MOBILE）
     * @param int $accessExp Access Token 过期时间（秒）
     * @param int $refreshExp Refresh Token 过期时间（秒）
     * @param bool $persistent 是否写入持久 Cookie；false 时为会话 Cookie（关闭浏览器失效）
     * @return array 登录响应数据
     */
    public static function login(array $user, string $client = 'WEB', int $accessExp = 0, int $refreshExp = 0, bool $persistent = true): array {
        $config = self::getConfig();
        $accessExp = $accessExp > 0 ? $accessExp : ($config['access_exp'] ?? 7200);
        $refreshExp = $refreshExp > 0 ? $refreshExp : ($config['refresh_exp'] ?? 604800);

        // 构建 JWT extend 字段（可配置用户信息）
        $extend = self::buildUserExtend($user, $client, $accessExp, $refreshExp);

        // 生成 JWT Tokens
        $tokens = self::generateToken($extend);

        // 设置安全 Cookie（非「记住我」时会话级，关浏览器即失效）
        self::setSecureCookie(self::ACCESS_TOKEN_COOKIE, $tokens['access_token'], $accessExp, $persistent);

        if (isset($tokens['refresh_token'])) {
            self::setSecureCookie(self::REFRESH_TOKEN_COOKIE, $tokens['refresh_token'], $refreshExp, $persistent);
        }

        // 生成 CSRF Token
        $csrfToken = self::generateCsrfToken();
        self::setSecureCookie(self::CSRF_TOKEN_COOKIE, $csrfToken, $accessExp, $persistent);

        // 构建返回的用户信息（只返回配置的字段）
        $userFields = $config['user_fields'] ?? ['id', 'username', 'role'];
        $returnUser = [];
        foreach ($userFields as $jwtField => $userField) {
            if (is_numeric($jwtField)) {
                // 简单字段：'username'
                $returnUser[$userField] = $user[$userField] ?? '';
            } else {
                // 别名字段：'nickname' => 'usernickname'
                $returnUser[$jwtField] = $user[$userField] ?? '';
            }
        }

        return [
            'code' => 200,
            'msg' => '登录成功',
            'data' => [
                'user' => $returnUser,
                'csrf_token' => $csrfToken,
                'expires_in' => $accessExp
            ]
        ];
    }

    /**
     * 构建 JWT extend 用户数据
     *
     * @param array $user 用户信息
     * @param string $client 客户端类型
     * @param int $accessExp Access Token 过期时间
     * @param int $refreshExp Refresh Token 过期时间
     * @return array extend 数据
     */
    private static function buildUserExtend(array $user, string $client, int $accessExp, int $refreshExp): array {
        $config = self::getConfig();
        
        // 从配置读取数据库主键字段名（默认 'id'，可配置为 'uid', 'user_id' 等）
        $dbPrimaryKey = $config['user_primary_key'] ?? 'id';
        // JWT 中统一使用的 ID 字段名（建议固定为 'id'，方便代码调用）
        $jwtIdField = $config['user_id_field'] ?? 'id';
        
        // 基础字段（必须）
        $extend = [
            $jwtIdField => $user[$dbPrimaryKey] ?? 0,  // 动态主键映射
            'client' => $client,
            'access_exp' => $accessExp,
            'refresh_exp' => $refreshExp
        ];

        // 从配置读取需要保存的用户字段
        $userFields = $config['user_fields'] ?? [$dbPrimaryKey, 'username', 'role'];
        
        foreach ($userFields as $jwtField => $userField) {
            // 处理字段映射
            if (is_numeric($jwtField)) {
                // 简单字段：'username' 或 'uid'
                $fieldName = $userField;
                $jwtKey = $fieldName;
            } else {
                // 别名字段：'nickname' => 'usernickname'
                $jwtKey = $jwtField;
                $fieldName = $userField;
            }

            // 跳过系统保留字段（避免重复）
            if (in_array($fieldName, ['id', 'uid', 'user_id', 'client', 'access_exp', 'refresh_exp'])) {
                continue;
            }

            // 如果字段名等于数据库主键，且已经映射到 JWT ID 字段，则跳过
            if ($fieldName === $dbPrimaryKey && $jwtKey !== $jwtIdField) {
                continue;
            }

            $extend[$jwtKey] = $user[$fieldName] ?? '';
        }

        return $extend;
    }

    /**
     * 获取当前登录用户 ID
     *
     * @return int 用户 ID
     */
    public static function getCurrentId(): int {
        $extend = self::getTokenExtend();
        
        // 从配置读取 JWT 中的 ID 字段名（默认 'id'）
        $jwtIdField = self::getConfig()['user_id_field'] ?? 'id';
        
        return (int)($extend[$jwtIdField] ?? 0);
    }

    /**
     * 验证认证状态（对外统一接口）
     *
     * @param bool $requireAuth 是否必须登录
     * @return array 验证结果
     */
    public static function auth(bool $requireAuth = true): array {
        // 按 token_mode 统一取 Token（cookie / Bearer Header）
        $accessToken = self::getToken(self::ACCESS_TOKEN);

        // Access 缺失时尝试用 Refresh 续期（Header 模式从 refresh 返回体取新 access）
        if (empty($accessToken) && !empty(self::getToken(self::REFRESH_TOKEN))) {
            $refreshResult = self::refresh();
            if (($refreshResult['code'] ?? 0) === 200) {
                $accessToken = (string)($refreshResult['data']['access_token'] ?? '');
                if ($accessToken === '') {
                    $accessToken = self::getToken(self::ACCESS_TOKEN) ?? '';
                }
            }
        }

        if (empty($accessToken)) {
            if ($requireAuth) {
                return ['code' => 401, 'msg' => '未登录', 'need_login' => true];
            }
            return ['code' => 200, 'authenticated' => false];
        }

        $verifyResult = self::verify(self::ACCESS_TOKEN, $accessToken);

        if ($verifyResult === false) {
            $refreshResult = self::refresh();

            if (($refreshResult['code'] ?? 0) === 200) {
                $accessToken = (string)($refreshResult['data']['access_token'] ?? '');
                if ($accessToken === '') {
                    $accessToken = self::getToken(self::ACCESS_TOKEN) ?? '';
                }
                $verifyResult = self::verify(self::ACCESS_TOKEN, $accessToken);

                if ($verifyResult === false) {
                    return ['code' => 401, 'msg' => '身份验证失败', 'need_login' => true];
                }
            } else {
                self::clearAuthCookies();
                $msg = empty($refreshResult) ? '账号已在其他设备登录' : '登录已过期';
                return ['code' => 401, 'msg' => $msg, 'need_login' => true];
            }
        }

        if (self::isBlacklisted($accessToken)) {
            self::clearAuthCookies();
            return ['code' => 401, 'msg' => '令牌已被吊销，请重新登录', 'need_login' => true];
        }

        // 必须使用本次验签成功的 payload；禁止再走 getUser()/verify() 读请求 Cookie
        // （Workerman 下 refresh 写入后，Request 快照曾未同步，会导致解锁误报未登录）
        $extend = is_array($verifyResult['extend'] ?? null) ? $verifyResult['extend'] : [];
        $jwtIdField = self::getConfig()['user_id_field'] ?? 'id';
        $userId = (int)($extend[$jwtIdField] ?? 0);
        if ($userId <= 0) {
            self::clearAuthCookies();
            return ['code' => 401, 'msg' => '身份验证失败', 'need_login' => true];
        }

        return [
            'code' => 200,
            'authenticated' => true,
            'user_id' => $userId,
            'user' => $extend,
            'payload' => $verifyResult,
        ];
    }

    /**
     * 刷新 Access Token
     *
     * @return array 刷新结果
     */
    public static function refresh(): array {
        // 与 auth 一致：按 token_mode 取 refresh（cookie 或后续扩展）
        if (empty(self::getToken(self::REFRESH_TOKEN))) {
            return ['code' => 401, 'msg' => '缺少刷新令牌'];
        }

        $extend = [];
        $newTokens = self::refreshToken($extend);

        if (empty($newTokens)) {
            return [];
        }

        $userExtend = $extend['extend'] ?? [];
        $config = self::getConfig();
        $accessExp = self::resolveTokenTtl($userExtend, 'access_exp', (int)($config['access_exp'] ?? 7200));
        $refreshExp = self::resolveTokenTtl($userExtend, 'refresh_exp', (int)($config['refresh_exp'] ?? 604800));
        $persistent = self::resolveCookiePersistence($userExtend);

        // Cookie 模式写入；Header 模式客户端读 data.access_token
        self::setSecureCookie(self::ACCESS_TOKEN_COOKIE, $newTokens['access_token'], $accessExp, $persistent);

        if (isset($newTokens['refresh_token'])) {
            self::setSecureCookie(self::REFRESH_TOKEN_COOKIE, $newTokens['refresh_token'], $refreshExp, $persistent);
        }

        $csrfToken = self::generateCsrfToken();
        self::setSecureCookie(self::CSRF_TOKEN_COOKIE, $csrfToken, $accessExp, $persistent);

        $data = [
            'access_token' => $newTokens['access_token'],
            'csrf_token'   => $csrfToken,
            'expires_in'   => $accessExp,
            'token_type'   => 'Bearer',
        ];
        if (isset($newTokens['refresh_token'])) {
            $data['refresh_token'] = $newTokens['refresh_token'];
        }

        return [
            'code' => 200,
            'msg' => '刷新成功',
            'data' => $data,
        ];
    }

    /**
     * 退出登录（注销）
     *
     * @return array 登出结果
     */
    public static function logout(): array {
        try {
            $userId = self::getCurrentId();

            if ($userId > 0) {
                $accessToken = self::getToken(self::ACCESS_TOKEN);
                if (!empty($accessToken)) {
                    self::addToBlacklist($accessToken);
                }

                self::clear();
            }
        } catch (\Exception $e) {
            // 即使出错也要清除 Cookie
        }

        // 清除所有认证 Cookie
        self::clearAuthCookies();

        return ['code' => 200, 'msg' => '登出成功'];
    }

    /**
     * 验证 CSRF Token
     *
     * @param string $csrfToken 请求中的 CSRF Token
     * @return bool 是否验证通过
     */
    public static function verifyCsrf(string $csrfToken = ''): bool {
        if (empty($csrfToken)) {
            $csrfToken = self::getCookie(self::CSRF_TOKEN_COOKIE);
        }

        if (empty($csrfToken)) {
            return false;
        }

        $cache = Cache::store();
        if (!$cache) {
            return false;
        }

        $storedToken = $cache->get('csrf:' . session_id());
        return $storedToken === $csrfToken;
    }
    
    /**
     * 获取当前登录用户详细信息
     *
     * 设计原则：
     * - JWT Token 本身就是通行证，extend 字段已包含用户完整信息（登录时存入）
     * - 验证通过后直接从 Token 解析，不查数据库
     * - 只有 Token 中信息不完整时才查一次数据库
     *
     * @return array 用户信息（从 Token 的 extend 字段直接获取）
     */
    public static function getUser(): array {
        // 从 Token extend 字段获取用户信息
        // Token 在登录时已存入完整用户信息（id, username, nickname, role, email, phone, group 等）
        $extend = self::getTokenExtend();

        // Token 中包含完整用户信息，直接返回
        if (!empty($extend) && isset($extend['id'])) {
            return $extend;
        }

        // 极端情况：Token 中只有 ID，没有完整信息
        // 此时查一次数据库（正常登录流程不应该出现这种情况）
        $id = $extend['id'] ?? 0;
        if ($id > 0) {
            $config = self::getConfig();
            if (is_callable($config['user_model'])) {
                return $config['user_model']($id);
            }
        }

        return [];
    }

    /**
     * 获取 Token 扩展信息
     *
     * @return array 扩展字段
     */
    public static function getExtend(): array {
        return self::getTokenExtend();
    }

    /**
     * 获取 Token 扩展字段值
     *
     * @param string $key 字段名
     * @return mixed 字段值
     */
    public static function getExtendVal(string $key) {
        return self::getTokenExtend()[$key] ?? '';
    }

    // ==================== 内部核心方法 ====================

    /**
     * 验证JWT令牌的有效性（非阻塞版）
     *
     * @param int $tokenType 令牌类型
     * @param string|null $token 要验证的令牌
     * @return array|bool 验证成功返回令牌数据，失败返回 false
     */
    public static function verify(int $tokenType = self::ACCESS_TOKEN, $token = null): array|bool {
        $detail = self::verifyDetailed($tokenType, $token);
        return $detail['ok'] ? ($detail['payload'] ?? false) : false;
    }

    /**
     * 验签详情（区分过期 / 签名错误；对外业务仍优先用 verify）
     *
     * @param int $tokenType 令牌类型
     * @param string|null $token 令牌
     * @return array{ok:bool,payload:?array,error:?string,error_class:?string}
     */
    public static function verifyDetailed(int $tokenType = self::ACCESS_TOKEN, $token = null): array
    {
        $token = $token ?? self::getToken($tokenType);
        if (empty($token)) {
            return ['ok' => false, 'payload' => null, 'error' => 'missing_token', 'error_class' => null];
        }
        try {
            $payload = self::verifyToken($token, $tokenType);
            if ($payload === false) {
                return ['ok' => false, 'payload' => null, 'error' => 'invalid_or_revoked', 'error_class' => null];
            }
            return ['ok' => true, 'payload' => $payload, 'error' => null, 'error_class' => null];
        } catch (ExpiredException $e) {
            return ['ok' => false, 'payload' => null, 'error' => 'expired', 'error_class' => ExpiredException::class];
        } catch (BeforeValidException $e) {
            return ['ok' => false, 'payload' => null, 'error' => 'before_valid', 'error_class' => BeforeValidException::class];
        } catch (SignatureInvalidException $e) {
            return ['ok' => false, 'payload' => null, 'error' => 'bad_signature', 'error_class' => SignatureInvalidException::class];
        } catch (\UnexpectedValueException $e) {
            return ['ok' => false, 'payload' => null, 'error' => 'unexpected', 'error_class' => \UnexpectedValueException::class];
        } catch (\Throwable $e) {
            return ['ok' => false, 'payload' => null, 'error' => $e->getMessage(), 'error_class' => $e::class];
        }
    }

    /**
     * 使用refresh_token刷新access_token
     */
    public static function refreshToken(array &$extend = []): array {
        $token = self::getToken(self::REFRESH_TOKEN);
        if (empty($token)) {
            return [];
        }

        $config = self::getConfig();
        try {
            $extend = self::verifyToken($token, self::REFRESH_TOKEN);
            if (empty($extend['extend'])) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }

        $payload = self::generatePayload($config, $extend['extend']);
        $secretKey = self::getPrivateKey($config);
        $extend['exp'] = time() + $config['access_exp'];
        $newToken['access_token'] = self::makeToken($extend, $secretKey, $config['algorithms']);

        if (!isset($config['refresh_disable']) || ($config['refresh_disable'] === false)) {
            $refreshSecretKey = self::getPrivateKey($config, self::REFRESH_TOKEN);
            $newToken['refresh_token'] = self::makeToken($payload['refreshPayload'], $refreshSecretKey, $config['algorithms']);
        }

        if ($config['is_single_device']) {
            $client = $extend['extend']['client'] ?? self::TOKEN_CLIENT_WEB;
            $userExt = $extend['extend'];
            $accessTtl = self::resolveTokenTtl($userExt, 'access_exp', (int)$config['access_exp']);
            $refreshTtl = self::resolveTokenTtl($userExt, 'refresh_exp', (int)$config['refresh_exp']);
            self::generateTokenToCache($config['cache_token_pre'], (string)$client, (string)$userExt['id'], $accessTtl, $newToken['access_token']);
            if (isset($newToken['refresh_token'])) {
                self::refreshTokenToCache($config["cache_refresh_token_pre"], (string)$client, (string)$userExt['id'], $refreshTtl, $newToken['refresh_token']);
            }
        }
        return $newToken;
    }

    /**
     * 获取令牌有效期剩余时长
     */
    public static function getTokenExp(int $tokenType = self::ACCESS_TOKEN): int {
        $result = self::verify($tokenType);
        if ($result === false) return 0;
        return ($result['exp'] ?? 0) - time();
    }

    /**
     * 生成JWT令牌
     */
    public static function generateToken(array $extend): array {
        if (!isset($extend['id'])) {
            return ['error' => 'Missing user ID'];
        }
        $config = self::getConfig();
        $config['access_exp'] = $extend['access_exp'] ?? $config['access_exp'];
        $config['refresh_exp'] = $extend['refresh_exp'] ?? $config['refresh_exp'];
        $payload = self::generatePayload($config, $extend);
        $secretKey = self::getPrivateKey($config);

        $token = [
            'token_type' => 'Bearer',
            'expires_in' => $config['access_exp'],
            'access_token' => self::makeToken($payload['accessPayload'], $secretKey, $config['algorithms'])
        ];

        if (!isset($config['refresh_disable']) || ($config['refresh_disable'] === false)) {
            $refreshSecretKey = self::getPrivateKey($config, self::REFRESH_TOKEN);
            $token['refresh_token'] = self::makeToken($payload['refreshPayload'], $refreshSecretKey, $config['algorithms']);
        }

        if ($config['is_single_device']) {
            $client = $extend['client'] ?? self::TOKEN_CLIENT_WEB;
            self::generateTokenToCache($config['cache_token_pre'], (string)$client, (string)$extend['id'], $config['access_exp'], $token['access_token']);
            if (!isset($config['refresh_disable']) || ($config['refresh_disable'] === false)) {
                if (isset($config["cache_refresh_token_pre"])) {
                    self::generateTokenToCache($config['cache_refresh_token_pre'], (string)$client, (string)$extend['id'], $config['refresh_exp'], $token['refresh_token']);
                }
            }
        }
        return $token;
    }

    /**
     * 清除用户 Token（单设备模式）
     */
    public static function clear(string $client = self::TOKEN_CLIENT_WEB): bool {
        $config = self::getConfig();
        if ($config['is_single_device']) {
            self::clearTokenToCache($config['cache_refresh_token_pre'], $client, (string)self::getCurrentId());
            self::clearTokenToCache($config['cache_token_pre'], $client, (string)self::getCurrentId());
            return true;
        }
        return true;
    }

    /**
     * 使指定用户的登录 Token 缓存失效（踢下线）
     *
     * 单设备模式下删除 JWT:TOKEN / REFRESH 缓存，旧端下次校验失败
     * 不依赖持有对方 access_token，故不写入黑名单
     *
     * @param int $userId 用户主键
     * @param string $client 客户端标识，默认 WEB
     * @return bool
     */
    public static function invalidateUser(int $userId, string $client = self::TOKEN_CLIENT_WEB): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $config = self::getConfig();
        self::clearTokenToCache($config['cache_refresh_token_pre'] ?? 'JWT:REFRESH_TOKEN:', $client, (string) $userId);
        self::clearTokenToCache($config['cache_token_pre'] ?? 'JWT:TOKEN:', $client, (string) $userId);

        return true;
    }

    // ==================== 私有方法 ====================

    /**
     * 获取令牌中的扩展字段内容
     */
    private static function getTokenExtend(): array {
        $result = self::verify();
        return is_array($result) ? ($result['extend'] ?? []) : [];
    }

    /**
     * 智能获取令牌（兼容 Cookie 和 Header 模式）
     */
    private static function getToken(int $tokenType = self::ACCESS_TOKEN): ?string {
        $config = self::getConfig();
        $mode = $config['token_mode'] ?? 'header';

        // API 客户端显式传 Bearer 时优先使用 Header；Cookie 模式仍作为
        // 浏览器页面的回退。这样同一套认证可同时服务 Portal Web 和 .json API。
        $headerToken = self::getTokenFromHeaders();
        if ($headerToken !== null && $headerToken !== '') {
            return $headerToken;
        }

        if ($mode === 'cookie') {
            $cookieName = ($tokenType === self::REFRESH_TOKEN)
                ? ($config['refresh_cookie_name'] ?? 'refresh_token')
                : ($config['access_cookie_name'] ?? 'access_token');

			return cookie($cookieName);
        }

        return self::getTokenFromHeaders();
    }

    /**
     * 从HTTP请求头获取authorization令牌
     */
    private static function getTokenFromHeaders(): ?string {
        $authorization = '';
        if (isWebServer()) {
            try {
                $request = \Candy\Core\Container::getInstance()->get('Request');
                if ($request && method_exists($request, 'getHeader')) {
                    $authorization = (string)($request->getHeader('authorization') ?? '');
                }
            } catch (\Throwable $e) {
                $authorization = '';
            }
        }
		if (Request::inCoroutine()) {
			$authorization = (string)(R()->header('authorization') ?? R()->header('redirect-http-authorization', ''));
		}
		// Request 未注入或未带该头时经统一请求封装回退。
		if ($authorization === '') {
            $authorization = (string)I('server.HTTP_AUTHORIZATION', I('server.REDIRECT_HTTP_AUTHORIZATION', '', ''), '');
        }

        if ($authorization === '') {
            return null;
        }

        if (stripos($authorization, 'Bearer ') === 0) {
            $token = substr($authorization, 7);
            return $token === '' ? null : $token;
        }

        return null;
    }

    /**
     * 校验令牌的有效性
     *
     * @param string $token JWT
     * @param int $tokenType 类型
     * @return array|bool
     */
    private static function verifyToken(string $token, $tokenType): array|bool {
        $config = self::getConfig();
        $algorithm = self::assertAlgorithm((string)($config['algorithms'] ?? 'HS256'));
        $key = self::getVerifyKey($algorithm, (int)$tokenType);

        try {
            $decoded = JWT::decode($token, $key);
            $decodeToken = self::objectToArray($decoded);
            if (!is_array($decodeToken)) {
                return false;
            }
        } catch (ExpiredException | BeforeValidException | SignatureInvalidException | \UnexpectedValueException $e) {
            // 向上抛给 verifyDetailed；verify() 仍会收成 false
            throw $e;
        } catch (\Exception $e) {
            return false;
        }

        if (!empty($config['is_single_device'])) {
            $cacheTokenPre = $config['cache_token_pre'];
            if ($tokenType == self::REFRESH_TOKEN) {
                $cacheTokenPre = $config['cache_refresh_token_pre'];
            }
            $client = $decodeToken['extend']['client'] ?? self::TOKEN_CLIENT_WEB;
            $uid = (string)($decodeToken['extend']['id'] ?? '');
            if ($uid === '' || self::verifyTokenToCache($cacheTokenPre, $client, $uid, $token) === false) {
                return false;
            }
        }
        return $decodeToken;
    }

    /**
     * Make Token
     * @param mixed $payload
     * @param mixed $secretKey
     * @param mixed $algorithms
     * @return string
     */
    private static function makeToken(array $payload, $secretKey, $algorithms): string {
        $algorithms = self::assertAlgorithm((string)$algorithms);
        return JWT::encode($payload, $secretKey, $algorithms);
    }

    /**
     * Generate Payload
     * @param mixed $config
     * @param mixed $extend
     * @return array
     */
    private static function generatePayload(array $config, $extend): array {
        $basePayload = [
            'iss' => $config['iss'],
            'aud' => $config['iss'],
            'iat' => time(),
            'nbf' => time() + ($config['nbf'] ?? 0),
            'exp' => time() + $config['access_exp'],
            'extend' => $extend
        ];
        $resPayLoad['accessPayload'] = $basePayload;
        $basePayload['exp'] = time() + $config['refresh_exp'];
        $resPayLoad['refreshPayload'] = $basePayload;

        return $resPayLoad;
    }

    /**
     * Get Public Key
     * @return string
     */
    private static function getPublicKey(string $algorithm, $tokenType = self::ACCESS_TOKEN): string {
        $config = self::getConfig();
        $algorithm = self::assertAlgorithm($algorithm);
        $key = match ($algorithm) {
            'HS256', 'HS384', 'HS512' => self::ACCESS_TOKEN == $tokenType
                ? (string)$config['access_secret_key']
                : (string)$config['refresh_secret_key'],
            'RS256', 'RS384', 'RS512' => self::ACCESS_TOKEN == $tokenType
                ? (string)$config['access_public_key']
                : (string)$config['refresh_public_key'],
        };
        if (str_starts_with($algorithm, 'HS')) {
            return self::normalizeHmacKey($key, $algorithm);
        }
        return $key;
    }

    /**
     * Get Private Key
     * @return string
     */
    private static function getPrivateKey(array $config, $tokenType = self::ACCESS_TOKEN): string {
        $algorithm = self::assertAlgorithm((string)($config['algorithms'] ?? 'HS256'));
        $key = match ($algorithm) {
            'HS256', 'HS384', 'HS512' => self::ACCESS_TOKEN == $tokenType
                ? (string)$config['access_secret_key']
                : (string)$config['refresh_secret_key'],
            'RS256', 'RS384', 'RS512' => self::ACCESS_TOKEN == $tokenType
                ? (string)$config['access_private_key']
                : (string)$config['refresh_private_key'],
        };
        if (str_starts_with($algorithm, 'HS')) {
            return self::normalizeHmacKey($key, $algorithm);
        }
        return $key;
    }

    /**
     * 兼容 php-jwt 7+ HMAC 最小密钥长度
     *
     * HS256≥32 字节、HS384≥48、HS512≥64；短密钥用哈希派生，避免配置过短直接抛 DomainException
     * 生产仍建议直接配置足够长的随机密钥
     *
     * @param string $key 原始密钥
     * @param string $algorithm HS*
     * @return string
     */
    private static function normalizeHmacKey(string $key, string $algorithm): string
    {
        $minBytes = match ($algorithm) {
            'HS384' => 48,
            'HS512' => 64,
            default => 32,
        };
        if (strlen($key) >= $minBytes) {
            return $key;
        }
        return match ($algorithm) {
            'HS384' => hash('sha384', $key, true),
            'HS512' => hash('sha512', $key, true),
            default => hash('sha256', $key, true),
        };
    }

    /**
     * 断言算法受支持
     *
     * @param string $algorithm 算法名
     * @return string 规范化大写算法
     */
    private static function assertAlgorithm(string $algorithm): string
    {
        $algorithm = strtoupper(trim($algorithm));
        if (!in_array($algorithm, self::ALLOWED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(
                'Unsupported JWT algorithm: ' . $algorithm . ' (allowed: ' . implode(',', self::ALLOWED_ALGORITHMS) . ')'
            );
        }
        return $algorithm;
    }

    /**
     * 获取验签 Key（带缓存）
     *
     * @param string $algorithm 算法
     * @param int $tokenType access/refresh
     * @return Key
     */
    private static function getVerifyKey(string $algorithm, int $tokenType): Key
    {
        $material = self::getPublicKey($algorithm, $tokenType);
        $cacheKey = $tokenType . ':' . $algorithm . ':' . hash('sha256', $material);
        return self::$keyCache[$cacheKey] ??= new Key($material, $algorithm);
    }

    /**
     * stdClass / 嵌套结构转关联数组（替代 json_encode/decode）
     *
     * @param mixed $data 解码结果
     * @return mixed
     */
    private static function objectToArray(mixed $data): mixed
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            $data[$k] = self::objectToArray($v);
        }
        return $data;
    }

    /**
     * Get Config
     * @return array
     */
    private static function getConfig(): array {
        static $config = null;
        if ($config === null) {
            $config = loadConfig('Jwt') ?? [];
            // leeway 只在配置首次加载时写入，避免每请求改全局静态
            JWT::$leeway = (int)($config['leeway'] ?? 60);
        }
        return $config;
    }

    /**
     * Generate Token To Cache
     * @param mixed $pre
     * @param mixed $client
     * @param mixed $uid
     * @param mixed $ttl
     * @param mixed $token
     */
    private static function generateTokenToCache(string $pre, $client, $uid, $ttl, $token): void {
        $cache = Cache::store();
        if ($cache) {
            $cacheKey = $pre . $client . ':' . $uid;
            $cache->delete($cacheKey);
            $cache->set($cacheKey, $token, $ttl);
        }
    }

    /**
     * Refresh Token To Cache
     * @param mixed $pre
     * @param mixed $client
     * @param mixed $uid
     * @param mixed $ttl
     * @param mixed $token
     */
    private static function refreshTokenToCache(string $pre, $client, $uid, $ttl, $token): void {
        $cache = Cache::store();
        if ($cache) {
            $cacheKey = $pre . $client . ':' . $uid;
            $isExists = $cache->has($cacheKey);
            if ($isExists) {
                $ttltmp = $cache->ttl($cacheKey);
                $ttl = $ttltmp > 0 ? $ttltmp : $ttl;
            }
            $cache->set($cacheKey, $token, $ttl);
        }
    }

    /**
     * Verify Token To Cache
     * @param mixed $pre
     * @param mixed $client
     * @param mixed $uid
     * @param mixed $token
     * @return bool
     */
    private static function verifyTokenToCache(string $pre, $client, $uid, $token): bool {
        $cache = Cache::store();
        if (!$cache) {
            return true; // 无缓存，跳过验证
        }
        $cacheKey = $pre . $client. ':'. $uid;
        $isExists = $cache->has($cacheKey);
        if ($isExists === false) {
            return false;
        }
        if ($cache->get($cacheKey) != $token) {
            return false;
        }
        return true;
    }

    /**
     * Clear Token To Cache
     * @param mixed $pre
     * @param mixed $client
     * @param mixed $uid
     * @return bool
     */
    private static function clearTokenToCache(string $pre, $client, $uid): bool {
        $cache = Cache::store();
        if ($cache) {
            $cache->delete($pre . $client. ':'. $uid);
        }
        return true;
    }

    /**
     * 设置安全 Cookie
     *
     * @param string $name Cookie 名
     * @param string $value Cookie 值
     * @param int $maxAge 有效秒数（仅持久 Cookie 使用）
     * @param bool $persistent true=持久 Cookie；false=会话 Cookie（关闭浏览器失效）
     */
    private static function setSecureCookie(string $name, string $value, int $maxAge, bool $persistent = true): void {
        $config = self::getCookieConfig();

        $options = [
            'path' => $config['path'] ?? '/',
            'domain' => $config['domain'] ?? '',
            'secure' => $config['secure'] ?? true,
            'httponly' => true,
            'samesite' => $config['samesite'] ?? 'Lax',
			'expires_at' => $persistent ? (time() + $maxAge) : 0,
        ];

		cookie($name, $value, $maxAge, (string)$options['path'], (string)$options['domain'], $options);
    }

    /**
     * 获取 Cookie 值
     */
	private static function getCookie(string $name): ?string {
		return cookie($name);
    }

    /**
     * 清除认证 Cookie
     */
    private static function clearAuthCookies(): void {
        $cookies = [self::ACCESS_TOKEN_COOKIE, self::REFRESH_TOKEN_COOKIE, self::CSRF_TOKEN_COOKIE];
        $config = self::getCookieConfig();

        foreach ($cookies as $name) {
            // 删除 Cookie 必须与写入时使用相同的协议属性；HTTP 演示环境若硬编码
            // Secure=true，浏览器会忽略删除响应，导致退出后仍携带旧租户令牌。
			cookie($name, null, 0, (string)($config['path'] ?? '/'), (string)($config['domain'] ?? ''), [
				'secure' => (bool)($config['secure'] ?? false),
				'httponly' => true,
				'samesite' => (string)($config['samesite'] ?? 'Lax'),
			]);
        }
    }

    /**
     * 生成 CSRF Token
     */
    private static function generateCsrfToken(): string {
        $token = bin2hex(random_bytes(32));
        $cache = Cache::store();
        if ($cache) {
            $cache->set('csrf:' . session_id(), $token, 3600);
        }
        return $token;
    }

    /**
     * 将 Token 加入黑名单
     */
    private static function addToBlacklist(string $token): void {
        $cache = Cache::store();
        if (!$cache) {
            return;
        }
        $ttl = 7200;
        try {
            $parts = explode('.', $token);
            if (count($parts) >= 2) {
                $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[1]));
                if (is_object($payload) && isset($payload->exp)) {
                    $ttl = (int)$payload->exp - time();
                } elseif (is_array($payload) && isset($payload['exp'])) {
                    $ttl = (int)$payload['exp'] - time();
                }
            }
        } catch (\Throwable $e) {
            $ttl = 7200;
        }
        if ($ttl > 0) {
            $cache->set(self::BLACKLIST_PREFIX . md5($token), 1, $ttl);
        }
    }

    /**
     * 检查 Token 是否在黑名单中
     */
    private static function isBlacklisted(string $token): bool {
        $cache = Cache::store();
        if ($cache) {
            return $cache->has(self::BLACKLIST_PREFIX . md5($token));
        }
        return false;
    }

    /**
     * 获取 Cookie 配置
     */
	private static function getCookieConfig(): array {
		$server = I('server.', [], '');
		return [
            'path' => '/',
            'domain' => '',
			'secure' => isset($server['HTTPS']) && $server['HTTPS'] === 'on',
            'samesite' => 'Lax'
        ];
    }

    /**
     * 根据 Token extend 判断是否使用持久 Cookie
     *
     * remember=1 时跨浏览器会话保持登录，否则为会话 Cookie
     *
     * @param array $userExtend JWT extend 用户字段
     * @return bool
     */
    private static function resolveCookiePersistence(array $userExtend): bool {
        return (int)($userExtend['remember'] ?? 0) === 1;
    }

    /**
     * 从 Token extend 读取有效期，回退到配置默认值
     *
     * @param array $userExtend JWT extend 用户字段
     * @param string $key access_exp 或 refresh_exp
     * @param int $default 默认秒数
     * @return int
     */
    private static function resolveTokenTtl(array $userExtend, string $key, int $default): int {
        $ttl = (int)($userExtend[$key] ?? 0);
        return $ttl > 0 ? $ttl : $default;
    }
}
