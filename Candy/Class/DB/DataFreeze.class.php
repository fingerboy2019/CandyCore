<?php
/***
 * 热 / 冷 / 冻 数据分层与年度冻结服务
 *
 * 业务说明：通用表热→冷迁移、往年打包出库、冷热合并查询、审计解冻
 * 与 DBService（整库备份）并列；IM 及其它业务表均可复用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 09:20:00 $
 *
 * @package Candy\Extend\DB
 */
declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 框架级数据冷冻能力
 *
 * 热保留最近 H 个自然月（H&lt;12）；溢出进冷表；冷表中 year≤今年-offset 整年 pack 后移出
 * 在线查询只合并热+冷；冻包仅 thaw 审计使用
 */
final class DataFreeze
{
	/** 冻包格式版本 */
	public const PACK_VERSION = 1;

	/**
	 * 一次生命周期 tick：热→冷，再冻往年
	 *
	 * @param array $opts 见规范 CandyImStorageDesign §11
	 * @return array{code:int,message:string,moved?:int,frozen?:array,errors?:array}
	 * @access public
	 * @static
	 */
	public static function runTier(array $opts): array
	{
		$hotMonths = (int)($opts['hot_months'] ?? 3);
		$offset = (int)($opts['freeze_year_offset'] ?? 2);
		if ($hotMonths < 1 || $hotMonths >= 12) {
			return self::fail('hot_months 须满足 1 ≤ H < 12');
		}
		if ($offset < 1) {
			return self::fail('freeze_year_offset 至少为 1');
		}

		$move = self::moveHotToCold($opts);
		if ((int)($move['code'] ?? 1) !== 0) {
			return $move;
		}

		$coldTable = (string)($opts['cold_table'] ?? '');
		$timeColumn = (string)($opts['time_column'] ?? 'createtime');
		if ($coldTable === '') {
			return self::fail('cold_table 必填');
		}

		$years = self::listColdYears($opts);
		if ((int)($years['code'] ?? 1) !== 0) {
			return $years;
		}

		$currentYear = (int)date('Y');
		$maxFreezeYear = $currentYear - $offset;
		$frozen = [];
		$errors = [];
		foreach ((array)($years['years'] ?? []) as $year) {
			$year = (int)$year;
			if ($year > $maxFreezeYear) {
				continue;
			}
			$pack = self::pack(array_merge($opts, [
				'table' => $coldTable,
				'time_column' => $timeColumn,
				'year' => $year,
			]));
			if ((int)($pack['code'] ?? 1) !== 0) {
				$errors[] = ['year' => $year, 'step' => 'pack', 'message' => $pack['message'] ?? 'pack failed'];
				continue;
			}
			$purge = self::purge(array_merge($opts, [
				'table' => $coldTable,
				'time_column' => $timeColumn,
				'year' => $year,
				'require_checksum' => true,
				'pack_path' => $pack['path'] ?? '',
				'checksum' => $pack['checksum'] ?? '',
			]));
			if ((int)($purge['code'] ?? 1) !== 0) {
				$errors[] = ['year' => $year, 'step' => 'purge', 'message' => $purge['message'] ?? 'purge failed'];
				continue;
			}
			$frozen[] = [
				'year' => $year,
				'path' => $pack['path'] ?? '',
				'count' => $pack['count'] ?? 0,
				'checksum' => $pack['checksum'] ?? '',
			];
		}

		return [
			'code' => $errors === [] ? 0 : 1,
			'message' => $errors === [] ? 'ok' : '部分年份冻结失败',
			'moved' => (int)($move['moved'] ?? 0),
			'frozen' => $frozen,
			'errors' => $errors,
		];
	}

