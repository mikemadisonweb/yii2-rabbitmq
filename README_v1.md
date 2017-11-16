RabbitMQ Extension for Yii2
==================
Wrapper based on php-amqplib to incorporate messaging in your Yii2 application via RabbitMQ. Inspired by RabbitMqBundle for Symfony framework which is awesome.

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
"mikemadisonweb/yii2-rabbitmq": "^1.7.0"
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
                    'queue_options' => [
                        'declare' => false, // Use this if you don't want to create a queue on producing messages
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
                        'name' => 'import_data', // Queue name which will be binded to the exchange adove
                        'routing_keys' => ['import_data'], // Name of the exchange to bind to
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
                            // Other optional settings can be listed here (like in queue_options)
                            'durable' => true,
                        ],
                        'update_index' => [
                            'name' => 'update_index',
                            'callback' => \path\to\UpdateIndexConsumer::class,
                            'routing_keys' => ['update_index'],
                            // Refer to the Options section for more
                            'exclusive' => true, // Optional
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
                'category' => 'application',
                'print_console' => false,
                'system_memory' => false,
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

Options
-------------
All default options are taken from php-amqplib library. Complete explanation about options, their defaults and valuable details can be found in [AMQP 0-9-1 Reference Guide](http://www.rabbitmq.com/amqp-0-9-1-reference.html). 

#####  Exchange
For example, to declare an exchange you should provide name and type for it.

parameter | required | type | default | comments
--- | --- | --- | --- | ---
name | yes | string |  | The exchange name consists of a non-empty sequence of these characters: letters, digits, hyphen, underscore, period, or colon.
type | yes | string |  | Type of the exchange, possible values are `direct`, `fanout`, `topic` and `headers`.
declare | no | boolean | true | Whether to declare a exchange on sending or consuming messages.
passive | no | boolean | false | If set to true, the server will reply with Declare-Ok if the exchange already exists with the same name, and raise an error if not. The client can use this to check whether an exchange exists without modifying the server state. When set, all other method fields except name and no-wait are ignored. A declare with both passive and no-wait has no effect.
durable | no | boolean | false | Durable exchanges remain active when a server restarts. Non-durable exchanges (transient exchanges) are purged if/when a server restarts.
auto_delete | no | boolean | true | If set to true, the exchange would be deleted when no queues are binded to it anymore.
internal | no | boolean | false | Internal exchange may not be used directly by publishers, but only when bound to other exchanges.
nowait | no | boolean | false | Client may send next request immediately after sending the first one, no waiting for reply is required
arguments | no | array | null | A set of arguments for the declaration.
ticket | no | integer | null | Access ticket

Good use-case of the `arguments` parameter usage can be a creation of a [dead-letter-exchange](https://github.com/php-amqplib/php-amqplib/blob/master/demo/queue_arguments.php#L17).
#####  Queue
As for the queue declaration, all parameters are optional. Even if you does not provide a name for your queue server will generate unique name for you: 

parameter | required | type | default | comments
--- | --- | --- | --- | ---
name | no | string | '' | The queue name can be empty, or a sequence of these characters: letters, digits, hyphen, underscore, period, or colon. 
declare | no | boolean | true | Whether to declare a queue on sending or consuming messages.
passive | no | boolean | false | If set to true, the server will reply with Declare-Ok if the queue already exists with the same name, and raise an error if not.
durable | no | boolean | false | Durable queues remain active when a server restarts. Non-durable queues (transient queues) are purged if/when a server restarts.
auto_delete | no | boolean | true | If set to true, the queue is deleted when all consumers have finished using it. 
exclusive | no | boolean | false | Exclusive queues may only be accessed by the current connection, and are deleted when that connection closes. Passive declaration of an exclusive queue by other connections are not allowed.
nowait | no | boolean | false | Client may send next request immediately after sending the first one, no waiting for reply is required
arguments | false | array | null | A set of arguments for the declaration. 
ticket | no | integer | null | Access ticket

Beware that not all these options are allowed to be changed 'on-the-fly', in other words after queue or exchange had already been created. Otherwise, you will receive an error. 
