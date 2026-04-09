<?php

namespace modules;

use Craft;
use craft\commerce\services\OrderAdjustments;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;
use modules\hcpworkspace\adjusters\HcpWorkspaceDiscountAdjuster;
use yii\base\Event;
use yii\base\Module;

class HcpWorkspaceModule extends Module
{
    public function init(): void
    {
        Craft::setAlias('@hcpWorkspaceModule', __DIR__ . '/hcpworkspace');

        if (!(Craft::$app instanceof ConsoleApplication)) {
            $this->controllerNamespace = 'modules\\hcpworkspace\\controllers';
        }

        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            return;
        }

        Event::on(
            OrderAdjustments::class,
            OrderAdjustments::EVENT_REGISTER_DISCOUNT_ADJUSTERS,
            function (RegisterComponentTypesEvent $event): void {
                $event->types[] = HcpWorkspaceDiscountAdjuster::class;
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['neuroselect/hcp/<action:[\w\-]+>'] = 'hcp-workspace/hcp/<action>';
            }
        );

        Craft::info('HCP workspace module loaded', __METHOD__);
    }
}
