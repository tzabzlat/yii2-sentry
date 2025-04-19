# Yii2 Sentry
[![Latest Stable Version](http://poser.pugx.org/tzabzlat/yii2-sentry/v)](https://packagist.org/packages/tzabzlat/yii2-sentry)
[![License](http://poser.pugx.org/tzabzlat/yii2-sentry/license)](https://packagist.org/packages/tzabzlat/yii2-sentry) [![PHP Version Require](http://poser.pugx.org/tzabzlat/yii2-sentry/require/php)](https://packagist.org/packages/tzabzlat/yii2-sentry)

*Read this in other languages: [English](README.md), [Русский](README.ru.md)*

Комплексная интеграция [Sentry](https://sentry.io) с фреймворком Yii2: логирование, трассировка и профилирование.

## Возможности

- Отслеживание ошибок и исключений через логи Yii2
- Мониторинг производительности базы данных (медленные запросы, транзакции)
- Трассировка HTTP-запросов (входящих и исходящих)
- Создание ручных span для отслеживания производительности критичных операций
- Гибкая настройка сбора данных через систему коллекторов
- Санитизация чувствительных данных (пароли, токены, ключи API)

## Установка

Установить пакет через composer:

```bash
composer require tzabzlat/yii2-sentry
```

Для использования функций профилирования производительности, необходимо установить расширение PHP [Excimer](https://github.com/wikimedia/mediawiki-php-excimer).

## Настройка

### Базовая конфигурация

Добавьте в конфигурацию приложения (не `common`):

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

## Встроенные коллекторы

Пакет включает четыре основных коллектора, каждый отвечает за свою область мониторинга:

### 1. LogCollector
Собирает и отправляет в Sentry логи Yii2 с уровнями error и warning. Позволяет настроить, какие логи должны быть отправлены, а какие - игнорироваться.

### 2. DbCollector

Отслеживает SQL-запросы, измеряет их производительность и создает spans в Sentry для анализа. Автоматически отмечает медленные запросы. Также отслеживает транзакции в базе данных.

### 3. HttpClientCollector

Отслеживает исходящие HTTP-запросы, выполненные через Yii2 HttpClient. Измеряет время ответа, фиксирует статус ответа и создает spans для визуализации HTTP-зависимостей.

### 4. RequestCollector

Отслеживает входящие HTTP-запросы к вашему приложению. Создает главную транзакцию для каждого запроса и собирает информацию о контроллере, действии, времени обработки и статусе ответа.

## Использование

### Ручные spans для кастомных операций

Для создания spans вручную используйте метод `trace`:

```php
// Простой span
Yii::$app->sentry->trace('Название операции', function() {
    // Ваш код здесь
    heavyOperation();
});

// С дополнительными данными
Yii::$app->sentry->trace(
    'Импорт данных', 
    function() {
        // Импорт данных
        return $result;
    }, 
    'custom.import', // Тип операции
    [
        'source' => 'api',
        'records_count' => $count
    ]
);

// Span с обработкой исключений
try {
    Yii::$app->sentry->trace('Критическая операция', function() {
        // В случае исключения, span будет помечен как failed
        throw new \Exception('Ошибка!');
    });
} catch (\Exception $e) {
    // Исключение будет перехвачено здесь
    // Span уже помечен как failed в Sentry
}
```

### Конфигурация коллекторов

Вы можете настроить каждый коллектор отдельно через параметр `collectorsConfig`:

```php
'sentry' => [
    'class' => 'tzabzlat\yii2sentry\SentryComponent',
    'dsn' => env('SENTRY_DSN', ''),
    'environment' => YII_ENV,
    'tracesSampleRatePercent' => YII_ENV_PROD ? 20 : 100,
    'collectorsConfig' => [
        // Настройка LogCollector
        'logCollector' => [
            'targetOptions' => [
                'levels' => ['error', 'warning'], // Уровни логов для отправки
                'except' => ['yii\web\HttpException:404'], // Исключения
                'exceptMessages' => [
                    '/^Информационное сообщение/' => true, // Исключить по паттерну
                ],
            ],
        ],
        
        // Настройка DbCollector
        'dbCollector' => [
            'slowQueryThreshold' => 100, // Порог в мс для медленных запросов
        ],
        
        // Настройка HttpClientCollector с маскированием чувствительных URL
        'httpClientCollector' => [
            'urlMaskPatterns' => [
                '|https://api\.telegram\.org/bot([^/]+)/|' => 'https://api.telegram.org/bot[HIDDEN]/',
            ],
        ],
        
        // Настройка RequestCollector
        'requestCollector' => [
            'captureUser' => true, // Захватывать ID пользователя
        ],
    ],
]
```

### Отключение коллекторов

Для отключения определенного коллектора, установите его конфигурацию в `false`:

```php
'collectorsConfig' => [
    'dbCollector' => false, // Отключает коллектор базы данных
    'httpClientCollector' => false, // Отключает коллектор HTTP-клиента
],
```

## Принцип работы коллекторов

### LogCollector

Подключает специальный LogTarget, который перехватывает логи с заданными уровнями и отправляет их в Sentry. Обрабатывает исключения как отдельный тип событий. Также поддерживает фильтрацию по категориям и шаблонам сообщений.

### DbCollector

Переопределяет стандартный DbCommand Yii2 и подключается к событиям профилирования запросов. Измеряет время выполнения каждого SQL-запроса, определяет тип запроса (SELECT, INSERT и т.д.), и создает spans для визуализации в Sentry. Отслеживает транзакции через события Connection.

### HttpClientCollector

Подписывается на события отправки запросов через HttpClient. Для каждого запроса создает span с деталями URL, метода, заголовков и тела запроса (с санитизацией чувствительных данных). Измеряет время ответа и добавляет информацию о статусе ответа.

### RequestCollector

Создает основную транзакцию для каждого входящего HTTP-запроса. Собирает информацию о маршруте, контроллере, действии, параметрах запроса и ответе. Измеряет общее время обработки запроса и пиковое использование памяти.

## Создание собственного коллектора

Вы можете создать собственный коллектор, реализовав интерфейс `CollectorInterface` или расширив класс `BaseCollector`:

```php
namespace app\components\sentry;

use tzabzlat\yii2sentry\collectors\BaseCollector;
use tzabzlat\yii2sentry\SentryComponent;
use Sentry\Breadcrumb;
use Sentry\State\Scope;
use Yii;

class MyCustomCollector extends BaseCollector
{
    // Конфигурация коллектора
    public $someOption = 'default';
    
    /**
     * Подключает коллектор к Sentry
     */
    public function attach(SentryComponent $sentryComponent): bool
    {
        parent::attach($sentryComponent);
        
        // Подключаемся к событиям Yii2
        \yii\base\Event::on(SomeClass::class, SomeClass::EVENT_NAME, function($event) {
            $this->handleEvent($event);
        });
        
        return true;
    }
    
    /**
     * Устанавливает дополнительные теги
     */
    public function setTags(Scope $scope): void
    {
        $scope->setTag('custom_tag', 'value');
    }
    
    /**
     * Обрабатывает пользовательское событие
     */
    protected function handleEvent($event)
    {
        // Создаем span для отслеживания
        $span = $this->sentryComponent->startSpan(
            'My Custom Operation',
            'custom.operation',
            [
                'key' => 'value',
                'event_type' => get_class($event)
            ]
        );
        
        // Добавляем "хлебную крошку" в таймлайн
        $this->addBreadcrumb(
            'My Event Happened',
            ['data' => 'value'],
            Breadcrumb::LEVEL_INFO,
            'custom'
        );
        
        // Завершаем span
        if ($span) {
            $this->sentryComponent->finishSpan($span, [
                'result' => 'success',
                'additional_data' => $someValue
            ]);
        }
    }
}
```

Затем добавьте ваш коллектор в конфигурацию:

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

### Вклад в проект
Если вы нашли ошибку или у вас есть предложения по улучшению, не стесняйтесь:

- Создавать issue с описанием проблемы или предложения
- Предлагать pull request'ы с исправлениями или новыми функциями

## Лицензия

MIT
