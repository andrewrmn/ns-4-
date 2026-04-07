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

use neuroscience\neuroselect\variables\NeuroselectVariable;
use neuroscience\neuroselect\adjusters\NeuroselectDiscountSharing;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\User as WebUser;
use yii\web\UserEvent;
use craft\helpers\UrlHelper;

use craft\commerce\elements\Order;
use craft\guestentries\controllers\SaveController;
use craft\guestentries\events\SaveEvent;

use craft\commerce\adjusters\Discount as CommerceDiscount;
use craft\commerce\services\OrderAdjustments;
use craft\events\RegisterComponentTypesEvent;
use craft\console\Application as ConsoleApplication;

use enupal\stripe\services\Orders;
use enupal\stripe\events\OrderCompleteEvent;
use enupal\stripe\Stripe;

use craft\mail\Message;
use craft\web\View;
use craft\mail\Mailer;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    Andrew Ross
 * @package   Neuroselect
 * @since     1.0.0
 *
 */
class Neuroselect extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Neuroselect::$plugin
     *
     * @var Neuroselect
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSettings = false;

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Neuroselect::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'neuroscience\neuroselect\console\controllers';
        }

        Event::on(
            OrderAdjustments::class,
            OrderAdjustments::EVENT_REGISTER_DISCOUNT_ADJUSTERS,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = NeuroselectDiscountSharing::class;
            }
        );

        // Register our site routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['siteActionTrigger1'] = 'neuroselect/api';
                $event->rules['siteActionTrigger2'] = 'neuroselect/pdf';
                $event->rules['siteActionTrigger3'] = 'neuroselect/sleep';
                $event->rules['siteActionTrigger4'] = 'neuroselect/update';
                $event->rules['siteActionTrigger4'] = 'neuroselect/survey';
            }
        );

        // Register our CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['cpActionTrigger1'] = 'neuroselect/api/do-something';
                $event->rules['cpActionTrigger2'] = 'neuroselect/pdf/do-something';
                $event->rules['cpActionTrigger3'] = 'neuroselect/sleep/do-something';
                $event->rules['cpActionTrigger4'] = 'neuroselect/update/do-something';
                $event->rules['cpActionTrigger4'] = 'neuroselect/survey/do-something';
            }
        );

        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(Event $e) {
            $order = $e->sender;
            if( $order->makeThisARecurringOrder && !Craft::$app->request->isCpRequest ){
                $order->orderStatusId = 5;
                Craft::$app->getElements()->saveElement($order, false);
                ## Cancel old subscription
                $cancelSubscriptionOrderId = $order->cancelSubscriptionOrderId;
                if( $cancelSubscriptionOrderId ){
                    $subscriptionId = $cancelSubscriptionOrderId;
                    $settings = Stripe::$app->settings->getSettings();
                    $result = Stripe::$app->subscriptions->cancelStripeSubscription($subscriptionId, $settings->cancelAtPeriodEnd);
                }
            }
            
            
            ## Send email to HCP about patient order
            $patient = Craft::$app->getUsers()->getUserByUsernameOrEmail($order->email);
						// Skip if no user was found (i.e., guest checkout)
            if (!$patient || !$patient->id) {
              return;
            }
            
                        
            //$hcp = $patient->relatedHcp->count() ? $patient->relatedHcp->one() : null;
            //if( !is_null($hcp) && $hcp->hcpEmailNotifications ){
              
            $hcp = ($patient->relatedHcp && $patient->relatedHcp->count()) ? $patient->relatedHcp->one() : null;
            if ($hcp && $hcp->hcpEmailNotifications) { 
                Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
                $body = Craft::$app->getView()->renderTemplate(
                    'shop/emails/_hcpPatientPlacedOrder', 
                    ['order' => $order, 'hcp' => $hcp]
                );
                $subject = "New order on your NeuroScience storefront";
                $mailer = Craft::$app->getMailer();
                $message = new Message();
                $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
                $message->setTo($hcp->email);
                $message->setSubject($subject);
                $message->setHtmlBody($body);
                $mailer->send($message);
            }
        });

        Event::on(
            WebUser::class,
            WebUser::EVENT_AFTER_LOGIN,
            function (UserEvent $event) {
                $user = Craft::$app->getUser()->getIdentity();

                if( $user->isInGroup('patients') )
                {
                    $hcp = $user->relatedHcp->count() ? $user->relatedHcp->one() : null;
                    if( !$user->patientEnrolled && !is_null($hcp) && $hcp->hcpEmailNotifications )
                    {
                        # Send email to HCP on first login
                        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
                        $body = Craft::$app->getView()->renderTemplate(
                            'shop/emails/_hcpPatientEnrolled', 
                            ['user' => $user]
                        );
                        $subject =  $user->firstName . ' ' . $user->lastName . " set up their patient portal";
                        $mailer = Craft::$app->getMailer();
                        $message = new Message();
                        $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
                        $message->setTo($hcp->email);
                        $message->setSubject($subject);
                        $message->setHtmlBody($body);
                        $success = $mailer->send($message);
                        if( $success ){
                            $user->setFieldValues([
                                'patientEnrolled' => 1
                            ]);
                            Craft::$app->elements->saveElement($user, false);
                        }
                    }
                    return Craft::$app->getResponse()->redirect(UrlHelper::url('patients/dashboard'))->send();
                }
            }
        );

        Event::on(Orders::class, Orders::EVENT_AFTER_ORDER_COMPLETE, function(OrderCompleteEvent $e) {
            $order = $e->order;
            $subscription = $order->getSubscription();
            $currentPeriodStart = date('Y-m-d', $subscription->data->current_period_start);
            $currentPeriodEnd = date('Y-m-d', $subscription->data->current_period_end);
            $orderId = $order->number;
            $formData = $order->getFormFields();
            $craftOrderNumber = $formData['orderNumber'];
            $sql = "SELECT id FROM craft_autoship_schedule WHERE orderId = '{$orderId}'";
            $orderQ = Craft::$app->db->createCommand($sql)->queryOne(); 
            if($orderQ){       
                $sql = "UPDATE craft_autoship_schedule SET `comOrderId` = '{$craftOrderNumber}', `currentPeriodStart` = '{$currentPeriodStart}', `currentPeriodEnd` = '{$currentPeriodEnd}' WHERE `id` = {$orderQ['id']}";
                $success = Craft::$app->db->createCommand($sql)->execute();
            }else{
                $sql = "INSERT INTO craft_autoship_schedule (`orderId`, `comOrderId`, `currentPeriodStart`, `currentPeriodEnd`) VALUES ('{$orderId}', '{$craftOrderNumber}', '{$currentPeriodStart}', '{$currentPeriodEnd}')";
                $success = Craft::$app->db->createCommand($sql)->execute();               
            }
        });


        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('neuroselect', NeuroselectVariable::class);
            }
        );

/**
 * Logging in Craft involves using one of the following methods:
 *
 * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
 * Craft::info(): record a message that conveys some useful information.
 * Craft::warning(): record a warning message that indicates something unexpected has happened.
 * Craft::error(): record a fatal error that should be investigated as soon as possible.
 *
 * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
 *
 * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
 * the category to the method (prefixed with the fully qualified class name) where the constant appears.
 *
 * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
 * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
 *
 * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
 */
        Craft::info(
            Craft::t(
                'neuroselect',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

}
