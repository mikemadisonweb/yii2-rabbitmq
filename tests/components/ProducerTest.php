<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\tests\components;

use mikemadisonweb\rabbitmq\components\{
    Logger, Producer, Routing
};
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\events\RabbitMQPublisherEvent;
use mikemadisonweb\rabbitmq\exceptions\RuntimeException;
use mikemadisonweb\rabbitmq\tests\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPLazyConnection;

class ProducerTest extends TestCase
{
    /**
     * Test without framework
     */
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
        $connection
            ->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->exactly(2))
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

    /**
     * Test events
     */
    public function testPublishEvents()
    {
        $producerName = 'test';
        $msg = 'Some-message';
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
                        ]
                    ],
                    'on before_publish' => function ($event) use ($msg) {
                        $this->assertInstanceOf(RabbitMQPublisherEvent::class, $event);
                        $this->assertSame($msg, $event->message->getBody());
                    },
                    'on after_publish' => function ($event) use ($msg) {
                        $this->assertInstanceOf(RabbitMQPublisherEvent::class, $event);
                        $this->assertSame($msg, $event->message->getBody());
                    },
                ],
            ],
        ]);
        $producer = \Yii::$app->rabbitmq->getProducer($producerName);
        $routing = $this->createMock(Routing::class);
        $routing->method('declareAll');
        $routing->method('isExchangeExists')
            ->willReturn(true);
        $this->setInaccessibleProperty($producer, 'routing', $routing);
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects($this->once())
            ->method('basic_publish');
        $connection
            ->method('channel')
            ->willReturn($channel);
        $this->setInaccessibleProperty($producer, 'conn', $connection);
        $producer->publish($msg, 'exchange');
    }

    /**
     * Test inside framework with different message types
     * @dataProvider checkMsgEncoding
     * @param $initial
     * @param $encoded
     */
    public function testPublishDifferentTypes($initial, $encoded)
    {
        $producerName = 'test';
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
                        ]
                    ],
                    'on after_publish' => function ($event) use ($encoded) {
                        $this->assertSame($encoded, $event->message->getBody());
                    },
                ],
            ],
        ]);
        $producer = \Yii::$app->rabbitmq->getProducer($producerName);
        $routing = $this->createMock(Routing::class);
        $routing->method('declareAll');
        $routing->method('isExchangeExists')
            ->willReturn(true);
        $this->setInaccessibleProperty($producer, 'routing', $routing);
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects($this->once())
            ->method('basic_publish');
        $connection
            ->method('channel')
            ->willReturn($channel);
        $this->setInaccessibleProperty($producer, 'conn', $connection);
        $producer->publish($initial, 'exchange');
    }

    public function checkMsgEncoding() : array
    {
        return [
            ['String!', 'String!'],
            [['array'], 'a:1:{i:0;s:5:"array";}'],
            [1, 'i:1;'],
            [null, 'N;'],
            [new \StdClass(), 'O:8:"stdClass":0:{}'],
        ];
    }
}
