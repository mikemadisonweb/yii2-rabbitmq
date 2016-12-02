<?php

namespace mikemadisonweb\rabbitmq;

use yii\base\Module;

class RabbitMQ extends Module
{
    public $controllerNamespace = 'mikemadisonweb\rabbitmq\controllers';

    public function init()
    {
        parent::init();
    }
}