# Time 时间处理工具使用文档

## 概述

Time类是Candy框架中的时间处理工具类，提供全面的时间和日期处理功能。本类设计为静态类，可以直接通过类名调用所有方法，无需实例化。

支持的主要功能：
- 时间戳和日期字符串的相互转换
- 日期格式化与自定义格式输出
- 时间加减计算（年、月、日、小时等）
- 不同时区的时间转换
- 时间比较功能
- 各种时间信息获取（年、月、日、小时、分钟、秒等）
- 不同精度的时间戳获取（秒级、毫秒级、微秒级、纳秒级）
- 闰年判断、月份天数计算等

## 快速开始

### 引入命名空间

```php
use Extend\Time;
```

### 基本用法

```php
// 获取当前时间
$now = Time::now(); // 默认为Y-m-d H:i:s格式
$nowCustom = Time::now('YmdHis'); // 自定义格式

// 时间戳转换
$timestamp = Time::toTimestamp('2023-10-01 12:00:00');
$datetime = Time::format('Y-m-d H:i:s', $timestamp);

// 时间比较
$diffDays = Time::diffDays('2023-09-25', '2023-10-01'); // 返回6

// 判断日期类型
$isToday = Time::isToday('2023-10-01');
$isWeekend = Time::isWeekend('2023-10-01'); // 周日返回true

// 获取时间信息
$year = Time::getYear('2023-10-01'); // 返回2023
$weekDay = Time::getWeekDay('2023-10-01'); // 返回7（周日）
```

## 核心功能

### 1. 时间戳操作

#### toTimestamp() - 转换为时间戳

```php
/**
 * 将任意时间类型的参数转为时间戳
 * @param $datetime 要转换为时间戳的字符串或数字,如果为空则返回当前时间戳
 * @return int 时间戳
 */
public static function toTimestamp($datetime = null): int
```

**示例：**

```php
// 获取当前时间戳
$now = Time::toTimestamp();

// 转换日期字符串为时间戳
$timestamp = Time::toTimestamp('2023-10-01 12:00:00');

// 转换时间戳字符串为时间戳
$timestamp2 = Time::toTimestamp('1696142400');
```

#### isTimestamp() - 判断是否为时间戳

```php
/**
 * 判断是否为时间戳格式
 * @param $timestamp 要判断的字符串
 * @return bool 如果是时间戳返回True,否则返回False
 */
public static function isTimestamp(int|string $timestamp): bool
```

**示例：**

```php
$isTimestamp = Time::isTimestamp('1696142400'); // 返回true
$isTimestamp2 = Time::isTimestamp('2023-10-01'); // 返回false
```

### 2. 日期格式化

#### format() - 格式化日期

```php
/**
 * 将任意格式的时间转换为指定格式
 * @param $format   格式化字符串(默认为:Y-m-d H:i:s)
 * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
 * @return false|string 格式化后的时间字符串
 */
public static function format(string $format = 'Y-m-d H:i:s', $datetime = null): string
```

**示例：**

```php
// 格式化当前时间
$formatted = Time::format('Y年m月d日 H:i:s');

// 格式化指定时间戳
$formatted = Time::format('Y-m-d', 1633046400);

// 自定义格式
$formatted = Time::format('Y/m/d H:i', '2023-10-01 12:00:00');
```

#### toFriendly() - 友好时间显示

```php
/**
 * 讲时间转换为友好显示格式
 * @param $datetime 时间日期的字符串或数字
 * @param $lang     语言,默认为中文,如果要显示英文传入en即可
 * @return string 转换后的友好时间格式
 */
public static function toFriendly(int|string $datetime, string $lang = 'zh'): string
```

**示例：**

```php
$friendly = Time::toFriendly(1633046400); // 输出: "2021-10-01"
$friendlyEn = Time::toFriendly(1633046400, 'en'); // 输出: "Oct 1, 2021"
```

### 3. 时间比较

#### compare() - 比较两个日期

```php
/**
 * 比较两个日期的大小
 * @param $datetime1 时间1
 * @param $datetime2 时间2
 * @return int 1表示时间1大于时间2, -1表示时间1小于时间2, 0表示相等
 */
public static function compare($datetime1, $datetime2): int
```