	/**
	 * 热表溢出迁入冷表
	 *
	 * 保留最近 hot_months 个自然月；更早行分批插入冷表后删除热表
	 *
	 * @param array $opts hot_table、cold_table、time_column、hot_months、batch_size、alias、pk、archived_at_column
	 * @return array{code:int,message:string,moved?:int,cutoff?:string}
	 * @access public
	 * @static
	 */
	public static function moveHotToCold(array $opts): array
	{
		$hot = self::resolveTable((string)($opts['hot_table'] ?? ''));
		$cold = self::resolveTable((string)($opts['cold_table'] ?? ''));
		$timeCol = self::safeIdent((string)($opts['time_column'] ?? 'createtime'));
		$pk = self::safeIdent((string)($opts['pk'] ?? 'id'));
		$hotMonths = (int)($opts['hot_months'] ?? 3);
		$batch = max(100, (int)($opts['batch_size'] ?? 1000));
		$archivedCol = '';
		if (!empty($opts['set_archived_at'])) {
			$archivedCol = isset($opts['archived_at_column'])
				? self::safeIdent((string)$opts['archived_at_column'])
				: 'archived_at';
		}

		if ($hot === '' || $cold === '') {
			return self::fail('hot_table / cold_table 必填');
		}
		if ($hotMonths < 1 || $hotMonths >= 12) {
			return self::fail('hot_months 须满足 1 ≤ H < 12');
		}

		$db = self::db($opts);

		$cutoff = self::hotWindowStart($hotMonths);
		$moved = 0;
		$now = date('Y-m-d H:i:s');

		while (true) {
			$rows = self::prepareDriverTable($db, $hot)
				->where($timeCol, '<', $cutoff)
				->order($pk . ' ASC')
				->limit(0, $batch)
				->select();
			if (!is_array($rows) || $rows === []) {
				break;
			}
			foreach ($rows as $row) {
				if (!is_array($row) || $row === []) {
					continue;
				}
				$row = self::normalizeRow($row);
				if ($archivedCol !== '' && !array_key_exists($archivedCol, $row)) {
					$row[$archivedCol] = $now;
				}
				$id = $row[$pk] ?? null;
				if ($id === null) {
					return self::fail('热表行缺少主键 ' . $pk);
				}
				// 上次任务可能已写冷表、但在删除热表前中断；先查主键可安全续跑。
				$exists = self::prepareDriverTable($db, $cold)
					->where($pk, '=', $id)
					->find();
				if (!is_array($exists) || $exists === []) {
					$ok = self::insertRow($db, $cold, $row);
					if (!$ok) {
						return self::fail('写入冷表失败，已迁移 ' . $moved . ' 行');
					}
				}
				$del = self::prepareDriverTable($db, $hot)
					->where($pk, '=', $id)
					->delete(true);
				if ($del === false || $del === null) {
					return self::fail('删除热表行失败 id=' . (string)$id);
				}
				$moved++;
			}
			if (count($rows) < $batch) {
				break;
			}
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'moved' => $moved,
			'cutoff' => $cutoff,
		];
	}

