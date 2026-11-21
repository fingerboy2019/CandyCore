<?php
/**
 * 邮件收发系统 · 基建命令行
 *
 * 用法：
 *   php candy mailbox install                          建表提示（打印 SQL 路径与执行命令）
 *   php candy mailbox add --email= --host= --port= --secure= --user= --pass= [--name=]
 *   php candy mailbox list                             账号列表
 *   php candy mailbox sync [--account=1] [--limit=50] [--folders=INBOX] [--mark-seen=0]
 *   php candy mailbox parse --file=/path/to/x.eml      只解析一封 EML（排障用，不入库）
 *   php candy mailbox stats                            各表行数
 *
 * @package Console\Command
 */
namespace Console\Command;

use Console\{Command, Input, Output};
use Console\Input\{Option, Argument};
use Candy\Extend\Network\Mail\{Mime, Store, Sync};

class Mailbox extends Command
{
    public function configure(): void
    {
        $this->setName('mailbox')
             ->addArgument('action', Argument::OPTIONAL, 'install|add|list|sync|parse|stats', 'install')
             ->addOption('account', null, Option::VALUE_OPTIONAL, '账号 id（sync 时指定）', '')
             ->addOption('limit', null, Option::VALUE_OPTIONAL, '本次最多处理几封', '50')
             ->addOption('folders', null, Option::VALUE_OPTIONAL, '文件夹，逗号分隔', 'INBOX')
             ->addOption('mark-seen', null, Option::VALUE_OPTIONAL, '同步后标记已读 1/0', '0')
             ->addOption('file', null, Option::VALUE_OPTIONAL, 'parse 时的 EML 路径', '')
             ->addOption('email', null, Option::VALUE_OPTIONAL, 'add 时的邮箱地址', '')
             ->addOption('name', null, Option::VALUE_OPTIONAL, 'add 时的显示名', '')
             ->addOption('host', null, Option::VALUE_OPTIONAL, 'add 时的 IMAP 主机', '')
             ->addOption('port', null, Option::VALUE_OPTIONAL, 'add 时的 IMAP 端口', '993')
             ->addOption('secure', null, Option::VALUE_OPTIONAL, 'ssl|tls|none', 'ssl')
             ->addOption('user', null, Option::VALUE_OPTIONAL, 'add 时的登录账号（默认同邮箱）', '')
             ->addOption('pass', null, Option::VALUE_OPTIONAL, 'add 时的授权码/密码', '')
             ->setDescription('Mail sync foundation for Candy (IMAP incremental + MIME parse).');
    }

