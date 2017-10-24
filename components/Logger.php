<?php

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Message\AMQPMessage;
use yii\helpers\Console;

class Logger
{
    protected $enabled;
    protected $systemMemory;
    protected $category;
    protected $printConsole;

    public function __construct(array $options)
    {
        $this->enabled = $options['enabled'];
        $this->systemMemory = $options['system_memory'];
        $this->category = $options['category'];
        $this->printConsole = $options['print_console'];
    }

    /**
     * Print success message to console
     *
     * @param $queueName
     * @param $timeStart
     * @param $processFlag
     */
    public function printToConsole(string $queueName, $timeStart, $processFlag)
    {
        if (!$this->enabled || !$this->printConsole) {
            return;
        }
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
     * @param \Exception $e
     */
    public function printErrorToConsole(\Exception $e)
    {
        if (!$this->enabled || !$this->printConsole) {
            return;
        }
        $color = Console::FG_RED;
        $consoleMessage = sprintf('Error: %s File: %s Line: %s', $e->getMessage(), $e->getFile(), $e->getLine());
        $this->stdout($consoleMessage, $color);
    }

    /**
     * Log message using standard Yii logger
     * @param \Throwable $e
     * @param $queueName
     * @param AMQPMessage $msg
     * @param $timeStart
     */
    public function logError(\Throwable $e, string $queueName, AMQPMessage $msg, $timeStart)
    {
        if (!$this->enabled) {
            return;
        }
        \Yii::error([
            'msg' => $e->getMessage(),
            'amqp' => [
                'queue' => $queueName,
                'message' => $msg->getBody(),
                'stacktrace' => $e->getTraceAsString(),
                'execution_time' => $this->getExecutionTime($timeStart),
            ],
        ], $this->category);
    }

    /**
     * Print message to STDOUT
     * @param $message
     * @param $color
     * @return bool|int
     */
    protected function stdout($message, $color = Console::FG_YELLOW)
    {
        if (Console::streamSupportsAnsiColors(\STDOUT)) {
            $message = Console::ansiFormat($message, [$color]) . "\n";
        }

        return Console::stdout($message);
    }

    /**
     * @param $timeStart
     * @param int $round
     * @return string
     */
    protected function getExecutionTime($timeStart, int $round = 3) : string
    {
        return (string)round(microtime(true) - $timeStart, $round) . 's';
    }

    /**
     * Get either script memory usage or free system memory info
     * @return string
     */
    protected function getMemory() : string
    {
        if ($this->systemMemory) {
            return $this->getSystemFreeMemory();
        }

        return 'Memory usage: ' . $this->getMemoryDiff();
    }

    /**
     * Get memory usage in human readable format
     * @return string
     */
    protected function getMemoryDiff() : string
    {
        $memory = memory_get_usage(true);
        if(0 === $memory) {

            return '0b';
        }
        $unit = ['b','kb','mb','gb','tb','pb'];

        return @round($memory/ (1024 ** ($i = floor(log($memory, 1024)))),2).' '.$unit[$i];
    }

    /**
     * Free system memory
     *
     * @return string
     */
    protected function getSystemFreeMemory() : string
    {
        $data = explode("\n", trim(file_get_contents('/proc/meminfo')));

        return sprintf(
            '%s, %s',
            preg_replace('/\s+/', ' ', $data[0]),
            preg_replace('/\s+/', ' ', $data[1])
        );
    }
}