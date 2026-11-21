<?php
/***
 * 运行时文件日志
 *
 * 业务说明：本地 Runtime/Logs 追加写入；可选 remote 远程上报（默认关）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 15:36:26 $
 *
 * @package Candy\Core
 */

declare (strict_types = 1);
namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Log {
    /**
     * 文件句柄缓存
     *
     * @var array<string, resource>
     */
    private static $_handle = [];

    /**
     * 远程上报中（防递归）
     *
     * @var bool
     */
    private static bool $reportingRemote = false;

    /**
     * 写入日志记录
     *
     * 本地文件追加；若 Log.inc.php remote 开启且级别命中，再短超时 POST 到远程
     *
     * @param mixed $data 要记录的数据（字符串/数组/对象/异常）
     * @param int $type 日志等级：-1 无等级；0 DEBUG；1 INFO；2 WARN；3 ERROR；4 FATAL；5 SQL_ERROR
     * @param string|null $path 日志文件路径，空则按日期
     * @return void
     */
    public static function input(mixed $data, int $type = 0, ?string $path = null): void
    {
        if (!C('APP_INIT_STATUS')) {
            return;
        }

        $log_path = $path ?: C('LOGPATH') . date('Y-m-d') . '.log';
        \Candy\Extend\Dir::create(dirname($log_path) . '/');
        $handle = self::$_handle[$log_path] ?? null;
        if (!is_resource($handle)) {
            $handle = @fopen($log_path, 'a');
            if ($handle === false) {
                return;
            }
            self::$_handle[$log_path] = $handle;
        }
        $desctitle = ($type === -1) ? '' : '[' . self::getDescTitle($type) . ']:';
        $fineStamp = date('Y-m-d H:i:s') . substr(microtime(), 1, 9);

        // SQL / PDO 展开为可读文本
        if ($type == 5 || $data instanceof \PDOException) {
            if (is_array($data)) {
                $data = 'SQL Error: ' . ($data['error'] ?? '') . "\nSQL: " . ($data['sql'] ?? '');
            } elseif ($data instanceof \PDOException) {
                $data = 'PDOException: ' . $data->getMessage() . "\nCode: " . $data->getCode()
                    . "\nFile: " . $data->getFile() . ':' . $data->getLine()
                    . "\nTrace: " . str_replace("\n", ' ', $data->getTraceAsString());
            }
        }

        $lineBody = self::getRequest($data);
        fwrite(self::$_handle[$log_path], strtoupper('[' . $fineStamp . ']') . $desctitle . $lineBody);
        self::close($log_path);

        self::reportRemote($type, $lineBody, $fineStamp);
    }

    /**
     * 读取日志文件内容
     *
     * @param string|null $path 日志文件路径，为空时使用默认路径(按日期)
     * @param bool $display 是否返回HTML表格格式
     * @return mixed 日志内容数组或HTML表格字符串
     */
    public static function read(?string $path = null, bool $display = false): mixed
    {
        $log_path = $path ?: C('LOGPATH') . date('Y-m-d') . '.log';
        $log_content = '';
        if (is_file($log_path)) {
            $log_content = file_get_contents($log_path);
            $list_str_array = explode(PHP_EOL, (string)$log_content);
            $log_content = '';
            if ($display) {
                $total_lines = sizeof($list_str_array);
                $log_content .= '<table width="85%" border="0" cellpadding="0" cellspacing="1" style="background:#0478CB; font-size:12px; line-height:25px;">';
                foreach ($list_str_array as $key => $lines_str) {
                    if ($key == $total_lines - 1) {
                        continue;
                    }
                    $bg_color = ($key % 2 == 0) ? '#FFFFFF' : '#C6E7FF';
                    $log_content .= '<tr><td height="25" align="left" bgcolor="' . $bg_color . '">&nbsp;' . $lines_str . '</td></tr>';
                }
                $log_content .= '</table>';
            } else {
                return $list_str_array;
            }
        }
        return $log_content;
    }

    /**
     * 可选远程上报
     *
     * 读取 Source/Inc/Log.inc.php → remote；失败静默，避免打日志死循环
     *
     * @param int $type 级别
     * @param string $message 已格式化正文（含换行）
     * @param string $stamp 时间戳串
     * @return void
     */
    private static function reportRemote(int $type, string $message, string $stamp): void
    {
        if (self::$reportingRemote) {
            return;
        }
        $cfg = self::remoteConfig();
        if ($cfg === null || empty($cfg['enabled']) || empty($cfg['url'])) {
            return;
        }
        $levels = $cfg['levels'] ?? [3, 4, 5];
        if (!is_array($levels) || !in_array($type, $levels, true)) {
            return;
        }

        $url = (string)$cfg['url'];
        $timeout = max(1, (int)($cfg['timeout'] ?? 2));
        $project = (string)($cfg['project'] ?? '');
        if ($project === '') {
            $project = (string)(C('APP_NAME') ?: (function_exists('gethostname') ? (string)@gethostname() : 'candy'));
        }

		$server = I('server.', [], '');
        $payload = [
            'project'    => $project,
            'level'      => $type === -1 ? 'NONE' : self::getDescTitle($type),
            'level_code' => $type,
            'message'    => rtrim($message),
            'time'       => $stamp,
            'host'       => (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''),
            'uri'        => (string)($server['REQUEST_URI'] ?? ''),
            'method'     => (string)($server['REQUEST_METHOD'] ?? ''),
            'ip'         => (string)($server['REMOTE_ADDR'] ?? ''),
        ];

        self::$reportingRemote = true;
        try {
            if (!class_exists(\Candy\Extend\Network\Http::class)) {
                return;
            }
            \Candy\Extend\Network\Http::post(
                $url,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                [
                    'timeout' => $timeout,
                    'headers' => [
                        'Content-Type: application/json; charset=utf-8',
                        'Accept: application/json',
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // 远程失败不回写日志，避免递归
        } finally {
            self::$reportingRemote = false;
        }
    }

    /**
     * 远程配置
     *
     * @return array<string,mixed>|null
     */
    private static function remoteConfig(): ?array
    {
        static $cached = false;
        static $remote = null;
        if ($cached) {
            return is_array($remote) ? $remote : null;
        }
        $cached = true;
        $all = function_exists('loadConfig') ? loadConfig('Log') : [];
        if (!is_array($all) || !isset($all['remote']) || !is_array($all['remote'])) {
            $remote = null;
            return null;
        }
        $remote = $all['remote'];
        return $remote;
    }

    /**
     * 转换数据为字符串格式
     *
     * @param mixed $request 需要转换的数据
     * @return string
     */
    private static function getRequest(mixed $request): string
    {
        if (!is_string($request)) {
            if (is_resource($request)) {
                $request = 'resource(N) of type (stream)';
            } elseif ($request instanceof \Exception) {
                $request = get_class($request) . ': ' . $request->getMessage()
                    . "\nFile: " . $request->getFile() . ':' . $request->getLine()
                    . "\nTrace: " . str_replace("\n", ' ', $request->getTraceAsString());
            } else {
                $request = json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }
        return $request . PHP_EOL;
    }

    /**
     * 日志等级代码转描述
     *
     * @param int $type 等级
     * @return string
     */
    private static function getDescTitle(int $type): string
    {
        return match ($type) {
            1 => 'INFO',
            2 => 'WARN',
            3 => 'ERROR',
            4 => 'FATAL',
            5 => 'SQL_ERROR',
            default => 'DEBUG',
        };
    }

    /**
     * 关闭指定日志文件句柄
     *
     * @param string $val 路径键
     * @return void
     */
    private static function close(string $val): void
    {
        if (isset(self::$_handle[$val]) && is_resource(self::$_handle[$val])) {
            @fclose(self::$_handle[$val]);
            unset(self::$_handle[$val]);
        }
    }
}
