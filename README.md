RabbitMQ Extension for Yii2
==================
Wrapper based on php-amqplib to incorporate messaging in your Yii2 application via RabbitMQ. Inspired by RabbitMqBundle for Symfony framework as it is really awesome.

This documentation is relevant for the latest stable version of the extension.

[![Latest Stable Version](https://poser.pugx.org/mikemadisonweb/yii2-rabbitmq/v/stable)](https://packagist.org/packages/mikemadisonweb/yii2-rabbitmq)
[![License](https://poser.pugx.org/mikemadisonweb/yii2-rabbitmq/license)](https://packagist.org/packages/mikemadisonweb/yii2-rabbitmq)

Installation
------------
The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run
```
php composer.phar require mikemadisonweb/yii2-rabbitmq
```
or add
```json
"mikemadisonweb/yii2-rabbitmq": "^1.5.1"
```
to the require section of your `composer.json` file.

Configuration
-------------
This extension facilitates creation of RabbitMQ [producers and consumers](https://www.rabbitmq.com/tutorials/tutorial-three-php.html) to meet your specific needs. This is an example basic config:
```php
<?php
return [
    // should be in common.php
    'components'    => [
        // ...
        'rabbitmq'  => [
            'class' => 'mikemadisonweb\rabbitmq\Configuration',
            'connections' => [
                'default' => [
                    'host' => '127.0.0.1',
                    'port' => '5672',
                    'user' => 'your_username',
                    'password' => 'your_password',
                    'vhost' => '/',
                    'heartbeat' => 0,
                ],
            ],
            'producers' => [
                'import_data' => [
                    'connection' => 'default',
                    'exchange_options' => [
                        'name' => 'import_data',
                        'type' => 'direct',
                    ],
                ],
            ],
            'consumers' => [
                'import_data' => [
                    'connection' => 'default',
                    'exchange_options' => [
                        'name' => 'import_data', // Name of exchange to declare
                        'type' => 'direct', // Type of exchange
                    ],
                    'queue_options' => [
                        'name' => 'import_data', // Queue name which will be binded to the exchange adove
                        'routing_keys' => ['import_data'], // Your custom options
                        'durable' => true,
                        'auto_delete' => false,
                    ],
                    // Or just '\path\to\ImportDataConsumer' in PHP 5.4
                    'callback' => \path\to\ImportDataConsumer::class,
                ],
            ],
        ],
        // ...
    ],
    // should be in console.php
    'controllerMap' => [
        'rabbitmq-consumer' => \mikemadisonweb\rabbitmq\controllers\ConsumerController::class,
        'rabbitmq-producer' => \mikemadisonweb\rabbitmq\controllers\ProducerController::class,
    ],
    // ...
];
```
To use this extension you should be familiar with the basic concepts of RabbitMQ. If you are not confident in your knowledge I suggest reading [this article](https://mikemadisonweb.github.io/2017/05/04/tldr-series-rabbitmq/).

The 'callback' parameter can be a class name or a service name from [dependency injection container](http://www.yiiframework.com/doc-2.0/yii-di-container.html). Starting from Yii version 2.0.11 you can configure your container like this:
```php
<?php
use yii\di\Instance;

return [
    // ...
    'container' => [
        'definitions' => [],
        'singletons' => [
            'rabbitmq.import-data.consumer' => [
                [
                    'class' => \path\to\ImportDataConsumer::class,
                ],
                [
                    'some-dependency' => Instance::of('dependency-service-name'),
                ],
            ],
        ],
    ],
];
```

#### Multiple consumers
If you need several consumers you can list respective entries in the configuration, but that would require a separate worker(daemon process) for each of that consumers. While it can be absolutely fine in some cases if you are dealing with small queues which consuming messages really fast you may want to group them into one worker.

This is how you can set a consumer with multiple queues:
```php
<?php
// config/main.php
return [
    // ...
    'components'    => [
        // ...
        'rabbitmq'  => [
            // ...
            'multipleConsumers' => [
                'import_data' => [
                    'connection' => 'default',
                    'exchange_options' => [
                        'name' => 'exchange_name',
                        'type' => 'direct',
                    ],
                    'queues' => [
                        'import_data' => [
                            'name' => 'import_data',
                            'callback' => \path\to\ImportDataConsumer::class,
                            'routing_keys' => ['import_data'], // Queue will be binded using routing key
                        ],
                        'update_index' => [
                            'name' => 'update_index',
                            'callback' => \path\to\UpdateIndexConsumer::class,
                            'routing_keys' => ['update_index'],
                        ],
                    ],
                ],
            ],
        ],
        // ...
    ],
];
```
Be aware that all queues are under the same exchange, it's up to you to set the correct routing for callbacks.
#### Lifecycle events
There are also couple of lifecycle events implemented: before_consume, after_consume, before_publish, after_publish. You can use them for any additional work you need to do before or after message been consumed/published. For example, reopen database connection for it not to be closed by timeout as a consumer is a long-running process: 
```php
<?php
// config/main.php
return [
    // ...
    'components'    => [
        // ...
        'rabbitmq'  => [
            // ...
            'on before_consume' => function ($event) {
                if (isset(\Yii::$app->db)) {
                    $db = \Yii::$app->db;
                    if ($db->getIsActive()) {
                        $db->close();
                    }
                    $db->open();
                }
            },
        ],
        // ...
    ],
];
```
#### Logger
Last but not least is logger configuration which is also optional:
```php
<?php
// config/main.php
return [
    // ...
    'components'    => [
        // ...
        'rabbitmq'  => [
            // ...
            'logger' => [
                'enable' => true,
                'category' => 'amqp',
                'print_console' => true,
            ],
        ],
        // ...
    ],
];
```
Logger enabled by default, but it log messages into main application log. You can change that by setting your own log target and specify corresponding category name, like 'amqp' is set above. Option 'print_console' disabled by default, it give you additional information while debugging a consumer in you console.

Console commands
-------------
Extension provides several console commands:
- **rabbitmq-consumer/single** - Run consumer(one instance per queue)
- **rabbitmq-consumer/multiple** - Run consumer(one instance per multiple queues)
- **rabbitmq-consumer/setup-fabric** - Setup RabbitMQ exchanges and queues based on configuration
- **rabbitmq-producer/publish** - Pubish messages from STDIN to queue

The most important here is single and multiple consumer commands as it start consumer processes based on consumer and multipleConsumer config respectively.

As PHP daemon especially based upon a framework may be prone to memory leaks, it may be reasonable to limit the number of messages to consume and stop:
```
yii rabbitmq-consumer/single import_data -m=10
```
In this case, you can use process control system, like Supervisor, to restart consumer process and this way keep your worker run continuously.

Usage
-------------
As the consumer worker will read messages from the queue, it executes a callback and passes a message to it. Callback class should implement ConsumerInterface:
```php
<?php

namespace components\rabbitmq;

use mikemadisonweb\rabbitmq\components\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class ImportDataConsumer implements ConsumerInterface
{
    /**
     * @param AMQPMessage $msg
     * @return bool
     */
    public function execute(AMQPMessage $msg)
    {
        $data = unserialize($msg->body);
        
        if ($this->isValid($data)) {
            // Apply your business logic here
            
            return ConsumerInterface::MSG_ACK;
        }
    }
}
```
You can format your message as you wish(JSON, XML, etc) the only restriction is that it should be a string. Here is an example how you can publish a message:
```php
\Yii::$app->rabbitmq->load();
$producer = \Yii::$container->get(sprintf('rabbit_mq.producer.%s', 'import_data'));
$msg = serialize(['dataset_id' => $dataset->id, 'linked_datasets' => []]);
$producer->publish($msg, 'import_data');
```
This template for a service name 'rabbit_mq.producer.%s' is also available as a constant mikemadisonweb\rabbitmq\components\BaseRabbitMQ::PRODUCER_SERVICE_NAME. It's needed because producer classes are lazy loaded, that means they are only got created on demand. Likewise the Connection class also got created on demand, that means a connection to RabbitMQ would not be established on each request.

Defaults
-------------
All default options are taken from php-amqplib library. If you are not familiar with meanings of some of these options, you can find them in [AMQP 0-9-1 Complete Reference Guide](http://www.rabbitmq.com/amqp-0-9-1-reference.html). 

For example, to declare an exchange you should provide name and type for it. Other optional parameters with corresponding default values are listed below:
```php
    $queueDefaults = [
        'passive' => false,
        'durable' => false,
        'auto_delete' => true,
        'internal' => false,
        'nowait' => false,
        'arguments' => null,
        'ticket' => null,
        'declare' => true,
    ];
```
As for the queue declaration, all parameters are optional. Even if you does not provide a name for your queue server will generate unique name for you: 
```php
    $queueDefaults = [
        'name' => '',
        'passive' => false,
        'durable' => false,
        'exclusive' => false,
        'auto_delete' => true,
        'nowait' => false,
        'arguments' => null,
        'ticket' => null,
        'declare' => true,
    ];
```
Beware that not all these options are allowed to be changed 'on-the-fly', in other words after queue or exchange had already been created. Otherwise, you will receive an error. 

Logger default settings:
```php
    $loggerDefaults = [
        'enable' => true,
        'category' => 'application',
        'print_console' => false,
        'system_memory' => false,
    ];
```
