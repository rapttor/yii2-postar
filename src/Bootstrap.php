<?php

declare(strict_types=1);

namespace rapttor\postar;

use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\console\Application as ConsoleApplication;
use yii\symfonymailer\Mailer;

/**
 * Zero config: when `POSTAR_API_KEY` is in the environment, registers the `postar` component (if the app has none) and makes
 * Postar the delivery backend of `Yii::$app->mailer`. No `MAIL_DSN`, no mailer config. `defaultMailer => false` opts out.
 * `compose()`, views and layouts keep working; only the transport changes. Replaces any existing `mailer` definition
 * (including `useFileTransport`), so enable it only where mail should really go out.
 */
final class Bootstrap implements BootstrapInterface
{
    /** @param Application $app */
    public function bootstrap($app): void
    {
        if (!$app->has('postar')) {
            if (Postar::env('POSTAR_API_KEY') === '') {
                return;
            }
            $app->set('postar', ['class' => Postar::class]);
        }
        $postar = $app->get('postar');
        if (!$postar instanceof Postar) {
            return;
        }
        if ($app instanceof ConsoleApplication) {
            \Yii::setAlias('@rapttor/postar', __DIR__); // migration namespace lookup, also when not installed as an extension
            $app->controllerMap['postar'] ??= ['class' => console\PostarController::class];
            $migrate = $app->controllerMap['migrate'] ?? ['class' => \yii\console\controllers\MigrateController::class];
            if (is_array($migrate)) {
                $migrate['migrationNamespaces'] = array_values(array_unique([...($migrate['migrationNamespaces'] ?? []), 'rapttor\\postar\\migrations']));
                $app->controllerMap['migrate'] = $migrate;
            }
        }
        if (!$postar->takesOverMailer() || !$postar->isActive()) {
            return;
        }
        $config = [
            'class' => Mailer::class,
            'transport' => $postar->usesQueue()
                ? new QueueTransport($postar->queue(), $postar->replyTo, $postar->attachmentPolicy())
                : new PostarTransport($postar->api(), $postar->replyTo, $postar->attachmentPolicy()),
        ];
        $name = $postar->fromDisplayName();
        $config['messageConfig'] = ['from' => $name ? [$postar->fromAddress() => $name] : $postar->fromAddress()];
        $app->set('mailer', $config);
    }
}
