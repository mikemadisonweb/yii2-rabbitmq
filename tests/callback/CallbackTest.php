<?php

namespace mikemadisonweb\rabbitmq\tests\callback;

use mikemadisonweb\rabbitmq\components\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class CallbackTest implements ConsumerInterface
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