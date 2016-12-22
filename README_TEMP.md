RabbitMQ Extension for Yii2
==================
Wrapper based on php-amqplib to incorporate messaging in your Yii2 application via RabbitMQ. Inspired by RabbitMqBundle for Symfony 2, really awesome package.

[![Latest Stable Version](https://poser.pugx.org/phpunit/phpunit/version)](https://packagist.org/packages/mikemadisonweb/yii2-rabbitmq)

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
                    'callback' => \path\to\YourConsumer::class,
                ],
            ],
        ],
        // ...
    ],
];
```
Think of a producer as your entry point for a message, then it would be passed to the RabbitMQ queue using specified connection details. Consumer is a daemon service that would take messages from the queue and process them.

You also need to add console controllers to you config file in order to use built-in console commands:
```php
// config/main.php
return [
    // ...
    'controllerMap' => [
        'rabbitmq-consumer' => \mikemadisonweb\rabbitmq\controllers\ConsumerController::className(),
        'rabbitmq-producer' => \mikemadisonweb\rabbitmq\controllers\ProducerController::className(),
    ],
    // ...
];
```
#### Multiple consumers
If you need several consumers you can list respective entries in the configuration, but that would require a separate worker(daemon process) for each of that consumers. While it can be absolutely fine in some cases if you are dealing with small queues which consuming messages really fast you may want to group them into one worker.

This is how you can set a consumer with multiple queues:
```php
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
                            'callback' => \path\to\YourConsumer::class,
                            'routing_keys' => ['import_data'],
                        ],
                        'update_index' => [
                            'name' => 'update_index',
                            'callback' => \path\to\YourAnotherConsumer::class,
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
// config/main.php
return [
    // ...
    'components'    => [
        // ...
        'rabbitmq'  => [
            // ...
            'on before_consume' => function ($event) {
                if (isset(\Yii::$app->db)) {
                    if (\Yii::$app->db->getIsActive()) {
                        \Yii::$app->db->close();
                    }
                    \Yii::$app->db->open();
                }
            },
        ],
        // ...
    ],
];
```
#### Logger
```php
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

Console commands
-------------
- rabbitmq-consumer                             
    rabbitmq-consumer/multiple                  Run consumer(one instance per multiple queues)
    rabbitmq-consumer/setup-fabric              Setup RabbitMQ exchanges and queues based on configuration
    rabbitmq-consumer/single                    Run consumer(one instance per queue)

- rabbitmq-producer                             
    rabbitmq-producer/publish                   Pubish messages from STDIN to queue


Usage
-------------
```php
\Yii::$app->rabbitmq->load();
$producer = \Yii::$container->get(sprintf(BaseRabbitMQ::PRODUCER_SERVICE_NAME, 'exchange_name'));
$msg = ['dataset_id' => $dataset->id, 'linked_datasets' => []];
$producer->publish($msg, 'import_data');
```