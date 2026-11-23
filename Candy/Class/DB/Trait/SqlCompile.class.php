<?php
/***
 * SQL 子句编译
 *
 * 业务说明：GROUP/HAVING/ORDER/LIMIT/JOIN 与字段格式化 getFormatStr
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-10-12 11:14:00 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Core\Debug;
use Candy\Extend\DB\DBServer;
use Candy\Extend\DB\Dialect\DialectInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait SqlCompile {
	/**
	 * 字段是否在表结构清单里
	 *
	 * fieldList 形如 ['pri'=>'id','id'=>...,'thread_id'=>...]，键是列名；
	 * 兼容老形态（只有值）。早期用 in_array 按值比对，键形态下永远不命中。
	 *
	 * @param string $name 字段名
	 * @return bool
	 */
	private function fieldListHas(string $name): bool
	{
		foreach ((array)$this->fieldList as $key => $value) {
			$candidate = is_string($key) ? $key : $value;
			if (is_string($candidate) && strcasecmp($candidate, $name) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 当前查询是否使用了显式 JOIN（join($table,$alias,$type,$on)）
	 *
	 * @return bool
	 */
	private function hasExplicitJoin(): bool
	{
		foreach ((array)$this->sqlArgs('join') as $joinTab) {
			if (is_array($joinTab) && ($joinTab['__explicit'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}

	private function compileGroup(string $groupField): string
	{
		if (preg_match('/^GROUP\s+BY\s+/i', trim($groupField))) {
			$groupField = trim((string)preg_replace('/^GROUP\s+BY\s+/i', '', trim($groupField)));
		}

		// 分割多个分组字段
		$fields = $this->splitFieldList($groupField);
		$validFields = [];
		
		// 验证每个字段
		foreach ($fields as $field) {
			$field = trim($field);
			if (empty($field)) continue;
			
			// 提取字段名（去除可能的函数调用）
			$fieldForValidation = str_replace(['`', '"', '[', ']'], '', $field);
			$fieldName = trim(preg_replace('/\([^)]*\)/', '', $fieldForValidation));
			$fieldName = preg_replace('/^[a-zA-Z_][a-zA-Z0-9_]*\./', '', $fieldName) ?? $fieldName;
			
			// 验证字段是否在 fieldList 中
			if ($this->fieldListHas($fieldName) || $this->hasExplicitJoin()) {
				// 验证字段格式（支持字段名和简单函数）
				if (preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?[a-zA-Z0-9_]+(\([^)]*\))?$/i', $fieldForValidation)) {
					$validFields[] = $field;
				}
			}
		}
		
		// 如果有有效的分组字段
		if (!empty($validFields)) {
			return " GROUP BY " . implode(', ', $validFields);
		}
		
		return '';
	}

	private function compileHaving(string $havingCondition): string
	{
		if (preg_match('/^HAVING\s+/i', trim($havingCondition))) {
			$havingCondition = trim((string)preg_replace('/^HAVING\s+/i', '', trim($havingCondition)));
		}

		// 简单的 HAVING 条件验证
		// 支持格式：field operator value 或 function(field) operator value
		// 分割多个 HAVING 条件
		$conditions = explode(' AND ', $havingCondition);
		$validConditions = [];
		
		foreach ($conditions as $condition) {
			$condition = trim($condition);
			if (empty($condition)) continue;
			
			// 验证条件格式
			if ($this->validateHavingCondition($condition)) {
				$validConditions[] = $condition;
			}
		}
		
		// 如果有有效的 HAVING 条件
		if (!empty($validConditions)) {
			return " HAVING " . implode(' AND ', $validConditions);
		}
		
		return '';
	}

	private function validateHavingCondition(string $condition): bool
	{
		// 支持的运算符
		$operators = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE'];
		
		// 聚合函数只允许引用当前表已知字段，值只允许数字或字符串字面量。
		$aggregatePattern = '/^(COUNT|SUM|AVG|MIN|MAX)\(\s*(\*|[a-zA-Z0-9_]+)\s*\)\s*(=|!=|<>|>=|<=|>|<|LIKE|NOT LIKE)\s*(.+)$/i';
		if (preg_match($aggregatePattern, $condition, $matches)) {
			$function = strtoupper($matches[1]);
			$column = $matches[2];
			$value = trim($matches[4]);
			if (($column === '*' && $function !== 'COUNT') || ($column !== '*' && !in_array($column, $this->fieldList, true))) {
				return false;
			}
			return preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value) === 1
				|| preg_match('/^["\'][^"\']*["\']$/', $value) === 1;
		}

		// 尝试匹配普通字段条件
		foreach ($operators as $operator) {
			$pattern = '/^([a-zA-Z0-9_]+\([^)]*\)|[a-zA-Z0-9_]+)\s*' . preg_quote($operator, '/') . '\s*(.+)$/i';
			if (preg_match($pattern, $condition, $matches)) {
				$field = $matches[1];
				$value = $matches[2];
				
				// 验证字段部分
				$fieldName = trim(preg_replace('/\([^)]*\)/', '', $field));
				if (!in_array($fieldName, $this->fieldList)) {
					return false;
				}
				
				// 验证值部分（只允许数字、字符串字面量）
				if (!preg_match('/^[0-9]+$/', $value) && !preg_match('/^["\'][^"\']*["\']$/', $value)) {
					return false;
				}
				
				return true;
			}
		}
		
		return false;
	}

	/**
	 * 构建 ORDER BY 子句
	 */
	private function compileOrder(string $orderField): string
	{
		$orderField = trim($orderField);
		$explicitJoin = false;
		foreach ((array)$this->sqlArgs('join') as $joinTab) {
			if (is_array($joinTab) && ($joinTab['__explicit'] ?? false) === true) {
				$explicitJoin = true;
				break;
			}
		}
		// getFormatStr 可能已带 ORDER BY 前缀，需剥离后再按字段解析
		if (preg_match('/^ORDER\s+BY\s+/i', $orderField)) {
			$orderField = trim((string)preg_replace('/^ORDER\s+BY\s+/i', '', $orderField));
		}

		// 分割多个排序字段
		$fields = $this->splitFieldList($orderField);
		$validFields = [];
		
		// 验证每个字段
		foreach ($fields as $field) {
			$field = trim($field);
			if (empty($field)) continue;
			// 特殊处理 rand() 函数
			if (strtolower($field) === 'rand()') {
				$validFields[] = 'RAND()';
				continue;
			}

			// 允许两个真实数值字段做受控减法排序（如 vote_up - vote_down）；不接受函数、常量或其他表达式。
			if (preg_match('/^\(\s*([a-zA-Z0-9_]+)\s*-\s*([a-zA-Z0-9_]+)\s*\)(?:\s+(ASC|DESC))?$/i', $field, $match)) {
				$left = $match[1];
				$right = $match[2];
				$direction = isset($match[3]) ? strtoupper($match[3]) : 'ASC';
				if (in_array($left, $this->fieldList, true) && in_array($right, $this->fieldList, true)) {
					$validFields[] = '(' . $left . ' - ' . $right . ') ' . $direction;
				}
				continue;
			}

			// 允许由两个真实字段组成的 COALESCE 排序，保留空值回退语义并阻断任意表达式注入。
			if (preg_match('/^COALESCE\(\s*([a-zA-Z0-9_]+)\s*,\s*([a-zA-Z0-9_]+)\s*\)(?:\s+(ASC|DESC))?$/i', $field, $match)) {
				$left = $match[1];
				$right = $match[2];
				$direction = isset($match[3]) ? strtoupper($match[3]) : 'ASC';
				if (in_array($left, $this->fieldList, true) && in_array($right, $this->fieldList, true)) {
					$validFields[] = 'COALESCE(' . $left . ',' . $right . ') ' . $direction;
				}
				continue;
			}

			// 允许由已知字段组成的聚合排序（例如 SUM(amount) DESC），避免榜单退回原生 SQL。
			if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\(\s*(\*|[a-zA-Z0-9_]+)\s*\)(?:\s+(ASC|DESC))?$/i', $field, $match)) {
				$function = strtoupper($match[1]);
				$column = $match[2];
				$direction = isset($match[3]) ? strtoupper($match[3]) : 'ASC';
				if (($column === '*' && $function !== 'COUNT') || ($column !== '*' && !in_array($column, $this->fieldList, true))) {
					continue;
				}
				$validFields[] = $function . '(' . $column . ') ' . $direction;
				continue;
			}

			// CASE 排序：支持多 WHEN 分支、IN 多值、字符串/整数字面量
			// 字段名必须在 fieldList 白名单内，值只能用字面量，拒绝函数/列引用/子查询/逻辑运算符
			$caseOrder = $this->compileCaseOrderExpr($field);
			if ($caseOrder !== null) {
				$validFields[] = $caseOrder;
				continue;
			}

			// 显式 JOIN：允许主表/关联表 alias.column（库位号、批次号等跨表排序）
			if ($explicitJoin) {
				$normalizedField = str_replace(['`', '"', '[', ']'], '', $field);
				if (preg_match(
					'/^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z0-9_]+)(?:\s+(ASC|DESC))?(?:\s+NULLS\s+(FIRST|LAST))?$/i',
					$normalizedField,
					$match
				) === 1) {
					$alias = $match[1];
					$column = $match[2];
					$direction = isset($match[3]) && $match[3] !== '' ? strtoupper($match[3]) : 'ASC';
					$nulls = isset($match[4]) && $match[4] !== '' ? ' NULLS ' . strtoupper($match[4]) : '';
					if ($this->isKnownJoinOrderAlias($alias)) {
						$validFields[] = $this->quoteIdent($alias) . '.' . $this->quoteIdent($column)
							. ' ' . $direction . $nulls;
					}
					continue;
				}
				if (preg_match('/^(?:(?:[a-zA-Z_][a-zA-Z0-9_]*)\.)?([a-zA-Z0-9_]+)(?:\s+(ASC|DESC))?(?:\s+NULLS\s+(FIRST|LAST))?$/i', $normalizedField, $match)) {
					$fieldName = $match[1];
					$direction = isset($match[2]) && $match[2] !== '' ? strtoupper($match[2]) : 'ASC';
					$nulls = isset($match[3]) && $match[3] !== '' ? ' NULLS ' . strtoupper($match[3]) : '';
					if (in_array($fieldName, $this->fieldList, true)) {
						$fieldWithoutDirection = preg_replace('/\s+(ASC|DESC)(?:\s+NULLS\s+(FIRST|LAST))?$/i', '', $field);
						$validFields[] = $fieldWithoutDirection . ' ' . $direction . $nulls;
					}
				}
				continue;
			}

			// 验证非 JOIN 查询的单个字段格式，并保留 PostgreSQL NULLS FIRST/LAST 语义。
			if (preg_match('/^([a-zA-Z0-9_]+)(?:\s+(ASC|DESC))?(?:\s+NULLS\s+(FIRST|LAST))?$/i', $field, $match)) {
				$fieldName = $match[1];
				$direction = isset($match[2]) && $match[2] !== '' ? strtoupper($match[2]) : 'ASC';
				$nulls = isset($match[3]) && $match[3] !== '' ? ' NULLS ' . strtoupper($match[3]) : '';
				if (in_array($fieldName, $this->fieldList, true)) {
					$validFields[] = $fieldName . ' ' . $direction . $nulls;
				}
			}
		}
		
		// 如果有有效的排序字段
		if (!empty($validFields)) {
			return " ORDER BY " . implode(', ', $validFields);
		}
		
		return '';
	}

	/**
	 * 判断排序别名是否属于当前显式 JOIN 查询的主表或关联表。
	 *
	 * @param string $alias 表别名
	 * @return bool 是否允许用于 alias.column 排序
	 */
	private function isKnownJoinOrderAlias(string $alias): bool
	{
		$alias = strtolower(trim($alias));
		if ($alias === '') {
			return false;
		}
		$main = $this->sqlArgs('alias');
		if (is_array($main) && strtolower(trim((string)($main[0] ?? ''))) === $alias) {
			return true;
		}
		foreach ((array)$this->sqlArgs('join') as $joinTab) {
			if (!is_array($joinTab) || ($joinTab['__explicit'] ?? false) !== true) {
				continue;
			}
			if (strtolower(trim((string)($joinTab['alias'] ?? ''))) === $alias) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 按顶层逗号拆分字段表达式，保留函数参数中的逗号。
	 *
	 * @param string $fieldList 字段表达式列表
	 * @return list<string> 拆分后的字段表达式
	 */
	/**
	 * 编译 CASE WHEN 排序表达式（受控白名单）
	 *
	 * 支持形态：CASE WHEN <field> = <literal> THEN <int> [WHEN ...] [ELSE <int>] END [ASC|DESC]
	 * 其中 <literal> 为整数或单引号字符串；<field> 必须在 fieldList 内。
	 * 多个 WHEN 用空格分隔，IN 支持 (值,值,...)。
	 *
	 * 安全边界：不接受函数调用、列引用、子查询、逻辑运算符（AND/OR/NOT）、注释符。
	 *
	 * @param string $field 原始排序片段
	 * @return string|null 编译后的安全 SQL；不匹配或校验失败返回 null
	 */
	private function compileCaseOrderExpr(string $field): ?string
	{
		$field = trim($field);
		if (preg_match('/^CASE\s+(.+?)\s+END(?:\s+(ASC|DESC))?$/is', $field, $m) !== 1) {
			return null;
		}
		$body = trim($m[1]);
		$direction = isset($m[2]) && $m[2] !== '' ? strtoupper($m[2]) : 'ASC';

		$elseVal = null;
		if (preg_match('/^(.*?)\s+ELSE\s+(-?\d+)$/is', $body, $em) === 1) {
			$body = trim($em[1]);
			$elseVal = (int) $em[2];
		}

		$pattern = '/(?:^|\s)WHEN\s+(.+?)\s+THEN\s+(-?\d+)(?=\s+WHEN\s+|\s*$)/is';
		if (preg_match_all($pattern, $body, $wm, PREG_SET_ORDER) === 0) {
			return null;
		}

		$segments = [];
		foreach ($wm as $seg) {
			$compiled = $this->compileCaseCondition(trim($seg[1]));
			if ($compiled === null) {
				return null;
			}
			$segments[] = 'WHEN ' . $compiled . ' THEN ' . (int) $seg[2];
		}

		if ($segments === [] || preg_match_all('/\bWHEN\b/i', $body) !== count($segments)) {
			return null;
		}

		$stripped = preg_replace($pattern, ' ', $body);
		if (is_string($stripped) && trim($stripped) !== '') {
			return null;
		}

		$sql = 'CASE ' . implode(' ', $segments);
		if ($elseVal !== null) {
			$sql .= ' ELSE ' . $elseVal;
		}
		$sql .= ' END';

		return $sql . ' ' . $direction;
	}

	/**
	 * 编译 CASE WHEN 的单个条件（受控白名单）
	 *
	 * 只接受三种形态：field = '字符串'、field = 整数、field IN (值,...)。
	 * 字段必须存在于 fieldList；值只接受字面量并做引号转义。
	 *
	 * @param string $cond 条件片段
	 * @return string|null 编译后的安全条件；不合法返回 null
	 */
	private function compileCaseCondition(string $cond): ?string
	{
		$cond = trim($cond);

		if (preg_match("/^([a-zA-Z0-9_]+)\s*=\s*'((?:[^'\\\\]|\\\\.)*)'$/s", $cond, $m) === 1) {
			$field = $m[1];
			if (!in_array($field, $this->fieldList, true)) {
				return null;
			}

			return $this->quoteIdent($field) . " = '" . str_replace("'", "''", $m[2]) . "'";
		}

		if (preg_match('/^([a-zA-Z0-9_]+)\s*=\s*(-?\d+)$/s', $cond, $m) === 1) {
			$field = $m[1];
			if (!in_array($field, $this->fieldList, true)) {
				return null;
			}

			return $this->quoteIdent($field) . ' = ' . (int) $m[2];
		}

		if (preg_match('/^([a-zA-Z0-9_]+)\s+IN\s*\((.+)\)$/is', $cond, $m) === 1) {
			$field = $m[1];
			if (!in_array($field, $this->fieldList, true)) {
				return null;
			}
			$values = $this->splitFieldList($m[2]);
			if ($values === [] || count($values) > 100) {
				return null;
			}
			$compiled = [];
			foreach ($values as $v) {
				$v = trim($v);
				if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/s", $v, $vm) === 1) {
					$compiled[] = "'" . str_replace("'", "''", $vm[1]) . "'";
					continue;
				}
				if (preg_match('/^-?\d+$/s', $v) === 1) {
					$compiled[] = (string) (int) $v;
					continue;
				}

				return null;
			}

			return $this->quoteIdent($field) . ' IN (' . implode(', ', $compiled) . ')';
		}

		return null;
	}

	private function splitFieldList(string $fieldList): array
	{
		$fields = [];
		$buffer = '';
		$depth = 0;
		$quote = '';
		$length = strlen($fieldList);

		for ($index = 0; $index < $length; $index++) {
			$char = $fieldList[$index];
			if ($quote !== '') {
				$buffer .= $char;
				if ($char === $quote && ($index === 0 || $fieldList[$index - 1] !== '\\')) {
					$quote = '';
				}
				continue;
			}
			if ($char === '`' || $char === '"' || $char === "'") {
				$quote = $char;
				$buffer .= $char;
				continue;
			}
			if ($char === '(') {
				$depth++;
				$buffer .= $char;
				continue;
			}
			if ($char === ')') {
				$depth = max(0, $depth - 1);
				$buffer .= $char;
				continue;
			}
			if ($char === ',' && $depth === 0) {
				$fields[] = trim($buffer);
				$buffer = '';
				continue;
			}
			$buffer .= $char;
		}

		if (trim($buffer) !== '') {
			$fields[] = trim($buffer);
		}

		return $fields;
	}

	/**
	 * 构建 LIMIT 子句
	 *
	 * 默认 MySQL 风格；SQL Server 等驱动可覆盖 formatLimitSql。
	 *
	 * @param array $args [count] 或 [offset, count]
	 * @return string
	 */
	protected function compileLimit(array $args): string
	{
		if (empty($args)) {
			return '';
		}
		if (!is_numeric($args[0])) {
			return '';
		}
		if (count($args) == 2) {
			if (!is_numeric($args[1])) {
				return '';
			}
			return $this->formatLimitSql((int) $args[1], (int) $args[0]);
		}
		return $this->formatLimitSql((int) $args[0], 0);
	}

	/**
	 * 格式化分页片段（委托当前方言）
	 *
	 * @param int $limit 条数
	 * @param int $offset 偏移
	 * @return string
	 */
	protected function formatLimitSql(int $limit, int $offset = 0): string
	{
		return $this->dialect()::limitSql($limit, $offset);
	}

	/**
	 * SQL 标识符左右引号（委托当前方言）
	 *
	 * @return array{0:string,1:string}
	 */
	protected function sqlIdentQuotes(): array
	{
		return $this->dialect()::identQuotes();
	}

	/**
	 * 引用标识符（委托当前方言）
	 *
	 * @param string $ident 标识符
	 * @return string
	 */
	protected function quoteIdent(string $ident): string
	{
		return $this->dialect()::quoteIdent($ident);
	}

	/**
	 * 解析当前方言类
	 *
	 * @return class-string<DialectInterface>
	 */
	protected function dialect(): string
	{
		if ($this instanceof DBServer) {
			return $this->dialectClass();
		}

		return DBServer::resolveDialectClass();
	}

	/**
	 * 处理 JOIN 关联查询
	 *
	 * 构建 LEFT JOIN 关联查询的 SQL 片段，包括：
	 * - 表别名
	 * - JOIN 条件
	 * - 关联字段
	 * - 关联查询的 WHERE、ORDER、GROUP、HAVING 条件
	 *
	 * @return array 包含关联查询各部分的数组
	 *   - as: 主表别名
	 *   - join: JOIN 子句字符串
	 *   - joinField: 关联表字段数组
	 *   - joinWhere: 关联表 WHERE 条件
	 *   - joinDate: 关联表查询参数
	 *   - joinOrder: 关联表 ORDER 条件
	 *   - joinGroup: 关联表 GROUP 条件
	 *   - joinHaving: 关联表 HAVING 条件
	 */
	private function compileJoin(): array
	{
		$join = [
			'as'=>'',
			'explicit'=>false,
			'join'=>'',
			'joinField'=>[],
			'joinWhere'=>'',
			'joinDate'=>[],
			'joinOrder'=>'',
			'joinGroup'=>'',
			'joinHaving'=>'',
		];
		$joinTabs = $this->sqlArgs('join');
		if(!empty($joinTabs)){
			$hasExplicitJoin = false;
			foreach ((array)$joinTabs as $tab) {
				if (is_array($tab) && ($tab['__explicit'] ?? false) === true) {
					$hasExplicitJoin = true;
					break;
				}
			}
			$alias = $this->sqlArgs('alias');
			if ($hasExplicitJoin) {
				if (!is_array($alias) || trim((string)($alias[0] ?? '')) === '') {
					throw new \LogicException('显式 JOIN 必须先设置主表 alias()');
				}
				$join['as'] = $this->quoteIdent(trim((string)$alias[0])) . '.';
				$join['explicit'] = true;
			} elseif (is_array($alias) && trim((string)($alias[0] ?? '')) !== '') {
				$join['as'] = trim((string)$alias[0]) . '.';
			} else {
				$baseName = str_replace((string)$this->tablePrefix, '', $this->tabName);
				$join['as'] = ($baseName[0] ?? 't') . '.';
			}
			
			$i = 1;
			foreach($joinTabs as $tab){
				if (is_array($tab) && ($tab['__explicit'] ?? false) === true) {
					$join['join'] .= $this->compileExplicitJoin($tab);
					continue;
				}
				[$tabName, $field, $fk] = $tab;
				//as
				if(is_array($tabName)){
					[$tabName, $joinTableAs] = $tabName;
				}else{
					$joinTableAs = strtolower($tabName)[0] . $i;
					$i++;
				}
				//join on
				is_array($fk) ? [$fk, $pri] = $fk : $pri = $this->fieldList['pri'];
				$join['join'] .= ' LEFT JOIN ' . $this->tablePrefix . $tabName . ' AS ' . $joinTableAs . ' ON ' . $join['as'] . $this->quoteIdent($pri) . ' = '. $joinTableAs .'.' . $this->quoteIdent($fk);
				
				//fields
				is_string($field) && $field = [$field];
				$join['joinField'][] = self::getFormatStr('fields', ' ', $field, ['*'], $joinTableAs . '.');
				
				//where order group have
				if(isset($tab[3])){
					//where
					if(!empty($tab[3][0])){
						$cachefile = C('RUNTIME') . 'Data/'.$tabName.'.php';
						$joinPri = '';
						$json = !\Candy\Core\Cache::isFileDriver() ? (file_exists($cachefile) ? false : ltrim(file_get_contents($cachefile),'<?php ')) : \Candy\Core\Cache::store()->get('data.' . md5($cachefile), false);
						if($json != false){
							$joinPri = (array)json_decode(substr($json, 0, -3), true)['pri'];	
						}
						if(isset($tab[3][0][1])){
							//OR
							$joinInfo = $this->compileWhere($tab[3][0][1], $joinTableAs. '.', ' OR ' . (count($tab[3][0][1]) > 1 ? '(' : ''), $joinPri, $joinTableAs);
							$join['joinWhere'] .= $joinInfo['where'] . (count($tab[3][0][1]) > 1 ? ')' : '');
						}else{
							//AND
							$joinInfo = $this->compileWhere($tab[3][0][0], $joinTableAs. '.', ' AND ', $joinPri, $joinTableAs);
							$join['joinWhere'] .= $joinInfo['where'];
						}
						$join['joinDate'] = array_merge($join['joinDate'], $joinInfo['data']);
					}
					//order
					if(!empty($tab[3][1])){
						$join['joinOrder'] = ',' . $this->compileOrder(self::getFormatStr('order', '', $tab[3][1], [], $joinTableAs. '.'));
					}
					//group
					if(!empty($tab[3][2])){
						$join['joinGroup'] = ',' . $this->compileGroup(self::getFormatStr('group', '', $tab[3][2], [], $joinTableAs. '.'));
					}
					// HAVING 
					if(!empty($tab[3][3])){
						$join['joinHaving'] = $this->compileHaving(self::getFormatStr('having', ' HAVING ', $tab[3][3], [], $joinTableAs. '.'));
					}
				}
			}
		}
		
		//查询范围
		if($this->sqlArgs('scope') != ''){
			$scopeList = stripos($this->sqlArgs('scope')[0], ',') ? explode(',', $this->sqlArgs('scope')[0]) : [$this->sqlArgs('scope')[0]];
			foreach($scopeList as $scope){
				//查询范命名规则  scope + 名称(首字母大写)
				$scopeName = 'scope'. ucfirst($scope);
				if(method_exists($this, $scopeName)){
					call_user_func([$this, $scopeName], $this);
				}
			}
			
		}
		
		return $join;
	}

	/**
	 * 编译显式 JOIN 子句。
	 *
	 * @param array $spec 已校验的 JOIN 配置
	 * @return string JOIN SQL 片段
	 */
	private function compileExplicitJoin(array $spec): string
	{
		$table = $this->compileJoinTableName((string)($spec['table'] ?? ''));
		$alias = $this->quoteIdent((string)($spec['alias'] ?? ''));
		$type = strtoupper((string)($spec['type'] ?? 'LEFT'));
		$join = ' ' . $type . ' JOIN ' . $this->quoteIdent($table) . ' AS ' . $alias;
		if ($type === 'CROSS') {
			return $join;
		}

		$conditions = [];
		foreach ((array)($spec['on'] ?? []) as $condition) {
			$conditions[] = $this->compileJoinIdentifier((string)($condition['left'] ?? ''))
				. ' ' . (string)($condition['operator'] ?? '=') . ' '
				. $this->compileJoinIdentifier((string)($condition['right'] ?? ''));
		}
		if ($conditions === []) {
			throw new \LogicException('JOIN ON 条件不能为空');
		}

		return $join . ' ON ' . implode(' AND ', $conditions);
	}

	/**
	 * 补齐 JOIN 逻辑表名前缀。
	 *
	 * 已包含当前连接前缀的物理表名保持原样，逻辑表名由当前连接前缀自动补齐。
	 *
	 * @param string $table 逻辑或已带前缀表名
	 * @return string 物理表名
	 */
	private function compileJoinTableName(string $table): string
	{
		$table = trim($table);
		$prefix = (string)$this->tablePrefix;
		if ($table === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
			throw new \InvalidArgumentException('JOIN 表名不合法');
		}
		if ($prefix !== '' && str_starts_with($table, $prefix)) {
			return $table;
		}

		return $prefix . $table;
	}

	/**
	 * 按方言引用 JOIN ON 中的列标识符。
	 *
	 * @param string $identifier 表别名与列名，格式为 alias.column
	 * @return string 已引用标识符
	 */
	private function compileJoinIdentifier(string $identifier): string
	{
		$identifier = trim($identifier);
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier)) {
			throw new \InvalidArgumentException('JOIN ON 列标识符不合法');
		}

		return implode('.', array_map(
			fn(string $part): string => $this->quoteIdent($part),
			explode('.', $identifier)
		));
	}

	/**
	 * 格式化SQL字段字符串
	 * 
	 * 用于处理SELECT、ORDER BY、GROUP BY、HAVING等SQL子句中的字段列表。
	 * 支持自动添加反引号、处理多字段、处理表别名等。
	 * 
	 * @param string $name 参数名称（用于错误提示，如'fields'、'order'等）
	 * @param string $head SQL子句前缀（如' ORDER BY '、' GROUP BY '等）
	 * @param mixed $source 源数据，可以是字符串或数组
	 * @param array $default 默认值，当$source为空时使用
	 * @param string $as 表别名前缀（用于关联查询）
	 * @return string 格式化后的SQL字段字符串
	 * 
	 * @example
	 * ```php
	 * // 处理字段列表
	 * $fields = $this->getFormatStr('fields', ' ', ['id,name,age'], ['*'], '');
	 * // 返回: `id`,`name`,`age`
	 * 
	 * // 处理ORDER BY
	 * $order = $this->getFormatStr('order', ' ORDER BY ', ['id desc'], ['id'], '');
	 * // 返回: ORDER BY `id` desc
	 * 
	 * // 处理带别名的字段
	 * $fields = $this->getFormatStr('fields', ' ', ['id,name'], ['*'], 'u.');
	 * // 返回: u.`id`, u.`name`
	 * ```
	 */
	protected function getFormatStr(string $name,string $head,mixed $source,array $default = [],string $as = ''): string
	{
		[$lq, $rq] = $this->sqlIdentQuotes();
		// 解析源数据：如果为空则使用默认值；如果是数组且有第二个元素则取第二个元素；
		// 否则如果第一个元素不包含逗号则转为单元素数组，否则按逗号分割为数组
		$return = $source == ''
			? $default
			: (isset($source[1]) ? trim($source[1]) : $this->splitFieldList((string)($source[0] ?? '')));
		$explicitJoin = false;
		foreach ((array)$this->sqlArgs('join') as $joinTab) {
			if (is_array($joinTab) && ($joinTab['__explicit'] ?? false) === true) {
				$explicitJoin = true;
				break;
			}
		}
		if ($name === 'fields' && $explicitJoin && is_array($return) && $return !== []) {
			$fields = [];
			foreach ($return as $value) {
				$trim = trim((string)$value);
				if ($trim === '*') {
					$fields[] = $as . '*';
					continue;
				}
				if ($trim === '' || str_contains($trim, $lq) || str_contains($trim, $rq)
					|| str_contains($trim, ' ') || str_contains($trim, '.') || str_contains($trim, '(') || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $trim) === 1) {
					$fields[] = $trim;
					continue;
				}
				if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trim) === 1) {
					$fields[] = $as . $lq . $trim . $rq;
					continue;
				}
				$fields[] = $trim;
			}

			return $head . implode(', ', $fields);
		}
		
		// 处理数组类型的字段列表
		if(is_array($return)){
			if(count($return) > 0){
				// 处理多字段情况（字段数量大于1）
				if(count($return) > 1){
					// 多字段：为每个字段添加标识符引号和表别名
					$parts = [];
					foreach ($return as $v) {
						// 将中文逗号替换为英文逗号
						if(stripos((string)$v, '，') !== false) {
							$v = str_replace('，', ',', (string)$v);
						}
						$trim = trim((string)$v);
						// 通配符*不需要添加引号
						if($trim === '*') {
							$parts[] = $as . '*';
							continue;
						}
						// 已含任一侧引号、空格（如 id desc）或表达式：保持主体，避免再拼主表 as
						if (str_contains($trim, $lq) || str_contains($trim, $rq) || str_contains($trim, ' ') || str_contains($trim, '(') || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $trim) === 1) {
							// 已是 alias.column / 表达式时禁止变成 inv.loc.column
							if ($as !== '' && (
								preg_match('/^[A-Za-z_][A-Za-z0-9_]*\./', $trim) === 1
								|| str_contains($trim, '(')
							)) {
								$parts[] = $trim;
							} else {
								$parts[] = $as . $trim;
							}
							continue;
						}
						$parts[] = $as . $lq . $trim . $rq;
					}
					$return = $head . implode(', ', $parts);
				}else{
					// 单字段情况
					is_null($return[0]) && $return[0] = '';
					if($return[0] === '*') {
						// 通配符*直接返回
						$return = $return[0];
					}else{
						// 将中文逗号替换为英文逗号
						if(stripos($return[0], '，')) $return[0] = str_replace('，', ',', $return[0]);
						$trim = trim($return[0]);
						if (str_contains($trim, $lq) || str_contains($trim, $rq) || str_contains($trim, ' ') || str_contains($trim, '(') || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $trim) === 1) {
							if ($as !== '' && (
								preg_match('/^[A-Za-z_][A-Za-z0-9_]*\./', $trim) === 1
								|| str_contains($trim, '(')
							)) {
								$return = $head . $trim;
							} else {
								$return = $head . $as . $trim;
							}
						} else {
							$return = $head . $as . $lq . $trim . $rq;
						}
					}
				}
			}else{
				// 空数组返回空字符串
				return '';
			}
		}else{
			// 处理字符串类型的输入
			if($return == '*') return $return;
			
			// 手写严格模式：字段名须用当前方言引号括起来
			if (!str_contains($return, $lq) && !str_contains($return, $rq)) {
				if(stripos($return, ',') === false){
					// 单字段且没有逗号，自动添加引号
					return $as . $lq . $return . $rq;
				}else{
					// 多字段但没有用引号括起来，提示错误并返回空字符串
					Debug::showMessage('手动输入'. $name .'参数字段名称必须以方言标识符引号括起来！', 'SQL');
					return '';
				}
			}
			
			// 关联查询模式下，字段名必须带表前缀（如'users.id'）
			if(!empty($this->sqlArgs('join')) && stripos($return, '.') === false){
				Debug::showMessage('左关联模式下手动输入'. $name .'参数字段名称必须带上表头', 'SQL');
			}
		}
		
		return $return;
	}

}
