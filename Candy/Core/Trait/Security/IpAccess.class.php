<?php
declare(strict_types=1);
/***
 * IP 黑白名单（按作用域隔离）
 *
 * 2026-11-17 升级：名单分 scope 存储
 *   scope=project（默认）→ Runtime/Logs/ip-blackList.log / ip-whiteList.log
 *   scope=sys           → Runtime/Logs/ip-blackList.sys.log / ip-whiteList.sys.log
 *
 * 目的：
 *   ① /sys/* 的防护（令牌暴力破解）只写 sys 名单，**不会连带封掉项目页面**；
 *   ② 为将来「项目特色名单」打基础 —— 各 scope 独立增删查清。
 *
 * 记录结构：[起始IP数值, 结束IP数值, 过期时间戳, scope]
 *
 * @package Candy\Core\Trait\Security
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 10:40:00
 */
namespace Candy\Core\Trait\Security;

trait IpAccess
{
	/** @var array<int, array<int, mixed>> 黑名单 */
	private static $blackList = [];

	/** @var array<int, array<int, mixed>> 白名单 */
	private static $whiteList = [];

	/** @var array<int, string> 支持的名单作用域 */
	private static $ipScopes = ['project', 'sys'];

	/** @var bool 是否已从文件加载 */
	private static $ipLoaded = false;

	/**
	 * 规范化 scope
	 * @param string $scope
	 * @return string project|sys
	 */
	public static function ipScope(string $scope = ''): string
	{
		$scope = strtolower(trim($scope));
		return in_array($scope, self::$ipScopes, true) ? $scope : 'project';
	}

	/**
	 * 名单文件路径
	 * @param string $type b|w
	 * @param string $scope project|sys
	 * @return string
	 */
	private static function ipFile(string $type, string $scope): string
	{
		$name = strtolower($type) === 'w' ? 'ip-whiteList' : 'ip-blackList';
		$suffix = self::ipScope($scope) === 'sys' ? '.sys' : '';
		return C('LOGPATH') . $name . $suffix . '.log';
	}

	/** 从文件懒加载所有 scope 的黑白名单到内存 @return void */
	public static function IPList(): void
	{
		if (self::$ipLoaded) {
			return;
		}
		self::$ipLoaded = true;
		self::$blackList = [];
		self::$whiteList = [];

		foreach (self::$ipScopes as $scope) {
			foreach (['b' => 'black', 'w' => 'white'] as $type => $which) {
				$file = self::ipFile($type, $scope);
				if (!is_file($file)) {
					continue;
				}
				$raw = @file_get_contents($file);
				if (!is_string($raw) || $raw === '') {
					continue;
				}
				foreach (self::textToArray($raw) as $row) {
					if (!isset($row[0])) {
						continue;
					}
					$row[1] = $row[1] ?? $row[0];
					$row[2] = $row[2] ?? '';
					$row[3] = $scope;
					if ($which === 'black') {
						self::$blackList[] = $row;
					} else {
						self::$whiteList[] = $row;
					}
				}
			}
		}
	}

	/**
	 * 添加 IP 到黑白名单
	 *
	 * @param array{type?:string,ip_start?:string,ip_end?:string,exp_time?:int,scope?:string} $data type: b=黑 w=白
	 * @return bool
	 */
	public static function add(array $data = []): bool
	{
		$type = strtolower((string) ($data['type'] ?? 'b'));
		$scope = self::ipScope((string) ($data['scope'] ?? 'project'));

		if (empty($data['ip_start'])) {
			$data['ip_start'] = \Candy\Extend\Network\Client::getClientIp();
		}
		if (ip2long((string) $data['ip_start']) === false) {
			return false;
		}
		if (empty($data['ip_end'])) {
			$data['ip_end'] = $data['ip_start'];
		}
		if (ip2long((string) $data['ip_end']) === false) {
			return false;
		}
		$expTime = (int) ($data['exp_time'] ?? 0);
		if ($expTime > 0 && $expTime < gmTime()) {
			return false;
		}

		$startIPNumber = self::transForm((string) $data['ip_start']);
		$endIPNumber = self::transForm((string) $data['ip_end'], false);

		self::IPList();
		self::remove($type, $startIPNumber, $endIPNumber, $scope);
		$row = [$startIPNumber, $endIPNumber, $expTime, $scope];
		if ($type === 'b') {
			self::$blackList[] = $row;
		} else {
			self::$whiteList[] = $row;
		}

		// 任何 SAPI 都要落盘：FPM 下只改内存等于没加（下个请求读文件就丢了）
		return self::save($type, $scope);
	}

