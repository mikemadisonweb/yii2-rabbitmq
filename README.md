RabbitMQ Extension for Yii2
==================
Wrapper based on php-amqplib library to incorporate messaging in your Yii2 application via RabbitMQ. Inspired by RabbitMqBundle for Symfony framework.

This documentation is relevant for the version 2.\*, which require PHP version >=7.0. For legacy PHP applications >=5.4 please use [previous version of this extension](https://github.com/mikemadisonweb/yii2-rabbitmq/blob/master/README_v1.md).

[![Latest Stable Version](https://poser.pugx.org/mikemadisonweb/yii2-rabbitmq/v/stable)](https://packagist.org/packages/mikemadisonweb/yii2-rabbitmq)
[![License](https://poser.pugx.org/mikemadisonweb/yii2-rabbitmq/license)](https://packagist.org/packages/mikemadisonweb/yii2-rabbitmq)
[![Build Status](https://travis-ci.org/mikemadisonweb/yii2-rabbitmq.svg?branch=master)](https://travis-ci.org/mikemadisonweb/yii2-rabbitmq)
[![Coverage Status](https://coveralls.io/repos/github/mikemadisonweb/yii2-rabbitmq/badge.svg?branch=master)](https://coveralls.io/github/mikemadisonweb/yii2-rabbitmq?branch=master)
[![FOSSA Status](https://app.fossa.io/api/projects/git%2Bgithub.com%2Fmikemadisonweb%2Fyii2-rabbitmq.svg?type=shield)](https://app.fossa.io/projects/git%2Bgithub.com%2Fmikemadisonweb%2Fyii2-rabbitmq?ref=badge_shield)

Installation
------------
The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run
```
php composer.phar require mikemadisonweb/yii2-rabbitmq
```
or add
```json
"mikemadisonweb/yii2-rabbitmq": "^2.2.0"
```
to the require section of your `composer.json` file.

Configuration
-------------
This extension facilitates the creation of RabbitMQ [producers and consumers](https://www.rabbitmq.com/tutorials/tutorial-three-php.html) to meet your specific needs. This is an example basic config:
```php
<?php
return [
    // should be in common.php
    'components'    => [
        // ...
        'rabbitmq' => [
            'class' => \mikemadisonweb\rabbitmq\Configuration::class,
            'connections' => [
                [
                    // You can pass these parameters as a single `url` option: https://www.rabbitmq.com/uri-spec.html
                    'host' => 'YOUR_HOSTNAME',
                    'port' => '5672',
                    'user' => 'YOUR_USERNAME',
                    'password' => 'YOUR_PASSWORD',
                    'vhost' => '/',
                ]
                // When multiple connections is used you need to specify a `name` option for each one and define them in producer and consumer configuration blocks 
            ],
            'exchanges' => [
                [
                    'name' => 'YOUR_EXCHANGE_NAME',
                    'type' => 'direct'
                    // Refer to Defaults section for all possible options
                ],
            ],
            'queues' => [
                [
                    'name' => 'YOUR_QUEUE_NAME',
                    // Queue can be configured here the way you want it:
                    //'durable' => true,
                    //'auto_delete' => false,
                ],
                [
                    'name' => 'YOUR_ANOTHER_QUEUE_NAME',
                ],
            ],
            'bindings' => [
                [
                    'queue' => 'YOUR_QUEUE_NAME',
                    'exchange' => 'YOUR_EXCHANGE_NAME',
                    'routing_keys' => ['YOUR_ROUTING_KEY'],
                ],
            ],
            'producers' => [
                [
                    'name' => 'YOUR_PRODUCER_NAME',
                ],
            ],
            'consumers' => [
                [
                    'name' => 'YOUR_CONSUMER_NAME',
                    // Every consumer should define one or more callbacks for corresponding queues
                    'callbacks' => [
                        // queue name => callback class name
                        'YOUR_QUEUE_NAME' => \path\to\YourConsumer::class,
                    ],
                ],
            ],
        ],
    ],
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
                    'class' => \path\to\YourConsumer::class,
                ],
                [
                    // If dependency is needed
                    'some-dependency' => Instance::of('dependency-service-name'),
                ],
            ],
        ],
    ],
];
```
If you need several consumers you can list respective entries in the configuration, but that would require a separate worker(daemon process) for each of that consumers. While it can be absolutely fine in some cases if you are dealing with small queues which consuming messages really fast you may want to group them into one worker. So just list your callbacks in consumer config and one worker will perform your business logic on multiple queues.

Be sure that all queues and exchanges are defined in corresponding bindings, it's up to you to set up correct message routing.
#### Lifecycle events
There are also some lifecycle events implemented: before_consume, after_consume, before_publish, after_publish. You can use them for any additional work you need to do before or after message been consumed/published. For example, make sure that Yii knows the database connection has been closed by a timeout as a consumer is a long-running process: 
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
                if (\Yii::$app->has('db') && \Yii::$app->db->isActive) {
                    try {
                        \Yii::$app->db->createCommand('SELECT 1')->query();
                    } catch (\yii\db\Exception $exception) {
                        \Yii::$app->db->close();
                    }
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
                'log' => true,
                'category' => 'application',
                'print_console' => false,
                'system_memory' => false,
            ],
        ],
        // ...
    ],
];
```
Logger disabled by default. When enabled it will log messages into main application log or to your own log target if you specify corresponding category name. Option 'print_console' gives you additional information while debugging a consumer in you console.

#### Example
Simple setup of Yii2 basic template with the RabbitMQ extension is available [here](https://bitbucket.org/MikeMadison/yii2-rabbitmq-test). Feel free to experiment with it and debug your existing configuration in an isolated manner.

Console commands
-------------
Extension provides several console commands:
- **rabbitmq/consume** - Run a consumer
- **rabbitmq/declare-all** - Create RabbitMQ exchanges, queues and bindings based on configuration
- **rabbitmq/declare-exchange** - Create the exchange listed in configuration
- **rabbitmq/declare-queue** - Create the queue listed in configuration
- **rabbitmq/delete-all** - Delete all RabbitMQ exchanges and queues that is defined in configuration
- **rabbitmq/delete-exchange** - Delete the exchange
- **rabbitmq/delete-queue** - Delete the queue
- **rabbitmq/publish** - Publish a message from STDIN to the queue
- **rabbitmq/purge-queue** - Delete all messages from the queue

To start a consumer:
```
yii rabbitmq/consume YOUR_CONSUMER_NAME
```
In this case, you can use process control system, like Supervisor, to restart consumer process and this way keep your worker run continuously.
#### Message limit
As PHP daemon especially based upon a framework may be prone to memory leaks, it may be reasonable to limit the number of messages to consume and stop:
```
--memoryLimit, -l:  (defaults to 0)
--messagesLimit, -m:  (defaults to 0)
```
#### Auto-declare
By default extension configured in auto-declare mode, which means that on every message published exchanges, queues and bindings will be checked and created if missing. If performance means much to your application you should disable that feature in configuration and use console commands to declare and delete routing schema by yourself.

Usage
-------------
As the consumer worker will read messages from the queue, execute a callback method and pass a message to it. 
#### Consume
In order a class to become a callback it should implement ConsumerInterface:
```php
<?php

namespace components\rabbitmq;

use mikemadisonweb\rabbitmq\components\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class YourConsumer implements ConsumerInterface
{
    /**
     * @param AMQPMessage $msg
     * @return bool
     */
    public function execute(AMQPMessage $msg)
    {
        $data = $msg->body;
        // Apply your business logic here
        
        return ConsumerInterface::MSG_ACK;
    }
}
```
You can publish any data type(object, int, array etc), despite the fact that RabbitMQ will transfer payload as a string here in consumer $msg->body your data will be of the same type it was sent.
#### Return codes
As for the return codes there is a bunch of them in order for you to control following processing of the message by the broker:
- **ConsumerInterface::MSG_ACK** - Acknowledge message (mark as processed) and drop it from the queue
- **ConsumerInterface::MSG_REJECT** - Reject and drop message from the queue
- **ConsumerInterface::MSG_REJECT_REQUEUE** - Reject and requeue message in RabbitMQ
#### Publish
 Here is an example how you can publish a message:
```php
$producer = \Yii::$app->rabbitmq->getProducer('YOUR_PRODUCER_NAME');
$msg = serialize(['dataset_id' => 657, 'linked_datasets' => []]);
$producer->publish($msg, 'YOUR_EXCHANGE_NAME', 'YOUR_ROUTING_KEY');
```
Routing key as third parameter is optional, which can be the case for fanout exchanges.

By default connection to broker only get established upon publishing a message, it would not try to connect on each HTTP request if there is no need to.

Options
-------------
All configuration options:
```php
$rabbitmq_defaults = [
        'auto_declare' => true,
        'connections' => [
            [
                'name' => self::DEFAULT_CONNECTION_NAME,
                'type' => AMQPLazyConnection::class,
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
                'channel_rpc_timeout' => 0.0
            ],
        ],
        'exchanges' => [
            [
                'name' => null,
                'type' => null,
                'passive' => false,
                'durable' => true,
                'auto_delete' => false,
                'internal' => false,
                'nowait' => false,
                'arguments' => null,
                'ticket' => null,
            ],
        ],
        'queues' => [
            [
                'name' => '',
                'passive' => false,
                'durable' => true,
                'exclusive' => false,
                'auto_delete' => false,
                'nowait' => false,
                'arguments' => null,
                'ticket' => null,
            ],
        ],
        'bindings' => [
            [
                'exchange' => null,
                'queue' => null,
                'to_exchange' => null,
                'routing_keys' => [],
            ],
        ],
        'producers' => [
            [
                'name' => null,
                'connection' => self::DEFAULT_CONNECTION_NAME,
                'safe' => true,
                'content_type' => 'text/plain',
                'delivery_mode' => 2,
                'serializer' => 'serialize',
            ],
        ],
        'consumers' => [
            [
                'name' => null,
                'connection' => self::DEFAULT_CONNECTION_NAME,
                'callbacks' => [],
                'qos' => [
                    'prefetch_size' => 0,
                    'prefetch_count' => 0,
                    'global' => false,
                ],
                'idle_timeout' => 0,
                'idle_timeout_exit_code' => null,
                'proceed_on_exception' => false,
                'deserializer' => 'unserialize',
            ],
        ],
        'logger' => [
            'log' => false,
            'category' => 'application',
            'print_console' => true,
            'system_memory' => false,
        ],
    ];
```
#####  Exchange
For example, to declare an exchange you should provide name and type for it.

parameter | required | type | default | comments
--- | --- | --- | --- | ---
name | yes | string |  | The exchange name consists of a non-empty sequence of these characters: letters, digits, hyphen, underscore, period, or colon.
type | yes | string |  | Type of the exchange, possible values are `direct`, `fanout`, `topic` and `headers`.
passive | no | boolean | false | If set to true, the server will reply with Declare-Ok if the exchange already exists with the same name, and raise an error if not. The client can use this to check whether an exchange exists without modifying the server state. When set, all other method fields except name and no-wait are ignored. A declare with both passive and no-wait has no effect.
durable | no | boolean | false | Durable exchanges remain active when a server restarts. Non-durable exchanges (transient exchanges) are purged if/when a server restarts.
auto_delete | no | boolean | true | If set to true, the exchange would be deleted when no queues are bound to it anymore.
internal | no | boolean | false | Internal exchange may not be used directly by publishers, but only when bound to other exchanges.
nowait | no | boolean | false | Client may send next request immediately after sending the first one, no waiting for the reply is required
arguments | no | array | null | A set of arguments for the declaration.
ticket | no | integer | null | Access ticket

Good use-case of the `arguments` parameter usage can be a creation of a [dead-letter-exchange](https://github.com/php-amqplib/php-amqplib/blob/master/demo/queue_arguments.php#L17).
#####  Queue
As for the queue declaration, all parameters are optional. Even if you do not provide a name for your queue server will generate a unique name for you: 

parameter | required | type | default | comments
--- | --- | --- | --- | ---
name | no | string | '' | The queue name can be empty, or a sequence of these characters: letters, digits, hyphen, underscore, period, or colon. 
passive | no | boolean | false | If set to true, the server will reply with Declare-Ok if the queue already exists with the same name, and raise an error if not.
durable | no | boolean | false | Durable queues remain active when a server restarts. Non-durable queues (transient queues) are purged if/when a server restarts.
auto_delete | no | boolean | true | If set to true, the queue is deleted when all consumers have finished using it. 
exclusive | no | boolean | false | Exclusive queues may only be accessed by the current connection, and are deleted when that connection closes. Passive declaration of an exclusive queue by other connections are not allowed.
nowait | no | boolean | false | Client may send next request immediately after sending the first one, no waiting for the reply is required
arguments | false | array | null | A set of arguments for the declaration. 
ticket | no | integer | null | Access ticket

A complete explanation about options, their defaults, and valuable details can be found in [AMQP 0-9-1 Reference Guide](http://www.rabbitmq.com/amqp-0-9-1-reference.html). 

Beware that not all these options are allowed to be changed 'on-the-fly', in other words after queue or exchange had already been created. Otherwise, you will receive an error.

Breaking Changes
-------------
Since version 1.\* this extension was completely rewritten internally and can be considered brand new. However, the following key differences can be distinguished:
- PHP version 7.0 and above required
- Configuration format changed
- All extension components get automatically loaded using [Yii2 Bootstraping](http://www.yiiframework.com/doc-2.0/guide-structure-extensions.html#bootstrapping-classes)
- Different connection types supported
- All extension components are registered in DIC as singletons
- Routing component added to control schema in broker
- Queue and exchange default options changed
- Console commands are joined into one controller class which is added automatically and doesn't need to be configured
- New console commands added to manipulate with routing schema
- All data types are supported for message payload
- Consumer handles control signals in a predictable manner


## License
[![FOSSA Status](https://app.fossa.io/api/projects/git%2Bgithub.com%2Fmikemadisonweb%2Fyii2-rabbitmq.svg?type=large)](https://app.fossa.io/projects/git%2Bgithub.com%2Fmikemadisonweb%2Fyii2-rabbitmq?ref=badge_large)
