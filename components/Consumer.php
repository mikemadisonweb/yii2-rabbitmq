<?php

namespace mikemadisonweb\rabbitmq\components;

use mikemadisonweb\rabbitmq\events\RabbitMQConsumerEvent;
use mikemadisonweb\rabbitmq\exceptions\RuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use yii\console\Controller;
use yii\helpers\Console;

/**
 * Service that receives AMQP Messages
 * @package mikemadisonweb\rabbitmq\components
 */
class Consumer extends BaseRabbitMQ
{
    protected $deserializer;
    protected $qos;
    protected $idleTimeout;
    protected $idleTimeoutExitCode;
    protected $queues = [];
    protected $memoryLimit = 0;
    protected $proceedOnException;
    protected $name = 'unnamed';

    private $id;
    private $target;
    private $consumed = 0;
    private $forceStop = false;

    /**
     * Set the memory limit
     * @param int $memoryLimit
     */
    public function setMemoryLimit($memoryLimit)
    {
        $this->memoryLimit = $memoryLimit;
    }

    /**
     * Get the memory limit
     *
     * @return int
     */
    public function getMemoryLimit() : int
    {
        return $this->memoryLimit;
    }

    /**
     * @param array $queues
     */
    public function setQueues(array $queues)
    {
        $this->queues = $queues;
    }

    /**
     * @return array
     */
    public function getQueues() : array
    {
        return $this->queues;
    }

    /**
     * @param $idleTimeout
     */
    public function setIdleTimeout($idleTimeout)
    {
        $this->idleTimeout = $idleTimeout;
    }

    public function getIdleTimeout()
    {
        return $this->idleTimeout;
    }

    /**
     * Set exit code to be returned when there is a timeout exception
     * @param int|null $idleTimeoutExitCode
     */
    public function setIdleTimeoutExitCode($idleTimeoutExitCode)
    {
        $this->idleTimeoutExitCode = $idleTimeoutExitCode;
    }

    /**
     * Get exit code to be returned when there is a timeout exception
     * @return int|null
     */
    public function getIdleTimeoutExitCode()
    {
        return $this->idleTimeoutExitCode;
    }

    /**
     * @return mixed
     */
    public function getDeserializer() : callable
    {
        return $this->deserializer;
    }

    /**
     * @param mixed $deserializer
     */
    public function setDeserializer(callable $deserializer)
    {
        $this->deserializer = $deserializer;
    }

    /**
     * @return mixed
     */
    public function getQos() : array
    {
        return $this->qos;
    }

    /**
     * @param mixed $qos
     */
    public function setQos(array $qos)
    {
        $this->qos = $qos;
    }

    /**
     * @param string $name
     */
    public function setName(string $name)
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * Resets the consumed property.
     * Use when you want to call start() or consume() multiple times.
     */
    public function getConsumed() : int
    {
        return $this->consumed;
    }

    /**
     * Resets the consumed property.
     * Use when you want to call start() or consume() multiple times.
     */
    public function resetConsumed()
    {
        $this->consumed = 0;
    }

    /**
     * @return mixed
     */
    public function getProceedOnException() : bool
    {
        return $this->proceedOnException;
    }

    /**
     * @param mixed $proceedOnException
     */
    public function setProceedOnException(bool $proceedOnException)
    {
        $this->proceedOnException = $proceedOnException;
    }

    /**
     * Consume designated number of messages (0 means infinite)
     * @param int $msgAmount
     * @return int
     * @throws \BadFunctionCallException
     * @throws RuntimeException
     * @throws AMQPTimeoutException
     */
    public function consume($msgAmount = 0) : int
    {
        $this->setQosOptions();
        $this->target = $msgAmount;
        if ($this->autoDeclare) {
            $this->routing->declareAll($this->conn);
        }
        $this->startConsuming();
        // At the end of the callback execution
        while (count($this->getChannel()->callbacks)) {
            if ($this->maybeStopConsumer()) {
                break;
            }
            try {
                $this->getChannel()->wait(null, false, $this->getIdleTimeout());
            } catch (AMQPTimeoutException $e) {
                if (null !== $this->getIdleTimeoutExitCode()) {
                    return $this->getIdleTimeoutExitCode();
                }

                throw $e;
            }
        }

        return Controller::EXIT_CODE_NORMAL;
    }

    /**
     * Stop consuming messages
     */
    public function stopConsuming()
    {
        foreach ($this->queues as $name => $options) {
            $this->getChannel()->basic_cancel($this->getConsumerTag($name), false, true);
        }
    }

    /**
     * Force stop the consumer
     */
    public function stopDaemon()
    {
        $this->forceStop = true;
        $this->stopConsuming();
        $this->logger->printInfo("\nConsumer stopped by user.\n", Console::FG_YELLOW);
    }

