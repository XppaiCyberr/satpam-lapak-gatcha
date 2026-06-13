<?php
/**
 * PHPTelebot.php.
 *
 *
 * @author Radya <radya@gmx.com>
 *
 * @link https://github.com/radyakaze/phptelebot
 *
 * @license GPL-3.0
 */

/**
 * Class PHPTelebot.
 */
class PHPTelebot
{
    /**
     * @var array
     */
    public static $getUpdates = [];
    /**
     * @var array
     */
    protected $_command = [];
    /**
     * @var array
     */
    protected $_onMessage = [];
    /**
     * @var array
     */
    protected $_options = [];
    /**
     * @var array
     */
    protected $_messageThreadLimits = [];
    /**
     * @var array
     */
    protected $_chatMessageStats = [];
    /**
     * Bot token.
     *
     * @var string
     */
    public static $token = '';
    /**
     * Bot username.
     *
     * @var string
     */
    protected static $username = '';

    /**
     * Debug.
     *
     * @var bool
     */
    public static $debug = true;

    /**
     * PHPTelebot version.
     *
     * @var string
     */
    protected static $version = '1.4';

    /**
     * PHPTelebot Constructor.
     *
     * @param string $token
     * @param string $username
     * @param array  $options
     */
    public function __construct($token, $username = '', $options = [])
    {
        // Check php version
        if (version_compare(phpversion(), '5.4', '<')) {
            die("PHPTelebot needs to use PHP 5.4 or higher.\n");
        }

        // Check curl
        if (!function_exists('curl_version')) {
            die("cURL is NOT installed on this server.\n");
        }

        // Check bot token
        if (empty($token)) {
            die("Bot token should not be empty!\n");
        }

        self::$token = $token;
        self::$username = $username;
        $this->_options = is_array($options) ? $options : [];
    }

    /**
     * Command.
     *
     * @param string          $command
     * @param callable|string $answer
     */
    public function cmd($command, $answer)
    {
        if ($command != '*') {
            $this->_command[$command] = $answer;
        }

        if (strrpos($command, '*') !== false) {
            $this->_onMessage['text'] = $answer;
        }
    }
    /**
     * Events.
     *
     * @param string          $types
     * @param callable|string $answer
     */
    public function on($types, $answer)
    {
        $types = explode('|', $types);
        foreach ($types as $type) {
            $this->_onMessage[$type] = $answer;
        }
    }

    /**
     * Custom regex for command.
     *
     * @param string          $regex
     * @param callable|string $answer
     */
    public function regex($regex, $answer)
    {
        $this->_command['customRegex:'.$regex] = $answer;
    }

    /**
     * Enforce a daily per-user message limit in a chat or forum topic.
     *
     * @param int|string|null $chatId
     * @param int|null   $messageThreadId
     * @param int        $maxPerDay
     * @param array      $options
     */
    public function enforceMessageThreadLimit($chatId, $messageThreadId = null, $maxPerDay = 2, $options = [])
    {
        $this->_messageThreadLimits[] = [
            'chat_id' => $chatId === null ? null : (string) $chatId,
            'message_thread_id' => $messageThreadId === null ? null : (string) $messageThreadId,
            'max_per_day' => (int) $maxPerDay,
            'storage_path' => isset($options['storage_path']) ? $options['storage_path'] : sys_get_temp_dir().'/phptelebot-thread-limits.sqlite',
            'delete_message' => isset($options['delete_message']) ? (bool) $options['delete_message'] : true,
            'warning_text' => isset($options['warning_text']) ? $options['warning_text'] : 'Daily limit reached. You can send up to %d messages in this topic each day.',
            'ignored_commands' => isset($options['ignored_commands']) && is_array($options['ignored_commands']) ? $options['ignored_commands'] : [],
            'warning_cooldown' => isset($options['warning_cooldown']) ? (int) $options['warning_cooldown'] : 300,
            'mention_user' => isset($options['mention_user']) ? (bool) $options['mention_user'] : false,
            'whitelist_sender_tag' => isset($options['whitelist_sender_tag']) ? (bool) $options['whitelist_sender_tag'] : false,
            'ban_after_violations' => isset($options['ban_after_violations']) ? (int) $options['ban_after_violations'] : 0,
            'ban_text' => isset($options['ban_text']) ? $options['ban_text'] : 'User dibanned setelah mencapai %d pelanggaran.',
        ];
    }

