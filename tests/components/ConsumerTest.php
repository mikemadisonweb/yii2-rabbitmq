<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\tests\components;

use mikemadisonweb\rabbitmq\components\{
    Consumer, ConsumerInterface, Logger, Routing
};
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\events\RabbitMQConsumerEvent;
use mikemadisonweb\rabbitmq\tests\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use yii\console\Controller;

class ConsumerTest extends TestCase
{
    /**
     * @dataProvider checkConsume
     * @param $queues
     * @param $consumeCount
     */
    public function testConsume($queues, $consumeCount)
    {
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->once())
            ->method('declareAll');
        $logger = \Yii::$container->get(Configuration::LOGGER_SERVICE_NAME);
        $consumer = new Consumer($connection, $routing, $logger, true);
        if (!empty($queues)) {
            $consumer->setQueues($queues);
        }
        $channel
            ->expects($consumeCount)
            ->method('basic_consume');
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $consumer->consume());
    }

    public function checkConsume() : array
    {
        return [
            [[], $this->never()],
            [['queue' => 'callback'], $this->once()],
            [['queue1' => 'callback1', 'queue2' => 'callback2', 'queue3' => 'callback3'], $this->exactly(3)],
        ];
    }

    public function testNoAutoDeclare()
    {
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->never())
            ->method('declareAll');
        $logger = \Yii::$container->get(Configuration::LOGGER_SERVICE_NAME);
        $consumer = new Consumer($connection, $routing, $logger, false);
        $consumer->setQos(['prefetch_size' => 0, 'prefetch_count' => 0, 'global' => false]);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $consumer->consume());
    }

    public function testConsumeEvents()
    {
        $queue = 'test-queue';
        $msgBody = 'Test message!';
        $consumerName = 'test';
        $callbackName = 'MockCallback';
        $callback = $this->getMockBuilder(ConsumerInterface::class)
            ->setMockClassName($callbackName)
            ->getMock();
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
                            'name' => $queue,
                        ],
                    ],
                    'consumers' => [
                        [
                            'name' => $consumerName,
                            'callbacks' => [$queue => $callbackName],
                        ]
                    ],
                    'on before_consume' => function ($event) use ($msgBody) {
                        $this->assertInstanceOf(RabbitMQConsumerEvent::class, $event);
                        $this->assertSame($msgBody, $event->message->getBody());
                    },
                    'on after_consume' => function ($event) use ($msgBody) {
                        $this->assertInstanceOf(RabbitMQConsumerEvent::class, $event);
                        $this->assertSame($msgBody, $event->message->getBody());
                    },
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
        $logger = $this->createMock(Logger::class);
        $routing = $this->createMock(Routing::class);
        $consumer = \Yii::$app->rabbitmq->getConsumer($consumerName);
        $this->setInaccessibleProperty($consumer, 'routing', $routing);
        $this->setInaccessibleProperty($consumer, 'conn', $connection);
        $this->setInaccessibleProperty($consumer, 'logger', $logger);
        $msg = new AMQPMessage($msgBody);
        $this->invokeMethod($consumer, 'onReceive', [$msg, $queue, [$callback, 'execute']]);
    }

    public function testOnReceive()
    {
        $queue = 'test-queue';
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
        $callback = $this->createMock(ConsumerInterface::class);
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->never())
            ->method('declareAll');
        $logger = $this->createMock(Logger::class);
        $consumer = $this->getMockBuilder(Consumer::class)
            ->setConstructorArgs([$connection, $routing, $logger, false])
            ->setMethods(['sendResult'])
            ->getMock();
        $msgBody = 'Test message';
        $consumer->method('sendResult')
            ->willThrowException(new \Exception($msgBody));
        $msg = new AMQPMessage($msgBody);
        // No exception should be thrown
        $consumer->setProceedOnException(true);
        $before = $consumer->getConsumed();
        $this->assertTrue($this->invokeMethod($consumer, 'onReceive', [$msg, $queue, [$callback, 'execute']]));
        $this->assertSame($before + 1, $consumer->getConsumed());
        // Exception should be thrown
        $consumer->setProceedOnException(false);
        $this->expectExceptionMessage($msgBody);
        $callback->expects($this->once())
            ->method('execute');
        $logger->expects($this->once())
            ->method('logError');
        $this->invokeMethod($consumer, 'onReceive', [$msg, $queue, [$callback, 'execute']]);
    }

    /**
     * @dataProvider checkMsgTypes
     * @param $userData
     */
    public function testOnReceiveDifferentTypes($userData)
    {
        $queue = 'test-queue';
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
        $callback = $this->createMock(ConsumerInterface::class);
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->never())
            ->method('declareAll');
        $logger = $this->createMock(Logger::class);
        $consumer = $this->getMockBuilder(Consumer::class)
            ->setConstructorArgs([$connection, $routing, $logger, false])
            ->setMethods(['sendResult'])
            ->getMock();
        $consumer->setDeserializer('json_decode');
        $msgBody = json_encode($userData);
        $msg = new AMQPMessage($msgBody);
        $headers['rabbitmq.serialized'] = 1;
        $headersTable = new AMQPTable($headers);
        $msg->set('application_headers', $headersTable);
        $this->invokeMethod($consumer, 'onReceive', [$msg, $queue, [$callback, 'execute']]);
        $this->assertEquals($userData, $msg->getBody());
    }

    public function checkMsgTypes() : array
    {
        return [
            ['String!'],
            [['array']],
            [1],
            [1.1],
            [null],
            [new \StdClass()],
        ];
    }

    public function testForceStop()
    {
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects($this->once())
            ->method('basic_cancel');
        $connection->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->never())
            ->method('declareAll');
        $logger = $this->createMock(Logger::class);
        $consumer = $this->getMockBuilder(Consumer::class)
            ->setConstructorArgs([$connection, $routing, $logger, false])
            ->setMethods(['maybeStopConsumer'])
            ->getMock();
        $consumer->setQueues(['queue' => 'callback']);
        $consumer->stopDaemon();
    }

    public function testForceRestart()
    {
        $connection = $this->getMockBuilder(AMQPLazyConnection::class)
            ->disableOriginalConstructor()
            ->setMethods(['channel'])
            ->getMock();
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->method('channel')
            ->willReturn($channel);
        $routing = $this->createMock(Routing::class);
        $logger = $this->createMock(Logger::class);
        $consumer = $this->getMockBuilder(Consumer::class)
            ->setConstructorArgs([$connection, $routing, $logger, false])
            ->setMethods(['stopConsuming', 'renew', 'setup'])
            ->getMock();
        $consumer->expects($this->once())
            ->method('stopConsuming');
        $consumer->expects($this->once())
            ->method('renew');
        $consumer->expects($this->once())
            ->method('setup');
        $consumer->setQueues(['queue' => 'callback']);
        $consumer->restartDaemon();
    }
}
