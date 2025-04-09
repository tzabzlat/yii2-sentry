<?php

namespace tzabzlat\yii2sentry\collectors\DbCollector;

use tzabzlat\yii2sentry\collectors\BaseCollector;
use tzabzlat\yii2sentry\enum\SpanOpEnum;
use tzabzlat\yii2sentry\Logger as SentryLogger;
use tzabzlat\yii2sentry\SentryComponent;
use Sentry\Breadcrumb;
use Sentry\Tracing\SpanStatus;
use Yii;
use yii\base\Event;
use yii\db\Connection;

/**
 * DbCollector tracks SQL operations and creates spans in Sentry in real-time.
 * It enables the enableProfiling option in Yii::$app->db.
 */
class DbCollector extends BaseCollector
{
    /**
     * @var int Query duration threshold (in ms) after which a query is considered slow
     */
    public int $slowQueryThreshold = 100;

    /**
     * @var int Connection open duration threshold (in ms) after which connection is considered slow
     */
    public int $slowConnectionThreshold = 50;

    /**
     * @var array Active spans by profiling tokens
     */
    protected array $activeSpans = [];

    /**
     * @var array Storage for query start information
     */
    protected array $queryStartInfo = [];

    /**
     * Attaches event handlers to collect DB query information
     */
    public function attach(SentryComponent $sentryComponent): bool
    {
        parent::attach($sentryComponent);

        if (!Yii::$app->has('db')) {
            Yii::error('Attempt to connect DbCollector to application without db.', $this->logCategory);

            return false;
        }

        if (!(Yii::getLogger() instanceof SentryLogger)) {
            Yii::error("Attempt to connect DbCollector to application without Logger.", $this->logCategory);

            return false;
        }

        $this->enableDbProfiling();
        $this->attachQueryEvents();
        $this->attachConnectionEvents();
        $this->attachTransactionEvents();

        return true;
    }

    protected function attachQueryEvents(): void
    {
        $logger = Yii::getLogger();

        $logger->registerHandler(
            'tzabzlat\yii2sentry\collectors\DbCollector\DbCommand::internalExecute',
            [$this, 'handleProfileBegin'],
            [$this, 'handleProfileEnd']
        );
    }

    protected function attachConnectionEvents(): void
    {
        $logger = Yii::getLogger();

        $logger->registerHandler(
            'yii\db\Connection::open',
            [$this, 'handleConnectionBegin'],
            [$this, 'handleConnectionEnd']
        );
    }

    /**
     * Checks and enables query profiling in the DB component
     */
    protected function enableDbProfiling(): void
    {
        $db = Yii::$app->get('db');

        $db->commandClass = DbCommand::class;

        // Enable query profiling if it's not already enabled
        if (!$db->enableProfiling) {
            $db->enableProfiling = true;
        }
    }

    protected function attachTransactionEvents(): void
    {
        Event::on(Connection::class, Connection::EVENT_BEGIN_TRANSACTION, function ($event) {
            $transactionSpan = $this->sentryComponent->startSpan(
                'Database transaction',
                SpanOpEnum::DB_TRANSACTION,
                [
                    'connection' => get_class($event->sender),
                    'dsn' => $event->sender->dsn,
                ]
            );

            if ($transactionSpan) {
                $connectionHash = spl_object_hash($event->sender);
                // Save the transaction span for later use
                $this->activeSpans['transaction_' . $connectionHash] = $transactionSpan;
            }

            $this->addBreadcrumb('Database transaction started', [
                'connection' => get_class($event->sender),
                'dsn' => $event->sender->dsn,
            ], Breadcrumb::LEVEL_INFO, SpanOpEnum::DB_TRANSACTION);
        });

        Event::on(Connection::class, Connection::EVENT_COMMIT_TRANSACTION, function ($event) {
            $connectionHash = spl_object_hash($event->sender);
            $spanKey = 'transaction_' . $connectionHash;

            if (isset($this->activeSpans[$spanKey])) {
                $this->sentryComponent->finishSpan(
                    $this->activeSpans[$spanKey],
                    ['status' => 'committed'],
                    SpanStatus::ok()
                );

                unset($this->activeSpans[$spanKey]);
            }

            $this->addBreadcrumb('Database transaction committed', [
                'connection' => get_class($event->sender),
                'dsn' => $event->sender->dsn,
            ], Breadcrumb::LEVEL_INFO, SpanOpEnum::DB_TRANSACTION);
        });

        Event::on(Connection::class, Connection::EVENT_ROLLBACK_TRANSACTION, function ($event) {
            $connectionHash = spl_object_hash($event->sender);
            $spanKey = 'transaction_' . $connectionHash;

            if (isset($this->activeSpans[$spanKey])) {
                $this->sentryComponent->finishSpan(
                    $this->activeSpans[$spanKey],
                    ['status' => 'rolled back'],
                    SpanStatus::unknownError()
                );

                unset($this->activeSpans[$spanKey]);
            }

            $this->addBreadcrumb('Database transaction rolled back', [
                'connection' => get_class($event->sender),
                'dsn' => $event->sender->dsn,
            ], Breadcrumb::LEVEL_WARNING, SpanOpEnum::DB_TRANSACTION);
        });
    }

    public function handleConnectionBegin(string $token, string $category): void
    {
        $uniqueToken = 'connection_' . $token . '_' . microtime(true);

        $this->queryStartInfo[$uniqueToken] = [
            'timestamp' => microtime(true),
            'category' => $category,
            'original_token' => $token
        ];

        $span = $this->sentryComponent->startSpan(
            'Database connection',
            SpanOpEnum::DB_CONNECTION,
            [
                'category' => $category,
            ]
        );

        if ($span) {
            $this->activeSpans[$uniqueToken] = $span;
        }
    }

