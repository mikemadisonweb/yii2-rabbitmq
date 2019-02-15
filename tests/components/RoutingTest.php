<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\tests\components;

use mikemadisonweb\rabbitmq\exceptions\RuntimeException;
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\tests\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;

class RoutingTest extends TestCase
{
    public function testRouting()
    {
        $name = 'test';
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'name' => $name,
                            'url' => 'amqp://user:pass@host:5432/vhost?query',
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
                        [
                            'durable' => false,
                        ],
                    ],
                    'bindings' => [
                        [
                            'queue' => $name,
                            'exchange' => $name,
                            'routing_keys' => [$name],
                        ],
                        [
                            'exchange' => $name,
                            'to_exchange' => $name,
                            'routing_keys' => [$name],
                        ],
                        [
                            'queue' => $name,
                            'exchange' => $name,
                        ],
                        [
                            'exchange' => $name,
                            'to_exchange' => $name,
                        ],
                        [
                            'queue' => '',
                            'exchange' => $name,
                        ],
                    ],
                ],
            ],
        ]);
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->method('channel')
            ->willReturn($channel);
        $routing = \Yii::$app->rabbitmq->getRouting($connection);
        $this->assertTrue($routing->declareAll());
        $this->assertFalse($routing->declareAll());
    }

    /**
     * @dataProvider checkExceptions
     * @param $functionName
     */
    public function testRoutingExceptions($functionName)
    {
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'url' => 'amqp://user:pass@host:5432/vhost?query',
                        ],
                    ],
                ],
            ],
        ]);
        $connection = \Yii::$app->rabbitmq->getConnection();
        $routing = \Yii::$app->rabbitmq->getRouting($connection);
        $this->expectException(RuntimeException::class);
        $routing->$functionName('non-existing');
    }

    /**
     * @return array
     */
    public function checkExceptions() : array
    {
        return [
            ['declareQueue'],
            ['declareExchange'],
            ['purgeQueue'],
            ['deleteQueue'],
            ['deleteExchange'],
        ];
    }

    public function testRoutingNonExisting()
    {
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'url' => 'amqp://user:pass@host:5432/vhost?query',
                        ],
                    ],
                ],
            ],
        ]);
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $exception = $this->createMock(AMQPProtocolChannelException::class);
        $channel
            ->expects($this->once())
            ->method('exchange_declare')
            ->willThrowException($exception);
        $channel
            ->expects($this->once())
            ->method('queue_declare')
            ->willThrowException($exception);
        $connection->method('channel')
            ->willReturn($channel);
        $routing = \Yii::$app->rabbitmq->getRouting($connection);
        $this->assertFalse($routing->isExchangeExists('non-existing'));
        $this->assertFalse($routing->isQueueExists('non-existing'));
    }

    public function testRoutingExisting()
    {
        $name = 'test';
        $this->loadExtension([
            'components' => [
                'rabbitmq' => [
                    'class' => Configuration::class,
                    'connections' => [
                        [
                            'url' => 'amqp://user:pass@host:5432/vhost?query',
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
                        [
                            'durable' => false,
                        ],
                    ],
                ],
            ],
        ]);
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel
            ->expects($this->once())
            ->method('exchange_declare');
        $channel
            ->expects($this->once())
            ->method('queue_declare');
        $channel
            ->expects($this->once())
            ->method('queue_purge');
        $connection->method('channel')
            ->willReturn($channel);
        $routing = \Yii::$app->rabbitmq->getRouting($connection);
        $this->assertTrue($routing->isExchangeExists($name));
        $this->assertTrue($routing->isQueueExists($name));
        // Test purging queue
        $routing->purgeQueue($name);
        // Test deleting all schema
        $channel
            ->expects($this->exactly(2))
            ->method('queue_delete');
        $channel
            ->expects($this->once())
            ->method('exchange_delete');
        $routing->deleteAll();
    }
}
