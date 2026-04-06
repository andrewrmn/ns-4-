<?php
/**
 * Admin Emails plugin for Craft CMS 3.x
 *
 * Send emails for certain actions
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross Co.
 */

namespace neuroscience\adminemails;


use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\commerce\elements\Order;
use craft\commerce\events\LineItemEvent;
use craft\commerce\services\LineItems;
use craft\mail\Message;

use craft\elements\User;
use craft\events\ModelEvent;
use craft\helpers\UrlHelper;

use yii\base\Event;

/**
 * Class AdminEmails
 *
 * @author    Andrew Ross Co.
 * @package   AdminEmails
 * @since     1.0.0
 *
 */
class AdminEmails extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var AdminEmails
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public $hasCpSettings = false;

    /**
     * @var bool
     */
    public $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

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
                'admin-emails',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );


        // Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(Event $event) {
        // Event::on(\craft\services\Elements::class, \craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT, function(Event $event) {
        //     if ($event->element instanceof \craft\elements\User) {
        //         // Do your thing.
        //     }
        // });

        Event::on(
            User::class,
            User::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                if( !Craft::$app->request->isCpRequest ){

                    $user = Craft::$app->getUser()->getIdentity();
					
					if( $user ) {
						
	                    $settings = Craft::$app->systemSettings->getSettings('email');
	                    $message = new Message();
	
	                    //newaccounts@neurorelief.com
	                    $mail = 'newaccounts@neurorelief.com';
	                    $subject = 'Please review an account update';
	
	                    $editUrl = UrlHelper::cpUrl()  .'/users/'. $user->id;
	
	                    $html    = 'User: '.$user->firstName . ' ' . $user->lastName . '<br />';
	                    $html    .= 'View Profile: <a href=" '. $editUrl  .'">' . $editUrl . '</a>';
	
	                    $message->setFrom([$settings['fromEmail'] => $settings['fromName']]);
	                    $message->setTo($mail);
	                    $message->setSubject($subject);
	                    $message->setHtmlBody($html);
	
	                    if (!empty($attachments) && \is_array($attachments)) {
	
	                        foreach ($attachments as $fileId) {
	                            if ($file = Craft::$app->assets->getAssetById((int)$fileId)) {
	                                $message->attach($this->getFolderPath() . '/' . $file->filename, array(
	                                    'fileName' => $file->title . '.' . $file->getExtension()
	                                ));
	                            }
	                        }
	                    }
	
	                    return Craft::$app->mailer->send($message);
                    
                    }
                }
        });
    }

    // Protected Methods
    // =========================================================================

}
