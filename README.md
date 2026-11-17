# rapttor/yii2-postar

Yii2 client for **Postar**, the Rapttor transactional mail API ([guide](https://postar.vip/llm.txt), `POST https://api.postar.vip/v1/send`). It talks to the API directly (plain curl, no SDK) and plugs into `Yii::$app->mailer`.

## Install and configure

```bash
composer require rapttor/yii2-postar
```

Put the settings from the Postar guide in the app's `.env`; that is all:

```
POSTAR_API_KEY=pk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
POSTAR_FROM=myapp@postar.vip
POSTAR_FROM_NAME="My App"
POSTAR_REPLY_TO=office@myapp.com
```

**When `POSTAR_API_KEY` is present, Postar is used**: the package registers the `postar` component and `Yii::$app->mailer` sends through the Postar API. No `MAIL_DSN`, no component or mailer config. Without the key nothing changes (the app keeps its own mailer, e.g. Mailpit locally). There is no DSN support for Postar: it is configured only with the API settings above.

The `from` domain must be allowed for the key: `@postar.vip` works with no DNS setup, your own domain needs the SPF/DKIM records shown in the Postar backoffice.

## Component (direct sends and unsubscribes)

The component is registered automatically; declare it only to override settings (`'components' => ['postar' => ['class' => \rapttor\postar\Postar::class, 'timeout' => 5]]`).

Sender (all optional): `from` / `POSTAR_FROM`, `fromName` / `POSTAR_FROM_NAME`, `replyTo` / `POSTAR_REPLY_TO`. Without them the sender is `<app name>@postar.vip` (slug of `Yii::$app->name`, works without DNS) with the app name as display name. `@postar.vip` cannot receive replies, so set `replyTo`. Every mail can override them: `send(..., ['from' => ..., 'name' => ..., 'reply_to' => ...])`, `enqueue([... 'from', 'name', 'reply_to'])`, or the From / Reply-To of the mailer message. Other settings come from the environment unless set in the config: `POSTAR_BASE_URL` (default `https://api.postar.vip`), `POSTAR_API_KEY`, `POSTAR_FROM`, `POSTAR_FROM_NAME`, `POSTAR_REPLY_TO`.

```php
try {
    Yii::$app->postar->send($user->email, 'Your login link', $html, ['text' => $plain]);
} catch (\rapttor\postar\PostarApiException $e) {
    if ($e->isSuppressed()) { $url = $e->resubscribeUrl(); /* show it to the user, never retry */ }
    Yii::error('Postar: ' . $e->getMessage() . ' (HTTP ' . $e->getCode() . ')');
}

Yii::$app->postar->unsubscribe($email);     // user opted out inside the app (true = new, false = already blocked)
Yii::$app->postar->isUnsubscribed($email);  // GET /v1/unsubscribes
Yii::$app->postar->resubscribe($email);     // authenticated action only; 409 = recipient used the link, send them to resubscribeUrl()
```

Postar adds its own unsubscribe link and `List-Unsubscribe` headers to every mail, even when the app adds one too; there is no need to build another.

### `Yii::$app->mailer`

With `POSTAR_API_KEY` set, the package registers `mailer` (`yii\symfonymailer\Mailer`) with the Postar transport and `POSTAR_FROM` / `POSTAR_FROM_NAME` as default sender. `compose()`, views and layouts work unchanged. It **replaces** any existing `mailer` definition, including `useFileTransport`. `'defaultMailer' => false` opts out and keeps your own mailer; Postar is then used only through `Yii::$app->postar`.

### Mail queue and cron (huge volumes)

```
POSTAR_QUEUE=1             # in .env; or 'queue' => true on the component
```

```bash
php yii migrate              # creates rapttor_emailqueue (the package registers its migration namespace)
php yii postar/cron          # prints the crontab line; add --install to put it into the crontab
```

Cron runs `php yii postar/run` every minute: up to 500 mails or 50 seconds per run, one worker at a time (lock file in `@runtime`).

| Command | |
|---|---|
| `postar/run [limit] [seconds]` | send due mails |
| `postar/stats` | rows per status (`pending`, `sending`, `sent`, `suppressed`, `failed`) |
| `postar/retry-failed` | queue failed mails again after fixing the cause |
| `postar/purge [days]` | delete finished rows older than N days (default 30); run it daily |

Retries: a timeout, network error or 5xx is retried with growing delays (up to `maxAttempts`, default 5, then `failed`); **429** (hourly key limit) pauses the run for 10 minutes without using an attempt; a blocked recipient becomes `suppressed` and is never retried; other 4xx fail at once with `last_error`. The table is shared by all Rapttor apps on the database; its name is `queueTable`, the connection `db`.
Without the mailer takeover, queue directly: `Yii::$app->postar->queue()->enqueue(['to' => ..., 'from' => ..., 'subject' => ..., 'html' => ...])`.

### Local environments (Mailpit)

Leave `POSTAR_API_KEY` out of the local `.env` and the app keeps Mailpit. Even with a key present, Postar does **not** take over the mailer when `YII_ENV` is `dev` or `test`, so a shared config or `.env` cannot send local test mail through the real API. Change it where needed:

| Setting | Effect |
|---|---|
| `'enabled' => true` or `POSTAR_ENABLED=1` | use Postar in every environment, e.g. to test real delivery |
| `'enabled' => false` or `POSTAR_ENABLED=0` | never take over the mailer |
| `'localEnvs' => ['dev']` | which `YII_ENV` values count as local (`[]` = none) |

The config value beats `POSTAR_ENABLED`. Use a test API key and `to: dev@localhost` (captured by Postar, never delivered) for smoke tests. A `queue => true` app in a local env that is enabled needs the cron too, or run `php yii postar/run` by hand.

## Attachments and inline images

The send API documented at https://postar.vip/llm.txt has no attachment field yet (`docs/ATTACHMENTS.md` is the request for the service). Policy via `POSTAR_ATTACHMENTS` (or `'attachments' => ...` on the component):

| value | |
|---|---|
| `send` | posts `attachments` (`filename`, `content_type`, `content` base64, `inline`, `cid`): use when the service accepts it |
| `skip-inline` (default) | `cid:` images are dropped with a logged warning and the mail goes out; real attachments (PDF) throw |
| `skip` | everything dropped with a warning |
| `throw` | any attachment or embedded image throws |

Until the service supports it, link images by absolute URL (keep an `alt` text: some clients block remote images).

## Behaviour

- **One API call per recipient** (`to`, `cc`, `bcc` each separately).
- **Unsubscribe is Postar's job**: every mail gets Postar's footer link (names this project only) and `List-Unsubscribe` headers, whatever the app sends. The plugin sends no unsubscribe field; mind that your own link and Postar's will both appear.
- **Suppressed recipients are not errors**: HTTP 422 for a bounced, complained or unsubscribed address is logged and skipped; `PostarTransport::$suppressed` lists them and `$onSuppressed` is called per address. Never retry.
- **Other API errors** become `Symfony\Component\Mailer\Exception\TransportException` with Postar's message and HTTP status.
- **Attachments** follow the policy above; real files are never dropped silently by default.
- `to: dev@localhost` is captured by Postar and never sent: handy for a smoke test with a real key.

## Tests

```bash
composer install && vendor/bin/phpunit
```

The tests run the transport against a stubbed HTTP function (no network, no key).

## License

MIT
