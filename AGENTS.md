# Repository Guidelines

## Project Structure & Module Organization

This is a small Composer package for a Telegram bot framework.

- `src/PHPTelebot.php` contains the main `PHPTelebot` class, command/event registration, update handling, and bot runtime flow.
- `src/Bot.php` contains static helpers for Telegram Bot API requests and message actions.
- `index.php` is an executable usage example for local/manual testing.
- `README.md` is the primary user documentation.
- `composer.json` defines package metadata, PHP requirements, and file autoloading.

There is currently no committed `tests/` directory or asset pipeline.

## Build, Test, and Development Commands

- `composer install` installs package dependencies and creates `vendor/autoload.php`.
- `composer validate` checks `composer.json` for structural issues.
- `php -l src/PHPTelebot.php` and `php -l src/Bot.php` run PHP syntax checks.
- `php index.php` runs the example bot script after replacing placeholder token values with a valid Telegram bot token.

Avoid committing local token-bearing scripts; `.gitignore` already excludes `test.php`.

## Coding Style & Naming Conventions

Keep the code compatible with PHP 5.4 as declared in `composer.json`; avoid modern syntax that requires newer PHP versions. Follow the existing style: global classes, four-space indentation, brace-on-next-line class declarations, concise PHPDoc blocks, and array short syntax. Method names use camelCase, for example `sendMessage()` and `answerInlineQuery()`. Keep Telegram API method wrapper names aligned with the upstream method names when adding helpers.

## Testing Guidelines

No automated test framework is currently configured. For now, validate changes with `composer validate`, PHP lint checks, and focused manual testing through `index.php` or a temporary ignored script. If adding tests, prefer a `tests/` directory and document the runner command in `README.md` and this file.

## Commit & Pull Request Guidelines

Recent history uses short, imperative commit subjects such as `Fix typo` and `Update Bot.php`. Keep commits focused and describe the behavior changed, not just the files touched.

Pull requests should include a concise summary, manual verification steps, and any relevant Telegram Bot API behavior or backward-compatibility notes. Link issues when applicable. Include screenshots or copied bot responses only when they clarify user-visible behavior.

## Security & Configuration Tips

Never commit bot tokens, webhook secrets, chat IDs from private conversations, or debug logs containing Telegram payloads. Keep local editor settings out of the repository; `.vscode` is ignored.
