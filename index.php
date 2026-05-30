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

$credentials = loadCredentials(__DIR__.'/x.c');
$token = $credentials['token'];
$username = ltrim($credentials['username'], '@');

$bot = new PHPTelebot($token, $username, [
    'allowed_updates' => [
        'message',
        'edited_message',
        'callback_query',
        'inline_query',
        'business_message',
        'guest_message',
        'message_reaction',
        'message_reaction_count',
        'chat_member',
        'managed_bot',
    ],
]);

$lapakMemberChatId = isset($credentials['lapak_member_chat_id']) && $credentials['lapak_member_chat_id'] !== ''
    ? $credentials['lapak_member_chat_id']
    : '-1001197136417';
$lapakMemberThreadId = isset($credentials['lapak_member_thread_id']) && $credentials['lapak_member_thread_id'] !== ''
    ? $credentials['lapak_member_thread_id']
    : '3282669';

// Lapak Member topic: each user may send up to 2 messages per day.
$bot->enforceMessageThreadLimit($lapakMemberChatId, $lapakMemberThreadId, 2, [
    'storage_path' => __DIR__.'/runtime/lapak-member-limits.json',
    'warning_text' => 'Limit Lapak Member: setiap user maksimal %d pesan per hari.',
]);

// Simple echo command
$bot->cmd('/echo|/say', function ($text) {
    if ($text == '') {
        $text = 'Command usage: /echo [text] or /say [text]';
    }

    return Bot::sendMessage($text);
});

// Show the current update and message type.
$bot->cmd('/status', function () {
    $text = "Update type: ".Bot::updateType()."\n";
    $text .= "Event type: ".Bot::type();

    return Bot::sendMessage($text, ['reply' => true]);
});

// Simple whoami command
$bot->cmd('/whoami', function () {
    // Get message properties
    $message = Bot::message();
    $name = $message['from']['first_name'];
    $userId = $message['from']['id'];
    $text = 'You are <b>'.$name.'</b> and your ID is <code>'.$userId.'</code>';
    $options = [
        'parse_mode' => 'html',
        'reply' => true,
    ];

    return Bot::sendMessage($text, $options);
});

// slice text by space
$bot->cmd('/split', function ($one, $two, $three) {
    $text = "First word: $one\n";
    $text .= "Second word: $two\n";
    $text .= "Third word: $three";

    return Bot::sendMessage($text);
});

// simple file upload
$bot->cmd('/upload', function () {
    $file = './composer.json';

    return Bot::sendDocument([
        'document' => $file,
        'caption' => 'composer.json uploaded from a local path',
    ]);
});

// inline keyboard
$bot->cmd('/keyboard', function () {
    $keyboard[] = [
        ['text' => 'PHPTelebot', 'url' => 'https://github.com/radyakaze/phptelebot'],
        ['text' => 'Callback', 'callback_data' => 'sample_callback'],
    ];
    $options = [
        'reply_markup' => ['inline_keyboard' => $keyboard],
    ];

    return Bot::sendMessage('Inline keyboard', $options);
});

// Send a poll with nested array parameters. PHPTelebot JSON-encodes arrays.
$bot->cmd('/poll', function () {
    return Bot::sendPoll([
        'question' => 'Which update transport are you using?',
        'options' => [
            ['text' => 'Long polling'],
            ['text' => 'Webhook'],
        ],
        'is_anonymous' => false,
        'allows_multiple_answers' => false,
    ]);
});

// Stream a temporary draft, then send the final message.
$bot->cmd('/draft', function () {
    Bot::sendMessageDraft([
        'draft_id' => time(),
        'text' => 'Preparing the final response...',
    ]);

    return Bot::sendMessage('Final response persisted in the chat.');
});

// custom regex
$bot->regex('/\/number ([0-9]+)/i', function ($matches) {
    return Bot::sendMessage($matches[1]);
});

// Inline
$bot->on('inline', function ($text) {
    $results[] = [
        'type' => 'article',
        'id' => 'unique_id1',
        'title' => $text ?: 'PHPTelebot sample',
        'input_message_content' => [
            'message_text' => 'Inline result from PHPTelebot',
        ],
    ];
    $options = [
        'cache_time' => 3600,
    ];

    return Bot::answerInlineQuery($results, $options);
});

// Callback query from the inline keyboard.
$bot->on('callback', function ($data) {
    Bot::answerCallbackQuery('Callback data: '.$data);

    return Bot::sendMessage('Callback query handled.', ['reply' => true]);
});

// Business messages can be answered with the current business connection.
$bot->on('business_message', function () {
    return Bot::sendMessage('Business message received.');
});

// Guest messages must be answered with answerGuestQuery().
$bot->on('guest_message', function () {
    return Bot::answerGuestQuery([
        'type' => 'article',
        'id' => 'guest_reply',
        'title' => 'PHPTelebot guest reply',
        'input_message_content' => [
            'message_text' => 'Guest message received by PHPTelebot.',
        ],
    ]);
});

// Reaction updates are only delivered when requested in allowed_updates.
$bot->on('message_reaction|message_reaction_count', function ($update) {
    error_log('Reaction update: '.json_encode($update));
});

// Fallback for messages that did not match a command or specific event.
$bot->on('*', function () {
    if (Bot::updateType() == 'message') {
        return Bot::sendMessage('Hi, human! I am a bot.');
    }
});

$bot->run();
