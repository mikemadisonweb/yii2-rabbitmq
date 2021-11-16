<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq;

use mikemadisonweb\rabbitmq\components\{
    AbstractConnectionFactory, Consumer, ConsumerInterface, Logger, Producer, Routing
};
use mikemadisonweb\rabbitmq\controllers\RabbitMQController;
use mikemadisonweb\rabbitmq\exceptions\InvalidConfigException;
use PhpAmqpLib\Connection\AbstractConnection;
use yii\base\Application;
use yii\base\BootstrapInterface;

class DependencyInjection implements BootstrapInterface
{
    /**
     * @var $logger Logger
     */
    private $logger;
    protected $isLoaded = false;

    /**
     * Configuration auto-loading
     * @param Application $app
     * @throws InvalidConfigException
     */
    public function bootstrap($app)
    {
        $config = $app->rabbitmq->getConfig();
        $this->registerLogger($config);
        $this->registerConnections($config);
        $this->registerRouting($config);
        $this->registerProducers($config);
        $this->registerConsumers($config);
        $this->addControllers($app);
    }

    /**
     * Register logger service
     * @param $config
     */
    private function registerLogger($config)
    {
        \Yii::$container->setSingleton(Configuration::LOGGER_SERVICE_NAME, ['class' => Logger::class, 'options' => $config->logger]);
    }

    /**
     * Register connections in service container
     * @param Configuration $config
     */
    protected function registerConnections(Configuration $config)
    {
        foreach ($config->connections as $options) {
            $serviceAlias = sprintf(Configuration::CONNECTION_SERVICE_NAME, $options['name']);
            \Yii::$container->setSingleton($serviceAlias, function () use ($options) {
                $factory = new AbstractConnectionFactory($options['type'], $options);
                return $factory->createConnection();
            });
        }
    }

    /**
     * Register routing in service container
     * @param Configuration $config
     */
    protected function registerRouting(Configuration $config)
    {
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, function ($container, $params) use ($config) {
            $routing = new Routing($params['conn']);
            \Yii::$container->invoke([$routing, 'setQueues'], [$config->queues]);
            \Yii::$container->invoke([$routing, 'setExchanges'], [$config->exchanges]);
            \Yii::$container->invoke([$routing, 'setBindings'], [$config->bindings]);

            return $routing;
        });
    }

    /**
     * Register producers in service container
     * @param Configuration $config
     */
    protected function registerProducers(Configuration $config)
    {
        $autoDeclare = $config->auto_declare;
        foreach ($config->producers as $options) {
            $serviceAlias = sprintf(Configuration::PRODUCER_SERVICE_NAME, $options['name']);
            \Yii::$container->setSingleton($serviceAlias, function () use ($options, $autoDeclare) {
                /**
                 * @var $connection AbstractConnection
                 */
                $connection = \Yii::$container->get(sprintf(Configuration::CONNECTION_SERVICE_NAME, $options['connection']));
                /**
                 * @var $routing Routing
                 */
                $routing = \Yii::$container->get(Configuration::ROUTING_SERVICE_NAME, ['conn' => $connection]);
                /**
                 * @var $logger Logger
                 */
                $logger = \Yii::$container->get(Configuration::LOGGER_SERVICE_NAME);
                $producer = new Producer($connection, $routing, $logger, $autoDeclare);
                \Yii::$container->invoke([$producer, 'setName'], [$options['name']]);
                \Yii::$container->invoke([$producer, 'setContentType'], [$options['content_type']]);
                \Yii::$container->invoke([$producer, 'setDeliveryMode'], [$options['delivery_mode']]);
                \Yii::$container->invoke([$producer, 'setSafe'], [$options['safe']]);
                \Yii::$container->invoke([$producer, 'setSerializer'], [$options['serializer']]);

                return $producer;
            });
        }
    }

    /**
     * Register consumers(one instance per one or multiple queues) in service container
     * @param Configuration $config
     */
    protected function registerConsumers(Configuration $config)
    {
        $autoDeclare = $config->auto_declare;
        foreach ($config->consumers as $options) {
            $serviceAlias = sprintf(Configuration::CONSUMER_SERVICE_NAME, $options['name']);
            \Yii::$container->setSingleton($serviceAlias, function () use ($options, $autoDeclare) {
                /**
                 * @var $connection AbstractConnection
                 */
                $connection = \Yii::$container->get(sprintf(Configuration::CONNECTION_SERVICE_NAME, $options['connection']));
                /**
                 * @var $routing Routing
                 */
                $routing = \Yii::$container->get(Configuration::ROUTING_SERVICE_NAME, ['conn' => $connection]);
                /**
                 * @var $logger Logger
                 */
                $logger = \Yii::$container->get(Configuration::LOGGER_SERVICE_NAME);
                $consumer = new Consumer($connection, $routing, $logger, $autoDeclare);
                $queues = [];
                foreach ($options['callbacks'] as $queueName => $callback) {
                    $callbackClass = $this->getCallbackClass($callback);
                    $queues[$queueName] = [$callbackClass, 'execute'];
                }
                \Yii::$container->invoke([$consumer, 'setName'], [$options['name']]);
                \Yii::$container->invoke([$consumer, 'setQueues'], [$queues]);
                \Yii::$container->invoke([$consumer, 'setQos'], [$options['qos']]);
                \Yii::$container->invoke([$consumer, 'setIdleTimeout'], [$options['idle_timeout']]);
                \Yii::$container->invoke([$consumer, 'setIdleTimeoutExitCode'], [$options['idle_timeout_exit_code']]);
                \Yii::$container->invoke([$consumer, 'setProceedOnException'], [$options['proceed_on_exception']]);
                \Yii::$container->invoke([$consumer, 'setDeserializer'], [$options['deserializer']]);

                return $consumer;
            });
        }
    }

    /**
     * Callback can be passed as class name or alias in service container
     * @param string $callbackName
     * @return ConsumerInterface
     * @throws InvalidConfigException
     */
    private function getCallbackClass(string $callbackName) : ConsumerInterface
    {
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

    /**
     * Auto-configure console controller classes
     * @param Application $app
     */
    private function addControllers(Application $app)
    {
	    if($app instanceof \yii\console\Application) {
		    $app->controllerMap[Configuration::EXTENSION_CONTROLLER_ALIAS] = RabbitMQController::class;
	    }
    }
}