**示例：**

```php
$result = Time::compare('2023-10-02', '2023-10-01'); // 返回1
$result = Time::compare('2023-10-01', '2023-10-02'); // 返回-1
$result = Time::compare('2023-10-01', '2023-10-01'); // 返回0
```

#### diff*() 系列方法 - 计算时间差

```php
// 计算秒数差
Time::diffSeconds('2023-10-01', '2023-10-02'); // 返回86400

// 计算分钟差
Time::diffMinutes('2023-10-01 12:00:00', '2023-10-01 13:30:00'); // 返回90

// 计算小时差
Time::diffHours('2023-10-01', '2023-10-02'); // 返回24

// 计算天数差
Time::diffDays('2023-09-25', '2023-10-01'); // 返回6

// 计算周数差
Time::diffWeeks('2023-09-20', '2023-10-01'); // 返回1

// 计算月数差
Time::diffMonths('2023-09-01', '2023-10-01'); // 返回1

// 计算年数差
Time::diffYears('2022-10-01', '2023-10-01'); // 返回1
```

### 4. 日期判断

```php
// 判断是否为今天
Time::isToday('2023-10-01');

// 判断是否为昨天
Time::isYesterday('2023-09-30');

// 判断是否为明天
Time::isTomorrow('2023-10-02');

// 判断是否为本周
Time::isThisWeek('2023-10-01');

// 判断是否为本月
Time::isThisMonth('2023-10-01');

// 判断是否为今年
Time::isThisYear('2023-10-01');

// 判断是否为工作日（周一至周五）
Time::isWeekday('2023-10-02'); // 周一返回true

// 判断是否为周末（周六和周日）
Time::isWeekend('2023-10-01'); // 周日返回true

// 判断是否为闰年
Time::isLeapYear('2020-01-01'); // 返回true
```

### 5. 时间计算

#### modifyTimestamp() - 修改时间

```php
/**
 * 根据日期字符串, 修改指定时间, 并返回修改后的时间戳
 * @param $modifier 日期修改字符串
 * @param $datetime 任意格式时间字符串或时间戳(默认为当前时间)
 * @return int 时间戳
 */
public static function modifyTimestamp(string $modifier, $datetime = null): int
```

**示例：**

```php
// 获取3天后的时间戳
$next3day = Time::modifyTimestamp('+3 day');

// 获取1小时前的时间戳
$lastHour = Time::modifyTimestamp('-1 hour', '2023-10-01 12:00:00');

// 获取1个月后的时间戳
$nextMonth = Time::modifyTimestamp('+1 month');
```

#### before*() 和 after*() 系列方法

```php
// 获取N天前的时间戳
Time::beforeDay(3);

// 获取N天后的时间戳
Time::afterDay(3);

// 获取N周前的时间
Time::beforeWeek(2);

// 获取N月后的时间
Time::afterMonth(1);

// 获取N年前的时间
Time::beforeYear(1);
```

### 6. 时间范围

```php
// 获取今天的时间范围 (00:00:00 到 23:59:59)
$todayRange = Time::getBetweenToday();

// 获取本周的时间范围
$weekRange = Time::getBetweenWeek();

// 获取本月的时间范围
$monthRange = Time::getBetweenMonth();

// 获取本年的时间范围
$yearRange = Time::getBetweenYear();

// 获取最近N天的时间范围
$last7Days = Time::getLastDays(7);

// 获取最近30天到指定时间的时间范围，并将开始时间舍入到午夜
$last30Days = Time::getLastDays(30, '2023-12-31', true);
```

### 7. 不同精度时间戳

```php
// 获取秒级时间戳
$timestamp = Time::getTimestamp(); // 或 Time::getTimestamp(0)

// 获取毫秒级时间戳
$milliTimestamp = Time::getTimestamp(1); // 或 Time::getMilliTimestamp()

// 获取微秒级时间戳
$microTimestamp = Time::getTimestamp(2); // 或 Time::getMicroTimestamp()

// 获取纳秒级时间戳
$nanoTimestamp = Time::getTimestamp(3); // 或 Time::getNanoTimestamp()
```

