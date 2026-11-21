<?php
/***
 * MCP 库访问授权 Token（Mcrypt）
 *
 * 业务说明：签发/验签/续签延期/宽限/后台 rearm 一次；tid=0 主库；查数强制 Token
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 16:17:12 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

use Candy\Core\Bootstrap as CoreBootstrap;
use Candy\Core\Cache;
use Candy\Extend\DB\SchemaMeta;
use Candy\Extend\Mcrypt;
use Candy\Extend\Mcp\Bootstrap as McpBootstrap;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Grant
{
	/** 平台主库哨兵 tid */
	public const TID_PLATFORM = 0;

	/** 当前请求已绑定的 tid */
	private static int|string|null $boundTid = null;

	/** 当前绑定 Token 的 exp（unix） */
	private static ?int $boundExp = null;

	/**
	 * 签发库访问授权 Token（人工/Portal/CLI 首次授权）
	 *
	 * @param int|string $tid 0=主库；六位租户 ID
	 * @param list<string> $scopes
	 * @param int|null $ttl
	 * @param int|null $chainStart 续签链起点；null 表示新链
	 * @return array{ok:bool,token?:string,tid?:int|string,exp?:int,jti?:string,ttl?:int,chain_start?:int,error?:string}
	 */
	public static function issue(
		int|string $tid,
		array $scopes = ['db_query'],
		?int $ttl = null,
		?int $chainStart = null
	): array {
		$norm = self::normalizeTid($tid);
		if ($norm === null) {
			return ['ok' => false, 'error' => 'invalid tid (use 0 for platform or 6-char tenant id)'];
		}

		$scopes = self::normalizeScopes($scopes);
		if ($scopes === []) {
			return ['ok' => false, 'error' => 'scopes empty'];
		}

		$key = self::secretKey();
		if ($key === '') {
			return ['ok' => false, 'error' => 'mcp_tenant_grant_key / Mcp.inc grant.key empty'];
		}

		$ttl = self::clampTtl($ttl ?? self::defaultTtl());
		$jti = bin2hex(random_bytes(16));
		$iat = time();
		$exp = $iat + $ttl;
		$chainStart = $chainStart !== null && $chainStart > 0 ? $chainStart : $iat;

		$payload = json_encode([
			'tid'         => $norm,
			'scope'       => $scopes,
			'jti'         => $jti,
			'iat'         => $iat,
			'exp'         => $exp,
			'chain_start' => $chainStart,
		], JSON_UNESCAPED_UNICODE);
		if ($payload === false) {
			return ['ok' => false, 'error' => 'payload encode failed'];
		}

		$token = Mcrypt::code($payload, $key, 'en', $ttl);
		if ($token === '') {
			return ['ok' => false, 'error' => 'encrypt failed'];
		}

		$jtiTtl = max($ttl + 60, self::graceSec() + 120, self::jtiRetainSec());
		try {
			Cache::store()->set(self::jtiCacheKey($jti), [
				'tid'         => $norm,
				'scope'       => $scopes,
				'exp'         => $exp,
				'chain_start' => $chainStart,
			], $jtiTtl);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'jti cache failed: ' . $e->getMessage()];
		}

		return [
			'ok'          => true,
			'token'       => $token,
			'tid'         => $norm,
			'exp'         => $exp,
			'jti'         => $jti,
			'ttl'         => $ttl,
			'chain_start' => $chainStart,
		];
	}

	/**
	 * Agent 续签延期（同 tid；主动在快过期时调用）
	 *
	 * 允许：未过期 | 宽限内 | 后台 rearm 一次
	 *
	 * @param string $token 当前（或刚过期）grant_token
	 * @param int|null $ttl 新 TTL
	 * @return array{ok:bool,token?:string,tid?:int|string,exp?:int,jti?:string,ttl?:int,renewed?:bool,reason?:string,error?:string}
	 */
	public static function renew(string $token, ?int $ttl = null): array
	{
		$parsed = self::parseToken($token, true);
		if (!($parsed['ok'] ?? false)) {
			return ['ok' => false, 'error' => (string) ($parsed['error'] ?? 'invalid grant_token')];
		}

		$tid = $parsed['tid'];
		$jti = (string) $parsed['jti'];
		$exp = (int) $parsed['exp'];
		$scopes = $parsed['scope'] ?? ['db_query'];
		$chainStart = (int) ($parsed['chain_start'] ?? $parsed['iat'] ?? time());
		$now = time();

		$alive = $exp >= $now;
		$inGrace = !$alive && ($exp + self::graceSec()) >= $now;
		$usedRearm = false;

		if (!$alive && !$inGrace) {
			if (!self::consumeRearm($tid)) {
				return [
					'ok'    => false,
					'error' => 'grant_token expired; ask admin to rearm (reset link) once, then renew again',
				];
			}
			$usedRearm = true;
		}

		// 未吊销：jti 仍在缓存，或 rearm/宽限允许
		$cached = self::readJti($jti);
		if ($cached === null && !$usedRearm && !$inGrace) {
			return ['ok' => false, 'error' => 'grant_token revoked or unknown jti'];
		}
		if (is_array($cached) && isset($cached['chain_start'])) {
			$chainStart = (int) $cached['chain_start'];
		}

		$maxChain = self::maxChainSec();
		if ($maxChain > 0 && ($now - $chainStart) > $maxChain) {
			return [
				'ok'    => false,
				'error' => 'grant chain exceeded max lifetime; re-issue from portal/admin required',
			];
		}

		$fresh = self::issue($tid, is_array($scopes) ? $scopes : ['db_query'], $ttl, $chainStart);
		if (!($fresh['ok'] ?? false)) {
			return $fresh;
		}

		// 作废旧 jti（新 jti 已写入）
		self::revoke($jti);

		$reason = $alive ? 'proactive' : ($inGrace ? 'grace' : 'rearm');

		return [
			'ok'          => true,
			'token'       => $fresh['token'],
			'tid'         => $fresh['tid'],
			'exp'         => $fresh['exp'],
			'jti'         => $fresh['jti'],
			'ttl'         => $fresh['ttl'],
			'chain_start' => $fresh['chain_start'],
			'renewed'     => true,
			'reason'      => $reason,
			'note'        => 'Replace grant_token with the new token for subsequent db_* calls. Renew again when grant_should_renew=true.',
		];
	}

	/**
	 * 后台「重置链接」：允许该 tid 在彻底过期后成功续签一次
	 *
	 * @param int|string $tid
	 * @return array{ok:bool,tid?:int|string,error?:string}
	 */
	public static function rearm(int|string $tid): array
	{
		$norm = self::normalizeTid($tid);
		if ($norm === null) {
			return ['ok' => false, 'error' => 'invalid tid'];
		}
		try {
			Cache::store()->set(self::rearmCacheKey($norm), ['armed' => 1, 'at' => time()], self::rearmTtlSec());
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'rearm cache failed: ' . $e->getMessage()];
		}

		return ['ok' => true, 'tid' => $norm];
	}

	/**
	 * 验签（严格：未过期 + jti 有效）
	 *
	 * @param string $token
	 * @return array{ok:bool,tid?:int|string,scope?:list<string>,jti?:string,exp?:int,chain_start?:int,error?:string}
	 */
	public static function verify(string $token): array
	{
		$parsed = self::parseToken($token, false);
		if (!($parsed['ok'] ?? false)) {
			return $parsed;
		}

		$jti = (string) $parsed['jti'];
		if (self::readJti($jti) === null) {
			return ['ok' => false, 'error' => 'grant_token revoked or unknown jti'];
		}

		return $parsed;
	}

	/**
	 * 解析 Token
	 *
	 * @param string $token
	 * @param bool $allowExpired 是否允许已过期（续签 peek）
	 * @return array{ok:bool,tid?:int|string,scope?:list<string>,jti?:string,exp?:int,iat?:int,chain_start?:int,error?:string}
	 */
	public static function parseToken(string $token, bool $allowExpired = false): array
	{
		$token = trim($token);
		if ($token === '') {
			return ['ok' => false, 'error' => 'grant_token required'];
		}

		$key = self::secretKey();
		if ($key === '') {
			return ['ok' => false, 'error' => 'grant key not configured'];
		}

		$plain = Mcrypt::code($token, $key, 'de', 0, $allowExpired);
		if ($plain === '') {
			return ['ok' => false, 'error' => $allowExpired
				? 'grant_token invalid'
				: 'grant_token invalid or expired'];
		}

		$data = json_decode($plain, true);
		if (!is_array($data)) {
			return ['ok' => false, 'error' => 'grant payload invalid'];
		}

		$tid = self::normalizeTid($data['tid'] ?? null);
		if ($tid === null) {
			return ['ok' => false, 'error' => 'grant tid invalid'];
		}

		$jti = trim((string) ($data['jti'] ?? ''));
		if ($jti === '' || !preg_match('/^[a-f0-9]{32}$/', $jti)) {
			return ['ok' => false, 'error' => 'grant jti invalid'];
		}

		$exp = (int) ($data['exp'] ?? 0);
		if (!$allowExpired && $exp > 0 && $exp < time()) {
			return ['ok' => false, 'error' => 'grant_token expired'];
		}

		$scopes = self::normalizeScopes($data['scope'] ?? ['db_query']);
		if (!in_array('db_query', $scopes, true)) {
			return ['ok' => false, 'error' => 'grant scope missing db_query'];
		}

		return [
			'ok'          => true,
			'tid'         => $tid,
			'scope'       => $scopes,
			'jti'         => $jti,
			'exp'         => $exp,
			'iat'         => (int) ($data['iat'] ?? 0),
			'chain_start' => (int) ($data['chain_start'] ?? $data['iat'] ?? 0),
		];
	}

	/**
	 * 吊销 jti
	 *
	 * @param string $jti
	 * @return bool
	 */
	public static function revoke(string $jti): bool
	{
		$jti = trim($jti);
		if ($jti === '') {
			return false;
		}
		try {
			Cache::store()->delete(self::jtiCacheKey($jti));
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 验 Token 并绑定库上下文
	 *
	 * @param string $token
	 * @return array{ok:bool,tid?:int|string,exp?:int,error?:string}
	 */
	public static function bindFromToken(string $token): array
	{
		$v = self::verify($token);
		if (!($v['ok'] ?? false)) {
			return ['ok' => false, 'error' => (string) ($v['error'] ?? 'verify failed')];
		}

		$tid = $v['tid'];
		self::unbind();

		if ($tid === self::TID_PLATFORM || $tid === '0') {
			C('TENANT_ID', null);
			self::$boundTid = self::TID_PLATFORM;
			self::$boundExp = (int) ($v['exp'] ?? 0);
			SchemaMeta::resetProvider();
			return ['ok' => true, 'tid' => self::TID_PLATFORM, 'exp' => self::$boundExp];
		}

		$tidStr = (string) $tid;
		$resolved = resolveTenantDbConfig($tidStr);
		if ($resolved === null) {
			return ['ok' => false, 'error' => 'tenant db config not found for tid=' . $tidStr];
		}
		C('TENANT_ID', $tidStr);
		self::$boundTid = $tidStr;
		self::$boundExp = (int) ($v['exp'] ?? 0);
		SchemaMeta::resetProvider();

		return ['ok' => true, 'tid' => $tidStr, 'exp' => self::$boundExp];
	}

	/**
	 * 清除绑定
	 *
	 * @return void
	 */
	public static function unbind(): void
	{
		C('TENANT_ID', null);
		self::$boundTid = null;
		self::$boundExp = null;
		SchemaMeta::resetProvider();
	}

	/**
	 * @return int|string|null
	 */
	public static function boundTid(): int|string|null
	{
		return self::$boundTid;
	}

	/**
	 * 查数响应附带的续签提示（供 Agent 主动延期）
	 *
	 * @return array{grant_tid:int|string|null,grant_exp:int|null,grant_expires_in:int|null,grant_should_renew:bool,grant_renew_hint:string}
	 */
	public static function renewHint(): array
	{
		$exp = self::$boundExp;
		$expiresIn = null;
		$should = false;
		if ($exp !== null && $exp > 0) {
			$expiresIn = max(0, $exp - time());
			$should = $expiresIn <= self::renewBeforeSec();
		}

		return [
			'grant_tid'           => self::$boundTid,
			'grant_exp'           => $exp,
			'grant_expires_in'    => $expiresIn,
			'grant_should_renew'  => $should,
			'grant_renew_hint'    => $should
				? 'Call mcp_grant_renew with current grant_token NOW to extend before expiry; then use the new token.'
				: 'When grant_should_renew becomes true, call mcp_grant_renew proactively.',
		];
	}

	/**
	 * @return bool
	 */
	public static function isTenantBound(): bool
	{
		return is_string(self::$boundTid) && self::$boundTid !== '' && self::$boundTid !== '0';
	}

	/**
	 * @return object
	 */
	public static function dbDriver(): object
	{
		if (self::isTenantBound()) {
			$db = D('_dbDriver', true);
		} else {
			$db = D('_dbDriver');
		}
		if (!is_object($db) || !method_exists($db, 'query')) {
			throw new \RuntimeException('db driver unavailable');
		}

		return $db;
	}

	/**
	 * @param mixed $raw
	 * @return int|string|null
	 */
	public static function normalizeTid(mixed $raw): int|string|null
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_int($raw) && $raw === 0) {
			return self::TID_PLATFORM;
		}
		if (is_string($raw) || is_int($raw) || is_float($raw)) {
			$s = trim((string) $raw);
			if ($s === '0') {
				return self::TID_PLATFORM;
			}

			return CoreBootstrap::normalizeTenantId($s);
		}

		return null;
	}

	/**
	 * tid 规范化为缓存键后缀
	 *
	 * @param int|string $tid
	 * @return string
	 */
	public static function tidKey(int|string $tid): string
	{
		return $tid === self::TID_PLATFORM || $tid === '0' ? '0' : (string) $tid;
	}

	/**
	 * @param mixed $scopes
	 * @return list<string>
	 */
	private static function normalizeScopes(mixed $scopes): array
	{
		if (!is_array($scopes)) {
			$scopes = ['db_query'];
		}
		$out = [];
		foreach ($scopes as $s) {
			$s = strtolower(trim((string) $s));
			if ($s !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $s)) {
				$out[] = $s;
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * 消费一次性 rearm
	 *
	 * @param int|string $tid
	 * @return bool
	 */
	private static function consumeRearm(int|string $tid): bool
	{
		$key = self::rearmCacheKey($tid);
		try {
			$row = Cache::store()->get($key, null);
			if (!is_array($row) || empty($row['armed'])) {
				return false;
			}
			Cache::store()->delete($key);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @param string $jti
	 * @return array<string, mixed>|null
	 */
	private static function readJti(string $jti): ?array
	{
		try {
			$row = Cache::store()->get(self::jtiCacheKey($jti), null);
		} catch (\Throwable) {
			return null;
		}

		return is_array($row) ? $row : null;
	}

	/**
	 * Secret Key
	 * @return string
	 */
	private static function secretKey(): string
	{
		$cfg = McpBootstrap::config();
		$grant = is_array($cfg['grant'] ?? null) ? $cfg['grant'] : [];
		return trim((string) ($grant['key'] ?? C('mcp_tenant_grant_key') ?? ''));
	}

	/**
	 * Default Ttl
	 * @return int
	 */
	public static function defaultTtl(): int
	{
		$cfg = McpBootstrap::config();
		$grant = is_array($cfg['grant'] ?? null) ? $cfg['grant'] : [];
		$ttl = (int) ($grant['ttl'] ?? 3600);

		return $ttl > 0 ? $ttl : 3600;
	}

	/** 剩余多少秒内应主动续签 */
	public static function renewBeforeSec(): int
	{
		$cfg = McpBootstrap::config();
		$grant = is_array($cfg['grant'] ?? null) ? $cfg['grant'] : [];
		$n = (int) ($grant['renew_before_sec'] ?? 600);

		return $n > 0 ? $n : 600;
	}

	/** 过期后宽限秒数 */
	public static function graceSec(): int
	{
		$cfg = McpBootstrap::config();
		$grant = is_array($cfg['grant'] ?? null) ? $cfg['grant'] : [];
		$n = (int) ($grant['grace_sec'] ?? 300);

		return max(0, $n);
	}

	/** 续签链最长秒数（0=不限制） */
	public static function maxChainSec(): int
	{
		$cfg = McpBootstrap::config();
		$grant = is_array($cfg['grant'] ?? null) ? $cfg['grant'] : [];
		$n = (int) ($grant['max_chain_sec'] ?? 86400);

		return max(0, $n);
	}

	/**
	 * Rearm Ttl Sec
	 * @return int
	 */
	private static function rearmTtlSec(): int
	{
		$cfg = McpBootstrap::config();
		$grant = is_array($cfg['grant'] ?? null) ? $cfg['grant'] : [];
		$n = (int) ($grant['rearm_ttl_sec'] ?? 3600);

		return $n > 0 ? $n : 3600;
	}

	/**
	 * Jti Retain Sec
	 * @return int
	 */
	private static function jtiRetainSec(): int
	{
		return max(self::graceSec() + 600, 1800);
	}

	/**
	 * Clamp Ttl
	 * @param mixed $ttl
	 * @return int
	 */
	private static function clampTtl(int $ttl): int
	{
		if ($ttl < 60) {
			$ttl = 60;
		}
		if ($ttl > 86400) {
			$ttl = 86400;
		}

		return $ttl;
	}

	/**
	 * Jti Cache Key
	 * @param mixed $jti
	 * @return string
	 */
	private static function jtiCacheKey(string $jti): string
	{
		return Cache::sharedKey('mcp:grant:jti:' . $jti);
	}

	/**
	 * @param int|string $tid
	 */
	private static function rearmCacheKey(int|string $tid): string
	{
		return Cache::sharedKey('mcp:grant:rearm:' . self::tidKey($tid));
	}
}

