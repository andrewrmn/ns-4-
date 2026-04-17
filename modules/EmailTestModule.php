<?php

namespace modules;

use Craft;
use craft\console\Application as ConsoleApplication;
use yii\base\Module;

/**
 * Console-only helpers for sending templated test emails (e.g. default new-user welcome, account activation).
 */
class EmailTestModule extends Module
{
    public function init(): void
    {
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'modules\\emailtest\\console\\controllers';
        }

        parent::init();
    }
}
