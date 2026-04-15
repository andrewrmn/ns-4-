<?php

namespace modules;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as CommerceTransactionRecord;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use craft\web\User as WebUser;
use craft\web\View;
use enupal\stripe\Stripe;
use yii\base\Event;
use yii\base\Module;
use yii\web\UserEvent;

class PatientShopModule extends Module
{
    public function init(): void
    {
        parent::init();

        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function (Event $e): void {
            $order = $e->sender;
            if ($order->makeThisARecurringOrder && !Craft::$app->request->isCpRequest) {
                $order->orderStatusId = 5;
                Craft::$app->getElements()->saveElement($order, false);
                $freshOrder = Order::find()->id((int) $order->id)->status(null)->one();
                if ($freshOrder) {
                    self::ensureSuccessfulPurchaseRecordForStripeAutoshipInitial($freshOrder);
                }
                $cancelSubscriptionOrderId = $order->cancelSubscriptionOrderId;
                if ($cancelSubscriptionOrderId) {
                    $subscriptionId = $cancelSubscriptionOrderId;
                    $settings = Stripe::$app->settings->getSettings();
                    Stripe::$app->subscriptions->cancelStripeSubscription($subscriptionId, $settings->cancelAtPeriodEnd);
                }
            }

            $patient = Craft::$app->getUsers()->getUserByUsernameOrEmail($order->email);
            if (!$patient || !$patient->id) {
                return;
            }

            $hcp = ($patient->relatedHcp && $patient->relatedHcp->count()) ? $patient->relatedHcp->one() : null;
            if ($hcp && $hcp->hcpEmailNotifications) {
                Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
                $body = Craft::$app->getView()->renderTemplate(
                    'shop/emails/_hcpPatientPlacedOrder',
                    [
                        'order' => $order,
                        'hcp' => $hcp,
                        'patient' => $patient,
                        'patientDisplayName' => HcpPatientOrderEmailHelper::patientDisplayName($order, $patient),
                    ]
                );
                $subject = 'New order on your NeuroScience storefront';
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
            function (UserEvent $event): void {
                $user = Craft::$app->getUser()->getIdentity();
                if (!$user || !$user->isInGroup('patients')) {
                    return;
                }

                $hcp = $user->relatedHcp->count() ? $user->relatedHcp->one() : null;
                if (!$user->patientEnrolled && !is_null($hcp) && $hcp->hcpEmailNotifications) {
                    Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
                    $body = Craft::$app->getView()->renderTemplate(
                        'shop/emails/_hcpPatientEnrolled',
                        ['user' => $user]
                    );
                    $subject = $user->firstName . ' ' . $user->lastName . ' set up their patient portal';
                    $mailer = Craft::$app->getMailer();
                    $message = new Message();
                    $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
                    $message->setTo($hcp->email);
                    $message->setSubject($subject);
                    $message->setHtmlBody($body);
                    $success = $mailer->send($message);
                    if ($success) {
                        $user->setFieldValues([
                            'patientEnrolled' => 1,
                        ]);
                        Craft::$app->elements->saveElement($user, false);
                    }
                }
                Craft::$app->getResponse()->redirect(UrlHelper::url('patients/dashboard'))->send();
            }
        );

        Craft::info('Patient shop module loaded', __METHOD__);
    }

    /**
     * Stripe/Enupal can complete the Commerce order without a successful **purchase** transaction row,
     * so CP shows “Unpaid”. Renewals get an explicit purchase in StripeWebhookModule; mirror that here
     * for the initial autoship checkout only when no successful purchase already exists.
     */
    private static function ensureSuccessfulPurchaseRecordForStripeAutoshipInitial(Order $order): void
    {
        foreach ($order->getTransactions() as $tx) {
            if ($tx->type === CommerceTransactionRecord::TYPE_PURCHASE && $tx->status === CommerceTransactionRecord::STATUS_SUCCESS) {
                // #region agent log
                @file_put_contents(
                    '/Users/andrewross/Sites/neuroscience-3/.cursor/debug-ee9ea5.log',
                    json_encode([
                        'sessionId' => 'ee9ea5',
                        'hypothesisId' => 'H2',
                        'runId' => 'verify',
                        'location' => 'PatientShopModule.php:ensureSuccessfulPurchase',
                        'message' => 'initial autoship purchase tx',
                        'data' => ['orderId' => (int) $order->id, 'outcome' => 'skip_existing_success_purchase'],
                        'timestamp' => (int) (microtime(true) * 1000),
                    ], JSON_UNESCAPED_SLASHES) . "\n",
                    FILE_APPEND | LOCK_EX
                );
                // #endregion

                return;
            }
        }

        if (!$order->getGateway()) {
            Craft::warning(
                'PatientShopModule: skipped synthetic purchase transaction — Commerce order has no gateway (id '
                . $order->id . ')',
                __METHOD__
            );

            // #region agent log
            @file_put_contents(
                '/Users/andrewross/Sites/neuroscience-3/.cursor/debug-ee9ea5.log',
                json_encode([
                    'sessionId' => 'ee9ea5',
                    'hypothesisId' => 'H2',
                    'runId' => 'verify',
                    'location' => 'PatientShopModule.php:ensureSuccessfulPurchase',
                    'message' => 'initial autoship purchase tx',
                    'data' => ['orderId' => (int) $order->id, 'outcome' => 'skip_no_gateway'],
                    'timestamp' => (int) (microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
            // #endregion

            return;
        }

        $transactions = Commerce::getInstance()->getTransactions();
        $tx = $transactions->createTransaction($order, null, CommerceTransactionRecord::TYPE_PURCHASE);
        $tx->status = CommerceTransactionRecord::STATUS_SUCCESS;
        $tx->reference = 'stripe:autoship-initial-checkout';
        $tx->message = 'Stripe autoship initial checkout';

        if (!$transactions->saveTransaction($tx)) {
            Craft::error(
                'PatientShopModule: failed to save initial autoship purchase transaction for order ' . $order->id,
                __METHOD__
            );

            // #region agent log
            @file_put_contents(
                '/Users/andrewross/Sites/neuroscience-3/.cursor/debug-ee9ea5.log',
                json_encode([
                    'sessionId' => 'ee9ea5',
                    'hypothesisId' => 'H2',
                    'runId' => 'verify',
                    'location' => 'PatientShopModule.php:ensureSuccessfulPurchase',
                    'message' => 'initial autoship purchase tx',
                    'data' => ['orderId' => (int) $order->id, 'outcome' => 'save_failed'],
                    'timestamp' => (int) (microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
            // #endregion

            return;
        }

        // #region agent log
        @file_put_contents(
            '/Users/andrewross/Sites/neuroscience-3/.cursor/debug-ee9ea5.log',
            json_encode([
                'sessionId' => 'ee9ea5',
                'hypothesisId' => 'H2',
                'runId' => 'verify',
                'location' => 'PatientShopModule.php:ensureSuccessfulPurchase',
                'message' => 'initial autoship purchase tx',
                'data' => ['orderId' => (int) $order->id, 'outcome' => 'saved_success_purchase'],
                'timestamp' => (int) (microtime(true) * 1000),
            ], JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
        // #endregion
    }
}
