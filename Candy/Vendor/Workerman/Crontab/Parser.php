<?php
/**
 * @author:  Jan Konieczny <jkonieczny@gmail.com>, group@hyperf.io
 * @license: http://www.gnu.org/licenses/
 * @license: https://github.com/hyperf/hyperf/blob/master/LICENSE
 *
 *  This is a simple script to parse crontab syntax to get the execution time
 *
 *  Eg.:   $timestamp = Crontab::parse('12 * * * 1-5');
 *
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Provides basic cron syntax parsing functionality
 *
 * @author:  Jan Konieczny <jkonieczny@gmail.com>, group@hyperf.io
 * @license: http://www.gnu.org/licenses/
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Workerman\Crontab;

/**
 * Class Parser
 * cron 表达式解析器，支持五段（分）与六段（秒）两种格式
 * @package Workerman\Crontab
 */
class Parser
{
    /**
     * 解析 cron 表达式，返回当前时间窗口内所有匹配的执行时间戳
     *
     * 六段格式：在当前分钟内返回所有匹配的秒级时间戳
     * 五段格式：若当前分钟匹配则返回该分钟 0 秒时间戳
     *
     * @param string $crontab_string cron 表达式
     * @param null|int $start_time 起始时间戳，默认为当前时间
     * @throws \InvalidArgumentException 当 cron 表达式无效时抛出
     * @return int[] 匹配的时间戳数组
     */
    public function parse($crontab_string, $start_time = null)
    {
        if (!$this->isValid($crontab_string)) {
            throw new \InvalidArgumentException('Invalid cron string: ' . $crontab_string);
        }

        $start_time = $start_time ? $start_time : time();
        $date = $this->parseDate($crontab_string);
        $hasSecond = $this->isSixField($crontab_string);
        $minuteBase = (int) (floor($start_time / 60) * 60);

        if (!$this->matchesMinuteFields($minuteBase, $date)) {
            return [];
        }

        $result = [];
        if ($hasSecond) {
            foreach ($date['second'] as $second) {
                $ts = $minuteBase + (int) $second;
                if ($ts >= $start_time && $this->matchesTimestamp($ts, $date, true)) {
                    $result[] = $ts;
                }
            }
        } else {
            $ts = $minuteBase;
            if ($ts >= $start_time && $this->matchesTimestamp($ts, $date, false)) {
                $result[] = $ts;
            }
        }

        sort($result);
        return $result;
    }

    /**
     * 计算指定时间之后的下一次执行时间戳
     *
     * 六段表达式按秒迭代，五段表达式按分钟迭代（固定在 0 秒触发）
     *
     * @param string $crontab_string cron 表达式
     * @param int $after 基准时间戳，返回严格大于该值的下次执行时间；0 表示当前时间
     * @return int 下次执行时间戳，无匹配时返回 0
     */
    public function nextRunTimestamp(string $crontab_string, int $after = 0): int
    {
        if (!$this->isValid($crontab_string)) {
            return 0;
        }

        $after = $after > 0 ? $after : time();
        $date = $this->parseDate($crontab_string);
        $hasSecond = $this->isSixField($crontab_string);
        $limit = $after + 86400 * 366;

        if ($hasSecond) {
            for ($t = $after + 1; $t <= $limit; $t++) {
                if ($this->matchesTimestamp($t, $date, true)) {
                    return $t;
                }
            }
            return 0;
        }

        $t = (int) (floor(($after + 1) / 60) * 60);
        if ($t <= $after) {
            $t += 60;
        }
        while ($t <= $limit) {
            if ($this->matchesTimestamp($t, $date, false)) {
                return $t;
            }
            $t += 60;
        }

        return 0;
    }

    /**
     * 是否为六段（含秒）表达式
     *
     * @param string $crontab_string cron 表达式
     * @return bool
     */
    public function isSixField(string $crontab_string): bool
    {
        return count(preg_split('/\s+/i', trim($crontab_string))) === 6;
    }

    /**
     * 验证 cron 表达式是否有效
     * @param string $crontab_string cron 表达式字符串
     * @return bool 表达式是否有效
     */
    public function isValid(string $crontab_string): bool
    {
        if (! preg_match('/^((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)$/i', trim($crontab_string))) {
            if (! preg_match('/^((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)$/i', trim($crontab_string))) {
                return false;
            }
        }
        return true;
    }