    public function handleConnectionEnd(string $token): void
    {
        $uniqueToken = null;

        foreach ($this->queryStartInfo as $key => $info) {
            if ($info['original_token'] === $token && strpos($key, 'connection_') === 0) {
                $uniqueToken = $key;
                break;
            }
        }

        if (!$uniqueToken || !isset($this->activeSpans[$uniqueToken]) || !isset($this->queryStartInfo[$uniqueToken])) {
            Yii::error('Cannot find connection span for finish by token ' . $token, $this->logCategory);
            return;
        }

        $span = $this->activeSpans[$uniqueToken];
        $startInfo = $this->queryStartInfo[$uniqueToken];

        $duration = (microtime(true) - $startInfo['timestamp']) * 1000;

        $isSlowConnection = $duration > $this->slowConnectionThreshold;

        $this->sentryComponent->finishSpan($span, [
            'duration' => round($duration, 2) . ' ms',
            'slow_connection' => $isSlowConnection ? 'yes' : 'no',
        ]);

        $message = "Database connection opened";

        if ($isSlowConnection) {
            $message .= " (SLOW: " . round($duration, 2) . " ms)";
        }

        $level = $isSlowConnection ? Breadcrumb::LEVEL_WARNING : Breadcrumb::LEVEL_INFO;

        $this->addBreadcrumb(
            $message,
            [
                'duration' => round($duration, 2) . ' ms',
            ],
            $level,
            SpanOpEnum::DB_CONNECTION
        );

        unset($this->activeSpans[$uniqueToken]);
        unset($this->queryStartInfo[$uniqueToken]);
    }

    public function handleProfileBegin(string $token, string $category): void
    {
        $uniqueToken = $token . '_' . microtime(true);

        $this->queryStartInfo[$uniqueToken] = [
            'timestamp' => microtime(true),
            'category' => $category,
            'original_token' => $token
        ];

        $type = $this->getQueryTypeFromCategory($category);

        $span = $this->sentryComponent->startSpan(
            $token,
            SpanOpEnum::DB_QUERY,
            [
                'type' => $type,
                'category' => $category,
            ]
        );

        if ($span) {
            // Save span for completion at handleProfileEnd
            $this->activeSpans[$uniqueToken] = $span;

            Yii::info("DB Span started: {$type}", $this->logCategory);
        }
    }

    /**
     * Handles the end of profiling (LEVEL_PROFILE_END)
     * This method will be called directly from the Logger
     *
     * @param string $token Profiling token (contains SQL query template)
     */
    public function handleProfileEnd(string $token): void
    {
        // Find the matching unique token
        $uniqueToken = null;

        foreach ($this->queryStartInfo as $key => $info) {
            if ($info['original_token'] === $token) {
                $uniqueToken = $key;
                break;
            }
        }

        if (!$uniqueToken || !isset($this->activeSpans[$uniqueToken]) || !isset($this->queryStartInfo[$uniqueToken])) {
            Yii::error('Cannot find span for finish by token ' . $token, $this->logCategory);
            return;
        }

        $span = $this->activeSpans[$uniqueToken];
        $startInfo = $this->queryStartInfo[$uniqueToken];

        // Calculate duration
        $duration = (microtime(true) - $startInfo['timestamp']) * 1000; // in milliseconds

        // Determine if query is slow
        $isSlowQuery = $duration > $this->slowQueryThreshold;

        // Get query details for breadcrumb
        $type = $this->getQueryTypeFromCategory($startInfo['category']);

        // Finish span using the SentryComponent
        $this->sentryComponent->finishSpan($span, [
            'duration' => round($duration, 2) . ' ms',
            'slow_query' => $isSlowQuery ? 'yes' : 'no',
        ]);

        // Create breadcrumb for query
        $message = "Database query: {$type}";
        if ($isSlowQuery) {
            $message .= " (SLOW: " . round($duration, 2) . " ms)";
        }

        $level = $isSlowQuery ? Breadcrumb::LEVEL_WARNING : Breadcrumb::LEVEL_INFO;

        $this->addBreadcrumb(
            $message,
            [
                'type' => $type,
                'duration' => round($duration, 2) . ' ms',
            ],
            $level,
            'db.query'
        );

        Yii::info("DB Span completed: {$type} in " . round($duration, 2) . "ms", $this->logCategory);

        // Clean up
        unset($this->activeSpans[$uniqueToken]);
        unset($this->queryStartInfo[$uniqueToken]);
    }

    /**
     * Gets query type from category
     *
     * @param string $category Log category
     * @return string
     */
    protected function getQueryTypeFromCategory(string $category): string
    {
        if (strpos($category, 'yii\db\Command::query') === 0) {
            return 'SELECT'; // Usually query is used for SELECT
        } elseif (strpos($category, 'yii\db\Command::execute') === 0) {
            return 'EXECUTE'; // And execute for INSERT, UPDATE, DELETE
        }

        return 'SQL';
    }

    /**
     * Gets the query type from SQL string
     *
     * @param string $sql The SQL query
     * @return string
     */
    protected function getQueryType(string $sql): string
    {
        preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|SHOW|SET|EXEC|EXPLAIN|BEGIN|COMMIT|ROLLBACK|CALL|WITH)/i', trim($sql), $matches);

        return isset($matches[1]) ? strtoupper($matches[1]) : 'UNKNOWN';
    }
}