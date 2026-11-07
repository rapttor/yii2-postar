<?php

declare(strict_types=1);

namespace rapttor\postar;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * Stores each mail (one row per recipient) in the {@see EmailQueue} instead of calling the API; `php yii postar/run` sends it.
 * Same message rules as {@see PostarTransport}: no attachments.
 */
final class QueueTransport extends AbstractTransport
{
    public function __construct(private readonly EmailQueue $queue, private readonly ?string $replyTo = null)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'postar+queue://';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new TransportException('Postar queue can only store Symfony\Component\Mime\Email messages.');
        }
        if ($email->getAttachments() !== []) {
            throw new TransportException('Postar queue: attachments are not supported.');
        }
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if (($html === null || $html === '') && ($text === null || $text === '')) {
            throw new TransportException('Postar queue: the message has neither an HTML nor a text body.');
        }
        $sender = $message->getEnvelope()->getSender();
        $reply = ($r = $email->getReplyTo()) !== [] ? $r[0]->getAddress() : $this->replyTo;

        foreach ($message->getEnvelope()->getRecipients() as $to) {
            $this->queue->enqueue([
                'to' => $to->getAddress(),
                'from' => $sender->getAddress(),
                'name' => $sender->getName() !== '' ? $sender->getName() : null,
                'reply_to' => $reply,
                'subject' => $email->getSubject() ?? '',
                'html' => $html,
                'text' => $text,
            ]);
        }
    }
}
