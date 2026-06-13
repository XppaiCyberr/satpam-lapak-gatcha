<?php
/**
 * Bot.php.
 *
 *
 * @author Radya <radya@gmx.com>
 *
 * @link https://github.com/radyakaze/phptelebot
 *
 * @license GPL-3.0
 */

/**
 * Class Bot.
 */
class Bot
{
    /**
     * Bot response debug.
     * 
     * @var string
     */
    public static $debug = '';

    /**
     * Send request to telegram api server.
     *
     * @param string $action
     * @param array  $data   [optional]
     *
     * @return array|bool
     */
    public static function send($action = 'sendMessage', $data = [])
    {
        $upload = false;
        if (!is_array($data)) {
            $data = [];
        }
        $action = self::normalizeAction($action);

        if (self::needsChatId($action) && !self::hasChatIdAlternative($action, $data) && !isset($data['chat_id']) && !isset($data['inline_message_id'])) {
            $message = self::currentMessage();
            if (isset($message['chat']['id'])) {
                $data['chat_id'] = $message['chat']['id'];
            }

            if (!isset($data['message_thread_id']) && self::supportsMessageThread($action) && isset($message['message_thread_id'])) {
                $data['message_thread_id'] = $message['message_thread_id'];
            }

            if (!isset($data['direct_messages_topic_id']) && self::supportsDirectMessagesTopic($action) && isset($message['direct_messages_topic']['topic_id'])) {
                $data['direct_messages_topic_id'] = $message['direct_messages_topic']['topic_id'];
            }
        }

        if (!isset($data['business_connection_id']) && self::supportsBusinessConnection($action)) {
            $message = self::currentMessage();
            if (isset($message['business_connection_id'])) {
                $data['business_connection_id'] = $message['business_connection_id'];
            }
        }

        if (isset($data['reply']) && $data['reply'] === true) {
            $message = self::currentMessage();
            if (!isset($data['reply_parameters']) && !isset($data['reply_to_message_id']) && isset($message['message_id'])) {
                $data['reply_parameters'] = ['message_id' => $message['message_id']];
            }
        }
        unset($data['reply']);

        $data = self::prepareRequestData($data, $upload);

        $ch = curl_init();
        $options = [
            CURLOPT_URL => 'https://api.telegram.org/bot'.PHPTelebot::$token.'/'.$action,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false
        ];

        if (is_array($data)) {
            $options[CURLOPT_POSTFIELDS] = $data;
        }

        if ($upload !== false) {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: multipart/form-data'];
        }

        curl_setopt_array($ch, $options);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            echo curl_error($ch)."\n";
        }
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (PHPTelebot::$debug && $action != 'getUpdates') {
            self::$debug .= 'Method: '.$action."\n";
            self::$debug .= 'Data: '.str_replace("Array\n", '', print_r($data, true))."\n";
            self::$debug .= 'Response: '.$result."\n";
        }

