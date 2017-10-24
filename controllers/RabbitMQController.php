<?php

namespace mikemadisonweb\rabbitmq\controllers;

use mikemadisonweb\rabbitmq\components\Consumer;
use mikemadisonweb\rabbitmq\components\Producer;
use mikemadisonweb\rabbitmq\components\Routing;
use mikemadisonweb\rabbitmq\Configuration;
use yii\base\Action;
use yii\console\Controller;
use yii\helpers\Console;

/**
 * RabbitMQ extension functionality
 * @package mikemadisonweb\rabbitmq\controllers
 */
class RabbitMQController extends Controller
{
    public $memoryLimit = 0;
    public $messagesLimit = 0;
    public $debug = false;
    public $withoutSignals = false;

    /**
     * @var $routing Routing
     */
    protected $routing;
    /**
     * @var $consumer Consumer
     */
    protected $consumer;

    protected $options = [
        'm' => 'messagesLimit',
        'l' => 'memoryLimit',
        'd' => 'debug',
        'w' => 'withoutSignals',
    ];

    /**
     * @param string $actionID
     * @return array
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), array_values($this->options));
    }

    /**
     * @return array
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), $this->options);
    }

    /**
     * @param Action $event
     * @return bool
     */
    public function beforeAction($event): bool
    {
        $this->setOptions();
        $this->routing = \Yii::$app->rabbitmq->getRouting();

        return parent::beforeAction($event);
    }

    /**
     * Run a consumer
     * @param    string $consumer Consumer name
     * @return   int
     * @throws \Exception
     * @throws \Error
     * @throws \RuntimeException
     */
    public function actionConsume(string $consumer) : int
    {
        $this->consumer = \Yii::$app->rabbitmq->getConsumer($consumer);
        if ((null !== $this->memoryLimit) && ctype_digit((string)$this->memoryLimit) && ($this->memoryLimit > 0)) {
            $this->consumer->setMemoryLimit($this->memoryLimit);
        }
        $this->consumer->tagName($consumer);

        return $this->consumer->consume($this->messagesLimit);
    }

