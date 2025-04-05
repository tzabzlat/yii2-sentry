<?php

namespace tzabzlat\yii2sentry\collectors;

use tzabzlat\yii2sentry\SentryComponent;
use Sentry\Breadcrumb;
use Sentry\State\Scope;
use yii\base\Component;

abstract class BaseCollector extends Component implements CollectorInterface
{
    public string $logCategory = 'SentryCollector';
    protected SentryComponent $sentryComponent;

    public function attach(SentryComponent $sentryComponent): bool
    {
        $this->sentryComponent = $sentryComponent;

        return true;
    }

    public function setTags(Scope $scope): void
    {
    }

    protected function addBreadcrumb($message, array $data = [], $level = Breadcrumb::LEVEL_INFO, $category = 'default'): bool
    {
        return $this->sentryComponent->addBreadcrumb($message, $data, $level, $category, $this->logCategory);
    }
}