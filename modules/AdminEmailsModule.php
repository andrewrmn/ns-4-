<?php

namespace modules;

use Craft;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\helpers\App;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use yii\base\Event;
use yii\base\Module;

/**
 * On front-end User saves, emails staff when a logged-in user updates their account
 * (same behavior as the former admin-emails plugin).
 */
class AdminEmailsModule extends Module
{
    private const NOTIFICATION_TO_PRODUCTION = 'newaccounts@neurorelief.com';

    private const NOTIFICATION_TO_DEV = 'andrewross.mn@gmail.com';

    private const SUBJECT = 'Please review an account update';

    public function init(): void
    {
        Craft::setAlias('@adminEmailsModule', __DIR__);
        parent::init();

        Event::on(
            User::class,
            User::EVENT_AFTER_SAVE,
            function (ModelEvent $event): void {
                if (Craft::$app->getRequest()->getIsCpRequest()) {
                    return;
                }

                $user = Craft::$app->getUser()->getIdentity();
                if ($user === null) {
                    return;
                }

                $mailSettings = App::mailSettings();
                $fromEmail = App::parseEnv($mailSettings->fromEmail);
                $fromName = App::parseEnv($mailSettings->fromName) ?: '';

                if ($fromEmail === '' || $fromEmail === null) {
                    Craft::warning('AdminEmailsModule: System email address is not configured; skipping notification.', __METHOD__);
                    return;
                }

                $message = new Message();
                $fullName = trim(($user->firstName ?? '') . ' ' . ($user->lastName ?? ''));
                $editUrl = UrlHelper::cpUrl('users/' . $user->id);

                $message->setFrom([$fromEmail => $fromName]);
                $message->setTo($this->notificationRecipient());
                $message->setSubject(self::SUBJECT);
                $message->setHtmlBody(
                    'User: ' . Html::encode($fullName) . '<br />'
                    . 'View Profile: <a href="' . Html::encode($editUrl) . '">' . Html::encode($editUrl) . '</a>'
                );

                Craft::$app->getMailer()->send($message);
            }
        );
    }

    private function notificationRecipient(): string
    {
        if (\defined('CRAFT_ENVIRONMENT') && \CRAFT_ENVIRONMENT === 'dev') {
            return self::NOTIFICATION_TO_DEV;
        }

        return self::NOTIFICATION_TO_PRODUCTION;
    }
}
