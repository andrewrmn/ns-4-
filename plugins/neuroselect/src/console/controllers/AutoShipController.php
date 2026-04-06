<?php
/**
 * User plugin for Craft CMS 3.x
 *
 * t
 *
 * @link      https://360adaptive.com/
 * @copyright Copyright (c) 2022 Bhashkar Yadav
 */

namespace neuroscience\neuroselect\console\controllers;

use yii\console\Controller;

use Craft;
use craft\web\View;
use craft\mail\Message;
use craft\commerce\elements\Order as CommerceOrder;

use Stripe\Subscription;
use enupal\stripe\Stripe as StripePlugin;
use enupal\stripe\elements\Order;

use neuroscience\neuroselect\AutoShip;

/**
 * AutoShip Command
 *
 * The first line of this class docblock is displayed as the description
 * of the Console Command in ./craft help
 *
 * Craft can be invoked via commandline console by using the `./craft` command
 * from the project root.
 *
 * Console Commands are just controllers that are invoked to handle console
 * actions. The segment routing is plugin-name/controller-name/action-name
 *
 * The actionIndex() method is what is executed if no sub-commands are supplied, e.g.:
 *
 * ./craft neuroselect/auto-ship
 *
 * Actions must be in 'kebab-case' so actionDoSomething() maps to 'do-something',
 * and would be invoked via:
 *
 */
class AutoShipController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Handle neuroselect/auto-ship console commands
     *
     * The first line of this method docblock is displayed as the description
     * of the Console Command in ./craft help
     *
     * @return mixed
     */
    public function actionIndex()
    {
        $result = 'something';

        echo "Welcome to the console AutoShipController actionIndex() method\n";

        return $result;
    }

    /**
     * Handle neuroselect/auto-ship/renew-auto-ship console commands
     *
     * The first line of this method docblock is displayed as the description
     * of the Console Command in ./craft help
     *
     * @return mixed
     */
    public function actionRenewAutoShip()
    {
        $autoShipOrders = Order::find()
                        ->isCompleted(true)
                        ->makeThisARecurringOrder(1)
                        ->all();
        foreach ($autoShipOrders as $autoShipOrder) {
            $recurringOrderFrequency = $autoShipOrder->recurringOrderFrequency->value;
            $dateOrdered = $autoShipOrder->dateOrdered->format(\DateTime::ATOM);
            $now = time();
            $diff = $now - strtotime($dateOrdered);
            $daysDiff = round($diff / (60 * 60 * 24)).'';
            $today = date("Y-m-d h:i:s");

            if( $recurringOrderFrequency == 1 && $daysDiff%17 == 0 ){
                $autoShipOrder->orderStatusId = 5;
                $autoShipOrder->dateUpdated = $today;
                Craft::$app->getElements()->saveElement($autoShipOrder, false);
            }elseif( $recurringOrderFrequency == 2 && $daysDiff%60 == 0 ){
                $autoShipOrder->dateUpdated = $today;
                $autoShipOrder->orderStatusId = 5;
                Craft::$app->getElements()->saveElement($autoShipOrder, false);
            }elseif( $recurringOrderFrequency == 3 && $daysDiff%90 == 0 ){
                $autoShipOrder->dateUpdated = $today;
                $autoShipOrder->orderStatusId = 5;
                Craft::$app->getElements()->saveElement($autoShipOrder, false);
            }
        }
        return ;
    }

    public function actionReactivateAutoShip(){
        /*$today = date("Y-m-d h:i:s");
        $sql = "SELECT orderId FROM craft_autoship_delay WHERE dateDelay = '{$today}'";
        $orders = Craft::$app->db->createCommand($sql)->queryAll();
        foreach ($orders as $order) {
            $orderId = $order['orderId'];
            $sql = "SELECT stripeTransactionId from craft_enupalstripe_orders WHERE id = {$orderId}";
            $orderSub = Craft::$app->db->createCommand($sql)->queryOne();
            $subscriptionId = $orderSub['stripeTransactionId'];
            $result = Stripe::$app->subscriptions->reactivateStripeSubscription($subscriptionId);
            Craft::$app->db->createCommand($sql)->queryOne();
        }
        return ;*/
    }

    public function actionUpcomingAutoshipEmail()
    {
        StripePlugin::$app->settings->initializeStripe();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
	
        $subscriptions = Subscription::search(['query' => 'status:"'.Subscription::STATUS_ACTIVE.'"']);
        foreach ($subscriptions->data as $subscription) {
            $daysUntilDue = floor(($subscription['current_period_end'] - time()) / (60 * 60 * 24));
            if ($daysUntilDue == 3 && !$subscription['cancel_at_period_end']) {
                $orderNumber = $subscription['metadata']->orderNumber;
                $order = Order::find()->where(['variants' => '{"orderNumber":"' . $orderNumber . '"}'])->one();
                $commerceOrder = CommerceOrder::find()->where(['number' => $orderNumber])->orderBy(['id' => 'DESC'])->one();
                if ($order && $commerceOrder) {
                    $body = Craft::$app->getView()->renderTemplate(
                        'shop/emails/_patientAutoshipComingUp',
                        [
                            'commerceOrder' => $commerceOrder,
                            'order' => $order,
                            'subscription' => $subscription,
                        ]
                    );
                    $subject = "We’re preparing your order";
                    $mailer = Craft::$app->getMailer();
                    $message = new Message();
                    $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
                    $message->setTo($order->email);
                    $message->setSubject($subject);
                    $message->setHtmlBody($body);
                    $mailer->send($message);
                }
           }
        }
    }
}
