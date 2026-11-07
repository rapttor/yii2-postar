<?php

declare(strict_types=1);

namespace rapttor\postar;

/** An error answer from the live Postar API. `getCode()` is the HTTP status (0 = network). */
final class PostarApiException extends \RuntimeException
{
    /** @param array<string,mixed> $data decoded JSON body of the error answer (`error`, `code`, `resubscribe_url`) */
    public function __construct(string $message, int $code = 0, private readonly array $data = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** Machine readable reason: `recipient_unsubscribed`, `recipient_suppressed` or null. */
    public function errorCode(): ?string
    {
        return isset($this->data['code']) ? (string) $this->data['code'] : null;
    }

    /** Where a blocked recipient can opt in again (only the recipient can finish it; the app cannot). */
    public function resubscribeUrl(): ?string
    {
        return isset($this->data['resubscribe_url']) ? (string) $this->data['resubscribe_url'] : null;
    }

    /** 422 is also used for "recipient is suppressed (bounced/complained/unsubscribed)": a normal outcome, never retried. */
    public function isSuppressed(): bool
    {
        if ($this->getCode() !== 422) {
            return false;
        }
        if (in_array($this->errorCode(), ['recipient_unsubscribed', 'recipient_suppressed'], true)) {
            return true;
        }

        return (bool) preg_match('/suppress|bounc|complain|unsubscrib/i', $this->getMessage());
    }
}