    /**
     * Force restart the consumer
     */
    public function restartDaemon()
    {
        $this->stopConsuming();
        $this->reconnect();
        $this->resetConsumed();
        $this->logger->printInfo("\nConsumer has been restarted.\n", Console::FG_YELLOW);
        $this->consume($this->target);
    }

    /**
     * Sets the qos settings for the current channel
     * This method needs a connection to broker
     */
    protected function setQosOptions()
    {
        if (empty($this->qos)) {
            return;
        }
        $prefetchSize = $this->qos['prefetch_size'] ?? null;
        $prefetchCount = $this->qos['prefetch_count'] ?? null;
        $global = $this->qos['global'] ?? null;
        $this->getChannel()->basic_qos($prefetchSize, $prefetchCount, $global);
    }

    /**
     * Start consuming messages
     * @throws RuntimeException
     * @throws \Throwable
     */
    protected function startConsuming()
    {
        $this->id = $this->generateUniqueId();
        foreach ($this->queues as $queue => $callback) {
            $that = $this;
            $this->getChannel()->basic_consume(
                $queue,
                $this->getConsumerTag($queue),
                null,
                null,
                null,
                null,
                function (AMQPMessage $msg) use ($that, $queue, $callback) {
                    // Execute user-defined callback
                    $that->onReceive($msg, $queue, $callback);
                }
            );
        }
    }

    /**
     * Decide whether it's time to stop consuming
     * @throws \BadFunctionCallException
     */
    protected function maybeStopConsumer() : bool
    {
        if (extension_loaded('pcntl') && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : true)) {
            if (!function_exists('pcntl_signal_dispatch')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called.");
            }
            pcntl_signal_dispatch();
        }
        if ($this->forceStop || ($this->consumed === $this->target && $this->target > 0)) {
            $this->stopConsuming();
            return true;
        }

        if (0 !== $this->getMemoryLimit() && $this->isRamAlmostOverloaded()) {
            $this->stopConsuming();
            return true;
        }

        return false;
    }

    /**
     * Callback that will be fired upon receiving new message
     * @param AMQPMessage $msg
     * @param $queueName
     * @param $callback
     * @return bool
     * @throws \Throwable
     */
    protected function onReceive(AMQPMessage $msg, string $queueName, callable $callback) : bool
    {
        $timeStart = microtime(true);
        \Yii::$app->rabbitmq->trigger(RabbitMQConsumerEvent::BEFORE_CONSUME, new RabbitMQConsumerEvent([
            'message' => $msg,
            'consumer' => $this,
        ]));

        try {
            // deserialize message back to initial data type
            if ($msg->has('application_headers') && isset($msg->get('application_headers')->getNativeData()['rabbitmq.serialized'])) {
                $msg->setBody(call_user_func($this->deserializer, $msg->getBody()));
            }
            // process message and return the result code back to broker
            $processFlag = $callback($msg);
            $this->sendResult($msg, $processFlag);
            \Yii::$app->rabbitmq->trigger(RabbitMQConsumerEvent::AFTER_CONSUME, new RabbitMQConsumerEvent([
                'message' => $msg,
                'consumer' => $this,
            ]));

            $this->logger->printResult($queueName, $processFlag, $timeStart);
            $this->logger->log(
                'Queue message processed.',
                $msg,
                [
                    'queue' => $queueName,
                    'processFlag' => $processFlag,
                    'timeStart' => $timeStart,
                    'memory' => true,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->logError($e, $msg);
            if (!$this->proceedOnException) {
                throw $e;
            }
        }
        $this->consumed++;

        return true;
    }

    /**
     * Mark message status based on return code from callback
     * @param AMQPMessage $msg
     * @param $processFlag
     */
    protected function sendResult(AMQPMessage $msg, $processFlag)
    {
        if ($processFlag === ConsumerInterface::MSG_REQUEUE || false === $processFlag) {
            // Reject and requeue message to RabbitMQ
            $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], true);
        } elseif ($processFlag === ConsumerInterface::MSG_REJECT) {
            // Reject and drop
            $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], false);
        } else {
            // Remove message from queue only if callback return not false
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        }
    }

    /**
     * Checks if memory in use is greater or equal than memory allowed for this process
     *
     * @return boolean
     */
    protected function isRamAlmostOverloaded() : bool
    {
        return memory_get_usage(true) >= ($this->getMemoryLimit() * 1024 * 1024);
    }

    /**
     * @param string $queueName
     * @return string
     */
    protected function getConsumerTag(string $queueName) : string
    {
        return sprintf('%s-%s-%s', $queueName, $this->name, $this->id);
    }

    /**
     * @return string
     */
    protected function generateUniqueId() : string
    {
        return uniqid();
    }
}
