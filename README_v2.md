RabbitMQ Extension for Yii2
==================

**Advanced usage** 

Для предотвращения потери сообщений при обмене с RabbitMq рекомендуется использовать расширенные настройки для настройки продюсеров и слушателей (воркеров).

**Пример конфига:**

```
<?php

use app\components\TestConsumer;
use mikemadisonweb\rabbitmq\Configuration;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;

return [
    'class' => Configuration::class,
    'connections' => [
        [
            'type' => $_ENV['RABBITMQ_SSL'] ? AMQPSSLConnection::class : AMQPLazyConnection::class,
            'host' => $_ENV['RABBITMQ_HOST'],
            'port' => $_ENV['RABBITMQ_PORT'],
            'user' => $_ENV['RABBITMQ_USER'],
            'password' => $_ENV['RABBITMQ_PASSWD'],
            'vhost' => $_ENV['RABBITMQ_VHOST'],
            'ssl_context' => $_ENV['RABBITMQ_SSL'] ? [
                'capath' => null,
                'cafile' => null,
                'verify_peer' => false,
            ] : null
        ],
    ],
    'exchanges' => [
        [
            'name' => 'test_exchange',
            'type' => 'direct'
        ],
    ],
    'queues' => [
        [
            'name' => 'test_queue',
        ],
    ],
    'producers' => [
        [
            'name' => 'test_producer',
        ],
    ],
    'bindings' => [
        [
            'queue' => 'test_queue',
            'exchange' => 'test_exchange',
        ],
    ],
    'consumers' => [
        [
            'name' => 'test_consumer',
            'callbacks' => [
                'test_queue' => TestConsumer::class
            ],
            'systemd' => [
                'memory_limit' => 8, // mb
                'workers' => 3
            ],
        ],
    ],
];
```

--------------------

**Настройка продюсеров** сводится к тому, что неотправленные сообщения сохраняются в таблице `rabbit_publish_error`, класс `\mikemadisonweb\rabbitmq\models\RabbitPublishError`, и отправляются, например, по крону.

* в файле конфига консольного приложения в секции controllerMap прописываем namespace для миграций компонента

```
...
'controllerMap' => [
        'migrate' => [
            'class' => 'yii\console\controllers\MigrateController',
            'migrationNamespaces' => [
                'mikemadisonweb\rabbitmq\migrations'
            ],
        ],
    ],
...
```

Выполняем `php yii migrate`

* при вызове продюсера отлавливаем исключения, и пишем сообщения в БД, пример:

```
public function actionPublish()
    {
        $producer = \Yii::$app->rabbitmq->getProducer('test_producer');
        $data = [
            'counter' => 1,
            'msg' => 'I\'am test publish'
        ];
        while (true) {
            sleep(1);
            try {
                $producer->publish(json_encode($data), 'test_exchange');
                $data['counter']++;
            } catch (\Exception $e) {
                $model_error = new RabbitPublishError();
                $model_error->exchangeName = 'test_exchange';
                $model_error->producerName = 'test_producer';
                $model_error->msgBody = json_encode($data);
                $model_error->errorMsg = $e->getMessage();
                $model_error->saveItem();
            }
        }
    }
```

* пример повторной отправки сохраненных сообщений

```
    public function actionRePublish()
    {
        $republish = new RabbitPublishError();
        $republish->rePublish();
    }
```
Если повторное сообщение отправлено успешно, то запись удаляется, иначе поле counter увеличивается на 1.

--------------

**Для расширенной настройки воркеров** необходимо запустить их в виде демонов с помощью systemd.   
Благодаря systemd мы можем решить две главные проблемы:

1. Перезапуск воркеров при разрыве соединения

2. Перезапуск воркеров при достижении memory limit

Также с помощью systemd мы можем запускать несколько экземпляров воркеров для одной очереди

* В конфиге rabbitmq, в секции `consumers` прописываем дополнительные настройки для systemd: для очереди `test_queue` запустить три воркера `test_consumer`, лимит памяти для каждого - 8 мб.

```
    'consumers' => [
        [
            'name' => 'test_consumer',
            'callbacks' => [
                'test_queue' => TestConsumer::class
            ],
            'systemd' => [
                'memory_limit' => 8, // mb
                'workers' => 3
            ],
        ],
    ],
```  

* Для автоматической генерации юнитов systemd рекомендуется использовать хелпер `\mikemadisonweb\rabbitmq\helpers\CreateUnitHelper`

При объявлении хелпера необходимо определить следующие поля:

```
    /** @var string папка в которой будут созданы юниты, должна быть доступна на запись */
    public $units_dir;

    /** @var string имя пользователя от имени которого будут запускаться юниты */
    public $user;

    /** @var string имя группы для запуска юнитов */
    public $group;

    /** @var string директория с исполняемым файлом yii */
    public $work_dir;
```
Также в хелпере есть поле `example`, в нем хранится шаблон для генерации юнита. Рекомендуется его изучить и, при необходимости, переобъявить. Особое внимание секции `[Unit]`

```
    public $example = '[Unit]
Description=%description%
After=syslog.target
After=network.target
After=postgresql.service
Requires=postgresql.service

[Service]
Type=simple
WorkingDirectory=%work_dir%

User=%user%
Group=%group%

ExecStart=php %yii_path% rabbitmq/consume %name_consumer% %memory_limit%
ExecReload=php %yii_path% rabbitmq/restart-consume %name_consumer% %memory_limit%
TimeoutSec=3
Restart=always

[Install]
WantedBy=multi-user.target';
```
Пример работы с хелпером, контроллер

```
<?php

namespace app\commands;

use mikemadisonweb\rabbitmq\helpers\CreateUnitHelper;
use yii\console\Controller;
use Yii;

class CreateUnitsController extends Controller
{
    public function actionIndex()
    {
        $helper = new CreateUnitHelper(
            [
                'units_dir' => Yii::getAlias('@runtime/units'),
                'work_dir' => Yii::getAlias('@app'),
                'user' => 'vagrant',
                'group' => 'vagrant',
            ]
        );

        $helper->create();
    }
}
```

Не забываем запустить генерацию юнитов: `php yii create-units`

* После генерации юнитов в папке c юнитами будет сгенерирован также баш скрипт exec.sh. При запуске, на вход могут быть переданы следующие команды: `copy | start | restart | status | delete`. Данный скрипт работает по маске со всеми сгенерированными юнитами.

После первоначальной генерации юнитов, достаточно запустить команду `sh exec.sh copy`

**Итак, для расширенной работы с воркерами** необходимо выполнить три шага

1. Объявить параметры для systemd в конфиге RabbitMq

2. Сгенерировать юниты для systemd

3. Запустить воркеры как демоны под управлением systemd

**Enjoy!**

