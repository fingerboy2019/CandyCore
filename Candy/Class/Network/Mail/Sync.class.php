<?php
/**
 * 邮件同步编排：IMAP 增量拉取 → MIME 解析 → 入库 → 更新游标
 *
 * 增量规则（关键）：
 *   ① 文件夹游标 = `uidvalidity` + `last_uid`
 *   ② 服务端 `UIDVALIDITY` 变了 → **所有 UID 作废**，游标归零重扫（不能沿用旧 last_uid）
 *   ③ 只取 `UID {last_uid+1}:*`；按 UID 升序处理，成功后推进游标
 *   ④ 单封失败不阻断整批（记 error，继续下一封），失败的那封下次会重试
 *
 * @package Candy\Extend\Network\Mail
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 16:00:00
 */
declare(strict_types=1);

namespace Candy\Extend\Network\Mail;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Sync
{
    /**
     * 同步一个账号
     *
     * @param array<string,mixed> $account 账号行（含 imap_host/port/secure/username/secret）
     * @param array<string,mixed> $opts    folders/batch/save_raw/mark_seen/limit
     * @return array<string,mixed> 统计
     */
    public static function syncAccount(array $account, array $opts = []): array
    {
        $stats = [
            'account'   => (string) ($account['email'] ?? ''),
            'folders'   => [],
            'fetched'   => 0,
            'saved'     => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
            'ok'        => false,
        ];

        $accountId = (int) ($account['id'] ?? 0);
        if ($accountId <= 0) {
            $stats['errors'][] = '账号 id 无效';
            return $stats;
        }

        $imap = new Imap(
            (string) ($account['imap_host'] ?? ''),
            (int) ($account['imap_port'] ?? 993),
            (string) ($account['imap_secure'] ?? 'ssl'),
            (string) ($account['username'] ?? ($account['email'] ?? '')),
            (string) ($account['secret'] ?? ''),
            20
        );
        if (!$imap->connect()) {
            $stats['errors'][] = '连接失败: ' . $imap->lastError();
            return $stats;
        }

        $folders = (array) ($opts['folders'] ?? ['INBOX']);
        if ($folders === []) {
            $folders = ['INBOX'];
        }
        $batch    = max(1, (int) ($opts['batch'] ?? 20));
        $saveRaw  = (bool) ($opts['save_raw'] ?? true);
        $markSeen = (bool) ($opts['mark_seen'] ?? false);
        $limit    = max(0, (int) ($opts['limit'] ?? 0));

        foreach ($folders as $folderName) {
            $one = self::syncFolder($imap, $account, (string) $folderName, $batch, $saveRaw, $markSeen, $limit, $stats);
            $stats['folders'][] = $one;
        }

        $imap->logout();
        $stats['ok'] = $stats['failed'] === 0;
        return $stats;
    }

