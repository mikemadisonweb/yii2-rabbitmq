<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use yii\helpers\ArrayHelper;

class Routing
{
    protected $queues = [];
    protected $exchanges = [];
    protected $bindings = [];

    private $exchangesDeclared = [];
    private $queuesDeclared = [];
    private $isDeclared = false;

    /**
     * @param array $queues
     */
    public function setQueues(array $queues)
    {
        $this->queues = $this->arrangeByName($queues);
    }

    /**
     * @param array $exchanges
     */
    public function setExchanges(array $exchanges)
    {
        $this->exchanges = $this->arrangeByName($exchanges);
    }

    /**
     * @param array $bindings
     */
    public function setBindings(array $bindings)
    {
        $this->bindings = $bindings;
    }

    /**
     * Declare all routing entries defined by configuration
     * @param AbstractConnection $conn
     * @return bool
     * @throws \RuntimeException
     */
    public function declareAll(AbstractConnection $conn) : bool
    {
        if (!$this->isDeclared) {
            foreach (array_keys($this->exchanges) as $name) {
                $this->declareExchange($conn, $name);
            }
            foreach (array_keys($this->queues) as $name) {
                $this->declareQueue($conn, $name);
            }
            $this->declareBindings($conn);
            $this->isDeclared = true;

            return true;
        }

        return false;
    }

    /**
     * @param AbstractConnection $conn
     * @param $queueName
     * @throws \RuntimeException
     */
    public function declareQueue(AbstractConnection $conn, string $queueName)
    {
        if(!isset($this->queues[$queueName])) {
            throw new \RuntimeException("Queue `{$queueName}` is not configured.");
        }
        $queue = $this->queues[$queueName];
        if (!isset($this->queuesDeclared[$queueName])) {
            if (ArrayHelper::isAssociative($queue)) {
                $conn->channel()->queue_declare(
                    $queue['name'],
                    $queue['passive'],
                    $queue['durable'],
                    $queue['exclusive'],
                    $queue['auto_delete'],
                    $queue['nowait'],
                    $queue['arguments'],
                    $queue['ticket']
                );
            } else {
                foreach ($queue as $q) {
                    $conn->channel()->queue_declare(
                        $q['name'],
                        $q['passive'],
                        $q['durable'],
                        $q['exclusive'],
                        $q['auto_delete'],
                        $q['nowait'],
                        $q['arguments'],
                        $q['ticket']
                    );
                }
            }
            $this->queuesDeclared[$queueName] = true;
        }
    }

    /**
     * Create bindings
     * @param AbstractConnection $conn
     */
    public function declareBindings(AbstractConnection $conn)
    {
        foreach ($this->bindings as $binding) {
            if (isset($binding['queue'])) {
                $this->bindExchangeToQueue($conn, $binding);
            } else {
                $this->bindExchangeToExchange($conn, $binding);
            }
        }
    }

    /**
     * Create exchange-to-queue binding
     * @param AbstractConnection $conn
     * @param array $binding
     */
    public function bindExchangeToQueue(AbstractConnection $conn, array $binding)
    {
        if (isset($binding['routingKeys']) && count($binding['routingKeys']) > 0) {
            foreach ($binding['routingKeys'] as $routingKey) {
                // queue binding is not permitted on the default exchange
                if ('' !== $binding['exchange']) {
                    $conn->channel()->queue_bind($binding['queue'], $binding['exchange'], $routingKey);
                }
            }
        } else {
            // queue binding is not permitted on the default exchange
            if ('' !== $binding['exchange']) {
                $conn->channel()->queue_bind($binding['queue'], $binding['exchange']);
            }
        }
    }

    /**
     * Create exchange-to-exchange binding
     * @param AbstractConnection $conn
     * @param array $binding
     */
    public function bindExchangeToExchange(AbstractConnection $conn, array $binding)
    {
        if (isset($binding['routingKeys']) && count($binding['routingKeys']) > 0) {
            foreach ($binding['routingKeys'] as $routingKey) {
                // queue binding is not permitted on the default exchange
                if ('' !== $binding['exchange']) {
                    $conn->channel()->exchange_bind($binding['toExchange'], $binding['exchange'], $routingKey);
                }
            }
        } else {
            // queue binding is not permitted on the default exchange
            if ('' !== $binding['exchange']) {
                $conn->channel()->exchange_bind($binding['toExchange'], $binding['exchange']);
            }
        }
    }

