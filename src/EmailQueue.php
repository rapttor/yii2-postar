<?php

declare(strict_types=1);

namespace rapttor\postar;

use yii\db\Connection;
use yii\db\Query;

/**
 * The `rapttor_emailqueue` table and its worker: mail is stored first and sent in batches by `php yii postar/run` (cron),
 * so a request never waits for the API and a huge number of mails survives rate limits (429) and outages.
 *
 * Status: pending -> sending -> sent | suppressed (recipient blocked, never retried) | failed (gave up, see `last_error`).
 * Timeouts, 5xx and network errors are retried with a growing delay; 429 pauses the whole batch.
 */
final class EmailQueue
{
    public const PENDING = 'pending';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const SUPPRESSED = 'suppressed';
    public const FAILED = 'failed';

    /** A `sending` row older than this was left by a crashed worker and is queued again. */
    private const STALE_SECONDS = 900;
    /** Pause after HTTP 429 (the hourly rate limit of the key). */
    private const THROTTLE_SECONDS = 600;

    public function __construct(
        private readonly Connection $db,
        private readonly SendApi $api,
        private readonly string $table = 'rapttor_emailqueue',
        private readonly int $maxAttempts = 5,
        /** @var array{from?:?string, name?:?string, reply_to?:?string} used when a mail has none of its own */
        private readonly array $defaults = [],
    ) {
    }

    /**
     * `from`, `name` and `reply_to` are optional: a mail without them gets the defaults of the `postar` component
     * (POSTAR_FROM, POSTAR_FROM_NAME, POSTAR_REPLY_TO, else the app name).
     *
     * @param array{to:string, subject:string, from?:?string, html?:?string, text?:?string, name?:?string, reply_to?:?string, send_after?:int} $mail
     * @return int row id
     */
    public function enqueue(array $mail): int
    {
        $now = time();
        $mail['from'] = ($mail['from'] ?? '') !== '' ? $mail['from'] : ($this->defaults['from'] ?? '');
        if ($mail['from'] === '') {
            throw new \InvalidArgumentException('The mail has no from address and no default is configured.');
        }
        $mail['name'] ??= $this->defaults['name'] ?? null;
        $mail['reply_to'] ??= $this->defaults['reply_to'] ?? null;
        $this->db->createCommand()->insert($this->table, [
            'to_email' => $mail['to'],
            'from_email' => $mail['from'],
            'from_name' => $mail['name'] ?? null,
            'reply_to' => $mail['reply_to'] ?? null,
            'subject' => $mail['subject'],
            'html' => $mail['html'] ?? null,
            'text' => $mail['text'] ?? null,
            'status' => self::PENDING,
            'attempts' => 0,
            'next_attempt_at' => $mail['send_after'] ?? $now,
            'created_at' => $now,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    /**
     * Sends due mails, oldest first, until the limit or the time budget is used up.
     *
     * @return array{sent:int, suppressed:int, retried:int, failed:int, throttled:bool}
     */
    public function process(int $limit = 500, int $seconds = 50): array
    {
        $result = ['sent' => 0, 'suppressed' => 0, 'retried' => 0, 'failed' => 0, 'throttled' => false];
        $now = time();
        $this->db->createCommand()->update($this->table, ['status' => self::PENDING, 'locked_at' => null], ['and', ['status' => self::SENDING], ['<', 'locked_at', $now - self::STALE_SECONDS]])->execute();

        $ids = (new Query())->select('id')->from($this->table)
            ->where(['status' => self::PENDING])->andWhere(['<=', 'next_attempt_at', $now])
            ->orderBy('id')->limit($limit)->column($this->db);
        $deadline = $now + $seconds;

        foreach ($ids as $id) {
            if (time() >= $deadline) {
                break;
            }
            // claim: only one worker wins the row
            $claimed = $this->db->createCommand()->update($this->table, ['status' => self::SENDING, 'locked_at' => time()], ['id' => $id, 'status' => self::PENDING])->execute();
            if ($claimed !== 1) {
                continue;
            }
            $row = (new Query())->from($this->table)->where(['id' => $id])->one($this->db);
            try {
                $res = $this->api->send(array_filter([
                    'from' => $row['from_email'],
                    'name' => $row['from_name'],
                    'to' => $row['to_email'],
                    'subject' => $row['subject'],
                    'html' => $row['html'] ?: null,
                    'text' => $row['text'] ?: null,
                    'reply_to' => $row['reply_to'],
                ], static fn ($v) => $v !== null));
                $this->mark($id, ['status' => self::SENT, 'postar_id' => $res['id'], 'sent_at' => time(), 'attempts' => (int) $row['attempts'] + 1, 'last_error' => null]);
                $result['sent']++;
            } catch (PostarApiException $e) {
                if ($e->isSuppressed()) {
                    $this->mark($id, ['status' => self::SUPPRESSED, 'last_error' => $e->getMessage(), 'attempts' => (int) $row['attempts'] + 1]);
                    $result['suppressed']++;
                } elseif ($e->getCode() === 429) {
                    // not the mail's fault: put it back untouched and stop this run
                    $this->mark($id, ['status' => self::PENDING, 'next_attempt_at' => time() + self::THROTTLE_SECONDS, 'last_error' => $e->getMessage()]);
                    $result['throttled'] = true;
                    break;
                } else {
                    $attempts = (int) $row['attempts'] + 1;
                    $temporary = $e->getCode() === 0 || $e->getCode() >= 500;
                    if ($temporary && $attempts < $this->maxAttempts) {
                        $this->mark($id, ['status' => self::PENDING, 'attempts' => $attempts, 'next_attempt_at' => time() + min(3600, 60 * 2 ** $attempts), 'last_error' => $e->getMessage()]);
                        $result['retried']++;
                    } else {
                        $this->mark($id, ['status' => self::FAILED, 'attempts' => $attempts, 'last_error' => $e->getMessage()]);
                        $result['failed']++;
                    }
                }
            } catch (\Throwable $e) {
                // unexpected (not an API answer): give the row back instead of leaving it locked, then stop
                $this->mark($id, ['status' => self::PENDING, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
                throw $e;
            }
        }

        return $result;
    }

    /** Deletes finished rows (sent, suppressed) older than $days, so the table stays small. */
    public function purge(int $days = 30): int
    {
        return $this->db->createCommand()->delete($this->table, ['and', ['status' => [self::SENT, self::SUPPRESSED]], ['<', 'created_at', time() - $days * 86400]])->execute();
    }

    /** Queues all `failed` mails again (after the cause, e.g. a wrong From domain, is fixed). */
    public function retryFailed(): int
    {
        return $this->db->createCommand()->update($this->table, ['status' => self::PENDING, 'attempts' => 0, 'next_attempt_at' => time()], ['status' => self::FAILED])->execute();
    }

    /** @return array<string,int> count per status */
    public function stats(): array
    {
        $rows = (new Query())->select(['status', 'n' => 'COUNT(*)'])->from($this->table)->groupBy('status')->all($this->db);

        return array_map('intval', array_column($rows, 'n', 'status'));
    }

    /** @param array<string,mixed> $values */
    private function mark(int|string $id, array $values): void
    {
        $this->db->createCommand()->update($this->table, $values + ['locked_at' => null], ['id' => $id])->execute();
    }
}
