<?php

namespace tzabzlat\yii2sentry\collectors;

use tzabzlat\yii2sentry\SentryComponent;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Yii;
use yii\base\Event;
use yii\web\Application;
use yii\web\Controller;
use yii\web\Response;
use yii\web\User;

/**
 * RequestCollector collects HTTP request information and sends it to Sentry
 * Creates and manages transactions for HTTP requests
 */
class RequestCollector extends BaseCollector
{

    public $captureUser = true;

    /**
     * @var float Timestamp when the request started
     */
    private $requestStartTime;

    /**
     * @var array Request data to be sent to Sentry
     */
    private $requestData = [];

    /**
     * @var \Sentry\Tracing\Transaction Transaction for this request
     */
    private $transaction;

    /**
     * Attaches event handlers to collect HTTP request information
     */
    public function attach(SentryComponent $sentryComponent): bool
    {
        parent::attach($sentryComponent);

        if (!Yii::$app instanceof Application) {
            Yii::error('Attempt to connect collector not to Web Application');

            return false;
        }

        $this->requestStartTime = defined('YII_BEGIN_TIME') ? YII_BEGIN_TIME : microtime(true);

        // Start transaction for this request
        $this->transaction = $this->startRequestTransaction();

        if ($this->captureUser && Yii::$app->has('user')) {
            Event::on(User::class, User::EVENT_AFTER_LOGIN, function () {
                $this->setUserContext();
            });
        }

        Event::on(Application::class, Application::EVENT_BEFORE_REQUEST, function () {
            $this->collectRequestData();
        });

        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function ($event) {
            $this->collectActionData($event);
        });

        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function ($event) {
            $this->collectResponseData($event);
        });

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, function () {
            $this->finishRequestTracking();
        });

        return true;
    }

    function setTags(Scope $scope): void
    {
        $request = Yii::$app->getRequest();

        if ($request->getUrl()) {
            $scope->setTag('url', $request->getUrl());
        }

        $scope->setTag('request_method', $request->getMethod());

        // Set user context if enabled
        if ($this->captureUser) {
            $this->setUserContext();
        }
    }


    /**
     * Starts a transaction for the current request
     *
     * @return \Sentry\Tracing\Transaction|null
     */
    protected function startRequestTransaction(): ?Transaction
    {
        try {
            $request = Yii::$app->getRequest();

            $context = new TransactionContext();

            if (Yii::$app->requestedRoute) {
                $name = Yii::$app->requestedRoute;
            } else {
                $name = $request->getMethod() . ' ' . $request->getPathInfo();
            }

            $context->setName($name);
            $context->setOp('http.server');

            if (defined('YII_BEGIN_TIME')) {
                $context->setStartTimestamp(YII_BEGIN_TIME);
            }

            $transaction = SentrySdk::getCurrentHub()->startTransaction($context);

            if (!$transaction) {
                Yii::error('Failed to start transaction for HTTP request', __METHOD__);
                return null;
            }

            $transaction->setData([
                'url' => $request->getAbsoluteUrl(),
                'method' => $request->getMethod(),
                'route' => Yii::$app->requestedRoute,
                'ajax' => $request->getIsAjax() ? 'yes' : 'no',
                'pjax' => $request->getIsPjax() ? 'yes' : 'no',
            ]);

            SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($transaction) {
                $scope->setSpan($transaction);
            });

            Yii::info('Started HTTP request transaction: ' . $name, __METHOD__);

            return $transaction;
        } catch (\Throwable $e) {
            Yii::error('Error starting Sentry transaction: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    protected function setUserContext()
    {
        if (!Yii::$app->has('user') || Yii::$app->user->isGuest) {
            return;
        }

        $identity = Yii::$app->user->identity;

        $userData = [
            'id' => $identity->getId(),
        ];

        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($userData) {
            $scope->setUser($userData);
        });
    }

    /**
     * Collects information about the current request
     */
    protected function collectRequestData()
    {
        $request = Yii::$app->getRequest();

        $this->requestData = [
            'url' => $request->getAbsoluteUrl(),
            'method' => $request->getMethod(),
            'is_ajax' => $request->getIsAjax(),
            'is_pjax' => $request->getIsPjax(),
            'headers' => $this->sanitizeHeaders($request->getHeaders()->toArray()),
            'query_params' => $this->sanitizeData($request->getQueryParams()),
            'body_params' => $this->sanitizeData($request->getBodyParams()),
            'time' => $this->requestStartTime,
        ];

        // Add user agent
        if ($request->getUserAgent()) {
            $this->requestData['user_agent'] = $request->getUserAgent();
        }

        // Add IP address
        if ($request->getUserIP()) {
            $this->requestData['ip_address'] = $request->getUserIP();
        }

        $this->addBreadcrumb(
            "Request: {$request->getMethod()} {$request->getPathInfo()}",
            [
                'url' => $request->getAbsoluteUrl(),
                'method' => $request->getMethod(),
            ],
            Breadcrumb::LEVEL_INFO,
            'http.request'
        );

        // Update transaction with request data
        if ($this->transaction) {
            $this->transaction->setData(array_merge(
                $this->transaction->getData(),
                ['request_details' => $this->requestData]
            ));
        }
    }

    /**
     * Collects information about the current controller action
     *
     * @param Event $event The event
     */
    protected function collectActionData($event)
    {
        $controller = $event->sender;
        $action = $event->action;

        if (!$controller || !$action) {
            return;
        }

        $this->requestData['controller'] = get_class($controller);
        $this->requestData['action'] = $action->id;
        $this->requestData['route'] = $controller->id . '/' . $action->id;

        // Update transaction name with more specific controller/action info
        if ($this->transaction) {
            $actionName = get_class($controller) . '::' . $action->id;
            $this->transaction->setName($actionName);

            $this->transaction->setData(array_merge(
                $this->transaction->getData(),
                [
                    'action' => $actionName,
                    'controller' => get_class($controller),
                    'action_id' => $action->id
                ]
            ));
        }

        $this->addBreadcrumb(
            "Route: {$controller->id}/{$action->id}",
            [
                'controller' => get_class($controller),
                'action' => $action->id,
            ],
            Breadcrumb::LEVEL_INFO,
            'http.route'
        );
    }

    /**
     * Collects information about the response
     *
     * @param Event $event The event
     */
    protected function collectResponseData($event)
    {
        /** @var Response $response */
        $response = $event->sender;

        if (!$response) {
            return;
        }

        $statusCode = $response->getStatusCode();

        $this->requestData['status_code'] = $statusCode;
        $this->requestData['content_type'] = $response->getHeaders()->get('Content-Type');

        // Update transaction with response status
        if ($this->transaction) {
            // Set transaction status based on HTTP status code
            if ($statusCode >= 500) {
                $this->transaction->setStatus(SpanStatus::internalError());
            } elseif ($statusCode >= 400) {
                $this->transaction->setStatus(SpanStatus::unauthenticated());
            } elseif ($statusCode >= 300) {
                $this->transaction->setStatus(SpanStatus::ok());
            } else {
                $this->transaction->setStatus(SpanStatus::ok());
            }

            $this->transaction->setData(array_merge(
                $this->transaction->getData(),
                [
                    'response_status' => $statusCode,
                    'content_type' => $response->getHeaders()->get('Content-Type')
                ]
            ));
        }

        $level = $statusCode >= 400 ? Breadcrumb::LEVEL_ERROR : Breadcrumb::LEVEL_INFO;

        $this->addBreadcrumb(
            "Response: {$statusCode}",
            [
                'status_code' => $statusCode,
                'content_type' => $response->getHeaders()->get('Content-Type'),
            ],
            $level,
            'http.response'
        );
    }

    /**
     * Finishes the request tracking and records the request duration
     */
    protected function finishRequestTracking()
    {
        $duration = microtime(true) - $this->requestStartTime;
        $this->requestData['duration'] = round($duration * 1000, 2); // Convert to milliseconds

        if ($this->transaction) {
            $finalData = [
                'request_final' => $this->requestData,
                'memory_peak' => memory_get_peak_usage(true),
                'processing_time' => round($duration * 1000, 2) . ' ms',
                'performance' => [
                    'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
                    'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                    'duration' => round($duration * 1000, 2) . ' ms',
                ]
            ];

            $this->transaction->setData(array_merge(
                $this->transaction->getData(),
                $finalData
            ));

            $this->addBreadcrumb(
                'Request completed',
                [
                    'duration' => round($duration * 1000, 2) . ' ms',
                    'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
                ],
                Breadcrumb::LEVEL_INFO,
                'http.complete'
            );

            Yii::info('Finishing HTTP request transaction', __METHOD__);

            // Finish transaction
            $this->transaction->finish();
        }
    }

    /**
     * Sanitizes headers to remove sensitive information
     *
     * @param array $headers The HTTP headers
     * @return array
     */
    protected function sanitizeHeaders($headers)
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'api-key',
            'apikey',
            'api_key',
            'token',
            'auth',
            'secret',
        ];

        $sanitized = [];

        foreach ($headers as $name => $value) {
            $lowerName = strtolower($name);

            if (in_array($lowerName, $sensitiveHeaders) ||
                strpos($lowerName, 'secret') !== false ||
                strpos($lowerName, 'password') !== false ||
                strpos($lowerName, 'token') !== false ||
                strpos($lowerName, 'auth') !== false) {
                $sanitized[$name] = '[HIDDEN]';
            } else {
                $sanitized[$name] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitizes data to remove sensitive information
     *
     * @param array $data The data to sanitize
     * @return array
     */
    protected function sanitizeData($data)
    {
        $sensitiveKeys = [
            'password',
            'passwd',
            'pass',
            'pwd',
            'secret',
            'token',
            'api_key',
            'apikey',
            'access_token',
            'auth',
            'credentials',
            'credit_card',
            'creditcard',
            'card_number',
            'cardnumber',
            'cvv',
            'cvc',
        ];

        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, $sensitiveKeys) ||
                strpos($lowerKey, 'password') !== false ||
                strpos($lowerKey, 'token') !== false ||
                strpos($lowerKey, 'secret') !== false ||
                strpos($lowerKey, 'auth') !== false ||
                strpos($lowerKey, 'credit') !== false ||
                strpos($lowerKey, 'card') !== false) {
                $sanitized[$key] = '[HIDDEN]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Formats bytes to human-readable format
     *
     * @param int $bytes Number of bytes
     * @param int $precision Precision of formatting
     * @return string
     */
    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}