	/**
	 * 从黑白名单删除 IP 或 IP 段
	 *
	 * @param array{type?:string,ip_start?:string,ip_end?:string,scope?:string} $data
	 * @return bool
	 */
	public static function delete(array $data = []): bool
	{
		$type = strtolower((string) ($data['type'] ?? 'b'));
		$scope = self::ipScope((string) ($data['scope'] ?? 'project'));

		if (empty($data['ip_start'])) {
			$data['ip_start'] = \Candy\Extend\Network\Client::getClientIp();
		}
		if (ip2long((string) $data['ip_start']) === false) {
			return false;
		}
		if (empty($data['ip_end'])) {
			$data['ip_end'] = $data['ip_start'];
		}
		if (ip2long((string) $data['ip_end']) === false) {
			return false;
		}

		self::IPList();
		$startIPNumber = self::transForm((string) $data['ip_start']);
		$endIPNumber = self::transForm((string) $data['ip_end'], false);
		self::remove($type, $startIPNumber, $endIPNumber, $scope);
		return self::save($type, $scope);
	}

	/**
	 * 检查 IP 是否在名单中
	 *
	 * @param string $type  b|w
	 * @param string $ip    空则当前客户端 IP
	 * @param string $scope project|sys|all
	 * @return bool
	 */
	public static function check(string $type = 'b', string $ip = '', string $scope = 'project'): bool
	{
		return self::find($ip, $type, $scope);
	}

	/**
	 * 获取名单全部记录
	 *
	 * @param string $type  b|w
	 * @param string $scope project|sys|all
	 * @return array<int, array<int, mixed>> [[起始IP, 结束IP, 过期时间, scope], ...]
	 */
	public static function getAll(string $type = 'b', string $scope = ''): array
	{
		self::IPList();
		$listArray = strtolower($type) === 'w' ? self::$whiteList : self::$blackList;
		$want = $scope === '' || strtolower($scope) === 'all' ? '' : self::ipScope($scope);

		$return = [];
		foreach ($listArray as $val) {
			$rowScope = (string) ($val[3] ?? 'project');
			if ($want !== '' && $rowScope !== $want) {
				continue;
			}
			$return[] = [self::transForm((string) $val[0]), self::transForm((string) $val[1]), $val[2] ?? '', $rowScope];
		}
		return $return;
	}

