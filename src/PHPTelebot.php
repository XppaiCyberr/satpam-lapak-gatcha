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
            'storage_path' => isset($options['storage_path']) ? $options['storage_path'] : sys_get_temp_dir().'/phptelebot-thread-limits.json',
            'delete_message' => isset($options['delete_message']) ? (bool) $options['delete_message'] : true,
            'warning_text' => isset($options['warning_text']) ? $options['warning_text'] : 'Daily limit reached. You can send up to %d messages in this topic each day.',
            'ignored_commands' => isset($options['ignored_commands']) && is_array($options['ignored_commands']) ? $options['ignored_commands'] : [],
            'warning_cooldown' => isset($options['warning_cooldown']) ? (int) $options['warning_cooldown'] : 300,
            'mention_user' => isset($options['mention_user']) ? (bool) $options['mention_user'] : false,
            'whitelist_sender_tag' => isset($options['whitelist_sender_tag']) ? (bool) $options['whitelist_sender_tag'] : false,
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
        $path = $storagePath != '' ? $storagePath : sys_get_temp_dir().'/phptelebot-thread-limits.json';
        $day = $day != '' ? $day : date('Y-m-d');
        $data = $this->readThreadLimitData($path);
        $totals = [];

        foreach ($messageThreadIds as $messageThreadId) {
            $messageThreadId = trim($messageThreadId);
            if ($messageThreadId == '') {
                continue;
            }

            $topicKey = $chatId.':'.$messageThreadId;
            $totals[$messageThreadId] = 0;
            if (isset($data[$day]['__warnings'][$topicKey]) && is_array($data[$day]['__warnings'][$topicKey])) {
                $totals[$messageThreadId] = count($data[$day]['__warnings'][$topicKey]);
            }
        }

        return $totals;
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
        $data = $this->readThreadLimitData($path);
        $day = date('Y-m-d', isset($message['date']) ? $message['date'] : time());
        $threadId = isset($message['message_thread_id']) ? (string) $message['message_thread_id'] : 'main';
        $key = $message['chat']['id'].':'.$threadId.':'.$message['from']['id'];
        $topicKey = $message['chat']['id'].':'.$threadId;

        if (!isset($data[$day])) {
            $data = [$day => []];
        } else {
            foreach (array_keys($data) as $storedDay) {
                if ($storedDay != $day) {
                    unset($data[$storedDay]);
                }
            }
        }

        $count = isset($data[$day][$key]) ? (int) $data[$day][$key] : 0;
        if ($count >= $limit['max_per_day']) {
            if (!isset($data[$day]['__warnings']) || !is_array($data[$day]['__warnings'])) {
                $data[$day]['__warnings'] = [];
            }
            if (!isset($data[$day]['__warnings'][$topicKey]) || !is_array($data[$day]['__warnings'][$topicKey])) {
                $data[$day]['__warnings'][$topicKey] = [];
            }
            $data[$day]['__warnings'][$topicKey][(string) $message['from']['id']] = true;
            $lastWarnedAt = isset($data[$day]['__last_warning'][$key]) ? (int) $data[$day]['__last_warning'][$key] : 0;
            $sendWarning = $limit['warning_cooldown'] <= 0 || $lastWarnedAt < (time() - $limit['warning_cooldown']);
            if ($sendWarning) {
                if (!isset($data[$day]['__last_warning']) || !is_array($data[$day]['__last_warning'])) {
                    $data[$day]['__last_warning'] = [];
                }
                $data[$day]['__last_warning'][$key] = time();
            }
            $this->writeThreadLimitData($path, $data);

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

        $data[$day][$key] = $count + 1;
        $this->writeThreadLimitData($path, $data);

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
        $name = isset($user['first_name']) ? $user['first_name'] : 'User';
        if (isset($user['last_name']) && $user['last_name'] != '') {
            $name .= ' '.$user['last_name'];
        }

        return '<a href="tg://user?id='.$user['id'].'">'.$this->escapeHtml($name).'</a>';
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
     * @return array
     */
    private function readThreadLimitData($path)
    {
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param string $path
     * @param array  $data
     */
    private function writeThreadLimitData($path, $data)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($data), LOCK_EX);
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
