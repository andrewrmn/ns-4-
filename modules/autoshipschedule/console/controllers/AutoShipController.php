<?php

namespace modules\autoshipschedule\console\controllers;

use Craft;
use craft\helpers\App;
use craft\commerce\elements\Order as CommerceOrder;
use craft\mail\Message;
use craft\web\View;
use enupal\stripe\Stripe as StripePlugin;
use enupal\stripe\elements\Order;
use Stripe\Subscription;
use yii\console\Controller;

/**
 * Autoship console commands (module: autoship-schedule).
 *
 * ./craft autoship-schedule/auto-ship
 * ./craft autoship-schedule/auto-ship/renew-auto-ship
 * ./craft autoship-schedule/auto-ship/upcoming-autoship-email
 */
class AutoShipController extends Controller
{
    public function actionIndex()
    {
        $result = 'something';

        echo "Welcome to the console AutoShipController actionIndex() method\n";

        return $result;
    }

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
            $daysDiff = round($diff / (60 * 60 * 24)) . '';
            $today = date('Y-m-d h:i:s');

            if ($recurringOrderFrequency == 1 && $daysDiff % 17 == 0) {
                $autoShipOrder->orderStatusId = 5;
                $autoShipOrder->dateUpdated = $today;
                Craft::$app->getElements()->saveElement($autoShipOrder, false);
            } elseif ($recurringOrderFrequency == 2 && $daysDiff % 60 == 0) {
                $autoShipOrder->dateUpdated = $today;
                $autoShipOrder->orderStatusId = 5;
                Craft::$app->getElements()->saveElement($autoShipOrder, false);
            } elseif ($recurringOrderFrequency == 3 && $daysDiff % 90 == 0) {
                $autoShipOrder->dateUpdated = $today;
                $autoShipOrder->orderStatusId = 5;
                Craft::$app->getElements()->saveElement($autoShipOrder, false);
            }
        }
    }

    public function actionReactivateAutoShip()
    {
        /* legacy commented implementation retained in plugin backup if needed */
    }

    public function actionUpcomingAutoshipEmail()
    {
        if (!filter_var(App::env('AUTOSHIP_UPCOMING_EMAIL_ENABLED') ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $this->stdout("Skipped: AUTOSHIP_UPCOMING_EMAIL_ENABLED is not true.\n");

            return;
        }

        StripePlugin::$app->settings->initializeStripe();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        $subscriptions = Subscription::search(['query' => 'status:"' . Subscription::STATUS_ACTIVE . '"']);
        foreach ($subscriptions->data as $subscription) {
            $daysUntilDue = floor(($subscription['current_period_end'] - time()) / (60 * 60 * 24));
            if ($daysUntilDue == 1 && !$subscription['cancel_at_period_end']) {
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
                    $subject = 'We’re preparing your order';
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
