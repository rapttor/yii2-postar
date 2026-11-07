<?php

declare(strict_types=1);

namespace rapttor\postar\tests;

use PHPUnit\Framework\TestCase;
use rapttor\postar\Bootstrap;
use rapttor\postar\Postar;
use yii\console\Application;

final class BootstrapTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    private function app(bool $default): Application
    {
        return new Application([
            'id' => 't', 'basePath' => __DIR__,
            'components' => ['postar' => ['class' => Postar::class, 'apiKey' => 'pk_t', 'from' => 'app@postar.vip', 'fromName' => 'App', 'defaultMailer' => $default,
                'http' => function (string $url, array $h, string $body): array {
                    $this->sent[] = json_decode($body, true);

                    return [202, '{"id":"m1","status":"queued"}'];
                }]],
        ]);
    }

    public function testMailerIsReplacedWhenEnabled(): void
    {
        $app = $this->app(true);
        (new Bootstrap())->bootstrap($app);

        $this->assertTrue($app->mailer->compose()->setTo('u@x.org')->setSubject('Hi')->setHtmlBody('<p>x</p>')->send());
        $this->assertSame('app@postar.vip', $this->sent[0]['from']);
        $this->assertSame('App', $this->sent[0]['name']);
        $this->assertSame('u@x.org', $this->sent[0]['to']);
    }

    public function testMailerUntouchedByDefault(): void
    {
        $app = $this->app(false);
        (new Bootstrap())->bootstrap($app);

        $this->assertFalse($app->has('mailer'));
    }
}
