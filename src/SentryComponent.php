<?php

namespace tzabzlat\yii2sentry;

use tzabzlat\yii2sentry\collectors\BaseCollector;
use tzabzlat\yii2sentry\collectors\CollectorInterface;
use tzabzlat\yii2sentry\collectors\DbCollector\DbCollector;
use tzabzlat\yii2sentry\collectors\HttpClientCollector;
use tzabzlat\yii2sentry\collectors\LogCollector\LogCollector;
use tzabzlat\yii2sentry\collectors\LogCollector\LogTarget;
use tzabzlat\yii2sentry\collectors\RequestCollector;
use tzabzlat\yii2sentry\enum\CollectorsEnum;
use tzabzlat\yii2sentry\enum\SpanOpEnum;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Yii;
use yii\base\BootstrapInterface;
use yii\base\Component;

/**
 * SentryComponent is a Yii2 component that integrates with Sentry for error tracking and performance monitoring.
 */
class SentryComponent extends Component implements BootstrapInterface
{
    /**
     * @var string The Sentry DSN
     */
    public string $dsn;

    /**
     * @var string The environment (e.g., 'production', 'staging', 'development')
     */
    public string $environment = 'production';

    /**
     * @var int Sample rate for performance monitoring (0% - 100%).
     * In the Sentry SDK, we always set 100% (float 1), sampling is implemented by SentryComponent.
     */
    public int $tracesSampleRatePercent = 100;

    /**
     * @var float Sample rate for performance profiling (0.0 - 1.0) from the subset of traces.
     */
    public float $profilesSampleRate = 1.0;

    /**
     * @var array Additional options for Sentry SDK
     */
    public array $options = [];

    /**
     * @var array Tags to add to every event
     */
    public array $tags = [];

    public string $appId = 'yiiapp';

    /**
     * @var array Configuration of information-gathering classes.
     */
    public array $collectorsConfig = [];

    /**
     * @var BaseCollector[] List of collectors objects
     */
    public array $collectorsList = [];

    /**
     * @var array Mapping of span IDs to parent spans for hierarchy tracking
     */
    private array $parentSpans = [];

    /**
     * {@inheritdoc}
     */
    public function bootstrap($app)
    {
        if (!$this->initSentrySdk()) {
            return;
        }

        $shouldSample = $this->checkShouldSample();

        $collectorConfig = $this->prepareCollectorsConfig();

        if (!$shouldSample) {
            $collectorConfig = array_filter($collectorConfig, function ($key) {
                return $key === CollectorsEnum::LOG_COLLECTOR;
            }, ARRAY_FILTER_USE_KEY);
        }

        foreach ($collectorConfig as $config) {
            $this->initCollector($config);
        }

        $this->setContextTags();
    }

    /**
     * {@inheritdoc}
     */
    public function initSentrySdk(): bool
    {
        if (empty($this->dsn)) {
            Yii::error('Sentry DSN not provided', __METHOD__);

            return false;
        }

        $options = array_merge_recursive([
            'dsn' => $this->dsn,
            'environment' => $this->environment,
            'traces_sample_rate' => 1,
            'profiles_sample_rate' => $this->profilesSampleRate,
            'release' => $this->getRelease(),
            'send_default_pii' => true,
        ], $this->options);

        if (YII_DEBUG) {
            Yii::info('Initializing Sentry with options: ' . json_encode($options), __METHOD__);
        }

        $client = ClientBuilder::create($options)->getClient();
        SentrySdk::init()->bindClient($client);
        $hub = new Hub($client);
        SentrySdk::setCurrentHub($hub);

        return true;
    }

    public function initCollector(array $config): void
    {
        /** @var CollectorInterface $collector */
        $collector = Yii::createObject(
            array_merge(
                $config
            )
        );

        if ($collector->attach($this)) {
            $this->collectorsList[] = $collector;
        }
    }

    /**
     * Wrap important operation into span
     */
    public function trace(string $name, callable $callable, ?string $op = null, array $initialData = [])
    {
        $span = $this->startSpan($name, $op ?? SpanOpEnum::CUSTOM, $initialData);

        if ($span === null) {
            Yii::error('Trace span start error');

            return $callable();
        }

        try {
            $result = $callable();

            // Mark the span as successful and finish it
            $this->finishSpan($span, ['status' => 'success']);

            return $result;
        } catch (\Throwable $exception) {
            // In case of exception, mark the span as failed
            $this->finishSpan(
                $span,
                [
                    'status' => 'error',
                    'error' => $exception->getMessage(),
                    'error_type' => get_class($exception)
                ],
                SpanStatus::internalError()
            );

            // Re-throw the exception
            throw $exception;
        }
    }

    public function addBreadcrumb($message, array $data = [], $level = Breadcrumb::LEVEL_INFO, $category = 'default', $logCategory = 'sentry'): bool
    {
        try {
            $hub = SentrySdk::getCurrentHub();

            $breadcrumb = new Breadcrumb(
                $level,
                'default',
                $category,
                $message,
                $data
            );

            $hub->addBreadcrumb($breadcrumb);

            Yii::debug("Added breadcrumb: {$message}", $logCategory);

            return true;
        } catch (\Throwable $e) {
            Yii::error('Failed to add breadcrumb: ' . $e->getMessage(), $logCategory);
            return false;
        }
    }

