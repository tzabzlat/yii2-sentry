<?php

namespace tzabzlat\yii2sentry;

use Yii;
use yii\log\Logger as YiiLogger;

/**
 * Logger extends Yii Logger with direct handler calls for specific categories
 */
class Logger extends YiiLogger
{
    /**
     * @var array Registry of handlers - maps message categories to handlers
     * Format: [
     *   'category' => [
     *     'begin' => callable,  // For LEVEL_PROFILE_BEGIN
     *     'end' => callable,    // For LEVEL_PROFILE_END
     *   ]
     * ]
     */
    public array $handlers = [];

    public function init(): void
    {
        $currentLogger = Yii::getLogger();

        // Check if the current logger is a standard Yii logger
        if (!($currentLogger instanceof YiiLogger) || $currentLogger instanceof self) {
            // Current logger is either already our Logger or a custom implementation
            Yii::error('Cannot initialize SentryLogger: A different custom logger is already in use. ' .
                'SentryLogger requires the default Yii logger to be active.', 'sentry');

            return;
        }

        $this->messages = $currentLogger->messages;

        \Yii::setLogger($this);

        parent::init();
    }


    /**
     * Registers a handler for a specific message category
     *
     * @param string $category Message category
     * @param callable $beginCallback Handler for LEVEL_PROFILE_BEGIN
     * @param callable $endCallback Handler for LEVEL_PROFILE_END
     * @return void
     */
    public function registerHandler(string $category, callable $beginCallback, callable $endCallback): void
    {
        $this->handlers[$category] = [
            'begin' => $beginCallback,
            'end' => $endCallback,
        ];
    }

    /**
     * @inheritdoc
     */
    public function log($message, $level, $category = 'application'): void
    {
        // Standard logger behavior
        parent::log($message, $level, $category);

        // Check if there are handlers for this category
        if (isset($this->handlers[$category])) {
            $handler = $this->handlers[$category];

            // Call the appropriate method depending on the log level
            if ($level === self::LEVEL_PROFILE_BEGIN && isset($handler['begin']) && is_callable($handler['begin'])) {
                call_user_func($handler['begin'], $message, $category);
            } elseif ($level === self::LEVEL_PROFILE_END && isset($handler['end']) && is_callable($handler['end'])) {
                call_user_func($handler['end'], $message, $category);
            }
        }
    }
}