    /**
     * 同步单个文件夹
     *
     * @param array<string,mixed> $stats 引用累加
     * @return array<string,mixed>
     */
    private static function syncFolder(
        Imap $imap,
        array $account,
        string $folderName,
        int $batch,
        bool $saveRaw,
        bool $markSeen,
        int $limit,
        array &$stats
    ): array {
        $accountId = (int) $account['id'];
        $out = ['folder' => $folderName, 'new' => 0, 'error' => '', 'uidvalidity' => 0, 'last_uid' => 0];

        $sel = $imap->select($folderName, true);
        if (!$sel['ok']) {
            $out['error'] = '选择文件夹失败';
            return $out;
        }
        $uidvalidity = (int) $sel['uidvalidity'];

        $cursor = Store::ensureFolder($accountId, $folderName);
        $lastUid = (int) $cursor['last_uid'];

        // UIDVALIDITY 变化 → 重置游标（旧 UID 全部失效）
        if ($uidvalidity > 0 && (int) $cursor['uidvalidity'] > 0 && $uidvalidity !== (int) $cursor['uidvalidity']) {
            $lastUid = 0;
            $out['error'] = 'UIDVALIDITY 变化，已重置游标';
        }

        $criteria = $lastUid > 0 ? ('UID ' . ($lastUid + 1) . ':*') : 'ALL';
        $uids = $imap->searchUids($criteria);
        if ($lastUid > 0) {
            // UID n:* 在没有新邮件时仍会返回最后一封，需要过滤
            $uids = array_values(array_filter($uids, static fn(int $u): bool => $u > $lastUid));
        }
        if ($limit > 0 && count($uids) > $limit) {
            $uids = array_slice($uids, 0, $limit);
        }

        $out['uidvalidity'] = $uidvalidity;
        $maxUid = $lastUid;
        $stopAt = 0;   // 失败停点：>0 表示这一批在它之前停下

        foreach (array_chunk($uids, $batch) as $chunk) {
            foreach ($chunk as $uid) {
                $stats['fetched']++;
                if (Store::messageExists($accountId, (int) $cursor['id'], $uid)) {
                    $stats['skipped']++;
                    $maxUid = max($maxUid, $uid);
                    continue;
                }
                $raw = $imap->fetchRaw($uid);
                if ($raw === '') {
                    $stats['failed']++;
                    $stats['errors'][] = 'UID ' . $uid . ' 取原文失败: ' . $imap->lastError();
                    // 失败就停：游标不能越过这封，否则它永远不会被重试
                    $stopAt = $uid;
                    break 2;
                }
                try {
                    $parsed = Mime::parse($raw);
                    $threadId = Store::resolveThread($accountId, $parsed);

                    $rawPath = $saveRaw
                        ? Store::saveRaw($accountId, (int) $cursor['id'], $uid, $raw)
                        : '';

                    $messageId = Store::saveMessage([
                        'account_id'   => $accountId,
                        'folder_id'    => (int) $cursor['id'],
                        'uid'          => $uid,
                        'thread_id'    => $threadId,
                        'message_id'   => mb_substr((string) ($parsed['message_id'] ?? ''), 0, 250),
                        'in_reply_to'  => mb_substr((string) ($parsed['in_reply_to'] ?? ''), 0, 250),
                        'refs'         => mb_substr(implode(' ', (array) ($parsed['references'] ?? [])), 0, 4000),
                        'subject'      => mb_substr((string) ($parsed['subject'] ?? ''), 0, 1000),
                        'from_name'    => mb_substr((string) ($parsed['from'] ?? ''), 0, 250),
                        'from_addr'    => mb_substr((string) ($parsed['from_addr'] ?? ''), 0, 190),
                        'to_addrs'     => mb_substr((string) ($parsed['to'] ?? ''), 0, 2000),
                        'cc_addrs'     => mb_substr((string) ($parsed['cc'] ?? ''), 0, 2000),
                        'sent_at'      => self::parseDate((string) ($parsed['date'] ?? '')),
                        'received_at'  => null,
                        'size'         => strlen($raw),
                        // PG 的 boolean 列：PHP false 会被绑成 ''，必须显式给 true/false
                        'has_attachment' => !empty($parsed['attachments']) ? 'true' : 'false',
                        'body_text'    => (string) ($parsed['text'] ?? ''),
                        'body_html'    => (string) ($parsed['html'] ?? ''),
                        'raw_path'     => $rawPath,
                        'direction'    => 'in',
                    ]);

                    if ($messageId > 0) {
                        foreach ((array) ($parsed['attachments'] ?? []) as $att) {
                            Store::saveAttachment($messageId, $att);
                        }
                        if ($markSeen) {
                            $imap->markSeen($uid);
                        }
                        $stats['saved']++;
                        $out['new']++;
                        $maxUid = max($maxUid, $uid);
                    } else {
                        $stats['failed']++;
                        $stats['errors'][] = 'UID ' . $uid . ' 入库失败';
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $stats['errors'][] = 'UID ' . $uid . ' 解析异常: ' . $e->getMessage();
                    // 同上：解析失败也停在这里，下次重试
                    $stopAt = $uid;
                    break 2;
                }
            }

            // 每批结束推进游标（中途崩溃不会重复扫已完成的批次）
            if ($maxUid > $lastUid) {
                Store::setFolderCursor((int) $cursor['id'], $uidvalidity, $maxUid, $out['error']);
                $lastUid = $maxUid;
            }
        }

        if ($stopAt > 0) {
            $out['error'] = trim($out['error'] . '；在 UID ' . $stopAt . ' 处停止，下次重试');
        }
        Store::setFolderCursor((int) $cursor['id'], $uidvalidity, $maxUid, $out['error']);
        $out['last_uid'] = $maxUid;
        return $out;
    }

    /**
     * RFC822 日期 → 'Y-m-d H:i:s'（解析失败返回 null）
     */
    public static function parseDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $ts = strtotime($date);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
