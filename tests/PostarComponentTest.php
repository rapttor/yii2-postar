<?php

declare(strict_types=1);

namespace rapttor\postar\tests;

use PHPUnit\Framework\TestCase;
use rapttor\postar\Postar;
use rapttor\postar\PostarApiException;
use yii\base\InvalidConfigException;

final class PostarComponentTest extends TestCase
{
    /** @var list<array{url:string, method:string, headers:array<string,string>, body:?array<string,mixed>}> */
    private array $calls = [];

    /** @param array{0:int,1:array<string,mixed>} $answer */
    private function postar(array $answer, array $config = []): Postar
    {
        $http = function (string $url, array $headers, string $body, string $method = 'POST') use ($answer): array {
            $this->calls[] = ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $body === '' ? null : json_decode($body, true)];

            return [$answer[0], json_encode($answer[1])];
        };

        return new Postar($config + ['apiKey' => 'pk_test', 'from' => 'app@postar.vip', 'fromName' => 'My App', 'replyTo' => 'office@app.com', 'http' => $http]);
    }

    public function testSendUsesConfiguredDefaultsAndOptions(): void
    {
        $res = $this->postar([202, ['id' => 'x1', 'status' => 'queued']])->send('u@x.org', 'Login', '<p>Hi</p>', ['text' => 'Hi']);

        $this->assertSame(['id' => 'x1', 'status' => 'queued'], $res);
        $c = $this->calls[0];
        $this->assertSame('https://api.postar.vip/v1/send', $c['url']);
        $this->assertSame('POST', $c['method']);
        $this->assertSame('Bearer pk_test', $c['headers']['Authorization']);
        $this->assertSame(
            ['from' => 'app@postar.vip', 'name' => 'My App', 'reply_to' => 'office@app.com', 'to' => 'u@x.org', 'subject' => 'Login', 'html' => '<p>Hi</p>', 'text' => 'Hi'],
            $c['body'],
        );
    }

    public function testBlockedRecipientExposesResubscribeUrl(): void
    {
        $postar = $this->postar([422, ['error' => 'Recipient has unsubscribed from this project', 'code' => 'recipient_unsubscribed', 'resubscribe_url' => 'https://postar.vip/r/abc']]);
        try {
            $postar->send('u@x.org', 'Hi', '<p>x</p>');
            $this->fail('expected PostarApiException');
        } catch (PostarApiException $e) {
            $this->assertTrue($e->isSuppressed());
            $this->assertSame('recipient_unsubscribed', $e->errorCode());
            $this->assertSame('https://postar.vip/r/abc', $e->resubscribeUrl());
        }
    }

    public function testInvalidRequestIsNotSuppressed(): void
    {
        try {
            $this->postar([422, ['error' => 'subject must not contain line breaks']])->send('u@x.org', "a\nb", '<p>x</p>');
            $this->fail('expected PostarApiException');
        } catch (PostarApiException $e) {
            $this->assertFalse($e->isSuppressed());
            $this->assertSame(422, $e->getCode());
        }
    }

    public function testUnsubscribe(): void
    {
        $this->assertTrue($this->postar([201, ['email' => 'u@x.org']])->unsubscribe('u@x.org'));
        $this->assertFalse($this->postar([200, ['email' => 'u@x.org']])->unsubscribe('u@x.org'));
        $this->assertSame(['email' => 'u@x.org'], $this->calls[0]['body']);
        $this->assertSame('https://api.postar.vip/v1/unsubscribes', $this->calls[0]['url']);
    }

    public function testStatusEncodesTheAddress(): void
    {
        $postar = $this->postar([200, ['email' => 'a+b@x.org', 'unsubscribed' => true, 'source' => 'recipient', 'since' => '2026-10-01']]);

        $this->assertTrue($postar->isUnsubscribed('a+b@x.org'));
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('https://api.postar.vip/v1/unsubscribes?email=a%2Bb%40x.org', $this->calls[0]['url']);
        $this->assertSame('recipient', $postar->unsubscribeStatus('a+b@x.org')['source']);
    }

    public function testResubscribeConflictCarriesTheUrl(): void
    {
        $postar = $this->postar([409, ['error' => 'Blocked by the recipient', 'resubscribe_url' => 'https://postar.vip/r/abc']]);
        try {
            $postar->resubscribe('u@x.org');
            $this->fail('expected PostarApiException');
        } catch (PostarApiException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('https://postar.vip/r/abc', $e->resubscribeUrl());
            $this->assertSame('DELETE', $this->calls[0]['method']);
        }

        $this->calls = [];
        $this->postar([200, ['email' => 'u@x.org']])->resubscribe('u@x.org');
        $this->assertCount(1, $this->calls);
    }

    public function testMissingConfigIsReported(): void
    {
        putenv('POSTAR_API_KEY');
        unset($_ENV['POSTAR_API_KEY']);
        $this->expectException(InvalidConfigException::class);
        (new Postar(['baseUrl' => 'https://api.postar.vip']))->send('u@x.org', 'Hi', '<p>x</p>');
    }

    public function testSettingsComeFromTheEnvironment(): void
    {
        putenv('POSTAR_API_KEY=pk_env');
        putenv('POSTAR_FROM=env@postar.vip');
        try {
            $postar = new Postar();
            $this->assertSame('pk_env', $postar->apiKey);
            $this->assertSame('env@postar.vip', $postar->from);
            $this->assertSame('https://api.postar.vip', $postar->baseUrl);
        } finally {
            putenv('POSTAR_API_KEY');
            putenv('POSTAR_FROM');
        }
    }
}
