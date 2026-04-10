<?php

namespace modules;

use Craft;
use craft\commerce\elements\Order;
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
                    ['order' => $order, 'hcp' => $hcp]
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
}
