# Instructions for AI coding agents: rapttor/yii2-postar

Yii2 client for the Postar mail API (https://postar.vip/llm.txt): component `Yii::$app->postar`, Symfony Mailer transport, mail queue `rapttor_emailqueue` with cron worker. Read README.md for config.

## Integrating in a host app

1. `composer require rapttor/yii2-postar`; register the `postar` component in web **and** console config (key and sender from `POSTAR_API_KEY`, `POSTAR_FROM`, `POSTAR_FROM_NAME`, `POSTAR_REPLY_TO`).
2. Production only: `'defaultMailer' => true` (and `'queue' => true`). It replaces `Yii::$app->mailer`. Local apps use Mailpit: the package does not take over the mailer when `YII_ENV` is `dev` or `test` unless `enabled => true` / `POSTAR_ENABLED=1`.
3. With the queue: `php yii migrate`, then cron `php yii postar/run` every minute (`php yii postar/cron --install`) and `php yii postar/purge` daily.

## Rules

- Postar adds the unsubscribe link and `List-Unsubscribe` headers itself. Never send an unsubscribe field, never build a second opt-out system. Sync in-app opt-outs with `unsubscribe()` / `resubscribe()`.
- A blocked recipient (`PostarApiException::isSuppressed()`, 422) is final: never retry it. 429 pauses, 5xx retries with backoff.
- `@postar.vip` addresses cannot receive replies: always set `replyTo`.
- No attachments (the API has none): they throw, never drop silently.
- Never write to `rapttor_emailqueue` with raw SQL; use `queue()->enqueue()`.

## Changing this package

PHP >= 8.2, `declare(strict_types=1)`, PSR-4 `rapttor\postar\`. Every behaviour change needs a test (no network: inject `http`). Run `composer install && vendor/bin/phpunit`.
