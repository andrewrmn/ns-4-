<?php

namespace modules;

use Craft;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use modules\paya\variables\PayaVariable;
use yii\base\Event;
use yii\base\Module;

/**
 * Paya: Payments JS verification + Twig helpers (former paya plugin).
 */
class PayaModule extends Module
{
    public function init(): void
    {
        Craft::setAlias('@payaModule', __DIR__ . '/paya');
        $this->controllerNamespace = 'modules\\paya\\controllers';
        parent::init();

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_SITE_URL_RULES,
                static function (RegisterUrlRulesEvent $event): void {
                    // Shorthand URLs used by checkout / invoice JS (full route is paya/verify/verify-response).
                    $event->rules['paya/verify-response'] = 'paya/verify/verify-response';
                    $event->rules['paya/verifyResponse'] = 'paya/verify/verify-response';
                }
            );

            Event::on(
                CraftVariable::class,
                CraftVariable::EVENT_INIT,
                static function (Event $event): void {
                    /** @var CraftVariable $variable */
                    $variable = $event->sender;
                    $variable->set('paya', PayaVariable::class);
                }
            );
        }

        Craft::info('Paya module loaded', __METHOD__);
    }
}
