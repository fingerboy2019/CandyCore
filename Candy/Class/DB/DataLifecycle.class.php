<?php
/***
 * 配置驱动的数据生命周期类库
 *
 * 业务说明：为任意注册业务表提供热转冷、年度冻结、临时解冻审查与冷热兼容查询
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 09:20:00 $
 *
 * @package Candy\Extend\DB
 */
declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class DataLifecycle
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    /** @return array<string,array> */
    public static function registrations(): array
    {
        $file = CANDYROOT . '/Source/Inc/DataLifecycle.inc.php';
        $rows = is_file($file) ? include $file : [];
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $key => $config) {
            try {
                $out[(string)$key] = self::validate((string)$key, (array)$config);
            } catch (\Throwable) {
                continue;
            }
        }
        return $out;
    }

    /**
     * Config
     * @param mixed $key
     * @return array
     */
    public static function config(string $key): array
    {
        $rows = self::registrations();
        if (!isset($rows[$key])) throw new \InvalidArgumentException('未注册的数据生命周期业务：' . $key);
        return $rows[$key];
    }

    /**
     * Preview
     * @param mixed $key
     * @return array
     */
    public static function preview(string $key): array
    {
        $cfg = self::config($key);
        $opts = self::freezeOptions($cfg);
        $cutoff = DataFreeze::hotWindowStart((int)$cfg['hot_months']);
        $hot = self::countRange($cfg['hot_table'], $cfg['time_column'], '<', $cutoff);
        $years = DataFreeze::listColdYears($opts);
        return ['code' => 0, 'message' => 'ok', 'data' => [
            'key' => $key, 'title' => $cfg['title'], 'enabled' => $cfg['enabled'],
            'cutoff' => $cutoff, 'move_count' => $hot,
            'cold_years' => $years['years'] ?? [],
            'freezable_years' => array_values(array_filter((array)($years['years'] ?? []),
                static fn(int $year): bool => $year <= (int)date('Y') - (int)$cfg['freeze_year_offset'])),
        ]];
    }

    /**
     * Status
     * @param mixed $key
     * @return array
     */
    public static function status(string $key): array
    {
        $cfg = self::config($key);
        return ['code' => 0, 'message' => 'ok', 'data' => [
            'key' => $key, 'title' => $cfg['title'], 'enabled' => $cfg['enabled'],
            'hot_count' => self::countAll($cfg['hot_table']),
            'cold_count' => self::countAll($cfg['cold_table']),
            'thaw_count' => self::countAll($cfg['thaw_table']),
            'preview' => self::preview($key)['data'] ?? [],
        ]];
    }

    /**
     * Run
     * @param mixed $key
     * @return array
     */
    public static function run(string $key): array
    {
        $cfg = self::config($key);
        if (!$cfg['enabled']) return ['code' => 1, 'message' => '该生命周期注册项未启用'];
        return DataFreeze::runTier(self::freezeOptions($cfg));
    }

    /**
     * Freeze Year
     * @param mixed $key
     * @param mixed $year
     * @return array
     */
    public static function freezeYear(string $key, int $year): array
    {
        $cfg = self::config($key);
        if ($year > (int)date('Y') - (int)$cfg['freeze_year_offset']) {
            return ['code' => 1, 'message' => '该年份尚不允许冻结'];
        }
        $opts = self::freezeOptions($cfg) + ['table' => $cfg['cold_table'], 'year' => $year];
        $pack = DataFreeze::pack($opts);
        if (($pack['code'] ?? 1) !== 0) return $pack;
        $purge = DataFreeze::purge($opts + [
            'pack_path' => $pack['path'], 'checksum' => $pack['checksum'], 'require_checksum' => true,
        ]);
        return ($purge['code'] ?? 1) === 0 ? $pack + ['purged' => $purge['deleted'] ?? 0] : $purge;
    }

    /**
     * 按年度将冻包解冻到临时审查表，并可写入审查批次标记。
     *
     * @param string $key 生命周期注册键
     * @param int $year 要解冻的自然年
     * @param string $batchId 审查批次号，可为空
     * @return array 解冻结果
     */
    public static function thawYear(string $key, int $year, string $batchId = ''): array
    {
        $cfg = self::config($key);
        if (self::countAll($cfg['thaw_table']) > 0) {
            return ['code' => 1, 'message' => '临时审查表非空，请先结束现有审查'];
        }
        $path = self::packPath($cfg, $year);
        $result = DataFreeze::thaw(self::freezeOptions($cfg) + [
            'pack_path' => $path, 'target_table' => $cfg['thaw_table'], 'truncate_first' => false,
        ]);
        if ((int)($result['code'] ?? 1) === 0 && $batchId !== '') {
            $db = self::prepareDriverTable(D('_dbDriver')->useWrite(), $cfg['thaw_table']);
            $db->where(['lifecycle_thaw_batch_id' => ''])->update([
                'lifecycle_thaw_batch_id' => $batchId,
                'lifecycle_thawed_at' => date('Y-m-d H:i:s'),
            ]);
            $result['thaw_batch_id'] = $batchId;
        }
        return $result;
    }

    /**
     * 清空临时审查表并返回清理条数。
     *
     * @param string $key 生命周期注册键
     * @return array 清理结果
     */
    public static function clearThaw(string $key): array
    {
        $cfg = self::config($key);
        $before = self::countAll($cfg['thaw_table']);
        $db = self::prepareDriverTable(D('_dbDriver')->useWrite(), $cfg['thaw_table']);
        $db->truncate();
        return ['code' => 0, 'message' => 'ok', 'cleared' => $before];
    }

    /**
     * 按临时审查批次 TTL 清理已过期数据。
     *
     * @param string $key 生命周期注册键
     * @return array 清理结果
     */
    public static function cleanupThaw(string $key): array
    {
        $cfg = self::config($key);
        $db = self::prepareDriverTable(D('_dbDriver'), $cfg['thaw_table']);
        $row = $db->field('MAX(lifecycle_thawed_at) AS last_at, COUNT(*) AS n')->find();
        $count = (int)($row['n'] ?? $row['N'] ?? 0);
        if ($count === 0) {
            return ['code' => 0, 'message' => 'ok', 'cleared' => 0];
        }
        $last = strtotime((string)($row['last_at'] ?? $row['LAST_AT'] ?? '')) ?: time();
        if ($last > time() - ((int)$cfg['thaw_ttl_hours'] * 3600)) {
            return ['code' => 0, 'message' => '临时审查批次尚未过期', 'cleared' => 0, 'skipped' => true];
        }
        return self::clearThaw($key);
    }

    /**
     * 查询临时审查表中的数据。
     *
     * @param string $key 生命周期注册键
     * @param array $query 结构化筛选、游标和条数
     * @return array 查询结果
     */
    public static function queryThaw(string $key, array $query): array
    {
        $cfg = self::config($key);
        $where = array_replace((array)$cfg['base_where'], is_array($query['where'] ?? null) ? $query['where'] : []);
        $conditions = [];
        foreach ($where as $field => $value) {
            if (!in_array($field, $cfg['allowed_where'], true)) {
                throw new \InvalidArgumentException('不允许的审查字段：' . $field);
            }
            if (is_array($value)) {
                if ($value === []) {
                    return ['code' => 0, 'message' => 'ok', 'list' => []];
                }
                $conditions[$field] = ['IN', array_values($value)];
            } else {
                $conditions[$field] = $value;
            }
        }
        $db = self::prepareDriverTable(D('_dbDriver'), $cfg['thaw_table']);
        if ($conditions !== []) {
            $db->where($conditions);
        }
        $before = (int)($query['before_id'] ?? 0);
        if ($before > 0) {
            $db->where($cfg['primary_key'], '<', $before);
        }
        $rows = $db->order($cfg['primary_key'] . ' DESC')
            ->limit(0, max(1, min(100, (int)($query['limit'] ?? 30))))
            ->select();
        return ['code' => 0, 'message' => 'ok', 'list' => is_array($rows) ? $rows : []];
    }

    /**
     * 查询热表与冷表合并后的在线数据。
     *
     * 查询操作符先通过生命周期白名单校验，再交由 ORM WhereParser 绑定参数。
     *
     * @param string $key 生命周期注册键
     * @param array $query 结构化筛选、游标、排序和条数
     * @return array 查询结果
     */
    public static function queryOnline(string $key, array $query): array
    {
        $cfg = self::config($key);
        $where = is_array($query['where'] ?? null) ? $query['where'] : [];
        $conditions = [];
        foreach ($where as $field => $value) {
            if (!in_array($field, $cfg['allowed_where'], true)) {
                throw new \InvalidArgumentException('不允许的查询字段：' . $field);
            }
            if (is_array($value) && isset($value[0])) {
                $op = strtolower((string)$value[0]);
                $operand = $value[1] ?? null;
                if ($op === 'in' && is_array($operand) && $operand !== []) {
                    $conditions[$field] = ['IN', array_values($operand)];
                } elseif ($op === 'like') {
                    $conditions[$field] = ['LIKE', $operand];
                } elseif (in_array($op, ['gt', 'egt', 'lt', 'elt', 'neq'], true)) {
                    $conditions[$field] = [
                        ['gt'=>'>','egt'=>'>=','lt'=>'<','elt'=>'<=','neq'=>'<>'][$op],
                        $operand,
                    ];
                } elseif ($op === 'between' && is_array($operand) && count($operand) === 2) {
                    $conditions[$field] = ['BETWEEN', array_values($operand)];
                } else {
                    throw new \InvalidArgumentException('不允许的查询操作符：' . $op);
                }
            } else {
                $conditions[$field] = $value;
            }
        }
        $result = DataFreeze::queryRange(self::freezeOptions($cfg) + [
            'before_id' => (int)($query['before_id'] ?? 0),
            'after_id' => (int)($query['after_id'] ?? 0),
            'limit' => (int)($query['limit'] ?? 30),
            'order' => (string)($query['order'] ?? 'desc'),
            'where' => $conditions,
        ]);
        if (is_callable($cfg['result_formatter'] ?? null) && is_array($result['list'] ?? null)) {
            $result['list'] = array_map(
                static fn(array $row): array => (array)call_user_func($cfg['result_formatter'], $row, $key),
                $result['list']
            );
        }
        return $result;
    }

    /**
     * Validate
     * @param mixed $key
     * @param mixed $cfg
     * @return array
     */
    private static function validate(string $key, array $cfg): array
    {
        if (!preg_match(self::KEY_PATTERN, $key)) throw new \InvalidArgumentException('注册键无效');
        foreach (['hot_table', 'cold_table', 'thaw_table', 'primary_key', 'time_column'] as $name) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)($cfg[$name] ?? ''))) {
                throw new \InvalidArgumentException($name . ' 无效');
            }
        }
        $packDir = trim((string)($cfg['pack_dir'] ?? ''));
        if ($packDir === '' || str_starts_with($packDir, '/') || str_contains($packDir, '..')) {
            throw new \InvalidArgumentException('pack_dir 无效');
        }
        $cfg['key'] = $key;
        $cfg['title'] = (string)($cfg['title'] ?? $key);
        $cfg['enabled'] = !empty($cfg['enabled']);
        $cfg['hot_months'] = max(1, min(11, (int)($cfg['hot_months'] ?? 3)));
        $cfg['freeze_year_offset'] = max(2, (int)($cfg['freeze_year_offset'] ?? 2));
        $cfg['batch_size'] = max(100, min(5000, (int)($cfg['batch_size'] ?? 500)));
        $cfg['thaw_ttl_hours'] = max(1, min(168, (int)($cfg['thaw_ttl_hours'] ?? 24)));
        $cfg['pack_dir'] = $packDir;
        $cfg['allowed_where'] = array_values(array_filter((array)($cfg['allowed_where'] ?? []),
            static fn($v): bool => is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $v)));
        $cfg['base_where'] = is_array($cfg['base_where'] ?? null) ? $cfg['base_where'] : [];
        $cfg['permission_checker'] = $cfg['permission_checker'] ?? null;
        $cfg['result_formatter'] = $cfg['result_formatter'] ?? null;
        return $cfg;
    }

    /**
     * Freeze Options
     * @param mixed $cfg
     * @return array
     */
    private static function freezeOptions(array $cfg): array
    {
        return [
            'hot_table' => $cfg['hot_table'], 'cold_table' => $cfg['cold_table'],
            'time_column' => $cfg['time_column'], 'pk' => $cfg['primary_key'],
            'hot_months' => $cfg['hot_months'], 'freeze_year_offset' => $cfg['freeze_year_offset'],
            'batch_size' => $cfg['batch_size'], 'pack_dir' => CANDYROOT . '/' . $cfg['pack_dir'],
            'set_archived_at' => true, 'archived_at_column' => 'lifecycle_archived_at',
        ];
    }

    /**
     * Pack Path
     * @param mixed $cfg
     * @param mixed $year
     * @return string
     */
    private static function packPath(array $cfg, int $year): string
    {
        return CANDYROOT . '/' . trim($cfg['pack_dir'], '/') . '/' . $year;
    }

    /**
     * Count All
     * @param mixed $table
     * @return int
     */
    private static function countAll(string $table): int
    {
        return self::countRange($table, '', '', '');
    }

    /**
     * Count Range
     * @param mixed $table
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return int
     */
    private static function countRange(string $table, string $column, string $operator, string $value): int
    {
        $db = self::prepareDriverTable(D('_dbDriver'), $table);
        if ($column !== '') {
            if (!in_array($operator, ['=', '<>', '<', '<=', '>', '>='], true)) {
                throw new \InvalidArgumentException('不支持的生命周期统计操作符：' . $operator);
            }
            $db->where($column, $operator, $value);
        }
        return (int)$db->total();
    }

    /**
     * Physical Table
     * @param mixed $table
     * @return string
     */
    private static function physicalTable(string $table): string
    {
        $prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
        if ($prefix !== '' && str_starts_with($table, $prefix)) return $table;
        return $prefix !== '' ? $prefix . $table : $table;
    }

    /**
     * 准备链式驱动并绑定逻辑表名。
     *
     * @param object $db 数据库驱动
     * @param string $table 逻辑表名
     * @return object 已清空查询状态并完成表绑定的驱动
     */
    private static function prepareDriverTable(object $db, string $table): object
    {
        $db->clearSql();
        $db->setTable(self::logicalTable(self::physicalTable($table)));
        return $db;
    }

    /**
     * 将生命周期物理表名还原为 ORM 驱动使用的逻辑表名。
     *
     * @param string $table 物理表名
     * @return string 逻辑表名
     */
    private static function logicalTable(string $table): string
    {
        $prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }
        return $table;
    }
}
