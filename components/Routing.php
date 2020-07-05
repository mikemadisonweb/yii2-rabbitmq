<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\components;

use mikemadisonweb\rabbitmq\exceptions\RuntimeException;
use PhpAmqpLib\Channel\AMQPChannel;
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
     * @var $conn AbstractConnection
     */
    private $conn;

    /**
     * @var $conn AMQPChannel
     */
    private $ch;

    /**
     * @param AbstractConnection $conn
     */
    public function __construct(AbstractConnection $conn)
    {
        $this->conn = $conn;
    }

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
     * @return bool
     * @throws RuntimeException
     */
    public function declareAll() : bool
    {
        if (!$this->isDeclared) {
            foreach (array_keys($this->exchanges) as $name) {
                $this->declareExchange($name);
            }
            foreach (array_keys($this->queues) as $name) {
                $this->declareQueue($name);
            }
            $this->declareBindings();
            $this->isDeclared = true;

            return true;
        }

        return false;
    }

    /**
     * @param $queueName
     * @throws RuntimeException
     */
    public function declareQueue(string $queueName)
    {
        if(!isset($this->queues[$queueName])) {
            throw new RuntimeException("Queue `{$queueName}` is not configured.");
        }

        $channel =
        $queue = $this->queues[$queueName];
        if (!isset($this->queuesDeclared[$queueName])) {
            if (ArrayHelper::isAssociative($queue)) {
                $this->getChannel()->queue_declare(
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
                    $this->getChannel()->queue_declare(
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
     */
    public function declareBindings()
    {
        foreach ($this->bindings as $binding) {
            if (isset($binding['queue'])) {
                $this->bindExchangeToQueue($binding);
            } else {
                $this->bindExchangeToExchange($binding);
            }
        }
    }

    /**
     * Create exchange-to-queue binding
     * @param array $binding
     */
    public function bindExchangeToQueue(array $binding)
    {
        if (isset($binding['routing_keys']) && count($binding['routing_keys']) > 0) {
            foreach ($binding['routing_keys'] as $routingKey) {
                // queue binding is not permitted on the default exchange
                if ('' !== $binding['exchange']) {
                    $this->getChannel()->queue_bind($binding['queue'], $binding['exchange'], $routingKey);
                }
            }
        } else {
            // queue binding is not permitted on the default exchange
            if ('' !== $binding['exchange']) {
                $this->getChannel()->queue_bind($binding['queue'], $binding['exchange']);
            }
        }
    }

    /**
     * Create exchange-to-exchange binding
     * @param array $binding
     */
    public function bindExchangeToExchange(array $binding)
    {
        if (isset($binding['routing_keys']) && count($binding['routing_keys']) > 0) {
            foreach ($binding['routing_keys'] as $routingKey) {
                // queue binding is not permitted on the default exchange
                if ('' !== $binding['exchange']) {
                    $this->getChannel()->exchange_bind($binding['to_exchange'], $binding['exchange'], $routingKey);
                }
            }
        } else {
            // queue binding is not permitted on the default exchange
            if ('' !== $binding['exchange']) {
                $this->getChannel()->exchange_bind($binding['to_exchange'], $binding['exchange']);
            }
        }
    }

    /**
     * @param $exchangeName
     * @throws RuntimeException
     */
    public function declareExchange(string $exchangeName)
    {
        if(!isset($this->exchanges[$exchangeName])) {
            throw new RuntimeException("Exchange `{$exchangeName}` is not configured.");
        }
        $exchange = $this->exchanges[$exchangeName];
        if (!isset($this->exchangesDeclared[$exchangeName])) {
            $this->getChannel()->exchange_declare(
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
     * @throws RuntimeException
     */
    public function purgeQueue(string $queueName)
    {
        if (!isset($this->queues[$queueName])) {
            throw new RuntimeException("Queue {$queueName} is not configured. Purge is aborted.");
        }
        $this->getChannel()->queue_purge($queueName, true);
    }

    /**
     * Delete all configured queues and exchanges
     * @throws RuntimeException
     */
    public function deleteAll()
    {
        foreach (array_keys($this->queues) as $name) {
            $this->deleteQueue($name);
        }
        foreach (array_keys($this->exchanges) as $name) {
            $this->deleteExchange($name);
        }
    }

    /**
     * Delete the queue
     * @param string $queueName
     * @throws RuntimeException
     */
    public function deleteQueue(string $queueName)
    {
        if (!isset($this->queues[$queueName])) {
            throw new RuntimeException("Queue {$queueName} is not configured. Delete is aborted.");
        }
        $this->getChannel()->queue_delete($queueName);
    }

    /**
     * Delete the queue
     * @param string $exchangeName
     * @throws RuntimeException
     */
    public function deleteExchange(string $exchangeName)
    {
        if (!isset($this->exchanges[$exchangeName])) {
            throw new RuntimeException("Exchange {$exchangeName} is not configured. Delete is aborted.");
        }
        $this->getChannel()->exchange_delete($exchangeName);
    }

    /**
     * Checks whether exchange is already declared in broker
     * @param string $exchangeName
     * @return bool
     */
    public function isExchangeExists(string $exchangeName) : bool
    {
        try {
            $this->getChannel()->exchange_declare($exchangeName, null, true);
        } catch (AMQPProtocolChannelException $e) {
            return false;
        }

        return true;
    }

    /**
     * Checks whether queue is already declared in broker
     * @param string $queueName
     * @return bool
     */
    public function isQueueExists(string $queueName) : bool
    {
        try {
            $this->getChannel()->queue_declare($queueName, true);
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

    /**
     * @return AMQPChannel
     */
    private function getChannel()
    {
        if (empty($this->ch) || null === $this->ch->getChannelId()) {
            $this->ch = $this->conn->channel();
        }

        return $this->ch;
    }
}
