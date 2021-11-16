<?php

namespace mikemadisonweb\rabbitmq\models;

use DomainException;
use Exception;
use InvalidArgumentException;
use mikemadisonweb\rabbitmq\components\Producer;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "rabbit_publish_error".
 *
 * @property int $id
 * @property string $message
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property string $options
 * @property string|null $error
 * @property int|null $counter
 */
class RabbitPublishError extends ActiveRecord
{
    /** @var string */
    public $msgBody;
    /** @var string */
    public $exchangeName;
    /** @var string */
    public $routingKey = '';
    /** @var array */
    public $additionalProperties = [];
    /** @var array|null */
    public $headers = null;
    /** @var string */
    public $producerName;
    /** @var string */
    public $errorMsg;

    public static function tableName()
    {
        return 'rabbit_publish_error';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules()
    {
        return [
            [['message', 'options'], 'required'],
            [['message', 'error'], 'string'],
            [['created_at', 'updated_at', 'counter'], 'integer'],
            [['options'], 'safe'],
        ];
    }

    public function saveItem()
    {
        if (!$this->producerName) {
            throw new InvalidArgumentException('Field producerName is required!');
        }
        if (!$this->msgBody) {
            throw new InvalidArgumentException('Field msgBody is required!');
        }
        if (!$this->exchangeName) {
            throw new InvalidArgumentException('Field exchangeName is required!');
        }
        if (!$this->errorMsg) {
            throw new InvalidArgumentException('Field errorMsg is required!');
        }
        $this->message = $this->msgBody;
        $options = [
            'exchangeName' => $this->exchangeName,
            'producerName' => $this->producerName,
            'routingKey' => $this->routingKey,
            'additionalProperties' => $this->additionalProperties,
            'headers' => $this->headers
        ];
        $this->options = json_encode($options);
        $this->error = $this->errorMsg;
        $this->counter = 1;
        if (!$this->save()) {
            print_r($this->errors);
            throw new DomainException();
        }
    }

    public function rePublish()
    {
        $cache = Yii::$app->cache;
        $key = 'rabbit_publish_error';
        if ($cache->exists($key)) {
            return;
        }
        $cache->set($key, true);

        foreach (self::find()->each() as $model) {
            /** @var self $model */
            try {
                $options = json_decode($model->options, true);
                /** @var Producer $producer */
                $producer = Yii::$app->rabbitmq->getProducer($options['producerName']);
                $producer->publish(
                    $model->message,
                    $options['exchangeName'],
                    $options['routingKey'],
                    $options['additionalProperties'],
                    $options['headers']
                );
                $model->delete();
            } catch (Exception $e) {
                $model->error = $e->getMessage();
                $model->updateCounters(['counter' => 1]);
                $model->save();
            }
        }
        $cache->delete($key);
    }
}
