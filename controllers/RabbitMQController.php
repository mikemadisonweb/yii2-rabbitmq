<?php declare(strict_types=1);
declare(ticks=1);

namespace mikemadisonweb\rabbitmq\controllers;

use BadFunctionCallException;
use InvalidArgumentException;
use mikemadisonweb\rabbitmq\components\Consumer;
use mikemadisonweb\rabbitmq\components\Routing;
use mikemadisonweb\rabbitmq\Configuration;
use Yii;
use yii\base\Action;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * RabbitMQ extension functionality
 *
 * @package mikemadisonweb\rabbitmq\controllers
 */
class RabbitMQController extends Controller
{
    public $memoryLimit = 0;

    public $messagesLimit = 0;

    public $debug = false;

    public $withoutSignals = false;

    /**
     * @var Configuration
     */
    private $rabbitmq;

    public function init()
    {
        $this->rabbitmq = Yii::$app->rabbitmq;
    }

    protected $options = [
        'm' => 'messagesLimit',
        'l' => 'memoryLimit',
        'd' => 'debug',
        'w' => 'withoutSignals',
    ];

    /**
     * @param string $actionID
     *
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
     *
     * @return bool
     */
    public function beforeAction($event): bool
    {
        if (defined('AMQP_WITHOUT_SIGNALS') === false)
        {
            define('AMQP_WITHOUT_SIGNALS', $this->withoutSignals);
        }
        if (defined('AMQP_DEBUG') === false)
        {
            if ($this->debug === 'false')
            {
                $this->debug = false;
            }
            define('AMQP_DEBUG', (bool)$this->debug);
        }

        return parent::beforeAction($event);
    }

    /**
     * Run a consumer
     *
     * @param    string $name Consumer name
     *
     * @return   int
     * @throws \Throwable
     */
    public function actionConsume(string $name): int
    {
        $consumer = $this->rabbitmq->getConsumer($name);
        $this->validateConsumerOptions($consumer);
        if ((null !== $this->memoryLimit) && ctype_digit((string)$this->memoryLimit) && ($this->memoryLimit > 0))
        {
            $consumer->setMemoryLimit($this->memoryLimit);
        }
        $consumer->consume($this->messagesLimit);

        return ExitCode::OK;
    }

    /**
     * Restart consumer by name
     *
     * @param string $name
     * @return int
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\di\NotInstantiableException
     */
    public function actionRestartConsume(string $name): int
    {
        $consumer = $this->rabbitmq->getConsumer($name);
        $consumer->restartDaemon();
        return $this->actionConsume($name);
    }

