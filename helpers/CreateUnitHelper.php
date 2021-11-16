<?php

namespace mikemadisonweb\rabbitmq\helpers;

use mikemadisonweb\rabbitmq\Configuration;
use Yii;
use yii\base\BaseObject;

class CreateUnitHelper extends BaseObject
{
    /** @var string */
    public $units_dir;
    /** @var string */
    public $user;
    /** @var string */
    public $group;
    /** @var string */
    public $work_dir;

    /** @var string */
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

    /** @var string */
    public $bash = '#!/usr/bin/env bash
MASK=consumer*.service
if [ "$1" = "copy" ]; then
  sudo find . -type f -name "$MASK" -exec cp {} /etc/systemd/system \\;
  sudo systemctl daemon-reload
  sudo find . -type f -name "$MASK" -exec sh -c \'systemctl enable "$(basename {})"\' \\;
  sudo find . -type f -name "$MASK" -exec sh -c \'systemctl start "$(basename {})"\' \\;
fi
if [ "$1" = "start" ]; then
  sudo find . -type f -name "$MASK" -exec sh -c \'systemctl start "$(basename {})"\' \\;
fi
if [ "$1" = "restart" ]; then
  sudo find . -type f -name "$MASK" -exec sh -c \'systemctl restart "$(basename {})"\' \\;
fi
if [ "$1" = "status" ]; then
  sudo find . -type f -name "$MASK" -exec sh -c \'systemctl status "$(basename {})"\' \\;
fi
if [ "$1" = "delete" ]; then
  sudo find . -type f -name "$MASK" -exec sh -c \'systemctl disable "$(basename {})"\' \\;
  sudo find /etc/systemd/system/ -type f -name "$MASK" -exec rm {} \\;
  sudo find . -type f -name "$MASK" -exec rm {} \\;
  sudo systemctl daemon-reload
fi';

    /** @var Configuration */
    private $rabbitmq;

    public function init()
    {
        $this->rabbitmq = Yii::$app->rabbitmq;
        if ($this->units_dir && !is_dir($this->units_dir)) {
            mkdir($this->units_dir);
        }
    }

    public function create()
    {
        $consumers = $this->rabbitmq->consumers;
        foreach ($consumers as $consumer) {
            $description = 'Consumer ' . $consumer['name'];
            $memory_limit = $consumer['systemd']['memory_limit'] == 0 ? '' : '-l ' . $consumer['systemd']['memory_limit'];
            $workers = $consumer['systemd']['workers'];
            $unit = str_replace(
                [
                    '%description%',
                    '%work_dir%',
                    '%user%',
                    '%group%',
                    '%yii_path%',
                    '%name_consumer%',
                    '%memory_limit%'
                ],
                [
                    $description,
                    $this->work_dir,
                    $this->user,
                    $this->group,
                    $this->work_dir . '/yii',
                    $consumer['name'],
                    $memory_limit
                ],
                $this->example
            );
            $result[] = [
                'unit' => $unit,
                'workers' => $workers,
                'consumer' => $consumer['name']
            ];
        }
        if (!empty($result)) {
            foreach ($result as $item) {
                for ($i = 1; $i <= $item['workers']; $i++) {
                    $file = $this->units_dir . '/consumer_' . $item['consumer'] . '_' . $i . '.service';
                    file_put_contents($file, $item['unit']);
                }
            }
            $bash_file = $this->units_dir . '/exec.sh';
            file_put_contents($bash_file, $this->bash);
            chmod($bash_file, 0700);
        }
    }
}