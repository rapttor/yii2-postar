# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

### Added
- `PostarTransport` for the live Postar API (`POST /v1/send`, `pk_` keys): one call per recipient, transactional by default, suppressed recipients (HTTP 422) skipped and logged.
- `Mail::transport()` / `Mail::fromDsn()`: DSN `postar://pk_...@api.postar.vip[?reply_to=]`, `postar+http://` for localhost.
- `SendApi` (curl client) and `PostarApiException`; 9 PHPUnit tests with a stubbed HTTP function.

- `Postar` Yii2 component (env based config, `send()`, `unsubscribe()`, `isUnsubscribed()`, `unsubscribeStatus()`, `resubscribe()`).
- `SendApi` covers `/v1/unsubscribes`; `PostarApiException::errorCode()` / `resubscribeUrl()`; suppression is detected by `code` (`recipient_unsubscribed`, `recipient_suppressed`).
- Tests bootstrap Yii; `composer.json` uses asset-packagist so `composer install` resolves.

- `Bootstrap` + `Postar::$defaultMailer`: registers Postar as `Yii::$app->mailer` (opt-in, replaces an existing `mailer`).
- `EmailQueue` + `QueueTransport` + migration `rapttor_emailqueue`; `Postar::$queue`; console command `postar/run|stats|retry-failed|purge|cron` (cron line, `--install`). 429 pauses the batch, 5xx/network retried with backoff, suppressed never retried.
- `Postar::$enabled` / `$localEnvs` / `POSTAR_ENABLED`: no takeover of the mailer in `dev`/`test` by default (Mailpit stays), configurable.
- Sender defaults: `from`, `name`, `reply_to` optional in `send()` and `enqueue()`; fall back to config/env, then to `<app name>@postar.vip` and the app name.
- No `unsubscribe` field and no category (`X-Postar-Category`, `?category=`, `$category`, queue column): Postar adds the unsubscribe link to every mail itself.

### Removed
- Draft support for the old `/v1/messages` API and the `postar/postar-php` SDK dependency.