	/**
	 * 将冷表某自然年数据打包出库（不删库；runTier 内会接 purge）
	 *
	 * @param array $opts table、year、time_column、pack_dir、compress、meta_table、alias、pk、batch_size
	 * @return array{code:int,message:string,path?:string,checksum?:string,count?:int,id_min?:mixed,id_max?:mixed,year?:int}
	 * @access public
	 * @static
	 */
	public static function pack(array $opts): array
	{
		$table = self::resolveTable((string)($opts['table'] ?? $opts['cold_table'] ?? ''));
		$timeCol = self::safeIdent((string)($opts['time_column'] ?? 'createtime'));
		$pk = self::safeIdent((string)($opts['pk'] ?? 'id'));
		$year = (int)($opts['year'] ?? 0);
		$batch = max(100, (int)($opts['batch_size'] ?? 1000));
		$compress = array_key_exists('compress', $opts) ? (bool)$opts['compress'] : true;

		if ($table === '' || $year < 1970) {
			return self::fail('table 与合法 year 必填');
		}

		[$from, $to] = self::yearRange($year);
		$packRoot = self::resolvePackDir($opts, $year);
		if (!self::ensureDir($packRoot)) {
			return self::fail('无法创建冻包目录：' . $packRoot);
		}

		$dataName = $compress ? 'data.jsonl.gz' : 'data.jsonl';
		$dataPath = rtrim($packRoot, '/\\') . DIRECTORY_SEPARATOR . $dataName;
		$manifestPath = rtrim($packRoot, '/\\') . DIRECTORY_SEPARATOR . 'manifest.json';

		$db = self::db($opts);

		$fp = $compress
			? @gzopen($dataPath, 'wb9')
			: @fopen($dataPath, 'wb');
		if ($fp === false || $fp === null) {
			return self::fail('无法写入数据文件：' . $dataPath);
		}

		$count = 0;
		$idMin = null;
		$idMax = null;
		$lastId = null;
		$writeOk = true;

		try {
			while (true) {
				$query = self::prepareDriverTable($db, $table)
					->where($timeCol, '>=', $from)
					->where($timeCol, '<', $to);
				if ($lastId !== null) {
					$query->where($pk, '>', $lastId);
				}
				$rows = $query
					->order($pk . ' ASC')
					->limit(0, $batch)
					->select();
				if (!is_array($rows) || $rows === []) {
					break;
				}
				foreach ($rows as $row) {
					if (!is_array($row)) {
						continue;
					}
					$row = self::normalizeRow($row);
					$line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					if ($line === false) {
						$writeOk = false;
						break 2;
					}
					$line .= "\n";
					$w = $compress ? gzwrite($fp, $line) : fwrite($fp, $line);
					if ($w === false) {
						$writeOk = false;
						break 2;
					}
					$count++;
					$id = $row[$pk] ?? null;
					if ($id !== null) {
						$idMin = $idMin === null ? $id : (self::cmpId($id, $idMin) < 0 ? $id : $idMin);
						$idMax = $idMax === null ? $id : (self::cmpId($id, $idMax) > 0 ? $id : $idMax);
						$lastId = $id;
					}
				}
				if (count($rows) < $batch) {
					break;
				}
			}
		} finally {
			if ($compress) {
				@gzclose($fp);
			} else {
				@fclose($fp);
			}
		}

		if (!$writeOk) {
			@unlink($dataPath);
			return self::fail('写入冻包数据失败');
		}

		$checksum = is_file($dataPath) ? hash_file('sha256', $dataPath) : '';
		if ($checksum === false || $checksum === '') {
			return self::fail('计算 checksum 失败');
		}

		$manifest = [
			'version' => self::PACK_VERSION,
			'table' => $table,
			'year' => $year,
			'time_column' => $timeCol,
			'pk' => $pk,
			'from' => $from,
			'to' => $to,
			'count' => $count,
			'id_min' => $idMin,
			'id_max' => $idMax,
			'checksum_sha256' => $checksum,
			'data_file' => $dataName,
			'compress' => $compress,
			'packed_at' => date('Y-m-d H:i:s'),
		];
		$json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		if ($json === false || file_put_contents($manifestPath, $json) === false) {
			return self::fail('写入 manifest 失败');
		}

		self::upsertMeta($opts, [
			'year' => $year,
			'status' => 'packed',
			'pack_path' => $packRoot,
			'checksum' => $checksum,
			'message_count' => $count,
			'id_min' => $idMin,
			'id_max' => $idMax,
			'packed_at' => $manifest['packed_at'],
		]);

		return [
			'code' => 0,
			'message' => 'ok',
			'path' => $packRoot,
			'checksum' => $checksum,
			'count' => $count,
			'id_min' => $idMin,
			'id_max' => $idMax,
			'year' => $year,
		];
	}

	/**
	 * 删除冷表中已打包的某自然年数据
	 *
	 * 默认要求提供 pack_path + checksum 或可读 manifest，校验通过后才删
	 *
	 * @param array $opts
	 * @return array{code:int,message:string,deleted?:int,year?:int}
	 * @access public
	 * @static
	 */
	public static function purge(array $opts): array
	{
		$table = self::resolveTable((string)($opts['table'] ?? $opts['cold_table'] ?? ''));
		$timeCol = self::safeIdent((string)($opts['time_column'] ?? 'createtime'));
		$year = (int)($opts['year'] ?? 0);
		$require = array_key_exists('require_checksum', $opts) ? (bool)$opts['require_checksum'] : true;

		if ($table === '' || $year < 1970) {
			return self::fail('table 与合法 year 必填');
		}

		$packPath = (string)($opts['pack_path'] ?? '');
		$expectSum = (string)($opts['checksum'] ?? '');
		if ($packPath === '') {
			$packPath = self::resolvePackDir($opts, $year);
		}
		$manifest = self::readManifest($packPath);
		if ($require) {
			if ($manifest === null) {
				return self::fail('缺少有效 manifest，拒绝 purge');
			}
			$dataFile = rtrim($packPath, '/\\') . DIRECTORY_SEPARATOR . (string)($manifest['data_file'] ?? 'data.jsonl.gz');
			if (!is_file($dataFile)) {
				return self::fail('冻包数据文件不存在');
			}
			$actual = hash_file('sha256', $dataFile);
			$expect = $expectSum !== '' ? $expectSum : (string)($manifest['checksum_sha256'] ?? '');
			if ($actual === false || $expect === '' || !hash_equals($expect, $actual)) {
				return self::fail('checksum 校验失败，拒绝 purge');
			}
			if ((int)($manifest['year'] ?? 0) !== $year) {
				return self::fail('manifest 年份与请求不一致');
			}
		}

		[$from, $to] = self::yearRange($year);
		$deleted = self::prepareDriverTable(self::db($opts), $table)
			->where($timeCol, '>=', $from)
			->where($timeCol, '<', $to)
			->delete(true);
		$n = is_numeric($deleted) ? (int)$deleted : 0;

		self::upsertMeta($opts, [
			'year' => $year,
			'status' => 'purged',
			'pack_path' => $packPath,
			'checksum' => $expectSum !== '' ? $expectSum : (string)($manifest['checksum_sha256'] ?? ''),
			'purged_at' => date('Y-m-d H:i:s'),
		]);

		return [
			'code' => 0,
			'message' => 'ok',
			'deleted' => $n,
			'year' => $year,
		];
	}

