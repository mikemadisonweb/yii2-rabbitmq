<?php
namespace mikemadisonweb\rabbitmq;

use mikemadisonweb\rabbitmq\components\AbstractConnectionFactory;
use mikemadisonweb\rabbitmq\components\BaseRabbitMQ;
use mikemadisonweb\rabbitmq\components\Consumer;
use mikemadisonweb\rabbitmq\components\ConsumerInterface;
use mikemadisonweb\rabbitmq\components\MultipleConsumer;
use mikemadisonweb\rabbitmq\components\Producer;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Configuration extends Component
{
    const CONNECTION_CLASS = '\PhpAmqpLib\Connection\AMQPLazyConnection';

    public $logger = [];
    public $connections = [];
    public $producers = [];
    public $consumers = [];
    public $multipleConsumers = [];
    private $isLoaded = false;

    /**
     * Register all required services to service container
     */
    public function load()
    {
        if ($this->isAlreadyLoaded()) {
            return;
        }
        $this->loadConnections();
        $this->loadProducers();
        $this->loadConsumers();
        $this->loadMultipleConsumers();
        $this->isLoaded = true;
    }

    /**
     * Set connections to service container
     */
    protected function loadConnections()
    {
        foreach ($this->connections as $key => $parameters) {
            $serviceAlias = sprintf(BaseRabbitMQ::CONNECTION_SERVICE_NAME, $key);
            \Yii::$container->set($serviceAlias, function () use ($parameters) {
                $factory = new AbstractConnectionFactory(self::CONNECTION_CLASS, $parameters);
                return $factory->createConnection();
            });
        }
    }

    /**
     * Set producers to service container
     */
    protected function loadProducers()
    {
        foreach ($this->producers as $key => $parameters) {
            $serviceAlias = sprintf(BaseRabbitMQ::PRODUCER_SERVICE_NAME, $key);
            \Yii::$container->set($serviceAlias, function () use ($key, $parameters) {
                if (!isset($parameters['connection'])) {
                    throw new InvalidConfigException("Please provide `connection` option for producer `{$key}`.");
                }
                $connection = \Yii::$container->get(sprintf('rabbit_mq.connection.%s', $parameters['connection']));
                $producer = new Producer($connection);

                //this producer doesn't define an exchange -> using AMQP Default
                if (!isset($parameters['exchange_options'])) {
                    $parameters['exchange_options'] = [];
                }
                \Yii::$container->invoke([$producer, 'setExchangeOptions'], [$parameters['exchange_options']]);

                //this producer doesn't define a queue -> using AMQP Default
                if (!isset($parameters['queue_options'])) {
                    $parameters['queue_options'] = [];
                }
                \Yii::$container->invoke([$producer, 'setQueueOptions'], [$parameters['queue_options']]);

                if (isset($parameters['auto_setup_fabric']) && !$parameters['auto_setup_fabric']) {
                    \Yii::$container->invoke([$producer, 'disableAutoSetupFabric']);
                }

                $this->logger = array_replace($this->getDefaultLoggerOptions(), $this->logger);
                \Yii::$container->invoke([$producer, 'setLogger'], [$this->logger]);

                return $producer;
            });
        }
    }

    /**
     * Set consumers(one instance per queue) to service container
     */
    protected function loadConsumers()
    {
        foreach ($this->consumers as $key => $parameters) {
            $serviceAlias = sprintf(BaseRabbitMQ::CONSUMER_SERVICE_NAME, $key);
            \Yii::$container->set($serviceAlias, function () use ($key, $parameters) {
                if (!isset($parameters['connection'])) {
                    throw new InvalidConfigException("Please provide `connection` option for consumer `{$key}`.");
                }
                $connection = \Yii::$container->get(sprintf('rabbit_mq.connection.%s', $parameters['connection']));
                $consumer = new Consumer($connection);

                // if consumer doesn't define an exchange -> using AMQP Default
                if (!isset($parameters['exchange_options'])) {
                    $parameters['exchange_options'] = [];
                }
                \Yii::$container->invoke([$consumer, 'setExchangeOptions'], [$parameters['exchange_options']]);

                // if consumer doesn't define a queue -> using AMQP Default
                if (!isset($parameters['queue_options'])) {
                    $parameters['queue_options'] = [];
                }
                \Yii::$container->invoke([$consumer, 'setQueueOptions'], [$parameters['queue_options']]);

                if (!isset($parameters['callback'])) {
                    throw new InvalidConfigException("Callback not configured for `{$key}`` queue consumer.");
                }
                $callbackClass = $this->getCallbackClass($parameters['callback']);
                \Yii::$container->invoke([$consumer, 'setCallback'], [[$callbackClass, 'execute']]);
                if (isset($parameters['qos_options'])) {
                    \Yii::$container->invoke([$consumer, 'setQosOptions'], [
                        $parameters['qos_options']['prefetch_size'],
                        $parameters['qos_options']['prefetch_count'],
                        $parameters['qos_options']['global'],
                    ]);
                }

                if (isset($parameters['auto_setup_fabric']) && !$parameters['auto_setup_fabric']) {
                    \Yii::$container->invoke([$consumer, 'disableAutoSetupFabric']);
                }

                if (isset($parameters['idle_timeout'])) {
                    \Yii::$container->invoke('setIdleTimeout', [$parameters['idle_timeout']]);
                }
                if (isset($parameters['idle_timeout_exit_code'])) {
                    \Yii::$container->invoke('setIdleTimeoutExitCode', [$parameters['idle_timeout_exit_code']]);
                }

                $this->logger = array_replace($this->getDefaultLoggerOptions(), $this->logger);
                \Yii::$container->invoke([$consumer, 'setLogger'], [$this->logger]);

                return $consumer;
            });
        }
    }

    /**
     * Set consumers(one instance per multiple queues) to service container
     */
    protected function loadMultipleConsumers()
    {
        foreach ($this->multipleConsumers as $key => $parameters) {
            $serviceAlias = sprintf(BaseRabbitMQ::MULTIPLE_CONSUMER_SERVICE_NAME, $key);
            \Yii::$container->set($serviceAlias, function () use ($key, $parameters) {
                $queues = [];

                if (!isset($parameters['connection'])) {
                    throw new InvalidConfigException("Please provide `connection` option for consumer `{$key}`.");
                }
                $connection = \Yii::$container->get(sprintf('rabbit_mq.connection.%s', $parameters['connection']));
                $multipleConsumer = new MultipleConsumer($connection);

                // if consumer doesn't define an exchange -> using AMQP Default
                if (!isset($parameters['exchange_options'])) {
                    $parameters['exchange_options'] = [];
                }
                \Yii::$container->invoke([$multipleConsumer, 'setExchangeOptions'], [$parameters['exchange_options']]);

                if (empty($parameters['queues'])) {
                    throw new InvalidConfigException(
                        "Error on loading {$key} multiple consumer. 'queues' parameter should be defined."
                    );
                }
                foreach ($parameters['queues'] as $queueName => $queueOptions) {
                    // Rearrange array for consistency
                    $queues[$queueOptions['name']] = $queueOptions;
                    if (!isset($queueOptions['callback'])) {
                        throw new InvalidConfigException("Callback not configured for `{$queueName}`` queue consumer.");
                    }
                    $callbackClass = $this->getCallbackClass($queueOptions['callback']);
                    $queues[$queueOptions['name']]['callback'] = [$callbackClass, 'execute'];
                }
                \Yii::$container->invoke([$multipleConsumer, 'setQueues'], [$queues]);

                if (isset($parameters['qos_options'])) {
                    \Yii::$container->invoke([$multipleConsumer, 'setQosOptions'], [
                        $parameters['qos_options']['prefetch_size'],
                        $parameters['qos_options']['prefetch_count'],
                        $parameters['qos_options']['global'],
                    ]);
                }

                if (isset($parameters['idle_timeout'])) {
                    \Yii::$container->invoke([$multipleConsumer, 'setIdleTimeout'], [
                        $parameters['idle_timeout'],
                    ]);
                }

                if (isset($parameters['idle_timeout_exit_code'])) {
                    \Yii::$container->invoke([$multipleConsumer, 'setIdleTimeoutExitCode'], [
                        $parameters['idle_timeout_exit_code'],
                    ]);
                }

                if (isset($parameters['auto_setup_fabric']) && !$parameters['auto_setup_fabric']) {
                    \Yii::$container->invoke([$multipleConsumer, 'disableAutoSetupFabric']);
                }

                $this->logger = array_replace($this->getDefaultLoggerOptions(), $this->logger);
                \Yii::$container->invoke([$multipleConsumer, 'setLogger'], [$this->logger]);

                return $multipleConsumer;
            });
        }
    }

    /**
     * Get default logger options
     *
     * @return array
     */
    protected function getDefaultLoggerOptions()
    {
        return [
            'enable' => true,
            'category' => 'application',
            'print_console' => false,
            'system_memory' => false,
        ];
    }

    /**
     * @return bool
     */
    private function isAlreadyLoaded()
    {
        return $this->isLoaded;
    }

    /**
     * @param $callbackName
     * @return object
     * @throws InvalidConfigException
     */
    private function getCallbackClass($callbackName)
    {
        if (!is_string($callbackName)) {
            throw new InvalidConfigException("Consumer `callback` parameter value should be a class name or service name in DI container.");
        }
        if (!class_exists($callbackName)) {
            $callbackClass = \Yii::$container->get($callbackName);
        } else {
            $callbackClass = new $callbackName();
        }
        if (!($callbackClass instanceof ConsumerInterface)) {
            throw new InvalidConfigException("{$callbackName} should implement ConsumerInterface.");
        }

        return $callbackClass;
    }
}
