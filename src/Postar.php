<?php

declare(strict_types=1);

namespace rapttor\postar;

use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Yii2 component for the Postar mail API (https://postar.vip/llm.txt).
 *
 *   'components' => ['postar' => ['class' => \rapttor\postar\Postar::class]]
 *
 * Settings not given in the config are read from the environment: POSTAR_BASE_URL, POSTAR_API_KEY,
 * POSTAR_FROM (domain must be allowed for the key), POSTAR_FROM_NAME, POSTAR_REPLY_TO.
 *
 *   Yii::$app->postar->send('user@example.com', 'Your login link', '<p>...</p>', ['text' => '...']);
 *   Yii::$app->postar->unsubscribe('user@example.com');          // user opted out inside the app
 *   Yii::$app->postar->isUnsubscribed('user@example.com');
 *   Yii::$app->postar->resubscribe('user@example.com');          // user re-enabled email in the app
 *
 * Failures throw {@see PostarApiException} (code = HTTP status, 0 = network). Treat them as non-fatal where possible.
 */
class Postar extends Component
{
    public string $baseUrl = '';
    public string $apiKey = '';
    public string $from = '';
    public ?string $fromName = null;
    public ?string $replyTo = null;
    public int $timeout = 10;

    /** true = `Yii::$app->mailer` sends through Postar (see {@see Bootstrap}) */
    public bool $defaultMailer = false;

    /**
     * null (auto) = Postar takes over `Yii::$app->mailer` unless YII_ENV is one of {@see self::$localEnvs}, so local apps keep
     * using Mailpit (or the file transport). true = always (also in dev/test, e.g. to test real delivery), false = never.
     * When not set in the config, `POSTAR_ENABLED=1|0` in the environment decides.
     */
    public ?bool $enabled = null;

    /** @var list<string> YII_ENV values where auto mode leaves the mailer alone; set `[]` to use Postar everywhere */
    public array $localEnvs = ['dev', 'test'];

    /** true = the mailer stores mails in the queue table; `php yii postar/run` (cron) sends them */
    public bool $queue = false;

    public string $queueTable = 'rapttor_emailqueue';

    /** id of the database component holding the queue table */
    public string $db = 'db';

    public int $maxAttempts = 5;

    /** @var (callable(string,array<string,string>,string,string): array{0:int,1:string})|null replaces curl, for tests */
    public $http = null;

    private ?SendApi $api = null;
    private ?EmailQueue $emailQueue = null;

    /** Whether Postar may replace the mailer here: see {@see self::$enabled}. */
    public function isActive(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }
        $env = self::env('POSTAR_ENABLED');
        if ($env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return !in_array(YII_ENV, $this->localEnvs, true);
    }

    public function init(): void
    {
        parent::init();
        $this->baseUrl = $this->baseUrl ?: (self::env('POSTAR_BASE_URL') ?: 'https://api.postar.vip');
        $this->apiKey = $this->apiKey ?: self::env('POSTAR_API_KEY');
        $this->from = $this->from ?: self::env('POSTAR_FROM');
        $this->fromName = $this->fromName ?? (self::env('POSTAR_FROM_NAME') ?: null);
        $this->replyTo = $this->replyTo ?? (self::env('POSTAR_REPLY_TO') ?: null);
    }

    /**
     * Queues one email.
     *
     * @param array<string,mixed> $options overrides: from, name, reply_to, text
     * @return array{id:string, status:string}
     * @throws PostarApiException a blocked recipient has {@see PostarApiException::isSuppressed()} and a `resubscribeUrl()`; never retry it
     */
    public function send(string $to, string $subject, string $html, array $options = []): array
    {
        $payload = array_filter([
            'from' => $this->fromAddress(),
            'name' => $this->fromDisplayName(),
            'reply_to' => $this->replyTo,
            'to' => $to,
            'subject' => $subject,
            'html' => $html !== '' ? $html : null,
        ], static fn ($v) => $v !== null);

        return $this->api()->send(array_merge($payload, $options));
    }

    /**
     * Sender address: `from` (POSTAR_FROM), else `<app name>@postar.vip` built from `Yii::$app->name`
     * (works without DNS; replies to it bounce, so set `replyTo`).
     */
    public function fromAddress(): string
    {
        if ($this->from !== '') {
            return $this->from;
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) \Yii::$app->name))), '-');

        return ($slug !== '' ? $slug : 'app') . '@postar.vip';
    }

    /** Sender display name: `fromName` (POSTAR_FROM_NAME), else `Yii::$app->name`. */
    public function fromDisplayName(): ?string
    {
        return $this->fromName ?? (\Yii::$app->name ?: null);
    }

    /** The user opted out inside the app. @return bool true = newly blocked, false = was already blocked */
    public function unsubscribe(string $email): bool
    {
        return $this->api()->unsubscribe($email);
    }

    /** @return array{email:string, unsubscribed:bool, source:?string, since:?string} */
    public function unsubscribeStatus(string $email): array
    {
        return $this->api()->unsubscribeStatus($email);
    }

    public function isUnsubscribed(string $email): bool
    {
        return $this->api()->unsubscribeStatus($email)['unsubscribed'];
    }

    /**
     * The user re-enabled email in the app (call only from an authenticated action).
     * A block the recipient made with the email link throws with code 409: send them to
     * {@see PostarApiException::resubscribeUrl()}, only they can finish it.
     */
    public function resubscribe(string $email): void
    {
        $this->api()->resubscribe($email);
    }

    /** The low level client, e.g. for {@see PostarTransport}. */
    public function api(): SendApi
    {
        if ($this->apiKey === '' || $this->baseUrl === '') {
            throw new InvalidConfigException('Postar needs baseUrl and apiKey (POSTAR_BASE_URL, POSTAR_API_KEY).');
        }

        return $this->api ??= new SendApi($this->apiKey, $this->baseUrl, $this->timeout, $this->http);
    }

    /** The mail queue (table `queueTable`, worker `postar/run`). */
    public function queue(): EmailQueue
    {
        return $this->emailQueue ??= new EmailQueue(\Yii::$app->get($this->db), $this->api(), $this->queueTable, $this->maxAttempts, [
            'from' => $this->fromAddress(), 'name' => $this->fromDisplayName(), 'reply_to' => $this->replyTo,
        ]);
    }

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return $value === false ? '' : (string) $value;
    }
}
