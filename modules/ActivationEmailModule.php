<?php

namespace modules;

use Craft;
use craft\mail\Mailer;
use craft\mail\Message;
use craft\web\View;
use yii\base\Event;
use yii\base\Module;
use yii\mail\MailEvent;

/**
 * Replaces Craft’s system activation email with a site template for `account_activation` only.
 */
class ActivationEmailModule extends Module
{
    public function init(): void
    {
        parent::init();

        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_PREP,
            function (MailEvent $event): void {
                $message = $event->message;
                if (!$message instanceof Message || $message->key !== 'account_activation') {
                    return;
                }

                $variables = $message->variables ?? [];
                $link = $variables['link'] ?? '';

                Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
                $html = Craft::$app->getView()->renderTemplate('shop/emails/_accountActivation.html', [
                    'link' => $link,
                ]);

                $message->setSubject('Activate your account');
                $message->setHtmlBody($html);

                $url = is_object($link) && method_exists($link, '__toString') ? (string) $link : (string) $link;
                $message->setTextBody(
                    "Welcome to NeuroScience!\n\n"
                    . "You've been invited to activate your new NeuroScience account. To complete the account setup, please use the activation link below.\n\n"
                    . $url
                );

                $message->key = null;
            }
        );
    }
}
