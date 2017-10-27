<?php

namespace mikemadisonweb\rabbitmq\tests\controllers;

use mikemadisonweb\rabbitmq\components\Consumer;
use mikemadisonweb\rabbitmq\components\Producer;
use mikemadisonweb\rabbitmq\Configuration;
use mikemadisonweb\rabbitmq\controllers\RabbitMQController;
use mikemadisonweb\rabbitmq\tests\TestCase;
use yii\console\Controller;

class RabbitMQControllerTest extends TestCase
{
    protected $controller;

    public function setUp()
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
        $response = $this->controller->runAction('consume', ['unknown']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);

        // Valid consumer name
        $name = 'valid';
        $consumer = $this->getMockBuilder(Consumer::class)
            ->disableOriginalConstructor()
            ->setMethods(['getConsumerTag', 'consume'])
            ->getMock();
        \Yii::$container->set(sprintf(Configuration::CONSUMER_SERVICE_NAME, $name), $consumer);
        $response = $this->controller->runAction('consume', [$name]);
        $this->assertSame(Controller::EXIT_CODE_NORMAL, $response);
    }

    public function testPublishAction()
    {
        // Invalid producer name
        $response = $this->controller->runAction('publish', ['unknown', 'unknown']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);

        // No data
        $name = 'valid';
        $producer = $this->getMockBuilder(Producer::class)
            ->disableOriginalConstructor()
            ->setMethods(['publish'])
            ->getMock();
        \Yii::$container->set(sprintf(Configuration::PRODUCER_SERVICE_NAME, $name), $producer);
        $response = $this->controller->runAction('publish', [$name, 'does not matter']);
        $this->assertSame(Controller::EXIT_CODE_ERROR, $response);
    }
}