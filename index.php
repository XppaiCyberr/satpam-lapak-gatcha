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

$credentials = loadCredentials(__DIR__.'/x.c');
$token = $credentials['token'];
$username = ltrim($credentials['username'], '@');

$bot = new PHPTelebot($token, $username, [
    'allowed_updates' => ['message'],
]);

$lapakMemberChatId = isset($credentials['lapak_member_chat_id']) && $credentials['lapak_member_chat_id'] !== ''
    ? $credentials['lapak_member_chat_id']
    : '-1001197136417';
$lapakMemberThreadIds = configuredThreadIds($credentials);
$lapakMemberThreadNames = [
    '3282669' => 'Lapak Digital',
    '4226256' => 'Lapak Fisik',
];
$lapakLimitStorage = __DIR__.'/runtime/lapak-member-limits.json';
$lapakWarningText = 'Limit Lapak Member: setiap user maksimal %d pesan per hari.';

$bot->cmd('/satpam', function () use ($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage) {
    $totals = $bot->messageThreadLimitWarningTotals($lapakMemberChatId, $lapakMemberThreadIds, $lapakLimitStorage);
    $text = "Satpam Lapak hari ini\n";

    foreach ($lapakMemberThreadIds as $threadId) {
        $count = isset($totals[$threadId]) ? $totals[$threadId] : 0;
        $name = isset($lapakMemberThreadNames[$threadId]) ? $lapakMemberThreadNames[$threadId] : 'Topik '.$threadId;
        $text .= $name.': '.$count." user kena warning\n";
    }

    return Bot::sendMessage(trim($text), ['reply' => true]);
});

$bot->cmd('/satpamlb', function () use ($bot, $lapakMemberChatId, $lapakMemberThreadIds, $lapakMemberThreadNames, $lapakLimitStorage) {
    $violations = $bot->messageThreadLimitViolations($lapakMemberChatId, $lapakMemberThreadIds, $lapakLimitStorage);
    $text = "Leaderboard pelanggar hari ini\n";

    foreach ($lapakMemberThreadIds as $threadId) {
        $name = isset($lapakMemberThreadNames[$threadId]) ? $lapakMemberThreadNames[$threadId] : 'Topik '.$threadId;
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

    return Bot::sendMessage(trim($text), ['reply' => true]);
});

// Lapak Member topics: each user may send up to 2 messages per topic per day.
foreach ($lapakMemberThreadIds as $lapakMemberThreadId) {
    $bot->enforceMessageThreadLimit($lapakMemberChatId, $lapakMemberThreadId, 2, [
        'storage_path' => $lapakLimitStorage,
        'warning_text' => $lapakWarningText,
        'ignored_commands' => ['/satpam', '/satpamlb'],
        'warning_cooldown' => 300,
        'mention_user' => true,
        'whitelist_sender_tag' => true,
    ]);
}

$bot->run();
