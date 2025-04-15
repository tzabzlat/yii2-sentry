<?php

namespace tzabzlat\yii2sentry\collectors\LogCollector;

use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;
use yii\log\Logger;
use yii\log\Target;

class LogTarget extends Target
{
    public $except = ['yii\web\HttpException:404'];

    public $logVars = ['_GET', '_POST', '_COOKIE', '_SESSION'];
    /**
     * @var array Key-value pairs of message pattern => excluded status
     */
    public array $exceptMessages = [];

    /**
     * @var array Mapping of Yii log levels to Sentry Severity levels
     */
    protected array $levelMap = [];

    /**
     * Initialize level map
     */
    public function init(): void
    {
        parent::init();

        $this->levelMap = [
            Logger::LEVEL_ERROR => Severity::error(),
            Logger::LEVEL_WARNING => Severity::warning(),
            Logger::LEVEL_INFO => Severity::info(),
            Logger::LEVEL_TRACE => Severity::debug(),
            Logger::LEVEL_PROFILE => Severity::debug(),
            Logger::LEVEL_PROFILE_BEGIN => Severity::debug(),
            Logger::LEVEL_PROFILE_END => Severity::debug(),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function export(): void
    {
        foreach ($this->messages as $message) {
            $this->exportMessage($message);
        }
    }

    /**
     * Exports a single log message to Sentry
     *
     * @param array $message The log message
     */
    protected function exportMessage($message): void
    {
        list($text, $level, $category, $timestamp, $traces) = $message;

        // Skip message if the category is excluded
        if ($this->isCategoryExcluded($category)) {
            return;
        }

        // Skip message if the message matches an excluded pattern
        if ($this->isMessageExcluded($text)) {
            return;
        }

        // Prepare extra data
        $extra = [
            'traces' => $this->formatTraces($traces),
        ];

        // Add context information if logVars are set
        if (!empty($this->logVars)) {
            $extra['context'] = parent::getContextMessage();
        }

        // Handle exceptions differently
        if ($text instanceof Throwable) {
            $this->captureException($text, [
                'level' => $this->getLevelName($level),
                'logger' => $category,
                'timestamp' => $timestamp,
                'extra' => $extra,
            ]);
            return;
        }

        // If it's an array or object, convert it to a string representation
        if (is_array($text) || is_object($text)) {
            $text = print_r($text, true);
        }

        // Capture message
        $this->captureMessage($text, $this->getLevelName($level), [
            'logger' => $category,
            'timestamp' => $timestamp,
            'extra' => $extra,
        ]);
    }

    /**
     * Captures an exception and sends it to Sentry
     *
     * @param Throwable $exception The exception to capture
     * @param array $options Additional options
     */
    protected function captureException(Throwable $exception, array $options = []): void
    {
        $hub = SentrySdk::getCurrentHub();
        $hint = new EventHint();
        $hint->exception = $exception;

        $hub->withScope(function (Scope $scope) use ($hub, $exception, $options, $hint) {
            if (isset($options['level'])) {
                $scope->setLevel($options['level']);
            }

            if (isset($options['logger'])) {
                $scope->setTag('logger', $options['logger']);
            }

            if (isset($options['extra'])) {
                foreach ($options['extra'] as $key => $value) {
                    $scope->setExtra($key, $value);
                }
            }

            $hub->captureException($exception, $hint);
        });
    }

    /**
     * Captures a message and sends it to Sentry
     *
     * @param string $message The message to capture
     * @param Severity $level The severity level
     * @param array $options Additional options
     */
    protected function captureMessage(string $message, Severity $level = null, array $options = []): void
    {
        if ($level === null) {
            $level = Severity::info();
        }

        $hub = SentrySdk::getCurrentHub();

        $hub->withScope(function (Scope $scope) use ($hub, $message, $level, $options) {
            $scope->setLevel($level);

            if (isset($options['logger'])) {
                $scope->setTag('logger', $options['logger']);
            }

            if (isset($options['extra'])) {
                foreach ($options['extra'] as $key => $value) {
                    $scope->setExtra($key, $value);
                }
            }

            if (isset($options['tags'])) {
                foreach ($options['tags'] as $key => $value) {
                    $scope->setTag($key, $value);
                }
            }

            $hub->captureMessage($message);
        });
    }

    /**
     * Gets the Sentry severity level from a Yii log level
     *
     * @param int $level The Yii log level
     * @return \Sentry\Severity
     */
    protected function getLevelName(int $level): Severity
    {
        if (isset($this->levelMap[$level])) {
            return $this->levelMap[$level];
        }

        return Severity::info();
    }

    /**
     * Formats the stack traces into a readable format
     *
     * @param array $traces The stack traces
     * @return array
     */
    protected function formatTraces(array $traces): array
    {
        $formattedTraces = [];

        foreach ($traces as $trace) {
            $formattedTraces[] = [
                'file' => isset($trace['file']) ? $trace['file'] : 'unknown',
                'line' => isset($trace['line']) ? $trace['line'] : 0,
                'function' => isset($trace['function']) ? $trace['function'] : 'unknown',
                'class' => isset($trace['class']) ? $trace['class'] : null,
            ];
        }

        return $formattedTraces;
    }

    /**
     * Checks if a category is excluded
     *
     * @param string $category The log category
     * @return bool
     */
    protected function isCategoryExcluded(string $category): bool
    {
        foreach ($this->except as $pattern) {
            if (strpos($category, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a message matches an excluded pattern
     *
     * @param string $message The log message
     * @return bool
     */
    protected function isMessageExcluded(string $message): bool
    {
        if ($message instanceof Throwable) {
            $message = $message->getMessage();
        }

        foreach ($this->exceptMessages as $pattern => $excluded) {
            if ($excluded && preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }
}