    public function execute(Input $input, Output $output)
    {
        // CLI 下没有 FPM 的请求上下文：不补这两步，M() 会报
        // RuntimeClassLoader::resolveFqcn(): Argument #2 ($app) must be of type string, null given
        if (method_exists('\\Candy\\Core\\Bootstrap', 'loadDbConfig')) {
            \Candy\Core\Bootstrap::loadDbConfig();
        }
        if ((string) C('APPNAME') === '') {
            C('APPNAME', 'Home');
        }
        if ((string) C('APP_PATH') === '') {
            C('APP_PATH', rtrim((string) C('CANDYROOT'), '/\\') . '/Application/home/');
        }

        $action = (string) $input->getArgument('action');
        switch ($action) {
            case 'install':
                // 权威脚本在 Data/Mail/schema.sql（与邮件数据同目录）；Doc 下保留文档副本
                $sql = C('CANDYROOT') . 'Data/Mail/schema.sql';
                if (!is_file($sql)) {
                    $sql = C('CANDYROOT') . 'Doc/邮件/Mail表结构.sql';
                }
                $output->writeln('<info>建表 SQL：</info>' . $sql);
                $output->writeln('在服务器上执行：');
                $output->writeln("  PGPASSWORD=<密码> psql -h 127.0.0.1 -U candy -d okr_candy -f '" . $sql . "'");
                $output->writeln('<comment>注意：必须带 -h 127.0.0.1，否则走 unix socket 会 peer 认证失败</comment>');
                $output->writeln('执行完再看：php candy mailbox stats');
                return;

            case 'add':
                $email = trim((string) $input->getOption('email'));
                $host  = trim((string) $input->getOption('host'));
                if ($email === '' || $host === '') {
                    $output->writeln('<error>--email 与 --host 必填</error>');
                    return;
                }
                $user = trim((string) $input->getOption('user'));
                $id = Store::addAccount([
                    'name'        => (string) $input->getOption('name'),
                    'email'       => $email,
                    'imap_host'   => $host,
                    'imap_port'   => (int) $input->getOption('port'),
                    'imap_secure' => (string) $input->getOption('secure'),
                    'username'    => $user !== '' ? $user : $email,
                    'secret'      => (string) $input->getOption('pass'),
                ]);
                $output->writeln($id > 0 ? ('<info>已添加账号 id=' . $id . '</info>') : '<error>添加失败</error>');
                return;

            case 'list':
                $rows = Store::accounts(false);
                if ($rows === []) {
                    $output->writeln('（暂无账号）（或表未创建，先执行 php candy mailbox install）');
                    return;
                }
                $output->writeln('id | email | imap | status');
                foreach ($rows as $r) {
                    $output->writeln(sprintf(
                        '%s | %s | %s:%s(%s) | %s',
                        $r['id'], $r['email'], $r['imap_host'], $r['imap_port'], $r['imap_secure'], $r['status']
                    ));
                }
                return;

            case 'sync':
                $onlyId = (int) $input->getOption('account');
                $rows = Store::accounts();
                if ($onlyId > 0) {
                    $one = Store::account($onlyId);
                    $rows = $one === null ? [] : [$one];
                }
                if ($rows === []) {
                    $output->writeln('<error>没有可同步的账号（先 php candy mailbox add）</error>');
                    return;
                }
                $folders = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('folders')))));
                $opts = [
                    'folders'   => $folders === [] ? ['INBOX'] : $folders,
                    'batch'     => 20,
                    'limit'     => (int) $input->getOption('limit'),
                    'mark_seen' => (string) $input->getOption('mark-seen') === '1',
                    'save_raw'  => true,
                ];
                foreach ($rows as $acct) {
                    $output->writeln('同步：' . $acct['email']);
                    $stat = Sync::syncAccount($acct, $opts);
                    $output->writeln(sprintf(
                        '  拉取 %d · 入库 %d · 跳过 %d · 失败 %d · %s',
                        $stat['fetched'], $stat['saved'], $stat['skipped'], $stat['failed'],
                        $stat['ok'] ? 'OK' : '有失败'
                    ));
                    foreach ($stat['folders'] as $f) {
                        $output->writeln(sprintf(
                            '  · %s：新增 %d，游标 uidvalidity=%s last_uid=%s %s',
                            $f['folder'], $f['new'], $f['uidvalidity'], $f['last_uid'], $f['error']
                        ));
                    }
                    foreach ($stat['errors'] as $err) {
                        $output->writeln('  ! ' . $err);
                    }
                }
                return;

            case 'parse':
                $file = (string) $input->getOption('file');
                if ($file === '' || !is_file($file)) {
                    $output->writeln('<error>--file 指向的 EML 不存在</error>');
                    return;
                }
                $raw = (string) file_get_contents($file);
                $m = Mime::parse($raw);
                $output->writeln('主题：' . $m['subject']);
                $output->writeln('发件：' . $m['from'] . ' <' . $m['from_addr'] . '>');
                $output->writeln('日期：' . $m['date'] . '   charset=' . $m['charset'] . ' multipart=' . ($m['is_multipart'] ? 'yes' : 'no'));
                $output->writeln('正文(text) 长度：' . mb_strlen($m['text']) . ' ，html 长度：' . mb_strlen($m['html']));
                $output->writeln('附件：' . count($m['attachments']) . ' 个');
                foreach ($m['attachments'] as $att) {
                    $output->writeln('  - ' . $att['filename'] . ' (' . $att['mime'] . ', ' . $att['size'] . ' B)');
                }
                $output->writeln('--- text 前 300 字 ---');
                $output->writeln(mb_substr($m['text'], 0, 300));
                return;

            case 'stats':
                $s = Store::stats();
                foreach ($s as $k => $v) {
                    $output->writeln(sprintf('  %-16s %d', $k, $v));
                }
                return;

            default:
                $output->writeln('<error>未知动作：' . $action . '</error>');
        }
    }
}
