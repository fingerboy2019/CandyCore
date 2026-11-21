<?php
declare(strict_types=1);
/***
 * 控制台命令：IP 黑白名单管理
 *
 * 用法：
 *   php candy ip list                        # 列出名单（默认黑名单 + project scope）
 *   php candy ip list --type=white --scope=all
 *   php candy ip add 1.2.3.4                 # 加入黑名单（project，永久）
 *   php candy ip add 1.2.3.4 --scope=sys --minutes=120
 *   php candy ip add 10.0.0.1 10.0.0.9 --scope=sys   # 区间
 *   php candy ip del 1.2.3.4 --type=white --scope=sys
 *   php candy ip reset --type=black --scope=sys        # 清空（只清 sys）
 *   php candy ip reset --type=all --scope=all          # 全清
 *
 * 作用域：
 *   project（默认）= 项目页面访问控制（Bootstrap::security 用）
 *   sys            = /sys/* 端点防护（令牌暴力破解自动封禁写这里）
 *
 * 与 /sys/service 页面共用 Candy\Core\Security 的 IP 名单能力，规则只维护一份。
 *
 * @package Console\Command
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 10:52:00
 */
namespace Console\Command;

use \Console\{Command, Input, Output};
use \Console\Input\{Option, Argument};

class Ip extends Command
{
    /**
     * 配置指令
     */
    protected function configure(): void
    {
        $this->setName('Ip')
            ->addArgument('action', Argument::OPTIONAL, 'list | add | del | reset', 'list')
            ->addArgument('ip', Argument::OPTIONAL, 'IP 地址（add/del 必填；区间时填起始 IP）', '')
            ->addArgument('end', Argument::OPTIONAL, '区间结束 IP（可选）', '')
            ->addOption('type', null, Option::VALUE_REQUIRED, '名单类型：black | white | all', 'black')
            ->addOption('scope', null, Option::VALUE_REQUIRED, '作用域：project | sys | all（del/reset 不传则两处都清）', '')
            ->addOption('minutes', null, Option::VALUE_REQUIRED, '有效分钟数，0 = 永久', '0')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出')
            ->setDescription('IP 黑白名单管理：查看 / 添加 / 删除 / 重置（支持 project 与 sys 两个作用域）');
    }

