<?php

declare(strict_types=1);

namespace rapttor\postar;

use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\console\Application as ConsoleApplication;
use yii\symfonymailer\Mailer;

/**
 * Makes Postar the delivery backend of `Yii::$app->mailer` when the `postar` component has `defaultMailer => true`.
 * `compose()`, views and layouts keep working; only the transport changes. Replaces any existing `mailer` definition
 * (including `useFileTransport`), so enable it only where mail should really go out.
 */
final class Bootstrap implements BootstrapInterface
{
    /** @param Application $app */
    public function bootstrap($app): void
    {
        if (!$app->has('postar')) {
            return;
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
        if (!$postar->defaultMailer || !$postar->isActive()) {
            return;
        }
        $config = [
            'class' => Mailer::class,
            'transport' => $postar->queue
                ? new QueueTransport($postar->queue(), $postar->replyTo)
                : new PostarTransport($postar->api(), $postar->replyTo),
        ];
        $name = $postar->fromDisplayName();
        $config['messageConfig'] = ['from' => $name ? [$postar->fromAddress() => $name] : $postar->fromAddress()];
        $app->set('mailer', $config);
    }
}
