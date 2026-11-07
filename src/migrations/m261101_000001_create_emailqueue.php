<?php

declare(strict_types=1);

namespace rapttor\postar\migrations;

use Yii;
use yii\db\Migration;

/** Creates the mail queue table (name from the `postar` component, default `rapttor_emailqueue`). Shared by all Rapttor apps on the database. */
final class m261101_000001_create_emailqueue extends Migration
{
    private function table(): string
    {
        return Yii::$app->get('postar')->queueTable;
    }

    public function safeUp(): void
    {
        $big = $this->db->driverName === 'mysql' ? 'MEDIUMTEXT' : $this->text();
        $this->createTable($this->table(), [
            'id' => $this->bigPrimaryKey(),
            'to_email' => $this->string(254)->notNull(),
            'from_email' => $this->string(254)->notNull(),
            'from_name' => $this->string(254),
            'reply_to' => $this->string(254),
            'subject' => $this->string(998)->notNull(),
            'html' => $big,
            'text' => $big,
            'status' => $this->string(12)->notNull()->defaultValue('pending'),
            'attempts' => $this->smallInteger()->notNull()->defaultValue(0),
            'next_attempt_at' => $this->integer()->notNull(),
            'locked_at' => $this->integer(),
            'postar_id' => $this->string(64),
            'last_error' => $this->string(500),
            'created_at' => $this->integer()->notNull(),
            'sent_at' => $this->integer(),
        ]);
        $this->createIndex('idx_emailqueue_due', $this->table(), ['status', 'next_attempt_at']);
        $this->createIndex('idx_emailqueue_to', $this->table(), ['to_email']);
    }

    public function safeDown(): void
    {
        $this->dropTable($this->table());
    }
}
