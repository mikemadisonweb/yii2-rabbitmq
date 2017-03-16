<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Message\AMQPMessage;

class MultipleConsumer extends Consumer
{
    protected $queues = [];

    /**
     * @param $queue
     * @return string
     */
    public function getQueueConsumerTag($queue)
    {
        return sprintf('%s-%s', $this->getConsumerTag(), $queue);
    }

    /**
     * @param array $queues
     */
    public function setQueues(array $queues)
    {
        $this->queues = $queues;
    }

    protected function startConsuming()
    {
        if ($this->autoSetupFabric) {
            $this->setupFabric();
        }
        foreach ($this->queues as $name => $options) {
            //PHP 5.3 Compliant
            $currentObject = $this;
            $this->getChannel()->basic_consume($name, $this->getQueueConsumerTag($name), false, false, false, false, function (AMQPMessage $msg) use ($currentObject, $name) {
                $currentObject->processQueueMessage($name, $msg);
            });
        }
    }

    protected function queueDeclare()
    {
        foreach ($this->queues as $name => $options) {
            $options = array_merge($this->queueOptions, $options);
            list($queueName, ,) = $this->getChannel()->queue_declare(
                $name,
                $options['passive'],
                $options['durable'],
                $options['exclusive'],
                $options['auto_delete'],
                $options['nowait'],
                $options['arguments'],
                $options['ticket']
            );
            if (isset($options['routing_keys']) && count($options['routing_keys']) > 0) {
                foreach ($options['routing_keys'] as $routingKey) {
                    $this->queueBind($queueName, $this->exchangeOptions['name'], $routingKey);
                }
            } else {
                $this->queueBind($queueName, $this->exchangeOptions['name'], $this->routingKey);
            }
        }
        $this->queueDeclared = true;
    }

    /**
     * @param $queueName
     * @param AMQPMessage $msg
     * @throws \Exception
     */
    public function processQueueMessage($queueName, AMQPMessage $msg)
    {
        if (!isset($this->queues[$queueName])) {
            throw new \Exception('Queue not found!');
        }
        $this->processMessageQueueCallback($msg, $queueName, $this->queues[$queueName]['callback']);
    }

    public function stopConsuming()
    {
        foreach ($this->queues as $name => $options) {
            $this->getChannel()->basic_cancel($this->getQueueConsumerTag($name), false, true);
        }
    }
}
