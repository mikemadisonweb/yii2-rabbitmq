<?php

namespace mikemadisonweb\rabbitmq\components;

use mikemadisonweb\rabbitmq\events\RabbitMQPublisherEvent;
use mikemadisonweb\rabbitmq\exceptions\RuntimeException;
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
    protected $safe;
    protected $name = 'unnamed';

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
     * @return mixed
     */
    public function getSafe() : bool
    {
        return $this->safe;
    }

    /**
     * @param mixed $safe
     */
    public function setSafe(bool $safe)
    {
        $this->safe = $safe;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name)
    {
        $this->name = $name;
    }

    /**
     * Publishes the message and merges additional properties with basic properties
     *
     * @param mixed $msgBody
     * @param string $exchangeName
     * @param string $routingKey
     * @param array $headers
     * @throws RuntimeException
     */
    public function publish($msgBody, string $exchangeName, string $routingKey = '', array $headers = null)
    {
        if ($this->safe && !$this->routing->isExchangeExists($exchangeName)) {
            throw new RuntimeException("Exchange `{$exchangeName}` does not exist.");
        }
        if ($this->autoDeclare) {
            $this->routing->declareAll($this->conn);
        }
        $serialized = false;
        if (!is_string($msgBody)) {
            $msgBody = call_user_func($this->serializer, $msgBody);
            $serialized = true;
        }
        $msg = new AMQPMessage($msgBody, $this->getBasicProperties());

        if (!empty($headers) || $serialized) {
            if ($serialized) {
                $headers['rabbitmq.serialized'] = 1;
            }
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
