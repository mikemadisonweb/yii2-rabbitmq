<?php

namespace mikemadisonweb\rabbitmq\controllers;

use mikemadisonweb\rabbitmq\components\BaseRabbitMQ;
use mikemadisonweb\rabbitmq\components\Consumer;
use yii\console\Controller;

/**
 * RabbitMQ consumer functionality
 * @package mikemadisonweb\rabbitmq\controllers
 */
class ConsumerController extends Controller
{
    public $route;
    public $memoryLimit = 0;
    public $messagesLimit = 0;
    public $debug = false;
    public $withoutSignals = false;

    protected $consumer;

    protected $options = [
        'm' => 'messagesLimit',
        'l' => 'memoryLimit',
        'r' => 'route',
        'd' => 'debug',
        'w' => 'withoutSignals',
    ];

    /**
     * @param string $actionID
     * @return array
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), array_values($this->options));
    }

    /**
     * @return array
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), $this->options);
    }

    /**
     * Force stop consumer
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

    public function init()
    {
        \Yii::$app->rabbitmq->load();
    }

    /**
     * @param \yii\base\Action $event
     * @return bool
     */
    public function beforeAction($event)
    {
        $this->setOptions();
        return parent::beforeAction($event);
    }

    /**
     * Run consumer(one instance per queue)
     * @param    string    $name    Consumer name
     * @return   int|null
     */
    public function actionSingle($name)
    {
        $serviceName = sprintf(BaseRabbitMQ::CONSUMER_SERVICE_NAME, $name);
        $this->consumer = $this->getConsumer($serviceName);

        return $this->consumer->consume($this->messagesLimit);
    }

    /**
     * Run consumer(one instance per multiple queues)
     * @param    string    $name    Consumer name
     * @return   int|null
     */
    public function actionMultiple($name)
    {
        $serviceName = sprintf(BaseRabbitMQ::MULTIPLE_CONSUMER_SERVICE_NAME, $name);
        $this->consumer = $this->getConsumer($serviceName);

        return $this->consumer->consume($this->messagesLimit);
    }

    /**
     * Setup RabbitMQ exchanges and queues based on configuration
     */
    public function actionSetupFabric()
    {
        $definitions = \Yii::$container->getDefinitions();
        foreach ($definitions as $definition) {
            if (is_callable($definition)) {
                $instance = $definition();
                if ($instance instanceof BaseRabbitMQ) {
                    $instance->setupFabric();
                }
            }
        }
    }

    /**
     * Set options passed by user
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

    /**
     * @param  string   $serviceName
     * @return Consumer
     */
    private function getConsumer($serviceName)
    {
        $consumer = \Yii::$container->get($serviceName);
        if ((null !== $this->memoryLimit) && ctype_digit((string)$this->memoryLimit) && ($this->memoryLimit > 0)) {
            $consumer->setMemoryLimit($this->memoryLimit);
        }
        if (null !== $this->route) {
            $consumer->setRoutingKey($this->route);
        }

        return $consumer;
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