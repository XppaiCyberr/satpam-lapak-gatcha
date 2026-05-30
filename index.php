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

function satpamCodeBlock($text)
{
    return "```\n".str_replace('```', "'''", trim($text))."\n```";
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

function satpamSummaryText($bot, $chatId, $threadIds, $threadNames, $storagePath)
{
    $totals = $bot->messageThreadLimitWarningTotals($chatId, $threadIds, $storagePath);
    $text = "Tangkapan Satpam hari ini\n";

    foreach ($threadIds as $threadId) {
        $count = isset($totals[$threadId]) ? $totals[$threadId] : 0;
        $name = isset($threadNames[$threadId]) ? $threadNames[$threadId] : 'Topik '.$threadId;
        $text .= $name.': '.$count." user kena warning\n";
    }

    return $text;
}

function satpamLeaderboardText($bot, $chatId, $threadIds, $threadNames, $storagePath)
{
    $violations = $bot->messageThreadLimitViolations($chatId, $threadIds, $storagePath);
    $text = "Leaderboard pelanggar hari ini\n";

    foreach ($threadIds as $threadId) {
        $name = isset($threadNames[$threadId]) ? $threadNames[$threadId] : 'Topik '.$threadId;
        $text .= "\n".$name."\n";

        if (empty($violations[$threadId])) {
            $text .= "Belum ada pelanggar\n";
            continue;
        }

        $rank = 1;
        foreach ($violations[$threadId] as $violation) {
            $text .= $rank.'. '.$violation['name'].' - '.$violation['count']." pelanggaran\n";
            $rank++;
        }
    }

    return $text;
}

function satpamTotalText($bot, $chatId, $threadIds, $threadNames, $storagePath)
{
    $totals = $bot->messageThreadLimitWarningTotals($chatId, $threadIds, $storagePath, '*');
    $violations = $bot->messageThreadLimitViolations($chatId, $threadIds, $storagePath, '*');
    $text = "Total Tangkapan Satpam\n";

    foreach ($threadIds as $threadId) {
        $warningCount = isset($totals[$threadId]) ? $totals[$threadId] : 0;
        $violationCount = 0;
        if (isset($violations[$threadId])) {
            foreach ($violations[$threadId] as $violation) {
                $violationCount += $violation['count'];
            }
        }

        $name = isset($threadNames[$threadId]) ? $threadNames[$threadId] : 'Topik '.$threadId;
        $text .= $name.': '.$warningCount.' user, '.$violationCount." pelanggaran\n";
    }

    return $text;
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

$bot->cmd('/satpam', function () use ($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage) {
    return Bot::sendMessage(satpamCodeBlock(satpamSummaryText($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage)), [
        'parse_mode' => 'markdown',
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

    Bot::answerCallbackQuery($data == 'satpam:total' ? 'Data total' : 'Data hari ini');
    if ($data == 'satpam:leaderboard') {
        $text = satpamLeaderboardText($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage);
    } elseif ($data == 'satpam:total') {
        $text = satpamTotalText($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage);
    } else {
        $text = satpamSummaryText($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage);
    }

    return Bot::editMessageText([
        'chat_id' => $callback['message']['chat']['id'],
        'message_id' => $callback['message']['message_id'],
        'text' => satpamCodeBlock($text),
        'parse_mode' => 'markdown',
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
    ]);
}

$bot->run();
