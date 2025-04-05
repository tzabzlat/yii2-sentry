<?php

namespace tzabzlat\yii2sentry\collectors;

use tzabzlat\yii2sentry\SentryComponent;
use Sentry\Breadcrumb;
use Sentry\Tracing\SpanStatus;
use Yii;
use yii\base\Event;
use yii\httpclient\Request;
use yii\httpclient\Response;

/**
 * HttpClientCollector collects HTTP client request information and sends it to Sentry
 */
class HttpClientCollector extends BaseCollector
{
    /**
     * @var array Array to store request start times
     */
    private array $requestTimes = [];

    /**
     * @var array Stores spans for each request by its identifier
     */
    private array $requestSpans = [];

    /**
     * @var array Stores sanitized URLs for each request
     */
    private array $sanitizedUrls = [];

    /**
     * @var array URL patterns that should be sanitized (regex patterns)
     * For example: [
     *   '|https://api\.telegram\.org/bot([^/]+)/|' => 'https://api.telegram.org/bot[HIDDEN]/'
     * ]
     */
    public array $urlMaskPatterns = [];

    /**
     * Attaches event handlers to collect HTTP client request information
     */
    public function attach(SentryComponent $sentryComponent): bool
    {
        parent::attach($sentryComponent);

        // Check yii2-httpclient if it's available
        if (class_exists('yii\httpclient\Client')) {
            Event::on(Request::class, Request::EVENT_BEFORE_SEND, function ($event) {
                $this->startRequestTimer($event->sender);
            });

            // For successful responses
            Event::on(Request::class, Request::EVENT_AFTER_SEND, function ($event) {
                // $event->data contains the response, but may be null in case of error
                $response = $event->data ?? null;
                $this->endRequestTimer($event->sender, $response);
            });
        }

        return true;
    }

    /**
     * Starts timing a HTTP request
     *
     * @param Request $request The HTTP request
     */
    protected function startRequestTimer(Request $request): void
    {
        $hash = spl_object_hash($request);
        $this->requestTimes[$hash] = microtime(true);

        $url = $this->getRequestUrl($request);
        $sanitizedUrl = $this->sanitizeUrl($url);
        $method = $request->getMethod();

        // Store the sanitized URL for later use
        $this->sanitizedUrls[$hash] = $sanitizedUrl;

        $span = $this->sentryComponent->startSpan(
            $method . ' ' . $sanitizedUrl,
            'http.client',
            [
                'url' => $sanitizedUrl,
                'method' => $method,
                'headers' => $this->sanitizeHeaders((array)$request->getHeaders()),
            ]
        );

        if (!$span) {
            Yii::error('Failed to start span', $this->logCategory);
            return;
        }

        // Store span in our array for later retrieval
        $this->requestSpans[$hash] = $span;
    }


    /**
     * Stops timing a HTTP request and adds the information to Sentry
     *
     * @param Request $request The HTTP request
     * @param Response|null $response The HTTP response (may be null on error)
     */
    protected function endRequestTimer(Request $request, $response = null)
    {
        $hash = spl_object_hash($request);

        if (!isset($this->requestTimes[$hash])) {
            return;
        }

        $time = microtime(true) - $this->requestTimes[$hash];

        // Get the sanitized URL we stored earlier, or sanitize it if not found
        $sanitizedUrl = isset($this->sanitizedUrls[$hash])
            ? $this->sanitizedUrls[$hash]
            : $this->sanitizeUrl($this->getRequestUrl($request));

        $method = $request->getMethod();

        // Get status code if response is available
        $statusCode = $response !== null ? $response->getStatusCode() : 0;

        // Get the span from our array
        $span = $this->requestSpans[$hash] ?? null;

        if ($span) {
            $spanStatus = null;

            if ($response === null) {
                $spanStatus = SpanStatus::unknownError();
            } elseif ($statusCode >= 500) {
                $spanStatus = SpanStatus::internalError();
            } elseif ($statusCode >= 400) {
                $spanStatus = SpanStatus::invalidArgument();
            } else {
                $spanStatus = SpanStatus::ok();
            }

            $this->sentryComponent->finishSpan(
                $span,
                [
                    'status_code' => $statusCode,
                    'duration_ms' => round($time * 1000, 2),
                    'error' => $response === null ? 'Response error or timeout' : null
                ],
                $spanStatus
            );
        }

        $data = [
            'url' => $sanitizedUrl,
            'method' => $method,
            'status_code' => $statusCode,
            'time' => round($time * 1000, 2) . 'ms', // Convert to milliseconds
            'error' => $response === null
        ];

        // Determine importance level: error if no response or code >= 400
        $level = ($response === null || $statusCode >= 400) ?
            Breadcrumb::LEVEL_ERROR : Breadcrumb::LEVEL_INFO;

        // Format message
        $statusText = $response === null ? 'ERROR' : $statusCode;

        $this->addBreadcrumb(
            "HTTP {$method} {$sanitizedUrl} ({$statusText})",
            $data,
            $level,
            'http.client'
        );

        // Clean up
        unset($this->requestTimes[$hash]);
        unset($this->requestSpans[$hash]);
        unset($this->sanitizedUrls[$hash]);
    }

    /**
     * Gets the full URL from a request
     *
     * @param Request $request The HTTP request
     * @return string
     */
    protected function getRequestUrl(Request $request)
    {
        try {
            $url = $request->getUrl();

            // If it's already a full URL, return it
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }

            // Otherwise construct the full URL from the client's base URL
            $client = $request->getClient();
            if ($client) {
                $baseUrl = $client->getConfig('baseUrl', '');
                return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
            }

            return $url; // Return as is if we can't build the full URL
        } catch (\Exception $e) {
            // If an error occurs when getting the URL, return a safe value
            return '[unable to determine URL]';
        }
    }

    /**
     * Sanitizes a URL by applying mask patterns
     *
     * @param string $url The URL to sanitize
     * @return string The sanitized URL
     */
    protected function sanitizeUrl(string $url): string
    {
        if (empty($this->urlMaskPatterns)) {
            return $url;
        }

        foreach ($this->urlMaskPatterns as $pattern => $replacement) {
            $url = preg_replace($pattern, $replacement, $url);
        }

        return $url;
    }

    /**
     * Sanitizes HTTP headers to remove sensitive information
     *
     * @param array $headers The HTTP headers
     * @return array
     */
    protected function sanitizeHeaders($headers)
    {
        if (!is_array($headers)) {
            return [];
        }

        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'api-key',
            'apikey',
            'api_key',
            'token',
            'password',
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
}