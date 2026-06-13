<?php

require_once __DIR__.'/src/PHPTelebot.php';

function loadCredentials($path)
{
    if (!is_file($path)) {
        die("Create x.c with token and username before running index.php.\nExample:\ntoken=123456:ABCDEF\nusername=YourBot\n");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    $values = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line == '' || substr($line, 0, 1) == '#') {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        } else {
            $values[] = $line;
        }
    }

    if (isset($config['token'])) {
        $config['username'] = isset($config['username']) ? $config['username'] : '';

        return $config;
    }

    return [
        'token' => isset($values[0]) ? $values[0] : '',
        'username' => isset($values[1]) ? $values[1] : '',
    ];
}

function configuredThreadIds($credentials)
{
    $threadIds = isset($credentials['lapak_member_thread_ids']) && $credentials['lapak_member_thread_ids'] !== ''
        ? explode(',', $credentials['lapak_member_thread_ids'])
        : ['3282669', '4226256'];

    $normalized = [];
    foreach ($threadIds as $threadId) {
        $threadId = trim($threadId);
        if ($threadId != '') {
            $normalized[] = $threadId;
        }
    }

    return $normalized;
}

function satpamRichEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function satpamRichTable($title, $headers, $rows)
{
    $html = '<h3>'.satpamRichEscape($title).'</h3>';
    $html .= '<table bordered striped><tr>';
    foreach ($headers as $header) {
        $html .= '<th>'.satpamRichEscape($header).'</th>';
    }
    $html .= '</tr>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $index => $value) {
            $align = is_numeric($value) ? ' align="right"' : ' align="left"';
            $html .= '<td'.$align.'>'.satpamRichEscape($value).'</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</table>';

    return $html;
}

function satpamRichMessage($title, $tables, $footer)
{
    $html = '<h2>'.satpamRichEscape($title).'</h2>'.$tables;
    if ($footer != '') {
        $html .= '<footer>'.satpamRichEscape($footer).'</footer>';
    }

    return [
        'html' => $html,
        'skip_entity_detection' => true,
    ];
}

function satpamKeyboard()
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Ringkasan', 'callback_data' => 'satpam:summary'],
                ['text' => 'Leaderboard', 'callback_data' => 'satpam:leaderboard'],
                ['text' => 'Total', 'callback_data' => 'satpam:total'],
            ],
        ],
    ];
}

function satpamSummaryRichMessage($bot, $chatId, $threadIds, $threadNames, $storagePath)
{
    $totals = $bot->messageThreadLimitWarningTotals($chatId, $threadIds, $storagePath);
    $tables = '';

    foreach ($threadIds as $threadId) {
        $count = isset($totals[$threadId]) ? $totals[$threadId] : 0;
        $name = isset($threadNames[$threadId]) ? $threadNames[$threadId] : 'Topik '.$threadId;
        $tables .= satpamRichTable($name, ['User Kena Warning'], [[$count]]);
    }

    return satpamRichMessage('Tangkapan Satpam Hari Ini', $tables, 'Batas: 2 pesan per user per topik per hari.');
}

function satpamLeaderboardRichMessage($bot, $chatId, $threadIds, $threadNames, $storagePath)
{
    $violations = $bot->messageThreadLimitViolations($chatId, $threadIds, $storagePath, '*');
    $tables = '';

    foreach ($threadIds as $threadId) {
        $name = isset($threadNames[$threadId]) ? $threadNames[$threadId] : 'Topik '.$threadId;
        $rows = [];
        if (!empty($violations[$threadId])) {
            $rank = 1;
            foreach ($violations[$threadId] as $violation) {
                $rows[] = [$rank, $violation['name'], $violation['count']];
                $rank++;
            }
        }

        if (empty($rows)) {
            $rows[] = ['-', 'Belum ada pelanggar', 0];
        }
        $tables .= satpamRichTable($name, ['#', 'User', 'Pelanggaran'], $rows);
    }

    return satpamRichMessage('Leaderboard Pelanggar', $tables, 'Data sepanjang waktu. User dibanned setelah 3 pelanggaran.');
}

