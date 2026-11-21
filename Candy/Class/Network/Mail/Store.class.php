<?php
/**
 * 邮件存储（账号 / 文件夹游标 / 线程 / 邮件 / 附件）
 *
 * 只做数据访问，不含业务判断（客户、询盘由上层做）。
 * 表结构见 `Doc/邮件/Mail表结构.sql`，或命令行 `php candy mailbox install` 打印。
 *
 * 设计要点：
 *   ① 文件夹游标存 `uidvalidity + last_uid`。**UIDVALIDITY 变了必须重置游标**，
 *      否则会把旧 UID 当成新邮件（或漏掉全部新邮件）。
 *   ② 唯一键 (account_id, folder_id, uid) 保证重复同步幂等。
 *   ③ 原始 EML 落盘（save_raw），正文解析失败时还能回溯。
 *
 * @package Candy\Extend\Network\Mail
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 16:00:00
 */
declare(strict_types=1);

namespace Candy\Extend\Network\Mail;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Store
{
    /** @var array<string,object> 表对象缓存（M() 本身也是单例，这里只减少函数调用） */
    private static array $models = [];

    private static function model(string $name): object
    {
        if (!isset(self::$models[$name])) {
            self::$models[$name] = M($name);
        }
        return self::$models[$name];
    }

    // ── 账号 ──────────────────────────────────────────────

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function accounts(bool $onlyActive = true): array
    {
        $q = self::model('MailAccounts');
        if ($onlyActive) {
            $q = $q->where(['status' => 1]);
        }
        $rows = $q->order('id', 'asc')->select();
        return is_array($rows) ? $rows : [];
    }

    public static function account(int $id): ?array
    {
        $row = self::model('MailAccounts')->where(['id' => $id])->find();
        return is_array($row) && $row ? $row : null;
    }

    public static function findAccountByEmail(string $email): ?array
    {
        $row = self::model('MailAccounts')->where(['email' => $email])->find();
        return is_array($row) && $row ? $row : null;
    }

    /**
     * 新增账号
     *
     * @param array<string,mixed> $data
     * @return int 新账号 id（失败 0）
     */
    public static function addAccount(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $now;
        $data['status']     = $data['status'] ?? 1;
        $id = self::model('MailAccounts')->add($data);
        return (int) $id;
    }

    // ── 文件夹游标 ────────────────────────────────────────

    public static function folder(int $accountId, string $name): ?array
    {
        $row = self::model('MailFolders')->where(['account_id' => $accountId, 'name' => $name])->find();
        return is_array($row) && $row ? $row : null;
    }