    /**
     * Get unique warned-user totals for message thread limits.
     *
     * @param int|string $chatId
     * @param array      $messageThreadIds
     * @param string     $storagePath
     * @param string     $day
     *
     * @return array
     */
    public function messageThreadLimitWarningTotals($chatId, $messageThreadIds, $storagePath = '', $day = '')
    {
        $path = $storagePath != '' ? $storagePath : sys_get_temp_dir().'/phptelebot-thread-limits.sqlite';
        $day = $day != '' ? $day : date('Y-m-d');
        $db = $this->threadLimitDatabase($path);
        $totals = [];

        foreach ($messageThreadIds as $messageThreadId) {
            $messageThreadId = trim($messageThreadId);
            if ($messageThreadId == '') {
                continue;
            }

            if ($day == '*') {
                $stmt = $db->prepare('SELECT COUNT(DISTINCT user_id) FROM message_thread_limits WHERE chat_id = ? AND thread_id = ? AND warned = 1');
                $stmt->execute([(string) $chatId, (string) $messageThreadId]);
            } else {
                $stmt = $db->prepare('SELECT COUNT(DISTINCT user_id) FROM message_thread_limits WHERE day = ? AND chat_id = ? AND thread_id = ? AND warned = 1');
                $stmt->execute([$day, (string) $chatId, (string) $messageThreadId]);
            }
            $totals[$messageThreadId] = (int) $stmt->fetchColumn();
        }

        return $totals;
    }

    /**
     * Get violation details for message thread limits.
     *
     * @param int|string $chatId
     * @param array      $messageThreadIds
     * @param string     $storagePath
     * @param string     $day
     *
     * @return array
     */
    public function messageThreadLimitViolations($chatId, $messageThreadIds, $storagePath = '', $day = '')
    {
        $path = $storagePath != '' ? $storagePath : sys_get_temp_dir().'/phptelebot-thread-limits.sqlite';
        $day = $day != '' ? $day : date('Y-m-d');
        $db = $this->threadLimitDatabase($path);
        $violations = [];

        foreach ($messageThreadIds as $messageThreadId) {
            $messageThreadId = trim($messageThreadId);
            if ($messageThreadId == '') {
                continue;
            }

            if ($day == '*') {
                $stmt = $db->prepare('SELECT user_id, MAX(name) AS name, SUM(violation_count) AS count FROM message_thread_limits WHERE chat_id = ? AND thread_id = ? AND violation_count > 0 GROUP BY user_id ORDER BY count DESC, name ASC');
                $stmt->execute([(string) $chatId, (string) $messageThreadId]);
            } else {
                $stmt = $db->prepare('SELECT user_id, name, violation_count AS count FROM message_thread_limits WHERE day = ? AND chat_id = ? AND thread_id = ? AND violation_count > 0 ORDER BY violation_count DESC, name ASC');
                $stmt->execute([$day, (string) $chatId, (string) $messageThreadId]);
            }

            $violations[$messageThreadId] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $violations[$messageThreadId][] = [
                    'user_id' => $row['user_id'],
                    'name' => $row['name'] != '' ? $row['name'] : 'User '.$row['user_id'],
                    'count' => (int) $row['count'],
                ];
            }
        }