function satpamTotalRichMessage($bot, $chatId, $threadIds, $threadNames, $storagePath)
{
    $totals = $bot->messageThreadLimitWarningTotals($chatId, $threadIds, $storagePath, '*');
    $violations = $bot->messageThreadLimitViolations($chatId, $threadIds, $storagePath, '*');
    $tables = '';

    foreach ($threadIds as $threadId) {
        $warningCount = isset($totals[$threadId]) ? $totals[$threadId] : 0;
        $violationCount = 0;
        if (isset($violations[$threadId])) {
            foreach ($violations[$threadId] as $violation) {
                $violationCount += $violation['count'];
            }
        }

        $name = isset($threadNames[$threadId]) ? $threadNames[$threadId] : 'Topik '.$threadId;
        $tables .= satpamRichTable($name, ['User Kena Warning', 'Pelanggaran'], [[$warningCount, $violationCount]]);
    }

    return satpamRichMessage('Total Tangkapan Satpam', $tables, 'Data sepanjang waktu.');
}

$credentials = loadCredentials(__DIR__.'/x.c');
$token = $credentials['token'];
$username = ltrim($credentials['username'], '@');

$bot = new PHPTelebot($token, $username, [
    'allowed_updates' => ['message', 'callback_query'],
]);

$lapakMemberChatId = isset($credentials['lapak_member_chat_id']) && $credentials['lapak_member_chat_id'] !== ''
    ? $credentials['lapak_member_chat_id']
    : '-1001197136417';
$lapakMemberThreadIds = configuredThreadIds($credentials);
$lapakMemberThreadNames = [
    '3282669' => 'Lapak Digital',
    '4226256' => 'Lapak Fisik',
];
$lapakLimitStorage = __DIR__.'/runtime/lapak-member-limits.sqlite';
$lapakWarningText = 'Limit Lapak Member: setiap user maksimal %d pesan per hari.';

$bot->trackChatMessageStats($lapakMemberChatId, $lapakLimitStorage);

$bot->cmd('oe', 'hadirr');

$bot->cmd('/satpam|/satspam', function () use ($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage) {
    return Bot::sendRichMessage([
        'rich_message' => satpamSummaryRichMessage($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage),
        'reply' => true,
        'reply_markup' => satpamKeyboard(),
    ]);
});

$bot->on('callback', function ($data) use ($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage) {
    if ($data != 'satpam:summary' && $data != 'satpam:leaderboard' && $data != 'satpam:total') {
        return false;
    }

    $callback = Bot::message();
    if (!isset($callback['message']['message_id']) || !isset($callback['message']['chat']['id'])) {
        return false;
    }

    Bot::answerCallbackQuery($data == 'satpam:summary' ? 'Data hari ini' : 'Data sepanjang waktu');
    if ($data == 'satpam:leaderboard') {
        $richMessage = satpamLeaderboardRichMessage($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage);
    } elseif ($data == 'satpam:total') {
        $richMessage = satpamTotalRichMessage($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage);
    } else {
        $richMessage = satpamSummaryRichMessage($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage);
    }

    return Bot::editMessageText([
        'chat_id' => $callback['message']['chat']['id'],
        'message_id' => $callback['message']['message_id'],
        'rich_message' => $richMessage,
        'reply_markup' => satpamKeyboard(),
    ]);
});

// Lapak Member topics: each user may send up to 2 messages per topic per day.
foreach ($lapakMemberThreadIds as $lapakMemberThreadId) {
    $bot->enforceMessageThreadLimit($lapakMemberChatId, $lapakMemberThreadId, 2, [
        'storage_path' => $lapakLimitStorage,
        'warning_text' => $lapakWarningText,
        'ignored_commands' => ['/satpam'],
        'warning_cooldown' => 300,
        'mention_user' => true,
        'whitelist_sender_tag' => true,
        'ban_after_violations' => 3,
        'ban_text' => 'mencapai %d pelanggaran dan telah dibanned.',
    ]);
}

$bot->run();
