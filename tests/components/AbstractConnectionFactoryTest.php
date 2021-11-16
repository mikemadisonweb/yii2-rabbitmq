<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\tests\components;

use mikemadisonweb\rabbitmq\components\AbstractConnectionFactory;
use mikemadisonweb\rabbitmq\tests\TestCase;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;

class AbstractConnectionFactoryTest extends TestCase
{
    public function testCreateConnection() {
        $testOptions = [
            'name' => 'test',
            'url' => null,
            'host' => null,
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'connection_timeout' => 3,
            'read_write_timeout' => 3,
            'ssl_context' => null,
            'keepalive' => false,
            'heartbeat' => 0,
            'channel_rpc_timeout' => 0.0,
        ];

        $factory = new AbstractConnectionFactory(AMQPLazyConnection::class, $testOptions);
        $connection = $factory->createConnection();
        $this->assertInstanceOf(AbstractConnection::class, $connection);
    }
}
