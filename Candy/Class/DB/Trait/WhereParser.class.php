<?php
/**
 * WHERE 条件解析 Trait
 *
 * 统一解析链式 where / orWhere 参数，生成参数化 SQL 片段。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-07 03:56:00 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Extend\Arr;

trait WhereParser
{
    private const WHERE_OPS = ['=', '<>', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS'];

    /**
     * ThinkPHP 风格操作符别名 → 标准 SQL 操作符
     *
     * 业务侧可写 eq/neq/gt/egt/lt/elt/like/notin 等；内部统一归一化后再编译
     */
    private const WHERE_OP_ALIASES = [
        'EQ' => '=',
        'NEQ' => '<>',
        'GT' => '>',
        'EGT' => '>=',
        'LT' => '<',
        'ELT' => '<=',
        'NOTLIKE' => 'NOT LIKE',
        'NOTILIKE' => 'NOT ILIKE',
        'NOTIN' => 'NOT IN',
        'NOTBETWEEN' => 'NOT BETWEEN',
    ];

    /**
     * 编译 where 栈为 SQL 片段
     *
     * @param array<int, array{logic:string,args:array}> $stack
     * @return array{sql:string,data:array<int,mixed>}
     */
    protected static function compileWhereStack(array $stack, string $fieldPrefix = '', string $pri = 'id', string $quoteL = '`', string $quoteR = '`'): array
    {
        $groups = [];
        $data = [];

        foreach ($stack as $item) {
            if (!is_array($item)) {
                continue;
            }
            $logic = strtoupper((string)($item['logic'] ?? 'AND'));
            $args = $item['args'] ?? [];
            if ($args === []) {
                continue;
            }
            [$sql, $bind] = self::parseWhereArgs($args, $fieldPrefix, $pri, $quoteL, $quoteR);
            if ($sql === '') {
                continue;
            }
            $groups[] = ['logic' => $logic, 'sql' => $sql];
            array_push($data, ...$bind);
        }

        if ($groups === []) {
            return ['sql' => '', 'data' => []];
        }

        $built = '';
        foreach ($groups as $index => $group) {
            if ($index === 0) {
                $built = $group['sql'];
            } else {
                $built .= ' ' . $group['logic'] . ' ' . $group['sql'];
            }
        }

        return ['sql' => $built, 'data' => $data];
    }

    /**
     * 解析单次 where/orWhere 调用的参数
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    protected static function parseWhereArgs(array $args, string $fieldPrefix = '', string $pri = 'id', string $quoteL = '`', string $quoteR = '`'): array
    {
        if ($args === []) {
            return ['', []];
        }

        if (Arr::isAssoc($args)) {
            return self::parseWhereArrayCondition($args, $fieldPrefix, $pri, $quoteL, $quoteR);
        }

        if (count($args) === 1 && is_array($args[0])) {
            if ($args[0] === []) {
                return ['', []];
            }
            return self::parseWhereArrayCondition($args[0], $fieldPrefix, $pri, $quoteL, $quoteR);
        }

        if (count($args) === 1 && !is_array($args[0])) {
            return self::buildWhereComparison((string)$pri, '=', $args[0], $fieldPrefix, $quoteL, $quoteR);
        }

        if (!is_string($args[0])) {
            throw new \InvalidArgumentException('where 字段名必须为字符串');
        }

        $field = $args[0];
        if (count($args) === 2) {
            return self::buildWhereComparison($field, '=', $args[1], $fieldPrefix, $quoteL, $quoteR);
        }

        if (count($args) === 3) {
            return self::buildWhereComparison($field, (string)$args[1], $args[2], $fieldPrefix, $quoteL, $quoteR);
        }

        throw new \InvalidArgumentException('where 参数数量不合法');
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function parseWhereArrayCondition(array $condition, string $fieldPrefix, string $pri, string $quoteL = '`', string $quoteR = '`'): array
    {
        if ($condition === []) {
            return ['', []];
        }

        if (array_key_exists('_complex', $condition)) {
            $complex = $condition['_complex'];
            if (!is_array($complex)) {
                throw new \InvalidArgumentException('where _complex 条件必须为数组');
            }

            $logic = strtoupper((string)($complex['_logic'] ?? 'AND'));
            if (!in_array($logic, ['AND', 'OR'], true)) {
                throw new \InvalidArgumentException('where _complex 逻辑连接符不合法');
            }

            $parts = [];
            $data = [];
            foreach ($complex as $key => $value) {
                if ($key === '_logic') {
                    continue;
                }
                if (is_int($key) || (is_string($key) && $key !== '' && ctype_digit($key))) {
                    if (!is_array($value)) {
                        continue;
                    }
                    $branch = $value;
                } else {
                    $branch = [(string)$key => $value];
                }

                [$sql, $bind] = self::parseWhereArrayCondition(
                    $branch,
                    $fieldPrefix,
                    $pri,
                    $quoteL,
                    $quoteR
                );
                if ($sql !== '') {
                    $parts[] = '(' . $sql . ')';
                }
                array_push($data, ...$bind);
            }

            if ($parts === []) {
                return ['', []];
            }

            return ['(' . implode(' ' . $logic . ' ', $parts) . ')', $data];
        }

        if (Arr::isAssoc($condition)) {
            $assocPart = [];
            $indexedPart = [];
            foreach ($condition as $key => $value) {
                if (is_int($key) || (is_string($key) && $key !== '' && ctype_digit($key))) {
                    $indexedPart[] = $value;
                } else {
                    $assocPart[$key] = $value;
                }
            }

            // 关联键与数字下标混写：['status' => 1, ['id', '<>', 1]]
            if ($indexedPart !== []) {
                $parts = [];
                $data = [];
                if ($assocPart !== []) {
                    [$sql, $bind] = self::parseWhereArrayCondition($assocPart, $fieldPrefix, $pri, $quoteL, $quoteR);
                    if ($sql !== '') {
                        $parts[] = $sql;
                    }
                    array_push($data, ...$bind);
                }
                foreach ($indexedPart as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    [$sql, $bind] = self::parseWhereArrayCondition($item, $fieldPrefix, $pri, $quoteL, $quoteR);
                    if ($sql !== '') {
                        $parts[] = '(' . $sql . ')';
                    }
                    array_push($data, ...$bind);
                }
                if ($parts === []) {
                    return ['', []];
                }
                return [implode(' AND ', $parts), $data];
            }

            $parts = [];
            $data = [];
            foreach ($condition as $key => $value) {
                [$field, $op] = self::splitWhereFieldOperator((string)$key);
                [$sql, $bind] = self::buildWhereComparison($field, $op, $value, $fieldPrefix, $quoteL, $quoteR);
                if ($sql !== '') {
                    $parts[] = $sql;
                }
                array_push($data, ...$bind);
            }
            if ($parts === []) {
                return ['', []];
            }
            return [implode(' AND ', $parts), $data];
        }

        if (count($condition) === 1 && !is_array($condition[0])) {
            return self::buildWhereComparison((string)$pri, '=', $condition[0], $fieldPrefix, $quoteL, $quoteR);
        }

        if (count($condition) >= 2 && is_string($condition[0])) {
            $field = $condition[0];
            if (count($condition) === 2) {
                if (is_string($condition[1])) {
                    $nullOp = strtoupper(trim($condition[1]));
                    if ($nullOp === 'NULL') {
                        return self::buildWhereComparison($field, 'IS NULL', null, $fieldPrefix, $quoteL, $quoteR);
                    }
                    if ($nullOp === 'NOT NULL') {
                        return self::buildWhereComparison($field, 'IS NOT NULL', null, $fieldPrefix, $quoteL, $quoteR);
                    }
                }
                return self::buildWhereComparison($field, '=', $condition[1], $fieldPrefix, $quoteL, $quoteR);
            }
            return self::buildWhereComparison($field, (string)$condition[1], $condition[2], $fieldPrefix, $quoteL, $quoteR);
        }

        if (count($condition) > 0 && is_array($condition[0])) {
            $parts = [];
            $data = [];
            foreach ($condition as $item) {
                if (!is_array($item)) {
                    continue;
                }
                [$sql, $bind] = self::parseWhereArrayCondition($item, $fieldPrefix, $pri, $quoteL, $quoteR);
                if ($sql !== '') {
                    $parts[] = '(' . $sql . ')';
                }
                array_push($data, ...$bind);
            }
            if ($parts === []) {
                return ['', []];
            }
            return [implode(' OR ', $parts), $data];
        }

        throw new \InvalidArgumentException('where 数组条件格式不合法: ' . var_export($condition, true));
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitWhereFieldOperator(string $key): array
    {
        $key = trim($key);
        // 含 ThinkPHP 别名：eq/neq/gt/egt/lt/elt/notlike/notin/notbetween（长词优先）
        if (preg_match('/^(.+?)\s+(NOT\s+ILIKE|NOT\s+IN|NOT\s+BETWEEN|NOT\s+LIKE|NOT\s+NULL|IS\s+NOT\s+NULL|IS\s+NULL|<>|!=|NOTBETWEEN|NOTILIKE|NOTLIKE|NOTIN|EGT|ELT|NEQ|EQ|GT|LT|NOT|IN|ILIKE|LIKE|BETWEEN|>=|<=|>|<)$/i', $key, $m)) {
            $op = self::normalizeWhereOperator(trim($m[2]));
            return [trim($m[1]), $op];
        }

        return [$key, '='];
    }

    /**
     * 归一化 where 操作符（大小写、空格、ThinkPHP 别名）
     *
     * @param string $operator
     * @return string
     * @access private
     * @static
     */
    private static function normalizeWhereOperator(string $operator): string
    {
        $operator = strtoupper(preg_replace('/\s+/', ' ', trim($operator)) ?? '');
        if ($operator === 'NOT') {
            return '<>';
        }
        return self::WHERE_OP_ALIASES[$operator] ?? $operator;
    }

    /**
     * 判断字符串是否可作为「操作符数组」首元（避免把 IN 列表值误当操作符）
     *
     * @param string $token
     * @return bool
     * @access private
     * @static
     */
    private static function isWhereOperatorToken(string $token): bool
    {
        $normalized = self::normalizeWhereOperator($token);
        if ($normalized === 'EXP') {
            return true;
        }
        return in_array($normalized, self::WHERE_OPS, true);
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function buildWhereComparison(string $field, string $operator, mixed $value, string $fieldPrefix, string $quoteL = '`', string $quoteR = '`'): array
    {
        $field = self::normalizeWhereField($field);
        $operator = self::normalizeWhereOperator($operator);
        if (str_contains($field, '.')) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
                throw new \InvalidArgumentException('where 字段限定符不合法: ' . $field);
            }
            $quoted = implode('.', array_map(
                static fn(string $part): string => $quoteL . $part . $quoteR,
                explode('.', $field)
            ));
        } else {
            $quoted = ($fieldPrefix !== '' ? $fieldPrefix : '') . $quoteL . $field . $quoteR;
        }

        if ($operator === 'IS' || $operator === 'IS NULL') {
            return [$quoted . ' IS NULL', []];
        }
        if ($operator === 'IS NOT' || $operator === 'IS NOT NULL' || $operator === 'NOT NULL') {
            return [$quoted . ' IS NOT NULL', []];
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            $values = is_array($value) ? $value : explode(',', (string)$value);
            $values = array_values(array_filter($values, static fn($v) => $v !== ''));
            if ($values === []) {
                return ['', []];
            }
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            return [$quoted . ' ' . $operator . ' (' . $placeholders . ')', $values];
        }

        if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
            // ThinkPHP 常传 '18,30' 字符串；标准写法为 [18, 30]
            if (is_string($value) && str_contains($value, ',')) {
                $value = array_map('trim', explode(',', $value, 2));
            }
            if (!is_array($value) || count($value) < 2) {
                throw new \InvalidArgumentException($field . ' BETWEEN 需要两个边界值');
            }
            return [$quoted . ' ' . $operator . ' ? AND ?', [$value[0], $value[1]]];
        }

        if ($operator === 'EXP') {
            return ['(' . (string)$value . ')', []];
        }

        if (is_array($value)) {
            // ThinkPHP：['field' => ['egt', 100]] / ['like', '%x%']
            if (count($value) >= 2 && is_string($value[0]) && self::isWhereOperatorToken($value[0])) {
                return self::buildWhereComparison($field, (string)$value[0], $value[1], $fieldPrefix, $quoteL, $quoteR);
            }
            // 等值键下数组默认按 IN：['id' => [1, 2, 3]]
            if ($operator === '=') {
                return self::buildWhereComparison($field, 'IN', $value, $fieldPrefix, $quoteL, $quoteR);
            }
        }

        if (!in_array($operator, self::WHERE_OPS, true)) {
            throw new \InvalidArgumentException('不支持的操作符: ' . $operator);
        }

        if ($operator === 'ILIKE' || $operator === 'NOT ILIKE') {
            // ILIKE 仅 PostgreSQL 原生支持；统一降为 LOWER + LIKE，保持跨方言链式查询语义。
            $likeOperator = $operator === 'ILIKE' ? 'LIKE' : 'NOT LIKE';
            return ['LOWER(' . $quoted . ') ' . $likeOperator . ' LOWER(?)', [(string)$value]];
        }

        if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            return [$quoted . ' ' . $operator . ' ?', [(string)$value]];
        }

        return [$quoted . ' ' . $operator . ' ?', [$value]];
    }

    private static function normalizeWhereField(string $field): string
    {
        $field = trim($field);
        if (str_contains($field, '.')) {
            return $field;
        }
        return trim(str_replace(['`', '[', ']'], '', $field));
    }
}
