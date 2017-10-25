<?php

namespace mikemadisonweb\rabbitmq\components;

use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\events\RabbitMQPublisherEvent;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Service that sends AMQP Messages
 * @package mikemadisonweb\rabbitmq\components
 */
class Producer extends BaseRabbitMQ
{
    protected $contentType;
    protected $deliveryMode;
    protected $serializer;

    /**
     * @param $contentType
     */
    public function setContentType($contentType)
    {
        $this->contentType = $contentType;
    }

    /**
     * @param $deliveryMode
     */
    public function setDeliveryMode($deliveryMode)
    {
        $this->deliveryMode = $deliveryMode;
    }

    /**
     * @param callable $serializer
     */
    public function setSerializer(callable $serializer)
    {
        $this->serializer = $serializer;
    }


    /**
     * @return callable
     */
    public function getSerializer() : callable
    {
        return $this->serializer;
    }

    /**
     * @return array
     */
    public function getBasicProperties() : array
    {
        return [
            'content_type' => $this->contentType,
            'delivery_mode' => $this->deliveryMode,
        ];
    }

    /**
     * Publishes the message and merges additional properties with basic properties
     *
     * @param mixed $msgBody
     * @param string $exchangeName
     * @param string $routingKey
     * @param array $headers
     * @throws \RuntimeException
     */
    public function publish($msgBody, string $exchangeName, string $routingKey = '', array $headers = null)
    {
        if ($this->autoDeclare) {
            $this->routing->declareAll($this->conn);
        }
        if (!is_string($msgBody)) {
            $msgBody = call_user_func($this->serializer, $msgBody);
        }
        $msg = new AMQPMessage($msgBody, $this->getBasicProperties());
        if (!empty($headers)) {
            $headersTable = new AMQPTable($headers);
            $msg->set('application_headers', $headersTable);
        }

        \Yii::$app->rabbitmq->trigger(RabbitMQPublisherEvent::BEFORE_PUBLISH, new RabbitMQPublisherEvent([
            'message' => $msg,
            'producer' => $this,
        ]));

        $this->getChannel()->basic_publish($msg, $exchangeName, $routingKey);

        \Yii::$app->rabbitmq->trigger(RabbitMQPublisherEvent::AFTER_PUBLISH, new RabbitMQPublisherEvent([
            'message' => $msg,
            'producer' => $this,
        ]));

        $this->logger->log(
            'AMQP message published',
            $msg,
            [
                'exchange' => $exchangeName,
                'routing_key' => $routingKey,

            ]
        );
    }
}