	/**
	 * 审计解冻：冻包灌入目标表（禁止当作热表回灌入口）
	 *
	 * @param array $opts pack_path、target_table、alias、truncate_first、batch_size
	 * @return array{code:int,message:string,inserted?:int}
	 * @access public
	 * @static
	 */
	public static function thaw(array $opts): array
	{
		$packPath = (string)($opts['pack_path'] ?? '');
		$target = self::resolveTable((string)($opts['target_table'] ?? ''));
		$batch = max(50, (int)($opts['batch_size'] ?? 500));
		$truncate = !empty($opts['truncate_first']);

		if ($packPath === '' || $target === '') {
			return self::fail('pack_path / target_table 必填');
		}
		$manifest = self::readManifest($packPath);
		if ($manifest === null) {
			return self::fail('无法读取 manifest');
		}
		$dataFile = rtrim($packPath, '/\\') . DIRECTORY_SEPARATOR . (string)($manifest['data_file'] ?? 'data.jsonl.gz');
		if (!is_file($dataFile)) {
			return self::fail('冻包数据文件不存在');
		}
		$actual = hash_file('sha256', $dataFile);
		$expect = (string)($manifest['checksum_sha256'] ?? '');
		if ($actual === false || $expect === '' || !hash_equals($expect, $actual)) {
			return self::fail('解冻前 checksum 校验失败');
		}

		$db = self::db($opts);
		if ($truncate) {
			$truncated = self::prepareDriverTable($db, $target)->truncate();
			if ($truncated === false) {
				return self::fail('清空目标表失败');
			}
		}

		$compress = !empty($manifest['compress']) || str_ends_with(strtolower($dataFile), '.gz');
		$fp = $compress ? @gzopen($dataFile, 'rb') : @fopen($dataFile, 'rb');
		if ($fp === false || $fp === null) {
			return self::fail('无法打开冻包数据文件');
		}

		$inserted = 0;
		$buf = [];
		try {
			while (true) {
				$line = $compress ? gzgets($fp) : fgets($fp);
				if ($line === false) {
					break;
				}
				$line = trim($line);
				if ($line === '') {
					continue;
				}
				$row = json_decode($line, true);
				if (!is_array($row)) {
					continue;
				}
				$buf[] = $row;
				if (count($buf) >= $batch) {
					foreach ($buf as $r) {
						if (!self::insertRow($db, $target, self::normalizeRow($r))) {
							return self::fail('解冻写入失败，已插入 ' . $inserted);
						}
						$inserted++;
					}
					$buf = [];
				}
			}
			foreach ($buf as $r) {
				if (!self::insertRow($db, $target, self::normalizeRow($r))) {
					return self::fail('解冻写入失败，已插入 ' . $inserted);
				}
				$inserted++;
			}
		} finally {
			if ($compress) {
				@gzclose($fp);
			} else {
				@fclose($fp);
			}
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'inserted' => $inserted,
		];
	}