### 8. 时间信息获取

```php
// 获取年份
Time::getYear('2023-10-01'); // 返回2023

// 获取季度 (1-4)
Time::getQuarter('2023-10-01'); // 返回4

// 获取月份 (1-12)
Time::getMonth('2023-10-01'); // 返回10

// 获取周数
Time::getWeek('2023-10-01');

// 获取日期 (1-31)
Time::getDay('2023-10-01'); // 返回1

// 获取小时 (0-23)
Time::getHour('2023-10-01 12:30:00'); // 返回12

// 获取分钟 (0-59)
Time::getMinute('2023-10-01 12:30:00'); // 返回30

// 获取秒数 (0-59)
Time::getSecond('2023-10-01 12:30:45'); // 返回45

// 获取星期几 (1-7, 1=周一, 7=周日)
Time::getWeekDay('2023-10-01'); // 返回7

// 获取指定月份的天数
Time::daysInMonth('2023-02-01'); // 返回28
```

### 9. 时区转换

```php
/**
 * 不同时区的时间转换
 * @param string      $toTimezone   目标时区
 * @param string|null $fromTimezone 原时区(默认为当前PHP运行环境所设置的时区)
 * @param mixed       $datetime     任意格式的时间字符串或时间戳(默认为当前时间)
 * @param string      $format       格式化字符串
 * @return string 转换后的格式化日期时间字符串
 * @throws Exception
 */
public static function timezoneFormat(string $toTimezone, string $fromTimezone = null, $datetime = 'now', string $format = 'Y-m-d H:i:s'): string
```

**示例：**

```php
// 将北京时间转换为纽约时间
$newYorkTime = Time::timezoneFormat('America/New_York', 'Asia/Shanghai', '2023-10-01 12:00:00');

// 将当前时间转换为伦敦时间
$londonTime = Time::timezoneFormat('Europe/London');

// 使用自定义格式
$londonTime = Time::timezoneFormat('Europe/London', 'Asia/Shanghai', '2023-10-01 12:00:00', 'Y-m-d H:i');
```

## 高级用法

### 日期比较与范围查询结合

```php
// 检查日期是否在指定范围内
$targetDate = '2023-10-15';
$startDate = '2023-10-01';
$endDate = '2023-10-31';

$isInRange = (Time::compare($targetDate, $startDate) >= 0) && (Time::compare($targetDate, $endDate) <= 0);
```

### 计算剩余时间

```php
// 计算距离活动结束还剩多少秒
$endTime = '2023-12-31 23:59:59';
$remainingSeconds = Time::diffSeconds($endTime);

// 计算距离活动开始还有多少天
$startTime = '2023-12-01';
$remainingDays = Time::diffDays($startTime);
```

### 批量处理时间数据

```php
// 批量格式化日期数组
$dates = ['2023-10-01', '2023-10-02', '2023-10-03'];
$formattedDates = array_map(function($date) {
    return Time::format('Y年m月d日', $date);
}, $dates);

// 批量判断是否为今天
$isTodayArray = array_map(function($date) {
    return Time::isToday($date);
}, $dates);
```

## 注意事项

1. 使用`toTimestamp()`方法时，请注意日期格式歧义：
   - m/d/y 格式使用斜线（/）分隔符
   - d-m-y 格式使用横杠（-）或者点（.）分隔符
   - 建议优先使用 YYYY-MM-DD 格式以避免潜在错误

2. `timezoneFormat()`方法转换时区时，需确保PHP环境已加载对应的时区数据库

3. 时间比较方法(`compare()`)返回值：
   - 1 表示前者大于后者
   - -1 表示前者小于后者
   - 0 表示相等

4. 取整参数(`round`)在日期计算方法中启用时，会将时间戳调整至对应周期的起始点（如日期0点、小时0分等）

5. 获取不同精度时间戳时注意：
   - 毫秒级时间戳（13位）
   - 微秒级时间戳（16位）
   - 纳秒级时间戳（19位）

## 版本信息

- 作者: 刘森 (fingerboy@qq.com)
- 创建日期: 2020-05-24 00:28:25
- 支持的PHP版本: 7.0及以上
- 依赖: PHP DateTime扩展