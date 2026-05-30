# Satpam Lapak Gatcha

Telegram moderation bot for the Lapak Member forum topics. The bot watches only the configured topic IDs and enforces a simple posting rule: each user may send at most 2 messages per day in each monitored topic.

This project is based on PHPTelebot, with a small moderation layer added for Lapak Member.

## Features

- Monitors the Lapak Member chat `-1001197136417`.
- Monitors only these Lapak Member topic/thread IDs: `3282669` and `4226256`.
- Counts messages per Telegram user per calendar day.
- Allows the first 2 messages from each user in each monitored topic.
- Deletes the 3rd and later messages from the same user in the same topic on the same day.
- Sends the warning as a reply to the violating user's message and mentions the user.
- Throttles repeated warnings for the same user/topic to avoid warning spam during bursts.

```text
Limit Lapak Member: setiap user maksimal 2 pesan per hari.
```

- Stores daily counters locally in `runtime/lapak-member-limits.json`.
- Tracks the number of unique users who received warnings in each monitored topic.
- Provides `/satpam` to report today's warning totals per topic.
- Ignores the runtime counter file through `.gitignore`.

## What Changed

- Renamed the executable bot entrypoint from `sample.php` to `index.php`.
- Added `PHPTelebot::enforceMessageThreadLimit()` in `src/PHPTelebot.php`.
- Added `PHPTelebot::messageThreadLimitWarningTotals()` for report commands.
- Wired the Lapak Member topic rules in `index.php`.
- Added local JSON storage for per-day topic message counts.
- Removed the old sample commands from `index.php`.
- Added `/satpam` to show warning totals per monitored topic.
- Updated the default moderation target:
  - Chat ID: `-1001197136417`
  - Message thread/topic IDs: `3282669`, `4226256`
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

The Lapak Member IDs are already the defaults. You only need these fields if the chat or topic list changes:

```ini
lapak_member_chat_id=-1001197136417
lapak_member_thread_ids=3282669,4226256
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
foreach ($lapakMemberThreadIds as $lapakMemberThreadId) {
    $bot->enforceMessageThreadLimit($lapakMemberChatId, $lapakMemberThreadId, 2, [
        'storage_path' => __DIR__.'/runtime/lapak-member-limits.json',
        'warning_text' => 'Limit Lapak Member: setiap user maksimal %d pesan per hari.',
        'ignored_commands' => ['/satpam'],
        'warning_cooldown' => 300,
        'mention_user' => true,
    ]);
}
```

For each new `message` update, the bot checks:

- The message is from chat `-1001197136417`.
- The message has `message_thread_id` `3282669` or `4226256`.
- The sender has not exceeded 2 messages for that topic on the current day.

If the user is still within the limit, the message is counted and normal bot handling continues. If the user is over the limit, the bot records that user as warned for the topic, deletes the message, and sends the warning text as a reply in the same topic. The warning mentions the violating user.

Repeated excess messages from the same user in the same topic are still deleted, but the warning text is only sent once per cooldown window. The default cooldown is 300 seconds.

The counter resets automatically when the calendar day changes.

## Useful Commands

`index.php` only includes the Satpam command:

- `/satpam` shows today's unique warned-user totals for Lapak Digital and Lapak Fisik.

Example response:

```text
Satpam Lapak hari ini
Lapak Digital: 1 user kena warning
Lapak Fisik: 0 user kena warning
```

The `/satpam` command is ignored by the limiter, so checking status does not consume one of a user's allowed topic messages.

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
- If messages are not counted, confirm Telegram is sending forum topic messages with `message_thread_id=3282669` or `message_thread_id=4226256`.
