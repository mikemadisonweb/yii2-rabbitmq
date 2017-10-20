<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Connection\AbstractConnection;
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
     * @param AbstractConnection $conn
     * @throws \RuntimeException
     */
    public function declareAll(AbstractConnection $conn)
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
        }
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