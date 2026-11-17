<?php

declare(strict_types=1);

namespace rapttor\postar;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Yii;

/**
 * Attachments and embedded images (`cid:`) in a mail. The Postar send API documented in https://postar.vip/llm.txt has no field for
 * them yet; the plugin can send them as an `attachments` array (see {@see self::payload()}, spec in docs/ATTACHMENTS.md) as soon as
 * the service accepts it. Until then it is a policy (`POSTAR_ATTACHMENTS` or `Postar::$attachments`):
 *
 *   send                   send them in the API payload: use once the Postar service supports `attachments`
 *   skip-inline (default)  embedded images (`cid:` logos in layouts) are dropped with a logged warning, the mail goes out; real
 *                          attachments (PDF, ...) throw, because a mail without the promised file is wrong
 *   skip                   everything is dropped with a logged warning
 *   throw                  any attachment or embedded image throws a TransportException
 *
 * Link images by absolute URL instead of `cid:` (mail clients may ask the reader to allow remote images; keep an alt text).
 */
final class Attachments
{
    public const SKIP_INLINE = 'skip-inline';
    public const SKIP = 'skip';
    public const THROW = 'throw';
    public const SEND = 'send';

    /** @throws TransportException */
    public static function check(Email $email, string $policy): void
    {
        if ($policy === self::SEND) {
            return;
        }
        $inline = 0;
        $files = 0;
        foreach ($email->getAttachments() as $part) {
            $part->getDisposition() === 'inline' ? $inline++ : $files++;
        }
        if ($inline + $files === 0) {
            return;
        }
        if ($policy === self::THROW || ($policy === self::SKIP_INLINE && $files > 0)) {
            throw new TransportException(sprintf('Postar: the mail has %d attachment(s) and %d embedded image(s), the Postar API cannot send them (link images by URL).', $files, $inline));
        }
        $message = sprintf('Postar: dropped %d attachment(s) and %d embedded image(s), the Postar API cannot send them.', $files, $inline);
        if (class_exists(Yii::class, false) && Yii::$app !== null) {
            Yii::warning($message, __CLASS__);
        }
    }

    /**
     * The `attachments` array of the send payload: `filename`, `content_type`, `content` (base64) and, for embedded images,
     * `cid` (the `cid:` used in the html) with `inline: true`.
     *
     * @return list<array<string,mixed>>
     */
    public static function payload(Email $email): array
    {
        $out = [];
        foreach ($email->getAttachments() as $part) {
            $item = [
                'filename' => $part->getFilename() ?? 'attachment',
                'content_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'content' => base64_encode($part->getBody()),
            ];
            if ($part->getDisposition() === 'inline') {
                $item['inline'] = true;
                $item['cid'] = $part->hasContentId() ? $part->getContentId() : $item['filename'];
            }
            $out[] = $item;
        }

        return $out;
    }
}
