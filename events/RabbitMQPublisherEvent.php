<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\events;

use mikemadisonweb\rabbitmq\components\Consumer;
use PhpAmqpLib\Message\AMQPMessage;
use yii\base\Event;

class RabbitMQPublisherEvent extends Event
{
    const BEFORE_PUBLISH = 'before_publish';
    const AFTER_PUBLISH  = 'after_publish';

    /**
     * @var AMQPMessage
     */
    public $message;

    /**
     * @var Consumer
     */
    public $producer;
}