    /**
     * Publish a message from STDIN to the queue
     * @param $producer
     * @param $exchange
     * @param string $routingKey
     * @return int
     */
    public function actionPublish(string $producer, string $exchange, string $routingKey = '') : int
    {
        /**
         * @var $producer Producer
         */
        $producer = \Yii::$app->rabbitmq->getProducer($producer);
        $data = '';
        while (!feof(STDIN)) {
            $data .= fread(STDIN, 8192);
        }
        $producer->publish($data, $exchange, $routingKey);
        $this->stdout("Message was successfully published.\n", Console::FG_GREEN);
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Create RabbitMQ exchanges, queues and bindings based on configuration
     * @param string $connection
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeclareAll(string $connection = Configuration::DEFAULT_CONNECTION_NAME) : int
    {
        $conn = \Yii::$app->rabbitmq->getConnection($connection);
        $result = $this->routing->declareAll($conn);
        if ($result) {
            $this->stdout(Console::ansiFormat("All configured entries was successfully declared.\n", [Console::FG_GREEN]));
            return self::EXIT_CODE_NORMAL;
        }
        $this->stderr(Console::ansiFormat("No queues, exchanges or bindings configured.\n", [Console::FG_RED]));
        return self::EXIT_CODE_ERROR;
    }

    /**
     * Create the exchange listed in configuration
     * @param $exchange
     * @param string $connectionName
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeclareExchange(string $exchange, string $connectionName = Configuration::DEFAULT_CONNECTION_NAME) : int
    {
        $conn = \Yii::$app->rabbitmq->getConnection($connectionName);
        if ($this->routing->isExchangeExists($conn, $exchange)) {
            $this->stderr(Console::ansiFormat("Exchange `{$exchange}` is already exists.\n", [Console::FG_RED]));
            return self::EXIT_CODE_ERROR;
        }
        $this->routing->declareExchange($conn, $exchange);
        $this->stdout(Console::ansiFormat("Exchange `{$exchange}` was declared.\n", [Console::FG_GREEN]));
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Create the queue listed in configuration
     * @param $queue
     * @param string $connectionName
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeclareQueue(string $queue, string $connectionName = Configuration::DEFAULT_CONNECTION_NAME) : int
    {
        $conn = \Yii::$app->rabbitmq->getConnection($connectionName);
        if ($this->routing->isQueueExists($conn, $queue)) {
            $this->stderr(Console::ansiFormat("Queue `{$queue}` is already exists.\n", [Console::FG_RED]));
            return self::EXIT_CODE_ERROR;
        }
        $this->routing->declareQueue($conn, $queue);
        $this->stdout(Console::ansiFormat("Queue `{$queue}` was declared.\n", [Console::FG_GREEN]));
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Delete all RabbitMQ exchanges and queues that is defined in configuration
     * @param string $connection
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeleteAll(string $connection = Configuration::DEFAULT_CONNECTION_NAME) : int
    {
        if ($this->interactive) {
            $input = Console::prompt('Are you sure you want to delete all queues and exchanges?', ['default'=>'yes']);
            if ($input !== 'yes') {
                $this->stderr(Console::ansiFormat("Aborted.\n", [Console::FG_RED]));
                return self::EXIT_CODE_ERROR;
            }
        }
        $conn = \Yii::$app->rabbitmq->getConnection($connection);
        $this->routing->deleteAll($conn);
        $this->stdout(Console::ansiFormat("All configured entries was deleted.\n", [Console::FG_GREEN]));
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Delete an exchange
     * @param $exchange
     * @param string $connectionName
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeleteExchange(string $exchange, string $connectionName = Configuration::DEFAULT_CONNECTION_NAME) : int
    {
        if ($this->interactive) {
            $input = Console::prompt('Are you sure you want to delete that exchange?', ['default'=>'yes']);
            if ($input !== 'yes') {
                $this->stderr(Console::ansiFormat("Aborted.\n", [Console::FG_RED]));
                return self::EXIT_CODE_ERROR;
            }
        }
        $conn = \Yii::$app->rabbitmq->getConnection($connectionName);
        $this->routing->deleteExchange($conn, $exchange);
        $this->stdout(Console::ansiFormat("Exchange `{$exchange}` was deleted.\n", [Console::FG_GREEN]));
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Delete a queue
     * @param $queue
     * @param string $connectionName
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeleteQueue(string $queue, string $connectionName = Configuration::DEFAULT_CONNECTION_NAME) : int
    {
        if ($this->interactive) {
            $input = Console::prompt('Are you sure you want to delete that queue?', ['default'=>'yes']);
            if ($input !== 'yes') {
                $this->stderr(Console::ansiFormat("Aborted.\n", [Console::FG_RED]));
                return self::EXIT_CODE_ERROR;
            }
        }

        $conn = \Yii::$app->rabbitmq->getConnection($connectionName);
        $this->routing->deleteQueue($conn, $queue);
        $this->stdout(Console::ansiFormat("Queue `{$queue}` was deleted.\n", [Console::FG_GREEN]));
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Delete all messages from the queue
     * @param $queue
     * @param string $connectionName
     * @return int
     * @throws \RuntimeException
     */
    public function actionPurgeQueue(string $queue, string $connectionName = Configuration::DEFAULT_CONNECTION_NAME) : int
    {
        $conn = \Yii::$app->rabbitmq->getConnection($connectionName);
        $this->routing->purgeQueue($conn, $queue);
        $this->stdout(Console::ansiFormat("Queue `{$queue}` was purged.\n", [Console::FG_GREEN]));
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Force stop the consumer
     */
    public function stopConsumer()
    {
        if ($this->consumer instanceof Consumer) {
            // Process current message, then halt consumer
            $this->consumer->forceStopConsumer();
            // Close connection and exit if waiting for messages
            try {
                $this->consumer->stopConsuming();
            } catch (\Exception $e) {
                \Yii::error($e);
            }
            $this->stdout("Daemon stopped by user.\n");
            exit(0);
        }
    }

    public function restartConsumer()
    {
        // TODO: Implement restarting of consumer
    }

    /**
     * Set options passed by user
     * @throws \InvalidArgumentException
     * @throws \BadFunctionCallException
     */
    private function setOptions()
    {
        if (defined('AMQP_WITHOUT_SIGNALS') === false) {
            define('AMQP_WITHOUT_SIGNALS', $this->withoutSignals);
        }
        if (!AMQP_WITHOUT_SIGNALS && extension_loaded('pcntl')) {
            if (!function_exists('pcntl_signal')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal' is referenced in the php.ini 'disable_functions' and can't be called.");
            }
            pcntl_signal(SIGTERM, [$this, 'stopConsumer']);
            pcntl_signal(SIGINT, [$this, 'stopConsumer']);
            pcntl_signal(SIGHUP, [$this, 'restartConsumer']);
        }
        $this->setDebug();

        $this->messagesLimit = (int)$this->messagesLimit;
        $this->memoryLimit = (int)$this->memoryLimit;
        if (!is_numeric($this->messagesLimit) || 0 > $this->messagesLimit) {
            throw new \InvalidArgumentException('The -m option should be null or greater than 0');
        }
        if (!is_numeric($this->memoryLimit) || 0 > $this->memoryLimit) {
            throw new \InvalidArgumentException('The -l option should be null or greater than 0');
        }
    }

    private function setDebug()
    {
        if (defined('AMQP_DEBUG') === false) {
            if ($this->debug === 'false') {
                $this->debug = false;
            }
            define('AMQP_DEBUG', (bool)$this->debug);
        }
    }
}