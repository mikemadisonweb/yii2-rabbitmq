<?php

namespace mikemadisonweb\rabbitmq;

use yii\base\Application;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Configuration extends Component
{
    const CONNECTION_CLASS = '\PhpAmqpLib\Connection\AMQPLazyConnection';

    public $autoDeclare = true;
    public $connections = [];
    public $producers = [];
    public $consumers = [];
    public $queues = [];
    public $exchanges = [];
    public $bindings = [];
    public $logger = [];

    /**
     * Extension configuration default values
     * @return array
     */
    protected function getDefaults() : array
    {
        return [
            'autoDeclare' => true,
            'connections' => [
                'url' => '',
                'host' => 'localhost',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
                'connection_timeout' => 3,
                'read_write_timeout' => 3,
                'ssl_context' => null,
                'keepalive' => false,
                'heartbeat' => 0,
            ],
            'exchanges' => [
                'name' => '',
                'type' => '',
                'passive' => false,
                'durable' => false,
                'auto_delete' => true,
                'internal' => false,
                'nowait' => false,
                'arguments' => null,
                'ticket' => null,
                'declare' => true,
            ],
            'queues' => [
                'name' => '',
                'passive' => false,
                'durable' => false,
                'exclusive' => false,
                'auto_delete' => true,
                'nowait' => false,
                'arguments' => null,
                'ticket' => null,
                'declare' => true,
            ],
            'bindings' => [
                'exchange' => '',
                'queue' => null,
                'toExchange' => null,
                'routingKey' => '',
            ],
            'producers' => [
                'connection' => '',
                'exchanges' => [],
                'serializer' => function () {},
            ],
            'consumers' => [
                'connection' => '',
                'queues' => [],
                'callbacks' => [],
                'qos' => [
                    'prefetch_size' => 0,
                    'prefetch_count' => 0,
                    'global' => false,
                ],
                'idle_timeout' => null,
                'idle_timeout_exit_code' => null,
                'unserializer' => function () {},
            ],
            'logger' => [
                'enable' => true,
                'category' => 'application',
                'print_console' => false,
                'system_memory' => false,
            ],
        ];
    }

    /**
     * Configuration auto-loading
     * @param Application $app
     * @throws InvalidConfigException
     */
    public function bootstrap($app)
    {
        $this->logger = $this->logger;
    }
    /**
     * Config validation
     * @param array $passed
     */
    protected function validate(array $passed)
    {
        $this->validateTopLevel($passed);
        $this->validateMultidimensional($passed);
        die();
    }

    /**
     * Validate multidimensional entries names
     * @param array $passed
     * @throws InvalidConfigException
     */
    protected function validateMultidimensional(array $passed)
    {
        $multidimensional = [
            'connection' => $passed['connections'],
            'exchange' => $passed['exchanges'],
            'queue' => $passed['queues'],
            'binding' => $passed['bindings'],
            'producer' => $passed['producers'],
            'consumer' => $passed['consumers'],
        ];

        foreach ($multidimensional as $configName => $configItem) {
            foreach ($configItem as $key => $value) {
                if (!is_int($key)) {
                    throw new InvalidConfigException("Invalid {$configName} key: `{$key}`. The array should be numeric.");
                }
            }
        }
    }

    /**
     * Validate config entry value
     * @param array $passed
     * @param string $key
     * @throws InvalidConfigException
     */
    protected function validateArray(array $passed, string $key)
    {
        $required = $this->getDefaults()[$key];
        $undeclaredFields = array_diff_key($passed, $required);
        if (!empty($undeclaredFields)) {
            $asString = json_encode($undeclaredFields);
            throw new InvalidConfigException("Unknown options: {$asString}");
        }
    }

    protected function validateTopLevel($config)
    {
        if (!is_bool($config['autoDeclare'])) {
            throw new InvalidConfigException("Option `autoDeclare` should be of type boolean.");
        }

        if (!is_array($config['logger'])) {
            throw new InvalidConfigException("Option `logger` should be of type array.");
        }

        $this->validateArray($config['logger'], 'logger');
    }
}
