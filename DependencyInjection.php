<?php

namespace mikemadisonweb\rabbitmq;

use mikemadisonweb\rabbitmq\components\ConsumerInterface;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\base\UnknownPropertyException;

class DependencyInjection implements BootstrapInterface
{
    /**
     * Configuration auto-loading
     * @param Application $app
     * @throws InvalidConfigException
     */
    public function bootstrap($app)
    {
        try {
            $configuration = $app->rabbitmq;
            $passedConfig = get_object_vars($configuration);
        } catch (UnknownPropertyException $e) {
            throw new InvalidConfigException("Key `rabbitmq` is not found in config, RabbitMQ extension is not configured.");
        }
        $configuration->validate($passedConfig);

        $this->registerConnections();
        $this->registerProducers();
        $this->registerConsumers();
    }

    /**
     * Set connections to service container
     */
    protected function registerConnections()
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
    protected function registerProducers()
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
     * Set consumers(one instance per multiple queues) to service container
     */
    protected function registerConsumers()
    {
        foreach ($this->consumers as $key => $parameters) {
            $serviceAlias = sprintf(BaseRabbitMQ::MULTIPLE_CONSUMER_SERVICE_NAME, $key);
            \Yii::$container->set($serviceAlias, function () use ($key, $parameters) {
                $queues = [];

                if (!isset($parameters['connection'])) {
                    throw new InvalidConfigException("Please provide `connection` option for consumer `{$key}`.");
                }
                $connection = \Yii::$container->get(sprintf('rabbit_mq.connection.%s', $parameters['connection']));
                $multipleConsumer = new Consumer($connection);

                // if consumer doesn't define an exchange -> using AMQP Default
                if (!isset($parameters['exchange_options'])) {
                    $parameters['exchange_options'] = [];
                }
                \Yii::$container->invoke([$multipleConsumer, 'setExchangeOptions'], [$parameters['exchange_options']]);

                if (empty($parameters['queues'])) {
                    throw new InvalidConfigException(
                        "Error on registering {$key} multiple consumer. 'queues' parameter should be defined."
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
     * @param string $callbackName
     * @return object
     * @throws InvalidConfigException
     */
    private function getCallbackClass(string $callbackName)
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