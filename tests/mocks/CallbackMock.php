<?php

namespace mikemadisonweb\rabbitmq\tests\mocks;

use mikemadisonweb\rabbitmq\components\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class CallbackMock implements ConsumerInterface
{
    /**
     * @param AMQPMessage $msg
     * @return bool|mixed
     */
    public function execute(AMQPMessage $msg)
    {
        return true;
    }
}