# Satpam Lapak Gatcha

Telegram moderation bot for the Lapak Member forum topic. The bot watches one configured group topic and enforces a simple posting rule: each user may send at most 2 messages per day in that topic.

This project is based on PHPTelebot, with a small moderation layer added for Lapak Member.

## Features

- Monitors the Lapak Member chat `-1001197136417`.
- Monitors the Lapak Member topic/thread `3282669`.
- Counts messages per Telegram user per calendar day.
- Allows the first 2 messages from each user.
- Deletes the 3rd and later messages from the same user on the same day.
- Sends a warning in the same topic when a user reaches the limit:

```text
Limit Lapak Member: setiap user maksimal 2 pesan per hari.
```

- Stores daily counters locally in `runtime/lapak-member-limits.json`.
- Ignores the runtime counter file through `.gitignore`.
- Keeps the existing PHPTelebot command/event examples in `index.php` for manual testing.

## What Changed

- Renamed the executable bot entrypoint from `sample.php` to `index.php`.
- Added `PHPTelebot::enforceMessageThreadLimit()` in `src/PHPTelebot.php`.
- Wired the Lapak Member rule in `index.php`.
- Added local JSON storage for per-day topic message counts.
- Updated the default moderation target:
  - Chat ID: `-1001197136417`
  - Message thread/topic ID: `3282669`
- Updated documentation and ignored generated runtime state.

## Requirements

- PHP 5.4 or newer
- PHP cURL extension
- Telegram bot token from [@BotFather](https://telegram.me/BotFather)
- Bot must be added to the target group
- Bot needs permission to delete messages in the group/topic

## Configuration

Create a local `x.c` file in the project root. This file is ignored by git and should not be committed.

```ini
token=123456:ABCDEF
username=YourBotUsername
```

The Lapak Member IDs are already the defaults. You only need these fields if the chat or topic changes:

```ini
lapak_member_chat_id=-1001197136417
lapak_member_thread_id=3282669
```

Plain two-line credential format is also supported:

```text
123456:ABCDEF
YourBotUsername
```

## Running

Install dependencies if needed:

```shell
composer install
```

Run the bot with long polling:

```shell
php index.php
```

Run quietly:

```shell
php index.php --quiet
```

## How Enforcement Works

The rule is registered before `$bot->run()`:

```php
$bot->enforceMessageThreadLimit($lapakMemberChatId, $lapakMemberThreadId, 2, [
    'storage_path' => __DIR__.'/runtime/lapak-member-limits.json',
    'warning_text' => 'Limit Lapak Member: setiap user maksimal %d pesan per hari.',
]);
```

For each new `message` update, the bot checks:

- The message is from chat `-1001197136417`.
- The message has `message_thread_id` `3282669`.
- The sender has not exceeded 2 messages for the current day.

If the user is still within the limit, the message is counted and normal bot handling continues. If the user is over the limit, the bot deletes the message and sends the warning text to the same topic.

The counter resets automatically when the calendar day changes.

## Useful Commands

`index.php` still includes example commands inherited from PHPTelebot:

- `/status` shows the current update and event type.
- `/whoami` replies with the sender name and Telegram user ID.
- `/echo <text>` or `/say <text>` echoes text.
- `/split one two three` demonstrates argument splitting.
- `/upload`, `/keyboard`, `/poll`, and `/draft` demonstrate Telegram API helper calls.

These commands are useful for testing bot permissions and update delivery.

## Validation

Run PHP syntax checks:

```shell
php -l src/PHPTelebot.php
php -l src/Bot.php
php -l index.php
```

Validate Composer metadata when Composer is available:

```shell
composer validate
```

## Notes

- Do not commit `x.c`, bot tokens, private chat IDs, or runtime logs.
- `runtime/` is local state and is ignored by git.
- If deletes fail, check that the bot is an admin with message deletion permission.
- If messages are not counted, confirm Telegram is sending forum topic messages with `message_thread_id=3282669`.
