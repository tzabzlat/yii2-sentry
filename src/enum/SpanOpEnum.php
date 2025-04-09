<?php

namespace tzabzlat\yii2sentry\enum;

class SpanOpEnum
{
    const CUSTOM = 'custom';
    const DB_TRANSACTION = 'db.transaction';
    const DB_QUERY = 'db.query';
    const DB_CONNECTION = 'db.connection';
}