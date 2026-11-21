<?php
/**
 * Mock IMAP 服务器（仅用于验证客户端同步逻辑，不参与生产）
 *
 * 支持：CAPABILITY / LOGIN / LIST / EXAMINE|SELECT / UID SEARCH / UID FETCH / LOGOUT
 * 邮件来源：Runtime/Mail/mock/*.eml（按文件名排序，uid = 序号 + 1）
 * UIDVALIDITY 固定 777；监听 127.0.0.1:14399
 *
 * 启动：php Runtime/Mail/mock_server.php  （后台）
 */
$port = (int) ($argv[1] ?? 14399);
$uidvalidity = 777;

$dir = dirname(__DIR__) . '/../Data/Mail/mock';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$files = glob($dir . '/*.eml') ?: [];
sort($files);
$messages = [];
foreach ($files as $i => $f) {
    $messages[$i + 1] = (string) file_get_contents($f);
}

fwrite(STDERR, '[mock] messages=' . count($messages) . " port={$port}\n");

$srv = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!is_resource($srv)) {
    fwrite(STDERR, "[mock] listen failed: {$errstr}\n");
    exit(1);
}
fwrite(STDERR, "[mock] listening\n");

while (true) {
    $conn = @stream_socket_accept($srv, 0);
    if (!is_resource($conn)) {
        usleep(50000);
        continue;
    }
    fwrite($conn, "* OK mock IMAP ready\r\n");
    $selected = false;
    $state = ['authed' => false];

    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        $parts = explode(' ', $line, 3);
        $tag = $parts[0] ?? '';
        $cmd = strtoupper($parts[1] ?? '');
        $rest = $parts[2] ?? '';

        if ($cmd === 'CAPABILITY') {
            fwrite($conn, "* CAPABILITY IMAP4rev1 UIDPLUS\r\n{$tag} OK CAPABILITY completed\r\n");
        } elseif ($cmd === 'LOGIN') {
            $state['authed'] = true;
            fwrite($conn, "{$tag} OK LOGIN completed\r\n");
        } elseif ($cmd === 'LIST') {
            fwrite($conn, "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n{$tag} OK LIST completed\r\n");
        } elseif ($cmd === 'EXAMINE' || $cmd === 'SELECT') {
            $selected = true;
            $n = count($messages);
            $next = $n + 1;
            fwrite($conn, "* {$n} EXISTS\r\n* 0 RECENT\r\n* OK [UIDVALIDITY {$uidvalidity}] UIDs valid\r\n* OK [UIDNEXT {$next}] Predicted next UID\r\n{$tag} OK [READ-ONLY] EXAMINE completed\r\n");
        } elseif ($cmd === 'UID') {
            $sub = strtoupper(explode(' ', $rest)[0] ?? '');
            if ($sub === 'SEARCH') {
                $crit = trim(substr($rest, strlen(explode(' ', $rest)[0])));
                $uids = array_keys($messages);
                if (preg_match('/UID\s+(\d+):\*/i', $crit, $m)) {
                    $from = (int) $m[1];
                    $uids = array_values(array_filter($uids, static fn($u) => $u >= $from));
                }
                // 没有匹配时，真实服务器会返回空 SEARCH（客户端自己过滤）
                fwrite($conn, '* SEARCH' . ($uids ? ' ' . implode(' ', $uids) : '') . "\r\n{$tag} OK SEARCH completed\r\n");
            } elseif ($sub === 'FETCH') {
                $args = trim(substr($rest, strlen(explode(' ', $rest)[0])));
                if (preg_match('/^(\d+)/', $args, $m)) {
                    $uid = (int) $m[1];
                    if (isset($messages[$uid])) {
                        $raw = $messages[$uid];
                        $len = strlen($raw);
                        // 序号固定用 1（客户端按 UID 匹配）
                        $head = "* 1 FETCH (UID {$uid} BODY[] {" . $len . "}\r\n";
                        fwrite($conn, $head . $raw . ")\r\n");
                        fwrite($conn, "{$tag} OK FETCH completed\r\n");
                        continue;
                    }
                }
                fwrite($conn, "{$tag} OK FETCH completed\r\n");
            } elseif ($sub === 'STORE') {
                fwrite($conn, "{$tag} OK STORE completed\r\n");
            } else {
                fwrite($conn, "{$tag} BAD unknown UID command\r\n");
            }
        } elseif ($cmd === 'NOOP') {
            fwrite($conn, "{$tag} OK NOOP completed\r\n");
        } elseif ($cmd === 'LOGOUT') {
            fwrite($conn, "* BYE mock logging out\r\n{$tag} OK LOGOUT completed\r\n");
            break;
        } else {
            fwrite($conn, "{$tag} BAD unknown command: {$cmd}\r\n");
        }
    }
    @fclose($conn);
}
