<?php

namespace tzabzlat\yii2sentry\collectors\LogCollector;

use tzabzlat\yii2sentry\collectors\BaseCollector;
use tzabzlat\yii2sentry\SentryComponent;
use Yii;

class LogCollector extends BaseCollector
{
    public array $targetOptions = [];

    public function attach(SentryComponent $sentryComponent): bool
    {
        parent::attach($sentryComponent);

        Yii::$app->getLog()->targets[] = Yii::createObject(
            $this->targetOptions
        );

        return true;
    }
}