<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use yii\helpers\Console;

abstract class BaseRabbitMQ
{
    protected $conn;

    protected $autoDeclare;

    protected $ch;

    protected $logger;

    protected $exchangeDeclared = false;

    protected $queueDeclared = false;

    protected $routingKey = '';

    /**
     * @param AbstractConnection $conn
     */
    public function __construct(AbstractConnection $conn, bool $autoDeclare)
    {
        $this->conn = $conn;
        $this->autoDeclare = $autoDeclare;
        if ($conn->connectOnConstruct()) {
            $this->getChannel();
        }
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
