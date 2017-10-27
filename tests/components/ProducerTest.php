<?php

namespace mikemadisonweb\rabbitmq\tests\components;

use mikemadisonweb\rabbitmq\components\{
    Logger, Producer, Routing
};
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\exceptions\RuntimeException;
use mikemadisonweb\rabbitmq\tests\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPLazyConnection;

class ProducerTest extends TestCase
{
    public function testPublish()
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
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects($this->once())
            ->method('basic_publish');
        $connection->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->once())
            ->method('declareAll');
        $routing->expects($this->exactly(2))
            ->method('isExchangeExists')
            ->willReturnOnConsecutiveCalls(true, false);
        $logger = $this->createMock(Logger::class);
        $producer = new Producer($connection, $routing, $logger, true);
        $producer->setSafe(true);
        // Good attempt
        $producer->publish('Test message', 'exist');
        // Non-existing exchange
        $this->expectException(RuntimeException::class);
        $producer->publish('Test message', 'not-exist');
    }

    public function testPublishDifferentTypes()
    {

    }
}
