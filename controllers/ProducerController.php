<?php

namespace mikemadisonweb\rabbitmq\controllers;

use mikemadisonweb\rabbitmq\components\BaseRabbitMQ;
use yii\console\Controller;
use yii\helpers\Console;

/**
 * RabbitMQ producer functionality
 * @package mikemadisonweb\rabbitmq\controllers
 */
class ProducerController extends Controller
{
    const FORMAT_PHP = 'php';
    const FORMAT_RAW = 'raw';

    public $format = self::FORMAT_RAW;
    public $route;
    public $debug = false;

    protected $consumer;

    protected $options = [
        'f' => 'format',
        'r' => 'route',
        'd' => 'debug',
    ];

    /**
     * @param string $actionID
     * @return array
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), array_values($this->options));
    }

    /**
     * @return array
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), $this->options);
    }

    public function init()
    {
        \Yii::$app->rabbitmq->load();
    }

    /**
     * Pubish messages from STDIN to queue
     * @param $name
     * @return int|null
     */
    public function actionPublish($name)
    {
        $this->setDebug();
        $producer = \Yii::$container->get(sprintf(BaseRabbitMQ::PRODUCER_SERVICE_NAME, $name));
        $data = '';
        while (!feof(STDIN)) {
            $data .= fread(STDIN, 8192);
        }
        switch ($this->format) {
            case self::FORMAT_RAW:
                break; // data as is
            case self::FORMAT_PHP:
                $data = serialize($data);
                break;
            default:
                $formats = implode(', ', [self::FORMAT_PHP, self::FORMAT_RAW]);
                $message = sprintf('Invalid payload format "%s", expecting one of: %s.', $this->format, $formats);
                throw new \InvalidArgumentException($message);
        }
        $producer->publish($data, $this->route);
        $this->stdout("Message was successfully published.\n", Console::FG_GREEN);

        return self::EXIT_CODE_NORMAL;
    }

    private function setDebug()
    {
        if (defined('AMQP_DEBUG') === false) {
            if ($this->debug === 'false') {
                $this->debug = false;
            }
            define('AMQP_DEBUG', (bool)$this->debug);
        }
    }
}