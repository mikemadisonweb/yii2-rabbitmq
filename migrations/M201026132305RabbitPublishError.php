<?php

namespace mikemadisonweb\rabbitmq\migrations;

use yii\db\Migration;

/**
 * Class M201026132305RabbitPublishError
 */
class M201026132305RabbitPublishError extends Migration
{
    public function safeUp()
    {
        $this->createTable('rabbit_publish_error', [
            'id' => $this->primaryKey(),
            'message' => $this->text()->notNull(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'options' => $this->json()->notNull(),
            'error' => $this->text(),
            'counter' => $this->integer()
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('rabbit_publish_error');
    }
}
