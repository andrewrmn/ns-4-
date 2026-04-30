<?php

namespace modules;

use Craft;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;
use yii\base\Module;

/**
 * NeuroSelect (PIR, Neuro Q survey, app API, PDF): same behavior as the neuroselect plugin,
 * registered as a project module so the plugin can be removed from the dashboard later.
 *
 * Module id: neuroselect-module (differs from plugin handle `neuroselect` while both may be installed).
 */
class NeuroSelectModule extends Module
{
    public function init(): void
    {
        $this->controllerNamespace = 'modules\\neuroselect\\controllers';

        parent::init();

        // Override plugin placeholder rules so they target this module (merge order: later keys win in PHP).
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules = array_merge($event->rules, [
                    'siteActionTrigger1' => 'neuroselect-module/api',
                    'siteActionTrigger2' => 'neuroselect-module/pdf',
                    'siteActionTrigger3' => 'neuroselect-module/sleep',
                    'siteActionTrigger4' => 'neuroselect-module/update',
                    'siteActionTrigger5' => 'neuroselect-module/survey',
                ]);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules = array_merge($event->rules, [
                    'cpActionTrigger1' => 'neuroselect-module/api/do-something',
                    'cpActionTrigger2' => 'neuroselect-module/pdf/do-something',
                    'cpActionTrigger3' => 'neuroselect-module/sleep/do-something',
                    'cpActionTrigger4' => 'neuroselect-module/update/do-something',
                    'cpActionTrigger5' => 'neuroselect-module/survey/do-something',
                ]);
            }
        );

        Craft::info('NeuroSelect module loaded', __METHOD__);
    }
}
