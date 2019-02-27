<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\tests\controllers;

use mikemadisonweb\rabbitmq\components\Consumer;
use mikemadisonweb\rabbitmq\components\Producer;
use mikemadisonweb\rabbitmq\components\Routing;
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\controllers\RabbitMQController;
use mikemadisonweb\rabbitmq\tests\TestCase;
use yii\base\InvalidConfigException;
use yii\console\Controller;

class RabbitMQControllerTest extends TestCase
{
    protected $controller;

    public function setUp()
    {
        $this->loadExtension(
            [
                'components' => [
                    'rabbitmq' => [
                        'class'       => Configuration::class,
                        'connections' => [
                            [
                                'host' => 'unreal',
                            ],
                        ],
                    ],
                ],
            ]
        );
        $this->controller = $this->getMockBuilder(RabbitMQController::class)
            ->setConstructorArgs([Configuration::EXTENSION_CONTROLLER_ALIAS, \Yii::$app])
            ->setMethods(['stderr', 'stdout'])
            ->getMock();
    }

    public function testConsumeAction()
    {
        // Check console flags existence
        $this->assertTrue(isset($this->controller->optionAliases()['l']));
        $this->assertTrue(isset($this->controller->optionAliases()['m']));
        $this->assertSame('messagesLimit', $this->controller->optionAliases()['m']);
        $this->assertSame('memoryLimit', $this->controller->optionAliases()['l']);
        $this->assertTrue(in_array('messagesLimit', $this->controller->options('consume'), true));
        $this->assertTrue(in_array('memoryLimit', $this->controller->options('consume'), true));

        // Invalid consumer name
        $this->expectException(InvalidConfigException::class);
        $response = $this->controller->runAction('consume', ['unknown']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);

        // Valid consumer name
        $name     = 'valid';
        $consumer = $this->getMockBuilder(Consumer::class)
            ->disableOriginalConstructor()
            ->setMethods(['getConsumerTag', 'consume'])
            ->getMock();
        \Yii::$container->set(sprintf(Configuration::CONSUMER_SERVICE_NAME, $name), $consumer);
        $this->controller->debug       = 'false';
        $this->controller->memoryLimit = '1024';
        $response                      = $this->controller->runAction('consume', [$name]);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testPublishAction()
    {
        // Invalid producer name
        $this->expectException(InvalidConfigException::class);
        $response = $this->controller->runAction('publish', ['unknown', 'unknown']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);

        // No data
        $name     = 'valid';
        $producer = $this->getMockBuilder(Producer::class)
            ->disableOriginalConstructor()
            ->setMethods(['publish'])
            ->getMock();
        \Yii::$container->set(sprintf(Configuration::PRODUCER_SERVICE_NAME, $name), $producer);
        $response = $this->controller->runAction('publish', [$name, 'does not matter']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);
    }

    public function testDeclareAllAction()
    {
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->exactly(2))
            ->method('declareAll')
            ->willReturnOnConsecutiveCalls(false, true);
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, $routing);
        $response = $this->controller->runAction('declare-all');
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);
        $response = $this->controller->runAction('declare-all');
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testDeclareQueueAction()
    {
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->exactly(2))
            ->method('isQueueExists')
            ->willReturnOnConsecutiveCalls(true, false);
        $routing->expects($this->once())
            ->method('declareQueue');
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, $routing);
        $response = $this->controller->runAction('declare-queue', ['queue-name']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);
        $response = $this->controller->runAction('declare-queue', ['queue-name']);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testDeclareExchangeAction()
    {
        $routing = $this->createMock(Routing::class);
        $routing->expects($this->exactly(2))
            ->method('isExchangeExists')
            ->willReturnOnConsecutiveCalls(true, false);
        $routing->expects($this->once())
            ->method('declareExchange');
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, $routing);
        $response = $this->controller->runAction('declare-exchange', ['exchange-name']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);
        $response = $this->controller->runAction('declare-exchange', ['exchange-name']);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testDeleteAllAction()
    {
        $this->controller->interactive = false;
        $routing                       = $this->createMock(Routing::class);
        $routing->expects($this->once())
            ->method('deleteAll')
            ->willReturnOnConsecutiveCalls(false, true);
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, $routing);
        $response = $this->controller->runAction('delete-all');
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testDeleteQueueAction()
    {
        $this->controller->interactive = false;
        $routing                       = $this->createMock(Routing::class);
        $routing->expects($this->once())
            ->method('deleteQueue');
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, $routing);
        $response = $this->controller->runAction('delete-queue', ['queue-name']);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testDeleteExchangeAction()
    {
        $this->controller->interactive = false;
        $routing                       = $this->createMock(Routing::class);
        $routing->expects($this->once())
            ->method('deleteExchange');
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, $routing);
        $response = $this->controller->runAction('delete-exchange', ['exchange-name']);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testPurgeQueueAction()
    {
        $this->controller->interactive = false;
        $routing                       = $this->createMock(Routing::class);
        $routing->expects($this->once())
            ->method('purgeQueue');
        \Yii::$container->setSingleton(Configuration::ROUTING_SERVICE_NAME, $routing);
        $response = $this->controller->runAction('purge-queue', ['queue-name']);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }
}
