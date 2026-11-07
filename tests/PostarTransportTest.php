<?php

declare(strict_types=1);

namespace rapttor\postar\tests;

use PHPUnit\Framework\TestCase;
use rapttor\postar\Mail;
use rapttor\postar\PostarTransport;
use rapttor\postar\SendApi;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

final class PostarTransportTest extends TestCase
{
    /** @var list<array{url:string, headers:array<string,string>, body:array<string,mixed>}> */
    private array $calls = [];

    /** @param callable(array<string,mixed>): array{0:int,1:array<string,mixed>} $reply */
    private function mailer(callable $reply, ?string $replyTo = null): Mailer
    {
        $http = function (string $url, array $headers, string $body) use ($reply): array {
            $b = json_decode($body, true);
            $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $b];
            [$status, $json] = $reply($b);

            return [$status, json_encode($json)];
        };

        return new Mailer(new PostarTransport(new SendApi('pk_test', 'https://api.postar.vip', 10, $http), $replyTo));
    }

    private function email(): Email
    {
        return (new Email())->from('Lex <login@postar.vip>')->to('amra@firma.ba')->subject('Hi')->html('<p>Hello</p>')->text('Hello');
    }

    private function queued(): callable
    {
        return fn () => [202, ['id' => 'abc', 'status' => 'queued']];
    }

    public function testMessageIsMappedToTheSendPayload(): void
    {
        $this->mailer($this->queued())->send($this->email()->addReplyTo('help@lex.org'));

        $this->assertCount(1, $this->calls);
        $this->assertSame('https://api.postar.vip/v1/send', $this->calls[0]['url']);
        $this->assertSame('Bearer pk_test', $this->calls[0]['headers']['Authorization']);
        $b = $this->calls[0]['body'];
        $this->assertSame('login@postar.vip', $b['from']);
        $this->assertSame('Lex', $b['name']);
        $this->assertSame('amra@firma.ba', $b['to']);
        $this->assertSame('Hi', $b['subject']);
        $this->assertSame('<p>Hello</p>', $b['html']);
        $this->assertSame('Hello', $b['text']);
        $this->assertSame('help@lex.org', $b['reply_to']);
        $this->assertArrayNotHasKey('unsubscribe', $b, 'Postar adds the unsubscribe link itself');
    }

    public function testDefaultReplyTo(): void
    {
        $this->mailer($this->queued(), 'office@app.com')->send($this->email());
        $this->assertSame('office@app.com', $this->calls[0]['body']['reply_to']);
    }

    public function testEveryRecipientGetsItsOwnCall(): void
    {
        $this->mailer($this->queued())->send($this->email()->cc('b@x.org')->bcc('c@x.org'));

        $this->assertSame(['amra@firma.ba', 'b@x.org', 'c@x.org'], array_map(fn ($c) => $c['body']['to'], $this->calls));
    }

    public function testSuppressedRecipientIsNotAnError(): void
    {
        $mailer = $this->mailer(fn () => [422, ['error' => 'recipient is suppressed (hard bounce)']]);
        $mailer->send($this->email());

        $this->assertCount(1, $this->calls); // returned normally, nothing thrown
    }

    public function testOtherApiErrorsBecomeTransportException(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('403');
        $this->mailer(fn () => [403, ['error' => 'from domain not allowed for this key']])->send($this->email());
    }

    public function testInvalidRequestIsNotMistakenForSuppression(): void
    {
        $this->expectException(TransportException::class);
        $this->mailer(fn () => [422, ['error' => 'subject must not contain line breaks']])->send($this->email());
    }

    public function testAttachmentsAreRefusedNotDropped(): void
    {
        $this->expectException(TransportException::class);
        $this->mailer($this->queued())->send($this->email()->attach('x', 'a.txt', 'text/plain'));
    }

    public function testDsn(): void
    {
        $this->assertSame(['dsn' => 'smtp://mailpit:1025'], Mail::transport('smtp://mailpit:1025'));
        $this->assertInstanceOf(PostarTransport::class, Mail::transport('postar://pk_abc@api.postar.vip?reply_to=office@app.com'));
        $this->assertInstanceOf(PostarTransport::class, Mail::transport('postar+http://pk_abc@localhost:8080'));
        $this->expectException(\InvalidArgumentException::class);
        Mail::fromDsn('postar://api.postar.vip');
    }

    public function testPlainHttpOnlyForLocalhost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Mail::fromDsn('postar+http://pk_abc@api.postar.vip');
    }
}
