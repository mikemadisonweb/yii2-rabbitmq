<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use yii\helpers\Console;

class Consumer extends BaseConsumer
{
    /**
     * @var int $memoryLimit
     */
    protected $memoryLimit = null;

    /**
     * Set the memory limit
     *
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
    public function getMemoryLimit()
    {
        return $this->memoryLimit;
    }

    /**
     * Consume the message
     * @param int $msgAmount
     * @return int|null
     */
    public function consume($msgAmount)
    {
        $this->target = $msgAmount;
        $this->startConsuming();
        while (count($this->getChannel()->callbacks)) {
            $this->maybeStopConsumer();
            if (!$this->forceStop) {
                try {
                    $this->getChannel()->wait(null, false, $this->getIdleTimeout());
                } catch (AMQPTimeoutException $e) {
                    if (null !== $this->getIdleTimeoutExitCode()) {
                        return $this->getIdleTimeoutExitCode();
                    } else {
                        throw $e;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Purge the queue
     */
    public function purge()
    {
        $this->getChannel()->queue_purge($this->queueOptions['name'], true);
    }

    /**
     * Delete the queue
     */
    public function delete()
    {
        $this->getChannel()->queue_delete($this->queueOptions['name'], true);
    }

    /**
     * @param AMQPMessage $msg
     * @param $queueName
     * @param $callback
     * @throws \Error
     * @throws \Exception
     */
    protected function processMessageQueueCallback(AMQPMessage $msg, $queueName, $callback)
    {
        \Yii::$app->rabbitmq->trigger(RabbitMQConsumerEvent::BEFORE_CONSUME, new RabbitMQConsumerEvent([
            'message' => $msg,
            'consumer' => $this,
        ]));
        $timeStart = microtime(true);
        try {
            $processFlag = call_user_func($callback, $msg);
            $this->handleProcessMessage($msg, $processFlag);
            \Yii::$app->rabbitmq->trigger(RabbitMQConsumerEvent::AFTER_CONSUME, new RabbitMQConsumerEvent([
                'message' => $msg,
                'consumer' => $this,
            ]));
            if ($this->logger['print_console']) {
                $this->printToConsole($queueName, $timeStart, $processFlag);
            }
            if ($this->logger['enable']) {
                \Yii::info([
                    'info' => 'Queue message processed.',
                    'amqp' => [
                        'queue' => $queueName,
                        'message' => $msg->getBody(),
                        'return_code' => $processFlag,
                        'execution_time' => $this->getExecutionTime($timeStart),
                        'memory' => $this->getMemory(),
                    ],
                ], $this->logger['category']);
            }
        } catch (\RuntimeException $e) {
            if ($this->logger['enable']) {
                if ($this->logger['print_console']) {
                    $this->printErrorToConsole($e);
                }

                \Yii::info([
                    'info' => 'Consumer requested restart.',
                    'amqp' => [
                        'queue' => $queueName,
                        'message' => $msg->getBody(),
                        'stacktrace' => $e->getTraceAsString(),
                        'execution_time' => $this->getExecutionTime($timeStart),
                        'memory' => $this->getMemory(),
                    ],
                ], $this->logger['category']);
            }
            $this->stopConsuming();
        } catch (\Exception $e) {
            if ($this->logger['enable']) {
                $this->logError($e, $queueName, $msg, $timeStart);
            }

            throw $e;
        } catch (\Error $e) {
            if ($this->logger['enable']) {
                $this->logError($e, $queueName, $msg, $timeStart);
            }

            throw $e;
        }
    }

    /**
     * @param AMQPMessage $msg
     * @return mixed|void
     */
    public function processMessage(AMQPMessage $msg)
    {
        $this->processMessageQueueCallback($msg, $this->queueOptions['name'], $this->callback);
    }

    /**
     * @param AMQPMessage $msg
     * @param $processFlag
     */
    protected function handleProcessMessage(AMQPMessage $msg, $processFlag)
    {
        if ($processFlag === ConsumerInterface::MSG_REJECT_REQUEUE || false === $processFlag) {
            // Reject and requeue message to RabbitMQ
            $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], true);
        } elseif ($processFlag === ConsumerInterface::MSG_SINGLE_NACK_REQUEUE) {
            // NACK and requeue message to RabbitMQ
            $msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag'], false, true);
        } elseif ($processFlag === ConsumerInterface::MSG_REJECT) {
            // Reject and drop
            $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], false);
        } else {
            // Remove message from queue only if callback return not false
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        }
        $this->consumed++;
        $this->maybeStopConsumer();
        if (null !== ($this->getMemoryLimit()) && $this->isRamAlmostOverloaded()) {
            $this->stopConsuming();
        }
    }

    /**
     * Checks if memory in use is greater or equal than memory allowed for this process
     *
     * @return boolean
     */
    protected function isRamAlmostOverloaded()
    {
        if (memory_get_usage(true) >= ($this->getMemoryLimit() * 1024 * 1024)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Print success message to console
     *
     * @param $queueName
     * @param $timeStart
     * @param $processFlag
     */
    protected function printToConsole($queueName, $timeStart, $processFlag)
    {
        if ($processFlag === ConsumerInterface::MSG_REJECT_REQUEUE || false === $processFlag) {
            $messageFormat = '%s - Message from queue `%s` was not processed and sent back to queue! Execution time: %s %s';
            $color = Console::FG_RED;
        } elseif ($processFlag === ConsumerInterface::MSG_SINGLE_NACK_REQUEUE) {
            $messageFormat = '%s - Message from queue `%s` was not processed and sent back to queue! Execution time: %s %s';
            $color = Console::FG_RED;
        } elseif ($processFlag === ConsumerInterface::MSG_REJECT) {
            $messageFormat = '%s - Message from queue `%s` was not processed and dropped from queue! Execution time: %s %s';
            $color = Console::FG_RED;
        } else {
            $messageFormat = '%s - Message from queue `%s` consumed successfully! Execution time: %s %s';
            $color = Console::FG_YELLOW;
        }
        $curDate = date('Y-m-d H:i:s');
        $execTime = $this->getExecutionTime($timeStart);
        $memory = $this->getMemory();
        $consoleMessage = sprintf($messageFormat, $curDate, $queueName, $execTime, $memory);
        $this->stdout($consoleMessage, $color);
    }

    /**
     * @param $timeStart
     * @param int $round
     * @return string
     */
    private function getExecutionTime($timeStart, $round = 3)
    {
        return (string)round((microtime(true) - $timeStart), $round) . 's';
    }

    /**
     * Get either script memory usage or free system memory info
     * @return string
     */
    private function getMemory() {
        if ($this->logger['system_memory']) {
            return $this->getSystemFreeMemory();
        } else {
            return 'Memory usage: ' . $this->getMemoryDiff();
        }
    }

    /**
     * Get memory usage in human readable format
     * @return string
     */
    private function getMemoryDiff() {
        $memory = memory_get_usage(true);
        if(0 === $memory) {

            return '0b';
        }
        $unit = ['b','kb','mb','gb','tb','pb'];

        return @round($memory/pow(1024,($i=floor(log($memory,1024)))),2).' '.$unit[$i];
    }

    /**
     * Free system memory
     *
     * @return string
     */
    private function getSystemFreeMemory()
    {
        $data = explode("\n", trim(file_get_contents('/proc/meminfo')));

        return sprintf(
            '%s, %s',
            preg_replace('/\s+/', ' ', $data[0]),
            preg_replace('/\s+/', ' ', $data[1])
        );
    }

    /**
     * @param \Exception $e
     */
    private function printErrorToConsole(\Exception $e)
    {
        $color = Console::FG_RED;
        $consoleMessage = sprintf('Error: %s File: %s Line: %s', $e->getMessage(), $e->getFile(), $e->getLine());
        $this->stdout($consoleMessage, $color);
    }

    /**
     * @param \Throwable $e
     * @param $queueName
     * @param AMQPMessage $msg
     * @param $timeStart
     */
    private function logError($e, $queueName, AMQPMessage $msg, $timeStart)
    {
        \Yii::error([
            'msg' => $e->getMessage(),
            'amqp' => [
                'queue' => $queueName,
                'message' => $msg->getBody(),
                'stacktrace' => $e->getTraceAsString(),
                'execution_time' => $this->getExecutionTime($timeStart),
            ],
        ], $this->logger['category']);
    }
}