    /**
     * 执行
     *
     * @param Input  $input  输入
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input, Output $output)
    {
        $action = strtolower(trim((string) ($input->getArgument('action') ?? 'list')));
        $ip = trim((string) ($input->getArgument('ip') ?? ''));
        $end = trim((string) ($input->getArgument('end') ?? ''));
        $type = $this->normType((string) $input->getOption('type'));
        $rawScope = strtolower(trim((string) $input->getOption('scope')));
        // 删除类操作不传 --scope 时跨两个作用域：封禁可能发生在 sys，也可能在项目
        if ($rawScope === '' && ($action === 'del' || $action === 'reset')) {
            $rawScope = 'all';
        }
        $scope = $this->normScope($rawScope);
        $minutes = (int) $input->getOption('minutes');
        $json = (bool) $input->getOption('json');

        try {
            switch ($action) {
                case 'list':
                    return $this->doList($type, $scope, $json);
                case 'add':
                    return $this->doAdd($type, $scope, $ip, $end, $minutes, $json);
                case 'del':
                    return $this->doDel($type, $scope, $ip, $end, $json);
                case 'reset':
                    return $this->doReset($type, $scope, $json);
                default:
                    echo '未知动作：' . $action . PHP_EOL;
                    echo '可用：list | add | del | reset' . PHP_EOL;
                    return 1;
            }
        } catch (\Throwable $e) {
            echo '执行失败：' . $e->getMessage() . PHP_EOL;
            return 1;
        }
    }

    /**
     * 列出名单
     *
     * @param string $type  black|white|all
     * @param string $scope project|sys|all
     * @param bool   $json  JSON 输出
     * @return int
     */
    protected function doList(string $type, string $scope, bool $json): int
    {
        $types = $type === 'all' ? ['black', 'white'] : [$type];
        $data = [];
        foreach ($types as $one) {
            $letter = $one === 'white' ? 'w' : 'b';
            $rows = [];
            foreach ((array) getIPList($letter, $scope === 'all' ? '' : $scope) as $row) {
                $rows[] = [
                    'start' => (string) $row[0],
                    'end' => (string) $row[1],
                    'expire' => (int) ($row[2] ?? 0),
                    'expire_at' => empty($row[2]) ? '' : $this->fmt((int) $row[2]),
                    'scope' => (string) ($row[3] ?? 'project'),
                ];
            }
            $data[$one] = $rows;
        }

        $cfg = class_exists('\\Candy\\Extend\\Tools\\Endpoint\\Guard')
            ? \Candy\Extend\Tools\Endpoint\Guard::ipConfig() : [];

        if ($json) {
            echo json_encode(['ok' => true, 'mode' => $cfg['mode'] ?? '', 'data' => $data],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
            return 0;
        }

        echo 'sys 守卫模式：' . ($cfg['mode'] ?? '?')
            . '（enable=' . (empty($cfg['enable']) ? 'off' : 'on')
            . '，max_fail=' . ($cfg['max_fail'] ?? '?')
            . '，ban_time=' . ($cfg['ban_time'] ?? '?') . 's）' . PHP_EOL;
        echo '名单目录：' . (string) C('LOGPATH') . PHP_EOL;

        foreach ($data as $one => $rows) {
            printf("%s名单（scope=%s）：%d 条\n", $one === 'white' ? '白' : '黑', $scope, count($rows));
            if ($rows === []) {
                continue;
            }
            printf("  %-18s %-18s %-10s %s\n", '起始IP', '结束IP', '作用域', '到期');
            foreach ($rows as $r) {
                printf("  %-18s %-18s %-10s %s\n", $r['start'], $r['end'], $r['scope'],
                    $r['expire'] === 0 ? '永久' : $r['expire_at']);
            }
        }
        return 0;
    }

    /**
     * 添加
     *
     * @param string $type    black|white|all
     * @param string $scope   project|sys|all
     * @param string $ip      IP 或区间起点
     * @param string $end     区间终点
     * @param int    $minutes 有效分钟
     * @param bool   $json    JSON
     * @return int
     */
    protected function doAdd(string $type, string $scope, string $ip, string $end, int $minutes, bool $json): int
    {
        if ($ip === '') {
            echo '缺少 IP：php candy ip add <ip> [--scope=sys] [--minutes=120]' . PHP_EOL;
            return 1;
        }
        if ($scope === 'all' || $type === 'all') {
            echo 'add 必须指定具体 --type（black|white）和 --scope（project|sys）' . PHP_EOL;
            return 1;
        }
        if (ip2long($ip) === false && strpos($ip, '*') === false) {
            echo '不是合法的 IP：' . $ip . PHP_EOL;
            return 1;
        }
        $letter = $type === 'white' ? 'w' : 'b';
        $exp = $minutes > 0 ? time() + $minutes * 60 : 0;
        $target = $end !== '' ? [$ip, $end] : $ip;

        $ok = addIPList($letter, $target, $exp, $scope);
        if ($json) {
            echo json_encode(['ok' => $ok, 'type' => $type, 'scope' => $scope, 'ip' => $target,
                'expire_at' => $exp === 0 ? '' : $this->fmt($exp)], JSON_UNESCAPED_UNICODE), PHP_EOL;
        } else {
            echo ($ok ? '已加入' : '写入失败：') . ($ok ? ($type === 'white' ? '白名单' : '黑名单')
                . ' ' . (is_array($target) ? implode(' ~ ', $target) : $target)
                . '（scope=' . $scope . '，' . ($exp === 0 ? '永久' : '到 ' . $this->fmt($exp)) . '）'
                : '请检查 Runtime/Logs 是否可写') . PHP_EOL;
        }
        return $ok ? 0 : 1;
    }

    /**
     * 删除
     *
     * @param string $type  black|white|all
     * @param string $scope project|sys|all
     * @param string $ip    IP 或区间起点
     * @param string $end   区间终点
     * @param bool   $json  JSON
     * @return int
     */
    protected function doDel(string $type, string $scope, string $ip, string $end, bool $json): int
    {
        if ($ip === '') {
            echo '缺少 IP：php candy ip del <ip> [--type=black] [--scope=sys]' . PHP_EOL;
            return 1;
        }
        if ($type === 'all') {
            echo 'del 必须指定具体 --type（black|white）' . PHP_EOL;
            return 1;
        }
        $letter = $type === 'white' ? 'w' : 'b';
        $target = $end !== '' ? [$ip, $end] : $ip;
        // scope=all：两个作用域都尝试删除，任一成功即视为成功
        $scopes = $scope === 'all' ? ['project', 'sys'] : [$scope];
        $ok = false;
        foreach ($scopes as $one) {
            if (delIPList($letter, $target, 0, $one)) {
                $ok = true;
            }
        }
        if ($json) {
            echo json_encode(['ok' => $ok, 'type' => $type, 'scope' => $scope, 'ip' => $target], JSON_UNESCAPED_UNICODE), PHP_EOL;
        } else {
            echo ($ok ? '已删除 ' : '删除失败 ') . ($type === 'white' ? '白名单' : '黑名单')
                . ' ' . (is_array($target) ? implode(' ~ ', $target) : $target) . '（scope=' . $scope . '）' . PHP_EOL;
        }
        return $ok ? 0 : 1;
    }

    /**
     * 重置（清空）
     *
     * @param string $type  black|white|all
     * @param string $scope project|sys|all
     * @param bool   $json  JSON
     * @return int
     */
    protected function doReset(string $type, string $scope, bool $json): int
    {
        $letter = $type === 'all' ? '' : ($type === 'white' ? 'w' : 'b');
        cleanIPList($letter, true, $scope === 'all' ? '' : $scope);
        // scope=all 时 cleanIPList 传空串已是全清，无需再逐域处理
        if ($json) {
            echo json_encode(['ok' => true, 'type' => $type, 'scope' => $scope], JSON_UNESCAPED_UNICODE), PHP_EOL;
        } else {
            echo '已清空：' . ($type === 'all' ? '黑+白名单' : ($type === 'white' ? '白名单' : '黑名单'))
                . '（scope=' . $scope . '）' . PHP_EOL;
        }
        return 0;
    }

    /**
     * 按站点时区格式化时间戳
     *
     * CLI 的默认时区是 UTC，直接用 date() 会与页面显示差 8 小时，所以统一走 C('TIMEZONE')。
     *
     * @param int $ts 时间戳
     * @return string
     */
    protected function fmt(int $ts): string
    {
        if ($ts <= 0) {
            return '永久';
        }
        $tz = function_exists('C') ? trim((string) C('TIMEZONE')) : '';
        // CLI 未必加载完整项目配置，退回从 Config 配置里取（实测 C('TIMEZONE') 在 CLI 为 null）
        if ($tz === '' && function_exists('loadConfig')) {
            try {
                $cfg = loadConfig('Config');
                $tz = is_array($cfg) ? trim((string) ($cfg['TIMEZONE'] ?? '')) : '';
            } catch (\Throwable $e) {
                $tz = '';
            }
        }
        try {
            $d = new \DateTime('@' . $ts);
            if ($tz !== '') {
                $d->setTimezone(new \DateTimeZone($tz));
            }
            return $d->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return date('Y-m-d H:i:s', $ts);
        }
    }

    /**
     * 规范名单类型
     *
     * @param string $type
     * @return string black|white|all
     */
    protected function normType(string $type): string
    {
        $t = strtolower(trim($type));
        if (in_array($t, ['w', 'white', 'allow'], true)) {
            return 'white';
        }
        if (in_array($t, ['all', '*'], true)) {
            return 'all';
        }
        return 'black';
    }

    /**
     * 规范作用域
     *
     * @param string $scope
     * @return string project|sys|all
     */
    protected function normScope(string $scope): string
    {
        $s = strtolower(trim($scope));
        if ($s === 'sys' || $s === 'endpoint') {
            return 'sys';
        }
        if ($s === 'all' || $s === '*') {
            return 'all';
        }
        return 'project';
    }
}
