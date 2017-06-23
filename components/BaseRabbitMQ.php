<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use yii\helpers\Console;

abstract class BaseRabbitMQ
{
    const CONNECTION_SERVICE_NAME = 'rabbit_mq.connection.%s';
    const CONSUMER_SERVICE_NAME = 'rabbit_mq.consumer.%s';
    const MULTIPLE_CONSUMER_SERVICE_NAME = 'rabbit_mq.multiple_consumer.%s';
    const PRODUCER_SERVICE_NAME = 'rabbit_mq.producer.%s';
    const BINDING_SERVICE_NAME = 'rabbit_mq.binding.%s';

    protected $conn;

    protected $ch;

    protected $consumerTag;

    protected $logger;

    protected $exchangeDeclared = false;

    protected $queueDeclared = false;

    protected $routingKey = '';

    protected $autoSetupFabric = true;

    protected $basicProperties = [
        'content_type'  => 'text/plain',
        'delivery_mode' => 2,
    ];

    protected $exchangeOptions = [
        'passive' => false,
        'durable' => false,
        'auto_delete' => true,
        'internal' => false,
        'nowait' => false,
        'arguments' => null,
        'ticket' => null,
        'declare' => true,
    ];

    protected $queueOptions = [
        'name' => '',
        'passive' => false,
        'durable' => false,
        'exclusive' => false,
        'auto_delete' => true,
        'nowait' => false,
        'arguments' => null,
        'ticket' => null,
        'declare' => true,
    ];

    /**
     * @param AbstractConnection   $conn
     */
    public function __construct(AbstractConnection $conn)
    {
        $this->conn = $conn;
        if (!($conn instanceof AMQPLazyConnection)) {
            $this->getChannel();
        }
        $this->consumerTag = empty($consumerTag) ? sprintf('PHPPROCESS_%s_%s', gethostname(), getmypid()) : $consumerTag;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close()
    {
        if ($this->ch) {
            try {
                $this->ch->close();
            } catch (\Exception $e) {
                // ignore on shutdown
            }
        }
        if ($this->conn && $this->conn->isConnected()) {
            try {
                $this->conn->close();
            } catch (\Exception $e) {
                // ignore on shutdown
            }
        }
    }

    public function reconnect()
    {
        if (!$this->conn->isConnected()) {
            return;
        }
        $this->conn->reconnect();
    }

    /**
     * @return AMQPChannel
     */
    public function getChannel()
    {
        if (empty($this->ch) || null === $this->ch->getChannelId()) {
            $this->ch = $this->conn->channel();
        }
        return $this->ch;
    }

    /**
     * @param  AMQPChannel $ch
     *
     * @return void
     */
    public function setChannel(AMQPChannel $ch)
    {
        $this->ch = $ch;
    }

    /**
     * @return array
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param array $logger
     */
    public function setLogger(array $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @throws \InvalidArgumentException
     * @param  array                     $options
     * @return void
     */
    public function setExchangeOptions(array $options = [])
    {
        if (!isset($options['name'])) {
            throw new \InvalidArgumentException('You must provide an exchange name');
        }
        if (empty($options['type'])) {
            throw new \InvalidArgumentException('You must provide an exchange type');
        }
        $this->exchangeOptions = array_merge($this->exchangeOptions, $options);
    }

    /**
     * @param  array $options
     * @return void
     */
    public function setQueueOptions(array $options = [])
    {
        $this->queueOptions = array_merge($this->queueOptions, $options);
    }

    /**
     * @param  string $routingKey
     * @return void
     */
    public function setRoutingKey($routingKey)
    {
        $this->routingKey = $routingKey;
    }

    public function setupFabric()
    {
        if (!$this->exchangeDeclared) {
            $this->exchangeDeclare();
        }
        if (!$this->queueDeclared) {
            $this->queueDeclare();
        }
    }

    /**
     * disables the automatic SetupFabric when using a consumer or producer
     */
    public function disableAutoSetupFabric()
    {
        $this->autoSetupFabric = false;
    }

    /**
     * Declares exchange
     */
    protected function exchangeDeclare()
    {
        if ($this->exchangeOptions['declare']) {
            $this->getChannel()->exchange_declare(
                $this->exchangeOptions['name'],
                $this->exchangeOptions['type'],
                $this->exchangeOptions['passive'],
                $this->exchangeOptions['durable'],
                $this->exchangeOptions['auto_delete'],
                $this->exchangeOptions['internal'],
                $this->exchangeOptions['nowait'],
                $this->exchangeOptions['arguments'],
                $this->exchangeOptions['ticket']
            );
            $this->exchangeDeclared = true;
        }
    }

    /**
     * Declares queue, creates if needed
     */
    protected function queueDeclare()
    {
        if ($this->queueOptions['declare']) {
            list($queueName,,) = $this->getChannel()->queue_declare(
                $this->queueOptions['name'],
                $this->queueOptions['passive'],
                $this->queueOptions['durable'],
                $this->queueOptions['exclusive'],
                $this->queueOptions['auto_delete'],
                $this->queueOptions['nowait'],
                $this->queueOptions['arguments'],
                $this->queueOptions['ticket']
            );
            if (isset($this->queueOptions['routing_keys']) && count($this->queueOptions['routing_keys']) > 0) {
                foreach ($this->queueOptions['routing_keys'] as $routingKey) {
                    $this->queueBind($queueName, $this->exchangeOptions['name'], $routingKey);
                }
            } else {
                $this->queueBind($queueName, $this->exchangeOptions['name'], $this->routingKey);
            }
            $this->queueDeclared = true;
        }
    }

    /**
     * Binds queue to an exchange
     *
     * @param string $queue
     * @param string $exchange
     * @param string $routing_key
     */
    protected function queueBind($queue, $exchange, $routing_key)
    {
        // queue binding is not permitted on the default exchange
        if ('' !== $exchange) {
            $this->getChannel()->queue_bind($queue, $exchange, $routing_key);
        }
    }

    /**
     * Print message to console
     * @param $message
     * @param $color
     * @return bool|int
     */
    public function stdout($message, $color = Console::FG_YELLOW)
    {
        if (Console::streamSupportsAnsiColors(\STDOUT)) {
            $message = Console::ansiFormat($message, [$color]) . "\n";
        }

        return Console::stdout($message);
    }
}
