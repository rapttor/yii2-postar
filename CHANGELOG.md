# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

### Changed
- **`POSTAR_API_KEY` alone switches Postar on**: the Bootstrap registers the `postar` component and `Yii::$app->mailer` without any config. `defaultMailer` and `queue` are `null` (auto; `POSTAR_QUEUE=1`), `false` opts out.

### Added
- Attachments and embedded images: policy `POSTAR_ATTACHMENTS` / `Postar::$attachments`: `send` (posts an `attachments` array, needs service support, see docs/ATTACHMENTS.md), `skip-inline` (default: `cid:` images dropped with a warning, real attachments throw), `skip`, `throw`. Queue column `attachments` (migration `m261117_000001_add_emailqueue_attachments`, run `php yii migrate`).

### Removed
- **DSN support**: `Mail::transport()`, `Mail::fromDsn()` and the `postar://` / `postar+http://` DSN. Postar is configured only with the API settings of https://postar.vip/llm.txt, never with `MAIL_DSN`. Replace `Mail::transport(...)` in the mailer config by nothing: delete the `transport` line (and the `mailer` block) and set `POSTAR_API_KEY`.

## [0.1.0] - 2026-11-07

### Added
- `PostarTransport` for the live Postar API (`POST /v1/send`, `pk_` keys): one call per recipient, suppressed recipients (HTTP 422) skipped and logged.
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

[Unreleased]: https://github.com/rapttor/yii2-postar/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/rapttor/yii2-postar/releases/tag/v0.1.0
