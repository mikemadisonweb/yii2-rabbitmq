<?php
/**
 * Created by PhpStorm.
 * User: unregistered
 * Date: 20.06.2017
 * Time: 21:18
 */

namespace mikemadisonweb\rabbitmq\lib;

class AMQPLazyConnection extends \PhpAmqpLib\Connection\AMQPLazyConnection
{
    /**
     * Returns the fully qualified name of this class.
     * @return string the fully qualified name of this class.
     */
    public static function className()
    {
        return get_called_class();
    }
}