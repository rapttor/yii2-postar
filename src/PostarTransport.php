<?php

declare(strict_types=1);

namespace rapttor\postar;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Yii;

/**
 * Sends Symfony Mailer messages through the live Postar API (`POST /v1/send`, see https://api.postar.vip/llm.txt).
 *
 * Postar takes exactly one recipient per call, so a mail to several recipients (to, cc, bcc) is sent as one call per address.
 * A recipient Postar refuses because it is suppressed (bounced, complained, unsubscribed) is skipped and logged, NOT an error:
 * read it from {@see self::$suppressed} or the `onSuppressed` callback, and never retry.
 *
 * Postar adds its own unsubscribe link and `List-Unsubscribe` headers to every mail: never send one from the app.
 */
final class PostarTransport extends AbstractTransport
{
    /** @var list<array{to:string, reason:string}> suppressed recipients of the last send */
    public array $suppressed = [];

    /** @var (callable(string $to, string $reason): void)|null */
    public $onSuppressed = null;

    /**
     * @param string|null $replyTo default Reply-To when the mail has none: `@postar.vip` addresses cannot receive mail,
     *                             so replies would bounce unless this (or the project's Reply-To in Postar) points to a real mailbox
     */
    public function __construct(private readonly SendApi $api, private readonly ?string $replyTo = null)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'postar://';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new TransportException('Postar transport can only send Symfony\Component\Mime\Email messages.');
        }
        if ($email->getAttachments() !== []) {
            // the live API has no attachments; fail loudly instead of dropping files
            throw new TransportException('Postar transport: attachments are not supported.');
        }
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if (($html === null || $html === '') && ($text === null || $text === '')) {
            throw new TransportException('Postar transport: the message has neither an HTML nor a text body.');
        }
        $sender = $message->getEnvelope()->getSender();
        $reply = ($r = $email->getReplyTo()) !== [] ? $r[0]->getAddress() : $this->replyTo;

        $this->suppressed = [];
        $first = null;
        foreach ($message->getEnvelope()->getRecipients() as $to) {
            $payload = array_filter([
                'from' => $sender->getAddress(),
                'name' => $sender->getName() !== '' ? $sender->getName() : null,
                'to' => $to->getAddress(),
                'subject' => $email->getSubject() ?? '',
                'html' => $html !== '' ? $html : null,
                'text' => $text !== '' ? $text : null,
                'reply_to' => $reply,
            ], static fn ($v) => $v !== null);
            try {
                $res = $this->api->send($payload);
                $first ??= $res['id'];
            } catch (PostarApiException $e) {
                if ($e->isSuppressed()) {
                    $this->suppressed[] = ['to' => $to->getAddress(), 'reason' => $e->getMessage()];
                    $this->log('warning', sprintf('Postar: %s is suppressed (%s), not sent.', $to->getAddress(), $e->getMessage()));
                    if ($this->onSuppressed !== null) {
                        ($this->onSuppressed)($to->getAddress(), $e->getMessage());
                    }
                    continue;
                }
                $this->log('error', 'Postar: ' . $e->getMessage() . ' (HTTP ' . $e->getCode() . ')');
                throw new TransportException('Postar: ' . $e->getMessage() . ' (HTTP ' . $e->getCode() . ')', 0, $e);
            }
        }
        if ($first !== null) {
            $message->setMessageId($first);
        }
    }

    private function log(string $level, string $message): void
    {
        if (class_exists(Yii::class, false) && Yii::$app !== null) {
            $level === 'error' ? Yii::error($message, __CLASS__) : Yii::warning($message, __CLASS__);
        }
    }
}