    /**
     * 取或建文件夹记录，返回 [id, uidvalidity, last_uid]
     *
     * @return array{id:int,uidvalidity:int,last_uid:int}
     */
    public static function ensureFolder(int $accountId, string $name): array
    {
        $row = self::folder($accountId, $name);
        if ($row === null) {
            $id = (int) self::model('MailFolders')->add([
                'account_id'  => $accountId,
                'name'        => $name,
                'uidvalidity' => 0,
                'last_uid'    => 0,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return ['id' => $id, 'uidvalidity' => 0, 'last_uid' => 0];
        }
        return [
            'id'          => (int) $row['id'],
            'uidvalidity' => (int) ($row['uidvalidity'] ?? 0),
            'last_uid'    => (int) ($row['last_uid'] ?? 0),
        ];
    }

    /**
     * 更新文件夹游标
     */
    public static function setFolderCursor(int $folderId, int $uidvalidity, int $lastUid, string $error = ''): void
    {
        self::model('MailFolders')->where(['id' => $folderId])->update([
            'uidvalidity'  => $uidvalidity,
            'last_uid'     => $lastUid,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'error'        => $error,
        ]);
    }

    // ── 邮件 ─────────────────────────────────────────────

    public static function messageExists(int $accountId, int $folderId, int $uid): bool
    {
        $n = self::model('MailMessages')
            ->where(['account_id' => $accountId, 'folder_id' => $folderId, 'uid' => $uid])
            ->count();
        return (int) $n > 0;
    }

    /**
     * 写入一封邮件，返回邮件 id（已存在则返回既有 id）
     *
     * @param array<string,mixed> $data
     */
    public static function saveMessage(array $data): int
    {
        $accountId = (int) ($data['account_id'] ?? 0);
        $folderId  = (int) ($data['folder_id'] ?? 0);
        $uid       = (int) ($data['uid'] ?? 0);

        if ($accountId > 0 && $folderId > 0 && $uid > 0) {
            $exist = self::model('MailMessages')
                ->where(['account_id' => $accountId, 'folder_id' => $folderId, 'uid' => $uid])
                ->find();
            if (is_array($exist) && $exist) {
                return (int) $exist['id'];
            }
        }

        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        return (int) self::model('MailMessages')->add($data);
    }

    public static function message(int $id): ?array
    {
        $row = self::model('MailMessages')->where(['id' => $id])->find();
        return is_array($row) && $row ? $row : null;
    }

    /**
     * 最近邮件（调试/后续界面用）
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recentMessages(int $limit = 20, int $accountId = 0): array
    {
        $q = self::model('MailMessages');
        if ($accountId > 0) {
            $q = $q->where(['account_id' => $accountId]);
        }
        $rows = $q->order('id', 'desc')->limit($limit)->select();
        return is_array($rows) ? $rows : [];
    }

    // ── 线程 ─────────────────────────────────────────────

    /**
     * 归并到线程：按 In-Reply-To / References / 归一化主题
     *
     * 顺序（越靠前越可靠）：
     *   ① References 里能找到已有 message_id 的邮件 → 用它的线程
     *   ② In-Reply-To 命中 → 用它
     *   ③ 都没有 → 新建线程（thread_key 用归一化主题 + 首封 message_id）
     *
     * @param array<string,mixed> $mail Mime::parse() 的结果
     * @return int thread_id
     */
    public static function resolveThread(int $accountId, array $mail): int
    {
        $candidates = [];
        foreach ((array) ($mail['references'] ?? []) as $ref) {
            $ref = trim((string) $ref, " \t<>");
            if ($ref !== '') {
                $candidates[] = $ref;
            }
        }
        $replyTo = trim((string) ($mail['in_reply_to'] ?? ''), " \t<>");
        if ($replyTo !== '') {
            array_unshift($candidates, $replyTo);   // In-Reply-To 最直接
        }

        if ($candidates !== []) {
            $row = self::model('MailMessages')
                ->where(['account_id' => $accountId, 'message_id' => $candidates])
                ->order('id', 'asc')
                ->find();
            if (is_array($row) && $row && !empty($row['thread_id'])) {
                return (int) $row['thread_id'];
            }
        }

        $subjectNorm = self::normalizeSubject((string) ($mail['subject'] ?? ''));
        $key = $subjectNorm !== ''
            ? $subjectNorm
            : ('mid:' . (string) ($mail['message_id'] ?? md5((string) ($mail['date'] ?? ''))));

        $exist = self::model('MailThreads')
            ->where(['account_id' => $accountId, 'thread_key' => mb_substr($key, 0, 180)])
            ->find();
        if (is_array($exist) && $exist) {
            return (int) $exist['id'];
        }

        $threadId = (int) self::model('MailThreads')->add([
            'account_id'  => $accountId,
            'thread_key'  => mb_substr($key, 0, 180),
            'subject_norm'=> mb_substr($subjectNorm, 0, 250),
            'message_count' => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        return $threadId;
    }

    /**
     * 主题归一化：去掉 Re:/Fwd:/答复:/转发: 与首尾空白、压缩空格
     */
    public static function normalizeSubject(string $subject): string
    {
        $s = (string) preg_replace(
            '/^\s*((re|fw|fwd|aw|sv|答复|回复|转发)\s*[:：]\s*)+/iu',
            '',
            trim($subject)
        );
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        return mb_substr(trim($s), 0, 250);
    }

    /**
     * 附件入库
     *
     * @param array<string,mixed> $att
     */
    public static function saveAttachment(int $messageId, array $att): int
    {
        return (int) self::model('MailAttachments')->add([
            'message_id' => $messageId,
            'filename'   => mb_substr((string) ($att['filename'] ?? ''), 0, 250),
            'mime'       => mb_substr((string) ($att['mime'] ?? ''), 0, 120),
            'size'       => (int) ($att['size'] ?? 0),
            'cid'        => mb_substr((string) ($att['cid'] ?? ''), 0, 180),
            'oss_path'   => (string) ($att['oss_path'] ?? ''),
            'storage'    => (string) ($att['storage'] ?? 'local'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 原始 EML 落盘，返回相对路径
     */
    public static function saveRaw(int $accountId, int $folderId, int $uid, string $raw): string
    {
        $root = (string) C('CANDYROOT');
        if ($root === '') {
            $root = rtrim((string) CANDYPATH, '/\\') . '/';
        }
        // ⚠️ 必须落「项目根目录 Data/」，不能落 Runtime/ 下：
        //    Runtime/{Cache,Comps,Controls,Logs,Models,Services,Tmps} 会被清缓存整片删掉，
        //    而 EML 是权威原始数据，丢了只能回邮件服务器重拉（服务商可能已删）。
        //    根目录 Data/ 才是持久数据目录（与 session 同一层语义：用户数据，不动）。
        $dir = rtrim($root, '/\\') . '/Data/Mail/raw/' . $accountId . '/' . $folderId;
        \Candy\Extend\Dir::create($dir, 0775);
        $file = $dir . '/' . $uid . '.eml';
        @file_put_contents($file, $raw);
        return 'Data/Mail/raw/' . $accountId . '/' . $folderId . '/' . $uid . '.eml';
    }

    /**
     * 统计（调试用）
     *
     * @return array<string,int>
     */
    public static function stats(): array
    {
        $out = [];
        foreach (['MailAccounts', 'MailFolders', 'MailThreads', 'MailMessages', 'MailAttachments'] as $name) {
            $out[$name] = (int) self::model($name)->count();
        }
        return $out;
    }
}