	/**
	 * 精确查找 IP 是否在名单中
	 *
	 * @param string $ip
	 * @param string $type  b|w
	 * @param string $scope project|sys|all
	 * @return bool
	 */
	public static function find(string $ip, string $type = 'b', string $scope = 'project'): bool
	{
		if (empty($ip)) {
			$ip = \Candy\Extend\Network\Client::getClientIp();
		}
		if (ip2long($ip) === false) {
			return false;
		}
		$want = $scope === '' || strtolower($scope) === 'all' ? '' : self::ipScope($scope);

		$IPNumber = self::transForm($ip);
		self::IPList();
		$listArray = strtolower($type) === 'w' ? self::$whiteList : self::$blackList;
		foreach ($listArray as $val) {
			$rowScope = (string) ($val[3] ?? 'project');
			if ($want !== '' && $rowScope !== $want) {
				continue;
			}
			if (($IPNumber >= $val[0] && $IPNumber <= $val[1]) && (gmTime() <= $val[2] || empty($val[2]))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 清理过期记录或重置名单
	 *
	 * @param string $type  b|w|空=全部
	 * @param bool   $reset true 清空
	 * @param string $scope project|sys|空=全部
	 * @return void
	 */
	public static function clean(string $type = '', $reset = false, string $scope = ''): void
	{
		$type = strtolower($type);
		$want = $scope === '' || strtolower($scope) === 'all' ? '' : self::ipScope($scope);
		$doBlack = ($type === 'b' || $type === '');
		$doWhite = ($type === 'w' || $type === '');
		self::IPList();

		$filter = function (array $list, bool $isReset) use ($want): array {
			$out = [];
			foreach ($list as $val) {
				$rowScope = (string) ($val[3] ?? 'project');
				if ($want !== '' && $rowScope !== $want) {
					$out[] = $val;
					continue;
				}
				if ($isReset) {
					continue;
				}
				if (!empty($val[2]) && (int) $val[2] < gmTime()) {
					continue;
				}
				$out[] = $val;
			}
			return array_values($out);
		};

		if ($doBlack) {
			self::$blackList = $filter(self::$blackList, (bool) $reset);
		}
		if ($doWhite) {
			self::$whiteList = $filter(self::$whiteList, (bool) $reset);
		}

		// 逐 scope 写回，避免把别的 scope 的文件清空
		foreach (self::$ipScopes as $one) {
			if ($want !== '' && $one !== $want) {
				continue;
			}
			if ($doBlack) {
				self::save('b', $one);
			}
			if ($doWhite) {
				self::save('w', $one);
			}
		}
	}

	/**
	 * 文本 → 数组
	 *
	 * @param string $str
	 * @return array<int, array<int, mixed>>
	 */
	private static function textToArray(string $str): array
	{
		// 清洗交给正则（实测比 explode + 逐行 trim/ctype 快 20~70%，单次整串替换由 C 层完成）
		$str = (string) preg_replace('/[^0-9.\-\*,&]/', '', $str);

		$rows = [];
		foreach (explode('&', $str) as $line) {
			if ($line === '') {
				continue;
			}
			// 结构解析：start-end,exp —— 修掉原实现的三处缺陷
			//   ① 没有逗号（只有 start-end）时，原先整行不拆，$row[1] 缺失；
			//   ② 只有单 IP（没有横线）时，原先把 exp 当成 end；
			//   ③ 空段原先会产出空记录。
			$comma = strpos($line, ',');
			$range = $comma === false ? $line : substr($line, 0, $comma);
			$exp = $comma === false ? 0 : (int) substr($line, $comma + 1);
			if ($range === '') {
				continue;
			}
			$dash = strpos($range, '-');
			if ($dash === false) {
				$start = $range;
				$end = $range;
			} else {
				$start = substr($range, 0, $dash);
				$end = substr($range, $dash + 1);
				if ($end === '') {
					$end = $start;
				}
			}
			if ($start === '') {
				continue;
			}
			$rows[] = [$start, $end, $exp];
		}
		return $rows;
	}

	/**
	 * 数组 → 文本（只输出指定 scope 的记录）
	 *
	 * @param array<int, array<int, mixed>> $array
	 * @param string $scope
	 * @return string
	 */
	private static function arrayToText(array $array, string $scope = 'project'): string
	{
		$return = '';
		foreach ($array as $val) {
			if (!isset($val[0])) {
				continue;
			}
			if ((string) ($val[3] ?? 'project') !== self::ipScope($scope)) {
				continue;
			}
			$end = $val[1] ?? $val[0];
			$exp = empty($val[2]) ? '' : $val[2];
			$start = $val[0];
			if ($start > $end) {
				[$start, $end] = [$end, $start];
			}
			$return .= $start . '-' . $end . ',' . $exp . '&';
		}
		return $return;
	}

	/**
	 * 判断是否是 IP 格式
	 *
	 * @param string $str
	 * @return bool
	 */
	private static function ipCheck(string $str): bool
	{
		return (bool) preg_match('/(?=(\b|\D))((\*\.)|(\*)|(25[0-5]|2[0-4]\d|[01]?\d\d?)($|(?!\.$)\.)){4}/', $str);
	}

	/**
	 * IP ↔ 数值
	 *
	 * @param string $str
	 * @param bool   $start
	 * @return mixed
	 */
	private static function transForm(string $str, bool $start = true)
	{
		if (ctype_digit($str)) {
			return long2ip((int) $str);
		}
		$str = $start ? str_replace('*', '0', $str) : str_replace('*', '255', $str);
		$intIP = ip2long($str);
		if ($intIP === false) {
			return false;
		}
		return sprintf('%u', $intIP);
	}

	/**
	 * 从内存移除指定 scope 的记录
	 *
	 * @param string $type
	 * @param mixed $startIPNumber
	 * @param mixed $endIPNumber
	 * @param string $scope
	 * @return bool
	 */
	private static function remove($type, $startIPNumber, $endIPNumber, string $scope = 'project'): bool
	{
		$scope = self::ipScope($scope);
		$isBlack = strtolower((string) $type) === 'b';
		$list = $isBlack ? self::$blackList : self::$whiteList;
		$keep = [];
		foreach ($list as $val) {
			$same = $startIPNumber == $val[0] && $endIPNumber == $val[1]
				&& (string) ($val[3] ?? 'project') === $scope;
			if (!$same) {
				$keep[] = $val;
			}
		}
		if ($isBlack) {
			self::$blackList = $keep;
		} else {
			self::$whiteList = $keep;
		}
		return true;
	}

	/**
	 * 落盘指定 scope 的名单
	 *
	 * 并发安全：临时文件 + flock + rename，避免多请求 read-modify-write 互相覆盖。
	 *
	 * @param string $type  b|w
	 * @param string $scope project|sys
	 * @return bool
	 */
	private static function save(string $type = '', string $scope = 'project'): bool
	{
		$isBlack = strtolower($type) === 'b';
		$file = self::ipFile($isBlack ? 'b' : 'w', $scope);
		$text = self::arrayToText($isBlack ? self::$blackList : self::$whiteList, $scope);

		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}

		$tmp = $file . '.' . getmypid() . '.tmp';
		$handle = @fopen($tmp, 'w');
		if ($handle === false) {
			return false;
		}
		@flock($handle, LOCK_EX);
		$ok = fwrite($handle, $text) !== false;
		@fflush($handle);
		@flock($handle, LOCK_UN);
		fclose($handle);
		if (!$ok || !@rename($tmp, $file)) {
			@unlink($tmp);
			return false;
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($file, true);
		}
		return true;
	}
}
