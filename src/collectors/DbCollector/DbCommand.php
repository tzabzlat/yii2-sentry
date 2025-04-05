<?php

namespace tzabzlat\yii2sentry\collectors\DbCollector;

use Yii;

class DbCommand extends \yii\db\Command
{
    protected function internalExecute($rawSql): void
    {
        $sql = $this->getSql();

        Yii::beginProfile($sql, __METHOD__);

        try {
            parent::internalExecute($rawSql);
        } finally {
            Yii::endProfile($sql, __METHOD__);
        }
    }
}