    /**
     * 解析 cron 表达式的单个段（如分钟、小时等）
     * @param string $string 要解析的段字符串
     * @param int $min 该段的最小值
     * @param int $max 该段的最大值
     * @param int|null $start 起始值，默认为 null
     * @return array 解析后的数值数组
     */
    protected function parseSegment(string $string, int $min, int $max, ?int $start = null)
    {
        if ($start === null || $start < $min) {
            $start = $min;
        }
        $result = [];
        if ($string === '*') {
            for ($i = $start; $i <= $max; ++$i) {
                $result[] = $i;
            }
        } elseif (strpos($string, ',') !== false) {
            $exploded = explode(',', $string);
            foreach ($exploded as $value) {
                if (strpos($value, '/') !== false || strpos($value, '-') !== false) {
                    $result = array_merge($result, $this->parseSegment($value, $min, $max, $start));
                    continue;
                }
                if (trim($value) === '' || ! $this->between((int) $value, (int) ($min > $start ? $min : $start), (int) $max)) {
                    continue;
                }
                $result[] = (int) $value;
            }
        } elseif (strpos($string, '/') !== false) {
            $exploded = explode('/', $string);
            if (strpos($exploded[0], '-') !== false) {
                [$nMin, $nMax] = explode('-', $exploded[0]);
                $nMin > $min && $min = (int) $nMin;
                $nMax < $max && $max = (int) $nMax;
            }
            $start < $min && $start = $min;
            for ($i = $start; $i <= $max;) {
                $result[] = $i;
                $i += (int) $exploded[1];
            }
        } elseif (strpos($string, '-') !== false) {
            $result = array_merge($result, $this->parseSegment($string . '/1', $min, $max, $start));
        } elseif ($this->between((int) $string, $min > $start ? $min : $start, $max)) {
            $result[] = (int) $string;
        }
        return $result;
    }

    /**
     * 检查值是否在指定范围内
     * @param int $value 要检查的值
     * @param int $min 最小值
     * @param int $max 最大值
     * @return bool 值是否在范围内
     */
    private function between(int $value, int $min, int $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * 将 cron 表达式解析为日期数组
     * @param string $crontab_string cron 表达式字符串
     * @return array 包含秒、分钟、小时、日期、月份和星期的数组
     */
    private function parseDate(string $crontab_string): array
    {
        $cron = preg_split('/[\\s]+/i', trim($crontab_string));
        if (count($cron) == 6) {
            $date = [
                'second'  => $this->parseSegment($cron[0], 0, 59),
                'minutes' => $this->parseSegment($cron[1], 0, 59),
                'hours'   => $this->parseSegment($cron[2], 0, 23),
                'day'     => $this->parseSegment($cron[3], 1, 31),
                'month'   => $this->parseSegment($cron[4], 1, 12),
                'week'    => $this->parseSegment($cron[5], 0, 6),
            ];
        } else {
            $date = [
                'second'  => [0],
                'minutes' => $this->parseSegment($cron[0], 0, 59),
                'hours'   => $this->parseSegment($cron[1], 0, 23),
                'day'     => $this->parseSegment($cron[2], 1, 31),
                'month'   => $this->parseSegment($cron[3], 1, 12),
                'week'    => $this->parseSegment($cron[4], 0, 6),
            ];
        }
        return $date;
    }

    /**
     * 判断时间戳是否匹配 cron 各字段（不含秒字段时要求秒为 0）
     *
     * @param int $timestamp 时间戳
     * @param array $date parseDate 结果
     * @param bool $hasSecond 是否为六段表达式
     * @return bool
     */
    private function matchesTimestamp(int $timestamp, array $date, bool $hasSecond): bool
    {
        if ($hasSecond) {
            if (!in_array((int) date('s', $timestamp), $date['second'], true)) {
                return false;
            }
        } elseif ((int) date('s', $timestamp) !== 0) {
            return false;
        }

        return $this->matchesMinuteFields($timestamp, $date);
    }

    /**
     * 判断时间戳的分钟及以上字段是否匹配
     *
     * @param int $timestamp 时间戳
     * @param array $date parseDate 结果
     * @return bool
     */
    private function matchesMinuteFields(int $timestamp, array $date): bool
    {
        return in_array((int) date('i', $timestamp), $date['minutes'], true)
            && in_array((int) date('G', $timestamp), $date['hours'], true)
            && in_array((int) date('j', $timestamp), $date['day'], true)
            && in_array((int) date('w', $timestamp), $date['week'], true)
            && in_array((int) date('n', $timestamp), $date['month'], true);
    }
}
