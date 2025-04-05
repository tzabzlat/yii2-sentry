<?php

namespace tzabzlat\yii2sentry\collectors;

use tzabzlat\yii2sentry\SentryComponent;
use Sentry\State\Scope;

interface CollectorInterface
{
    function attach(SentryComponent $sentryComponent): bool;

    function setTags(Scope $scope): void;
}