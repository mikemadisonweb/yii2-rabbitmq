<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Message\AMQPMessage;

interface ConsumerInterface
{
    /**
     * Flag for message ack
     */
    const MSG_ACK = 0;

    /**
     * Flag for reject and drop message
     */
    const MSG_REJECT = 1;

    /**
     * Flag for reject and requeue message
     */
    const MSG_REQUEUE = 2;

    /**
     * @param AMQPMessage $msg The message
     * @return mixed false to reject and requeue, any other value to acknowledge
     */
    public function execute(AMQPMessage $msg);
}
