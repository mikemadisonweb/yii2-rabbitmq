<?php
namespace mikemadisonweb\rabbitmq;

use mikemadisonweb\rabbitmq\components\AbstractConnectionFactory;
use mikemadisonweb\rabbitmq\components\BaseRabbitMQ;
use mikemadisonweb\rabbitmq\components\Consumer;
use mikemadisonweb\rabbitmq\components\ConsumerInterface;
use mikemadisonweb\rabbitmq\components\MultipleConsumer;
use mikemadisonweb\rabbitmq\components\Producer;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Configuration extends Component
{
    public $logger = [];
    public $connections = [];
    public $producers = [];
    public $consumers = [];
    public $multipleConsumers = [];

    /**
     * Register all required services to service container
     */
    public function load()
    {
        $this->loadConnections();
        $this->loadProducers();
        $this->loadConsumers();
        $this->loadMultipleConsumers();
    }

    /**
     * Set connections to service container
     */
    protected function loadConnections()
    {
        foreach ($this->connections as $key => $parameters) {
            $serviceAlias = sprintf(BaseRabbitMQ::CONNECTION_SERVICE_NAME, $key);
            \Yii::$container->set($serviceAlias, function () use ($parameters) {
                $factory = new AbstractConnectionFactory(AMQPLazyConnection::class, $parameters);
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
                $parameters['exchange_options'] = array_replace($this->getDefaultExchangeOptions(), $parameters['exchange_options']);
                \Yii::$container->invoke([$producer, 'setExchangeOptions'], [$parameters['exchange_options']]);

                //this producer doesn't define a queue -> using AMQP Default
                if (!isset($parameters['queue_options'])) {
                    $parameters['queue_options'] = [];
                }
                $parameters['queue_options'] = array_replace($this->getDefaultQueueOptions(), $parameters['queue_options']);
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
                $parameters['exchange_options'] = array_replace($this->getDefaultExchangeOptions(), $parameters['exchange_options']);
                \Yii::$container->invoke([$consumer, 'setExchangeOptions'], [$parameters['exchange_options']]);

                // if consumer doesn't define a queue -> using AMQP Default
                if (!isset($parameters['queue_options'])) {
                    $parameters['queue_options'] = [];
                }
                $parameters['queue_options'] = array_replace($this->getDefaultQueueOptions(), $parameters['queue_options']);
                \Yii::$container->invoke([$consumer, 'setQueueOptions'], [$parameters['queue_options']]);

                if (!isset($parameters['callback']) || !class_exists($parameters['callback'])) {
                    throw new InvalidConfigException("Please provide valid class name for consumer `{$key}` callback.");
                }
                $interfaces = class_implements($parameters['callback']);
                if (empty($interfaces) || !in_array(ConsumerInterface::class, $interfaces)) {
                    throw new InvalidConfigException("{$parameters['callback']} should implement ConsumerInterface.");
                }
                $callbackClass = new $parameters['callback']();
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
                $callbacks = [];

                if (!isset($parameters['connection'])) {
                    throw new InvalidConfigException("Please provide `connection` option for consumer `{$key}`.");
                }
                $connection = \Yii::$container->get(sprintf('rabbit_mq.connection.%s', $parameters['connection']));
                $multipleConsumer = new MultipleConsumer($connection);

                // if consumer doesn't define an exchange -> using AMQP Default
                if (!isset($parameters['exchange_options'])) {
                    $parameters['exchange_options'] = [];
                }
                $parameters['exchange_options'] = array_replace($this->getDefaultExchangeOptions(), $parameters['exchange_options']);
                \Yii::$container->invoke([$multipleConsumer, 'setExchangeOptions'], [$parameters['exchange_options']]);

                if (empty($parameters['queues'])) {
                    throw new InvalidConfigException(
                        "Error on loading {$key} multiple consumer. 'queues' parameter should be defined."
                    );
                }
                foreach ($parameters['queues'] as $queueName => $queueOptions) {
                    // Rearrange array for consistency
                    $queues[$queueOptions['name']] = $queueOptions;
                    $callbackClass = new $queueOptions['callback']();
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
     * Get default AMQP exchange options
     *
     * @return array
     */
    protected function getDefaultExchangeOptions()
    {
        return [
            'name' => '',
            'type' => 'direct',
            'passive' => true,
            'declare' => true,
        ];
    }

    /**
     * Get default AMQP queue options
     *
     * @return array
     */
    protected function getDefaultQueueOptions()
    {
        return [
            'name' => '',
            'declare' => true,
        ];
    }
}