    /**
     * Publish a message from STDIN to the queue
     *
     * @param string $producerName
     * @param string $exchangeName
     * @param string $routingKey
     *
     * @return int
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\di\NotInstantiableException
     */
    public function actionPublish(string $producerName, string $exchangeName, string $routingKey = ''): int
    {
        $producer = $this->rabbitmq->getProducer($producerName);
        $data = '';
        if (posix_isatty(STDIN))
        {
            $this->stderr(Console::ansiFormat("Please pipe in some data in order to send it.\n", [Console::FG_RED]));

            return ExitCode::UNSPECIFIED_ERROR;
        }
        while (!feof(STDIN))
        {
            $data .= fread(STDIN, 8192);
        }
        $producer->publish($data, $exchangeName, $routingKey);
        $this->stdout("Message was successfully published.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Create RabbitMQ exchanges, queues and bindings based on configuration
     *
     * @param string $connectionName
     *
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeclareAll(string $connectionName = Configuration::DEFAULT_CONNECTION_NAME): int
    {
        $routing = $this->getRouting($connectionName);
        $result  = $routing->declareAll();
        if ($result)
        {
            $this->stdout(
                Console::ansiFormat("All configured entries was successfully declared.\n", [Console::FG_GREEN])
            );

            return ExitCode::OK;
        }
        $this->stderr(Console::ansiFormat("No queues, exchanges or bindings configured.\n", [Console::FG_RED]));

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Create the exchange listed in configuration
     *
     * @param        $exchangeName
     * @param string $connectionName
     *
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeclareExchange(
        string $exchangeName,
        string $connectionName = Configuration::DEFAULT_CONNECTION_NAME
    ): int {
        $routing = $this->getRouting($connectionName);
        if ($routing->isExchangeExists($exchangeName))
        {
            $this->stderr(Console::ansiFormat("Exchange `{$exchangeName}` is already exists.\n", [Console::FG_RED]));

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $routing->declareExchange($exchangeName);
        $this->stdout(Console::ansiFormat("Exchange `{$exchangeName}` was declared.\n", [Console::FG_GREEN]));

        return ExitCode::OK;
    }

    /**
     * Create the queue listed in configuration
     *
     * @param        $queueName
     * @param string $connectionName
     *
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeclareQueue(
        string $queueName,
        string $connectionName = Configuration::DEFAULT_CONNECTION_NAME
    ): int {
        $routing = $this->getRouting($connectionName);
        if ($routing->isQueueExists($queueName))
        {
            $this->stderr(Console::ansiFormat("Queue `{$queueName}` is already exists.\n", [Console::FG_RED]));

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $routing->declareQueue($queueName);
        $this->stdout(Console::ansiFormat("Queue `{$queueName}` was declared.\n", [Console::FG_GREEN]));

        return ExitCode::OK;
    }

    /**
     * Delete all RabbitMQ exchanges and queues that is defined in configuration
     *
     * @param string $connection
     *
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeleteAll(string $connection = Configuration::DEFAULT_CONNECTION_NAME): int
    {
        if ($this->interactive)
        {
            $input = Console::prompt('Are you sure you want to delete all queues and exchanges?', ['default' => 'yes']);
            if ($input !== 'yes' && $input !== 'y')
            {
                $this->stderr(Console::ansiFormat("Aborted.\n", [Console::FG_RED]));

                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        $routing = $this->getRouting($connection);
        $routing->deleteAll();
        $this->stdout(Console::ansiFormat("All configured entries was deleted.\n", [Console::FG_GREEN]));

        return ExitCode::OK;
    }

    /**
     * Delete an exchange
     *
     * @param        $exchangeName
     * @param string $connectionName
     *
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeleteExchange(
        string $exchangeName,
        string $connectionName = Configuration::DEFAULT_CONNECTION_NAME
    ): int {
        if ($this->interactive)
        {
            $input = Console::prompt('Are you sure you want to delete that exchange?', ['default' => 'yes']);
            if ($input !== 'yes')
            {
                $this->stderr(Console::ansiFormat("Aborted.\n", [Console::FG_RED]));

                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        $routing = $this->getRouting($connectionName);
        $routing->deleteExchange($exchangeName);
        $this->stdout(Console::ansiFormat("Exchange `{$exchangeName}` was deleted.\n", [Console::FG_GREEN]));

        return ExitCode::OK;
    }

    /**
     * Delete a queue
     *
     * @param        $queueName
     * @param string $connectionName
     *
     * @return int
     * @throws \RuntimeException
     */
    public function actionDeleteQueue(
        string $queueName,
        string $connectionName = Configuration::DEFAULT_CONNECTION_NAME
    ): int {
        if ($this->interactive)
        {
            $input = Console::prompt('Are you sure you want to delete that queue?', ['default' => 'yes']);
            if ($input !== 'yes')
            {
                $this->stderr(Console::ansiFormat("Aborted.\n", [Console::FG_RED]));

                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $routing = $this->getRouting($connectionName);
        $routing->deleteQueue($queueName);
        $this->stdout(Console::ansiFormat("Queue `{$queueName}` was deleted.\n", [Console::FG_GREEN]));

        return ExitCode::OK;
    }

    /**
     * Delete all messages from the queue
     *
     * @param        $queueName
     * @param string $connectionName
     *
     * @return int
     * @throws \RuntimeException
     */
    public function actionPurgeQueue(
        string $queueName,
        string $connectionName = Configuration::DEFAULT_CONNECTION_NAME
    ): int {
        if ($this->interactive)
        {
            $input = Console::prompt(
                'Are you sure you want to delete all messages inside that queue?',
                ['default' => 'yes']
            );
            if ($input !== 'yes')
            {
                $this->stderr(Console::ansiFormat("Aborted.\n", [Console::FG_RED]));

                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $routing = $this->getRouting($connectionName);
        $routing->purgeQueue($queueName);
        $this->stdout(Console::ansiFormat("Queue `{$queueName}` was purged.\n", [Console::FG_GREEN]));

        return ExitCode::OK;
    }


    /**
     * @param string $connectionName
     * @return Routing|object|string
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\di\NotInstantiableException
     */
    private function getRouting(string $connectionName)
    {
        $conn = $this->rabbitmq->getConnection($connectionName);
        return $this->rabbitmq->getRouting($conn);
    }

    /**
     * Validate options passed by user
     *
     * @param Consumer $consumer
     */
    private function validateConsumerOptions(Consumer $consumer)
    {
        if (!AMQP_WITHOUT_SIGNALS && extension_loaded('pcntl'))
        {
            if (!function_exists('pcntl_signal'))
            {
                throw new BadFunctionCallException(
                    "Function 'pcntl_signal' is referenced in the php.ini 'disable_functions' and can't be called."
                );
            }

            pcntl_signal(SIGTERM, [$consumer, 'stopDaemon']);
            pcntl_signal(SIGINT, [$consumer, 'stopDaemon']);
            pcntl_signal(SIGHUP, [$consumer, 'restartDaemon']);
        }

        $this->messagesLimit = (int)$this->messagesLimit;
        $this->memoryLimit   = (int)$this->memoryLimit;
        if (!is_numeric($this->messagesLimit) || 0 > $this->messagesLimit)
        {
            throw new InvalidArgumentException('The -m option should be null or greater than 0');
        }
        if (!is_numeric($this->memoryLimit) || 0 > $this->memoryLimit)
        {
            throw new InvalidArgumentException('The -l option should be null or greater than 0');
        }
    }
}
