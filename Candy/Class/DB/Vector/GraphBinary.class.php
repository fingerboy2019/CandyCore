<?php
/***
 * HNSW 图纯二进制编解码
 *
 * 业务说明：graph.bin 使用 CVG2 定长头 + 变长节点/邻接；可读旧版 CVG1（serialize）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 20:25:00 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class GraphBinary
{
	public const MAGIC_V2 = 'CVG2';
	public const MAGIC_V1 = 'CVG1';

	/**
	 * 编码 HNSW 图为 CVG2 二进制
	 *
	 * @param HnswGraph $graph
	 * @return string
	 */
	public static function encode(HnswGraph $graph): string
	{
		$st = $graph->export();
		$bin = self::MAGIC_V2;
		$bin .= pack('v', (int)$st['m']);
		$bin .= pack('v', (int)$st['ef_construction']);
		$bin .= pack('v', (int)$st['ef_search']);
		$bin .= pack('v', (int)$st['level_cap']);
		$bin .= pack('v', (int)$st['max_level']);
		$bin .= self::packString($st['entry_point'] !== null ? (string)$st['entry_point'] : '');

		$nodes = is_array($st['nodes'] ?? null) ? $st['nodes'] : [];
		$bin .= pack('N', count($nodes));
		$levelCap = (int)$st['level_cap'];

		foreach ($nodes as $id => $node) {
			$bin .= self::packString((string)$id);
			$bin .= chr(max(0, min(255, (int)($node['level'] ?? 0))));
			$bin .= pack('N', (int)($node['slot'] ?? 0));
			$bin .= chr(!empty($node['deleted']) ? 1 : 0);
			for ($l = 0; $l <= $levelCap; $l++) {
				$nbs = $node['neighbors'][$l] ?? [];
				if (!is_array($nbs)) {
					$nbs = [];
				}
				$bin .= pack('v', count($nbs));
				foreach ($nbs as $nb) {
					$bin .= self::packString((string)$nb);
				}
			}
		}

		return $bin;
	}

	/**
	 * 解码 graph.bin（优先 CVG2，兼容 CVG1 serialize）
	 *
	 * @param string $raw
	 * @return HnswGraph|null
	 */
	public static function decode(string $raw): ?HnswGraph
	{
		if (strlen($raw) < 4) {
			return null;
		}
		$magic = substr($raw, 0, 4);
		if ($magic === self::MAGIC_V1) {
			$data = @unserialize(substr($raw, 4), ['allowed_classes' => false]);
			return is_array($data) ? HnswGraph::import($data) : null;
		}
		if ($magic !== self::MAGIC_V2) {
			return null;
		}

		$o = 4;
		$m = self::unpackU16($raw, $o);
		$efC = self::unpackU16($raw, $o);
		$efS = self::unpackU16($raw, $o);
		$levelCap = self::unpackU16($raw, $o);
		$maxLevel = self::unpackU16($raw, $o);
		$entry = self::unpackString($raw, $o);
		$count = self::unpackU32($raw, $o);

		$nodes = [];
		for ($i = 0; $i < $count; $i++) {
			$id = self::unpackString($raw, $o);
			$level = ord($raw[$o] ?? "\0");
			$o++;
			$slot = self::unpackU32($raw, $o);
			$deleted = ord($raw[$o] ?? "\0") === 1;
			$o++;
			$neighbors = [];
			for ($l = 0; $l <= $levelCap; $l++) {
				$nc = self::unpackU16($raw, $o);
				$nbs = [];
				for ($j = 0; $j < $nc; $j++) {
					$nbs[] = self::unpackString($raw, $o);
				}
				$neighbors[$l] = $nbs;
			}
			$nodes[$id] = [
				'level' => $level,
				'slot' => $slot,
				'deleted' => $deleted,
				'neighbors' => $neighbors,
			];
		}

		return HnswGraph::import([
			'm' => $m,
			'ef_construction' => $efC,
			'ef_search' => $efS,
			'level_cap' => $levelCap,
			'max_level' => $maxLevel,
			'entry_point' => $entry === '' ? null : $entry,
			'nodes' => $nodes,
		]);
	}

	private static function packString(string $s): string
	{
		if (strlen($s) > 65535) {
			$s = substr($s, 0, 65535);
		}
		return pack('v', strlen($s)) . $s;
	}

	private static function unpackU16(string $raw, int &$o): int
	{
		if ($o + 2 > strlen($raw)) {
			return 0;
		}
		$v = unpack('v', substr($raw, $o, 2))[1];
		$o += 2;
		return (int)$v;
	}

	private static function unpackU32(string $raw, int &$o): int
	{
		if ($o + 4 > strlen($raw)) {
			return 0;
		}
		$v = unpack('N', substr($raw, $o, 4))[1];
		$o += 4;
		return (int)$v;
	}

	private static function unpackString(string $raw, int &$o): string
	{
		$len = self::unpackU16($raw, $o);
		if ($len <= 0 || $o + $len > strlen($raw)) {
			return '';
		}
		$s = substr($raw, $o, $len);
		$o += $len;
		return $s;
	}
}
