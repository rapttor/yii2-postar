# rapttor/yii2-postar

[Symfony Mailer](https://symfony.com/doc/current/mailer.html) transport for **Postar**, the Rapttor transactional mail API ([guide](https://postar.vip/llm.txt), `POST https://api.postar.vip/v1/send`),
for apps using `yiisoft/yii2-symfonymailer`. One DSN picks the transport: Mailpit in dev, Postar in production, no code changes. Plain curl, no SDK.

## Install

```bash
composer require rapttor/yii2-postar
```

Private for now: add it as a `path` or `vcs` repository (see `rapttor.md` section 5).

## Configure

```php
// common/config/main.php
'mailer' => [
    'class' => \yii\symfonymailer\Mailer::class,
    'transport' => \rapttor\postar\Mail::transport(getenv('MAIL_DSN') ?: 'smtp://mailpit:1025'),
],
```

| `MAIL_DSN` | Result |
|---|---|
| `smtp://mailpit:1025` (or any Symfony DSN) | passed to Symfony Mailer unchanged |
| `postar://pk_xxx@api.postar.vip` | Postar over https |
| `postar://pk_xxx@api.postar.vip?reply_to=office@app.com` | default Reply-To (`postar.vip` addresses cannot receive replies) |
| `postar+http://pk_xxx@localhost:8080` | plain http, localhost only |

The `from` domain must be allowed for the key: `@postar.vip` works with no DNS setup, your own domain needs the SPF/DKIM records shown in the Postar backoffice.

## Component (direct sends and unsubscribes)

```php
// config/web.php and config/console.php
'components' => ['postar' => ['class' => \rapttor\postar\Postar::class]],
```

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

### Postar as the default `Yii::$app->mailer`

```php
'components' => ['postar' => ['class' => \rapttor\postar\Postar::class, 'defaultMailer' => true]],
```

The package bootstrap then registers `mailer` (`yii\symfonymailer\Mailer`) with the Postar transport and `POSTAR_FROM` / `POSTAR_FROM_NAME` as default sender. `compose()`, views and layouts work unchanged. It **replaces** any existing `mailer` definition, including `useFileTransport`, so enable it per environment (e.g. only in production config).

### Mail queue and cron (huge volumes)

```php
// production config, console AND web
'components' => ['postar' => [
    'class' => \rapttor\postar\Postar::class,
    'defaultMailer' => true,   // Yii::$app->mailer now goes through Postar
    'queue' => true,           // ... and is stored in `rapttor_emailqueue` first, sent by cron
]],
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
Without `defaultMailer`, queue directly: `Yii::$app->postar->queue()->enqueue(['to' => ..., 'from' => ..., 'subject' => ..., 'html' => ...])`.

### Local environments (Mailpit)

By default Postar does **not** take over the mailer when `YII_ENV` is `dev` or `test`, so local apps keep sending to Mailpit (or the file transport) even if the production config is shared. Change it where needed:

| Setting | Effect |
|---|---|
| `'enabled' => true` or `POSTAR_ENABLED=1` | use Postar in every environment, e.g. to test real delivery |
| `'enabled' => false` or `POSTAR_ENABLED=0` | never take over the mailer |
| `'localEnvs' => ['dev']` | which `YII_ENV` values count as local (`[]` = none) |

The config value beats `POSTAR_ENABLED`. Use a test API key and `to: dev@localhost` (captured by Postar, never delivered) for smoke tests. A `queue => true` app in a local env that is enabled needs the cron too, or run `php yii postar/run` by hand.

## Behaviour

- **One API call per recipient** (`to`, `cc`, `bcc` each separately).
- **Unsubscribe is Postar's job**: every mail gets Postar's footer link (names this project only) and `List-Unsubscribe` headers, whatever the app sends. The plugin sends no unsubscribe field; mind that your own link and Postar's will both appear.
- **Suppressed recipients are not errors**: HTTP 422 for a bounced, complained or unsubscribed address is logged and skipped; `PostarTransport::$suppressed` lists them and `$onSuppressed` is called per address. Never retry.
- **Other API errors** become `Symfony\Component\Mailer\Exception\TransportException` with Postar's message and HTTP status.
- **Attachments throw** (the API has none); they are never dropped silently.
- `to: dev@localhost` is captured by Postar and never sent: handy for a smoke test with a real key.

## Tests

```bash
composer install && vendor/bin/phpunit
```

The tests run the transport against a stubbed HTTP function (no network, no key).

## License

MIT
