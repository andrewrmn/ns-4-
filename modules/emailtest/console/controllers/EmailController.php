<?php

namespace modules\emailtest\console\controllers;

use Craft;
use craft\elements\User;
use craft\mail\Message;
use craft\web\View;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Sends test copies of site email templates (always to {@see EmailController::TEST_TO}).
 *
 * Default (non-patient) new-user welcome after HCP registration:
 *   ./craft email-test/email/send-welcome-new-user
 *
 * Craft “Activate your account” system email (same as CP → user → Send activation email).
 * Requires a Craft user whose email is {@see EmailController::TEST_TO}:
 *   ./craft email-test/email/send-activate-account
 */
class EmailController extends Controller
{
    private const TEST_TO = 'andrewross.mn@gmail.com';

    /**
     * Renders `shop/emails/_newCustomer.html` (HCP account application received — not the patient invite flow).
     */
    public function actionSendWelcomeNewUser(): int
    {
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        $body = Craft::$app->getView()->renderTemplate('shop/emails/_newCustomer.html');

        $subject = 'Your NeuroScience account application';

        $mailer = Craft::$app->getMailer();
        $message = new Message();
        $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
        $message->setTo(self::TEST_TO);
        $message->setSubject($subject);
        $message->setHtmlBody($body);

        if ($mailer->send($message)) {
            Craft::info('Sent welcome new user (default) test email to ' . self::TEST_TO, __METHOD__);
            $this->stdout('Sent to ' . self::TEST_TO . "\n");

            return ExitCode::OK;
        }

        $this->stderr('Failed to send to ' . self::TEST_TO . "\n");

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sends Craft’s `account_activation` email for the user with email {@see EmailController::TEST_TO}
     * (regenerates their verification code).
     */
    public function actionSendActivateAccount(): int
    {
        $user = User::find()->email(self::TEST_TO)->one();
        if ($user === null) {
            $this->stderr('No user found with email: ' . self::TEST_TO . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (Craft::$app->getUsers()->sendActivationEmail($user)) {
            Craft::info('Sent account activation test email to ' . self::TEST_TO, __METHOD__);
            $this->stdout('Sent to ' . self::TEST_TO . "\n");

            return ExitCode::OK;
        }

        $this->stderr('Failed to send to ' . self::TEST_TO . "\n");

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
