<?php

namespace mikemadisonweb\rabbitmq\components;

interface ProducerInterface
{
    /**
     * Publish a message
     *
     * @param string $msgBody
     * @param string $exchangeName
     * @param string $routingKey
     * @param array|null $headers
     */
    public function publish($msgBody, string $exchangeName, string $routingKey = '', array $headers = null);
}