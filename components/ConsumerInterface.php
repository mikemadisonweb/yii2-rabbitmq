<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Message\AMQPMessage;

interface ConsumerInterface
{
    /**
     * Flag for message ack
     */
    const MSG_ACK = 1;

    /**
     * Flag single for message nack and requeue
     */

    const MSG_SINGLE_NACK_REQUEUE = 2;
    /**
     * Flag for reject and requeue
     */
    const MSG_REJECT_REQUEUE = 0;

    /**
     * Flag for reject and drop
     */
    const MSG_REJECT = -1;

    /**
     * @param AMQPMessage $msg The message
     * @return mixed false to reject and requeue, any other value to acknowledge
     */
    public function execute(AMQPMessage $msg);

    /**
     * Returns the fully qualified name of this class.
     * You may extend your class by yii\base\Object.
     * @return string the fully qualified name of this class.
     */
    public static function className();
}
