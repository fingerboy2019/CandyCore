<?php
/***
 * 时间处理工具类
 *
 * 业务说明：时间戳/日期互转、格式化、加减、时区、区间与精度时间戳
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 03:19:59 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Time
{
    //常见的特殊日期格式
    private static $dateFormats = ['Y-m-d', 'm/d/Y', 'd.m.Y', 'm.d.y', 'd/m/Y', 'Y年m月d日', 'Y年m月', 'Y年m月d号', 'Y/m/d', 'Y.m.d', 'Y.m', 'F d, Y', 'M d, Y', 'F j, Y', 'M j, Y', 'F jS, Y', 'M jS, Y'];
    //常见的特殊时间格式
    private static $timeFormats = ['H', 'H:i', 'H:i:s', 'H点', 'H点i分', 'H点i分s秒', 'H时', 'H时i分', 'H时i分s秒', 'g:i a', 'h:i a'];

    /**
     * 判断是否为时间戳格式
     * @param $timestamp 要判断的字符串
     * @return bool 如果是时间戳返回True,否则返回False
     */
    public static function isTimestamp(int|string $timestamp): bool
    {
        $start = strtotime('1970-01-01 00:00:00');
        $end = strtotime('2099-12-31 23:59:59');
        //判断是否为时间戳
        if (!empty($timestamp) && is_numeric($timestamp) && $timestamp <= $end && $timestamp >= $start) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 将任意时间类型的参数转为时间戳
     * 请注意 m/d/y 或 d-m-y 格式的日期，如果分隔符是斜线（/），则使用美洲的 m/d/y 格式。如果分隔符是横杠（-）或者点（.），则使用欧洲的 d-m-y 格式。为了避免潜在的错误，您应该尽可能使用 YYYY-MM-DD 格式或者使用 date_create_from_format() 函数。
     * @param $datetime 要转换为时间戳的字符串或数字,如果为空则返回当前时间戳
     * @return int 时间戳
     */
    public static function toTimestamp($datetime = null): int
    {
        if (empty($datetime)) {
            return time();
        }

        $start = strtotime('1970-01-01 00:00:00');
        $end = strtotime('2099-12-31 23:59:59');
        //判断是否为时间戳
        if (is_numeric($datetime) && $datetime <= $end && $datetime >= $start) {
            return intval($datetime);
        } else {
            $timestamp = strtotime($datetime);
            if ($timestamp) {
                return $timestamp;
            } else {
                /* 尝试处理特殊的日期格式 */
                [$date, $time] = explode(' ', $datetime);
                if ($date) {
                    //获取时间的格式
                    $time_format_str = '';
                    if ($time) {
                        foreach (self::$timeFormats as $time_format) {
                            if (date_create_from_format($time_format, $time) !== false) {
                                $time_format_str = $time_format;
                                break;
                            }
                        }
                    }
                    foreach (self::$dateFormats as $date_format) {
                        //获取日期的格式
                        if (date_create_from_format($date_format, $date) !== false) {
                            $datetime_format = ($time_format_str) ? "$date_format $time_format_str" : $date_format;
                            //获取日期时间对象
                            $datetime_obj = date_create_from_format($datetime_format, $datetime);
                            if ($datetime_obj !== false) {
                                return strtotime($datetime_obj->format('Y-m-d' . ($time_format_str ? ' H:i:s' : '')));
                            }
                        }
                    }
                }
				
                throw new \InvalidArgumentException('Param datetime must be a timestamp or a string time');
            }
        }
    }

    /**
     * 将任意格式的时间转换为指定格式
     * @param $format   格式化字符串(默认为:Y-m-d H:i:s)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return false|string 格式化后的时间字符串
     */
    public static function format(string $format = 'Y-m-d H:i:s', $datetime = null): string
    {
        return date($format, self::toTimestamp($datetime));
    }

    /**
     * 根据日期字符串, 修改指定时间, 并返回修改后的时间戳
     * @param $modifier 日期修改字符串
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return int 时间戳
     */
    public static function modifyTimestamp(string $modifier, $datetime = null): int
    {
        $date = new \DateTime();
        $date->setTimestamp(self::toTimestamp($datetime));
        return $date->modify($modifier)->getTimestamp();
    }

    /**
     * 返回截止到今天晚上零点之前的秒数
     * @return int 秒数
     */
    public static function secondEndToday(): int
    {
        [$y, $m, $d] = explode('-', date('Y-m-d'));
        return mktime(23, 59, 59, intval($m), intval($d), intval($y)) - time();
    }

    /**
     * 返回一分钟的秒数,传入参数可以返回数分钟的秒数
     * @param $minutes 分钟数,默认为1分钟
     * @return int 秒数
     */
    public static function secondMinute(int $minutes = 1): int
    {
        return 60 * $minutes;
    }

    /**
     * 返回一小时的秒数,传入参数可以返回数小时的秒数
     * @param $hours 小时数,默认为1小时
     * @return int 秒数
     */
    public static function secondHour(int $hours = 1): int
    {
        return 3600 * $hours;
    }

    /**
     * 返回一天的秒数,传入参数可以返回数天的秒数
     * @param $days 天数,默认为1天
     * @return int 秒数
     */
    public static function secondDay(int $days = 1): int
    {
        return 86400 * $days;
    }

    /**
     * 返回一周的秒数,传入参数可以返回数周的秒数
     * @param $weeks 周数,默认为1周
     * @return int 秒数
     */
    public static function secondWeek(int $weeks = 1): int
    {
        return 604800 * $weeks;
    }

    /**
     * 讲时间转换为友好显示格式
     * @param $datetime 时间日期的字符串或数字
     * @param $lang     语言,默认为中文,如果要显示英文传入en即可
     * @return string 转换后的友好时间格式
     */
    public static function toFriendly(int|string $datetime, string $lang = 'zh'): string
    {
        $interval = self::getDateDiff($datetime);

        $count = 0;
        $type = '';

        if ($interval->y) {
            $count = $interval->y;
            $type = $lang == 'zh' ? '年' : ' year';
        } elseif ($interval->m) {
            $count = $interval->m;
            $type = $lang == 'zh' ? '月' : ' month';
        } elseif ($interval->d) {
            $count = $interval->d;
            $type = $lang == 'zh' ? '天' : ' day';
        } elseif ($interval->h) {
            $count = $interval->h;
            $type = $lang == 'zh' ? '小时' : ' hour';
        } elseif ($interval->i) {
            $count = $interval->i;
            $type = $lang == 'zh' ? '分钟' : ' minute';
        } elseif ($interval->s) {
            $count = $interval->s;
            $type = $lang == 'zh' ? '秒' : ' second';
        }

        if (empty($type)) {
            return $lang == 'zh' ? '刚刚' : 'just now';
        } else {
            return $count . $type . ($lang == 'zh' ? '前' : (($count > 1 ? 's' : '') . ' ago'));
        }
    }

    /**
     * 判断日期是否为今天
     * @param $datetime 时间日期
     * @return bool 如果是今天则返回True,否则返回False
     */
    public static function isToday(int|string $datetime): bool
    {
        return self::format('Y-m-d', $datetime) == self::format('Y-m-d');
    }

    /**
     * 判断日期是否为昨天
     * @param $datetime 时间日期
     * @return bool 如果是昨天则返回True,否则返回False
     */
    public static function isYesterday(int|string $datetime): bool
    {
        return self::format('Y-m-d', $datetime) == self::format('Y-m-d', strtotime('-1 day'));
    }

    /**
     * 判断日期是否为明天
     * @param $datetime 时间日期
     * @return bool 如果是明天则返回True,否则返回False
     */
    public static function isTomorrow(int|string $datetime): bool
    {
        return self::format('Y-m-d', $datetime) == self::format('Y-m-d', strtotime('+1 day'));
    }

    /**
     * 判断日期是否为本周
     * @param  $datetime 时间日期
     * @return bool 如果是本周则返回True,否则返回False
     */
    public static function isThisWeek(int|string $datetime): bool
    {
        $week_start = strtotime(date('Y-m-d 00:00:00', strtotime('this week')));
        $week_end = strtotime(date('Y-m-d 23:59:59', strtotime('last day next week')));
        $timestamp = self::toTimestamp($datetime);
        return $timestamp >= $week_start && $timestamp <= $week_end;
    }

    /**
     * 判断日期是否为本月
     * @param $datetime 时间日期
     * @return bool 如果是本月则返回True,否则返回False
     */
    public static function isThisMonth(int|string $datetime): bool
    {
        return self::format('Y-m', $datetime) == self::format('Y-m');
    }

    /**
     * 判断日期是否为今年
     * @param $datetime 时间日期
     * @return bool 如果是今年则返回True,否则返回False
     */
    public static function isThisYear(int|string $datetime): bool
    {
        return self::format('Y', $datetime) == self::format('Y');
    }

    /**
     * 获得指定日期是星期几(默认为当前时间)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return int 星期几(1-7)
     */
    public static function getWeekDay(mixed $datetime = null): int
    {
        return intval(self::format('N', $datetime));
    }

    /**
     * 判断指定日期是否为平常日(周一到周五)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return bool 是平常日返回true,否则返回false
     */
    public static function isWeekday(?int $datetime = null): bool
    {
        return in_array(self::getWeekDay($datetime), [1, 2, 3, 4, 5]);
    }

    /**
     * 判断指定日期是否为周末(周六和周日)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return bool 是周末返回true,否则返回false
     */
    public static function isWeekend(?int $datetime = null): bool
    {
        return in_array(self::getWeekDay($datetime), [6, 7]);
    }

    //获得两个日期得差量对象
    /**
     * Get Date Diff
     * @param mixed $datetime
     * @param mixed $newDatetime
     * @return \DateInterval
     */
    private static function getDateDiff(int $datetime,?int $newDatetime = null): \DateInterval
    {
        $datetime = self::format('Y-m-d H:i:s', $datetime);
        $newDatetime = self::format('Y-m-d H:i:s', $newDatetime);
        return date_diff(date_create($datetime), date_create($newDatetime));
    }

    /**
     * 返回两个日期相差的秒数(如果之传入一个日期,则与当前时间比较)
     * @param $datetime
     * @param $newDatetime
     * @return int
     */
    public static function diffSeconds($datetime, $newDatetime = null): int
    {
        $timestamp = self::toTimestamp($datetime);
        $new_timestamp = self::toTimestamp($newDatetime);
        return abs($new_timestamp - $timestamp);
    }

    /**
     * 返回两个日期相差分钟数(如果之传入一个日期,则与当前时间比较)
     * @param $datetime
     * @param $newDatetime
     * @return int
     */
    public static function diffMinutes($datetime, $newDatetime = null): int
    {
        return intval(self::diffSeconds($datetime, $newDatetime) / 60);
    }

    /**
     * 返回两个日期相差小时数(如果之传入一个日期,则与当前时间比较)
     * @param $datetime
     * @param $newDatetime
     * @return int
     */
    public static function diffHours($datetime, $newDatetime = null): int
    {
        return intval(self::diffSeconds($datetime, $newDatetime) / 3600);
    }

    /**
     * 返回两个日期相差天数(如果只传入一个日期,则与当天时间比较)
     * @param $datetime     要计算的时间
     * @param $newDatetime 要比较的时间(默认为当前时间)
     * @return int 相差天数
     */
    public static function diffDays($datetime, $newDatetime = null): int
    {
        return self::getDateDiff($datetime, $newDatetime)->days;
    }

    /**
     * 返回两个日期相差星期数(如果只传入一个日期,则与当天时间比较)
     * @param $datetime     要计算的时间
     * @param $newDatetime 要比较的时间(默认为当前时间)
     * @return int 相差星期数
     */
    public static function diffWeeks($datetime, $newDatetime = null): int
    {
        return intval(self::diffDays($datetime, $newDatetime) / 7);
    }

    /**
     * 返回两个日期相差月数(如果只传入一个日期,则与当天时间比较)
     * @param $datetime     要计算的时间
     * @param $newDatetime 要比较的时间(默认为当前时间)
     * @return int 相差月数
     */
    public static function diffMonths($datetime, $newDatetime = null): int
    {
        $diff = self::getDateDiff($datetime, $newDatetime);
        return $diff->y * 12 + $diff->m;
    }

    /**
     * 返回两个日期相差年数(如果只传入一个日期,则与当前时间比较)
     * @param $datetime     要计算的时间
     * @param $newDatetime 要比较的时间(默认为当前时间)
     * @return int 相差年数
     */
    public static function diffYears($datetime, $newDatetime = null): int
    {
        return self::getDateDiff($datetime, $newDatetime)->y;
    }

    /**
     * 返回N分钟前的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $minute   分钟数(默认为1分钟)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前分钟0秒的时间戳
     * @return int 时间戳
     */
    public static function beforeMinute(int $minute = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('-%d minute', $minute), $datetime);
        return $round ? strtotime(date('Y-m-d H:i:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N分钟后的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $minute   分钟数(默认为1分钟)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前分钟0秒的时间戳
     * @return int 时间戳
     */
    public static function afterMinute(int $minute = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('+%d minute', $minute), $datetime);
        return $round ? strtotime(date('Y-m-d H:i:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N小时前的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $hour     小时数(默认为1小时)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前小时0分钟的时间戳
     * @return int 时间戳
     */
    public static function beforeHour(int $hour = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('-%d hour', $hour), $datetime);
        return $round ? strtotime(date('Y-m-d H:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N小时后的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $hour     小时数(默认为1小时)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前小时0分钟的时间戳
     * @return int 时间戳
     */
    public static function afterHour(int $hour = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('+%d hour', $hour), $datetime);
        return $round ? strtotime(date('Y-m-d H:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N天前的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $day      天数(默认为1天)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前日期0点的时间戳
     * @return int 时间戳
     */
    public static function beforeDay(int $day = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('-%d day', $day), $datetime);
        return $round ? strtotime(date('Y-m-d 00:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N天后的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $day      天数(默认为1天)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前日期0点的时间戳
     * @return int 时间戳
     */
    public static function afterDay(int $day = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('+%d day', $day), $datetime);
        return $round ? strtotime(date('Y-m-d 00:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N星期前的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $week     星期数(默认为1星期)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return int 时间戳
     */
    public static function beforeWeek(int $week = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('-%d week', $week), $datetime);
        return $round ? strtotime(date('Y-m-d 00:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N星期后的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $week     星期数(默认为1星期)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return int 时间戳
     */
    public static function afterWeek(int $week = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('+%d week', $week), $datetime);
        return $round ? strtotime(date('Y-m-d 00:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N月前的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $month    月数(默认为1个月)
     * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前日期1号0点的时间戳
     * @return int 时间戳
     */
    public static function beforeMonth(int $month = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('-%d month', $month), $datetime);
        return $round ? strtotime(date('Y-m-1 00:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N月后的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $month    月数(默认为1个月)
     * @param mixed      $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前日期1号0点的时间戳
     * @return int 时间戳
     */
    public static function afterMonth(int $month = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('+%d month', $month), $datetime);
        return $round ? self::modifyTimestamp('last day of this month 23:59:59', $timestamp) : $timestamp;
    }

    /**
     * 返回N年前的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $year     年数(默认为1年)
     * @param mixed      $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前日期1月1号0点的时间戳
     * @return int 时间戳
     */
    public static function beforeYear(int $year = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('-%d year', $year), $datetime);
        return $round ? strtotime(date('Y-1-1 00:00:00', $timestamp)) : $timestamp;
    }

    /**
     * 返回N年后的时间戳,传入第二个参数,则从该时间开始计算
     * @param int        $year     年数(默认为1年)
     * @param mixed      $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @param bool       $round    是否取整(默认false),如果传入true,则返回当前日期1月1号0点的时间戳
     * @return int 时间戳
     */
    public static function afterYear(int $year = 1, $datetime = null, bool $round = false): int
    {
        $timestamp = self::modifyTimestamp(sprintf('+%d year', $year), $datetime);
        return $round ? strtotime(date('Y-12-31 23:59:59', $timestamp)) : $timestamp;
    }

    /**
     * 获得秒级/毫秒级/微秒级/纳秒级时间戳
     * @param int $level 默认0,获得秒级时间戳. 1.毫秒级时间戳; 2.微秒级时间戳; 3.纳米级时间戳
     * @return int 时间戳
     */
    public static function getTimestamp(int $level = 0): int
    {
        if ($level === 0) return time();
        [$msc, $sec] = explode(' ', microtime());
        if ($level === 1) {
            return intval(sprintf('%.0f', (floatval($msc) + floatval($sec)) * 1000));
        } elseif ($level === 2) {
            return intval(sprintf('%.0f', (floatval($msc) + floatval($sec)) * 1000 * 1000));
        } else {
            return intval(sprintf('%.0f', (floatval($msc) + floatval($sec)) * 1000 * 1000 * 1000));
        }
    }

    /**
     * 获得毫秒级的时间戳
     * @return int 毫秒级时间戳
     */
    public static function getMilliTimestamp(): int
    {
        return self::getTimestamp(1);
    }

    /**
     * 获得微秒级的时间戳
     * @return int 微秒级时间戳
     */
    public static function getMicroTimestamp(): int
    {
        return self::getTimestamp(2);
    }

    /**
     * 获得纳秒级的时间戳
     * @return int 纳秒级时间戳
     */
    public static function getNanoTimestamp(): int
    {
        return self::getTimestamp(3);
    }

    /**
     * 判断该日期是否为闰年
     * @param mixed|null $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return bool 闰年返回true,否则返回false
     */
    public static function isLeapYear($datetime = null): bool
    {
        return self::format('L', $datetime) == 1;
    }

    /**
     * 判断该日期的当年有多少天
     * @param mixed|null $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return int 该年的天数
     */
    public static function daysInYear($datetime = null): int
    {
        return self::isLeapYear($datetime) ? 366 : 365;
    }

    /**
     * 判断该日期的当月有多少天
     * @param mixed|null $datetime 任意格式时间字符串或时间戳(默认为当前时间)
     * @return int 该月的天数
     */
    public static function daysInMonth($datetime = null): int
    {
        return intval(self::format('t', $datetime));
    }

    /**
     * 不同时区的时间转换
     * @param string      $toTimezone   目标时区
     * @param string|null $fromTimezone 原时区(默认为当前PHP运行环境所设置的时区)
     * @param mixed       $datetime     任意格式的时间字符串或时间戳(默认为当前时间)
     * @param string      $format       格式化字符串
     * @return string 转换后的格式化日期时间字符串
     * @throws Exception
     */
    public static function timezoneFormat(string $toTimezone, ?string $fromTimezone = null, $datetime = 'now', string $format = 'Y-m-d H:i:s'): string
    {
        if (self::isTimestamp($datetime)) {
            $date = new \DateTime();
            $datetime = $datetime === 'now' ? time() : $datetime;
            $date->setTimestamp($datetime);
            $date->setTimezone(new \DateTimeZone('UTC'));
        } else {
            if ($fromTimezone === null) {
                $fromTimezone = date_default_timezone_get();
            }
            $date = new \DateTime($datetime, new \DateTimeZone($fromTimezone));
        }
        $date->setTimezone(new \DateTimeZone($toTimezone));
        return $date->format($format);
    }

    /**
     * 比较两个时间的大小,如果第二个参数为空,则与当前时间比较
     * @param mixed      $datetime1 第一个时间（时间戳或时间字符串）
     * @param mixed|null $datetime2 第二个时间（时间戳或时间字符串，默认与当前时间比较）
     * @return int 第一个时间大于第二个时间则返回1,小于则返回-1,相等时则返回0
     */
    public static function compare($datetime1, $datetime2 = null): int
    {
        $timestamp1 = self::toTimestamp($datetime1);
        $timestamp2 = self::toTimestamp($datetime2);
        if ($timestamp1 > $timestamp2) {
            return 1;
        } elseif ($timestamp1 < $timestamp2) {
            return -1;
        } else {
            return 0;
        }
    }

    /**
     * 获取当前时间
     * @param string $format 格式化字符串(默认为:Y-m-d H:i:s)
     * @return string 格式化后的当前时间字符串
     */
    public static function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }

	/**
	 * 今天日期字符串
	 *
	 * @param string $format 格式，默认 Y-m-d
	 * @return string
	 * @access public
	 * @static
	 */
	public static function today(string $format = 'Y-m-d'): string
	{
		return self::format($format);
	}

	/**
	 * 昨天日期字符串
	 *
	 * @param string $format 格式，默认 Y-m-d
	 * @return string
	 * @access public
	 * @static
	 */
	public static function yesterday(string $format = 'Y-m-d'): string
	{
		return self::format($format, strtotime('-1 day'));
	}

	/**
	 * 明天日期字符串
	 *
	 * @param string $format 格式，默认 Y-m-d
	 * @return string
	 * @access public
	 * @static
	 */
	public static function tomorrow(string $format = 'Y-m-d'): string
	{
		return self::format($format, strtotime('+1 day'));
	}

	/**
	 * 本周起始（周一 00:00:00）格式化
	 *
	 * @param string $format 格式
	 * @param mixed $datetime 参考时间，默认当前
	 * @return string
	 * @access public
	 * @static
	 */
	public static function weekStart(string $format = 'Y-m-d', mixed $datetime = null): string
	{
		return self::format($format, self::modifyTimestamp('monday this week midnight', $datetime));
	}

	/**
	 * 本周结束（周日 23:59:59）格式化
	 *
	 * @param string $format 格式
	 * @param mixed $datetime 参考时间
	 * @return string
	 * @access public
	 * @static
	 */
	public static function weekEnd(string $format = 'Y-m-d', mixed $datetime = null): string
	{
		return self::format($format, self::modifyTimestamp('sunday this week 23:59:59', $datetime));
	}

	/**
	 * 本月起始格式化
	 *
	 * @param string $format 格式
	 * @param mixed $datetime 参考时间
	 * @return string
	 * @access public
	 * @static
	 */
	public static function monthStart(string $format = 'Y-m-d', mixed $datetime = null): string
	{
		return self::format($format, self::modifyTimestamp('first day of this month midnight', $datetime));
	}

	/**
	 * 本月结束格式化
	 *
	 * @param string $format 格式
	 * @param mixed $datetime 参考时间
	 * @return string
	 * @access public
	 * @static
	 */
	public static function monthEnd(string $format = 'Y-m-d', mixed $datetime = null): string
	{
		return self::format($format, self::modifyTimestamp('last day of this month 23:59:59', $datetime));
	}
	
    /**
     * 获取当前年份
     * @return string 当前年份，格式为4位数字
     */
    public static function getYear(): string
    {
        return date('Y');
    }

    /**
     * 获取当前季度
     * @return int 当前季度（1-4）
     */
    public static function getQuarter(): int
    {
        $month = date('n'); // 获取当前月份
        if ($month >= 1 && $month <= 3) {
            return 1; // 第一季度
        } elseif ($month >= 4 && $month <= 6) {
            return 2; // 第二季度
        } elseif ($month >= 7 && $month <= 9) {
            return 3; // 第三季度
        } else {
            return 4; // 第四季度
        }
    }

    /**
     * 获取当前月份
     * @return int 当前月份，格式为2位数字（01-12）
     */
    public static function getMonth(): string
    {
        return date('m');
    }

    /**
     * 获取当前周数
     * 
     * @return int 当前年份的周数（1-53）
     */
    public static function getWeek(): string
    {
        return date('w');
    }

    /**
     * 获取当前日期
     * @return int 当前日期，格式为2位数字（01-31）
     */
    public static function getDay(): string
    {
        return date('d');
    }

    /**
     * 获取当前小时
     * @return int 当前小时，24小时制，格式为2位数字（00-23）
     */
    public static function getHour(): string
    {
        return date('H');
    }

    /**
     * 获取当前分钟数
     * @return int 当前分钟数，格式为2位数字（00-59）
     */
    public static function getMinute(): string
    {
        return date('i');
    }

    /**
     * 获取当前秒数
     * @return int 当前秒数，格式为2位数字（00-59）
     */
    public static function getSecond(): string
    {
        return date('s');
    }
    
    /**
     * 获取今天的时间戳范围, 包括00:00:00到23:59:59
     * @param bool $passed 是否只计算到当前时间，默认为false
     * @return array 包含今天00:00:00和23:59:59的时间戳数组
     * @example
     * // 获取今天完整的时间范围
     * Time::getBetweenToday(); // 返回 [今天00:00:00的时间戳, 今天23:59:59的时间戳]
     * 
     * // 获取从今天00:00:00到当前时间的时间范围
     * Time::getBetweenToday(true); // 返回 [今天00:00:00的时间戳, 当前时间的时间戳]
     */
    public static function getBetweenToday(bool $passed = false): array
    {
        $end = time();
        $start = strtotime(date('Y-m-d 00:00:00', $end));
        $end = $passed ? $end : strtotime(date('Y-m-d 23:59:59', $end));
        return [$start, $end];
    }
    
    /**
     * 获取本周的时间戳范围, 包括本周的00:00:00到本周的23:59:59
     * @param bool $passed 是否只计算到当前时间，默认为false
     * @return array 包含本周00:00:00和23:59:59的时间戳数组
     * @example
     * // 获取本周完整的时间范围
     * Time::getBetweenWeek(); // 返回 [本周一00:00:00的时间戳, 本周日23:59:59的时间戳]
     * 
     * // 获取从本周一00:00:00到当前时间的时间范围
     * Time::getBetweenWeek(true); // 返回 [本周一00:00:00的时间戳, 当前时间的时间戳]
     */
    public static function getBetweenWeek(bool $passed = false): array
    {
        $end = time();
        $start = self::modifyTimestamp('monday this week midnight', $end);
        $end = $passed ? $end : self::modifyTimestamp('sunday this week 23:59:59', $end);
        return [$start, $end];
    }
    
    /**
     * 获取本月的时间戳范围, 包括本月的00:00:00到本月的23:59:59
     * @param bool $passed 是否只计算到当前时间，默认为false
     * @return array 包含本月00:00:00和23:59:59的时间戳数组
     * @example
     * // 获取本月完整的时间范围
     * Time::getBetweenMonth(); // 返回 [本月1号00:00:00的时间戳, 本月最后一天23:59:59的时间戳]
     * 
     * // 获取从本月1号00:00:00到当前时间的时间范围
     * Time::getBetweenMonth(true); // 返回 [本月1号00:00:00的时间戳, 当前时间的时间戳]
     */
    public static function getBetweenMonth(bool $passed = false): array
    {
        $end = time();
        $start = self::modifyTimestamp('first day of this month midnight', $end);
        $end = $passed ? $end : self::modifyTimestamp('last day of this month 23:59:59', $end);
        return [$start, $end];
    }
    
    /**
     * Get Between Year
     * @return array
     */
    public static function getBetweenYear(bool $passed = false): array
    {
        $end = time();
        $start = self::modifyTimestamp('first day of this year midnight', $end);
        $end = $passed ? $end : self::modifyTimestamp('last day of this year 23:59:59', $end);
        return [$start, $end];
    }

    /**
     * 获取指定天数前的时间戳范围
     * 
     * 获取从指定天数前到指定时间点的时间戳范围
     * 
     * @param int $days 天数
     * @param mixed $datetime 指定的结束时间点，可以是时间戳或日期字符串，默认为当前时间
     * @param bool $round 是否将开始时间舍入到午夜（00:00:00）
     * @return array 包含开始时间戳和结束时间戳的数组 [start, end]
     * @example
     * // 获取最近7天的时间范围
     * Time::getLastDays(7); // 返回 [7天前的时间戳, 当前时间戳]
     * 
     * // 获取最近30天到指定时间的时间范围，并将开始时间舍入到午夜
     * Time::getLastDays(30, '2023-12-31', true); // 返回 [30天前的00:00:00时间戳, 2023-12-31的时间戳]
     */
    public static function getLastDays(int $days, $datetime = null,bool $round = false): array
    {
        $end = is_null($datetime) ? time() : (is_numeric($datetime) ? (int)$datetime : strtotime($datetime));
        $modify = "-{$days} days" . ($round ? ' midnight' : '');
        $start = self::modifyTimestamp($modify, $end);
        return [$start, $end];
    }
}