        return $violations;
    }

    /**
     * Track received message volume for a chat.
     *
     * @param int|string $chatId
     * @param string     $storagePath
     */
    public function trackChatMessageStats($chatId, $storagePath = '')
    {
        $this->_chatMessageStats[] = [
            'chat_id' => (string) $chatId,
            'storage_path' => $storagePath != '' ? $storagePath : sys_get_temp_dir().'/phptelebot-thread-limits.sqlite',
        ];
    }

    /**
     * Get received message volume stats for a chat.
     *
     * @param int|string $chatId
     * @param string     $storagePath
     * @param string     $day
     *
     * @return array
     */
    public function chatMessageStats($chatId, $storagePath = '', $day = '')
    {
        $path = $storagePath != '' ? $storagePath : sys_get_temp_dir().'/phptelebot-thread-limits.sqlite';
        $day = $day != '' ? $day : date('Y-m-d');
        $db = $this->threadLimitDatabase($path);

        $stmt = $db->prepare('SELECT COALESCE(SUM(count), 0) FROM group_message_stats WHERE day = ? AND chat_id = ?');
        $stmt->execute([$day, (string) $chatId]);
        $today = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM group_message_stats WHERE day = ? AND chat_id = ?');
        $stmt->execute([$day, (string) $chatId]);
        $activeHours = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COALESCE(MAX(count), 0) FROM group_message_stats WHERE day = ? AND chat_id = ?');
        $stmt->execute([$day, (string) $chatId]);
        $peakHour = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COALESCE(SUM(count), 0) FROM group_message_stats WHERE chat_id = ?');
        $stmt->execute([(string) $chatId]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(DISTINCT day) FROM group_message_stats WHERE chat_id = ?');
        $stmt->execute([(string) $chatId]);
        $days = (int) $stmt->fetchColumn();

        return [
            'today' => $today,
            'total' => $total,
            'active_hours_today' => $activeHours,
            'avg_per_active_hour_today' => $activeHours > 0 ? $today / $activeHours : 0,
            'avg_per_day' => $days > 0 ? $total / $days : 0,
            'peak_hour_today' => $peakHour,
            'stored_days' => $days,
        ];
    }

    /**
     * Run telebot.
     *
     * @return bool
     */
    public function run()
    {
        try {
            if (php_sapi_name() == 'cli') {
                echo 'PHPTelebot version '.self::$version;
                echo "\nMode\t: Long Polling\n";
                $options = getopt('q', ['quiet']);
                if (isset($options['q']) || isset($options['quiet'])) {
                    self::$debug = false;
                }
                echo "Debug\t: ".(self::$debug ? 'ON' : 'OFF')."\n";
                $this->longPoll();
            } else {
                $this->webhook();
            }

            return true;
        } catch (Exception $e) {
            echo $e->getMessage()."\n";

            return false;
        }
    }

    /**
     * Webhook Mode.
     */
    private function webhook()
    {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && strpos($contentType, 'application/json') === 0) {
            self::$getUpdates = json_decode(file_get_contents('php://input'), true);
            echo $this->process();
        } else {
            http_response_code(400);
            throw new Exception('Access not allowed!');
        }
    }

    /**
     * Long Poll Mode.
     *
     * @throws Exception
     */
    private function longPoll()
    {
        $offset = 0;
        while (true) {
            $request = array_merge(['timeout' => 30], $this->_options, ['offset' => $offset]);
            $req = json_decode(Bot::send('getUpdates', $request), true);

            // Check error.
            if (isset($req['error_code'])) {
                if ($req['error_code'] == 404) {
                    $req['description'] = 'Incorrect bot token';
                }
                throw new Exception($req['description']);
            }

            if (!empty($req['result'])) {
                foreach ($req['result'] as $update) {
                    self::$getUpdates = $update;
                    $process = $this->process();

                    if (self::$debug) {
                        $line = "\n--------------------\n";
                        $outputFormat = "$line %s $update[update_id] $line%s";
                        echo sprintf($outputFormat, 'Query ID :', json_encode($update));
                        echo sprintf($outputFormat, 'Response for :', Bot::$debug?: $process ?: '--NO RESPONSE--');
                        // reset debug
                        Bot::$debug = '';
                    }
                    $offset = $update['update_id'] + 1;
                }
            }

            // Delay 1 second
            sleep(1);
        }
    }

    /**
     * Process the message.
     *
     * @return string
     */
    private function process()
    {
        $get = self::$getUpdates;
        $message = $this->currentMessage();
        $run = false;

        if (isset($message['date']) && $message['date'] < (time() - 120)) {
            return '-- Pass --';
        }

        $this->recordChatMessageStats($message);

        $limitResponse = $this->enforceMessageThreadLimits($message);
        if ($limitResponse !== false) {
            return $limitResponse;
        }

        if (Bot::type() == 'text' && isset($message['text'])) {
            foreach ($this->_command as $cmd => $call) {
                $customRegex = false;
                if (substr($cmd, 0, 12) == 'customRegex:') {
                    $regex = substr($cmd, 12);
                    // Remove bot username from command
                     if (self::$username != '') {
                         $message['text'] = preg_replace('/^\/(.*)@'.self::$username.'(.*)/', '/$1$2', $message['text']);
                     }
                    $customRegex = true;
                } else {
                    $regex = '/^(?:'.addcslashes($cmd, '/\+*?[^]$(){}=!<>:-').')'.(self::$username ? '(?:@'.self::$username.')?' : '').'(?:\s(.*))?$/';
                }
                if ($message['text'] != '*' && preg_match($regex, $message['text'], $matches)) {
                    $run = true;
                    if ($customRegex) {
                        $param = [$matches];
                    } else {
                        $param = isset($matches[1]) ? $matches[1] : '';
                    }
                    break;
                }
            }
        }

        if (isset($this->_onMessage) && $run === false) {
            $eventTypes = $this->eventTypes(Bot::type(), Bot::updateType());
            foreach ($eventTypes as $eventType) {
                if (isset($this->_onMessage[$eventType])) {
                    $run = true;
                    $call = $this->_onMessage[$eventType];
                    break;
                }
            }

            if (!$run && isset($this->_onMessage['*'])) {
                $run = true;
                $call = $this->_onMessage['*'];
            }

            if ($run) {
                switch (Bot::type()) {
                    case 'callback':
                        $param = isset($get['callback_query']['data']) ? $get['callback_query']['data'] : '';
                    break;
                    case 'inline':
                        $param = isset($get['inline_query']['query']) ? $get['inline_query']['query'] : '';
                    break;
                    case 'location':
                        $param = [$message['location']['longitude'], $message['location']['latitude']];
                    break;
                    case 'text':
                        $param = $message['text'];
                    break;
                    default:
                        if (isset($message[Bot::type()])) {
                            $param = $message[Bot::type()];
                        } elseif (isset($get[Bot::updateType()])) {
                            $param = $get[Bot::updateType()];
                        } else {
                            $param = '';
                        }
                    break;
                }
            }
        }

        if ($run) {
            if (is_callable($call)) {
                if (!is_array($param)) {
                    $count = count((new ReflectionFunction($call))->getParameters());
                    if ($count > 1) {
                        $param = array_pad(explode(' ', $param, $count), $count, '');
                    } else {
                        $param = [$param];
                    }
                }

                return call_user_func_array($call, $param);
            } else {
                if (!isset($get['inline_query'])) {
                    return Bot::send('sendMessage', ['text' => $call]);
                }
            }
        }
    }

    /**
     * Current message-like update payload.
     *
     * @return array
     */
    private function currentMessage()
    {
        $get = self::$getUpdates;
        $fields = [
            'message', 'business_message', 'guest_message', 'edited_message',
            'channel_post', 'edited_channel_post', 'edited_business_message',
        ];

        foreach ($fields as $field) {
            if (isset($get[$field])) {
                return $get[$field];
            }
        }

        if (isset($get['callback_query']['message'])) {
            return $get['callback_query']['message'];
        }

        return [];
    }

    /**
     * Apply registered message thread limits.
     *
     * @param array $message
     *
     * @return string|bool
     */
    private function enforceMessageThreadLimits($message)
    {
        if (empty($this->_messageThreadLimits) || Bot::updateType() != 'message') {
            return false;
        }

        foreach ($this->_messageThreadLimits as $limit) {
            if (!$this->messageMatchesThreadLimit($message, $limit)) {
                continue;
            }

            return $this->applyMessageThreadLimit($message, $limit);
        }

        return false;
    }

    /**
     * Record received message volume for configured chats.
     *
     * @param array $message
     */
    private function recordChatMessageStats($message)
    {
        if (empty($this->_chatMessageStats) || Bot::updateType() != 'message' || !isset($message['chat']['id'])) {
            return;
        }

        foreach ($this->_chatMessageStats as $config) {
            if ((string) $message['chat']['id'] != $config['chat_id']) {
                continue;
            }

            $db = $this->threadLimitDatabase($config['storage_path']);
            $time = isset($message['date']) ? $message['date'] : time();
            $day = date('Y-m-d', $time);
            $hour = date('H:00', $time);
            if (isset($message['message_thread_id'])) {
                $topicName = '';
                if (isset($message['forum_topic_created']['name'])) {
                    $topicName = $message['forum_topic_created']['name'];
                } elseif (isset($message['forum_topic_edited']['name'])) {
                    $topicName = $message['forum_topic_edited']['name'];
                }
                $stmt = $db->prepare('INSERT OR IGNORE INTO chat_topics (chat_id, thread_id, name, updated_at) VALUES (?, ?, ?, ?)');
                $stmt->execute([$config['chat_id'], (string) $message['message_thread_id'], $topicName, $time]);
                if ($topicName != '') {
                    $stmt = $db->prepare('UPDATE chat_topics SET name = ?, updated_at = ? WHERE chat_id = ? AND thread_id = ?');
                    $stmt->execute([$topicName, $time, $config['chat_id'], (string) $message['message_thread_id']]);
                }
            }
            $stmt = $db->prepare('INSERT OR IGNORE INTO group_message_stats (day, hour, chat_id, count) VALUES (?, ?, ?, 0)');
            $stmt->execute([$day, $hour, $config['chat_id']]);
            $stmt = $db->prepare('UPDATE group_message_stats SET count = count + 1 WHERE day = ? AND hour = ? AND chat_id = ?');
            $stmt->execute([$day, $hour, $config['chat_id']]);

            if (isset($message['message_id'])) {
                $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : '';
                $userId = isset($message['from']['id']) ? (string) $message['from']['id'] : '';
                $name = isset($message['from']) ? $this->messageThreadLimitUserName($message['from']) : '';
                $username = isset($message['from']['username']) ? (string) $message['from']['username'] : '';
                $type = Bot::type();
                $text = isset($message['text']) ? $message['text'] : (isset($message['caption']) ? $message['caption'] : '['.$type.']');
                $fileId = '';
                if ($type == 'sticker' && isset($message['sticker']['file_id'])) {
                    $fileId = $message['sticker']['file_id'];
                } elseif ($type == 'photo' && isset($message['photo']) && is_array($message['photo'])) {
                    $photo = end($message['photo']);
                    $fileId = isset($photo['file_id']) ? $photo['file_id'] : '';
                } elseif (isset($message[$type]['file_id'])) {
                    $fileId = $message[$type]['file_id'];
                }
                $mediaType = $fileId != '' ? $type : '';
                $stmt = $db->prepare('INSERT OR IGNORE INTO chats (message_id, chat_id, thread_id, user_id, name, username, text, media_type, file_id, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([(string) $message['message_id'], $config['chat_id'], $threadId, $userId, $name, $username, $text, $mediaType, $fileId, $time]);
            }
        }
    }

    /**
     * @param array $message
     * @param array $limit
     *
     * @return bool
     */
    private function messageMatchesThreadLimit($message, $limit)
    {
        if (!isset($message['chat']['id'])) {
            return false;
        }

        if ($limit['chat_id'] !== null && (string) $message['chat']['id'] != $limit['chat_id']) {
            return false;
        }

        if ($limit['message_thread_id'] === null) {
            return true;
        }

        return isset($message['message_thread_id']) && (string) $message['message_thread_id'] == $limit['message_thread_id'];
    }

    /**
     * @param array $message
     * @param array $limit
     *
     * @return string
     */
    private function applyMessageThreadLimit($message, $limit)
    {
        if (!isset($message['from']['id']) || !isset($message['message_id'])) {
            return false;
        }

        if ($limit['whitelist_sender_tag'] && $this->messageHasSenderTag($message)) {
            return false;
        }

        if ($this->isIgnoredMessageThreadLimitCommand($message, $limit)) {
            return false;
        }

        $path = $limit['storage_path'];
        $db = $this->threadLimitDatabase($path);
        $day = date('Y-m-d', isset($message['date']) ? $message['date'] : time());
        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : 'main';
        $chatId = (string) $message['chat']['id'];
        $userId = (string) $message['from']['id'];
        $userName = $this->messageThreadLimitUserName($message['from']);

        $stmt = $db->prepare('SELECT count, last_warning FROM message_thread_limits WHERE day = ? AND chat_id = ? AND thread_id = ? AND user_id = ?');
        $stmt->execute([$day, $chatId, $threadId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $stmt = $db->prepare('INSERT INTO message_thread_limits (day, chat_id, thread_id, user_id, name) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$day, $chatId, $threadId, $userId, $userName]);
            $row = ['count' => 0, 'last_warning' => 0];
        }

        $count = (int) $row['count'];
        if ($count >= $limit['max_per_day']) {
            $lastWarnedAt = (int) $row['last_warning'];
            $sendWarning = $limit['warning_cooldown'] <= 0 || $lastWarnedAt < (time() - $limit['warning_cooldown']);
            $stmt = $db->prepare('UPDATE message_thread_limits SET name = ?, warned = 1, violation_count = violation_count + 1, last_warning = ? WHERE day = ? AND chat_id = ? AND thread_id = ? AND user_id = ?');
            $stmt->execute([$userName, $sendWarning ? time() : $lastWarnedAt, $day, $chatId, $threadId, $userId]);

            if ($limit['ban_after_violations'] > 0) {
                $stmt = $db->prepare('SELECT COALESCE(SUM(violation_count), 0) FROM message_thread_limits WHERE chat_id = ? AND user_id = ?');
                $stmt->execute([$chatId, $userId]);
                $totalViolations = (int) $stmt->fetchColumn();

                if ($totalViolations >= $limit['ban_after_violations']) {
                    $banResult = json_decode(Bot::banChatMember([
                        'chat_id' => $message['chat']['id'],
                        'user_id' => $message['from']['id'],
                    ]), true);

                    if (isset($banResult['ok']) && $banResult['ok']) {
                        $options = [
                            'chat_id' => $message['chat']['id'],
                            'text' => sprintf($limit['ban_text'], $limit['ban_after_violations']),
                        ];
                        if ($limit['mention_user']) {
                            $options['text'] = $this->messageThreadLimitUserMention($message['from']).' '.$options['text'];
                            $options['parse_mode'] = 'html';
                        }
                        if (isset($message['message_thread_id'])) {
                            $options['message_thread_id'] = $message['message_thread_id'];
                        }
                        Bot::sendMessage($options);

                        if ($limit['delete_message']) {
                            Bot::deleteMessage([
                                'chat_id' => $message['chat']['id'],
                                'message_id' => $message['message_id'],
                            ]);
                        }

                        return '-- Banned --';
                    }
                }
            }

            if (!$sendWarning) {
                if ($limit['delete_message']) {
                    Bot::deleteMessage([
                        'chat_id' => $message['chat']['id'],
                        'message_id' => $message['message_id'],
                    ]);
                }

                return '-- Limited --';
            }

            $text = sprintf($limit['warning_text'], $limit['max_per_day']);
            $options = [
                'chat_id' => $message['chat']['id'],
                'text' => $text,
                'reply_parameters' => ['message_id' => $message['message_id']],
                'allow_sending_without_reply' => true,
            ];
            if ($limit['mention_user']) {
                $options['text'] = $this->messageThreadLimitUserMention($message['from']).' '.$options['text'];
                $options['parse_mode'] = 'html';
            }
            if (isset($message['message_thread_id'])) {
                $options['message_thread_id'] = $message['message_thread_id'];
            }

            $warning = Bot::sendMessage($options);

            if ($limit['delete_message']) {
                Bot::deleteMessage([
                    'chat_id' => $message['chat']['id'],
                    'message_id' => $message['message_id'],
                ]);
            }

            return $warning;
        }

        $stmt = $db->prepare('UPDATE message_thread_limits SET name = ?, count = count + 1 WHERE day = ? AND chat_id = ? AND thread_id = ? AND user_id = ?');
        $stmt->execute([$userName, $day, $chatId, $threadId, $userId]);

        return false;
    }

    /**
     * @param array $message
     *
     * @return bool
     */
    private function messageHasSenderTag($message)
    {
        if (isset($message['sender_tag']) && $message['sender_tag'] !== '') {
            return true;
        }

        return isset($message['from']['sender_tag']) && $message['from']['sender_tag'] !== '';
    }

    /**
     * @param array $user
     *
     * @return string
     */
    private function messageThreadLimitUserMention($user)
    {
        $name = $this->messageThreadLimitUserName($user);
        return '<a href="tg://user?id='.$user['id'].'">'.$this->escapeHtml($name).'</a>';
    }

    /**
     * @param array $user
     *
     * @return string
     */
    private function messageThreadLimitUserName($user)
    {
        $name = isset($user['first_name']) ? $user['first_name'] : 'User';
        if (isset($user['last_name']) && $user['last_name'] != '') {
            $name .= ' '.$user['last_name'];
        }

        return $name;
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private function escapeHtml($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array $message
     * @param array $limit
     *
     * @return bool
     */
    private function isIgnoredMessageThreadLimitCommand($message, $limit)
    {
        if (empty($limit['ignored_commands']) || !isset($message['text'])) {
            return false;
        }

        foreach ($limit['ignored_commands'] as $command) {
            $command = trim($command);
            if ($command == '') {
                continue;
            }

            if ($message['text'] == $command || strpos($message['text'], $command.' ') === 0 || strpos($message['text'], $command.'@') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $path
     *
     * @return PDO
     */
    private function threadLimitDatabase($path)
    {
        if (!class_exists('PDO')) {
            throw new Exception('PDO is required for SQLite message limit storage.');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers())) {
            throw new Exception('PDO SQLite driver is required for message limit storage.');
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $db = new PDO('sqlite:'.$path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE IF NOT EXISTS message_thread_limits (
            day TEXT NOT NULL,
            chat_id TEXT NOT NULL,
            thread_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT "",
            count INTEGER NOT NULL DEFAULT 0,
            warned INTEGER NOT NULL DEFAULT 0,
            violation_count INTEGER NOT NULL DEFAULT 0,
            last_warning INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day, chat_id, thread_id, user_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_message_thread_limits_topic ON message_thread_limits (chat_id, thread_id)');
        $db->exec('CREATE TABLE IF NOT EXISTS group_message_stats (
            day TEXT NOT NULL,
            hour TEXT NOT NULL,
            chat_id TEXT NOT NULL,
            count INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day, hour, chat_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_group_message_stats_chat ON group_message_stats (chat_id, day)');
        $db->exec('CREATE TABLE IF NOT EXISTS chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id TEXT NOT NULL,
            chat_id TEXT NOT NULL,
            thread_id TEXT NOT NULL DEFAULT "",
            user_id TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT "",
            username TEXT NOT NULL DEFAULT "",
            text TEXT NOT NULL DEFAULT "",
            media_type TEXT NOT NULL DEFAULT "",
            file_id TEXT NOT NULL DEFAULT "",
            date INTEGER NOT NULL
        )');
        $this->ensureChatMediaColumns($db);
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_chats_msg_chat ON chats (chat_id, message_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_chats_date ON chats (date)');
        $db->exec('CREATE TABLE IF NOT EXISTS chat_topics (
            chat_id TEXT NOT NULL,
            thread_id TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT "",
            updated_at INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (chat_id, thread_id)
        )');
        $this->migrateThreadLimitJsonStorage($db, $path);

        return $db;
    }

    /**
     * @param PDO $db
     */
    private function ensureChatMediaColumns($db)
    {
        $columns = [];
        $result = $db->query('PRAGMA table_info(chats)');
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row['name']] = true;
        }
        if (!isset($columns['media_type'])) {
            $db->exec('ALTER TABLE chats ADD COLUMN media_type TEXT NOT NULL DEFAULT ""');
        }
        if (!isset($columns['file_id'])) {
            $db->exec('ALTER TABLE chats ADD COLUMN file_id TEXT NOT NULL DEFAULT ""');
        }
    }

    /**
     * @param PDO    $db
     * @param string $path
     */
    private function migrateThreadLimitJsonStorage($db, $path)
    {
        $count = (int) $db->query('SELECT COUNT(*) FROM message_thread_limits')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $jsonPath = preg_replace('/\.sqlite$/', '.json', $path);
        if ($jsonPath == $path || !is_file($jsonPath)) {
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            return;
        }

        $rows = [];
        foreach ($data as $day => $dayData) {
            if (!is_array($dayData)) {
                continue;
            }

            foreach ($dayData as $key => $value) {
                if (substr($key, 0, 2) == '__') {
                    continue;
                }
                $parts = explode(':', $key, 3);
                if (count($parts) != 3) {
                    continue;
                }
                $rowKey = $day.':'.$key;
                $rows[$rowKey] = [
                    'day' => $day,
                    'chat_id' => $parts[0],
                    'thread_id' => $parts[1],
                    'user_id' => $parts[2],
                    'name' => '',
                    'count' => (int) $value,
                    'warned' => 0,
                    'violation_count' => 0,
                    'last_warning' => 0,
                ];
            }

            if (isset($dayData['__warnings']) && is_array($dayData['__warnings'])) {
                foreach ($dayData['__warnings'] as $topicKey => $users) {
                    $topic = explode(':', $topicKey, 2);
                    if (count($topic) != 2 || !is_array($users)) {
                        continue;
                    }
                    foreach ($users as $userId => $warned) {
                        $rowKey = $day.':'.$topicKey.':'.$userId;
                        $this->ensureMigratedThreadLimitRow($rows, $rowKey, $day, $topic[0], $topic[1], $userId);
                        $rows[$rowKey]['warned'] = 1;
                    }
                }
            }

            if (isset($dayData['__last_warning']) && is_array($dayData['__last_warning'])) {
                foreach ($dayData['__last_warning'] as $key => $lastWarning) {
                    $parts = explode(':', $key, 3);
                    if (count($parts) != 3) {
                        continue;
                    }
                    $rowKey = $day.':'.$key;
                    $this->ensureMigratedThreadLimitRow($rows, $rowKey, $day, $parts[0], $parts[1], $parts[2]);
                    $rows[$rowKey]['last_warning'] = (int) $lastWarning;
                }
            }

            if (isset($dayData['__violations']) && is_array($dayData['__violations'])) {
                foreach ($dayData['__violations'] as $topicKey => $users) {
                    $topic = explode(':', $topicKey, 2);
                    if (count($topic) != 2 || !is_array($users)) {
                        continue;
                    }
                    foreach ($users as $userId => $violation) {
                        $rowKey = $day.':'.$topicKey.':'.$userId;
                        $this->ensureMigratedThreadLimitRow($rows, $rowKey, $day, $topic[0], $topic[1], $userId);
                        if (is_array($violation)) {
                            $rows[$rowKey]['name'] = isset($violation['name']) ? $violation['name'] : '';
                            $rows[$rowKey]['violation_count'] = isset($violation['count']) ? (int) $violation['count'] : 0;
                        }
                    }
                }
            }
        }

        $stmt = $db->prepare('INSERT OR REPLACE INTO message_thread_limits (day, chat_id, thread_id, user_id, name, count, warned, violation_count, last_warning) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $stmt->execute([
                $row['day'],
                $row['chat_id'],
                $row['thread_id'],
                $row['user_id'],
                $row['name'],
                $row['count'],
                $row['warned'],
                $row['violation_count'],
                $row['last_warning'],
            ]);
        }
    }

    /**
     * @param array  $rows
     * @param string $rowKey
     * @param string $day
     * @param string $chatId
     * @param string $threadId
     * @param string $userId
     */
    private function ensureMigratedThreadLimitRow(&$rows, $rowKey, $day, $chatId, $threadId, $userId)
    {
        if (isset($rows[$rowKey])) {
            return;
        }

        $rows[$rowKey] = [
            'day' => $day,
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'user_id' => $userId,
            'name' => '',
            'count' => 0,
            'warned' => 0,
            'violation_count' => 0,
            'last_warning' => 0,
        ];
    }

    /**
     * Candidate event names for handler matching.
     *
     * @param string $type
     * @param string $updateType
     *
     * @return array
     */
    private function eventTypes($type, $updateType)
    {
        $types = [$type, $updateType];
        $aliases = [
            'inline' => ['inline_query'],
            'inline_query' => ['inline'],
            'callback' => ['callback_query'],
            'callback_query' => ['callback'],
            'edited' => ['edited_message'],
            'edited_message' => ['edited'],
            'channel' => ['channel_post'],
            'channel_post' => ['channel'],
            'edited_channel' => ['edited_channel_post'],
            'edited_channel_post' => ['edited_channel'],
            'new_chat_members' => ['new_chat_member'],
            'new_chat_member' => ['new_chat_members'],
        ];

        foreach ($types as $candidate) {
            if (isset($aliases[$candidate])) {
                $types = array_merge($types, $aliases[$candidate]);
            }
        }

        return array_values(array_unique(array_filter($types, function ($value) {
            return $value != '' && $value != 'unknown';
        })));
    }
}

require_once __DIR__.'/Bot.php';