	/**
	 * 冷热兼容查询：先热后冷，合并有序结果（不读冻包）
	 *
	 * @param array $opts hot_table、cold_table、time_column、pk、limit、before_id、after_id、order=asc|desc、alias、where
	 * @return array{code:int,message:string,list?:array}
	 * @access public
	 * @static
	 */
	public static function queryRange(array $opts): array
	{
		$hot = self::resolveTable((string)($opts['hot_table'] ?? ''));
		$cold = self::resolveTable((string)($opts['cold_table'] ?? ''));
		$pk = self::safeIdent((string)($opts['pk'] ?? 'id'));
		$limit = max(1, min(500, (int)($opts['limit'] ?? 30)));
		$order = strtolower((string)($opts['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
		$beforeId = $opts['before_id'] ?? null;
		$afterId = $opts['after_id'] ?? null;
		$where = is_array($opts['where'] ?? null) ? $opts['where'] : [];

		if ($hot === '' || $cold === '' || $pk === '') {
			return self::fail('hot_table / cold_table / pk 必填');
		}

		$db = self::db($opts);
		$hotRows = self::fetchRangeRows($db, $hot, $pk, $where, $order, $beforeId, $afterId, $limit);
		$need = $limit - count($hotRows);
		$coldRows = [];
		if ($need > 0) {
			// 排除热表已取出的主键，避免合并重复
			$ids = [];
			if ($hotRows !== []) {
				foreach ($hotRows as $hr) {
					if (isset($hr[$pk])) {
						$ids[] = $hr[$pk];
					}
				}
			}
			$coldRows = self::fetchRangeRows(
				$db,
				$cold,
				$pk,
				$where,
				$order,
				$beforeId,
				$afterId,
				$need,
				$ids
			);
		}

		$merged = array_merge($hotRows, $coldRows);
		usort($merged, static function (array $a, array $b) use ($pk, $order): int {
			$va = $a[$pk] ?? 0;
			$vb = $b[$pk] ?? 0;
			$c = self::cmpId($va, $vb);
			return $order === 'ASC' ? $c : -$c;
		});
		// 去重主键（热冷不应重叠，兜底）
		$seen = [];
		$list = [];
		foreach ($merged as $row) {
			$k = (string)($row[$pk] ?? '');
			if ($k !== '' && isset($seen[$k])) {
				continue;
			}
			if ($k !== '') {
				$seen[$k] = true;
			}
			$list[] = $row;
			if (count($list) >= $limit) {
				break;
			}
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'list' => $list,
		];
	}

	/**
	 * 使用 ORM 链式条件读取单个热表或冷表的范围数据。
	 *
	 * 表名仍由生命周期配置解析为物理表，但交给驱动时恢复为逻辑表名，
	 * 让连接自身的 TABPREFIX 统一完成物理表拼接；所有筛选值交由 WhereParser 绑定。
	 *
	 * @param object $db 数据库驱动
	 * @param string $physicalTable 已解析的物理表名
	 * @param string $pk 主键字段
	 * @param array $where 结构化筛选条件
	 * @param string $order ASC 或 DESC
	 * @param mixed $beforeId 向前游标
	 * @param mixed $afterId 向后游标
	 * @param int $limit 读取条数
	 * @param array $excludeIds 需要排除的主键
	 * @return array<int,array<string,mixed>>
	 */
	private static function fetchRangeRows(
		object $db,
		string $physicalTable,
		string $pk,
		array $where,
		string $order,
		mixed $beforeId,
		mixed $afterId,
		int $limit,
		array $excludeIds = []
	): array
	{
		$db->clearSql();
		$db->setTable(self::logicalTable($physicalTable));
		if ($where !== []) {
			$db->where($where);
		}
		if ($beforeId !== null && $beforeId !== '' && (int)$beforeId > 0) {
			$db->where($pk, '<', $beforeId);
		}
		if ($afterId !== null && $afterId !== '' && (int)$afterId > 0) {
			$db->where($pk, '>', $afterId);
		}
		if ($excludeIds !== []) {
			$db->where($pk, 'NOT IN', $excludeIds);
		}

		$rows = $db->order($pk . ' ' . $order)->limit(0, $limit)->select();
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row)) {
				$out[] = self::normalizeRow($row);
			}
		}
		return $out;
	}

