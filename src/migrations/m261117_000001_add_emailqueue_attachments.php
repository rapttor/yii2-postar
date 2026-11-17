<?php

declare(strict_types=1);

namespace rapttor\postar\migrations;

use Yii;
use yii\db\Migration;

/** Adds the `attachments` column (JSON with base64 content) to the mail queue table. */
final class m261117_000001_add_emailqueue_attachments extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(Yii::$app->get('postar')->queueTable, 'attachments', $this->db->driverName === 'mysql' ? 'LONGTEXT' : $this->text());
    }

    public function safeDown(): void
    {
        $this->dropColumn(Yii::$app->get('postar')->queueTable, 'attachments');
    }
}