        if ($httpcode == 401) {
            throw new Exception('Incorect bot token');

            return false;
        } else {
            return $result;
        }
    }

    /**
     * Answer Inline.
     *
     * @param array $results
     * @param array $options
     *
     * @return string
     */
    public static function answerInlineQuery($results, $options = [])
    {
        $data = $options;

        if (!isset($data['inline_query_id'])) {
            $get = PHPTelebot::$getUpdates;
            $data['inline_query_id'] = $get['inline_query']['id'];
        }

        $data['results'] = $results;

        return self::send('answerInlineQuery', $data);
    }

    /**
     * Answer Callback.
     *
     * @param string $text
     * @param array  $options [optional]
     *
     * @return string
     */
    public static function answerCallbackQuery($text, $options = [])
    {
        $options['text'] = $text;

        if (!isset($options['callback_query_id'])) {
            $get = PHPTelebot::$getUpdates;
            $options['callback_query_id'] = $get['callback_query']['id'];
        }

        return self::send('answerCallbackQuery', $options);
    }

    /**
     * Answer a guest query.
     *
     * @param array $result
     * @param array $options [optional]
     *
     * @return string
     */
    public static function answerGuestQuery($result, $options = [])
    {
        $data = $options;

        if (!isset($data['guest_query_id'])) {
            $message = self::currentMessage();
            if (isset($message['guest_query_id'])) {
                $data['guest_query_id'] = $message['guest_query_id'];
            }
        }

        $data['result'] = $result;

        return self::send('answerGuestQuery', $data);
    }

    /**
     * Create curl file.
     *
     * @param string $path
     *
     * @return string
     */
    private static function curlFile($path)
    {
        // PHP 5.5 introduced a CurlFile object that deprecates the old @filename syntax
        // See: https://wiki.php.net/rfc/curl-file-upload
        if (function_exists('curl_file_create')) {
            return curl_file_create($path);
        } else {
            // Use the old style if using an older version of PHP
            return "@$path";
        }
    }

    /**
     * Prepare request parameters for Telegram.
     *
     * @param array $data
     * @param bool  $upload
     *
     * @return array
     */
    private static function prepareRequestData($data, &$upload)
    {
        $fileFields = [
            'animation', 'audio', 'certificate', 'cover', 'document', 'live_photo',
            'photo', 'sticker', 'thumbnail', 'video', 'video_note', 'voice',
            'png_sticker', 'tgs_sticker', 'webm_sticker',
        ];

        foreach ($fileFields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && is_file($data[$field])) {
                $upload = true;
                $data[$field] = self::curlFile($data[$field]);
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_encode($value);
            }
        }

        return $data;
    }

    /**
     * @param string $action
     *
     * @return string
     */
    private static function normalizeAction($action)
    {
        $aliases = [
            'getChatMembersCount' => 'getChatMemberCount',
            'kickChatMember' => 'banChatMember',
        ];

        return isset($aliases[$action]) ? $aliases[$action] : $action;
    }

    /**
     * @param string $action
     *
     * @return bool
     */
    private static function needsChatId($action)
    {
        return in_array($action, [
            'sendMessage', 'sendRichMessage', 'forwardMessage', 'forwardMessages', 'copyMessage', 'copyMessages',
            'sendPhoto', 'sendAudio', 'sendDocument', 'sendVideo', 'sendAnimation',
            'sendVoice', 'sendVideoNote', 'sendPaidMedia', 'sendMediaGroup', 'sendLocation',
            'sendVenue', 'sendContact', 'sendPoll', 'sendDice', 'sendChatAction',
            'sendSticker', 'sendGame', 'sendInvoice', 'sendLivePhoto', 'sendChecklist',
            'sendMessageDraft', 'editMessageText', 'editMessageCaption', 'editMessageMedia',
            'editMessageReplyMarkup', 'editMessageLiveLocation', 'editMessageChecklist',
            'stopMessageLiveLocation', 'stopPoll', 'deleteMessage', 'deleteMessages',
            'deleteAllMessageReactions', 'deleteMessageReaction', 'setMessageReaction',
            'pinChatMessage', 'unpinChatMessage', 'unpinAllChatMessages', 'getChat',
            'leaveChat', 'getChatAdministrators', 'getChatMemberCount', 'getChatMembersCount',
            'getChatMember', 'setChatPhoto', 'deleteChatPhoto', 'setChatTitle',
            'setChatDescription', 'banChatMember', 'kickChatMember', 'unbanChatMember',
            'restrictChatMember', 'promoteChatMember', 'setChatAdministratorCustomTitle',
            'setChatMemberTag', 'banChatSenderChat', 'unbanChatSenderChat',
            'setChatPermissions', 'exportChatInviteLink', 'createChatInviteLink',
            'editChatInviteLink', 'createChatSubscriptionInviteLink',
            'editChatSubscriptionInviteLink', 'revokeChatInviteLink',
            'approveChatJoinRequest', 'declineChatJoinRequest', 'setChatStickerSet',
            'deleteChatStickerSet', 'createForumTopic', 'editForumTopic',
            'closeForumTopic', 'reopenForumTopic', 'deleteForumTopic',
            'unpinAllForumTopicMessages', 'editGeneralForumTopic', 'closeGeneralForumTopic',
            'reopenGeneralForumTopic', 'hideGeneralForumTopic', 'unhideGeneralForumTopic',
            'unpinAllGeneralForumTopicMessages', 'getUserChatBoosts',
            'approveSuggestedPost', 'declineSuggestedPost', 'readBusinessMessage',
            'getChatGifts', 'sendGift',
        ]);
    }

    /**
     * @param string $action
     * @param array  $data
     *
     * @return bool
     */
    private static function hasChatIdAlternative($action, $data)
    {
        return $action == 'sendGift' && isset($data['user_id']);
    }

    /**
     * @param string $action
     *
     * @return bool
     */
    private static function supportsMessageThread($action)
    {
        return in_array($action, [
            'sendMessage', 'sendRichMessage', 'sendPhoto', 'sendVideo', 'sendAnimation', 'sendAudio',
            'sendDocument', 'sendPaidMedia', 'sendSticker', 'sendVideoNote', 'sendVoice',
            'sendLocation', 'sendVenue', 'sendContact', 'sendPoll', 'sendDice',
            'sendInvoice', 'sendGame', 'sendMediaGroup', 'sendChatAction', 'copyMessage',
            'copyMessages', 'forwardMessage', 'forwardMessages', 'sendLivePhoto',
            'sendChecklist', 'sendMessageDraft',
        ]);
    }

    /**
     * @param string $action
     *
     * @return bool
     */
    private static function supportsDirectMessagesTopic($action)
    {
        return in_array($action, [
            'sendMessage', 'sendRichMessage', 'sendPhoto', 'sendVideo', 'sendAnimation', 'sendAudio',
            'sendDocument', 'sendPaidMedia', 'sendSticker', 'sendVideoNote', 'sendVoice',
            'sendLocation', 'sendVenue', 'sendContact', 'sendPoll', 'sendDice',
            'sendInvoice', 'sendGame', 'sendMediaGroup', 'sendChatAction', 'copyMessage',
            'copyMessages', 'forwardMessage', 'forwardMessages', 'sendLivePhoto',
            'sendChecklist', 'sendMessageDraft',
        ]);
    }

    /**
     * @param string $action
     *
     * @return bool
     */
    private static function supportsBusinessConnection($action)
    {
        return in_array($action, [
            'sendMessage', 'sendRichMessage', 'sendPhoto', 'sendVideo', 'sendAnimation', 'sendAudio',
            'sendDocument', 'sendPaidMedia', 'sendSticker', 'sendVideoNote', 'sendVoice',
            'sendLocation', 'sendVenue', 'sendContact', 'sendPoll', 'sendDice',
            'sendGame', 'sendMediaGroup', 'sendChatAction', 'sendLivePhoto',
            'sendChecklist', 'editMessageText', 'editMessageCaption', 'editMessageMedia',
            'editMessageReplyMarkup', 'editMessageLiveLocation', 'editMessageChecklist',
            'stopMessageLiveLocation', 'stopPoll', 'pinChatMessage', 'unpinChatMessage',
            'readBusinessMessage', 'deleteBusinessMessages', 'setBusinessAccountName',
            'setBusinessAccountUsername', 'setBusinessAccountBio',
            'setBusinessAccountProfilePhoto', 'removeBusinessAccountProfilePhoto',
            'setBusinessAccountGiftSettings', 'getBusinessAccountStarBalance',
            'transferBusinessAccountStars', 'getBusinessAccountGifts',
            'convertGiftToStars', 'upgradeGift', 'transferGift', 'postStory',
            'repostStory', 'editStory', 'deleteStory',
        ]);
    }

    /**
     * Get message properties.
     *
     * @return array
     */
    public static function message()
    {
        $get = PHPTelebot::$getUpdates;
        $fields = [
            'message', 'business_message', 'guest_message', 'callback_query', 'inline_query',
            'edited_message', 'channel_post', 'edited_channel_post', 'edited_business_message',
            'deleted_business_messages', 'message_reaction', 'message_reaction_count',
            'chosen_inline_result', 'shipping_query', 'pre_checkout_query',
            'purchased_paid_media', 'poll', 'poll_answer', 'my_chat_member',
            'chat_member', 'chat_join_request', 'chat_boost', 'removed_chat_boost',
            'managed_bot', 'business_connection',
        ];

        foreach ($fields as $field) {
            if (isset($get[$field])) {
                return $get[$field];
            }
        }

        return [];
    }

    /**
     * Update type.
     *
     * @return string
     */
    public static function updateType()
    {
        $get = PHPTelebot::$getUpdates;
        $fields = [
            'message', 'edited_message', 'channel_post', 'edited_channel_post',
            'business_connection', 'business_message', 'edited_business_message',
            'deleted_business_messages', 'guest_message', 'message_reaction',
            'message_reaction_count', 'inline_query', 'chosen_inline_result',
            'callback_query', 'shipping_query', 'pre_checkout_query',
            'purchased_paid_media', 'poll', 'poll_answer', 'my_chat_member',
            'chat_member', 'chat_join_request', 'chat_boost', 'removed_chat_boost',
            'managed_bot',
        ];

        foreach ($fields as $field) {
            if (isset($get[$field])) {
                return $field;
            }
        }

        return 'unknown';
    }

    /**
     * Current message payload.
     *
     * @return array
     */
    private static function currentMessage()
    {
        $get = PHPTelebot::$getUpdates;
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
     * Mesage type.
     *
     * @return string
     */
    public static function type()
    {
        $updateType = self::updateType();

        if ($updateType == 'message' || $updateType == 'business_message' || $updateType == 'guest_message') {
            return self::messageType(self::currentMessage());
        } elseif ($updateType == 'inline_query') {
            return 'inline';
        } elseif ($updateType == 'callback_query') {
            return 'callback';
        } elseif ($updateType == 'edited_message') {
            return 'edited';
        } elseif ($updateType == 'channel_post') {
            return 'channel';
        } elseif ($updateType == 'edited_channel_post') {
            return 'edited_channel';
        }

        return $updateType;
    }

    /**
     * Message content type.
     *
     * @param array $message
     *
     * @return string
     */
    private static function messageType($message)
    {
        $fields = [
            'text', 'animation', 'audio', 'document', 'live_photo', 'paid_media',
            'photo', 'sticker', 'story', 'video', 'video_note', 'voice', 'checklist',
            'contact', 'dice', 'game', 'poll', 'venue', 'location', 'new_chat_members',
            'new_chat_member', 'left_chat_member', 'chat_owner_left', 'chat_owner_changed',
            'new_chat_title', 'new_chat_photo', 'delete_chat_photo', 'group_chat_created',
            'supergroup_chat_created', 'channel_chat_created',
            'message_auto_delete_timer_changed', 'migrate_to_chat_id', 'migrate_from_chat_id',
            'pinned_message', 'invoice', 'successful_payment', 'refunded_payment',
            'users_shared', 'user_shared', 'chat_shared', 'gift', 'unique_gift',
            'gift_upgrade_sent', 'connected_website', 'write_access_allowed',
            'passport_data', 'proximity_alert_triggered', 'boost_added',
            'chat_background_set', 'checklist_tasks_done', 'checklist_tasks_added',
            'direct_message_price_changed', 'forum_topic_created', 'forum_topic_edited',
            'forum_topic_closed', 'forum_topic_reopened', 'general_forum_topic_hidden',
            'general_forum_topic_unhidden', 'giveaway_created', 'giveaway',
            'giveaway_winners', 'giveaway_completed', 'managed_bot_created',
            'paid_message_price_changed', 'poll_option_added', 'poll_option_deleted',
            'suggested_post_approved', 'suggested_post_approval_failed',
            'suggested_post_declined', 'suggested_post_paid', 'suggested_post_refunded',
            'video_chat_scheduled', 'video_chat_started', 'video_chat_ended',
            'video_chat_participants_invited', 'web_app_data',
        ];

        foreach ($fields as $field) {
            if (isset($message[$field])) {
                return $field;
            }
        }

        return 'unknown';
    }

    /**
     * @param array $array
     *
     * @return bool
     */
    private static function isAssoc($array)
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Create an action.
     *
     * @param string $name
     * @param array  $args
     *
     * @return array
     */
    public static function __callStatic($action, $args)
    {
        $param = [];
        $firstParam = [
            'sendMessage' => 'text',
            'sendPhoto' => 'photo',
            'sendAnimation' => 'animation',
            'sendVideo' => 'video',
            'sendAudio' => 'audio',
            'sendVoice' => 'voice',
            'sendVideoNote' => 'video_note',
            'sendDocument' => 'document',
            'sendSticker' => 'sticker',
            'sendLivePhoto' => 'live_photo',
            'sendPaidMedia' => 'media',
            'sendMediaGroup' => 'media',
            'sendChecklist' => 'checklist',
            'sendPoll' => 'question',
            'sendDice' => 'emoji',
            'sendChatAction' => 'action',
            'sendMessageDraft' => 'text',
            'setWebhook' => 'url',
            'sendGift' => 'gift_id',
            'giftPremiumSubscription' => 'user_id',
            'getUserProfilePhotos' => 'user_id',
            'getUserProfileAudios' => 'user_id',
            'getFile' => 'file_id',
            'getChat' => 'chat_id',
            'leaveChat' => 'chat_id',
            'getChatAdministrators' => 'chat_id',
            'getChatMemberCount' => 'chat_id',
            'getChatMembersCount' => 'chat_id',
            'getBusinessConnection' => 'business_connection_id',
            'getCustomEmojiStickers' => 'custom_emoji_ids',
            'getStickerSet' => 'name',
            'deleteStickerFromSet' => 'sticker',
            'deleteStickerSet' => 'name',
            'setStickerEmojiList' => 'sticker',
            'setStickerKeywords' => 'sticker',
            'setStickerMaskPosition' => 'sticker',
            'setStickerPositionInSet' => 'sticker',
            'setStickerSetTitle' => 'name',
            'setCustomEmojiStickerSetThumbnail' => 'name',
            'setStickerSetThumbnail' => 'name',
            'uploadStickerFile' => 'user_id',
            'deleteMessages' => 'message_ids',
            'sendGame' => 'game_short_name',
            'getGameHighScores' => 'user_id',
            'getManagedBotToken' => 'user_id',
            'replaceManagedBotToken' => 'user_id',
            'getManagedBotAccessSettings' => 'user_id',
            'setManagedBotAccessSettings' => 'user_id',
            'readBusinessMessage' => 'message_id',
            'deleteBusinessMessages' => 'message_ids',
            'setBusinessAccountName' => 'first_name',
            'setBusinessAccountUsername' => 'username',
            'setBusinessAccountBio' => 'bio',
            'setBusinessAccountProfilePhoto' => 'photo',
            'transferBusinessAccountStars' => 'star_count',
            'getUserGifts' => 'user_id',
            'getChatGifts' => 'chat_id',
            'convertGiftToStars' => 'owned_gift_id',
            'upgradeGift' => 'owned_gift_id',
            'transferGift' => 'owned_gift_id',
            'postStory' => 'content',
            'editStory' => 'story_id',
            'deleteStory' => 'story_id',
            'refundStarPayment' => 'user_id',
            'editUserStarSubscription' => 'user_id',
        ];

        if (isset($args[0]) && is_array($args[0]) && (!isset($firstParam[$action]) || self::isAssoc($args[0]))) {
            $param = $args[0];
        } elseif (isset($firstParam[$action]) && isset($args[0])) {
            $param[$firstParam[$action]] = $args[0];
            if (isset($args[1]) && is_array($args[1])) {
                $param = array_merge($param, $args[1]);
            }
        }

        return call_user_func_array('self::send', [$action, $param]);
    }
}
