<?php

declare(strict_types=1);

namespace rapttor\postar\console;

use rapttor\postar\Postar;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Mail queue worker. Cron runs `php yii postar/run` every minute (`php yii postar/cron --install` adds the entry).
 *
 *   postar/run [limit] [seconds]   send due mails (default 500 mails, 50 seconds), one worker at a time
 *   postar/stats                   rows per status
 *   postar/retry-failed            queue failed mails again
 *   postar/purge [days]            delete sent/suppressed rows older than N days (default 30)
 *   postar/cron [--install]        print (or install) the crontab line
 */
final class PostarController extends Controller
{
    public $defaultAction = 'run';

    /** @var bool add the crontab entry instead of only printing it */
    public $install = false;

    public function options($actionID): array
    {
        return $actionID === 'cron' ? ['install'] : [];
    }

    private function postar(): Postar
    {
        return Yii::$app->get('postar');
    }

    public function actionRun(int $limit = 500, int $seconds = 50): int
    {
        $lock = fopen(Yii::getAlias('@runtime') . '/postar-queue.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->stdout("Another worker is running.\n");

            return ExitCode::OK;
        }
        $r = $this->postar()->queue()->process($limit, $seconds);
        $this->stdout(sprintf("sent %d, suppressed %d, retry later %d, failed %d%s\n", $r['sent'], $r['suppressed'], $r['retried'], $r['failed'], $r['throttled'] ? ', rate limit reached (429), paused' : ''));
        flock($lock, LOCK_UN);

        return ExitCode::OK;
    }

    public function actionStats(): int
    {
        foreach ($this->postar()->queue()->stats() as $status => $n) {
            $this->stdout(sprintf("%-11s %d\n", $status, $n));
        }

        return ExitCode::OK;
    }

    public function actionRetryFailed(): int
    {
        $this->stdout($this->postar()->queue()->retryFailed() . " mails queued again.\n");

        return ExitCode::OK;
    }

    public function actionPurge(int $days = 30): int
    {
        $this->stdout($this->postar()->queue()->purge($days) . " rows deleted.\n");

        return ExitCode::OK;
    }

    public function actionCron(): int
    {
        $script = realpath($_SERVER['argv'][0] ?? '') ?: ($_SERVER['argv'][0] ?? 'yii');
        $line = sprintf('* * * * * cd %s && %s %s postar/run >/dev/null 2>&1 # rapttor-postar', escapeshellarg(dirname($script)), escapeshellarg(PHP_BINARY), escapeshellarg($script));
        if (!$this->install) {
            $this->stdout($line . "\n\nAdd it with: php yii postar/cron --install\n");

            return ExitCode::OK;
        }
        $current = (string) shell_exec('crontab -l 2>/dev/null');
        if (str_contains($current, '# rapttor-postar')) {
            $this->stdout("Already installed.\n");

            return ExitCode::OK;
        }
        $file = tempnam(sys_get_temp_dir(), 'cron');
        file_put_contents($file, rtrim($current) . ($current === '' ? '' : "\n") . $line . "\n");
        exec('crontab ' . escapeshellarg($file), $out, $code);
        unlink($file);
        $this->stdout($code === 0 ? "Installed:\n$line\n" : "crontab failed (exit $code). Add the line by hand.\n");

        return $code === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
