<?php

declare(strict_types=1);

namespace rapttor\postar\tests;

use PHPUnit\Framework\TestCase;
use rapttor\postar\EmailQueue;
use rapttor\postar\migrations\m261101_000001_create_emailqueue;
use rapttor\postar\Postar;
use rapttor\postar\QueueTransport;
use rapttor\postar\SendApi;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use yii\console\Application;
use yii\db\Connection;
use yii\db\Query;

final class EmailQueueTest extends TestCase
{
    private Connection $db;
    private Application $app;
    /** @var list<array{0:int,1:array<string,mixed>}> answers handed out in order, the last one repeats */
    private array $answers = [];
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->app = new Application(['id' => 't', 'basePath' => __DIR__, 'components' => ['db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:']]]);
        $this->db = $this->app->db;
        $m = new m261101_000001_create_emailqueue(['db' => $this->db, 'compact' => true]);
        $this->app->set('postar', ['class' => Postar::class, 'apiKey' => 'pk_t', 'from' => 'a@postar.vip']);
        $m->up();
        $this->answers = [[202, ['id' => 'm1', 'status' => 'queued']]];
        $this->calls = 0;
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }

    private function queue(): EmailQueue
    {
        $http = function (): array {
            $a = $this->answers[min($this->calls++, count($this->answers) - 1)];

            return [$a[0], json_encode($a[1])];
        };

        return new EmailQueue($this->db, new SendApi('pk_t', 'https://api.postar.vip', 10, $http));
    }

    private function add(EmailQueue $q, string $to = 'u@x.org'): int
    {
        return $q->enqueue(['to' => $to, 'from' => 'a@postar.vip', 'subject' => 'Hi', 'html' => '<p>x</p>']);
    }

    private function rowStatus(int $id): string
    {
        return (string) (new Query())->select('status')->from('rapttor_emailqueue')->where(['id' => $id])->scalar($this->db);
    }

    public function testSendsDueMailsAndMarksThemSent(): void
    {
        $q = $this->queue();
        $id = $this->add($q);
        $this->add($q, 'later@x.org');
        $this->db->createCommand()->update('rapttor_emailqueue', ['next_attempt_at' => time() + 3600], ['to_email' => 'later@x.org'])->execute();

        $this->assertSame(1, $q->process()['sent']);
        $this->assertSame('sent', $this->rowStatus($id));
        $this->assertEqualsCanonicalizing(['sent' => 1, 'pending' => 1], $q->stats());
    }

    public function testSuppressedIsFinalAndNotRetried(): void
    {
        $this->answers = [[422, ['error' => 'Recipient has unsubscribed', 'code' => 'recipient_unsubscribed']]];
        $q = $this->queue();
        $id = $this->add($q);

        $this->assertSame(1, $q->process()['suppressed']);
        $this->assertSame('suppressed', $this->rowStatus($id));
        $this->assertSame(0, $q->process()['sent'] + $q->process()['retried']);
    }

    public function testRateLimitPausesTheBatchWithoutBurningAttempts(): void
    {
        $this->answers = [[429, ['error' => 'rate limit']]];
        $q = $this->queue();
        $a = $this->add($q);
        $this->add($q, 'b@x.org');

        $r = $q->process();
        $this->assertTrue($r['throttled']);
        $this->assertSame(1, $this->calls, 'stops at the first 429');
        $this->assertSame('pending', $this->rowStatus($a));
        $this->assertSame(0, (int) (new Query())->select('attempts')->from('rapttor_emailqueue')->where(['id' => $a])->scalar($this->db));
        $this->assertSame(0, $q->process()['sent'], 'paused until next_attempt_at');
    }

    public function testServerErrorsRetryThenFail(): void
    {
        $this->answers = [[503, ['error' => 'down']]];
        $q = $this->queue();
        $id = $this->add($q);
        for ($i = 1; $i <= 5; $i++) {
            $this->db->createCommand()->update('rapttor_emailqueue', ['next_attempt_at' => 0], ['id' => $id])->execute();
            $r = $q->process();
        }
        $this->assertSame(1, $r['failed']);
        $this->assertSame('failed', $this->rowStatus($id));

        $this->assertSame(1, $q->retryFailed());
        $this->assertSame('pending', $this->rowStatus($id));
    }

    public function testClientErrorFailsAtOnce(): void
    {
        $this->answers = [[403, ['error' => 'from domain not allowed']]];
        $q = $this->queue();
        $id = $this->add($q);

        $this->assertSame(1, $q->process()['failed']);
        $this->assertSame('failed', $this->rowStatus($id));
    }

    public function testUnexpectedErrorReleasesTheRow(): void
    {
        $q = new EmailQueue($this->db, new SendApi('pk_t', 'https://api.postar.vip', 10, function (): array {
            throw new \LogicException('boom');
        }));
        $id = $this->add($q);
        try {
            $q->process();
            $this->fail('expected LogicException');
        } catch (\LogicException) {
        }
        $this->assertSame('pending', $this->rowStatus($id));
    }

    public function testCrashedWorkerRowIsPickedUpAgain(): void
    {
        $q = $this->queue();
        $id = $this->add($q);
        $this->db->createCommand()->update('rapttor_emailqueue', ['status' => 'sending', 'locked_at' => time() - 3600], ['id' => $id])->execute();

        $this->assertSame(1, $q->process()['sent']);
    }

    public function testPurgeKeepsUnfinishedMails(): void
    {
        $q = $this->queue();
        $sent = $this->add($q);
        $pending = $this->add($q, 'p@x.org');
        $this->db->createCommand()->update('rapttor_emailqueue', ['status' => 'sent', 'created_at' => time() - 40 * 86400], ['id' => $sent])->execute();
        $this->db->createCommand()->update('rapttor_emailqueue', ['created_at' => time() - 40 * 86400], ['id' => $pending])->execute();

        $this->assertSame(1, $q->purge(30));
        $this->assertSame('pending', $this->rowStatus($pending));
    }

    public function testQueueTransportStoresOneRowPerRecipient(): void
    {
        $q = $this->queue();
        $mail = (new Email())->from('App <a@postar.vip>')->to('u@x.org')->cc('c@x.org')->subject('Hi')->html('<p>x</p>')->text('x');
        (new Mailer(new QueueTransport($q, 'office@app.com')))->send($mail);

        $this->assertSame(['pending' => 2], $q->stats());
        $row = (new Query())->from('rapttor_emailqueue')->orderBy('id')->one($this->db);
        $this->assertSame(['u@x.org', 'App', 'office@app.com'], [$row['to_email'], $row['from_name'], $row['reply_to']]);
    }

    public function testSenderFallsBackToConfigThenToTheAppName(): void
    {
        $this->app->name = 'Moj Šoping App';
        $p = new Postar(['apiKey' => 'pk_t']);
        $this->assertSame('moj-soping-app@postar.vip', $p->fromAddress());
        $this->assertSame('Moj Šoping App', $p->fromDisplayName());

        $p = new Postar(['apiKey' => 'pk_t', 'from' => 'login@postar.vip', 'fromName' => 'Login', 'replyTo' => 'office@app.com']);
        $this->assertSame('login@postar.vip', $p->fromAddress());
        $this->assertSame('Login', $p->fromDisplayName());
    }

    public function testEnqueueWithoutSenderUsesDefaultsAndOwnValuesWin(): void
    {
        $this->app->name = 'Shop';
        $this->app->set('postar', ['class' => Postar::class, 'apiKey' => 'pk_t', 'replyTo' => 'office@shop.com', 'http' => fn () => [202, '{"id":"m","status":"queued"}']]);
        $q = $this->app->get('postar')->queue();

        $q->enqueue(['to' => 'a@x.org', 'subject' => 'Hi', 'html' => '<p>x</p>']);
        $q->enqueue(['to' => 'b@x.org', 'subject' => 'Hi', 'html' => '<p>x</p>', 'from' => 'own@postar.vip', 'name' => 'Own', 'reply_to' => 'me@x.org']);

        $rows = (new Query())->from('rapttor_emailqueue')->orderBy('id')->all($this->db);
        $this->assertSame(['shop@postar.vip', 'Shop', 'office@shop.com'], [$rows[0]['from_email'], $rows[0]['from_name'], $rows[0]['reply_to']]);
        $this->assertSame(['own@postar.vip', 'Own', 'me@x.org'], [$rows[1]['from_email'], $rows[1]['from_name'], $rows[1]['reply_to']]);
    }

    public function testDevEnvironmentKeepsTheLocalMailer(): void
    {
        $this->assertFalse((new Postar(['enabled' => false]))->isActive());
        $this->assertTrue((new Postar(['enabled' => true]))->isActive());
        $this->assertTrue((new Postar())->isActive(), 'tests run with YII_ENV=prod');
        $this->assertFalse((new Postar(['localEnvs' => ['prod']]))->isActive());
        putenv('POSTAR_ENABLED=0');
        try {
            $this->assertFalse((new Postar())->isActive());
            $this->assertTrue((new Postar(['enabled' => true]))->isActive(), 'config beats the environment');
        } finally {
            putenv('POSTAR_ENABLED');
        }
    }
}