    protected function setContextTags(): void
    {
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) {
            $scope->setTag('yii_version', Yii::getVersion());
            $scope->setTag('php_version', PHP_VERSION);
            $scope->setTag('app_id', $this->appId ?: Yii::$app->id);

            foreach ($this->collectorsList as $collector) {
                $collector->setTags($scope);
            }

            foreach ($this->tags as $key => $value) {
                $scope->setTag($key, $value);
            }
        });
    }

    public function startSpan($name, $op = 'custom', array $data = []): ?Span
    {
        try {
            $hub = SentrySdk::getCurrentHub();
            $parentSpan = $hub->getSpan();

            if ($parentSpan === null) {
                Yii::error("Failed to create span: parent span not found", $this->logCategory ?? 'sentry');

                return null;
            }

            $context = new SpanContext();
            $context->setOp($op);
            $context->setDescription($name);

            $span = $parentSpan->startChild($context);

            if ($span) {
                // Save the parent span for later restoration
                $spanId = (string)$span->getSpanId();
                $this->parentSpans[$spanId] = $parentSpan;

                if (!empty($data)) {
                    $span->setData($data);
                }

                // Make the new span active
                $hub->configureScope(function (Scope $scope) use ($span) {
                    $scope->setSpan($span);
                });

                Yii::debug("Started span: {$name} ({$op}) [ID: {$spanId}]", $this->logCategory ?? 'sentry');
            }

            return $span;
        } catch (\Throwable $e) {
            Yii::error("Failed to create span: " . $e->getMessage(), $this->logCategory ?? 'sentry');
            return null;
        }
    }

    public function finishSpan(Span $span, array $finalData = [], ?SpanStatus $status = null): void
    {
        try {
            $spanId = (string)$span->getSpanId();

            // Update span with final data
            if (!empty($finalData)) {
                $span->setData(array_merge($span->getData(), $finalData));
            }

            // Set status if provided
            if ($status !== null) {
                $span->setStatus($status);
            } elseif ($span->getStatus() === null) {
                // Default to OK if not set
                $span->setStatus(SpanStatus::ok());
            }

            // Finish the span
            $span->finish();

            // Restore parent span as active if available
            if (isset($this->parentSpans[$spanId])) {
                $parentSpan = $this->parentSpans[$spanId];
                $hub = SentrySdk::getCurrentHub();

                $hub->configureScope(function (Scope $scope) use ($parentSpan) {
                    $scope->setSpan($parentSpan);
                });

                // Clean up
                unset($this->parentSpans[$spanId]);

                Yii::debug("Finished span [ID: {$spanId}] and restored parent span", $this->logCategory ?? 'sentry');
            } else {
                Yii::debug("Finished span [ID: {$spanId}] (no parent to restore)", $this->logCategory ?? 'sentry');
            }
        } catch (\Throwable $e) {
            Yii::error("Failed to finish span: " . $e->getMessage(), $this->logCategory ?? 'sentry');
        }
    }

    /**
     * Returns the release version
     *
     * @return string|null
     */
    protected function getRelease()
    {
        // Try to get release from environment variable
        if (getenv('SENTRY_RELEASE')) {
            return getenv('SENTRY_RELEASE');
        }

        // Try to get from composer installed.json if available
        $composerInstalledPath = Yii::getAlias('@vendor/composer/installed.json');
        if (file_exists($composerInstalledPath)) {
            $installed = json_decode(file_get_contents($composerInstalledPath), true);

            // Format changed in Composer 2.0
            $packages = isset($installed['packages']) ? $installed['packages'] : $installed;

            // Try to find the main package
            foreach ($packages as $package) {
                if (isset($package['name']) && $package['name'] === Yii::$app->id) {
                    return isset($package['version']) ? $package['version'] : null;
                }
            }
        }

        return null;
    }

    protected function prepareCollectorsConfig(): array
    {
        $default = [
            CollectorsEnum::LOG_COLLECTOR => [
                'class' => LogCollector::class,
                'targetOptions' => [
                    'class' => LogTarget::class,
                    'logVars' => ['_GET', '_POST', '_COOKIE', '_SESSION'],
                    'except' => ['yii\web\HttpException:404'],
                    'levels' => ['error', 'warning']
                ],
            ],
            CollectorsEnum::HTTP_CLIENT_COLLECTOR => [
                'class' => HttpClientCollector::class,
            ],
            CollectorsEnum::REQUEST_COLLECTOR => [
                'class' => RequestCollector::class,
                'captureUser' => true
            ],
            CollectorsEnum::DB_COLLECTOR => [
                'class' => DbCollector::class,
            ]
        ];

        return array_merge_recursive($default, $this->collectorsConfig);
    }

    protected function checkShouldSample(): bool
    {
        $randomValue = mt_rand(0, 100);

        return $randomValue <= $this->tracesSampleRatePercent;
    }
}