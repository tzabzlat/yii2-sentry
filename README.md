# Yii2 Sentry
[![Latest Stable Version](http://poser.pugx.org/tzabzlat/yii2-sentry/v)](https://packagist.org/packages/tzabzlat/yii2-sentry) 
[![License](http://poser.pugx.org/tzabzlat/yii2-sentry/license)](https://packagist.org/packages/tzabzlat/yii2-sentry) [![PHP Version Require](http://poser.pugx.org/tzabzlat/yii2-sentry/require/php)](https://packagist.org/packages/tzabzlat/yii2-sentry)

*Read this in other languages: [English](README.md), [Русский](README.ru.md)*


A component for integrating [Sentry](https://sentry.io) with the Yii2 framework.
Not just a LogTarget, but also application performance monitoring.

## Features

- Tracking errors and exceptions through Yii2 logs
- Database performance monitoring (slow queries, transactions)
- HTTP request tracing (incoming and outgoing)
- Manual spans for tracking performance of critical operations
- Flexible data collection configuration through collector system
- Sanitization of sensitive data (passwords, tokens, API keys)

## Installation

Install the package via composer:

```bash
composer require tzabzlat/yii2-sentry
```

For using performance profiling features, you need to install the PHP [Excimer extension](https://github.com/wikimedia/mediawiki-php-excimer).

## Configuration

### Basic Configuration

Add to your application configuration (not `common`):

```php
'bootstrap' => ['sentry'],
'log'          => [
    'logger'  => 'tzabzlat\yii2sentry\Logger',
]
'components' => [
    'sentry' => [
        'class' => 'tzabzlat\yii2sentry\SentryComponent',
        'dsn' => 'https://your-sentry-dsn@sentry.io/project',
        'environment' => YII_ENV,
        // Sampling rate (percentage of requests for performance metrics collection)
        'tracesSampleRatePercent' => YII_ENV_PROD ? 5 : 100,
        // Additional tags for all events
        'tags' => [
            'application' => 'app-api',
            'app_version' => '1.0.0',
        ],
    ],
]
```

## Built-in Collectors

The package includes four main collectors, each responsible for its own monitoring area:

### 1. LogCollector
Collects and sends Yii2 logs with error and warning levels to Sentry. Allows configuring which logs should be sent and which should be ignored.

### 2. DbCollector

Tracks SQL queries, measures their performance, and creates spans in Sentry for analysis. Automatically marks slow queries. Also tracks database transactions.

### 3. HttpClientCollector

Tracks outgoing HTTP requests made through Yii2 HttpClient. Measures response time, records response status, and creates spans for visualizing HTTP dependencies.

### 4. RequestCollector

Tracks incoming HTTP requests to your application. Creates the main transaction for each request and collects information about the controller, action, processing time, and response status.

## Usage

### Manual Spans for Custom Operations

To create spans manually, use the `trace` method:

```php
// Simple span
Yii::$app->sentry->trace('Operation name', function() {
    // Your code here
    heavyOperation();
});

// With additional data
Yii::$app->sentry->trace(
    'Data import', 
    function() {
        // Import data
        return $result;
    }, 
    'custom.import', // Operation type
    [
        'source' => 'api',
        'records_count' => $count
    ]
);

// Span with exception handling
try {
    Yii::$app->sentry->trace('Critical operation', function() {
        // In case of an exception, the span will be marked as failed
        throw new \Exception('Error!');
    });
} catch (\Exception $e) {
    // The exception will be caught here
    // The span is already marked as failed in Sentry
}
```

### Collector Configuration

You can configure each collector separately through the `collectorsConfig` parameter:

```php
'sentry' => [
    'class' => 'tzabzlat\yii2sentry\SentryComponent',
    'dsn' => env('SENTRY_DSN', ''),
    'environment' => YII_ENV,
    'tracesSampleRatePercent' => YII_ENV_PROD ? 20 : 100,
    'collectorsConfig' => [
        // LogCollector configuration
        'logCollector' => [
            'targetOptions' => [
                'levels' => ['error', 'warning'], // Log levels to send
                'except' => ['yii\web\HttpException:404'], // Exceptions
                'exceptMessages' => [
                    '/^Informational message/' => true, // Exclude by pattern
                ],
            ],
        ],
        
        // DbCollector configuration
        'dbCollector' => [
            'slowQueryThreshold' => 100, // Threshold in ms for slow queries
        ],
        
        // HttpClientCollector with sensitive URL masking
        'httpClientCollector' => [
            'urlMaskPatterns' => [
                '|https://api\.telegram\.org/bot([^/]+)/|' => 'https://api.telegram.org/bot[HIDDEN]/',
            ],
        ],
        
        // RequestCollector configuration
        'requestCollector' => [
            'captureUser' => true, // Capture user ID
        ],
    ],
]
```

### Disabling Collectors

To disable a specific collector, set its configuration to `false`:

```php
'collectorsConfig' => [
    'dbCollector' => false, // Disables the database collector
    'httpClientCollector' => false, // Disables the HTTP client collector
],
```

## How Collectors Work

### LogCollector

Connects a special LogTarget that intercepts logs with specified levels and sends them to Sentry. Processes exceptions as a separate type of event. Also supports filtering by categories and message patterns.

### DbCollector

Overrides the standard Yii2 DbCommand and connects to query profiling events. Measures the execution time of each SQL query, determines the query type (SELECT, INSERT, etc.), and creates spans for visualization in Sentry. Tracks transactions through Connection events.

### HttpClientCollector

Subscribes to request sending events through HttpClient. For each request, it creates a span with details of URL, method, headers, and request body (with sanitization of sensitive data). Measures response time and adds information about the response status.

### RequestCollector

Creates the main transaction for each incoming HTTP request. Collects information about the route, controller, action, request parameters, and response. Measures the total request processing time and peak memory usage.

## Creating a Custom Collector

You can create your own collector by implementing the `CollectorInterface` or extending the `BaseCollector` class:

```php
namespace app\components\sentry;

use tzabzlat\yii2sentry\collectors\BaseCollector;
use tzabzlat\yii2sentry\SentryComponent;
use Sentry\Breadcrumb;
use Sentry\State\Scope;
use Yii;

class MyCustomCollector extends BaseCollector
{
    // Collector configuration
    public $someOption = 'default';
    
    /**
     * Attaches the collector to Sentry
     */
    public function attach(SentryComponent $sentryComponent): bool
    {
        parent::attach($sentryComponent);
        
        // Connect to Yii2 events
        \yii\base\Event::on(SomeClass::class, SomeClass::EVENT_NAME, function($event) {
            $this->handleEvent($event);
        });
        
        return true;
    }
    
    /**
     * Sets additional tags
     */
    public function setTags(Scope $scope): void
    {
        $scope->setTag('custom_tag', 'value');
    }
    
    /**
     * Handles a custom event
     */
    protected function handleEvent($event)
    {
        // Create a span for tracking
        $span = $this->sentryComponent->startSpan(
            'My Custom Operation',
            'custom.operation',
            [
                'key' => 'value',
                'event_type' => get_class($event)
            ]
        );
        
        // Add a breadcrumb to the timeline
        $this->addBreadcrumb(
            'My Event Happened',
            ['data' => 'value'],
            Breadcrumb::LEVEL_INFO,
            'custom'
        );
        
        // Finish the span
        if ($span) {
            $this->sentryComponent->finishSpan($span, [
                'result' => 'success',
                'additional_data' => $someValue
            ]);
        }
    }
}
```

Then add your collector to the configuration:

```php
'sentry' => [
    // ...
    'collectorsConfig' => [
        'myCustomCollector' => [
            'class' => 'app\components\sentry\MyCustomCollector',
            'someOption' => 'custom value',
        ],
    ],
],
```

### Contributing
If you found a bug or have suggestions for improvement, feel free to:

- Create an issue with a description of the problem or suggestion
- Propose pull requests with fixes or new features

## License

MIT