    /**
     * @param AbstractConnection $conn
     * @param $exchangeName
     * @throws \RuntimeException
     */
    public function declareExchange(AbstractConnection $conn, string $exchangeName)
    {
        if(!isset($this->exchanges[$exchangeName])) {
            throw new \RuntimeException("Exchange `{$exchangeName}` is not configured.");
        }
        $exchange = $this->exchanges[$exchangeName];
        if (!isset($this->exchangesDeclared[$exchangeName])) {
            $conn->channel()->exchange_declare(
                $exchange['name'],
                $exchange['type'],
                $exchange['passive'],
                $exchange['durable'],
                $exchange['auto_delete'],
                $exchange['internal'],
                $exchange['nowait'],
                $exchange['arguments'],
                $exchange['ticket']
            );
            $this->exchangesDeclared[$exchangeName] = true;
        }
    }

    /**
     * Purge the queue
     * @param string $queueName
     * @throws \RuntimeException
     */
    public function purgeQueue(AbstractConnection $conn, string $queueName)
    {
        if (!isset($this->queues[$queueName])) {
            throw new \RuntimeException("Queue {$queueName} is not configured. Purge is aborted.");
        }
        $conn->channel()->queue_purge($queueName, true);
    }

    /**
     * Delete all configured queues and exchanges
     * @param AbstractConnection $conn
     * @throws \RuntimeException
     */
    public function deleteAll(AbstractConnection $conn)
    {
        foreach (array_keys($this->queues) as $name) {
            $this->deleteQueue($conn, $name);
        }
        foreach (array_keys($this->exchanges) as $name) {
            $this->deleteExchange($conn, $name);
        }
    }

    /**
     * Delete the queue
     * @param string $queueName
     * @throws \RuntimeException
     */
    public function deleteQueue(AbstractConnection $conn, string $queueName)
    {
        if (!isset($this->queues[$queueName])) {
            throw new \RuntimeException("Queue {$queueName} is not configured. Delete is aborted.");
        }
        $conn->channel()->queue_delete($queueName);
    }

    /**
     * Delete the queue
     * @param string $exchangeName
     * @throws \RuntimeException
     */
    public function deleteExchange(AbstractConnection $conn, string $exchangeName)
    {
        if (!isset($this->queues[$exchangeName])) {
            throw new \RuntimeException("Queue {$exchangeName} is not configured. Delete is aborted.");
        }
        $conn->channel()->exchange_delete($exchangeName);
    }

    /**
     * Checks whether exchange is already declared in broker
     * @param AbstractConnection $conn
     * @param string $exchangeName
     * @return bool
     */
    public function isExchangeExists(AbstractConnection $conn, string $exchangeName) : bool
    {
        try {
            $conn->channel()->exchange_declare($exchangeName, null, true);
        } catch (AMQPProtocolChannelException $e) {
            return false;
        }

        return true;
    }

    /**
     * Checks whether queue is already declared in broker
     * @param AbstractConnection $conn
     * @param string $queueName
     * @return bool
     */
    public function isQueueExists(AbstractConnection $conn, string $queueName) : bool
    {
        try {
            $conn->channel()->queue_declare($queueName, true);
        } catch (AMQPProtocolChannelException $e) {
            return false;
        }

        return true;
    }

    /**
     * @param array $unnamedArr
     * @return array
     */
    private function arrangeByName(array $unnamedArr) : array
    {
        $namedArr = [];
        foreach ($unnamedArr as $elem) {
            if('' === $elem['name']) {
                $namedArr[$elem['name']][] = $elem;
            } else {
                $namedArr[$elem['name']] = $elem;
            }
        }

        return $namedArr;
    }
}