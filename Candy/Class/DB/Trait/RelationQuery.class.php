<?php
/***
 * 关联查询
 *
 * 业务说明：r_select 多表关联与 r_delete 级联删除
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-17 18:29:13 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Core\Debug;
use Candy\Extend\Validate\Csrf;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait RelationQuery {
	/**
	 * 关联查询
	 * 
	 * 执行多表关联查询，支持一对一、一对多关系。
	 * 可指定关联表、关联字段和关联条件。
	 * 支持左关联（LEFT JOIN）等多种关联方式。
	 * 支持数据缓存功能。
	 * 
	 * @param array ...$args 关联表配置数组，每个数组包含：[表名, 字段, 外键, 子查询配置]
	 * @return array|bool 查询结果数组或false
	 * @example
	 * ```php
	 * // 简单一对一关联
	 * $result = $db->r_select(['user_info', 'address,phone', 'user_id']);
	 * 
	 * // 指定别名和关联方式
	 * $result = $db->r_select([['user_info', 'info'], 'address,phone', 'user_id']);
	 * 
	 * // 一对多关联（带子查询配置）
	 * $result = $db->r_select([
	 *     'orders',
	 *     '*',
	 *     'user_id',
	 *     ['orderList', 'id DESC', 10] // 子查询配置：别名、排序、数量
	 * ]);
	 * ```
	 */
	public function r_select(...$args): mixed
	{
		if ($args === [] || !is_array($args[0])) {
			return false;
		}

		$data = self::getDataCache($args);
		if ($data !== false) {
			$this->resetSql();
			return $data;
		}

		$tojson = (bool)$this->sqlArgs('tojson');
		$mainPri = $this->fieldList['pri'];
		$joinStr = ' FROM ' . $this->quoteIdent($this->tabName) . ' AS pri';
		$fields = empty($this->sqlArgs('field'))
			? 'pri.*'
			: self::getFormatStr('fields', ' ', $this->sqlArgs('field'), $this->fieldList, 'pri.');
		$subList = [];
		$aliasIndex = 0;

		$where = $this->compileWhere($this->sqlArgs('where'), 'pri.', ' WHERE ', '', 'pri');
		$order = $this->compileOrder(
			self::getFormatStr('order', ' ORDER BY ', $this->sqlArgs('order'), [$mainPri], 'pri.')
		);
		$group = $this->compileGroup(
			self::getFormatStr('group', ' GROUP BY ', $this->sqlArgs('group'), [], 'pri.')
		);
		$having = $this->compileHaving(
			self::getFormatStr('having', ' HAVING ', $this->sqlArgs('having'), [], 'pri.')
		);
		$limit = $this->sqlArgs('limit') !== '' ? $this->compileLimit($this->sqlArgs('limit')) : '';

		foreach ($args as $tab) {
			if (!is_array($tab) || count($tab) < 3) {
				continue;
			}

			if (isset($tab[3]) && is_array($tab[3])) {
				$subList[] = $tab;
				continue;
			}

			$parsed = $this->parseRSelectJoinTab($tab, $aliasIndex);
			$joinPri = is_string($tab[3] ?? null) && $tab[3] !== '' ? $tab[3] : $mainPri;
			$joinStr .= ' ' . $parsed['join'] . ' JOIN ' . $this->quoteIdent($this->tablePrefix . $parsed['table']) . ' AS ' . $parsed['alias']
				. ' ON pri.' . $this->quoteIdent($joinPri) . ' = ' . $parsed['alias'] . '.' . $this->quoteIdent($parsed['fk']);
			$fields .= ', ' . (empty($parsed['field'])
				? $parsed['alias'] . '.*'
				: self::getFormatStr('fields', ' ', [$parsed['field']], [], $parsed['alias'] . '.'));
		}

		$data = [];
		$hasJoin = str_contains($joinStr, ' JOIN ');

		if ($hasJoin) {
			$sql = 'SELECT ' . $fields . $joinStr . $where['where'] . $group . $having . $order . $limit;
			listen('db_query', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $args, 'data' => $where['data']]);
			$data = $this->checkAttr($this->query($sql, 'select', $where['data']), '*');
			listen('db_after', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $args, 'data' => $data]);
		}

		if ($subList !== []) {
			if ($data === []) {
				$mainFields = self::getFormatStr('fields', ' ', $this->sqlArgs('field'), $this->fieldList, 'pri.');
				$sql = 'SELECT ' . $mainFields . ' FROM ' . $this->quoteIdent($this->tabName) . ' AS pri' . $where['where'] . $group . $having . $order . $limit;
				listen('db_query', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $args, 'data' => $where['data']]);
				$data = $this->checkAttr($this->query($sql, 'select', $where['data']), $mainFields);
				listen('db_after', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $args, 'data' => $data]);
			}

			foreach ($subList as $sub) {
				$data = $this->attachRSelectChildren($data, $sub, $mainPri);
			}
		}

		$data = $tojson ? json($data) : $data;
		self::setDataCache($args, $data);
		$this->resetSql();
		return $data;
	}

	/**
	 * 解析 r_select 的 1:1 JOIN 表配置
	 *
	 * @return array{table:string,alias:string,join:string,field:string,fk:string}
	 */
	private function parseRSelectJoinTab(array $tab, int &$aliasIndex): array
	{
		[$tabName, $field, $fk] = $tab;
		$join = 'LEFT';
		$as = '';

		if (is_array($tabName)) {
			if (count($tabName) > 2) {
				[$tabName, $as, $join] = $tabName;
			} else {
				[$tabName, $as] = $tabName;
			}
			$tabName = strtolower((string)$tabName);
		} else {
			$tabName = strtolower((string)$tabName);
			if (stripos($tabName, ' as ') !== false) {
				[$tabName, $as] = array_map('trim', explode(' as ', $tabName, 2));
			} elseif (stripos($tabName, 'as') !== false && stripos($tabName, 'as') > 0) {
				[$tabName, $as] = array_map('trim', explode('as', $tabName, 2));
			} else {
				$aliasIndex++;
			}
		}

		if ($as === '') {
			$as = 't' . $aliasIndex;
		}

		$join = strtoupper(trim((string)$join));
		if (!in_array($join, ['LEFT', 'RIGHT', 'INNER', 'CROSS'], true)) {
			$join = 'LEFT';
		}

		$field = (string)$field;
		if ($field !== '' && !in_array($fk, explode(',', $field), true)) {
			$field .= ',' . $fk;
		}

		return [
			'table' => $tabName,
			'alias' => $as,
			'join' => $join,
			'field' => $field,
			'fk' => (string)$fk,
		];
	}

	/**
	 * 批量加载一对多子表并挂载到主表结果（消除 N+1）
	 */
	private function attachRSelectChildren(array $data, array $sub, string $mainPri): array
	{
		if ($data === []) {
			return $data;
		}

		[$tabName, $field, $fk] = $sub;
		$tabName = strtolower((string)$tabName);
		$fk = (string)$fk;
		$field = (string)$field;

		$config = array_pad($sub[3] ?? [], 4, null);
		[$sname, $sorder, $slimit, $swhere] = $config;
		$spri = $mainPri;
		if (is_array($sname)) {
			[$sname, $spri] = $sname;
		}
		$sname = (string)$sname;
		$spri = (string)$spri;

		$parentIds = array_values(array_unique(array_filter(
			array_column($data, $spri),
			static fn($id) => $id !== null && $id !== ''
		)));

		if ($parentIds === []) {
			foreach ($data as $key => $row) {
				$data[$key][$sname] = [];
			}
			return $data;
		}

		$child = D($tabName);
		$child->field($field === '' || $field === '*' ? '*' : $field)->where($fk, 'IN', $parentIds);
		$this->applyRSelectChildWhere($child, $swhere);
		if ($sorder !== null && $sorder !== '') {
			$child->order((string)$sorder);
		}

		$rows = $child->select();
		$rows = $this->checkAttr($rows, $field === '' || $field === '*' ? '*' : $field);
		$grouped = $this->groupRSelectChildRows($rows, $fk, $slimit);

		foreach ($data as $key => $row) {
			$parentId = $row[$spri] ?? null;
			$data[$key][$sname] = ($parentId === null || $parentId === '') ? [] : ($grouped[$parentId] ?? []);
		}

		return $data;
	}

	/**
	 * @param object $child D() 返回的模型实例
	 */
	private function applyRSelectChildWhere(object $child, mixed $swhere): void
	{
		if ($swhere === null || $swhere === '') {
			return;
		}
		if (is_array($swhere)) {
			$child->where($swhere);
			return;
		}
		$child->where('1', 'exp', '1 AND (' . (string)$swhere . ')');
	}

	/**
	 * 按外键分组子表行，并按 slimit 截取每组条数
	 */
	private function groupRSelectChildRows(array $rows, string $fk, mixed $slimit): array
	{
		$grouped = [];
		$maxPerParent = ($slimit === null || $slimit === '') ? null : max(0, (int)$slimit);

		foreach ($rows as $row) {
			if (!isset($row[$fk])) {
				continue;
			}
			$parentId = $row[$fk];
			$grouped[$parentId] ??= [];
			if ($maxPerParent === null || count($grouped[$parentId]) < $maxPerParent) {
				$grouped[$parentId][] = $row;
			}
		}

		return $grouped;
	}

	/**
	 * 关联删除
	 * 
	 * 删除当前表数据的同时，也删除关联表中的相关数据。
	 * 支持软删除和硬删除两种模式。
	 * 
	 * @param array ...$args 关联表配置数组，每个数组包含：[表名, 外键, 额外条件]
	 * @param bool $args[1] 可选，是否执行硬删除
	 * @return int|bool 成功返回删除的总记录数，失败返回false
	 * @example
	 * ```php
	 * // 软删除（默认），同时删除关联表数据
	 * $result = $db->where(['user_id' => 100])->r_delete([
	 *     ['orders', 'user_id'],
	 *     ['user_logs', 'user_id']
	 * ]);
	 * 
	 * // 硬删除，同时强制删除关联表数据
	 * $result = $db->where(['user_id' => 100])->r_delete([
	 *     ['orders', 'user_id'],
	 *     ['user_logs', 'user_id']
	 * ], true);
	 * ```
	 */
	public function r_delete(...$args): int
	{
		$vali = true;
		if (!empty($this->sqlArgs('token'))) {
			$vali = Csrf::check((string)$this->sqlArgs('token')[0]);
			if (!$vali) {
				self::setMsg('Token令牌无效请刷新后重新提交');
			}
		}
		
		if($vali){
			if(count($args) == 0 || !is_array($args[0]))
				return 0;
			
			$one = $this->select();
			$pri = $this->fieldList['pri'];
			$pris = [];
			
			foreach($one as $row){
				$pris[] = $row[$pri];
			}
			
			$true_delete = (isset($args[1]) && $args[1] === true) ? true : false;
			
			$affected_rows = 0;
			foreach($args as $tab){
				$where = [$tab[1]=>$pris];
				
				if(!empty($tab[2]))
					$where = array_merge($where, $tab[2]);
				
				if(!empty($where[$tab[1]])){
					$affected_rows += ($true_delete ? D($tab[0])->where($where)->delete(true) : D($tab[0])->where($where)->delete());
				}
			}
			
			$affected_rows += ($true_delete ? $this->where($pris)->delete(true) : $this->where($pris)->delete());
			return $affected_rows;
		}else{
			return 0;
		}
	}

}