	/**
	 * 列出冷表中出现的自然年
	 *
	 * @param array $opts cold_table、time_column、alias
	 * @return array{code:int,message:string,years?:array<int>}
	 * @access public
	 * @static
	 */
	public static function listColdYears(array $opts): array
	{
		$cold = self::resolveTable((string)($opts['cold_table'] ?? $opts['table'] ?? ''));
		$timeCol = self::safeIdent((string)($opts['time_column'] ?? 'createtime'));
		if ($cold === '') {
			return self::fail('cold_table 必填');
		}
		$db = self::prepareDriverTable(self::db($opts), $cold);
		$dialect = $db->dialectClass();
		$qtime = $dialect::quoteIdent($timeCol);
		$short = $db->getResolvedDriver();

		if (in_array($short, ['PdoPgsql', 'Pgsql'], true)) {
			$yearExpr = 'EXTRACT(YEAR FROM ' . $qtime . ')::int';
		} elseif (in_array($short, ['PdoSqlite', 'Sqlite'], true)) {
			$yearExpr = "CAST(strftime('%Y', {$qtime}) AS INTEGER)";
		} else {
			$yearExpr = 'YEAR(' . $qtime . ')';
		}

		$rows = $db
			->field('DISTINCT ' . $yearExpr . ' AS y')
			->order('y ASC')
			->select();
		$years = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (!is_array($row)) {
					continue;
				}
				$row = self::normalizeRow($row);
				$y = (int)($row['y'] ?? 0);
				if ($y >= 1970) {
					$years[] = $y;
				}
			}
		}
		return ['code' => 0, 'message' => 'ok', 'years' => $years];
	}

	/**
	 * 热窗口起点（保留月的第一天 00:00:00）
	 *
	 * @param int $hotMonths
	 * @param \DateTimeInterface|null $now
	 * @return string
	 * @access public
	 * @static
	 */
	public static function hotWindowStart(int $hotMonths, ?\DateTimeInterface $now = null): string
	{
		$base = $now instanceof \DateTimeInterface
			? \DateTimeImmutable::createFromInterface($now)
			: new \DateTimeImmutable('today');
		$start = $base->modify('first day of this month')->setTime(0, 0, 0);
		if ($hotMonths > 1) {
			$start = $start->modify('-' . ($hotMonths - 1) . ' months');
		}
		return $start->format('Y-m-d H:i:s');
	}

	/**
	 * 自然年半开区间 [from, to)
	 *
	 * @param int $year
	 * @return array{0:string,1:string}
	 * @access public
	 * @static
	 */
	public static function yearRange(int $year): array
	{
		return [
			sprintf('%04d-01-01 00:00:00', $year),
			sprintf('%04d-01-01 00:00:00', $year + 1),
		];
	}

	// ---------- 内部工具 ----------

	/**
	 * @param array $opts
	 * @return array{code:int,message:string}
	 */
	private static function fail(string $message): array
	{
		return ['code' => 1, 'message' => $message];
	}

	/**
	 * @param array $opts
	 * @return \Candy\Extend\DB\DBServer
	 */
	private static function db(array $opts): object
	{
		$alias = $opts['alias'] ?? null;
		if (is_string($alias) && $alias !== '') {
			return D('_dbDriver', $alias);
		}
		return D();
	}

	/**
	 * 解析物理表名（补 TABPREFIX）
	 *
	 * @param string $table
	 * @return string
	 */
	private static function resolveTable(string $table): string
	{
		$table = trim($table);
		if ($table === '') {
			return '';
		}
		$prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
		if ($prefix !== '' && stripos($table, $prefix) !== 0) {
			return $prefix . $table;
		}
		return $table;
	}

	/**
	 * 将已解析的物理表名还原为驱动链使用的逻辑表名。
	 *
	 * @param string $table 物理表名
	 * @return string 逻辑表名
	 */
	private static function logicalTable(string $table): string
	{
		$prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
		if ($prefix !== '' && stripos($table, $prefix) === 0) {
			return substr($table, strlen($prefix));
		}
		return $table;
	}

	/**
	 * 将已解析的物理表绑定到驱动，并清空上一条链式查询状态。
	 *
	 * @param object $db 数据库驱动
	 * @param string $physicalTable 已解析的物理表名
	 * @return object 已绑定表的数据库驱动
	 */
	private static function prepareDriverTable(object $db, string $physicalTable): object
	{
		$db->clearSql();
		$db->setTable(self::logicalTable($physicalTable));
		return $db;
	}

	/**
	 * 安全标识（仅字母数字下划线）
	 *
	 * @param string $ident
	 * @return string
	 */
	private static function safeIdent(string $ident): string
	{
		$ident = trim($ident);
		if ($ident === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
			return '';
		}
		return $ident;
	}

	/**
	 * @param array $row
	 * @return array
	 */
	private static function normalizeRow(array $row): array
	{
		$out = [];
		foreach ($row as $k => $v) {
			$out[strtolower((string)$k)] = $v;
		}
		return $out;
	}

	/**
	 * 通过驱动链式 API 插入一行归档数据。
	 *
	 * 归档行来自已读取的数据库记录或经过 checksum 校验的冻包；字段名
	 * 仍经过标识符白名单过滤，写入动作交由 DBServer 统一绑定参数。
	 *
	 * @param object $db 数据库驱动
	 * @param string $table 已解析的物理表名
	 * @param array $row 待写入数据
	 * @return bool 是否写入成功
	 */
	private static function insertRow(object $db, string $table, array $row): bool
	{
		if ($row === []) {
			return false;
		}
		$clean = [];
		foreach ($row as $k => $v) {
			$k = self::safeIdent((string)$k);
			if ($k === '') {
				continue;
			}
			$clean[$k] = $v;
		}
		if ($clean === []) {
			return false;
		}
		$res = self::prepareDriverTable($db, $table)->add($clean, 0, 0);
		return $res !== false && $res !== null;
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 * @return int
	 */
	private static function cmpId(mixed $a, mixed $b): int
	{
		if (is_numeric($a) && is_numeric($b)) {
			return $a <=> $b;
		}
		return strcmp((string)$a, (string)$b);
	}

	/**
	 * @param array $opts
	 * @param int $year
	 * @return string
	 */
	private static function resolvePackDir(array $opts, int $year): string
	{
		$base = (string)($opts['pack_dir'] ?? '');
		if ($base === '') {
			$root = rtrim((string)(defined('CANDYROOT') ? CANDYROOT : (C('CANDYROOT') ?? '')), '/\\');
			$base = $root . '/Data/freeze';
		}
		$base = str_replace(['{CANDYROOT}', '{year}'], [
			rtrim((string)(defined('CANDYROOT') ? CANDYROOT : (C('CANDYROOT') ?? '')), '/\\'),
			(string)$year,
		], $base);
		return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . (string)$year;
	}

	/**
	 * @param string $dir
	 * @return bool
	 */
	private static function ensureDir(string $dir): bool
	{
		if (is_dir($dir)) {
			return true;
		}
		return @mkdir($dir, 0755, true) || is_dir($dir);
	}

	/**
	 * @param string $packPath
	 * @return array|null
	 */
	private static function readManifest(string $packPath): ?array
	{
		$file = rtrim($packPath, '/\\') . DIRECTORY_SEPARATOR . 'manifest.json';
		if (!is_file($file)) {
			return null;
		}
		$raw = file_get_contents($file);
		if ($raw === false || $raw === '') {
			return null;
		}
		$data = json_decode($raw, true);
		return is_array($data) ? $data : null;
	}

	/**
	 * 可选写入冻结元数据表（表不存在或未配置则忽略）
	 *
	 * @param array $opts
	 * @param array $meta
	 * @return void
	 */
	private static function upsertMeta(array $opts, array $meta): void
	{
		$metaTable = trim((string)($opts['meta_table'] ?? ''));
		if ($metaTable === '') {
			return;
		}
		$metaTable = self::resolveTable($metaTable);
		$year = (int)($meta['year'] ?? 0);
		if ($year < 1970) {
			return;
		}
		try {
			$db = self::db($opts);
			$exist = self::prepareDriverTable($db, $metaTable)
				->where('year', '=', $year)
				->find();
			$row = [
				'year' => $year,
				'status' => (string)($meta['status'] ?? ''),
				'pack_path' => (string)($meta['pack_path'] ?? ''),
				'checksum' => (string)($meta['checksum'] ?? ''),
				'message_count' => (int)($meta['message_count'] ?? 0),
				'id_min' => $meta['id_min'] ?? null,
				'id_max' => $meta['id_max'] ?? null,
				'packed_at' => $meta['packed_at'] ?? null,
				'purged_at' => $meta['purged_at'] ?? null,
				'updatetime' => date('Y-m-d H:i:s'),
			];
			if (is_array($exist) && $exist !== []) {
				unset($row['year']);
				self::prepareDriverTable($db, $metaTable)
					->where('year', '=', $year)
					->update($row, 0, 0);
			} else {
				$row['createtime'] = date('Y-m-d H:i:s');
				self::insertRow($db, $metaTable, $row);
			}
		} catch (\Throwable) {
			// 元数据表可选，失败不影响主流程
		}
	}
}
