<?php
/**
 * neuroselect plugin for Craft CMS 3.x
 *
 * Pull Data from the NeuroScience app and display in User Profiles
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross
 */

namespace neuroscience\neuroselect;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;

use yii\base\Event;

/**
 * @author    Andrew Ross
 * @package   Neuroselect
 * @since     1.0.0
 */
class Neuroselect extends Plugin
{
    public static $plugin;

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['siteActionTrigger1'] = 'neuroselect/api';
                $event->rules['siteActionTrigger2'] = 'neuroselect/pdf';
                $event->rules['siteActionTrigger3'] = 'neuroselect/sleep';
                $event->rules['siteActionTrigger4'] = 'neuroselect/update';
                $event->rules['siteActionTrigger5'] = 'neuroselect/survey';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['cpActionTrigger1'] = 'neuroselect/api/do-something';
                $event->rules['cpActionTrigger2'] = 'neuroselect/pdf/do-something';
                $event->rules['cpActionTrigger3'] = 'neuroselect/sleep/do-something';
                $event->rules['cpActionTrigger4'] = 'neuroselect/update/do-something';
                $event->rules['cpActionTrigger5'] = 'neuroselect/survey/do-something';
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                }
            }
        );

        Craft::info(
            Craft::t(
                'neuroselect',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }
}
