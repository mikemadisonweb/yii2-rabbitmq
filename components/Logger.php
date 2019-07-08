<?php declare(strict_types=1);

namespace mikemadisonweb\rabbitmq\components;

use PhpAmqpLib\Message\AMQPMessage;
use yii\helpers\Console;

/**
 * @codeCoverageIgnore
 */
class Logger
{
    public $options;

    public function getOptions() : array
    {
        return $this->options;
    }

    /**
     * Print success message to console
     *
     * @param $queueName
     * @param $timeStart
     * @param $processFlag
     */
    public function printResult(string $queueName, $processFlag, $timeStart)
    {
        if (!$this->options['print_console']) {
            return;
        }
        if ($processFlag === ConsumerInterface::MSG_REQUEUE || false === $processFlag) {
            $messageFormat = '%s - Message from queue `%s` was not processed and sent back to queue! Execution time: %s %s';
            $color = Console::FG_RED;
        } elseif ($processFlag === ConsumerInterface::MSG_REJECT) {
            $messageFormat = '%s - Message from queue `%s` was not processed and dropped from queue! Execution time: %s %s';
            $color = Console::FG_RED;
        } else {
            $messageFormat = '%s - Message from queue `%s` consumed successfully! Execution time: %s %s';
            $color = Console::FG_GREEN;
        }
        $curDate = date('Y-m-d H:i:s');
        $execTime = $this->getExecutionTime($timeStart);
        $memory = $this->getMemory();
        $consoleMessage = sprintf($messageFormat, $curDate, $queueName, $execTime, $memory);
        $this->printInfo($consoleMessage, $color);
    }

    /**
     * @param \Exception $e
     */
    public function printError(\Exception $e)
    {
        if (!$this->options['print_console']) {
            return;
        }
        $color = Console::FG_RED;
        $consoleMessage = sprintf('Error: %s File: %s Line: %s', $e->getMessage(), $e->getFile(), $e->getLine());
        $this->printInfo($consoleMessage, $color);
    }

    /**
     * Log message using standard Yii logger
     * @param string $title
     * @param AMQPMessage $msg
     * @param array $options
     */
    public function log(string $title, AMQPMessage $msg, array $options)
    {
        if (!$this->options['log']) {
            return;
        }
        $extra['execution_time'] = isset($options['timeStart']) ? $this->getExecutionTime($options['timeStart']) : null;
        $extra['return_code'] = $options['processFlag'] ?? null;
        $extra['memory'] = isset($options['memory']) ? $this->getMemory() : null;
        $extra['routing_key'] = $options['routingKey'] ?? null;
        $extra['queue'] = $options['queue'] ?? null;
        $extra['exchange'] = $options['exchange'] ?? null;
        \Yii::info([
            'info' => $title,
            'amqp' => [
                'body' => $msg->getBody(),
                'headers' => $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : null,
                'extra' => $extra,
            ],
        ], $this->options['category']);
    }

    /**
     * Log error message using standard Yii logger
     * @param \Throwable $e
     * @param AMQPMessage $msg
     */
    public function logError(\Throwable $e, AMQPMessage $msg)
    {
        if (!$this->options['log']) {
            return;
        }
        \Yii::error([
            'msg' => $e->getMessage(),
            'amqp' => [
                'message' => $msg->getBody(),
                'stacktrace' => $e->getTraceAsString(),
            ],
        ], $this->options['category']);
    }

    /**
     * Print message to STDOUT
     * @param $message
     * @param $color
     * @return bool|int
     */
    public function printInfo($message, $color = Console::FG_YELLOW)
    {
        if (Console::streamSupportsAnsiColors(\STDOUT)) {
            $message = Console::ansiFormat($message, [$color]);
        }

        return Console::output($message);
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
        if ($this->options['system_memory']) {
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
