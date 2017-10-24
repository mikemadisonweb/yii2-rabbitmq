<?php

namespace mikemadisonweb\rabbitmq\tests;

use mikemadisonweb\rabbitmq\components\Consumer;
use mikemadisonweb\rabbitmq\components\Producer;
use mikemadisonweb\rabbitmq\components\Routing;
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\DependencyInjection;
use mikemadisonweb\rabbitmq\tests\callback\CallbackTest;
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
                            'host' => 'localhost',
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
                                $testName => CallbackTest::class,
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
    }

    public function testBootstrapEmpty()
    {
        $this->mockApplication([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'host' => 'localhost',
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
        $testContentType = 'non-existing';
        $testDeliveryMode = 432;
        $testSerializer = 'serialize';
        $this->mockApplication([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'host' => 'localhost',
                        ],
                    ],
                    'producers' => [
                        [
                            'name' => $producerName,
                            'contentType' => $testContentType,
                            'deliveryMode' => $testDeliveryMode,
                            'serializer' => $testSerializer,
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
        $this->assertEquals($testContentType, $props['content_type']);
        $this->assertEquals($testDeliveryMode, $props['delivery_mode']);
        $this->assertEquals($testSerializer, $producer->getSerializer());
    }
}