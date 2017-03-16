RabbitMQ Extension for Yii2
==================
Wrapper based on php-amqplib to incorporate messaging in your Yii2 application via RabbitMQ. Inspired by RabbitMqBundle for Symfony 2, really awesome package.

[![Latest Stable Version](https://poser.pugx.org/mikemadisonweb/yii2-rabbitmq/v/stable)](https://packagist.org/packages/mikemadisonweb/yii2-rabbitmq)

Installation
------------
The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run
```
php composer.phar require --prefer-dist mikemadisonweb/yii2-rabbitmq
```
or add
```json
"mikemadisonweb/yii2-rabbitmq": "^1.0"
```
to the require section of your `composer.json` file.

Configuration
-------------
This extension facilitates creation of RabbitMQ [producers and consumers](https://www.rabbitmq.com/tutorials/tutorial-three-php.html) to meet your specific needs. This is an example basic config:
```php
<?php
// config/main.php
return [
    // ...
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
                        'name' => 'import_data',
                        'type' => 'direct',
                    ],
                    'queue_options' => [
                        'name' => 'import_data',
                    ],
                    'callback' => \path\to\ImportDataConsumer::class,
                ],
            ],
        ],
        // ...
    ],
    // ...
    'controllerMap' => [
        'rabbitmq-consumer' => \mikemadisonweb\rabbitmq\controllers\ConsumerController::className(),
        'rabbitmq-producer' => \mikemadisonweb\rabbitmq\controllers\ProducerController::className(),
    ],
    // ...
];
```
Think of a producer as your entry point for a message, then it would be passed to the RabbitMQ queue using specified connection details. Consumer is a daemon service that would take messages from the queue and process them.

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
                            'routing_keys' => ['import_data'],
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
There are also couple of lifecycle events implemented: before_consume and after_consume. You can use them for any additional work you need to do before or after message been consumed. For example, reopen database connection for it not to be closed by timeout as a consumer is a long-running process: 
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

As PHP daemon especially based upon a framework may be prone to memory leaks, it may be reasonable to limit number of messages to consume and stop:
```
yii rabbitmq-consumer/single import_data -m=10
```
In this case you can use process control system, like supervisor, to restart consumer process and this way keep your worker run continuously.

Usage
-------------
As the consumer worker will read messages from the queue, it executes a callback and pass a message into it. Callback class should implements ConsumerInterface:
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
$producer = \Yii::$container->get(sprintf('rabbit_mq.producer.%s', 'exchange_name'));
$msg = serialize(['dataset_id' => $dataset->id, 'linked_datasets' => []]);
$producer->publish($msg, 'import_data');
```
This template for a service name 'rabbit_mq.producer.%s' is also available as a constant mikemadisonweb\rabbitmq\components\BaseRabbitMQ::PRODUCER_SERVICE_NAME. It's needed because producer classes are lazy loaded, that means they are only got created on demand. Likewise the Connection class also got created on demand, that means a connection to RabbitMQ would not be established on each request.
