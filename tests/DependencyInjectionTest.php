<?php

namespace mikemadisonweb\rabbitmq\tests;

use mikemadisonweb\rabbitmq\components\{
    Consumer, Logger, Producer, Routing
};
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\DependencyInjection;
use mikemadisonweb\rabbitmq\tests\mocks\CallbackMock;
use PhpAmqpLib\Connection\AbstractConnection;

class DependencyInjectionTest extends TestCase
{
    public function testBootstrap()
    {
        $testName = 'test';
        $this->mockApplication([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'name' => $testName,
                            'host' => 'unreal',
                        ],
                    ],
                    'exchanges' => [
                        [
                            'name' => $testName,
                            'type' => 'direct'
                        ],
                    ],
                    'queues' => [
                        [
                            'name' => $testName,
                            'durable' => true,
                        ],
                    ],
                    'bindings' => [
                        [
                            'queue' => $testName,
                            'exchange' => $testName,
                            'routingKeys' => [$testName],
                        ],
                    ],
                    'producers' => [
                        [
                            'name' => $testName,
                            'connection' => $testName,
                        ],
                    ],
                    'consumers' => [
                        [
                            'name' => $testName,
                            'connection' => $testName,
                            'callbacks' => [
                                $testName => CallbackMock::class,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $di = new DependencyInjection();
        $di->bootstrap(\Yii::$app);
        $container = \Yii::$container;
        $this->assertInstanceOf(Routing::class, $container->get(Configuration::ROUTING_SERVICE_NAME));
        $this->assertInstanceOf(AbstractConnection::class, $container->get(sprintf(Configuration::CONNECTION_SERVICE_NAME, $testName)));
        $this->assertInstanceOf(Producer::class, $container->get(sprintf(Configuration::PRODUCER_SERVICE_NAME, $testName)));
        $this->assertInstanceOf(Consumer::class, $container->get(sprintf(Configuration::CONSUMER_SERVICE_NAME, $testName)));
        $this->assertInstanceOf(Logger::class, $container->get(Configuration::LOGGER_SERVICE_NAME));
    }

    public function testBootstrapEmpty()
    {
        $this->mockApplication([
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
        $di = new DependencyInjection();
        $di->bootstrap(\Yii::$app);
        $container = \Yii::$container;
        $conn = $container->get(sprintf(Configuration::CONNECTION_SERVICE_NAME, Configuration::DEFAULT_CONNECTION_NAME));
        $this->assertInstanceOf(AbstractConnection::class, $conn);
        $router = $container->get(Configuration::ROUTING_SERVICE_NAME);
        // Declare nothing as nothing was configured
        $this->assertTrue($router->declareAll($conn));
    }

    public function testBootstrapProducer()
    {
        $producerName = 'smth';
        $contentType = 'non-existing';
        $deliveryMode = 432;
        $serializer = 'json_encode';
        $this->mockApplication([
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
                            'serializer' => $serializer,
                        ]
                    ],
                ],
            ],
        ]);
        $di = new DependencyInjection();
        $di->bootstrap(\Yii::$app);
        $container = \Yii::$container;
        // Test producer setter injection
        $producer = $container->get(sprintf(Configuration::PRODUCER_SERVICE_NAME, $producerName));
        $props = $producer->getBasicProperties();
        $this->assertEquals($contentType, $props['content_type']);
        $this->assertEquals($deliveryMode, $props['delivery_mode']);
        $this->assertEquals($serializer, $producer->getSerializer());
    }

    public function testBootstrapConsumer()
    {
        $consumerName = 'smth';
        $queueName = 'non-existing';
        $callback = CallbackMock::class;
        $deserializer = 'json_decode';
        $qos = [
            'prefetch_size' => 11,
            'prefetch_count' => 11,
            'global' => true,
        ];
        $idleTimeout = 100;
        $idleTimeoutExitCode = 101;
        $this->mockApplication([
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
                                $queueName => $callback,
                            ],
                            'qos' => $qos,
                            'idle_timeout' => $idleTimeout,
                            'idle_timeout_exit_code' => $idleTimeoutExitCode,
                            'deserializer' => $deserializer,
                        ]
                    ],
                ],
            ],
        ]);
        $di = new DependencyInjection();
        $di->bootstrap(\Yii::$app);
        $container = \Yii::$container;
        // Test producer setter injection
        $consumer = $container->get(sprintf(Configuration::CONSUMER_SERVICE_NAME, $consumerName));
        $this->assertEquals(array_keys([$queueName => $callback,]), array_keys($consumer->getQueues()));
        $this->assertEquals($qos, $consumer->getQos());
        $this->assertEquals($idleTimeout, $consumer->getIdleTimeout());
        $this->assertEquals($idleTimeoutExitCode, $consumer->getIdleTimeoutExitCode());
        $this->assertEquals($deserializer, $consumer->getDeserializer());
    }

    public function testBootstrapLogger()
    {
        $options = [
            'log' => true,
            'category' => 'some',
            'print_console' => false,
            'system_memory' => true,
        ];
        $this->mockApplication([
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
        $di = new DependencyInjection();
        $di->bootstrap(\Yii::$app);
        $container = \Yii::$container;
        // Test producer setter injection
        $logger = $container->get(Configuration::LOGGER_SERVICE_NAME);
        $this->assertEquals($options, $logger->options);
    }
}