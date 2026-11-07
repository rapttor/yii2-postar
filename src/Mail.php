<?php

declare(strict_types=1);

namespace rapttor\postar;

use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * One DSN decides the mail transport, so dev and prod differ only in `MAIL_DSN`:
 *
 *   smtp://mailpit:1025                                      Mailpit (dev), any Symfony DSN works
 *   postar://pk_xxx@api.postar.vip                           Postar over https
 *   postar://pk_xxx@api.postar.vip?reply_to=office@app.com   default Reply-To (postar.vip cannot receive replies)
 *   postar+http://pk_xxx@localhost:8080                      plain http, only for localhost
 *
 * In `common/config/main.php`:  'transport' => \rapttor\postar\Mail::transport($env('MAIL_DSN')),
 */
final class Mail
{
    /** @return array{dsn:string}|TransportInterface config value for yii\symfonymailer\Mailer::$transport */
    public static function transport(string $dsn): array|TransportInterface
    {
        return self::isPostar($dsn) ? self::fromDsn($dsn) : ['dsn' => $dsn];
    }

    public static function isPostar(string $dsn): bool
    {
        return str_starts_with($dsn, 'postar://') || str_starts_with($dsn, 'postar+http://');
    }

    /** @param (callable(string,array<string,string>,string): array{0:int,1:string})|null $http for tests */
    public static function fromDsn(string $dsn, ?callable $http = null): PostarTransport
    {
        $p = parse_url($dsn);
        if ($p === false || !isset($p['host'], $p['scheme'])) {
            throw new \InvalidArgumentException('Invalid Postar DSN, expected postar://<api key>@<host>.');
        }
        $key = rawurldecode($p['user'] ?? '');
        if ($key === '') {
            throw new \InvalidArgumentException('The Postar DSN has no API key: postar://pk_...@api.postar.vip.');
        }
        parse_str($p['query'] ?? '', $query);
        $http_ = $p['scheme'] === 'postar+http';
        if ($http_ && !in_array($p['host'], ['localhost', '127.0.0.1'], true)) {
            throw new \InvalidArgumentException('postar+http:// is only allowed for localhost.');
        }
        $base = ($http_ ? 'http' : 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

        return new PostarTransport(
            new SendApi($key, $base, 10, $http),
            isset($query['reply_to']) && $query['reply_to'] !== '' ? (string) $query['reply_to'] : null,
        );
    }
}
