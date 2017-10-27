<?php

namespace mikemadisonweb\rabbitmq\tests;

use mikemadisonweb\rabbitmq\components\{
    Consumer, ConsumerInterface, Logger, Producer, Routing
};
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\controllers\RabbitMQController;
use PhpAmqpLib\Connection\AbstractConnection;

class DependencyInjectionTest extends TestCase
{
    public function testBootstrap()
    {
        $name = 'test';
        $callbackName = 'CallbackMock';
        $this->getMockBuilder(ConsumerInterface::class)
            ->setMockClassName($callbackName)
            ->getMock();
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'name' => $name,
                            'host' => 'unreal',
                        ],
                    ],
                    'exchanges' => [
                        [
                            'name' => $name,
                            'type' => 'direct'
                        ],
                    ],
                    'queues' => [
                        [
                            'name' => $name,
                            'durable' => true,
                        ],
                    ],
                    'bindings' => [
                        [
                            'queue' => $name,
                            'exchange' => $name,
                            'routingKeys' => [$name],
                        ],
                    ],
                    'producers' => [
                        [
                            'name' => $name,
                            'connection' => $name,
                        ],
                    ],
                    'consumers' => [
                        [
                            'name' => $name,
                            'connection' => $name,
                            'callbacks' => [
                                $name => $callbackName,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $container = \Yii::$container;
        $connection = $container->get(sprintf(Configuration::CONNECTION_SERVICE_NAME, $name));
        $this->assertInstanceOf(AbstractConnection::class, $connection);
        $this->assertInstanceOf(Routing::class, $container->get(Configuration::ROUTING_SERVICE_NAME, ['conn' => $connection]));
        $this->assertInstanceOf(Producer::class, $container->get(sprintf(Configuration::PRODUCER_SERVICE_NAME, $name)));
        $this->assertInstanceOf(Consumer::class, $container->get(sprintf(Configuration::CONSUMER_SERVICE_NAME, $name)));
        $this->assertInstanceOf(Logger::class, $container->get(Configuration::LOGGER_SERVICE_NAME));
        $this->assertSame(\Yii::$app->controllerMap[Configuration::EXTENSION_CONTROLLER_ALIAS], RabbitMQController::class);
    }

    public function testBootstrapEmpty()
    {
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'host' => 'unreal',
                        ],
                    ],
                ],
            ],
        ]);
        $container = \Yii::$container;
        $conn = $container->get(sprintf(Configuration::CONNECTION_SERVICE_NAME, Configuration::DEFAULT_CONNECTION_NAME));
        $this->assertInstanceOf(AbstractConnection::class, $conn);
        $router = $container->get(Configuration::ROUTING_SERVICE_NAME, ['conn' => $conn]);
        // Declare nothing as nothing was configured
        $this->assertTrue($router->declareAll($conn));
    }

    public function testBootstrapProducer()
    {
        $producerName = 'smth';
        $contentType = 'non-existing';
        $deliveryMode = 432;
        $serializer = 'json_encode';
        $safe = false;
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'host' => 'unreal',
                        ],
                    ],
                    'producers' => [
                        [
                            'name' => $producerName,
                            'content_type' => $contentType,
                            'delivery_mode' => $deliveryMode,
                            'safe' => $safe,
                            'serializer' => $serializer,
                        ]
                    ],
                ],
            ],
        ]);
        // Test producer setter injection
        $producer = \Yii::$container->get(sprintf(Configuration::PRODUCER_SERVICE_NAME, $producerName));
        $props = $producer->getBasicProperties();
        $this->assertSame($producerName, $producer->getName());
        $this->assertSame($safe, $producer->getSafe());
        $this->assertSame($contentType, $props['content_type']);
        $this->assertSame($deliveryMode, $props['delivery_mode']);
        $this->assertSame($serializer, $producer->getSerializer());
    }

    public function testBootstrapConsumer()
    {
        $consumerName = 'smth';
        $queueName = 'non-existing';
        $callbackName = 'CallbackMock';
        $callback = $this->getMockBuilder(ConsumerInterface::class)
            ->setMockClassName($callbackName)
            ->setMethods(['execute'])
            ->getMock();
        $deserializer = 'json_decode';
        $qos = [
            'prefetch_size' => 11,
            'prefetch_count' => 11,
            'global' => true,
        ];
        $idleTimeout = 100;
        $idleTimeoutExitCode = 101;
        $proceedOnException = true;
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'host' => 'unreal',
                        ],
                    ],
                    'queues' => [
                        [
                            'name' => $queueName,
                        ]
                    ],
                    'consumers' => [
                        [
                            'name' => $consumerName,
                            'callbacks' => [
                                $queueName => $callbackName,
                            ],
                            'qos' => $qos,
                            'idle_timeout' => $idleTimeout,
                            'idle_timeout_exit_code' => $idleTimeoutExitCode,
                            'proceed_on_exception' => $proceedOnException,
                            'deserializer' => $deserializer,
                        ]
                    ],
                ],
            ],
        ]);
        // Test producer setter injection
        $consumer = \Yii::$container->get(sprintf(Configuration::CONSUMER_SERVICE_NAME, $consumerName));
        $this->assertSame($consumerName, $consumer->getName());
        $this->assertSame(array_keys([$queueName => $callback,]), array_keys($consumer->getQueues()));
        $this->assertSame($qos, $consumer->getQos());
        $this->assertSame($idleTimeout, $consumer->getIdleTimeout());
        $this->assertSame($idleTimeoutExitCode, $consumer->getIdleTimeoutExitCode());
        $this->assertSame($deserializer, $consumer->getDeserializer());
        $this->assertSame($proceedOnException, $consumer->getProceedOnException());
    }

    public function testBootstrapLogger()
    {
        $options = [
            'log' => true,
            'category' => 'some',
            'print_console' => false,
            'system_memory' => true,
        ];
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'host' => 'unreal',
                        ],
                    ],
                    'logger' => $options,
                ],
            ],
        ]);
        // Test producer setter injection
        $logger = \Yii::$container->get(Configuration::LOGGER_SERVICE_NAME);
        $this->assertSame($options, $logger->options);
    }
}